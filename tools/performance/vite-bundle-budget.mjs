/**
 * Deterministic, report-only Vite bundle budget measurement.
 *
 * This reads an existing build; it never invokes Vite or changes build output.
 * Initial JS and entry CSS are hard budgets. The 500 KiB raw chunk threshold is
 * warning-only because it mirrors Vite's diagnostic and is not a transfer-size
 * user metric. Reports and refusals contain no host or asset paths.
 */

import { lstat, readFile, realpath } from 'node:fs/promises';
import { extname, isAbsolute, relative, resolve, sep } from 'node:path';
import process from 'node:process';
import { pathToFileURL } from 'node:url';
import { gzipSync } from 'node:zlib';

export class BundleBudgetRefused extends Error {
    constructor() {
        super('bundle_budget');
        this.name = 'BundleBudgetRefused';
    }
}

export const BUDGETS = Object.freeze({
    initialJsGzipBytes: 200 * 1024,
    entryCssGzipBytes: 50 * 1024,
    largestChunkRawBytes: 500 * 1024,
});

const MAX_MANIFEST_BYTES = 2 * 1024 * 1024;
const MAX_ASSET_BYTES = 64 * 1024 * 1024;
const MAX_TOTAL_ASSET_BYTES = 256 * 1024 * 1024;
const MAX_ENTRIES = 4096;
const RECORD_KEYS = new Set([
    'assets',
    'css',
    'dynamicImports',
    'file',
    'imports',
    'isDynamicEntry',
    'isEntry',
    'name',
    'names',
    'src',
]);
const MEDIA_EXTENSIONS = new Set([
    '.avif',
    '.gif',
    '.ico',
    '.jpeg',
    '.jpg',
    '.otf',
    '.png',
    '.svg',
    '.ttf',
    '.webp',
    '.woff',
    '.woff2',
]);

function refuse() {
    throw new BundleBudgetRefused();
}

function safeRelative(value) {
    if (
        typeof value !== 'string' ||
        value.length < 1 ||
        value.length > 4096 ||
        value.includes('\\') ||
        value.includes('\0') ||
        isAbsolute(value)
    ) {
        refuse();
    }

    const parts = value.split('/');

    if (parts.some((part) => !part || part === '.' || part === '..')) {
        refuse();
    }

    return value;
}

function stringArray(value) {
    if (
        !Array.isArray(value) ||
        value.length > MAX_ENTRIES ||
        value.some((item) => typeof item !== 'string') ||
        new Set(value).size !== value.length
    ) {
        refuse();
    }

    return value;
}

function validateManifest(value) {
    if (value === null || Array.isArray(value) || typeof value !== 'object') {
        refuse();
    }

    const keys = Object.keys(value);

    if (keys.length < 1 || keys.length > MAX_ENTRIES) {
        refuse();
    }

    let entryKey;
    const cssEntries = [];

    for (const key of keys) {
        safeRelative(key.startsWith('_') ? key.slice(1) : key);
        const record = value[key];

        if (
            record === null ||
            Array.isArray(record) ||
            typeof record !== 'object' ||
            Object.keys(record).some((field) => !RECORD_KEYS.has(field))
        ) {
            refuse();
        }

        if (typeof record.file !== 'string') {
            refuse();
        }

        safeRelative(record.file);

        for (const field of ['name', 'src']) {
            if (
                field in record &&
                (typeof record[field] !== 'string' ||
                    record[field].length < 1 ||
                    record[field].length > 4096)
            ) {
                refuse();
            }
        }

        for (const field of ['isEntry', 'isDynamicEntry']) {
            if (field in record && typeof record[field] !== 'boolean') {
                refuse();
            }
        }

        for (const field of ['imports', 'dynamicImports']) {
            if (field in record) {
                stringArray(record[field]);

                if (
                    record[field].some((dependency) => !(dependency in value))
                ) {
                    refuse();
                }
            }
        }

        if ('names' in record) {
            stringArray(record.names);
        }

        for (const field of ['css', 'assets']) {
            if (field in record) {
                stringArray(record[field]).forEach(safeRelative);
            }
        }

        if (record.isEntry === true) {
            const extension = extname(record.file).toLowerCase();

            if (extension === '.js') {
                if (entryKey !== undefined) {
                    refuse();
                }

                entryKey = key;
            } else if (extension === '.css') {
                cssEntries.push(key);
            } else {
                refuse();
            }
        }
    }

    if (entryKey === undefined || cssEntries.length !== 1) {
        refuse();
    }

    return { entryKey, cssEntryKey: cssEntries[0] };
}

async function directoryState(buildReal) {
    const state = await lstat(buildReal);

    if (!state.isDirectory() || state.isSymbolicLink()) {
        refuse();
    }

    return { dev: state.dev, ino: state.ino };
}

async function guardedFile(buildReal, expectedDirectory, assetPath) {
    const directoryBefore = await directoryState(buildReal);

    if (
        directoryBefore.dev !== expectedDirectory.dev ||
        directoryBefore.ino !== expectedDirectory.ino
    ) {
        refuse();
    }

    safeRelative(assetPath);
    const linked = resolve(buildReal, ...assetPath.split('/'));
    const lexical = relative(buildReal, linked);

    if (
        !lexical ||
        lexical === '..' ||
        lexical.startsWith(`..${sep}`) ||
        isAbsolute(lexical)
    ) {
        refuse();
    }

    const before = await lstat(linked);

    if (
        !before.isFile() ||
        before.isSymbolicLink() ||
        before.size < 0 ||
        before.size > MAX_ASSET_BYTES
    ) {
        refuse();
    }

    const final = await realpath(linked);
    const escaped = relative(buildReal, final);

    if (
        !escaped ||
        escaped === '..' ||
        escaped.startsWith(`..${sep}`) ||
        isAbsolute(escaped) ||
        final !== linked
    ) {
        refuse();
    }

    const bytes = await readFile(linked);
    const after = await lstat(linked);

    if (
        !after.isFile() ||
        after.isSymbolicLink() ||
        bytes.length !== before.size ||
        before.dev !== after.dev ||
        before.ino !== after.ino ||
        before.size !== after.size ||
        before.mtimeMs !== after.mtimeMs ||
        before.ctimeMs !== after.ctimeMs
    ) {
        refuse();
    }

    const directoryAfter = await directoryState(buildReal);

    if (
        directoryAfter.dev !== expectedDirectory.dev ||
        directoryAfter.ino !== expectedDirectory.ino
    ) {
        refuse();
    }

    return bytes;
}

