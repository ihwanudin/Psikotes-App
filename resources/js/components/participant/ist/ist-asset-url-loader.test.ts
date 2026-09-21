import assert from 'node:assert/strict';
import { test } from 'node:test';

import { createIstAssetUrlLoader } from './ist-asset-url-loader.ts';
import type { IstAssetUrlOutcome } from './ist-asset-url.ts';

const FAR_FUTURE = '2099-01-01T00:00:00+00:00';
const FAR_PAST = '2000-01-01T00:00:00+00:00';

test('starts in loading and resolves to ready on a fresh, unexpired URL', async () => {
    const loader = createIstAssetUrlLoader({
        assetId: 'asset_1',
        fetchAssetUrl: async () => ({
            type: 'available',
            url: 'https://x/img.png',
            expiresAt: FAR_FUTURE,
        }),
    });

    assert.deepEqual(loader.getState(), { status: 'loading' });
    loader.start();
    await Promise.resolve();
    await Promise.resolve();

    assert.deepEqual(loader.getState(), {
        status: 'ready',
        url: 'https://x/img.png',
    });
});

test('a definitive error outcome (e.g. asset_not_found) surfaces as-is', async () => {
    const loader = createIstAssetUrlLoader({
        assetId: 'asset_1',
        fetchAssetUrl: async () => ({ type: 'asset_not_found' }),
    });

    loader.start();
    await Promise.resolve();
    await Promise.resolve();

    assert.deepEqual(loader.getState(), {
        status: 'error',
        outcome: { type: 'asset_not_found' },
    });
});

test('a rejected/thrown fetcher surfaces as a network_error outcome', async () => {
    const loader = createIstAssetUrlLoader({
        assetId: 'asset_1',
        fetchAssetUrl: async () => {
            throw new TypeError('Failed to fetch');
        },
    });

    loader.start();
    await Promise.resolve();
    await Promise.resolve();

    assert.deepEqual(loader.getState(), {
        status: 'error',
        outcome: { type: 'network_error' },
    });
});

test('a URL that arrives already expired is retried once automatically, and a fresh one is used', async () => {
    const responses: IstAssetUrlOutcome[] = [
        { type: 'available', url: 'https://x/stale.png', expiresAt: FAR_PAST },
        {
            type: 'available',
            url: 'https://x/fresh.png',
            expiresAt: FAR_FUTURE,
        },
    ];
    let calls = 0;
    const loader = createIstAssetUrlLoader({
        assetId: 'asset_1',
        fetchAssetUrl: async () => responses[calls++]!,
    });

    loader.start();
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();

    assert.equal(
        calls,
        2,
        'the already-expired first response must trigger exactly one automatic retry',
    );
    assert.deepEqual(loader.getState(), {
        status: 'ready',
        url: 'https://x/fresh.png',
    });
});

test('two already-expired responses in a row surface as an error, not an unbounded retry loop', async () => {
    let calls = 0;
    const loader = createIstAssetUrlLoader({
        assetId: 'asset_1',
        fetchAssetUrl: async () => {
            calls++;

            return {
                type: 'available',
                url: 'https://x/stale.png',
                expiresAt: FAR_PAST,
            };
        },
    });

    loader.start();
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();

    assert.equal(calls, 2, 'must stop after exactly 2 attempts, never loop');
    assert.deepEqual(loader.getState(), {
        status: 'error',
        outcome: { type: 'network_error' },
    });
});

test('reload() re-fetches and can recover from a prior error', async () => {
    const responses: IstAssetUrlOutcome[] = [
        { type: 'network_error' },
        { type: 'available', url: 'https://x/img.png', expiresAt: FAR_FUTURE },
    ];
    let calls = 0;
    const loader = createIstAssetUrlLoader({
        assetId: 'asset_1',
        fetchAssetUrl: async () => responses[calls++]!,
    });

    loader.start();
    await Promise.resolve();
    await Promise.resolve();
    assert.deepEqual(loader.getState(), {
        status: 'error',
        outcome: { type: 'network_error' },
    });

    loader.reload();
    assert.deepEqual(
        loader.getState(),
        { status: 'loading' },
        'reload() must show loading immediately (called from an event handler, not an effect)',
    );
    await Promise.resolve();
    await Promise.resolve();

    assert.deepEqual(loader.getState(), {
        status: 'ready',
        url: 'https://x/img.png',
    });
});

test('a stale attempt superseded by reload() never overwrites the newer result', async () => {
    const first = Promise.withResolvers<IstAssetUrlOutcome>();
    let calls = 0;
    const loader = createIstAssetUrlLoader({
        assetId: 'asset_1',
        fetchAssetUrl: () => {
            calls++;

            return calls === 1
                ? first.promise
                : Promise.resolve<IstAssetUrlOutcome>({
                      type: 'available',
                      url: 'https://x/second.png',
                      expiresAt: FAR_FUTURE,
                  });
        },
    });

    loader.start();
    loader.reload();
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();

    assert.deepEqual(loader.getState(), {
        status: 'ready',
        url: 'https://x/second.png',
    });

    first.resolve({
        type: 'available',
        url: 'https://x/stale-first.png',
        expiresAt: FAR_FUTURE,
    });
    await Promise.resolve();
    await Promise.resolve();

    assert.deepEqual(
        loader.getState(),
        { status: 'ready', url: 'https://x/second.png' },
        'the first (stale) attempt resolving late must not overwrite the newer ready state',
    );
});

test('dispose() ignores a still-in-flight resolution', async () => {
    const deferred = Promise.withResolvers<IstAssetUrlOutcome>();
    const loader = createIstAssetUrlLoader({
        assetId: 'asset_1',
        fetchAssetUrl: () => deferred.promise,
    });

    loader.start();
    await Promise.resolve();
    loader.dispose();

    deferred.resolve({
        type: 'available',
        url: 'https://x/img.png',
        expiresAt: FAR_FUTURE,
    });
    await Promise.resolve();
    await Promise.resolve();

    assert.deepEqual(
        loader.getState(),
        { status: 'loading' },
        'a resolution arriving after dispose() must never update state',
    );
});
