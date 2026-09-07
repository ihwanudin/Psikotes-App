import assert from 'node:assert/strict';
import { link, mkdir, mkdtemp, rm, symlink, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';

import {
    CacheLockInventoryRefused,
    inspectCacheLockInventory,
} from './cache-lock-inventory.mjs';

const FILES = Object.freeze({
    'app/Http/Middleware/AuthenticateSelectionResultPoll.php': `<?php
final class AuthenticateSelectionResultPoll {
 private function signalRateLimit(): void {
  if (! Cache::add($signalKey.':logged', true, 70)) { return; }
 }
}
`,
    'app/Jobs/DispatchGenericAssessmentResultCallback.php': `<?php
#[UniqueFor(300)]
final class DispatchGenericAssessmentResultCallback implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue {
 public function uniqueId(): string { return $this->scheduleId; }
}
`,
    'app/Jobs/DeliverIntegrationCallbackJob.php': `<?php
#[UniqueFor(900)]
final class DeliverIntegrationCallbackJob implements ShouldBeUnique, ShouldQueue {
 public function uniqueId(): string { return $this->eventId; }
}
`,
    'app/Jobs/DeliverOutboxMessage.php': `<?php
#[UniqueFor(900)]
final class DeliverOutboxMessage implements ShouldBeUnique, ShouldQueue {
 public function uniqueId(): string { return $this->messageId; }
}
`,
    'app/Providers/AppServiceProvider.php': `<?php
$requirements = [
 'CACHE_STORE_REDIS' => config('cache.default') === 'redis',
];
`,
    'config/cache.php': `<?php
return [
 'default' => env('CACHE_STORE', 'database'),
 'stores' => [
  'database' => [
   'driver' => 'database',
   'connection' => env('DB_CACHE_CONNECTION'),
   'table' => env('DB_CACHE_TABLE', 'cache'),
   'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'),
   'lock_table' => env('DB_CACHE_LOCK_TABLE'),
  ],
  'redis' => [
   'driver' => 'redis',
   'connection' => env('REDIS_CACHE_CONNECTION', 'cache'),
   'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
  ],
 ],
 'prefix' => env('CACHE_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-cache-'),
 'serializable_classes' => false,
];
`,
    'config/database.php': `<?php
return [
 'redis' => [
  'cache' => [
   'database' => env('REDIS_CACHE_DB', '1'),
  ],
 ],
];
`,
});

async function makeFixture(mutate = (files) => files) {
    const root = await mkdtemp(join(tmpdir(), 'oncam-cache-inventory-'));
    const files = mutate({ ...FILES });

    for (const [relative, source] of Object.entries(files)) {
        const target = join(root, ...relative.split('/'));
        await mkdir(dirname(target), { recursive: true });
        await writeFile(target, source, 'utf8');
    }

    return root;
}

async function withFixture(run, mutate) {
    const root = await makeFixture(mutate);

    try {
        await run(root);
    } finally {
        await rm(root, { recursive: true, force: true });
    }
}

async function refuses(run) {
    await assert.rejects(run, (error) => {
        assert.equal(error.constructor, CacheLockInventoryRefused);
        assert.equal(error.message, 'cache_lock_inventory');

        return true;
    });
}

await withFixture(async (root) => {
    const report = await inspectCacheLockInventory(root);
    assert.deepEqual(report, {
        version: 1,
        staticEvidenceOnly: true,
        inspectedFiles: Object.keys(FILES).sort(),
        cacheFacadeOperations: [
            {
                operation: 'add',
                ttlSeconds: 70,
                source: 'app/Http/Middleware/AuthenticateSelectionResultPoll.php',
            },
        ],
        uniqueJobLocks: [
            {
                className: 'DispatchGenericAssessmentResultCallback',
                ttlSeconds: 300,
                uniqueIdMethod: true,
                source: 'app/Jobs/DispatchGenericAssessmentResultCallback.php',
            },
            {
                className: 'DeliverIntegrationCallbackJob',
                ttlSeconds: 900,
                uniqueIdMethod: true,
                source: 'app/Jobs/DeliverIntegrationCallbackJob.php',
            },
            {
                className: 'DeliverOutboxMessage',
                ttlSeconds: 900,
                uniqueIdMethod: true,
                source: 'app/Jobs/DeliverOutboxMessage.php',
            },
        ],
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
    assert.equal(Object.isFrozen(report), true);
    assert.equal(Object.isFrozen(report.uniqueJobLocks), true);
    assert.equal(Object.isFrozen(report.declarations), true);
    assert.equal(JSON.stringify(report).includes(root), false);
});

for (const [, mutate] of [
    [
        'missing cache add',
        (source) => source.replace('Cache::add', 'Cache::put'),
    ],
    ['cache ttl drift', (source) => source.replace('true, 70', 'true, 71')],
    [
        'extra cache operation',
        (source) => `${source}\nCache::get('not-a-secret');`,
    ],
]) {
    await withFixture(async (root) => {
        const target = join(
            root,
            'app/Http/Middleware/AuthenticateSelectionResultPoll.php',
        );
        const source =
            FILES['app/Http/Middleware/AuthenticateSelectionResultPoll.php'];
        await writeFile(target, mutate(source), 'utf8');
        await refuses(() => inspectCacheLockInventory(root));
    });
}

for (const [, relative, mutate] of [
    [
        'missing unique job',
        'app/Jobs/DeliverOutboxMessage.php',
        (source) => source.replace('ShouldBeUnique, ', ''),
    ],
    [
        'unique ttl drift',
        'app/Jobs/DeliverIntegrationCallbackJob.php',
        (source) => source.replace('UniqueFor(900)', 'UniqueFor(901)'),
    ],
    [
        'missing unique id',
        'app/Jobs/DispatchGenericAssessmentResultCallback.php',
        (source) => source.replace('uniqueId', 'otherId'),
    ],
    [
        'production redis assertion drift',
        'app/Providers/AppServiceProvider.php',
        (source) => source.replace("=== 'redis'", "=== 'database'"),
    ],
    [
        'unexpected cache operation in another allowlisted file',
        'app/Providers/AppServiceProvider.php',
        (source) => `${source}\nCache::get('ignored-value');`,
    ],
    [
        'unexpected unique construct in another allowlisted file',
        'app/Providers/AppServiceProvider.php',
        (source) =>
            `${source}\n#[UniqueFor(60)] class Surprise implements ShouldBeUnique {}`,
    ],
    [
        'prefix declaration drift',
        'config/cache.php',
        (source) => source.replace("env('CACHE_PREFIX'", "env('OTHER_PREFIX'"),
    ],
    [
        'database declaration drift',
        'config/database.php',
        (source) => source.replace('REDIS_CACHE_DB', 'REDIS_DB'),
    ],
]) {
    await withFixture(async (root) => {
        const target = join(root, ...relative.split('/'));
        await writeFile(target, mutate(FILES[relative]), 'utf8');
        await refuses(() => inspectCacheLockInventory(root));
    });
}

await withFixture(async (root) => {
    await writeFile(join(root, '.env'), 'SECRET_SENTINEL=do-not-read\n');
    const report = await inspectCacheLockInventory(root);
    assert.equal(JSON.stringify(report).includes('SECRET_SENTINEL'), false);
});

await withFixture(async (root) => {
    const outside = await mkdtemp(join(tmpdir(), 'oncam-cache-outside-'));
    const relative = 'config/cache.php';
    const target = join(root, ...relative.split('/'));
    const external = join(outside, 'cache.php');

    try {
        await writeFile(external, FILES[relative], 'utf8');
        await rm(target);
        await symlink(external, target, 'file');
        await refuses(() => inspectCacheLockInventory(root));
    } finally {
        await rm(outside, { recursive: true, force: true });
    }
});

await withFixture(async (root) => {
    const first = join(root, 'config/cache.php');
    const second = join(root, 'config/database.php');
    await rm(second);
    await link(first, second);
    await refuses(() => inspectCacheLockInventory(root));
});

await refuses(() => inspectCacheLockInventory('relative/source'));

console.log('cache-lock-inventory tests passed');
