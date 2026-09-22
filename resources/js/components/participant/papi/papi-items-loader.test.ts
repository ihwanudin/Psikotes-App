import assert from 'node:assert/strict';
import { test } from 'node:test';

import { createPapiItemsLoader } from './papi-items-loader.ts';
import type { PapiItemsOutcome } from './papi-items.ts';

// The retry-orchestration logic itself is identical to, and already
// exhaustively tested by, session-runner/resume-answers-loader.test.ts
// and kraepelin/items-loader.test.ts (same module, split only because
// the outcome sets differ — see papi-items-loader.ts's module doc). This
// suite focuses on what's specific to this outcome set rather than
// re-proving every scenario a third time.

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

const AVAILABLE: PapiItemsOutcome = {
    type: 'available',
    content: {
        sessionId: 'ses_1',
        instrument: 'papi',
        version: 'F0-ITEMS-PAPI-2026.09',
        items: [{ item: 1, statement_a: 'A1', statement_b: 'B1' }],
        instructions: {
            intro: 'Intro',
            example: { statement_a: 'Ex A', statement_b: 'Ex B' },
            answer_sheet_demo: {
                label: 'Contoh',
                statement_a: 'Demo A',
                statement_b: 'Demo B',
            },
            closing: 'Closing',
        },
    },
};

test('starts in loading and resolves to ready on available', async () => {
    const { queueRetry } = fakeQueueRetry();
    const loader = createPapiItemsLoader({
        fetchItems: async () => AVAILABLE,
        queueRetry,
    });

    assert.deepEqual(loader.getState(), { status: 'loading' });
    loader.start();
    await Promise.resolve();

    assert.deepEqual(loader.getState(), {
        status: 'ready',
        outcome: AVAILABLE,
    });
});

test('network_error and a rejected fetch both retry, not a terminal failure', async () => {
    for (const fetchItems of [
        async (): Promise<PapiItemsOutcome> => ({ type: 'network_error' }),
        (): Promise<PapiItemsOutcome> =>
            Promise.reject(new TypeError('Failed to fetch')),
    ]) {
        const tracker = fakeQueueRetry();
        const loader = createPapiItemsLoader({
            fetchItems,
            queueRetry: tracker.queueRetry,
        });

        loader.start();
        await Promise.resolve();
        await Promise.resolve();

        assert.deepEqual(loader.getState(), {
            status: 'reconnecting',
            autoRetryExhausted: false,
        });
        assert.equal(tracker.queuedCount, 1);
    }
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
        const loader = createPapiItemsLoader({
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

test('automatic retries are capped at 5, then manual retry() recovers', async () => {
    const tracker = fakeQueueRetry();
    let calls = 0;
    const loader = createPapiItemsLoader({
        fetchItems: async (): Promise<PapiItemsOutcome> => {
            calls++;

            return calls <= 5 ? { type: 'network_error' } : AVAILABLE;
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
    assert.equal(
        tracker.queuedCount,
        4,
        'the 5th failure must not queue a 6th automatic retry',
    );

    loader.retry();
    assert.deepEqual(loader.getState(), { status: 'loading' });
    await Promise.resolve();

    assert.deepEqual(loader.getState(), {
        status: 'ready',
        outcome: AVAILABLE,
    });
});

test('dispose() ignores a still-in-flight resolution', async () => {
    const tracker = fakeQueueRetry();
    const deferred = Promise.withResolvers<PapiItemsOutcome>();
    const loader = createPapiItemsLoader({
        fetchItems: () => deferred.promise,
        queueRetry: tracker.queueRetry,
    });

    loader.start();
    await Promise.resolve();
    loader.dispose();

    deferred.resolve(AVAILABLE);
    await Promise.resolve();
    await Promise.resolve();

    assert.deepEqual(
        loader.getState(),
        { status: 'loading' },
        'a resolution arriving after dispose() must never update state',
    );
});
