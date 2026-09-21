/**
 * Pure connectivity tracker + single-slot retry queue. No React, no
 * fetch, no timers of its own — the caller (a hook) wires this to real
 * signals: the browser's online/offline events, and the outcome of real
 * network attempts the app already makes.
 *
 * Per Lead's 2026-09-21 decision, this module does NOT add a dedicated
 * connectivity-check request: `GET /sessions/:id`'s regular poll is
 * already the "is the server reachable" signal, reported here via
 * `reportNetworkOutcome`. `navigator.onLine` alone is not trusted for
 * "online" — it only reflects the OS network adapter, not whether the
 * server is actually reachable (a captive portal or VPN issue can leave
 * it `true` while every request fails) — so a real network outcome, when
 * available, always overrides the raw browser flag.
 *
 * The queue holds at most one pending retry. A newer `queueRetry()` call
 * replaces an older one rather than stacking: retrying stale intent
 * (e.g. an autosave flush that a later edit has already superseded) is
 * not useful, and the caller re-queues whatever is still actually
 * pending each time it tries and fails.
 *
 * In-memory only, by design (Lead's 2026-09-21 decision): a hard reload
 * loses anything queued here. Session resume (GET /sessions/:id)
 * re-derives state from the server rather than trusting a client queue,
 * so this is an acceptable, explicitly documented trade-off — not
 * silently dropped, see the PR description's offline-queue disclosure.
 */

export type ConnectivityState = 'online' | 'offline';

export type OfflineQueue = {
    getState: () => ConnectivityState;
    /** Wire this to `window.addEventListener('online'/'offline', ...)`. */
    reportBrowserOnline: (online: boolean) => void;
    /** Wire this to the outcome of any real request the app makes
     * (autosave send, session poll) — a real outcome is trusted over the
     * browser's online/offline flag. */
    reportNetworkOutcome: (outcome: 'success' | 'failure') => void;
    /** Queues `retry` to run once the tracker believes it is online
     * (immediately, if it already does). Replaces any previously queued
     * retry. Returns a function that cancels this specific queued retry
     * (a no-op if a newer retry has already replaced or run it). */
    queueRetry: (retry: () => void) => () => void;
};

export type OfflineQueueOptions = {
    initialBrowserOnline: boolean;
};

export function createOfflineQueue(options: OfflineQueueOptions): OfflineQueue {
    let browserOnline = options.initialBrowserOnline;
    let lastNetworkOutcome: 'success' | 'failure' | null = null;
    let queuedRetry: (() => void) | null = null;

    function state(): ConnectivityState {
        // A real, recent network outcome is stronger evidence than the
        // browser's adapter-level flag, in either direction.
        if (lastNetworkOutcome === 'failure') {
            return 'offline';
        }

        if (lastNetworkOutcome === 'success') {
            return 'online';
        }

        return browserOnline ? 'online' : 'offline';
    }

    function runQueuedRetryIfOnline(): void {
        if (state() !== 'online' || queuedRetry === null) {
            return;
        }

        const retry = queuedRetry;
        queuedRetry = null;
        retry();
    }

    return {
        getState: state,
        reportBrowserOnline(online: boolean) {
            browserOnline = online;
            runQueuedRetryIfOnline();
        },
        reportNetworkOutcome(outcome: 'success' | 'failure') {
            lastNetworkOutcome = outcome;
            runQueuedRetryIfOnline();
        },
        queueRetry(retry: () => void) {
            queuedRetry = retry;
            runQueuedRetryIfOnline();

            return () => {
                if (queuedRetry === retry) {
                    queuedRetry = null;
                }
            };
        },
    };
}
