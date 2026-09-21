import type {
    FetchResumeAnswers,
    ResumeAnswersOutcome,
} from './resume-answers.ts';

/**
 * Pure retry orchestration for GET /sessions/:id/answers, split out from
 * use-resume-answers.ts the same way autosave-engine.ts is split from
 * use-autosave.ts and camera-controller.ts from use-proctoring-camera.ts:
 * no React, no fetch, no DOM — testable via `node --test` without a
 * browser/jsdom.
 *
 * Exists to close a real gap Lead caught in review (2026-09-21): the
 * original hook fetched exactly once, on mount. If that one attempt
 * failed, the caller was left with no answer UI, no retry mechanism, and
 * a server timer that kept running regardless — the participant lost
 * test time to a network blip, not to anything about their answers.
 *
 * Two different technical shapes both mean the same thing to a
 * participant — "couldn't reach the server right now" — and are treated
 * identically here (Lead's second review pass, 2026-09-21): the
 * `network_error` OUTCOME the contract defines (a resolved promise; the
 * fetcher chose to report it that way), and the fetcher's promise
 * REJECTING outright (e.g. a real `TypeError: Failed to fetch` from an
 * actual dropped signal — the far more common real-world shape, and the
 * one the original version of this module missed). Both transition to
 * `reconnecting` and are retried through the caller-supplied
 * `queueRetry` — the SAME connectivity signal offline-queue.ts already
 * tracks (via `useOfflineQueue()`/`SessionRunnerContext.connectivity`),
 * not a second connectivity detector.
 *
 * Every other outcome (`available`, `not_started`, `closed`,
 * `deadline_exceeded`, `not_found`) is final and is never auto-retried.
 *
 * Automatic retries are capped at `MAX_CONSECUTIVE_AUTO_RETRIES`
 * consecutive connectivity-type failures — `queueRetry` only fires on a
 * genuine connectivity-restored signal (not a blind timer), but without
 * a cap, a real programming bug in the injected fetcher (always throws,
 * unrelated to actual connectivity) would retry forever every time the
 * browser reports itself online. Once exhausted, the state stays
 * `reconnecting` (never downgraded to some other terminal-looking state
 * — "tidak dapat terhubung" is still not final) with
 * `autoRetryExhausted: true`, and `retry()` remains callable — a manual
 * retry resets the budget, since a human tapping "Coba lagi" is fresh
 * intent, not another automatic reflex.
 */

const MAX_CONSECUTIVE_AUTO_RETRIES = 5;

export type ResumeAnswersLoaderState =
    | { status: 'loading' }
    /** Couldn't reach the server (network_error outcome or a rejected
     * fetch) — not final. `autoRetryExhausted` tells the caller whether
     * this is still being retried automatically or has hit the cap and
     * is waiting for a manual `retry()`; either way the copy shown must
     * be "tidak dapat terhubung, coba lagi", never a terminal message
     * like "tes tidak tersedia". */
    | { status: 'reconnecting'; autoRetryExhausted: boolean }
    | { status: 'ready'; outcome: ResumeAnswersOutcome };

export type ResumeAnswersLoaderOptions = {
    fetchResumeAnswers: FetchResumeAnswers;
    /** Same shape as offline-queue.ts's `queueRetry`: queues `retry` to
     * run once connectivity is believed restored (immediately, if it
     * already is). */
    queueRetry: (retry: () => void) => () => void;
};

export type ResumeAnswersLoader = {
    getState: () => ResumeAnswersLoaderState;
    subscribe: (
        listener: (state: ResumeAnswersLoaderState) => void,
    ) => () => void;
    /** Starts the first attempt. Call once. */
    start: () => void;
    /** Retries immediately, bypassing any queued reconnect wait, and
     * resets the automatic-retry budget. Safe to call from any state. */
    retry: () => void;
    /** Cancels any in-flight or queued attempt so it can never update
     * state again — call on unmount. */
    dispose: () => void;
};

export function createResumeAnswersLoader(
    options: ResumeAnswersLoaderOptions,
): ResumeAnswersLoader {
    let state: ResumeAnswersLoaderState = { status: 'loading' };
    const listeners = new Set<(state: ResumeAnswersLoaderState) => void>();
    let attemptId = 0;
    let unsubscribeQueuedRetry: (() => void) | null = null;
    let disposed = false;
    let consecutiveFailures = 0;

    function setState(next: ResumeAnswersLoaderState): void {
        state = next;

        for (const listener of listeners) {
            listener(state);
        }
    }

    function onConnectivityFailure(): void {
        consecutiveFailures++;
        const exhausted = consecutiveFailures >= MAX_CONSECUTIVE_AUTO_RETRIES;
        setState({ status: 'reconnecting', autoRetryExhausted: exhausted });

        if (exhausted) {
            return;
        }

        unsubscribeQueuedRetry = options.queueRetry(() => {
            attempt();
        });
    }

    function attempt(): void {
        unsubscribeQueuedRetry?.();
        unsubscribeQueuedRetry = null;

        const thisAttemptId = ++attemptId;

        options
            .fetchResumeAnswers()
            .then((outcome) => {
                if (disposed || attemptId !== thisAttemptId) {
                    return;
                }

                if (outcome.type === 'network_error') {
                    onConnectivityFailure();

                    return;
                }

                consecutiveFailures = 0;
                setState({ status: 'ready', outcome });
            })
            .catch(() => {
                if (disposed || attemptId !== thisAttemptId) {
                    return;
                }

                // A rejected/thrown fetcher (e.g. a real "Failed to
                // fetch") is indistinguishable from the network_error
                // outcome above, from the participant's point of view —
                // see module doc. The concrete error is intentionally
                // not surfaced in state; there is nothing UI-actionable
                // about it beyond "couldn't connect, retrying".
                onConnectivityFailure();
            });
    }

    return {
        getState: () => state,
        subscribe(listener) {
            listeners.add(listener);

            return () => listeners.delete(listener);
        },
        start() {
            attempt();
        },
        retry() {
            consecutiveFailures = 0;
            setState({ status: 'loading' });
            attempt();
        },
        dispose() {
            disposed = true;
            unsubscribeQueuedRetry?.();
            unsubscribeQueuedRetry = null;
        },
    };
}
