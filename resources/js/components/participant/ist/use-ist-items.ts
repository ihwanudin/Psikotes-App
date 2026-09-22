import { useCallback, useEffect, useState } from 'react';

import type { GenericItemsOutcome } from '../session-runner/http-transport.ts';
import { createIstItemsLoader } from './ist-items-loader.ts';
import type { IstItemsLoaderState } from './ist-items-loader.ts';

/**
 * Thin React wrapper around ist-items-loader.ts — see that module's doc
 * for the retry design (network_error is retryable, everything else is
 * final). Mirrors use-resume-answers.ts's exact shape.
 */

export type UseIstItemsOptions = {
    fetchItems: () => Promise<GenericItemsOutcome>;
    /** From `useOfflineQueue()` (or `SessionRunnerContext.connectivity`)
     * — the SAME connectivity signal the shell's offline banner uses.
     * Not a second connectivity detector. */
    queueRetry: (retry: () => void) => () => void;
};

export type UseIstItemsResult = {
    state: IstItemsLoaderState;
    /** Retries immediately, bypassing any queued reconnect wait — wire
     * this to a "Coba lagi" button while `state.status === 'reconnecting'`. */
    retry: () => void;
};

export function useIstItems(options: UseIstItemsOptions): UseIstItemsResult {
    // `options` captured once, at mount — same contract as
    // use-resume-answers.ts's `options` (see that module's doc for why).
    const [loader] = useState(() => createIstItemsLoader(options));
    const [state, setState] = useState<IstItemsLoaderState>(() =>
        loader.getState(),
    );

    useEffect(() => {
        const unsubscribe = loader.subscribe(setState);
        loader.start();

        return () => {
            unsubscribe();
            loader.dispose();
        };
    }, [loader]);

    const retry = useCallback((): void => loader.retry(), [loader]);

    return { state, retry };
}
