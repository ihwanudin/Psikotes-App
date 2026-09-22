import { createRetryLoader } from '../session-runner/retry-loader.ts';
import type { RetryLoaderState } from '../session-runner/retry-loader.ts';
import type { FetchPapiItems, PapiItemsOutcome } from './papi-items.ts';

/**
 * Pure retry orchestration for GET /sessions/:id/items (PAPI) — no React,
 * no fetch, no DOM. The actual retry state machine (network_error/
 * rejection classification, the 5-consecutive-failure cap, stale-attempt
 * handling) lives in `../session-runner/retry-loader.ts`, shared across
 * every "fetch once, retry on connectivity" loader in this codebase
 * (Lead's 2026-09-21 extraction, `glm/retry-loader-extraction` — scoped
 * at the time to `resume-answers-loader.ts`, the only one of the four
 * near-identical copies merged to `main` then; PAPI/RMIB/Kraepelin's own
 * loaders migrate to the shared core in their own connection PRs once
 * merged, per that extraction's own doc — this file is that migration
 * for PAPI). This file is now a thin type-specific wrapper: it exists so
 * `createPapiItemsLoader`'s public shape (function name,
 * `PapiItemsLoaderState`, `PapiItemsLoaderOptions`) stays exactly what
 * `use-papi-items.ts` already depends on, unchanged.
 *
 * Every non-`network_error` outcome (`available`, `not_started`,
 * `closed`, `deadline_exceeded`, `not_found`, `content_unavailable`) is
 * final and is never auto-retried.
 */

export type PapiItemsLoaderState = RetryLoaderState<PapiItemsOutcome>;

export type PapiItemsLoaderOptions = {
    fetchItems: FetchPapiItems;
    /** Same shape as offline-queue.ts's `queueRetry`: queues `retry` to
     * run once connectivity is believed restored (immediately, if it
     * already is). */
    queueRetry: (retry: () => void) => () => void;
};

export type PapiItemsLoader = {
    getState: () => PapiItemsLoaderState;
    subscribe: (listener: (state: PapiItemsLoaderState) => void) => () => void;
    /** Starts the first attempt. Call once. */
    start: () => void;
    /** Retries immediately, bypassing any queued reconnect wait, and
     * resets the automatic-retry budget. Safe to call from any state. */
    retry: () => void;
    /** Cancels any in-flight or queued attempt so it can never update
     * state again — call on unmount. */
    dispose: () => void;
};

export function createPapiItemsLoader(
    options: PapiItemsLoaderOptions,
): PapiItemsLoader {
    return createRetryLoader<PapiItemsOutcome>({
        fetch: options.fetchItems,
        queueRetry: options.queueRetry,
        isNetworkError: (outcome) => outcome.type === 'network_error',
    });
}
