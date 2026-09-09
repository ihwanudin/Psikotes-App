import { createHash } from 'node:crypto';
import { lstat, readFile, realpath } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';
import { spawnSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';

const KINDS = new Set(['secret', 'pii']);
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
        if (entry.stage !== 0) {
            throw new ScanFailure(
                `Unmerged tracked entry is unsupported: ${entry.path}`,
            );
        }

        if (!['100644', '100755'].includes(entry.mode)) {
            throw new ScanFailure(
                `Tracked symlink or unsupported mode is forbidden: ${entry.path}`,
            );
        }

        const target = path.resolve(canonicalRoot, entry.path);

        if (!isInside(canonicalRoot, target)) {
            throw new ScanFailure(
                `Tracked path escapes repository: ${entry.path}`,
            );
        }

        const info = await lstat(target).catch(() => {
            throw new ScanFailure(`Tracked file is unreadable: ${entry.path}`);
        });

        if (!info.isFile() || info.isSymbolicLink()) {
            throw new ScanFailure(
                `Tracked symlink or unsupported file is forbidden: ${entry.path}`,
            );
        }

        if (info.size > policy.max_blob_bytes) {
            throw new ScanFailure(
                `Tracked file exceeds the configured maximum: ${entry.path}`,
            );
        }

        const bytes = await readFile(target).catch(() => {
            throw new ScanFailure(`Tracked file is unreadable: ${entry.path}`);
        });

        if (bytes.length !== info.size) {
            throw new ScanFailure(
                `Tracked file changed while scanning: ${entry.path}`,
            );
        }

        findings.push(
            ...scanBlob(kind, entry.path.replaceAll('\\', '/'), bytes),
        );
    }

    findings.sort(
        (a, b) =>
            a.path.localeCompare(b.path) ||
            a.line - b.line ||
            a.rule.localeCompare(b.rule),
    );
    const applicable = policy.exceptions.filter(
        (exception) => exception.kind === kind,
    );
    const used = new Set();
    const unsuppressed = findings.filter((finding) => {
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
    const content = bytes.toString('latin1');

    return kind === 'secret'
        ? scanSecrets(relativePath, content)
        : scanPii(relativePath, content);
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

function finding(kind, rule, relativePath, content, index, value) {
    return {
        kind,
        rule,
        path: relativePath,
        line: 1 + countNewlines(content, index),
        fingerprint: createHash('sha256')
            .update(kind)
            .update('\0')
            .update(rule)
            .update('\0')
            .update(relativePath)
            .update('\0')
            .update(value)
            .digest('hex'),
    };
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
    const normalized = value.toLowerCase();

    return (
        normalized.includes('${') ||
        normalized.includes('example') ||
        normalized.includes('placeholder') ||
        normalized.includes('changeme') ||
        normalized.includes('dummy') ||
        normalized.includes('synthetic') ||
        normalized.includes('your_') ||
        normalized.includes('your-') ||
        normalized.includes('test-secret') ||
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
    return (
        /(\d)\1{3,}/.test(digits) ||
        /012345|123456|234567|345678|456789/.test(digits) ||
        /^(?:62|08)(\d)\1{7,}$/.test(digits)
    );
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
            `${result.kind.toUpperCase()} scan passed (${result.scanned} tracked blobs; current snapshot only).\n`,
        );
    } catch (error) {
        if (error instanceof ScanFailure) {
            process.stderr.write(`${error.message}\n`);

            for (const item of error.findings) {
                process.stderr.write(
                    `${item.kind} ${item.rule} ${item.path}:${item.line} sha256:${item.fingerprint}\n`,
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
