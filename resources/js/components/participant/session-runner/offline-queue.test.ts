import assert from 'node:assert/strict';
import { test } from 'node:test';

import { createOfflineQueue } from './offline-queue.ts';

test('starts in the state given by initialBrowserOnline', () => {
    const online = createOfflineQueue({ initialBrowserOnline: true });
    assert.equal(online.getState(), 'online');

    const offline = createOfflineQueue({ initialBrowserOnline: false });
    assert.equal(offline.getState(), 'offline');
});

test('a real network failure overrides a browser flag that says online', () => {
    const queue = createOfflineQueue({ initialBrowserOnline: true });

    queue.reportNetworkOutcome('failure');

    assert.equal(
        queue.getState(),
        'offline',
        'a real failed request is stronger evidence than navigator.onLine',
    );
});

test('a real network success overrides a browser flag that says offline', () => {
    const queue = createOfflineQueue({ initialBrowserOnline: false });

    queue.reportNetworkOutcome('success');

    assert.equal(queue.getState(), 'online');
});

test('queueRetry runs immediately when already online', () => {
    const queue = createOfflineQueue({ initialBrowserOnline: true });
    let ran = 0;

    queue.queueRetry(() => {
        ran++;
    });

    assert.equal(ran, 1);
});

test('queueRetry waits for an online signal when currently offline', () => {
    const queue = createOfflineQueue({ initialBrowserOnline: false });
    let ran = 0;

    queue.queueRetry(() => {
        ran++;
    });

    assert.equal(ran, 0, 'must not run while still offline');

    queue.reportNetworkOutcome('success');

    assert.equal(ran, 1);
});

test('a browser online event can also trigger the queued retry', () => {
    const queue = createOfflineQueue({ initialBrowserOnline: false });
    let ran = 0;

    queue.queueRetry(() => {
        ran++;
    });
    queue.reportBrowserOnline(true);

    assert.equal(ran, 1);
});

test('only the most recently queued retry runs; an older one is replaced, not stacked', () => {
    const queue = createOfflineQueue({ initialBrowserOnline: false });
    const calls: string[] = [];

    queue.queueRetry(() => calls.push('first'));
    queue.queueRetry(() => calls.push('second'));
    queue.reportNetworkOutcome('success');

    assert.deepEqual(calls, ['second']);
});

test('a queued retry runs at most once even if reportNetworkOutcome fires again', () => {
    const queue = createOfflineQueue({ initialBrowserOnline: false });
    let ran = 0;

    queue.queueRetry(() => {
        ran++;
    });
    queue.reportNetworkOutcome('success');
    queue.reportNetworkOutcome('success');
    queue.reportBrowserOnline(true);

    assert.equal(ran, 1);
});

test('the unsubscribe function returned by queueRetry cancels that specific retry', () => {
    const queue = createOfflineQueue({ initialBrowserOnline: false });
    let ran = 0;

    const cancel = queue.queueRetry(() => {
        ran++;
    });
    cancel();
    queue.reportNetworkOutcome('success');

    assert.equal(ran, 0);
});

test('an unsubscribe call does not cancel a newer retry that already replaced it', () => {
    const queue = createOfflineQueue({ initialBrowserOnline: false });
    const calls: string[] = [];

    const cancelFirst = queue.queueRetry(() => calls.push('first'));
    queue.queueRetry(() => calls.push('second'));
    cancelFirst(); // stale handle; "second" has already replaced "first"
    queue.reportNetworkOutcome('success');

    assert.deepEqual(calls, ['second']);
});

test('going offline again after a success is reflected immediately', () => {
    const queue = createOfflineQueue({ initialBrowserOnline: true });

    queue.reportNetworkOutcome('success');
    assert.equal(queue.getState(), 'online');

    queue.reportNetworkOutcome('failure');
    assert.equal(queue.getState(), 'offline');
});
