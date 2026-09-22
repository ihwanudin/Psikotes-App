import type { FetchRmibItems, RmibItemsOutcome } from './rmib-items.ts';

/**
 * Pure retry orchestration for `GET /sessions/:id/items` (RMIB) — no
 * React, no fetch, no DOM. This is the FOURTH near-identical copy of this
 * retry-loader pattern (after `session-runner/resume-answers-loader.ts`,
 * `kraepelin/items-loader.ts` on PR #69, and `papi/papi-items-loader.ts`
 * on the PAPI runner PR) — Lead's 2026-09-21 read when reviewing the PAPI
 * PR: three copies was already the last tolerable point, RMIB is the
 * fourth. The PAPI PR (#82) is still open/draft (not merged) as of this
 * file's creation, so per the approved RMIB plan this is written as a
 * fourth copy rather than against an extracted shared core, and
 * `tasks/handoffs/f2/papi-runner-retry-loader-extraction-debt.md` is
 * updated to say so — the extraction itself stays gated on the PAPI PR
 * merging, per Lead's explicit instruction not to start it before then.
 *
 * Same retry design as the other three: a `network_error` outcome AND a
 * rejected/thrown fetcher are both treated as "couldn't reach the
 * server", transitioning to `reconnecting` and retried through the
 * caller-supplied `queueRetry` (the same connectivity signal
 * `offline-queue.ts` exposes, never a second detector). Capped at
 * `MAX_CONSECUTIVE_AUTO_RETRIES` consecutive failures; `retry()` always
 * available and resets the budget. Every other outcome is final.
 */

const MAX_CONSECUTIVE_AUTO_RETRIES = 5;

export type RmibItemsLoaderState =
    | { status: 'loading' }
    | { status: 'reconnecting'; autoRetryExhausted: boolean }
    | { status: 'ready'; outcome: RmibItemsOutcome };

export type RmibItemsLoaderOptions = {
    fetchItems: FetchRmibItems;
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
    let state: RmibItemsLoaderState = { status: 'loading' };
    const listeners = new Set<(state: RmibItemsLoaderState) => void>();
    let attemptId = 0;
    let unsubscribeQueuedRetry: (() => void) | null = null;
    let disposed = false;
    let consecutiveFailures = 0;

    function setState(next: RmibItemsLoaderState): void {
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
            .fetchItems()
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
