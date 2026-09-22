import { useCallback, useEffect, useState } from 'react';

import { createItemsLoader } from './items-loader.ts';
import type { ItemsLoaderState } from './items-loader.ts';
import type { FetchAssessmentSessionItems } from './items.ts';

/**
 * Thin React wrapper around items-loader.ts — see that module's doc for
 * the retry design (network_error/rejection retryable, everything else
 * final). Mirrors session-runner/use-resume-answers.ts.
 */

export type UseItemsOptions = {
    fetchItems: FetchAssessmentSessionItems;
    /** From `useOfflineQueue()` (or `SessionRunnerContext.connectivity`)
     * — the SAME connectivity signal the shell's offline banner uses.
     * Not a second connectivity detector. */
    queueRetry: (retry: () => void) => () => void;
};

export type UseItemsResult = {
    state: ItemsLoaderState;
    /** Retries immediately, bypassing any queued reconnect wait — wire
     * this to a "Coba lagi" button while reconnecting. */
    retry: () => void;
};

export function useItems(options: UseItemsOptions): UseItemsResult {
    // `options` captured once, at mount — same "captured once" contract
    // as use-autosave.ts's `send` (see that module's doc for why).
    const [loader] = useState(() => createItemsLoader(options));
    const [state, setState] = useState<ItemsLoaderState>(() =>
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
