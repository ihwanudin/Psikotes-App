import assert from 'node:assert/strict';
import { test } from 'node:test';

import { createPhotoCaptureScheduler } from './photo-capture-scheduler.ts';
import type { CaptureKind, CaptureRecord } from './photo-capture-scheduler.ts';

type FakeTimer = { callback: () => void; ms: number; handle: number };

function fakeTimers() {
    const pending = new Map<number, FakeTimer>();
    let nextHandle = 1;
    const cleared: number[] = [];

    return {
        setTimeoutFn: (callback: () => void, ms: number): unknown => {
            const handle = nextHandle++;
            pending.set(handle, { callback, ms, handle });

            return handle;
        },
        clearTimeoutFn: (handle: unknown): void => {
            cleared.push(handle as number);
            pending.delete(handle as number);
        },
        cleared,
        pendingCount: () => pending.size,
        /** Fires the single pending timer and removes it, returning the
         * ms it was scheduled with. Throws if there isn't exactly one —
         * tests should always know precisely which timer they expect. */
        fireOnly: async (): Promise<number> => {
            assert.equal(
                pending.size,
                1,
                `expected exactly one pending timer, found ${pending.size}`,
            );
            const [[handle, timer]] = pending;
            pending.delete(handle);
            timer.callback();
            // Let the scheduler's internal promise chain (runCapture then
            // scheduleNext) settle before the test inspects state. A real
            // macrotask tick flushes every pending microtask ahead of it,
            // regardless of how many `await`s the chain has — more
            // robust than guessing a fixed number of `Promise.resolve()`
            // hops.
            await new Promise((resolve) => setTimeout(resolve, 0));

            return timer.ms;
        },
        /** Fires the single pending timer's callback synchronously,
         * without waiting for the resulting promise chain to settle —
         * for tests that need to inspect state while a capture is still
         * in flight. */
        fireOnlyWithoutWaiting: (): void => {
            assert.equal(
                pending.size,
                1,
                `expected exactly one pending timer, found ${pending.size}`,
            );
            const [[handle, timer]] = pending;
            pending.delete(handle);
            timer.callback();
        },
    };
}

function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((res) => {
        resolve = res;
    });

    return { promise, resolve };
}

async function flushMicrotasks(): Promise<void> {
    await new Promise((resolve) => setTimeout(resolve, 0));
}

function recordingCaptureFrame(
    result: (kind: CaptureKind) => Promise<Blob | null>,
) {
    const calls: CaptureKind[] = [];

    return {
        captureFrame: async (kind: CaptureKind) => {
            calls.push(kind);

            return result(kind);
        },
        calls,
    };
}

function recordingOnRecord() {
    const records: { record: CaptureRecord; blob: Blob | null }[] = [];

    return {
        onRecord: (record: CaptureRecord, blob: Blob | null) => {
            records.push({ record, blob });
        },
        records,
    };
}

test('constructing the scheduler never captures anything on its own', () => {
    const { captureFrame, calls } = recordingCaptureFrame(
        async () => new Blob(),
    );
    const { onRecord, records } = recordingOnRecord();
    const timers = fakeTimers();

    createPhotoCaptureScheduler({
        minIntervalSeconds: 12,
        maxIntervalSeconds: 20,
        captureFrame,
        onRecord,
        setTimeoutFn: timers.setTimeoutFn,
        clearTimeoutFn: timers.clearTimeoutFn,
    });

    assert.equal(
        calls.length,
        0,
        'captureFrame must not be called on construction',
    );
    assert.equal(records.length, 0);
    assert.equal(timers.pendingCount(), 0, 'no timer scheduled before start()');
});

test('invalid interval bounds are rejected at construction', () => {
    const { captureFrame } = recordingCaptureFrame(async () => new Blob());
    const { onRecord } = recordingOnRecord();

    assert.throws(() =>
        createPhotoCaptureScheduler({
            minIntervalSeconds: 0,
            maxIntervalSeconds: 20,
            captureFrame,
            onRecord,
        }),
    );
    assert.throws(() =>
        createPhotoCaptureScheduler({
            minIntervalSeconds: 20,
            maxIntervalSeconds: 12,
            captureFrame,
            onRecord,
        }),
    );
});

test('start() schedules the first periodic capture within [min, max] seconds', () => {
    const { captureFrame } = recordingCaptureFrame(async () => new Blob());
    const { onRecord } = recordingOnRecord();
    const timers = fakeTimers();

    for (const random of [0, 0.5, 0.999]) {
        const scheduler = createPhotoCaptureScheduler({
            minIntervalSeconds: 12,
            maxIntervalSeconds: 20,
            captureFrame,
            onRecord,
            random: () => random,
            setTimeoutFn: timers.setTimeoutFn,
            clearTimeoutFn: timers.clearTimeoutFn,
        });

        scheduler.start();
        assert.equal(timers.pendingCount(), 1);
        scheduler.stop();
    }
});

