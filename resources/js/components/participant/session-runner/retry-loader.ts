/**
 * The shared retry state machine behind every "fetch once on mount, retry
 * on connectivity" loader in this codebase — no React, no fetch, no DOM,
 * testable via `node --test` without a browser/jsdom.
 *
 * Extracted from `resume-answers-loader.ts` (Lead's 2026-09-21
 * instruction — three other near-identical copies of this exact pattern
 * exist on branches not yet merged to `main`: Kraepelin's
 * `items-loader.ts` (PR #69), PAPI's `papi-items-loader.ts` (PR #82), and
 * RMIB's `rmib-items-loader.ts` — three copies was already flagged as the
 * last tolerable point before this extraction, and a fourth landed before
 * it happened. Only `resume-answers-loader.ts` is in `main` today, so
 * this extraction is scoped to that one copy; the other three migrate to
 * this shared core in their own connection PRs once merged (per Lead's
 * instruction, not done here — this module has no per-instrument
 * knowledge to migrate them with anyway).
 *
 * What's shared (this file): classifying a resolved outcome whose `type`
 * counts as "couldn't reach the server" and a rejected/thrown fetcher as
 * the SAME thing — both transition to `reconnecting` and are retried
 * through the caller-supplied `queueRetry` (the same connectivity signal
 * `offline-queue.ts` exposes, never a second connectivity detector).
 * Automatic retries are capped at `MAX_CONSECUTIVE_AUTO_RETRIES`
 * consecutive failures; once exhausted, state stays `reconnecting` with
 * `autoRetryExhausted: true` (never downgraded to a different-looking
 * terminal state — "couldn't connect" is still not final), and `retry()`
 * remains callable and resets the budget, since a human tapping "Coba
 * lagi" is fresh intent, not another automatic reflex. Stale-attempt
 * handling: an in-flight fetch racing a `dispose()` or a newer `retry()`
 * must never clobber state after the fact.
 *
 * What stays per-instrument (NOT this file): the outcome type itself,
 * which of its variants means "final" vs. retryable beyond the single
 * `isNetworkError` predicate below, and whatever mapping the caller's own
 * raw response needs before it reaches this core.
 */

const MAX_CONSECUTIVE_AUTO_RETRIES = 5;

export type RetryLoaderState<TOutcome> =
    | { status: 'loading' }
    /** Couldn't reach the server (a resolved outcome `isNetworkError`
     * accepts, or a rejected fetch) — not final. `autoRetryExhausted`
     * tells the caller whether this is still being retried automatically
     * or has hit the cap and is waiting for a manual `retry()`; either
     * way the copy shown must be "tidak dapat terhubung, coba lagi",
     * never a terminal message like "tes tidak tersedia". */
    | { status: 'reconnecting'; autoRetryExhausted: boolean }
    | { status: 'ready'; outcome: TOutcome };

export type RetryLoaderOptions<TOutcome> = {
    fetch: () => Promise<TOutcome>;
    /** Same shape as offline-queue.ts's `queueRetry`: queues `retry` to
     * run once connectivity is believed restored (immediately, if it
     * already is). */
    queueRetry: (retry: () => void) => () => void;
    /** True for exactly the resolved-outcome shape(s) this instrument's
     * contract treats as "couldn't reach the server" (typically a single
     * `outcome.type === 'network_error'` check) — everything else this
     * returns false for is final and is never auto-retried. */
    isNetworkError: (outcome: TOutcome) => boolean;
};

export type RetryLoader<TOutcome> = {
    getState: () => RetryLoaderState<TOutcome>;
    subscribe: (
        listener: (state: RetryLoaderState<TOutcome>) => void,
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

export function createRetryLoader<TOutcome>(
    options: RetryLoaderOptions<TOutcome>,
): RetryLoader<TOutcome> {
    let state: RetryLoaderState<TOutcome> = { status: 'loading' };
    const listeners = new Set<(state: RetryLoaderState<TOutcome>) => void>();
    let attemptId = 0;
    let unsubscribeQueuedRetry: (() => void) | null = null;
    let disposed = false;
    let consecutiveFailures = 0;

    function setState(next: RetryLoaderState<TOutcome>): void {
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
            .fetch()
            .then((outcome) => {
                if (disposed || attemptId !== thisAttemptId) {
                    return;
                }

                if (options.isNetworkError(outcome)) {
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
                // fetch") is indistinguishable from an isNetworkError
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
