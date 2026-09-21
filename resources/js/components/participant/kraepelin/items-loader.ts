import type {
    AssessmentSessionItemsOutcome,
    FetchAssessmentSessionItems,
} from './items.ts';

/**
 * Pure retry orchestration for GET /sessions/:id/items — same shape and
 * same reasoning as session-runner/resume-answers-loader.ts (that
 * module's doc covers the "why" in full; not repeated here). Kept as its
 * own module rather than a shared generic loader: the two outcome sets
 * differ (this one has `content_unavailable`, resume-answers doesn't),
 * and duplicating ~130 lines is cheaper than forcing a premature
 * abstraction over two things that may keep diverging.
 *
 * `network_error` (a resolved outcome) and a rejected/thrown fetcher are
 * both treated as "couldn't reach the server right now" — retried
 * through the caller-supplied `queueRetry`, capped at
 * `MAX_CONSECUTIVE_AUTO_RETRIES` consecutive failures, with `retry()`
 * always available and resetting the budget. Every other outcome
 * (`available`, `not_started`, `closed`, `deadline_exceeded`,
 * `not_found`, `content_unavailable`) is final and never auto-retried —
 * `content_unavailable` in particular means no reader is registered (or
 * a registered one failed) server-side, not a connectivity problem an
 * immediate retry would fix.
 */

const MAX_CONSECUTIVE_AUTO_RETRIES = 5;

export type ItemsLoaderState =
    | { status: 'loading' }
    | { status: 'reconnecting'; autoRetryExhausted: boolean }
    | { status: 'ready'; outcome: AssessmentSessionItemsOutcome };

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
    let state: ItemsLoaderState = { status: 'loading' };
    const listeners = new Set<(state: ItemsLoaderState) => void>();
    let attemptId = 0;
    let unsubscribeQueuedRetry: (() => void) | null = null;
    let disposed = false;
    let consecutiveFailures = 0;

    function setState(next: ItemsLoaderState): void {
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
