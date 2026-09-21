import { useEffect, useState } from 'react';

import { createIstAssetUrlLoader } from './ist-asset-url-loader.ts';
import type { IstAssetUrlLoaderState } from './ist-asset-url-loader.ts';
import type { FetchIstAssetUrl } from './ist-asset-url.ts';

/**
 * Thin React wrapper around `ist-asset-url-loader.ts` — same shape as
 * `use-resume-answers.ts`/`use-papi-items.ts`: `loader` is captured once
 * via `useState(() => ...)`, and the only state transitions after mount
 * flow through `loader.subscribe(setState)`, never a synchronous
 * `setState` call inside this effect's own body (keeping this hook clear
 * of `react-hooks/set-state-in-effect`).
 *
 * `assetId` is treated as stable for this hook's whole mounted lifetime,
 * the same "captured once" contract every other injected-options hook in
 * this codebase already documents. `IstAssetImage.tsx` is what actually
 * handles a genuinely DIFFERENT asset being shown: it remounts this
 * hook's owner with `key={assetId}` rather than asking this hook to
 * detect the change itself — the standard React way to reset
 * per-instance state on a changing identity, and it sidesteps needing
 * any effect-driven reset at all.
 */

export type UseIstAssetUrlOptions = {
    assetId: string;
    fetchAssetUrl: FetchIstAssetUrl;
};

export type UseIstAssetUrlResult = {
    state: IstAssetUrlLoaderState;
    /** Fetches a fresh URL for the same assetId — wire this to the
     * <img>'s onError. */
    reload: () => void;
};

export function useIstAssetUrl({
    assetId,
    fetchAssetUrl,
}: UseIstAssetUrlOptions): UseIstAssetUrlResult {
    const [loader] = useState(() =>
        createIstAssetUrlLoader({ assetId, fetchAssetUrl }),
    );
    const [state, setState] = useState<IstAssetUrlLoaderState>(() =>
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

    return {
        state,
        reload: () => loader.reload(),
    };
}
