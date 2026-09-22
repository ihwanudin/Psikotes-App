import { useEffect, useRef } from 'react';

import { createPhotoCaptureScheduler } from '../proctoring/photo-capture-scheduler.ts';
import type {
    CaptureKind,
    CaptureRecord,
    PhotoCaptureScheduler,
} from '../proctoring/photo-capture-scheduler.ts';
import type { CameraStatus } from './camera-controller.ts';
import type { CaptureFrameOptions } from './use-proctoring-camera.ts';

/**
 * Thin React wrapper connecting the pure `photo-capture-scheduler.ts`
 * (SPEC.md 8A.2, butir 12/PR #87) to the live camera via
 * `useProctoringCamera().captureFrame` (PR #91) — the split this
 * codebase already uses for camera-controller.ts/use-proctoring-camera.ts
 * and autosave-engine.ts/use-autosave.ts: the scheduler knows nothing
 * about React, MediaStream, or canvas; this hook only wires "when to
 * fire" to "how to grab a frame" and "what active means right now".
 *
 * `config` is the caller's server-supplied cadence/size/quality —
 * CLAUDE.md forbids baking configurable thresholds into code, so this
 * hook never has an opinion on the numbers, only on the wiring. Passing
 * `config: null` disables the feature entirely (no scheduler is ever
 * constructed) — the hook itself must still be called unconditionally
 * (React's rules of hooks), so the caller expresses "not configured for
 * this instrument/branch" through the value, not by skipping the call.
 *
 * `config` is trusted to be referentially stable across renders when its
 * values haven't actually changed (the caller should `useMemo` it from
 * server config, the same contract any dependency-array value needs) —
 * a new `config` object tears down and rebuilds the scheduler, stopping
 * any in-progress periodic wait and restarting the cadence from zero, so
 * this must not happen on every render.
 *
 * The scheduler is started/stopped to track `cameraStatus === 'active'`
 * exactly — no independent "should we be running" flag lives in this
 * hook, so there is only one place (the camera's own state machine) that
 * decides whether proctoring evidence should be captured right now.
 * Interruptions/reactivation already flow through `cameraStatus`, so no
 * extra wiring is needed here for that.
 *
 * Not implemented here (deliberately, out of scope for this increment):
 * uploading a captured frame anywhere. `config.onCapture(blob, kind)` is
 * the one seam a later transport/upload increment plugs into — it is
 * called with every successfully captured `Blob`, and nothing else in
 * this module inspects or holds onto that blob afterward.
 */

export type PeriodicPhotoCaptureConfig = {
    /** Lower bound of the randomized periodic interval, in seconds —
     * server-configured, never a constant here. See
     * photo-capture-scheduler.ts's module doc. */
    minIntervalSeconds: number;
    /** Upper bound of the randomized periodic interval, in seconds —
     * server-configured, never a constant here. */
    maxIntervalSeconds: number;
    /** Bounding box each captured frame is shrunk to fit within — passed
     * straight through to `captureFrame`, never a constant here (owner
     * decision item 14, PR #81, currently 480x360, but that value
     * belongs to whoever supplies this config, not this hook). */
    maxWidth: number;
    maxHeight: number;
    /** JPEG quality in [0, 1] — also caller-supplied, never a constant
     * here. */
    jpegQuality: number;
    /** The one extension point for a later transport/upload increment:
     * called once per successfully captured frame. Never called for a
     * `no_frame` outcome — see `onRecord` for that. */
    onCapture: (blob: Blob, kind: CaptureKind) => void;
    /** Called once per capture *attempt*, whether it produced a photo or
     * not — the evidence trail (SPEC.md 8A.2: a gap is a fact, not
     * something to hide). Optional because no caller needs it yet; wired
     * through unchanged from the scheduler's own `onRecord`. */
    onRecord?: (record: CaptureRecord) => void;
    /** Test-only injection points, passed straight through to
     * `createPhotoCaptureScheduler` — see its own doc. Never supplied by
     * real callers. */
    now?: () => string;
    random?: () => number;
    setTimeoutFn?: (callback: () => void, ms: number) => unknown;
    clearTimeoutFn?: (handle: unknown) => void;
};

