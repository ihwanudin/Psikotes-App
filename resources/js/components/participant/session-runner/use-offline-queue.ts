import { useCallback, useEffect, useState } from 'react';

import { createOfflineQueue } from './offline-queue.ts';
import type { ConnectivityState } from './offline-queue.ts';

/**
 * Thin React wrapper around offline-queue.ts: wires the browser's
 * online/offline events to the pure tracker and re-syncs React state
 * whenever the tracker's connectivity verdict changes. The 30s session
 * poll and autosave sends (not this hook) are what actually call
 * `reportNetworkOutcome` — see module doc on offline-queue.ts for why a
 * real request outcome outranks the browser flag.
 */
export type UseOfflineQueueResult = {
    state: ConnectivityState;
    /** Wire this to the outcome of any real request the shell makes
     * (autosave send, session poll). */
    reportNetworkOutcome: (outcome: 'success' | 'failure') => void;
    /** Queues `retry` to run once connectivity is believed restored
     * (immediately, if it already is). See offline-queue.ts: at most one
     * retry is held at a time. */
    queueRetry: (retry: () => void) => () => void;
};

export function useOfflineQueue(): UseOfflineQueueResult {
    // A lazy useState initializer (not useRef): guaranteed to run
    // exactly once, so `queue` is a normal stable value safe to read
    // during render and to list in dependency arrays — unlike a
    // useRef's `.current`, which the project's react-hooks/refs rule
    // (React Compiler) forbids reading outside effects/handlers.
    const [queue] = useState(() =>
        createOfflineQueue({
            initialBrowserOnline:
                typeof navigator === 'undefined' ? true : navigator.onLine,
        }),
    );

    const [state, setState] = useState<ConnectivityState>(() =>
        queue.getState(),
    );

    useEffect(() => {
        function onOnline(): void {
            queue.reportBrowserOnline(true);
            setState(queue.getState());
        }
        function onOffline(): void {
            queue.reportBrowserOnline(false);
            setState(queue.getState());
        }

        window.addEventListener('online', onOnline);
        window.addEventListener('offline', onOffline);

        return () => {
            window.removeEventListener('online', onOnline);
            window.removeEventListener('offline', onOffline);
        };
    }, [queue]);

    const reportNetworkOutcome = useCallback(
        (outcome: 'success' | 'failure'): void => {
            queue.reportNetworkOutcome(outcome);
            setState(queue.getState());
        },
        [queue],
    );

    const queueRetry = useCallback(
        (retry: () => void): (() => void) => queue.queueRetry(retry),
        [queue],
    );

    return { state, reportNetworkOutcome, queueRetry };
}
