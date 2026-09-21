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

test('reportImageLoadFailure() below the cap re-fetches instead of giving up', async () => {
    let calls = 0;
    const loader = createIstAssetUrlLoader({
        assetId: 'asset_1',
        fetchAssetUrl: async () => {
            calls++;

            return {
                type: 'available',
                url: 'https://x/img.png',
                expiresAt: FAR_FUTURE,
            };
        },
    });

    loader.start();
    await Promise.resolve();
    await Promise.resolve();
    assert.equal(calls, 1);

    loader.reportImageLoadFailure();
    assert.deepEqual(
        loader.getState(),
        { status: 'loading' },
        'a single onError, below the cap, must re-fetch rather than error immediately',
    );
    await Promise.resolve();
    await Promise.resolve();

    assert.equal(
        calls,
        2,
        'below the cap, reportImageLoadFailure() must trigger a fresh fetch',
    );
    assert.deepEqual(loader.getState(), {
        status: 'ready',
        url: 'https://x/img.png',
    });
});

test('reportImageLoadFailure() reaching the cap surfaces an error and stops fetching — proves onError can never loop forever', async () => {
    let calls = 0;
    const loader = createIstAssetUrlLoader({
        assetId: 'asset_1',
        fetchAssetUrl: async () => {
            calls++;

            // Simulates a URL that is always "available" from the
            // server's point of view but the browser can never actually
            // load (a broken/missing storage object, or a dropped mobile
            // download) — every fetch succeeds, every <img> load fails.
            return {
                type: 'available',
                url: 'https://x/img.png',
                expiresAt: FAR_FUTURE,
            };
        },
    });

    loader.start();
    await Promise.resolve();
    await Promise.resolve();

    // Two consecutive onError events, exactly like the <img> firing
    // onError right after each re-fetch renders a new (still broken) src.
    loader.reportImageLoadFailure();
    await Promise.resolve();
    await Promise.resolve();
    assert.deepEqual(
        loader.getState(),
        { status: 'ready', url: 'https://x/img.png' },
        'the first onError, below the cap, must still recover to ready',
    );

    loader.reportImageLoadFailure();

    assert.deepEqual(
        loader.getState(),
        { status: 'error', outcome: { type: 'network_error' } },
        'the second consecutive onError must reach the cap and surface the same error/"Coba lagi" state reload() uses',
    );
    assert.equal(
        calls,
        2,
        'reaching the cap must not trigger a further fetch — 1 (start) + 1 (first onError), never a 3rd',
    );

    // Confirms it really is capped, not just slow: further onError calls
    // (as would keep happening if a stale <img> somehow re-fired) change
    // nothing further.
    loader.reportImageLoadFailure();
    await Promise.resolve();
    await Promise.resolve();
    assert.equal(
        calls,
        2,
        'once capped, additional onError calls must never fetch again',
    );
});

test('reload() resets the reportImageLoadFailure() count, earning a fresh budget', async () => {
    let calls = 0;
    const loader = createIstAssetUrlLoader({
        assetId: 'asset_1',
        fetchAssetUrl: async () => {
            calls++;

            return {
                type: 'available',
                url: 'https://x/img.png',
                expiresAt: FAR_FUTURE,
            };
        },
    });

    loader.start();
    await Promise.resolve();
    await Promise.resolve();

    loader.reportImageLoadFailure();
    await Promise.resolve();
    await Promise.resolve();
    assert.equal(calls, 2, 'one onError below the cap');

    // A manual "Coba lagi" click resets the count — the very next
    // onError afterwards must NOT immediately hit the cap, proving the
    // reset actually took effect rather than merely re-fetching once.
    loader.reload();
    await Promise.resolve();
    await Promise.resolve();
    assert.equal(calls, 3, 'reload() itself re-fetches once');

    loader.reportImageLoadFailure();
    await Promise.resolve();
    await Promise.resolve();
    assert.deepEqual(
        loader.getState(),
        { status: 'ready', url: 'https://x/img.png' },
        'the first onError after reload() must be treated as a fresh 1st failure, not a 3rd',
    );
    assert.equal(calls, 4);

    loader.reportImageLoadFailure();
    assert.deepEqual(
        loader.getState(),
        { status: 'error', outcome: { type: 'network_error' } },
        'the second onError after reload() must now hit the (reset) cap',
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
