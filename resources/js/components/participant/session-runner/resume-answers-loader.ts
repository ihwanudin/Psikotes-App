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
 * failed with `network_error` (a transient signal glitch, not a real
 * rejection), the caller was left with no answer UI, no retry mechanism,
 * and a server timer that kept running regardless — the participant lost
 * test time to a network blip, not to anything about their answers.
 *
 * `network_error` is treated as retryable, not final: on it, this module
 * queues a retry through the caller-supplied `queueRetry` (the SAME
 * connectivity signal offline-queue.ts already tracks — this module has
 * no idea what "online" means, deliberately, so there is only ever one
 * connectivity detector in the app, not two). Every other outcome
 * (`available`, `not_started`, `closed`, `deadline_exceeded`,
 * `not_found`) is final and is never auto-retried.
 */

export type ResumeAnswersLoaderState =
    | { status: 'loading' }
    /** The last attempt returned `network_error`; a retry is queued to
     * fire once connectivity is believed restored (or `retry()` can be
     * called directly). Distinct from `error` and from `ready` so a page
     * can render "Menyambungkan kembali…" rather than a terminal message. */
    | { status: 'reconnecting' }
    | { status: 'ready'; outcome: ResumeAnswersOutcome }
    /** The fetch itself threw/rejected — an unexpected failure, not the
     * `network_error` outcome the contract defines. Not auto-retried. */
    | { status: 'error'; error: unknown };

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
    /** Retries immediately, bypassing any queued reconnect wait. Safe to
     * call from any state. */
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

    function setState(next: ResumeAnswersLoaderState): void {
        state = next;

        for (const listener of listeners) {
            listener(state);
        }
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
                    setState({ status: 'reconnecting' });
                    unsubscribeQueuedRetry = options.queueRetry(() => {
                        attempt();
                    });

                    return;
                }

                setState({ status: 'ready', outcome });
            })
            .catch((error: unknown) => {
                if (disposed || attemptId !== thisAttemptId) {
                    return;
                }

                setState({ status: 'error', error });
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
