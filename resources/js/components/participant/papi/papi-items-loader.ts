import type { FetchPapiItems, PapiItemsOutcome } from './papi-items.ts';

/**
 * Pure retry orchestration for GET /sessions/:id/items (PAPI) — same
 * shape and reasoning as kraepelin/items-loader.ts (that module's doc
 * covers the "why" in full, following the pattern Lead required after
 * catching the original resume-answers gap: a one-shot fetch that
 * blocks entering the test must not leave the participant stuck with no
 * UI and a running timer on a transient network blip). Kept as its own
 * module per-instrument rather than one shared generic loader — the
 * outcome sets already differ across the three copies that exist
 * (resume-answers, Kraepelin items, this one), and duplicating ~130
 * lines is cheaper than forcing a premature abstraction over things
 * that keep diverging.
 *
 * `network_error` (a resolved outcome) and a rejected/thrown fetcher are
 * both treated as "couldn't reach the server right now" — retried
 * through the caller-supplied `queueRetry`, capped at
 * `MAX_CONSECUTIVE_AUTO_RETRIES` consecutive failures, with `retry()`
 * always available and resetting the budget. Every other outcome
 * (`available`, `not_started`, `closed`, `deadline_exceeded`,
 * `not_found`, `content_unavailable`) is final and never auto-retried.
 */

const MAX_CONSECUTIVE_AUTO_RETRIES = 5;

export type PapiItemsLoaderState =
    | { status: 'loading' }
    | { status: 'reconnecting'; autoRetryExhausted: boolean }
    | { status: 'ready'; outcome: PapiItemsOutcome };

export type PapiItemsLoaderOptions = {
    fetchItems: FetchPapiItems;
    /** Same shape as offline-queue.ts's `queueRetry`. */
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
    let state: PapiItemsLoaderState = { status: 'loading' };
    const listeners = new Set<(state: PapiItemsLoaderState) => void>();
    let attemptId = 0;
    let unsubscribeQueuedRetry: (() => void) | null = null;
    let disposed = false;
    let consecutiveFailures = 0;

    function setState(next: PapiItemsLoaderState): void {
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
