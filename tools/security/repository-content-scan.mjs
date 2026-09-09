import { Buffer } from 'node:buffer';
import { createHash } from 'node:crypto';
import { lstat, readFile, realpath } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';
import { spawnSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';

import {
    extractDocxTextParts,
    OoxmlContentError,
} from './ooxml-content.mjs';

const KINDS = new Set(['secret', 'pii']);
const SYNTHETIC_PHONES = new Set([
    '620000000000',
    '628111111110',
    '628123456789',
    '6281234567800',
    '6281234567890',
]);
const RULES = Object.freeze({
    secret: new Set([
        'aws_access_key',
        'private_key',
        'provider_token',
        'credential_assignment',
    ]),
    pii: new Set(['email', 'indonesian_phone', 'nik', 'identity_assignment']),
});

export class ScanFailure extends Error {
    constructor(message, findings = []) {
        super(message);
        this.name = 'ScanFailure';
        this.findings = findings;
    }

    toJSON() {
        return {
            name: this.name,
            message: this.message,
            findings: this.findings,
        };
    }
}

export function parseTrackedEntries(output) {
    const entries = [];

    for (const record of output.split('\0')) {
        if (record === '') {
            continue;
        }

        const match = /^(\d{6}) ([0-9a-f]{40,64}) (\d)\t([\s\S]+)$/.exec(
            record,
        );

        if (match === null) {
            throw new ScanFailure(
                'Git returned an unsupported tracked-entry record.',
            );
        }

        entries.push({
            mode: match[1],
            objectId: match[2],
            stage: Number(match[3]),
            path: match[4],
        });
    }

    return entries;
}

export function reportPath(value) {
    return [...value.replaceAll('\\', '/')]
        .map((character) => {
            const code = character.charCodeAt(0);

            if (character !== '%' && code >= 32 && code <= 126) {
                return character;
            }

            return [...Buffer.from(character)]
                .map(
                    (byte) =>
                        `%${byte.toString(16).toUpperCase().padStart(2, '0')}`,
                )
                .join('');
        })
        .join('');
}

export async function scanRepository({
    root,
    kind,
    policyPath,
    now = new Date(),
}) {
    if (!KINDS.has(kind)) {
        throw new ScanFailure('Unsupported scan kind.');
    }

    const canonicalRoot = await realpath(root).catch(() => {
        throw new ScanFailure('Repository root is unreadable.');
    });
    const policy = await loadPolicy(policyPath, now);
    const entries = trackedEntries(canonicalRoot);
    const findings = [];

    for (const entry of entries) {
        const displayPath = reportPath(entry.path);

        if (entry.stage !== 0) {
            throw new ScanFailure(
                `Unmerged tracked entry is unsupported: ${displayPath}`,
            );
        }

        if (!['100644', '100755'].includes(entry.mode)) {
            throw new ScanFailure(
                `Tracked symlink or unsupported mode is forbidden: ${displayPath}`,
            );
        }

        const target = path.resolve(canonicalRoot, entry.path);

        if (!isInside(canonicalRoot, target)) {
            throw new ScanFailure(
                `Tracked path escapes repository: ${displayPath}`,
            );
        }

        const info = await lstat(target).catch(() => {
            throw new ScanFailure(`Tracked file is unreadable: ${displayPath}`);
        });

        if (!info.isFile() || info.isSymbolicLink()) {
            throw new ScanFailure(
                `Tracked symlink or unsupported file is forbidden: ${displayPath}`,
            );
        }

        if (info.size > policy.max_blob_bytes) {
            throw new ScanFailure(
                `Tracked file exceeds the configured maximum: ${displayPath}`,
            );
        }

        const bytes = await readFile(target).catch(() => {
            throw new ScanFailure(`Tracked file is unreadable: ${displayPath}`);
        });

        if (bytes.length !== info.size) {
            throw new ScanFailure(
                `Tracked file changed while scanning: ${displayPath}`,
            );
        }

        const indexBytes = readIndexBlob(
            canonicalRoot,
            entry,
            policy.max_blob_bytes,
        );

        findings.push(
            ...scanSnapshot(
                kind,
                displayPath,
                indexBytes,
                policy.max_blob_bytes,
            ),
        );
        findings.push(
            ...scanSnapshot(kind, displayPath, bytes, policy.max_blob_bytes),
        );
    }

    const uniqueFindings = deduplicate(findings);

    uniqueFindings.sort(
        (a, b) =>
            a.path.localeCompare(b.path) ||
            a.line - b.line ||
            a.rule.localeCompare(b.rule),
    );
    const applicable = policy.exceptions.filter(
        (exception) => exception.kind === kind,
    );
    const used = new Set();
    const unsuppressed = uniqueFindings.filter((finding) => {
        const index = applicable.findIndex(
            (exception, candidate) =>
                !used.has(candidate) &&
                exception.rule === finding.rule &&
                exception.path === finding.path &&
                exception.fingerprint === finding.fingerprint,
        );

        if (index === -1) {
            return true;
        }

        used.add(index);

        return false;
    });

    if (used.size !== applicable.length) {
        throw new ScanFailure(`Stale ${kind} scan exception is forbidden.`);
    }

    if (unsuppressed.length > 0) {
        throw new ScanFailure(
            `${kind.toUpperCase()} scan found ${unsuppressed.length} redacted finding(s).`,
            unsuppressed,
        );
    }

    return { kind, scanned: entries.length, findings: [] };
}

function trackedEntries(root) {
    const result = spawnSync('git', ['ls-files', '--stage', '-z'], {
        cwd: root,
        encoding: 'utf8',
        windowsHide: true,
        maxBuffer: 16 * 1024 * 1024,
    });

    if (result.status !== 0 || result.error) {
        throw new ScanFailure('Unable to enumerate Git-tracked blobs.');
    }

    return parseTrackedEntries(result.stdout);
}

function readIndexBlob(root, entry, maximum) {
    const result = spawnSync('git', ['cat-file', 'blob', entry.objectId], {
        cwd: root,
        encoding: null,
        windowsHide: true,
        maxBuffer: 16 * 1024 * 1024,
    });

    if (
        result.status !== 0 ||
        result.error ||
        !Buffer.isBuffer(result.stdout)
    ) {
        throw new ScanFailure(
            `Unable to read Git index blob: ${reportPath(entry.path)}`,
        );
    }

    if (result.stdout.length > maximum) {
        throw new ScanFailure(
            `Git index blob exceeds the configured maximum: ${reportPath(entry.path)}`,
        );
    }

    return result.stdout;
}

async function loadPolicy(policyPath, now) {
    let raw;

    try {
        raw = JSON.parse(await readFile(policyPath, 'utf8'));
    } catch {
        throw new ScanFailure('Scan policy is unreadable or malformed.');
    }

    if (
        !isPlainObject(raw) ||
        !sameKeys(raw, ['exceptions', 'max_blob_bytes', 'version']) ||
        raw.version !== 1 ||
        !Number.isSafeInteger(raw.max_blob_bytes) ||
        raw.max_blob_bytes < 1 ||
        !Array.isArray(raw.exceptions)
    ) {
        throw new ScanFailure('Scan policy has an unsupported shape.');
    }

    const seen = new Set();

    for (const exception of raw.exceptions) {
        if (isPlainObject(exception) && exception.kind === 'pii') {
            throw new ScanFailure(
                'PII scan exceptions are forbidden without private fingerprint authority.',
            );
        }

        if (
            !isPlainObject(exception) ||
            !sameKeys(exception, [
                'expires_on',
                'fingerprint',
                'kind',
                'path',
                'reason',
                'rule',
            ]) ||
            !KINDS.has(exception.kind) ||
            !RULES[exception.kind].has(exception.rule) ||
            typeof exception.path !== 'string' ||
            exception.path === '' ||
            exception.path.includes('\\') ||
            reportPath(exception.path) !== exception.path ||
            typeof exception.fingerprint !== 'string' ||
            !/^[0-9a-f]{64}$/.test(exception.fingerprint) ||
            typeof exception.reason !== 'string' ||
            exception.reason.trim() !== exception.reason ||
            exception.reason.length < 10 ||
            exception.reason.length > 200 ||
            typeof exception.expires_on !== 'string' ||
            !/^\d{4}-\d{2}-\d{2}$/.test(exception.expires_on)
        ) {
            throw new ScanFailure(
                'Scan policy contains an unknown or malformed exception.',
            );
        }

        const [year, month, day] = exception.expires_on.split('-').map(Number);
        const expiry = new Date(
            Date.UTC(year, month - 1, day, 23, 59, 59, 999),
        );

        if (
            Number.isNaN(expiry.valueOf()) ||
            expiry.getUTCFullYear() !== year ||
            expiry.getUTCMonth() !== month - 1 ||
            expiry.getUTCDate() !== day
        ) {
            throw new ScanFailure(
                'Malformed scan exception expiry is forbidden.',
            );
        }

        if (expiry < now) {
            throw new ScanFailure('Expired scan exception is forbidden.');
        }

        const identity = [
            exception.kind,
            exception.rule,
            exception.path,
            exception.fingerprint,
        ].join('\0');

        if (seen.has(identity)) {
            throw new ScanFailure('Duplicate scan exception is forbidden.');
        }

        seen.add(identity);
    }

    return raw;
}

function scanBlob(kind, relativePath, bytes) {
    const content = decodeBlob(bytes, relativePath);

    return kind === 'secret'
        ? scanSecrets(relativePath, content)
        : scanPii(relativePath, content);
}

function scanSnapshot(kind, relativePath, bytes, maximum) {
    if (!relativePath.toLowerCase().endsWith('.docx')) {
        return scanBlob(kind, relativePath, bytes);
    }

    try {
        return extractDocxTextParts(bytes, {
            maxTotalBytes: maximum,
        }).flatMap(({ reportId, bytes: content }) =>
            scanBlob(kind, `${relativePath}#${reportId}`, content),
        );
    } catch (error) {
        if (error instanceof OoxmlContentError) {
            throw new ScanFailure(
                `Tracked DOCX is malformed or unsupported: ${relativePath}`,
            );
        }

        throw error;
    }
}

function decodeBlob(bytes, relativePath) {
    let content;

    try {
        if (bytes.subarray(0, 2).equals(Buffer.from([0xff, 0xfe]))) {
            content = new TextDecoder('utf-16le', { fatal: true }).decode(
                bytes.subarray(2),
            );
        } else if (bytes.subarray(0, 2).equals(Buffer.from([0xfe, 0xff]))) {
            content = new TextDecoder('utf-16be', { fatal: true }).decode(
                bytes.subarray(2),
            );
        } else {
            content = new TextDecoder('utf-8', { fatal: true }).decode(bytes);
        }
    } catch {
        throw new ScanFailure(
            `Tracked file has unsupported encoding: ${relativePath}`,
        );
    }

    if ([...content].some((character) => isUnsupportedControl(character))) {
        throw new ScanFailure(
            `Tracked file has unsupported encoding: ${relativePath}`,
        );
    }

    return content;
}

function isUnsupportedControl(character) {
    const code = character.charCodeAt(0);

    return (
        code === 0 ||
        (code >= 1 && code <= 8) ||
        code === 11 ||
        code === 12 ||
        (code >= 14 && code <= 31) ||
        code === 127
    );
}

function scanSecrets(relativePath, content) {
    const matches = [];
    collect(
        matches,
        relativePath,
        content,
        'private_key',
        /-----BEGIN (?:RSA |EC |DSA |OPENSSH )?PRIVATE KEY-----/g,
    );
    collect(
        matches,
        relativePath,
        content,
        'aws_access_key',
        /\bAKIA[0-9A-Z]{16}\b/g,
    );
    collect(
        matches,
        relativePath,
        content,
        'provider_token',
        /\b(?:gh[pousr]_[A-Za-z0-9]{36,255}|xnd_(?:development|production)_[A-Za-z0-9_-]{16,255}|sk_(?:live|prod)_[A-Za-z0-9_-]{16,255})\b/g,
    );
    const assignment =
        /\b(?:api[_-]?key|api[_-]?secret|client[_-]?secret|private[_-]?key|password|access[_-]?token|auth[_-]?token)\b\s*[:=]\s*["']([^"'\r\n]{16,512})["']/gi;

    for (const match of content.matchAll(assignment)) {
        const value = match[1];

        if (!looksLikePlaceholder(value) && entropy(value) >= 3.8) {
            matches.push(
                finding(
                    'secret',
                    'credential_assignment',
                    relativePath,
                    content,
                    match.index + match[0].indexOf(value),
                    value,
                ),
            );
        }
    }

    return deduplicate(matches);
}

function scanPii(relativePath, content) {
    const matches = [];
    const email = /\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,24}\b/gi;

    for (const match of content.matchAll(email)) {
        const value = match[0];
        const before = content.slice(
            Math.max(0, match.index - 12),
            match.index,
        );

        if (
            !['composer.lock', 'package-lock.json'].includes(relativePath) &&
            !isSyntheticEmail(value) &&
            !looksLikeFilename(value) &&
            !before.includes('://')
        ) {
            matches.push(
                finding(
                    'pii',
                    'email',
                    relativePath,
                    content,
                    match.index,
                    value.toLowerCase(),
                ),
            );
        }
    }

    const phone = /(?<![0-9A-Za-z])(?:\+?62|08)[0-9]{8,13}(?![0-9A-Za-z])/g;

    for (const match of content.matchAll(phone)) {
        const digits = match[0].replace(/\D/g, '');

        if (!isSyntheticPhone(digits)) {
            matches.push(
                finding(
                    'pii',
                    'indonesian_phone',
                    relativePath,
                    content,
                    match.index,
                    digits,
                ),
            );
        }
    }

    const nik = /(?<!\d)(\d{16})(?!\d)/g;

    for (const match of content.matchAll(nik)) {
        if (isValidNikShape(match[1])) {
            matches.push(
                finding(
                    'pii',
                    'nik',
                    relativePath,
                    content,
                    match.index,
                    match[1],
                ),
            );
        }
    }

    const identity =
        /\b(?:nik|nomor[_-]?(?:identitas|passport|paspor)|passport[_-]?number)\b\s*[:=]\s*["']([A-Z0-9]{7,24})["']/gi;

    for (const match of content.matchAll(identity)) {
        const value = match[1];

        if (!looksLikePlaceholder(value)) {
            matches.push(
                finding(
                    'pii',
                    'identity_assignment',
                    relativePath,
                    content,
                    match.index + match[0].indexOf(value),
                    value,
                ),
            );
        }
    }

    return deduplicate(matches);
}

function collect(target, relativePath, content, rule, expression) {
    for (const match of content.matchAll(expression)) {
        target.push(
            finding(
                'secret',
                rule,
                relativePath,
                content,
                match.index,
                match[0],
            ),
        );
    }
}

function finding(kind, rule, relativePath, content, index) {
    const result = {
        kind,
        rule,
        path: relativePath,
        line: 1 + countNewlines(content, index),
    };

    if (kind === 'secret') {
        const blobDigest = createHash('sha256').update(content).digest('hex');
        result.fingerprint = createHash('sha256')
            .update(kind)
            .update('\0')
            .update(rule)
            .update('\0')
            .update(relativePath)
            .update('\0')
            .update(String(index))
            .update('\0')
            .update(blobDigest)
            .digest('hex');
    }

    return result;
}

function countNewlines(content, end) {
    let count = 0;

    for (let index = 0; index < end; index += 1) {
        if (content.charCodeAt(index) === 10) {
            count += 1;
        }
    }

    return count;
}

function deduplicate(findings) {
    return [
        ...new Map(
            findings.map((item) => [
                [item.rule, item.path, item.line, item.fingerprint].join('\0'),
                item,
            ]),
        ).values(),
    ];
}

function entropy(value) {
    const frequencies = new Map();

    for (const character of value) {
        frequencies.set(character, (frequencies.get(character) ?? 0) + 1);
    }

    let result = 0;

    for (const count of frequencies.values()) {
        const probability = count / value.length;
        result -= probability * Math.log2(probability);
    }

    return result;
}

function looksLikePlaceholder(value) {
    return (
        /^\$\{[A-Z][A-Z0-9_]*\}$/i.test(value) ||
        /^(?:placeholder|changeme|dummy|synthetic|test-secret)$/i.test(value) ||
        /^your[_-][a-z0-9_-]+$/i.test(value) ||
        /^(.)\1+$/.test(value)
    );
}

function isSyntheticEmail(value) {
    const normalized = value.toLowerCase();

    return (
        normalized === 'nama@email.com' ||
        /@(example\.(?:com|net|org)|[^@]+\.(?:test|invalid)|localhost)$/.test(
            normalized,
        )
    );
}

function isSyntheticPhone(digits) {
    return SYNTHETIC_PHONES.has(digits);
}

function looksLikeFilename(value) {
    return /\.(?:php|js|mjs|cjs|ts|tsx|jsx|json|md|txt|py|css|html)$/i.test(
        value,
    );
}

function isValidNikShape(value) {
    let day = Number(value.slice(6, 8));

    if (day > 40) {
        day -= 40;
    }

    const month = Number(value.slice(8, 10));
    const year = Number(value.slice(10, 12));

    if (day < 1 || day > 31 || month < 1 || month > 12) {
        return false;
    }

    const fullYear = year <= 30 ? 2000 + year : 1900 + year;
    const date = new Date(Date.UTC(fullYear, month - 1, day));

    return (
        date.getUTCFullYear() === fullYear &&
        date.getUTCMonth() === month - 1 &&
        date.getUTCDate() === day
    );
}

function isPlainObject(value) {
    return (
        value !== null &&
        typeof value === 'object' &&
        !Array.isArray(value) &&
        Object.getPrototypeOf(value) === Object.prototype
    );
}

function sameKeys(value, expected) {
    return (
        Object.keys(value).sort().join('\0') === [...expected].sort().join('\0')
    );
}

function isInside(root, target) {
    const relative = path.relative(root, target);

    return (
        relative !== '' &&
        !relative.startsWith(`..${path.sep}`) &&
        relative !== '..' &&
        !path.isAbsolute(relative)
    );
}

async function main() {
    const argument = process.argv.find((value) => value.startsWith('--kind='));
    const kind = argument?.slice('--kind='.length);

    try {
        const result = await scanRepository({
            root: process.cwd(),
            kind,
            policyPath: path.join(
                process.cwd(),
                'tools/security/repository-content-scan-policy.json',
            ),
        });
        process.stdout.write(
            `${result.kind.toUpperCase()} scan passed (${result.scanned} tracked paths; index and working-tree snapshots).\n`,
        );
    } catch (error) {
        if (error instanceof ScanFailure) {
            process.stderr.write(`${error.message}\n`);

            for (const item of error.findings) {
                const fingerprint = item.fingerprint
                    ? ` fingerprint:${item.fingerprint}`
                    : '';

                process.stderr.write(
                    `${item.kind} ${item.rule} ${item.path}:${item.line}${fingerprint}\n`,
                );
            }

            process.exitCode = 1;

            return;
        }

        process.stderr.write('Repository content scan failed unexpectedly.\n');
        process.exitCode = 1;
    }
}

if (
    process.argv[1] &&
    import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href
) {
    await main();
}
