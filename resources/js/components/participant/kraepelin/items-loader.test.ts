import assert from 'node:assert/strict';
import { test } from 'node:test';

import { createItemsLoader } from './items-loader.ts';
import type { AssessmentSessionItemsOutcome } from './items.ts';

// The retry-orchestration logic itself is identical to, and already
// exhaustively tested by, session-runner/resume-answers-loader.test.ts
// (same module, split only because the outcome sets differ — see
// items-loader.ts's module doc). This suite focuses on what's new or
// specific to this outcome set (content_unavailable) rather than
// re-proving every scenario a second time.

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

const AVAILABLE: AssessmentSessionItemsOutcome = {
    type: 'available',
    content: {
        sessionId: 'ses_1',
        instrument: 'kraepelin',
        version: 'F0-2026.09',
        subtests: [{ code: 'col_01', items: [{ position: 1, value: 5 }] }],
    },
};

test('starts in loading and resolves to ready on available', async () => {
    const { queueRetry } = fakeQueueRetry();
    const loader = createItemsLoader({
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
        async (): Promise<AssessmentSessionItemsOutcome> => ({
            type: 'network_error',
        }),
        (): Promise<AssessmentSessionItemsOutcome> =>
            Promise.reject(new TypeError('Failed to fetch')),
    ]) {
        const tracker = fakeQueueRetry();
        const loader = createItemsLoader({
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
        const loader = createItemsLoader({
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
    const loader = createItemsLoader({
        fetchItems: async (): Promise<AssessmentSessionItemsOutcome> => {
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
    const deferred = Promise.withResolvers<AssessmentSessionItemsOutcome>();
    const loader = createItemsLoader({
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