function frozenReport(value) {
    Object.freeze(value.budgets);
    Object.freeze(value.policy);
    Object.freeze(value.warnings);

    return Object.freeze(value);
}

export async function measureBundle(buildDirectory) {
    try {
        if (typeof buildDirectory !== 'string' || !isAbsolute(buildDirectory)) {
            refuse();
        }

        const buildReal = await realpath(buildDirectory);

        if (buildReal !== resolve(buildDirectory)) {
            refuse();
        }

        const buildState = await directoryState(buildReal);
        const manifestBytes = await guardedFile(
            buildReal,
            buildState,
            'manifest.json',
        );

        if (
            manifestBytes.length < 2 ||
            manifestBytes.length > MAX_MANIFEST_BYTES
        ) {
            refuse();
        }

        let manifest;

        try {
            manifest = JSON.parse(manifestBytes.toString('utf8'));
        } catch {
            refuse();
        }

        if (
            JSON.stringify(manifest, null, 2) !== manifestBytes.toString('utf8')
        ) {
            refuse();
        }

        const { entryKey, cssEntryKey } = validateManifest(manifest);
        const initialKeys = new Set();
        const pending = [entryKey];

        while (pending.length > 0) {
            const key = pending.pop();

            if (initialKeys.has(key)) {
                continue;
            }

            initialKeys.add(key);
            pending.push(...(manifest[key].imports ?? []));
        }

        const initialJs = new Set();
        const entryCss = new Set();
        entryCss.add(manifest[cssEntryKey].file);
        const allFiles = new Set();
        const dynamicImports = new Set();

        for (const [key, record] of Object.entries(manifest)) {
            allFiles.add(record.file);

            for (const file of record.css ?? []) {
                allFiles.add(file);
            }

            for (const file of record.assets ?? []) {
                allFiles.add(file);
            }

            if (initialKeys.has(key)) {
                if (extname(record.file).toLowerCase() === '.js') {
                    initialJs.add(record.file);
                }

                for (const file of record.css ?? []) {
                    entryCss.add(file);
                }

                for (const dependency of record.dynamicImports ?? []) {
                    dynamicImports.add(dependency);
                }
            }
        }

        const bytesByFile = new Map();
        let totalBytes = 0;

        for (const file of [...allFiles].sort()) {
            const bytes = await guardedFile(buildReal, buildState, file);
            totalBytes += bytes.length;

            if (totalBytes > MAX_TOTAL_ASSET_BYTES) {
                refuse();
            }

            bytesByFile.set(file, bytes);
        }

        const gzipSize = (files) =>
            [...files]
                .sort()
                .reduce(
                    (total, file) =>
                        total +
                        gzipSync(bytesByFile.get(file), { level: 9 }).length,
                    0,
                );
        const jsSizes = [...bytesByFile]
            .filter(([file]) => extname(file).toLowerCase() === '.js')
            .map(([, bytes]) => bytes.length);

        if (jsSizes.length < 1) {
            refuse();
        }

        const media = [...bytesByFile].filter(([file]) =>
            MEDIA_EXTENSIONS.has(extname(file).toLowerCase()),
        );
        const initialJsGzipBytes = gzipSize(initialJs);
        const entryCssGzipBytes = gzipSize(entryCss);
        const largestChunkRawBytes = Math.max(...jsSizes);
        const warnings =
            largestChunkRawBytes >= BUDGETS.largestChunkRawBytes
                ? ['largest_chunk_raw_at_or_above_500_kib']
                : [];

        return frozenReport({
            version: 1,
            initialJsGzipBytes,
            entryCssGzipBytes,
            largestChunkRawBytes,
            fontImageRawBytes: media.reduce(
                (total, [, bytes]) => total + bytes.length,
                0,
            ),
            fontImageCount: media.length,
            dynamicImportCount: dynamicImports.size,
            budgets: { ...BUDGETS },
            policy: {
                initialJsGzip: 'fail',
                entryCssGzip: 'fail',
                largestChunkRaw: 'warn',
            },
            passed:
                initialJsGzipBytes < BUDGETS.initialJsGzipBytes &&
                entryCssGzipBytes < BUDGETS.entryCssGzipBytes,
            warnings,
        });
    } catch (error) {
        if (error instanceof BundleBudgetRefused) {
            throw error;
        }

        throw new BundleBudgetRefused();
    }
}

if (
    process.argv[1] &&
    import.meta.url === pathToFileURL(resolve(process.argv[1])).href
) {
    try {
        const build = resolve(process.argv[2] ?? 'public/build');
        const report = await measureBundle(build);
        process.stdout.write(`${JSON.stringify(report)}\n`);

        if (!report.passed) {
            process.exitCode = 1;
        }
    } catch {
        process.stderr.write('{"error":"bundle_budget"}\n');
        process.exitCode = 2;
    }
}