export type UsePeriodicPhotoCaptureOptions = {
    cameraStatus: CameraStatus;
    captureFrame: (options: CaptureFrameOptions) => Promise<Blob | null>;
    /** `null` disables the feature entirely — see module doc. */
    config: PeriodicPhotoCaptureConfig | null;
};

export type UsePeriodicPhotoCaptureResult = {
    /** Whether the periodic loop is currently running. Computed directly
     * from this render's own `cameraStatus`/`config` inputs (`config !==
     * null && cameraStatus === 'active'`) rather than read imperatively
     * off the scheduler instance — the two are guaranteed identical by
     * this hook's own invariant (see module doc), and deriving it this
     * way makes it genuinely reactive: a plain ref read here would lag
     * by one render, since the effect that actually calls
     * `scheduler.start()`/`stop()` only runs *after* the render that
     * shows the new `cameraStatus` commits. */
    isRunning: boolean;
    /** Explicit, non-scheduled capture for `session_start`/
     * `session_submit` moments — not part of the random periodic
     * schedule. No-op (resolves immediately) when `config` is `null`.
     * Not called automatically by this hook; wiring an actual
     * session-start/submit trigger to this is a separate increment. */
    captureNow: (kind: 'session_start' | 'session_submit') => Promise<void>;
};

export function usePeriodicPhotoCapture({
    cameraStatus,
    captureFrame,
    config,
}: UsePeriodicPhotoCaptureOptions): UsePeriodicPhotoCaptureResult {
    const schedulerRef = useRef<PhotoCaptureScheduler | null>(null);

    useEffect(() => {
        if (config === null) {
            return;
        }

        const {
            minIntervalSeconds,
            maxIntervalSeconds,
            maxWidth,
            maxHeight,
            jpegQuality,
            onCapture,
            onRecord,
            now,
            random,
            setTimeoutFn,
            clearTimeoutFn,
        } = config;

        const scheduler = createPhotoCaptureScheduler({
            minIntervalSeconds,
            maxIntervalSeconds,
            now,
            random,
            setTimeoutFn,
            clearTimeoutFn,
            // The scheduler's own `CaptureKind` argument isn't needed here
            // — captureFrame() from useProctoringCamera() only takes
            // size/quality, not what triggered the capture. Omitting the
            // parameter entirely (rather than naming and ignoring it) is
            // valid: a callback may accept fewer arguments than the type
            // it satisfies allows.
            captureFrame: () =>
                captureFrame({ maxWidth, maxHeight, jpegQuality }),
            onRecord: (record, blob) => {
                onRecord?.(record);

                if (blob) {
                    onCapture(blob, record.kind);
                }
            },
        });

        schedulerRef.current = scheduler;

        return () => {
            scheduler.stop();
            schedulerRef.current = null;
        };
        // `config` is the whole dependency: the caller owns memoizing it,
        // exactly like any other dependency-array value (see module
        // doc). `captureFrame` from useProctoringCamera() is stable
        // (useCallback with no deps), so omitting it here does not risk
        // a stale closure in practice — re-reading it on every capture
        // instead of on every scheduler rebuild would require this
        // effect to depend on `captureFrame`'s identity too, which
        // would then also rebuild the scheduler on any render where the
        // caller passed a fresh function, exactly the churn this hook
        // is trying to avoid.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [config]);

    useEffect(() => {
        const scheduler = schedulerRef.current;

        if (!scheduler) {
            return;
        }

        if (cameraStatus === 'active') {
            scheduler.start();
        } else {
            scheduler.stop();
        }
        // Re-run whenever the scheduler is (re)constructed too, not just
        // when cameraStatus changes on its own — a fresh scheduler from
        // the effect above always starts `running: false`
        // (photo-capture-scheduler.ts's own guarantee), so if the camera
        // is already 'active' at the moment `config` first becomes
        // non-null, this effect must still run to actually start it.
    }, [cameraStatus, config]);

    return {
        isRunning: config !== null && cameraStatus === 'active',
        captureNow: (kind) =>
            schedulerRef.current?.captureNow(kind) ?? Promise.resolve(),
    };
}