test('a periodic tick with a real frame reports "captured" and reschedules', async () => {
    const { captureFrame, calls } = recordingCaptureFrame(
        async () => new Blob(['x']),
    );
    const { onRecord, records } = recordingOnRecord();
    const timers = fakeTimers();

    const scheduler = createPhotoCaptureScheduler({
        minIntervalSeconds: 12,
        maxIntervalSeconds: 20,
        captureFrame,
        onRecord,
        now: () => 'T0',
        random: () => 0,
        setTimeoutFn: timers.setTimeoutFn,
        clearTimeoutFn: timers.clearTimeoutFn,
    });

    scheduler.start();
    const firstDelayMs = await timers.fireOnly();

    assert.equal(
        firstDelayMs,
        12_000,
        'random()=0 must map to the minimum bound',
    );
    assert.deepEqual(calls, ['periodic']);
    assert.equal(records.length, 1);
    assert.equal(records[0].record.kind, 'periodic');
    assert.equal(records[0].record.outcome, 'captured');
    assert.equal(records[0].record.triggeredAt, 'T0');
    assert.ok(records[0].blob instanceof Blob);
    assert.equal(
        timers.pendingCount(),
        1,
        'the loop must reschedule itself after a completed capture',
    );
});

test('a periodic tick with no frame reports "no_frame" without stopping the loop', async () => {
    const { captureFrame } = recordingCaptureFrame(async () => null);
    const { onRecord, records } = recordingOnRecord();
    const timers = fakeTimers();

    const scheduler = createPhotoCaptureScheduler({
        minIntervalSeconds: 12,
        maxIntervalSeconds: 20,
        captureFrame,
        onRecord,
        random: () => 0,
        setTimeoutFn: timers.setTimeoutFn,
        clearTimeoutFn: timers.clearTimeoutFn,
    });

    scheduler.start();
    await timers.fireOnly();

    assert.equal(records.length, 1);
    assert.equal(records[0].record.outcome, 'no_frame');
    assert.equal(records[0].blob, null);
    assert.equal(
        timers.pendingCount(),
        1,
        'a missed frame must not pause the schedule',
    );

    // And the loop keeps going through a second miss.
    await timers.fireOnly();
    assert.equal(records.length, 2);
    assert.equal(records[1].record.outcome, 'no_frame');
});

test('an unexpected captureFrame rejection is recorded as "no_frame", not thrown', async () => {
    const { captureFrame } = recordingCaptureFrame(async () => {
        throw new Error('camera exploded');
    });
    const { onRecord, records } = recordingOnRecord();
    const timers = fakeTimers();

    const scheduler = createPhotoCaptureScheduler({
        minIntervalSeconds: 12,
        maxIntervalSeconds: 20,
        captureFrame,
        onRecord,
        random: () => 0,
        setTimeoutFn: timers.setTimeoutFn,
        clearTimeoutFn: timers.clearTimeoutFn,
    });

    scheduler.start();
    await timers.fireOnly();

    assert.equal(records.length, 1);
    assert.equal(records[0].record.outcome, 'no_frame');
    assert.equal(
        timers.pendingCount(),
        1,
        'one bad tick must not kill the periodic loop',
    );
});

test('stop() cancels the pending timer and no further captures happen', () => {
    const { captureFrame, calls } = recordingCaptureFrame(
        async () => new Blob(),
    );
    const { onRecord } = recordingOnRecord();
    const timers = fakeTimers();

    const scheduler = createPhotoCaptureScheduler({
        minIntervalSeconds: 12,
        maxIntervalSeconds: 20,
        captureFrame,
        onRecord,
        setTimeoutFn: timers.setTimeoutFn,
        clearTimeoutFn: timers.clearTimeoutFn,
    });

    scheduler.start();
    assert.equal(scheduler.isRunning(), true);
    scheduler.stop();

    assert.equal(scheduler.isRunning(), false);
    assert.equal(timers.pendingCount(), 0);
    assert.equal(timers.cleared.length, 1);
    assert.equal(calls.length, 0);
});

test('start() is idempotent — calling it twice schedules only one timer', () => {
    const { captureFrame } = recordingCaptureFrame(async () => new Blob());
    const { onRecord } = recordingOnRecord();
    const timers = fakeTimers();

    const scheduler = createPhotoCaptureScheduler({
        minIntervalSeconds: 12,
        maxIntervalSeconds: 20,
        captureFrame,
        onRecord,
        setTimeoutFn: timers.setTimeoutFn,
        clearTimeoutFn: timers.clearTimeoutFn,
    });

    scheduler.start();
    scheduler.start();

    assert.equal(timers.pendingCount(), 1);
});

