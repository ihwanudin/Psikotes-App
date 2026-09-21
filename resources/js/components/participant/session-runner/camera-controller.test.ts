import assert from 'node:assert/strict';
import { test } from 'node:test';

import { createCameraController } from './camera-controller.ts';
import type {
    ProctoringEvent,
    ProctoringReporter,
} from './proctoring-reporter.ts';

function recordingReporter(): {
    reporter: ProctoringReporter;
    events: ProctoringEvent[];
} {
    const events: ProctoringEvent[] = [];

    return { reporter: { report: (event) => events.push(event) }, events };
}

test('creating a controller never requests a camera stream (mount safety)', () => {
    let requestCount = 0;
    const { reporter } = recordingReporter();

    const controller = createCameraController({
        requestStream: async () => {
            requestCount++;

            return 'granted';
        },
        reporter,
    });

    assert.equal(requestCount, 0);
    assert.equal(controller.getStatus(), 'inactive');
});

test('only an explicit activate() call ever requests a stream', async () => {
    let requestCount = 0;
    const { reporter } = recordingReporter();
    const controller = createCameraController({
        requestStream: async () => {
            requestCount++;

            return 'granted';
        },
        reporter,
    });

    assert.equal(requestCount, 0, 'still zero before activate()');
    await controller.activate();
    assert.equal(requestCount, 1);
    assert.equal(controller.getStatus(), 'active');
});

test('a denied permission is reported and reflected in status, not silently swallowed', async () => {
    const { reporter, events } = recordingReporter();
    const controller = createCameraController({
        requestStream: async () => 'denied',
        reporter,
        now: () => '2026-09-21T00:00:00Z',
    });

    await controller.activate();

    assert.equal(controller.getStatus(), 'denied');
    assert.deepEqual(events, [
        {
            kind: 'camera_permission_denied',
            occurredAt: '2026-09-21T00:00:00Z',
        },
    ]);
});

test('an unavailable camera is reported and reflected in status', async () => {
    const { reporter, events } = recordingReporter();
    const controller = createCameraController({
        requestStream: async () => 'unavailable',
        reporter,
    });

    await controller.activate();

    assert.equal(controller.getStatus(), 'unavailable');
    assert.equal(events[0]?.kind, 'camera_unavailable');
});

test('handleStreamEnded before any activation is a no-op', () => {
    const { reporter, events } = recordingReporter();
    const controller = createCameraController({
        requestStream: async () => 'granted',
        reporter,
    });

    controller.handleStreamEnded();

    assert.equal(controller.getStatus(), 'inactive');
    assert.deepEqual(events, []);
});

test('handleStreamEnded after activation marks the stream interrupted and reports it', async () => {
    const { reporter, events } = recordingReporter();
    const controller = createCameraController({
        requestStream: async () => 'granted',
        reporter,
    });

    await controller.activate();
    controller.handleStreamEnded();

    assert.equal(controller.getStatus(), 'interrupted');
    assert.equal(events.at(-1)?.kind, 'camera_interrupted');
});

test('a muted track (handleStreamEnded reused for mute — no ended event on iOS Safari) is treated identically to a genuinely ended one', async () => {
    const { reporter, events } = recordingReporter();
    const controller = createCameraController({
        requestStream: async () => 'granted',
        reporter,
    });

    await controller.activate();
    // The hook wires both the 'ended' and 'mute' DOM events to this
    // same method (2026-09-21 fix) — this test documents that the pure
    // controller has no separate mute concept, by design.
    controller.handleStreamEnded();

    assert.equal(controller.getStatus(), 'interrupted');
    assert.equal(events.at(-1)?.kind, 'camera_interrupted');
});

test('handleStreamResumed() is a no-op unless status is currently interrupted', async () => {
    const { reporter, events } = recordingReporter();
    const controller = createCameraController({
        requestStream: async () => 'granted',
        reporter,
    });

    controller.handleStreamResumed(); // status is 'inactive'
    assert.equal(controller.getStatus(), 'inactive');
    assert.deepEqual(events, []);

    await controller.activate(); // status is now 'active'
    controller.handleStreamResumed();
    assert.equal(
        controller.getStatus(),
        'active',
        'resuming an already-active camera does nothing',
    );
    assert.deepEqual(events, []);
});

test('handleStreamResumed() returns directly to active and reports reactivation_succeeded, without a fresh requestStream() call', async () => {
    let requestCount = 0;
    const { reporter, events } = recordingReporter();
    const controller = createCameraController({
        requestStream: async () => {
            requestCount++;

            return 'granted';
        },
        reporter,
    });

    await controller.activate();
    assert.equal(requestCount, 1);
    controller.handleStreamEnded(); // simulates a 'mute' event
    assert.equal(controller.getStatus(), 'interrupted');

    controller.handleStreamResumed(); // simulates the matching 'unmute'

    assert.equal(controller.getStatus(), 'active');
    assert.equal(
        requestCount,
        1,
        'the same still-live track resumed — no new stream should ever be requested',
    );
    assert.deepEqual(
        events.map((event) => event.kind),
        ['camera_interrupted', 'camera_reactivation_succeeded'],
    );
});

