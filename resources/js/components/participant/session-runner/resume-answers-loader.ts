import { createRetryLoader } from './retry-loader.ts';
import type { RetryLoaderState } from './retry-loader.ts';
import type {
    FetchResumeAnswers,
    ResumeAnswersOutcome,
} from './resume-answers.ts';

/**
 * Retry orchestration for GET /sessions/:id/answers, split out from
 * use-resume-answers.ts the same way autosave-engine.ts is split from
 * use-autosave.ts and camera-controller.ts from use-proctoring-camera.ts:
 * no React, no fetch, no DOM — testable via `node --test` without a
 * browser/jsdom.
 *
 * The actual retry state machine (network_error/rejection classification,
 * the 5-consecutive-failure cap, stale-attempt handling) now lives in
 * `retry-loader.ts`, shared across every "fetch once, retry on
 * connectivity" loader in this codebase (Lead's 2026-09-21 extraction —
 * this was the reference copy it was pulled out of, since it's the only
 * one of the four near-identical copies merged to `main` at the time).
 * This file is now a thin type-specific wrapper: it exists so
 * `createResumeAnswersLoader`'s public shape (function name,
 * `ResumeAnswersLoaderState`, `ResumeAnswersLoaderOptions`) stays exactly
 * what `use-resume-answers.ts` already depends on, unchanged.
 *
 * Every other outcome (`available`, `not_started`, `closed`,
 * `deadline_exceeded`, `not_found`) is final and is never auto-retried.
 */

export type ResumeAnswersLoaderState = RetryLoaderState<ResumeAnswersOutcome>;

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
    return createRetryLoader<ResumeAnswersOutcome>({
        fetch: options.fetchResumeAnswers,
        queueRetry: options.queueRetry,
        isNetworkError: (outcome) => outcome.type === 'network_error',
    });
}
