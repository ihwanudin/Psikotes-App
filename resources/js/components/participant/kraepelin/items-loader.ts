import { createRetryLoader } from '../session-runner/retry-loader.ts';
import type { RetryLoaderState } from '../session-runner/retry-loader.ts';
import type {
    AssessmentSessionItemsOutcome,
    FetchAssessmentSessionItems,
} from './items.ts';

/**
 * Pure retry orchestration for GET /sessions/:id/items (Kraepelin) — no
 * React, no fetch, no DOM. The actual retry state machine now lives in
 * `../session-runner/retry-loader.ts`, shared across every "fetch once,
 * retry on connectivity" loader in this codebase (Lead's 2026-09-21
 * extraction, `glm/retry-loader-extraction` — see
 * `papi/papi-items-loader.ts`'s identical doc for the full history; this
 * file is that same migration for Kraepelin). This file is now a thin
 * type-specific wrapper: it exists so `createItemsLoader`'s public shape
 * (function name, `ItemsLoaderState`, `ItemsLoaderOptions`) stays exactly
 * what `use-items.ts` already depends on, unchanged.
 *
 * Every non-`network_error` outcome (`available`, `not_started`,
 * `closed`, `deadline_exceeded`, `not_found`, `content_unavailable`) is
 * final and is never auto-retried — `content_unavailable` in particular
 * means no reader is registered (or a registered one failed) server-side,
 * not a connectivity problem an immediate retry would fix.
 */

export type ItemsLoaderState = RetryLoaderState<AssessmentSessionItemsOutcome>;

export type ItemsLoaderOptions = {
    fetchItems: FetchAssessmentSessionItems;
    /** Same shape as offline-queue.ts's `queueRetry`. */
    queueRetry: (retry: () => void) => () => void;
};

export type ItemsLoader = {
    getState: () => ItemsLoaderState;
    subscribe: (listener: (state: ItemsLoaderState) => void) => () => void;
    /** Starts the first attempt. Call once. */
    start: () => void;
    /** Retries immediately, bypassing any queued reconnect wait, and
     * resets the automatic-retry budget. Safe to call from any state. */
    retry: () => void;
    /** Cancels any in-flight or queued attempt so it can never update
     * state again — call on unmount. */
    dispose: () => void;
};

export function createItemsLoader(options: ItemsLoaderOptions): ItemsLoader {
    return createRetryLoader<AssessmentSessionItemsOutcome>({
        fetch: options.fetchItems,
        queueRetry: options.queueRetry,
        isNetworkError: (outcome) => outcome.type === 'network_error',
    });
}
