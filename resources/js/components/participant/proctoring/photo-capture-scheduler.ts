/**
 * Pure periodic-photo-capture scheduler (SPEC.md 8A.2: capture every
 * 12-20 seconds, randomized, plus mandatory session-start/submit
 * captures). No React, no MediaStream, no canvas — mirrors the split
 * `camera-controller.ts` uses: this module only knows WHEN to trigger a
 * capture, never how to take one. The actual frame grab is `captureFrame`,
 * injected by the caller (eventually a hook wrapping the live camera
 * stream — out of scope here, see
 * tasks/handoffs/f7/proctoring-camera-interruption-plan-2026-09-21.md §7).
 *
 * `minIntervalSeconds`/`maxIntervalSeconds` are constructor input, never
 * constants in this file — CLAUDE.md forbids baking configurable
 * thresholds into code, and SPEC.md 8A.2 says the cadence is
 * "configurable per branch". The confirmed proposal (480x360, JPEG
 * ~0.6, 90-day retention — owner decision item 14, PR #81) governs the
 * `captureFrame` implementation's encoding, not this scheduler, which
 * never touches pixels.
 *
 * `captureFrame` resolves to `null` for "no frame available" (camera
 * not active right now) — never throws for that case (Lead's 2026-09-21
 * review of PR #84 §6 item 5: a missing frame is a normal, expected
 * condition, not a failure). This scheduler does not pause its periodic
 * loop when frames go missing; it keeps ticking on schedule and records
 * each miss as its own fact via `onRecord`, exactly like every other
 * proctoring signal in this codebase — a gap is evidence, not something
 * to hide by skipping silently.
 *
 * Constructing this scheduler never captures anything on its own — same
 * "mounting alone must never act" guarantee `camera-controller.ts`
 * makes for `getUserMedia`. Nothing happens until `start()` (periodic)
 * or `captureNow()` (explicit `session_start`/`session_submit`) is
 * called.
 *
 * Slow captures (Lead's 2026-09-21 review of the initial PR): this
 * module picks **wait-then-schedule**, not skip-and-continue. The next
 * periodic timer is only created inside `runCapture`'s own completion
 * handler (see `scheduleNext`), so a second periodic capture can never
 * start while one is still in flight — there is no separate "is a tick
 * due" check that could race against a slow `captureFrame`. A capture
 * that takes longer than the nominal interval simply pushes the next
 * one later; it does not queue a skipped tick or fire two attempts back
 * to back.
 *
 * `stop()` while a capture is in flight: the in-flight `captureFrame`
 * call is not cancelled (there is nothing to cancel — it is the
 * caller's promise), and its outcome still reaches `onRecord` once
 * settled, because the attempt genuinely happened and is still a fact
 * worth recording. What `stop()` guarantees is narrower and load-bearing
 * for cleanup: no *new* timer is ever scheduled once `running` is false,
 * checked at the one place a new timer could be created — so a
 * `stop()` called mid-capture (e.g. on unmount) can never leak a
 * dangling timeout, even though the in-flight promise itself keeps
 * running to completion.
 */

export type CaptureKind = 'session_start' | 'periodic' | 'session_submit';

export type CaptureRecord = {
    kind: CaptureKind;
    triggeredAt: string;
    outcome: 'captured' | 'no_frame';
};

export type PhotoCaptureSchedulerOptions = {
    /** Lower bound of the randomized periodic interval, in seconds.
     * Server-configured; must be a positive number. */
    minIntervalSeconds: number;
    /** Upper bound of the randomized periodic interval, in seconds.
     * Server-configured; must be >= minIntervalSeconds. */
    maxIntervalSeconds: number;
    /** The only way this module ever captures anything. Must resolve,
     * never reject, for the ordinary "camera not active" case (resolve
     * `null` instead) — see module doc. A genuinely unexpected rejection
     * is still handled (recorded as `no_frame`, loop keeps running) so
     * one bad tick can never silently end proctoring evidence for the
     * rest of the session. */
    captureFrame: (kind: CaptureKind) => Promise<Blob | null>;
    /** Called once per capture attempt, whether it produced a photo or
     * not — this is the only way facts leave this module. Never called
     * before a capture is actually attempted (no synthetic/batched
     * records). */
    onRecord: (record: CaptureRecord, blob: Blob | null) => void;
    now?: () => string;
    /** Injectable for deterministic tests. Must return a value in
     * [0, 1); defaults to `Math.random()`. */
    random?: () => number;
    /** Injectable so tests can control scheduling without real delays.
     * Default to the real timer functions. */
    setTimeoutFn?: (callback: () => void, ms: number) => unknown;
    clearTimeoutFn?: (handle: unknown) => void;
};

export type PhotoCaptureScheduler = {
    /** Starts the periodic randomized-interval loop. No-op if already
     * running. */
    start: () => void;
    /** Stops the periodic loop. Does not affect `captureNow()`, which
     * can still be called explicitly (e.g. for `session_submit`) after
     * stopping. */
    stop: () => void;
    /** Explicit, non-scheduled capture for `session_start`/
     * `session_submit` — caller-triggered moments, not part of the
     * random periodic schedule. Independent of `start()`/`stop()`. */
    captureNow: (kind: 'session_start' | 'session_submit') => Promise<void>;
    isRunning: () => boolean;
};

export function createPhotoCaptureScheduler(
    options: PhotoCaptureSchedulerOptions,
): PhotoCaptureScheduler {
    const {
        minIntervalSeconds,
        maxIntervalSeconds,
        captureFrame,
        onRecord,
        now = () => new Date().toISOString(),
        random = Math.random,
        setTimeoutFn = (callback: () => void, ms: number) =>
            setTimeout(callback, ms),
        clearTimeoutFn = (handle: unknown) =>
            clearTimeout(handle as ReturnType<typeof setTimeout>),
    } = options;

    if (minIntervalSeconds <= 0) {
        throw new Error(
            'photo-capture-scheduler: minIntervalSeconds must be positive.',
        );
    }

    if (maxIntervalSeconds < minIntervalSeconds) {
        throw new Error(
            'photo-capture-scheduler: maxIntervalSeconds must be >= minIntervalSeconds.',
        );
    }

    let running = false;
    let timeoutHandle: unknown = null;

    function nextDelayMs(): number {
        const spanSeconds = maxIntervalSeconds - minIntervalSeconds;

        return (minIntervalSeconds + random() * spanSeconds) * 1000;
    }

    async function runCapture(kind: CaptureKind): Promise<void> {
        let blob: Blob | null;

        try {
            blob = await captureFrame(kind);
        } catch {
            // An unexpected rejection is still just a missed frame from
            // this scheduler's point of view — see module doc. It must
            // never take down the periodic loop.
            blob = null;
        }

        onRecord(
            {
                kind,
                triggeredAt: now(),
                outcome: blob ? 'captured' : 'no_frame',
            },
            blob,
        );
    }

    function scheduleNext(): void {
        timeoutHandle = setTimeoutFn(() => {
            void runCapture('periodic').then(() => {
                if (running) {
                    scheduleNext();
                }
            });
        }, nextDelayMs());
    }

    function start(): void {
        if (running) {
            return;
        }

        running = true;
        scheduleNext();
    }

    function stop(): void {
        running = false;

        if (timeoutHandle !== null) {
            clearTimeoutFn(timeoutHandle);
            timeoutHandle = null;
        }
    }

    return {
        start,
        stop,
        captureNow: (kind) => runCapture(kind),
        isRunning: () => running,
    };
}