test('captureNow(session_start) captures immediately, independent of the periodic schedule', async () => {
    const { captureFrame, calls } = recordingCaptureFrame(
        async () => new Blob(),
    );
    const { onRecord, records } = recordingOnRecord();
    const timers = fakeTimers();

    const scheduler = createPhotoCaptureScheduler({
        minIntervalSeconds: 12,
        maxIntervalSeconds: 20,
        captureFrame,
        onRecord,
        setTimeoutFn: timers.setTimeoutFn,
        clearTimeoutFn: timers.clearTimeoutFn,
    });

    // Never started — captureNow must still work on its own.
    await scheduler.captureNow('session_start');

    assert.deepEqual(calls, ['session_start']);
    assert.equal(records.length, 1);
    assert.equal(records[0].record.kind, 'session_start');
    assert.equal(
        timers.pendingCount(),
        0,
        'captureNow must not start the periodic loop',
    );
});

test('captureNow(session_submit) works after stop()', async () => {
    const { captureFrame, calls } = recordingCaptureFrame(
        async () => new Blob(),
    );
    const { onRecord, records } = recordingOnRecord();
    const timers = fakeTimers();

    const scheduler = createPhotoCaptureScheduler({
        minIntervalSeconds: 12,
        maxIntervalSeconds: 20,
        captureFrame,
        onRecord,
        setTimeoutFn: timers.setTimeoutFn,
        clearTimeoutFn: timers.clearTimeoutFn,
    });

    scheduler.start();
    scheduler.stop();
    await scheduler.captureNow('session_submit');

    assert.deepEqual(calls, ['session_submit']);
    assert.equal(records.length, 1);
    assert.equal(records[0].record.kind, 'session_submit');
});

test('the periodic interval respects injected min/max bounds, not a hardcoded 12-20', async () => {
    const { captureFrame } = recordingCaptureFrame(async () => new Blob());
    const { onRecord } = recordingOnRecord();
    const timers = fakeTimers();

    const scheduler = createPhotoCaptureScheduler({
        minIntervalSeconds: 5,
        maxIntervalSeconds: 5,
        captureFrame,
        onRecord,
        random: () => 0.5, // must not matter when min === max
        setTimeoutFn: timers.setTimeoutFn,
        clearTimeoutFn: timers.clearTimeoutFn,
    });

    scheduler.start();
    const delayMs = await timers.fireOnly();

    assert.equal(delayMs, 5_000);
});

test('a slow capture is never overlapped by a second periodic attempt', async () => {
    const slow = deferred<Blob | null>();
    const { captureFrame, calls } = recordingCaptureFrame(
        async () => slow.promise,
    );
    const { onRecord, records } = recordingOnRecord();
    const timers = fakeTimers();

    const scheduler = createPhotoCaptureScheduler({
        minIntervalSeconds: 12,
        maxIntervalSeconds: 20,
        captureFrame,
        onRecord,
        setTimeoutFn: timers.setTimeoutFn,
        clearTimeoutFn: timers.clearTimeoutFn,
    });

    scheduler.start();
    timers.fireOnlyWithoutWaiting();
    await flushMicrotasks();

    // The capture is still in flight: exactly one call so far, and no
    // second timer scheduled — there is nowhere for a would-be
    // "next tick is due" check to fire from, because the next timer
    // does not exist until this capture's promise settles.
    assert.deepEqual(calls, ['periodic']);
    assert.equal(
        records.length,
        0,
        'no record yet — the capture has not settled',
    );
    assert.equal(
        timers.pendingCount(),
        0,
        'no next timer exists while a capture is still in flight',
    );

    slow.resolve(new Blob(['late']));
    await flushMicrotasks();

    assert.equal(records.length, 1);
    assert.equal(records[0].record.outcome, 'captured');
    assert.equal(
        timers.pendingCount(),
        1,
        'the next timer is only scheduled after the slow capture settles',
    );
    assert.deepEqual(
        calls,
        ['periodic'],
        'still exactly one capture attempt, never two',
    );
});

test('stop() during an in-flight capture leaves the recorded fact but schedules no new timer', async () => {
    const slow = deferred<Blob | null>();
    const { captureFrame } = recordingCaptureFrame(async () => slow.promise);
    const { onRecord, records } = recordingOnRecord();
    const timers = fakeTimers();

    const scheduler = createPhotoCaptureScheduler({
        minIntervalSeconds: 12,
        maxIntervalSeconds: 20,
        captureFrame,
        onRecord,
        setTimeoutFn: timers.setTimeoutFn,
        clearTimeoutFn: timers.clearTimeoutFn,
    });

    scheduler.start();
    timers.fireOnlyWithoutWaiting();
    await flushMicrotasks();

    assert.equal(timers.pendingCount(), 0, 'capture in flight, no timer yet');

    scheduler.stop();
    assert.equal(scheduler.isRunning(), false);

    slow.resolve(new Blob(['after stop']));
    await flushMicrotasks();

    assert.equal(
        records.length,
        1,
        'the in-flight attempt still reports its outcome — it genuinely happened',
    );
    assert.equal(
        timers.pendingCount(),
        0,
        'stop() before settlement must prevent a new timer from ever being scheduled — no leak',
    );
});
