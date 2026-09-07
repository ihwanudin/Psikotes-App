/**
 * Static, report-only inventory of the repository's current cache and unique
 * job lock declarations. It is restricted to a fixed allowlist in a trusted,
 * quiescent working tree and never intentionally selects .env, vendor,
 * generated output, or a live cache. Path checks are defense in depth, not a
 * race-proof filesystem authority. The result is not evidence of Redis
 * availability, hit rates, invalidation behavior, or lock efficacy.
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

function scanSource(source) {
    let code = '';
    const tokens = [];
    let index = 0;

    while (index < source.length) {
        const current = source[index];
        const next = source[index + 1];

        if (source.startsWith('<<<', index)) {
            refuse();
        }

        if (current === "'" || current === '"' || current === '`') {
            const quote = current;
            let value = '';
            let simple = quote !== '`';
            let closed = false;
            code += ' ';
            index += 1;

            while (index < source.length) {
                const character = source[index];

                if (character === '\n' || character === '\r') {
                    simple = false;
                    code += character;
                    index += 1;
                    continue;
                }

                code += ' ';

                if (character === quote) {
                    closed = true;
                    index += 1;
                    break;
                }

                if (character === '\\') {
                    if (index + 1 >= source.length) {
                        refuse();
                    }

                    const escaped = source[index + 1];
                    code +=
                        escaped === '\n' || escaped === '\r' ? escaped : ' ';

                    if (
                        quote === "'" &&
                        (escaped === "'" || escaped === '\\')
                    ) {
                        value += escaped;
                    } else {
                        simple = false;
                    }

                    index += 2;
                    continue;
                }

                if (quote === '"' && character === '$') {
                    simple = false;
                }

                value += character;
                index += 1;
            }

            if (!closed) {
                refuse();
            }

            tokens.push({ kind: 'string', value: simple ? value : null });
            continue;
        }

        if (current === '/' && next === '/') {
            while (index < source.length && source[index] !== '\n') {
                code += ' ';
                index += 1;
            }

            continue;
        }

        if (current === '/' && next === '*') {
            const end = source.indexOf('*/', index + 2);

            if (end < 0) {
                refuse();
            }

            while (index < end + 2) {
                code += source[index] === '\n' ? '\n' : ' ';
                index += 1;
            }

            continue;
        }

        if (current === '#' && next !== '[') {
            while (index < source.length && source[index] !== '\n') {
                code += ' ';
                index += 1;
            }

            continue;
        }

        if (/[A-Za-z_]/.test(current)) {
            let end = index + 1;

            while (end < source.length && /[A-Za-z0-9_\\]/.test(source[end])) {
                end += 1;
            }

            const value = source.slice(index, end);
            tokens.push({ kind: 'identifier', value });
            code += value;
            index = end;
            continue;
        }

        if (/[0-9]/.test(current)) {
            let end = index + 1;

            while (end < source.length && /[0-9]/.test(source[end])) {
                end += 1;
            }

            const value = source.slice(index, end);
            tokens.push({ kind: 'number', value });
            code += value;
            index = end;
            continue;
        }

        const operator = source.startsWith('===', index)
            ? '==='
            : source.startsWith('=>', index)
              ? '=>'
              : null;

        if (operator !== null) {
            tokens.push({ kind: 'symbol', value: operator });
            code += operator;
            index += operator.length;
            continue;
        }

        if ('()[],.'.includes(current)) {
            tokens.push({ kind: 'symbol', value: current });
        }

        code += current;
        index += 1;
    }

    return { code, tokens };
}

function tokenIs(token, kind, value) {
    return token?.kind === kind && token.value === value;
}

function keyedValues(tokens, key, topLevelOnly = true) {
    const values = [];
    let depth = 0;

    for (let index = 0; index + 1 < tokens.length; index += 1) {
        if (tokenIs(tokens[index], 'symbol', '[')) {
            depth += 1;
            continue;
        }

        if (tokenIs(tokens[index], 'symbol', ']')) {
            depth -= 1;

            if (depth < 0) {
                refuse();
            }

            continue;
        }

        if (
            (!topLevelOnly || depth === 0) &&
            tokenIs(tokens[index], 'string', key) &&
            tokenIs(tokens[index + 1], 'symbol', '=>')
        ) {
            values.push(index + 2);
        }
    }

    return values;
}

function exactlyOneKey(tokens, key, topLevelOnly = true) {
    const values = keyedValues(tokens, key, topLevelOnly);

    if (values.length !== 1) {
        refuse();
    }

    return values[0];
}

function returnedArray(tokens) {
    const starts = [];

    for (let index = 0; index + 1 < tokens.length; index += 1) {
        if (
            tokenIs(tokens[index], 'identifier', 'return') &&
            tokenIs(tokens[index + 1], 'symbol', '[')
        ) {
            starts.push(index + 1);
        }
    }

    if (starts.length !== 1) {
        refuse();
    }

    let depth = 0;

    for (let index = starts[0]; index < tokens.length; index += 1) {
        if (tokenIs(tokens[index], 'symbol', '[')) {
            depth += 1;
        } else if (tokenIs(tokens[index], 'symbol', ']')) {
            depth -= 1;

            if (depth === 0) {
                return tokens.slice(starts[0] + 1, index);
            }
        }
    }

    refuse();
}