test('handleStreamResumed() does not resurrect a status left behind by a separate, later reactivate() cycle', async () => {
    const { reporter } = recordingReporter();
    // Put the controller into reactivation_failed via its own full
    // cycle: granted (activate) -> ended -> reactivate() -> unavailable.
    const controller = createCameraController({
        requestStream: (() => {
            let call = 0;

            return async () => {
                call++;

                return call === 1 ? 'granted' : 'unavailable';
            };
        })(),
        reporter,
    });
    await controller.activate();
    controller.handleStreamEnded();
    await controller.reactivate();
    assert.equal(controller.getStatus(), 'reactivation_failed');

    // An unmute arriving late, from the ORIGINAL (now-abandoned) track,
    // must not pull this back to 'active'.
    controller.handleStreamResumed();
    assert.equal(controller.getStatus(), 'reactivation_failed');
});

test('reactivate() is a no-op unless the status is currently interrupted or reactivation_failed', async () => {
    let requestCount = 0;
    const { reporter } = recordingReporter();
    const controller = createCameraController({
        requestStream: async () => {
            requestCount++;

            return 'granted';
        },
        reporter,
    });

    await controller.reactivate(); // status is 'inactive'
    assert.equal(requestCount, 0);

    await controller.activate(); // status is now 'active'
    requestCount = 0;
    await controller.reactivate();
    assert.equal(
        requestCount,
        0,
        'reactivating an already-active camera does nothing',
    );
});

test('a successful reactivation reports attempted then succeeded and returns to active', async () => {
    const { reporter, events } = recordingReporter();
    const controller = createCameraController({
        requestStream: async () => 'granted',
        reporter,
    });

    await controller.activate();
    assert.equal(controller.getStatus(), 'active');
    controller.handleStreamEnded();
    assert.equal(controller.getStatus(), 'interrupted');

    await controller.reactivate();

    assert.equal(controller.getStatus(), 'active');
    assert.deepEqual(
        events.map((event) => event.kind),
        [
            'camera_interrupted',
            'camera_reactivation_attempted',
            'camera_reactivation_succeeded',
        ],
    );
});

test('a failed reactivation reports attempted then failed and does not silently claim success', async () => {
    const { reporter, events } = recordingReporter();
    const controller = createCameraController({
        requestStream: async () => 'denied',
        reporter,
    });

    await controller.activate(); // 'denied' — never actually active
    // Simulate the participant returning while status is still
    // 'interrupted' from a prior successful-then-lost stream: force it
    // via a granted activation, then an interruption, then a failing
    // reactivation attempt.
    const controller2 = createCameraController({
        requestStream: (() => {
            let call = 0;

            return async () => {
                call++;

                return call === 1 ? 'granted' : 'unavailable';
            };
        })(),
        reporter,
    });
    await controller2.activate();
    controller2.handleStreamEnded();
    await controller2.reactivate();

    assert.equal(controller2.getStatus(), 'reactivation_failed');
    const kinds = events.map((event) => event.kind);
    assert.ok(kinds.includes('camera_reactivation_attempted'));
    assert.ok(kinds.includes('camera_reactivation_failed'));
    assert.ok(
        !kinds.includes('camera_reactivation_succeeded'),
        'must never report success for a failed reactivation',
    );
});

test('reactivate() retried from reactivation_failed can still succeed (2026-09-21 fix)', async () => {
    const { reporter, events } = recordingReporter();
    let call = 0;
    const controller = createCameraController({
        requestStream: async () => {
            call++;

            // 1st: initial activate() succeeds. 2nd: reactivate() after
            // the stream dies fails. 3rd: a later reactivate() (e.g. the
            // hook's next visibilitychange/focus, or a manual retry
            // button) finally succeeds.
            if (call === 1) {
                return 'granted';
            }

            if (call === 2) {
                return 'unavailable';
            }

            return 'granted';
        },
        reporter,
    });

    await controller.activate();
    controller.handleStreamEnded();
    await controller.reactivate();
    assert.equal(
        controller.getStatus(),
        'reactivation_failed',
        'setup: first reactivation attempt must fail',
    );

    // Without the 2026-09-21 fix, this second reactivate() call would be
    // a no-op forever (the guard only accepted 'interrupted') — every
    // later visibilitychange/focus for the rest of the session would do
    // nothing, even though a fresh attempt could succeed.
    await controller.reactivate();

    assert.equal(controller.getStatus(), 'active');
    const kinds = events.map((event) => event.kind);
    assert.deepEqual(kinds, [
        'camera_interrupted',
        'camera_reactivation_attempted',
        'camera_reactivation_failed',
        'camera_reactivation_attempted',
        'camera_reactivation_succeeded',
    ]);
});

test('deactivate() resets to inactive and further stream-ended events are ignored', async () => {
    const { reporter, events } = recordingReporter();
    const controller = createCameraController({
        requestStream: async () => 'granted',
        reporter,
    });

    await controller.activate();
    controller.deactivate();
    assert.equal(controller.getStatus(), 'inactive');

    events.length = 0;
    controller.handleStreamEnded();
    assert.equal(controller.getStatus(), 'inactive');
    assert.deepEqual(events, []);
});

test('subscribe receives every status transition and unsubscribe stops delivery', async () => {
    const { reporter } = recordingReporter();
    const controller = createCameraController({
        requestStream: async () => 'granted',
        reporter,
    });
    const seen: string[] = [];
    const unsubscribe = controller.subscribe((status) => seen.push(status));

    await controller.activate();
    assert.deepEqual(seen, ['requesting', 'active']);

    unsubscribe();
    controller.deactivate();
    assert.deepEqual(
        seen,
        ['requesting', 'active'],
        'no more events after unsubscribe',
    );
});
