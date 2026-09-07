import assert from 'node:assert/strict';
import { Buffer } from 'node:buffer';
import { mkdtemp, mkdir, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { gzipSync } from 'node:zlib';

import {
    BundleBudgetRefused,
    BUDGETS,
    measureBundle,
} from './vite-bundle-budget.mjs';

const canonicalManifest = (value) => JSON.stringify(value, null, 2);

async function fixture(files = {}) {
    const root = await mkdtemp(join(tmpdir(), 'oncam-bundle-budget-'));
    const build = join(root, 'build');
    await mkdir(join(build, 'assets'), { recursive: true });

    for (const [name, bytes] of Object.entries(files)) {
        await writeFile(join(build, name), bytes);
    }

    return { root, build };
}

function manifest() {
    return {
        '_shared.js': {
            file: 'assets/shared.js',
            name: 'shared',
        },
        'resources/js/app.tsx': {
            file: 'assets/app.js',
            name: 'app',
            src: 'resources/js/app.tsx',
            isEntry: true,
            imports: ['_shared.js'],
            dynamicImports: ['resources/js/pages/one.tsx'],
            css: ['assets/app.css'],
            assets: ['assets/font.woff2', 'assets/logo.png'],
        },
        'resources/js/pages/one.tsx': {
            file: 'assets/page.js',
            name: 'one',
            src: 'resources/js/pages/one.tsx',
            isDynamicEntry: true,
        },
        'resources/css/app.css': {
            file: 'assets/app.css',
            src: 'resources/css/app.css',
            isEntry: true,
        },
    };
}

async function withFixture(run) {
    const state = await fixture({
        'assets/app.js': Buffer.from('A'.repeat(2048)),
        'assets/shared.js': Buffer.from('shared'.repeat(100)),
        'assets/page.js': Buffer.from('dynamic'.repeat(50)),
        'assets/app.css': Buffer.from('body{}'.repeat(20)),
        'assets/font.woff2': Buffer.alloc(31, 7),
        'assets/logo.png': Buffer.alloc(41, 8),
    });

    try {
        await writeFile(
            join(state.build, 'manifest.json'),
            canonicalManifest(manifest()),
        );
        await run(state);
    } finally {
        await rm(state.root, { recursive: true, force: true });
    }
}

await withFixture(async ({ build }) => {
    const report = await measureBundle(build);
    assert.deepEqual(Object.keys(report), [
        'version',
        'initialJsGzipBytes',
        'entryCssGzipBytes',
        'largestChunkRawBytes',
        'fontImageRawBytes',
        'fontImageCount',
        'dynamicImportCount',
        'budgets',
        'policy',
        'passed',
        'warnings',
    ]);
    assert.equal(report.version, 1);
    assert.equal(
        report.initialJsGzipBytes,
        gzipSync(Buffer.from('A'.repeat(2048)), { level: 9 }).length +
            gzipSync(Buffer.from('shared'.repeat(100)), { level: 9 }).length,
    );
    assert.equal(
        report.entryCssGzipBytes,
        gzipSync(Buffer.from('body{}'.repeat(20)), { level: 9 }).length,
    );
    assert.equal(report.largestChunkRawBytes, 2048);
    assert.equal(report.fontImageRawBytes, 72);
    assert.equal(report.fontImageCount, 2);
    assert.equal(report.dynamicImportCount, 1);
    assert.deepEqual(report.budgets, BUDGETS);
    assert.deepEqual(report.policy, {
        initialJsGzip: 'fail',
        entryCssGzip: 'fail',
        largestChunkRaw: 'warn',
    });
    assert.equal(report.passed, true);
    assert.deepEqual(report.warnings, []);
    assert.equal(
        JSON.stringify(report),
        JSON.stringify(await measureBundle(build)),
    );
});

await withFixture(async ({ build }) => {
    const value = manifest();
    value['resources/js/app.tsx'].imports.push('resources/js/pages/one.tsx');
    value['resources/js/pages/one.tsx'].imports = ['_shared.js'];
    value['resources/js/pages/one.tsx'].dynamicImports = ['_shared.js'];
    await writeFile(join(build, 'manifest.json'), canonicalManifest(value));
    const report = await measureBundle(build);
    assert.equal(report.dynamicImportCount, 2);
    assert.equal(
        report.initialJsGzipBytes,
        gzipSync(Buffer.from('A'.repeat(2048)), { level: 9 }).length +
            gzipSync(Buffer.from('shared'.repeat(100)), { level: 9 }).length +
            gzipSync(Buffer.from('dynamic'.repeat(50)), { level: 9 }).length,
    );
});

await withFixture(async ({ build }) => {
    const value = manifest();
    await writeFile(
        join(build, 'assets/app.js'),
        Buffer.alloc(BUDGETS.initialJsGzipBytes),
    );
    await writeFile(
        join(build, 'assets/app.css'),
        Buffer.alloc(BUDGETS.entryCssGzipBytes),
    );
    await writeFile(
        join(build, 'assets/page.js'),
        Buffer.alloc(BUDGETS.largestChunkRawBytes),
    );
    await writeFile(join(build, 'manifest.json'), canonicalManifest(value));
    const report = await measureBundle(build);
    assert.equal(
        report.passed,
        true,
        'compressible inputs stay below gzip budgets',
    );
    assert.deepEqual(report.warnings, [
        'largest_chunk_raw_at_or_above_500_kib',
    ]);
});

await withFixture(async ({ build }) => {
    const noisy = (length, seed) => {
        const bytes = Buffer.alloc(length);
        let state = seed >>> 0;

        for (let index = 0; index < length; index += 1) {
            state ^= state << 13;
            state ^= state >>> 17;
            state ^= state << 5;
            bytes[index] = state & 255;
        }

        return bytes;
    };
    await writeFile(
        join(build, 'assets/app.js'),
        noisy(BUDGETS.initialJsGzipBytes + 8192, 0x12345678),
    );
    await writeFile(
        join(build, 'assets/app.css'),
        noisy(BUDGETS.entryCssGzipBytes + 4096, 0x87654321),
    );
    const report = await measureBundle(build);
    assert.equal(report.initialJsGzipBytes >= BUDGETS.initialJsGzipBytes, true);
    assert.equal(report.entryCssGzipBytes >= BUDGETS.entryCssGzipBytes, true);
    assert.equal(report.passed, false);
});

for (const mutate of [
    () => null,
    () => [],
    () => ({ entry: null }),
    () => ({ entry: { file: 'assets/app.js', isEntry: 'true' } }),
    () => ({ entry: { file: '../outside.js', isEntry: true } }),
    () => ({ entry: { file: '/absolute.js', isEntry: true } }),
    () => ({ entry: { file: 'assets\\app.js', isEntry: true } }),
    () => ({
        entry: { file: 'assets/app.js', isEntry: true, imports: ['missing'] },
    }),
    () => ({
        a: { file: 'assets/app.js', isEntry: true },
        b: { file: 'assets/shared.js', isEntry: true },
        c: { file: 'assets/app.css', isEntry: true },
    }),
]) {
    await withFixture(async ({ build }) => {
        await writeFile(
            join(build, 'manifest.json'),
            canonicalManifest(mutate()),
        );
        await assert.rejects(
            () => measureBundle(build),
            (error) =>
                error instanceof BundleBudgetRefused &&
                error.message === 'bundle_budget',
        );
    });
}

await withFixture(async ({ build, root }) => {
    await writeFile(join(root, 'outside.js'), 'outside');
    const value = manifest();
    value['resources/js/app.tsx'].file = '../outside.js';
    await writeFile(join(build, 'manifest.json'), canonicalManifest(value));
    await assert.rejects(() => measureBundle(build), BundleBudgetRefused);
});

await withFixture(async ({ build }) => {
    await rm(join(build, 'assets/app.js'));
    await assert.rejects(() => measureBundle(build), BundleBudgetRefused);
});

await withFixture(async ({ build }) => {
    await rm(join(build, 'manifest.json'));
    await assert.rejects(
        () => measureBundle(build),
        (error) =>
            error instanceof BundleBudgetRefused &&
            error.message === 'bundle_budget',
    );
});

for (const malformed of [
    '{',
    '{"entry":{"file":"assets/app.js","isEntry":true},"entry":{"file":"assets/app.js","isEntry":true}}',
]) {
    await withFixture(async ({ build }) => {
        await writeFile(join(build, 'manifest.json'), malformed);
        await assert.rejects(() => measureBundle(build), BundleBudgetRefused);
    });
}

console.log('vite bundle budget tests: PASS');
