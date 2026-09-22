import { createRetryLoader } from '../session-runner/retry-loader.ts';
import type { RetryLoaderState } from '../session-runner/retry-loader.ts';
import type { FetchRmibItems, RmibItemsOutcome } from './rmib-items.ts';

/**
 * Pure retry orchestration for GET /sessions/:id/items (RMIB) — no React,
 * no fetch, no DOM. The actual retry state machine now lives in
 * `../session-runner/retry-loader.ts`, shared across every "fetch once,
 * retry on connectivity" loader in this codebase (Lead's 2026-09-21
 * extraction, `glm/retry-loader-extraction` — see `papi-items-loader.ts`'s
 * identical doc for the full history; this file is that same migration
 * for RMIB). This file is now a thin type-specific wrapper: it exists so
 * `createRmibItemsLoader`'s public shape (function name,
 * `RmibItemsLoaderState`, `RmibItemsLoaderOptions`) stays exactly what
 * `use-rmib-items.ts` already depends on, unchanged.
 *
 * Every non-`network_error` outcome is final and is never auto-retried.
 */

export type RmibItemsLoaderState = RetryLoaderState<RmibItemsOutcome>;

export type RmibItemsLoaderOptions = {
    fetchItems: FetchRmibItems;
    /** Same shape as offline-queue.ts's `queueRetry`. */
    queueRetry: (retry: () => void) => () => void;
};

export type RmibItemsLoader = {
    getState: () => RmibItemsLoaderState;
    subscribe: (listener: (state: RmibItemsLoaderState) => void) => () => void;
    /** Starts the first attempt. Call once. */
    start: () => void;
    /** Retries immediately, bypassing any queued reconnect wait, and
     * resets the automatic-retry budget. Safe to call from any state. */
    retry: () => void;
    /** Cancels any in-flight or queued attempt so it can never update
     * state again — call on unmount. */
    dispose: () => void;
};

export function createRmibItemsLoader(
    options: RmibItemsLoaderOptions,
): RmibItemsLoader {
    return createRetryLoader<RmibItemsOutcome>({
        fetch: options.fetchItems,
        queueRetry: options.queueRetry,
        isNetworkError: (outcome) => outcome.type === 'network_error',
    });
}
