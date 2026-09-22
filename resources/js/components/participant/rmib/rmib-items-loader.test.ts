import assert from 'node:assert/strict';
import { test } from 'node:test';

import { createRmibItemsLoader } from './rmib-items-loader.ts';
import type { RmibItemsOutcome, RmibPosition } from './rmib-items.ts';

function syntheticPosition(
    overrides: Partial<RmibPosition> = {},
): RmibPosition {
    return {
        group: 1,
        group_letter: 'A',
        position: 1,
        job: 'Synthetic job',
        ...overrides,
    };
}

function fakeQueueRetry(): {
    queueRetry: (retry: () => void) => () => void;
    fireQueuedRetry: () => void;
    queuedCount: number;
} {
    let queued: (() => void) | null = null;
    let queuedCount = 0;

    return {
        queueRetry(retry) {
            queued = retry;
            queuedCount++;

            return () => {
                if (queued === retry) {
                    queued = null;
                }
            };
        },
        fireQueuedRetry() {
            queued?.();
        },
        get queuedCount() {
            return queuedCount;
        },
    };
}

test('starts in loading and resolves to ready on a final outcome', async () => {
    const outcome: RmibItemsOutcome = { type: 'not_started' };
    const { queueRetry } = fakeQueueRetry();
    const loader = createRmibItemsLoader({
        fetchItems: async () => outcome,
        queueRetry,
    });

    assert.deepEqual(loader.getState(), { status: 'loading' });
    loader.start();
    await Promise.resolve();

    assert.deepEqual(loader.getState(), { status: 'ready', outcome });
});

test('network_error transitions to reconnecting and queues a retry, not a terminal failure', async () => {
    const tracker = fakeQueueRetry();
    let calls = 0;
    const loader = createRmibItemsLoader({
        fetchItems: async () => {
            calls++;

            return { type: 'network_error' };
        },
        queueRetry: tracker.queueRetry,
    });

    loader.start();
    await Promise.resolve();

    assert.deepEqual(loader.getState(), {
        status: 'reconnecting',
        autoRetryExhausted: false,
    });
    assert.equal(calls, 1);
    assert.equal(
        tracker.queuedCount,
        1,
        'a network_error outcome must queue exactly one retry',
    );
});

test('a rejected/thrown fetcher (a real dropped-signal failure) is treated identically to the network_error outcome', async () => {
    const tracker = fakeQueueRetry();
    const availableOutcome: RmibItemsOutcome = {
        type: 'available',
        content: {
            sessionId: 'ses_1',
            instrument: 'rmib',
            version: 'v1',
            positions: [syntheticPosition()],
            instructions: { text: 't', write_preferred_jobs_prompt: 'p' },
        },
    };
    const outcomes: (() => Promise<RmibItemsOutcome>)[] = [
        () => Promise.reject(new TypeError('Failed to fetch')),
        () => Promise.resolve(availableOutcome),
    ];
    let call = 0;
    const loader = createRmibItemsLoader({
        fetchItems: () => outcomes[call++]!(),
        queueRetry: tracker.queueRetry,
    });

    loader.start();
    await Promise.resolve();
    await Promise.resolve();

    assert.deepEqual(
        loader.getState(),
        { status: 'reconnecting', autoRetryExhausted: false },
        'a rejected fetch must be treated as a connectivity failure, not a terminal error',
    );

    tracker.fireQueuedRetry();
    await Promise.resolve();

    assert.deepEqual(loader.getState(), {
        status: 'ready',
        outcome: availableOutcome,
    });
});

for (const outcome of [
    { type: 'not_started' as const },
    { type: 'closed' as const },
    { type: 'deadline_exceeded' as const },
    { type: 'not_found' as const },
    { type: 'content_unavailable' as const },
]) {
    test(`a final outcome (${outcome.type}) is never retried`, async () => {
        const tracker = fakeQueueRetry();
        const loader = createRmibItemsLoader({
            fetchItems: async () => outcome,
            queueRetry: tracker.queueRetry,
        });

        loader.start();
        await Promise.resolve();

        assert.deepEqual(loader.getState(), { status: 'ready', outcome });
        assert.equal(
            tracker.queuedCount,
            0,
            'a final outcome must never queue a retry',
        );
    });
}

