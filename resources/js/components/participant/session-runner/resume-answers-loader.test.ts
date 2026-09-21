import assert from 'node:assert/strict';
import { test } from 'node:test';

import { createResumeAnswersLoader } from './resume-answers-loader.ts';
import type { ResumeAnswersOutcome } from './resume-answers.ts';

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
    const outcome: ResumeAnswersOutcome = { type: 'not_started' };
    const { queueRetry } = fakeQueueRetry();
    const loader = createResumeAnswersLoader({
        fetchResumeAnswers: async () => outcome,
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
    const loader = createResumeAnswersLoader({
        fetchResumeAnswers: async () => {
            calls++;

            return { type: 'network_error' };
        },
        queueRetry: tracker.queueRetry,
    });

    loader.start();
    await Promise.resolve();

    assert.deepEqual(loader.getState(), { status: 'reconnecting' });
    assert.equal(calls, 1);
    assert.equal(
        tracker.queuedCount,
        1,
        'a network_error outcome must queue exactly one retry',
    );
});

test('network_error -> connectivity restored -> retry fires -> available yields the ready state with the correct outcome', async () => {
    const tracker = fakeQueueRetry();
    const outcomes: ResumeAnswersOutcome[] = [
        { type: 'network_error' },
        {
            type: 'available',
            sessionId: 'ses_1',
            answersRevision: 7,
            answers: [{ itemNo: 1, value: 'A' }],
        },
    ];
    let call = 0;
    const loader = createResumeAnswersLoader({
        fetchResumeAnswers: async () => outcomes[call++]!,
        queueRetry: tracker.queueRetry,
    });

    loader.start();
    await Promise.resolve();
    assert.deepEqual(loader.getState(), { status: 'reconnecting' });

    tracker.fireQueuedRetry();
    await Promise.resolve();

    assert.deepEqual(loader.getState(), {
        status: 'ready',
        outcome: outcomes[1],
    });
});

for (const outcome of [
    { type: 'closed' as const },
    { type: 'deadline_exceeded' as const },
    { type: 'not_found' as const },
]) {
    test(`a final failure (${outcome.type}) is never retried`, async () => {
        const tracker = fakeQueueRetry();
        const loader = createResumeAnswersLoader({
            fetchResumeAnswers: async () => outcome,
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

test('a thrown/rejected fetch is reported as error, distinct from network_error, and is not auto-retried', async () => {
    const tracker = fakeQueueRetry();
    const loader = createResumeAnswersLoader({
        fetchResumeAnswers: async () => {
            throw new Error('boom');
        },
        queueRetry: tracker.queueRetry,
    });

    loader.start();
    // Two microtask hops: the rejection has to pass through `.then()`'s
    // fulfillment handler (a no-op pass-through for a rejected promise)
    // before `.catch()` actually runs.
    await Promise.resolve();
    await Promise.resolve();

    const state = loader.getState();
    assert.equal(state.status, 'error');
    assert.equal(tracker.queuedCount, 0);
});

test('retry() bypasses the queued wait and re-attempts immediately', async () => {
    const tracker = fakeQueueRetry();
    const outcomes: ResumeAnswersOutcome[] = [
        { type: 'network_error' },
        { type: 'not_started' },
    ];
    let call = 0;
    const loader = createResumeAnswersLoader({
        fetchResumeAnswers: async () => outcomes[call++]!,
        queueRetry: tracker.queueRetry,
    });

    loader.start();
    await Promise.resolve();
    assert.deepEqual(loader.getState(), { status: 'reconnecting' });

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
    const first = Promise.withResolvers<ResumeAnswersOutcome>();
    let call = 0;
    const loader = createResumeAnswersLoader({
        fetchResumeAnswers: () => {
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
    const deferred = Promise.withResolvers<ResumeAnswersOutcome>();
    const loader = createResumeAnswersLoader({
        fetchResumeAnswers: () => deferred.promise,
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
