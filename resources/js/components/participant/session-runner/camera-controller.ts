import type { ProctoringReporter } from './proctoring-reporter.ts';

/**
 * Pure camera status state machine. No React, no MediaStream, no DOM —
 * the actual `getUserMedia` call and track-lifecycle listeners live in
 * useProctoringCamera (a hook), which is the only thing this module
 * doesn't know about. Separated the same way autosave-engine.ts is
 * separated from use-autosave.ts, specifically so the one behavior Lead
 * required to be tested — "mounting alone must never call getUserMedia"
 * — is a pure, `node --test`-able assertion (creating a controller never
 * calls `requestStream`) rather than something that needs a real DOM.
 *
 * SPEC.md 8A.7 requires a proctoring consent screen before the camera
 * ever activates; that screen is out of scope for this increment (Lead's
 * 2026-09-21 decision). `activate()` exists so a future consent screen
 * has something explicit to call — nothing in this module or the hook
 * wrapping it may call it on its own.
 */

export type CameraStatus =
    | 'inactive'
    | 'requesting'
    | 'active'
    | 'denied'
    | 'unavailable'
    | 'interrupted'
    | 'reactivating'
    | 'reactivation_failed';

export type StreamRequestOutcome = 'granted' | 'denied' | 'unavailable';

export type CameraControllerOptions = {
    /** The only way this module ever asks for a camera stream. Never
     * called except from `activate()` or `reactivate()`, both of which
     * are only ever invoked by an explicit external caller. */
    requestStream: () => Promise<StreamRequestOutcome>;
    reporter: ProctoringReporter;
    now?: () => string;
};

export type CameraController = {
    getStatus: () => CameraStatus;
    /** Requests a stream. The only entry point that ever calls
     * `requestStream` for the first time — must be wired to an explicit
     * participant action (eventually the SPEC.md 8A.7 consent screen),
     * never to mount. */
    activate: () => Promise<void>;
    /** Stops treating the camera as active. Does not itself stop any
     * MediaStream — that is the hook's responsibility, since this module
     * never holds one. */
    deactivate: () => void;
    /** Call when the hook observes the underlying track end OR mute
     * (SPEC.md 8A.2's mobile app-switch/lock-screen case). `mute` is the
     * signal that actually fires on iOS Safari and several Android
     * browsers when the camera is backgrounded/taken by another app —
     * `ended` never comes on those platforms, the track just stops
     * producing frames while staying `readyState === 'live'` (2026-09-21
     * fix, Lead's review of PR #91). Both signals reach this same
     * method: from this controller's point of view "stream stopped
     * producing frames" is one fact regardless of which DOM event
     * reported it. A no-op if the controller was never activated or has
     * since been deactivated. */
    handleStreamEnded: () => void;
    /** Call when the hook observes the underlying track `unmute` on the
     * SAME still-live track that caused `handleStreamEnded()` — the
     * camera never actually stopped at the OS level, so frames can
     * resume immediately without a fresh `requestStream()` round trip.
     * A no-op unless status is currently `interrupted`: if a later,
     * separate `reactivate()` cycle has since taken over (e.g. it
     * already failed and moved to `reactivation_failed`), an unmute on
     * the old, already-abandoned track must not resurrect a state that
     * belongs to a different attempt. */
    handleStreamResumed: () => void;
    /** Attempts to re-request a stream after an interruption. A no-op
     * unless status is currently `interrupted` or `reactivation_failed`
     * — reactivating an inactive or already-active camera is
     * meaningless. Also retriable from `reactivation_failed` (not just
     * `interrupted`, 2026-09-21 fix): without this, a single failed
     * attempt would permanently stop every later automatic retry for
     * the rest of the session, even though the hook keeps calling this
     * on every subsequent `visibilitychange`/`focus` — see
     * tasks/handoffs/f7/proctoring-camera-interruption-plan-2026-09-21.md
     * §2. This does not add any new automatic trigger; it only widens
     * which status the existing external triggers can act on. */
    reactivate: () => Promise<void>;
    subscribe: (listener: (status: CameraStatus) => void) => () => void;
};

export function createCameraController(
    options: CameraControllerOptions,
): CameraController {
    let status: CameraStatus = 'inactive';
    let everActivated = false;
    const listeners = new Set<(status: CameraStatus) => void>();
    const now = options.now ?? (() => new Date().toISOString());

    function setStatus(next: CameraStatus): void {
        status = next;

        for (const listener of listeners) {
            listener(status);
        }
    }

    async function requestAndApply(
        onGranted: () => void,
        onDenied: () => void,
        onUnavailable: () => void,
    ): Promise<void> {
        const outcome = await options.requestStream();

        switch (outcome) {
            case 'granted':
                onGranted();
                break;
            case 'denied':
                onDenied();
                break;
            case 'unavailable':
                onUnavailable();
                break;
        }
    }

    async function activate(): Promise<void> {
        everActivated = true;
        setStatus('requesting');
        await requestAndApply(
            () => setStatus('active'),
            () => {
                options.reporter.report({
                    kind: 'camera_permission_denied',
                    occurredAt: now(),
                });
                setStatus('denied');
            },
            () => {
                options.reporter.report({
                    kind: 'camera_unavailable',
                    occurredAt: now(),
                });
                setStatus('unavailable');
            },
        );
    }

    function deactivate(): void {
        everActivated = false;
        setStatus('inactive');
    }

    function handleStreamEnded(): void {
        if (!everActivated) {
            return;
        }

        options.reporter.report({
            kind: 'camera_interrupted',
            occurredAt: now(),
        });
        setStatus('interrupted');
    }

    function handleStreamResumed(): void {
        if (status !== 'interrupted') {
            return;
        }

        // Reuses 'camera_reactivation_succeeded' rather than a new kind
        // — from any consumer's point of view this is the same fact as
        // a reactivate()-driven recovery ("the camera is producing
        // frames again"), and the backend's allowed-kinds list for
        // client observations is a shared, already-scarce resource (see
        // tasks/handoffs/f7/proctoring-camera-interruption-plan-2026-09-21.md
        // §3 celah 2 — F2 hasn't decided whether to widen it yet).
        options.reporter.report({
            kind: 'camera_reactivation_succeeded',
            occurredAt: now(),
        });
        setStatus('active');
    }

    async function reactivate(): Promise<void> {
        if (status !== 'interrupted' && status !== 'reactivation_failed') {
            return;
        }

        options.reporter.report({
            kind: 'camera_reactivation_attempted',
            occurredAt: now(),
        });
        setStatus('reactivating');
        await requestAndApply(
            () => {
                options.reporter.report({
                    kind: 'camera_reactivation_succeeded',
                    occurredAt: now(),
                });
                setStatus('active');
            },
            () => {
                options.reporter.report({
                    kind: 'camera_reactivation_failed',
                    occurredAt: now(),
                });
                setStatus('reactivation_failed');
            },
            () => {
                options.reporter.report({
                    kind: 'camera_reactivation_failed',
                    occurredAt: now(),
                });
                setStatus('reactivation_failed');
            },
        );
    }

    return {
        getStatus: () => status,
        activate,
        deactivate,
        handleStreamEnded,
        handleStreamResumed,
        reactivate,
        subscribe(listener) {
            listeners.add(listener);

            return () => listeners.delete(listener);
        },
    };
}