function arrayForKey(tokens, key) {
    const start = exactlyOneKey(tokens, key);

    if (!tokenIs(tokens[start], 'symbol', '[')) {
        refuse();
    }

    let depth = 0;

    for (let index = start; index < tokens.length; index += 1) {
        if (tokenIs(tokens[index], 'symbol', '[')) {
            depth += 1;
        } else if (tokenIs(tokens[index], 'symbol', ']')) {
            depth -= 1;

            if (depth === 0) {
                return tokens.slice(start + 1, index);
            }
        }
    }

    refuse();
}

function requireEnvKey(tokens, key, environment) {
    const start = exactlyOneKey(tokens, key);

    if (
        !tokenIs(tokens[start], 'identifier', 'env') ||
        !tokenIs(tokens[start + 1], 'symbol', '(') ||
        !tokenIs(tokens[start + 2], 'string', environment) ||
        (!tokenIs(tokens[start + 3], 'symbol', ',') &&
            !tokenIs(tokens[start + 3], 'symbol', ')'))
    ) {
        refuse();
    }
}

function requireStringKey(tokens, key, expected) {
    const start = exactlyOneKey(tokens, key);

    if (!tokenIs(tokens[start], 'string', expected)) {
        refuse();
    }
}

function requireFalseKey(tokens, key) {
    const start = exactlyOneKey(tokens, key);

    if (!tokenIs(tokens[start], 'identifier', 'false')) {
        refuse();
    }
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

function validateProductionRequirement(tokens) {
    const start = exactlyOneKey(tokens, 'CACHE_STORE_REDIS', false);

    if (
        !tokenIs(tokens[start], 'identifier', 'config') ||
        !tokenIs(tokens[start + 1], 'symbol', '(') ||
        !tokenIs(tokens[start + 2], 'string', 'cache.default') ||
        !tokenIs(tokens[start + 3], 'symbol', ')') ||
        !tokenIs(tokens[start + 4], 'symbol', '===') ||
        !tokenIs(tokens[start + 5], 'string', 'redis')
    ) {
        refuse();
    }
}

function validateCacheConfig(tokens) {
    const root = returnedArray(tokens);

    requireEnvKey(root, 'default', 'CACHE_STORE');
    requireEnvKey(root, 'prefix', 'CACHE_PREFIX');
    requireFalseKey(root, 'serializable_classes');

    const stores = arrayForKey(root, 'stores');
    const database = arrayForKey(stores, 'database');
    const redis = arrayForKey(stores, 'redis');

    requireStringKey(database, 'driver', 'database');
    requireEnvKey(database, 'lock_connection', 'DB_CACHE_LOCK_CONNECTION');
    requireEnvKey(database, 'lock_table', 'DB_CACHE_LOCK_TABLE');
    requireStringKey(redis, 'driver', 'redis');
    requireEnvKey(redis, 'connection', 'REDIS_CACHE_CONNECTION');
    requireEnvKey(redis, 'lock_connection', 'REDIS_CACHE_LOCK_CONNECTION');
}

function validateDatabaseConfig(tokens) {
    const redis = arrayForKey(returnedArray(tokens), 'redis');
    const cache = arrayForKey(redis, 'cache');

    requireEnvKey(cache, 'database', 'REDIS_CACHE_DB');
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

    return { scanned: scanSource(source), size: bytes.length };
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

            sources.set(relativePath, item.scanned);
        }

        const cachePath =
            'app/Http/Middleware/AuthenticateSelectionResultPoll.php';
        const uniquePaths = new Set([
            'app/Jobs/DispatchGenericAssessmentResultCallback.php',
            'app/Jobs/DeliverIntegrationCallbackJob.php',
            'app/Jobs/DeliverOutboxMessage.php',
        ]);

        for (const [relativePath, scanned] of sources) {
            const source = scanned.code;
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
            cacheOperation(sources.get(cachePath).code, cachePath),
        ];
        const uniqueJobLocks = [
            uniqueJob(
                sources.get(
                    'app/Jobs/DispatchGenericAssessmentResultCallback.php',
                ).code,
                'app/Jobs/DispatchGenericAssessmentResultCallback.php',
                'DispatchGenericAssessmentResultCallback',
                300,
            ),
            uniqueJob(
                sources.get('app/Jobs/DeliverIntegrationCallbackJob.php').code,
                'app/Jobs/DeliverIntegrationCallbackJob.php',
                'DeliverIntegrationCallbackJob',
                900,
            ),
            uniqueJob(
                sources.get('app/Jobs/DeliverOutboxMessage.php').code,
                'app/Jobs/DeliverOutboxMessage.php',
                'DeliverOutboxMessage',
                900,
            ),
        ];

        validateProductionRequirement(
            sources.get('app/Providers/AppServiceProvider.php').tokens,
        );
        validateCacheConfig(sources.get('config/cache.php').tokens);
        validateDatabaseConfig(sources.get('config/database.php').tokens);

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
                'trusted_quiescent_working_tree_only',
                'concurrent_filesystem_mutation_or_reparse_race_not_excluded',
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
