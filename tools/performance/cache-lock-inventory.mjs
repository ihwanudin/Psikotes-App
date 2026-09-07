/**
 * Static, report-only inventory of the repository's current cache and unique
 * job lock declarations. It reads a fixed allowlist and never reads .env,
 * vendor, generated output, or a live cache. The result is not evidence of
 * Redis availability, hit rates, invalidation behavior, or lock efficacy.
 */

import { lstat, readFile, realpath, stat } from 'node:fs/promises';
import { isAbsolute, relative, resolve, sep } from 'node:path';
import process from 'node:process';
import { pathToFileURL } from 'node:url';

export class CacheLockInventoryRefused extends Error {
    constructor() {
        super('cache_lock_inventory');
        this.name = 'CacheLockInventoryRefused';
    }
}

const SOURCE_FILES = Object.freeze([
    'app/Http/Middleware/AuthenticateSelectionResultPoll.php',
    'app/Jobs/DispatchGenericAssessmentResultCallback.php',
    'app/Jobs/DeliverIntegrationCallbackJob.php',
    'app/Jobs/DeliverOutboxMessage.php',
    'app/Providers/AppServiceProvider.php',
    'config/cache.php',
    'config/database.php',
]);
const MAX_FILE_BYTES = 512 * 1024;
const MAX_TOTAL_BYTES = 2 * 1024 * 1024;

function refuse() {
    throw new CacheLockInventoryRefused();
}

function freeze(value) {
    if (
        value !== null &&
        typeof value === 'object' &&
        !Object.isFrozen(value)
    ) {
        for (const child of Object.values(value)) {
            freeze(child);
        }

        Object.freeze(value);
    }

    return value;
}

function samePath(left, right) {
    return process.platform === 'win32'
        ? left.toLowerCase() === right.toLowerCase()
        : left === right;
}

function inside(root, target) {
    const child = relative(root, target);

    return child !== '' && child !== '..' && !child.startsWith(`..${sep}`);
}

function sameIdentity(left, right) {
    return (
        left.dev === right.dev &&
        left.ino === right.ino &&
        left.size === right.size &&
        left.mtimeNs === right.mtimeNs &&
        left.ctimeNs === right.ctimeNs
    );
}

function stripComments(source) {
    let output = '';
    let index = 0;
    let quote = null;

    while (index < source.length) {
        const current = source[index];
        const next = source[index + 1];

        if (quote !== null) {
            output += current;

            if (current === '\\') {
                if (index + 1 >= source.length) {
                    refuse();
                }

                output += source[index + 1];
                index += 2;
                continue;
            }

            if (current === quote) {
                quote = null;
            }

            index += 1;
            continue;
        }

        if (current === "'" || current === '"') {
            quote = current;
            output += current;
            index += 1;
            continue;
        }

        if (current === '/' && next === '/') {
            while (index < source.length && source[index] !== '\n') {
                index += 1;
            }

            output += '\n';
            index += 1;
            continue;
        }

        if (current === '/' && next === '*') {
            const end = source.indexOf('*/', index + 2);

            if (end < 0) {
                refuse();
            }

            output += ' ';
            index = end + 2;
            continue;
        }

        if (current === '#' && next !== '[') {
            while (index < source.length && source[index] !== '\n') {
                index += 1;
            }

            output += '\n';
            index += 1;
            continue;
        }

        output += current;
        index += 1;
    }

    if (quote !== null) {
        refuse();
    }

    return output;
}

function matches(source, pattern) {
    return [...source.matchAll(pattern)];
}

function exactlyOne(source, pattern) {
    const found = matches(source, pattern);

    if (found.length !== 1) {
        refuse();
    }

    return found[0];
}

function cacheOperation(source, path) {
    const all = matches(
        source,
        /\bCache\s*::\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/g,
    );

    if (all.length !== 1 || all[0][1] !== 'add') {
        refuse();
    }

    const call = exactlyOne(
        source,
        /\bCache\s*::\s*add\s*\([^,\r\n]+,\s*true\s*,\s*([0-9]+)\s*\)/g,
    );
    const ttlSeconds = Number(call[1]);

    if (ttlSeconds !== 70) {
        refuse();
    }

    return { operation: 'add', ttlSeconds, source: path };
}

