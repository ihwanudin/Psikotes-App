import { useCallback, useEffect, useState } from 'react';

import { createPapiItemsLoader } from './papi-items-loader.ts';
import type { PapiItemsLoaderState } from './papi-items-loader.ts';
import type { FetchPapiItems } from './papi-items.ts';

/**
 * Thin React wrapper around papi-items-loader.ts — see that module's doc
 * for the retry design (network_error/rejection retryable, everything
 * else final). Mirrors kraepelin/use-items.ts and
 * session-runner/use-resume-answers.ts.
 */

export type UsePapiItemsOptions = {
    fetchItems: FetchPapiItems;
    /** From `useOfflineQueue()` (or `SessionRunnerContext.connectivity`)
     * — the SAME connectivity signal the shell's offline banner uses.
     * Not a second connectivity detector. */
    queueRetry: (retry: () => void) => () => void;
};

export type UsePapiItemsResult = {
    state: PapiItemsLoaderState;
    /** Retries immediately, bypassing any queued reconnect wait — wire
     * this to a "Coba lagi" button while reconnecting. */
    retry: () => void;
};

export function usePapiItems(options: UsePapiItemsOptions): UsePapiItemsResult {
    // `options` captured once, at mount — same "captured once" contract
    // as use-autosave.ts's `send` (see that module's doc for why).
    const [loader] = useState(() => createPapiItemsLoader(options));
    const [state, setState] = useState<PapiItemsLoaderState>(() =>
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
