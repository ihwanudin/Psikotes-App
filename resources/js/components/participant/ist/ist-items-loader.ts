import { createRetryLoader } from '../session-runner/retry-loader.ts';
import type { RetryLoaderState } from '../session-runner/retry-loader.ts';
import type { GenericItemsOutcome } from '../session-runner/http-transport.ts';
import { istItemsOutcomeFromGeneric } from './ist-items.ts';
import type { IstItemsOutcome } from './ist-items.ts';

/**
 * Pure retry orchestration for GET /sessions/:id/items (IST, all six
 * SE/WA/AN/GE/RA/ZR subtests at once) — no React, no fetch, no DOM. Built
 * on the shared retry core (`../session-runner/retry-loader.ts`,
 * `glm/retry-loader-extraction` #88) as a thin wrapper from the start —
 * IST never had its own pre-extraction copy of this pattern (see
 * `tasks/handoffs/f2/papi-runner-retry-loader-extraction-debt.md`'s
 * 2026-09-22 update: `ist-subtest-screen.tsx` took `subtest` as a prop,
 * no fetching of its own), so this mirrors PAPI/RMIB/Kraepelin's
 * post-extraction shape directly rather than being migrated from one.
 *
 * Every non-`network_error` outcome (`available`, `not_started`, `closed`,
 * `deadline_exceeded`, `not_found`, `content_unavailable`) is final and is
 * never auto-retried — `content_unavailable` in particular means no reader
 * is registered (or a registered one failed) server-side, not a
 * connectivity problem an immediate retry would fix.
 */

export type IstItemsLoaderState = RetryLoaderState<IstItemsOutcome>;

export type IstItemsLoaderOptions = {
    fetchItems: () => Promise<GenericItemsOutcome>;
    /** Same shape as offline-queue.ts's `queueRetry`. */
    queueRetry: (retry: () => void) => () => void;
};

export type IstItemsLoader = {
    getState: () => IstItemsLoaderState;
    subscribe: (listener: (state: IstItemsLoaderState) => void) => () => void;
    /** Starts the first attempt. Call once. */
    start: () => void;
    /** Retries immediately, bypassing any queued reconnect wait, and
     * resets the automatic-retry budget. Safe to call from any state. */
    retry: () => void;
    /** Cancels any in-flight or queued attempt so it can never update
     * state again — call on unmount. */
    dispose: () => void;
};

export function createIstItemsLoader(
    options: IstItemsLoaderOptions,
): IstItemsLoader {
    return createRetryLoader<IstItemsOutcome>({
        fetch: async () =>
            istItemsOutcomeFromGeneric(await options.fetchItems()),
        queueRetry: options.queueRetry,
        isNetworkError: (outcome) => outcome.type === 'network_error',
    });
}