function uniqueJob(source, path, expectedClass, expectedTtl) {
    const attributes = matches(
        source,
        /#\[\s*UniqueFor\s*\(\s*([0-9]+)\s*\)\s*\]/g,
    );

    if (attributes.length !== 1 || Number(attributes[0][1]) !== expectedTtl) {
        refuse();
    }

    const declarations = matches(
        source,
        /\b(?:final\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)\s+implements\s+([^{]+){/g,
    );

    if (
        declarations.length !== 1 ||
        declarations[0][1] !== expectedClass ||
        matches(declarations[0][2], /\bShouldBeUnique\b/g).length !== 1
    ) {
        refuse();
    }

    if (
        matches(
            source,
            /\bpublic\s+function\s+uniqueId\s*\(\s*\)\s*:\s*string\b/g,
        ).length !== 1
    ) {
        refuse();
    }

    return {
        className: expectedClass,
        ttlSeconds: expectedTtl,
        uniqueIdMethod: true,
        source: path,
    };
}

function validateProductionRequirement(source) {
    exactlyOne(
        source,
        /['"]CACHE_STORE_REDIS['"]\s*=>\s*config\(\s*['"]cache\.default['"]\s*\)\s*===\s*['"]redis['"]/g,
    );
}

function validateCacheConfig(source) {
    exactlyOne(
        source,
        /['"]default['"]\s*=>\s*env\(\s*['"]CACHE_STORE['"]\s*,/g,
    );
    exactlyOne(
        source,
        /['"]prefix['"]\s*=>\s*env\(\s*['"]CACHE_PREFIX['"]\s*,/g,
    );
    exactlyOne(source, /['"]serializable_classes['"]\s*=>\s*false\b/g);

    for (const [store, driver] of [
        ['database', 'database'],
        ['redis', 'redis'],
    ]) {
        exactlyOne(
            source,
            new RegExp(
                `['"]${store}['"]\\s*=>\\s*\\[[\\s\\S]{0,700}?['"]driver['"]\\s*=>\\s*['"]${driver}['"]`,
                'g',
            ),
        );
    }

    for (const environment of [
        'DB_CACHE_LOCK_CONNECTION',
        'DB_CACHE_LOCK_TABLE',
        'REDIS_CACHE_CONNECTION',
        'REDIS_CACHE_LOCK_CONNECTION',
    ]) {
        exactlyOne(source, new RegExp(`env\\(\\s*['"]${environment}['"]`, 'g'));
    }
}

function validateDatabaseConfig(source) {
    exactlyOne(
        source,
        /['"]cache['"]\s*=>\s*\[[\s\S]{0,1200}?['"]database['"]\s*=>\s*env\(\s*['"]REDIS_CACHE_DB['"]\s*,/g,
    );
}

async function readAllowlisted(root, relativePath, rootIdentity, identities) {
    const target = resolve(root, ...relativePath.split('/'));

    if (!inside(root, target)) {
        refuse();
    }

    const beforeLink = await lstat(target, { bigint: true });

    if (!beforeLink.isFile() || beforeLink.isSymbolicLink()) {
        refuse();
    }

    const linked = await realpath(target);

    if (!samePath(linked, target) || !inside(root, linked)) {
        refuse();
    }

    const before = await stat(target, { bigint: true });

    if (
        !before.isFile() ||
        before.size < 1n ||
        before.size > BigInt(MAX_FILE_BYTES)
    ) {
        refuse();
    }

    const identity = `${before.dev}:${before.ino}`;

    if (identities.has(identity)) {
        refuse();
    }

    identities.add(identity);

    const bytes = await readFile(target);
    const after = await stat(target, { bigint: true });
    const rootAfter = await stat(root, { bigint: true });

    if (
        BigInt(bytes.length) !== before.size ||
        !sameIdentity(before, after) ||
        rootAfter.dev !== rootIdentity.dev ||
        rootAfter.ino !== rootIdentity.ino
    ) {
        refuse();
    }

    let source;

    try {
        source = new TextDecoder('utf-8', { fatal: true }).decode(bytes);
    } catch {
        refuse();
    }

    if (source.includes('\0')) {
        refuse();
    }

    return { source: stripComments(source), size: bytes.length };
}

export async function inspectCacheLockInventory(sourceRoot) {
    try {
        if (
            typeof sourceRoot !== 'string' ||
            sourceRoot.length < 1 ||
            sourceRoot.length > 4096 ||
            sourceRoot.includes('\0') ||
            !isAbsolute(sourceRoot) ||
            resolve(sourceRoot) !== sourceRoot
        ) {
            refuse();
        }

        const casefold = SOURCE_FILES.map((path) => path.toLowerCase());

        if (new Set(casefold).size !== SOURCE_FILES.length) {
            refuse();
        }

        const rootLink = await lstat(sourceRoot, { bigint: true });

        if (!rootLink.isDirectory() || rootLink.isSymbolicLink()) {
            refuse();
        }

        const canonicalRoot = await realpath(sourceRoot);

        if (!samePath(canonicalRoot, sourceRoot)) {
            refuse();
        }

        const rootIdentity = await stat(sourceRoot, { bigint: true });

        const sources = new Map();
        const identities = new Set();
        let totalBytes = 0;

        for (const relativePath of SOURCE_FILES) {
            const item = await readAllowlisted(
                sourceRoot,
                relativePath,
                rootIdentity,
                identities,
            );
            totalBytes += item.size;

            if (totalBytes > MAX_TOTAL_BYTES) {
                refuse();
            }

            sources.set(relativePath, item.source);
        }

        const cachePath =
            'app/Http/Middleware/AuthenticateSelectionResultPoll.php';
        const uniquePaths = new Set([
            'app/Jobs/DispatchGenericAssessmentResultCallback.php',
            'app/Jobs/DeliverIntegrationCallbackJob.php',
            'app/Jobs/DeliverOutboxMessage.php',
        ]);

        for (const [relativePath, source] of sources) {
            const cacheReferences = matches(
                source,
                /\bCache\s*::\s*[A-Za-z_][A-Za-z0-9_]*\s*\(/g,
            );
            const uniqueAttributes = matches(source, /#\[\s*UniqueFor\s*\(/g);
            const uniqueInterfaces = matches(source, /\bShouldBeUnique\b/g);

            if (
                (relativePath === cachePath) !==
                    (cacheReferences.length === 1) ||
                (!uniquePaths.has(relativePath) &&
                    (uniqueAttributes.length !== 0 ||
                        uniqueInterfaces.length !== 0))
            ) {
                refuse();
            }
        }

        const cacheFacadeOperations = [
            cacheOperation(sources.get(cachePath), cachePath),
        ];
        const uniqueJobLocks = [
            uniqueJob(
                sources.get(
                    'app/Jobs/DispatchGenericAssessmentResultCallback.php',
                ),
                'app/Jobs/DispatchGenericAssessmentResultCallback.php',
                'DispatchGenericAssessmentResultCallback',
                300,
            ),
            uniqueJob(
                sources.get('app/Jobs/DeliverIntegrationCallbackJob.php'),
                'app/Jobs/DeliverIntegrationCallbackJob.php',
                'DeliverIntegrationCallbackJob',
                900,
            ),
            uniqueJob(
                sources.get('app/Jobs/DeliverOutboxMessage.php'),
                'app/Jobs/DeliverOutboxMessage.php',
                'DeliverOutboxMessage',
                900,
            ),
        ];

        validateProductionRequirement(
            sources.get('app/Providers/AppServiceProvider.php'),
        );
        validateCacheConfig(sources.get('config/cache.php'));
        validateDatabaseConfig(sources.get('config/database.php'));

        return freeze({
            version: 1,
            staticEvidenceOnly: true,
            inspectedFiles: [...SOURCE_FILES].sort(),
            cacheFacadeOperations,
            uniqueJobLocks,
            declarations: {
                defaultStoreEnvironment: 'CACHE_STORE',
                productionRequiresRedis: true,
                cachePrefixEnvironment: 'CACHE_PREFIX',
                redisCacheDatabaseEnvironment: 'REDIS_CACHE_DB',
                configuredLockStores: ['database', 'redis'],
                serializedClassesAllowed: false,
            },
            limitations: [
                'allowlisted_source_only',
                'no_runtime_store_proof',
                'no_hit_rate_proof',
                'no_invalidation_proof',
            ],
        });
    } catch (error) {
        if (error instanceof CacheLockInventoryRefused) {
            throw error;
        }

        throw new CacheLockInventoryRefused();
    }
}

if (
    process.argv[1] &&
    import.meta.url === pathToFileURL(process.argv[1]).href
) {
    inspectCacheLockInventory(process.argv[2])
        .then((report) => process.stdout.write(`${JSON.stringify(report)}\n`))
        .catch(() => {
            process.stderr.write('cache_lock_inventory\n');
            process.exitCode = 1;
        });
}