test('automatic retries are capped, and the counter is shared across network_error outcomes and rejections', async () => {
    const tracker = fakeQueueRetry();
    let calls = 0;
    const loader = createRmibItemsLoader({
        fetchItems: () => {
            calls++;

            return calls % 2 === 0
                ? Promise.reject(new TypeError('Failed to fetch'))
                : Promise.resolve({ type: 'network_error' as const });
        },
        queueRetry: tracker.queueRetry,
    });

    loader.start();
    await Promise.resolve();
    await Promise.resolve();

    for (let i = 0; i < 10; i++) {
        const state = loader.getState();

        if (state.status === 'reconnecting' && state.autoRetryExhausted) {
            break;
        }

        tracker.fireQueuedRetry();
        await Promise.resolve();
        await Promise.resolve();
    }

    assert.deepEqual(loader.getState(), {
        status: 'reconnecting',
        autoRetryExhausted: true,
    });
    assert.equal(
        calls,
        5,
        'exactly 5 consecutive failures (the cap) should have been attempted',
    );
    assert.equal(
        tracker.queuedCount,
        4,
        'the 5th failure must not queue a 6th automatic retry',
    );

    tracker.fireQueuedRetry();
    await Promise.resolve();
    assert.equal(calls, 5);
});

test('after the automatic-retry cap is reached, a manual retry() still works and resets the budget', async () => {
    const tracker = fakeQueueRetry();
    let calls = 0;
    const loader = createRmibItemsLoader({
        fetchItems: () => {
            calls++;

            if (calls <= 5) {
                return Promise.resolve({ type: 'network_error' as const });
            }

            return Promise.resolve({ type: 'not_started' as const });
        },
        queueRetry: tracker.queueRetry,
    });

    loader.start();

    for (let i = 0; i < 5; i++) {
        await Promise.resolve();
        await Promise.resolve();

        if (i < 4) {
            tracker.fireQueuedRetry();
        }
    }

    assert.deepEqual(loader.getState(), {
        status: 'reconnecting',
        autoRetryExhausted: true,
    });
    assert.equal(calls, 5);

    loader.retry();
    assert.deepEqual(loader.getState(), { status: 'loading' });
    await Promise.resolve();

    assert.deepEqual(loader.getState(), {
        status: 'ready',
        outcome: { type: 'not_started' },
    });
    assert.equal(
        calls,
        6,
        'retry() must have triggered exactly one more attempt',
    );
});

test('retry() bypasses the queued wait and re-attempts immediately', async () => {
    const tracker = fakeQueueRetry();
    const outcomes: RmibItemsOutcome[] = [
        { type: 'network_error' },
        { type: 'not_started' },
    ];
    let call = 0;
    const loader = createRmibItemsLoader({
        fetchItems: async () => outcomes[call++]!,
        queueRetry: tracker.queueRetry,
    });

    loader.start();
    await Promise.resolve();
    assert.deepEqual(loader.getState(), {
        status: 'reconnecting',
        autoRetryExhausted: false,
    });

    loader.retry();
    assert.deepEqual(
        loader.getState(),
        { status: 'loading' },
        'retry() must show loading immediately, not wait for the fetch',
    );
    await Promise.resolve();

    assert.deepEqual(loader.getState(), {
        status: 'ready',
        outcome: outcomes[1],
    });
});

test('a stale attempt superseded by retry() never overwrites the newer result', async () => {
    const tracker = fakeQueueRetry();
    const first = Promise.withResolvers<RmibItemsOutcome>();
    let call = 0;
    const loader = createRmibItemsLoader({
        fetchItems: () => {
            call++;

            return call === 1
                ? first.promise
                : Promise.resolve({ type: 'not_started' as const });
        },
        queueRetry: tracker.queueRetry,
    });

    loader.start();
    loader.retry();
    await Promise.resolve();
    await Promise.resolve();

    assert.deepEqual(loader.getState(), {
        status: 'ready',
        outcome: { type: 'not_started' },
    });

    first.resolve({ type: 'not_found' });
    await Promise.resolve();
    await Promise.resolve();

    assert.deepEqual(
        loader.getState(),
        { status: 'ready', outcome: { type: 'not_started' } },
        'the first (stale) attempt resolving late must not overwrite the newer ready state',
    );
});

test('dispose() cancels a queued retry and ignores a still-in-flight resolution', async () => {
    const tracker = fakeQueueRetry();
    const deferred = Promise.withResolvers<RmibItemsOutcome>();
    const loader = createRmibItemsLoader({
        fetchItems: () => deferred.promise,
        queueRetry: tracker.queueRetry,
    });

    loader.start();
    await Promise.resolve();
    loader.dispose();

    deferred.resolve({ type: 'not_started' });
    await Promise.resolve();
    await Promise.resolve();

    assert.deepEqual(
        loader.getState(),
        { status: 'loading' },
        'a resolution arriving after dispose() must never update state',
    );
});
