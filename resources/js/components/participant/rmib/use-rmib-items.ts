import { useCallback, useEffect, useState } from 'react';

import { createRmibItemsLoader } from './rmib-items-loader.ts';
import type { RmibItemsLoaderState } from './rmib-items-loader.ts';
import type { FetchRmibItems } from './rmib-items.ts';

/**
 * Thin React wrapper around rmib-items-loader.ts — see that module's doc
 * for the retry design and for why this is a fourth copy of the pattern
 * rather than a shared core. Mirrors papi/use-papi-items.ts and
 * kraepelin/use-items.ts.
 */

export type UseRmibItemsOptions = {
    fetchItems: FetchRmibItems;
    /** From `useOfflineQueue()` (or `SessionRunnerContext.connectivity`)
     * — the SAME connectivity signal the shell's offline banner uses.
     * Not a second connectivity detector. */
    queueRetry: (retry: () => void) => () => void;
};

export type UseRmibItemsResult = {
    state: RmibItemsLoaderState;
    /** Retries immediately, bypassing any queued reconnect wait — wire
     * this to a "Coba lagi" button while reconnecting. */
    retry: () => void;
};

export function useRmibItems(options: UseRmibItemsOptions): UseRmibItemsResult {
    // `options` captured once, at mount — same "captured once" contract
    // as use-autosave.ts's `send` (see that module's doc for why).
    const [loader] = useState(() => createRmibItemsLoader(options));
    const [state, setState] = useState<RmibItemsLoaderState>(() =>
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
