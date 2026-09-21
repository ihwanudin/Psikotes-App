import { isIstAssetUrlExpired } from './ist-asset-url.ts';
import type { FetchIstAssetUrl, IstAssetUrlOutcome } from './ist-asset-url.ts';

/**
 * Pure fetch-once-then-reload state machine for one asset's signed URL —
 * no React, no DOM, testable via `node --test`. Split out the same way
 * every other loader in this codebase is (`resume-answers-loader.ts`
 * etc.): the initial `loading` state lives in this module's own closure,
 * set synchronously at creation, never via a React `setState` call inside
 * an effect body — `use-ist-asset-url.ts`'s hook only subscribes to this
 * loader and calls `start()`, which is what keeps it clear of the
 * `react-hooks/set-state-in-effect` rule the rest of this codebase's
 * hooks already respect.
 *
 * Deliberately NOT the full `retry-loader.ts` machinery (no automatic
 * background retry-on-connectivity, no 5-attempt cap): Lead's
 * 2026-09-21 instruction was narrower than that — reload only on the
 * `<img>`'s own `onError`, or once proactively if a just-fetched URL is
 * already past its own `expiresAt` (a slow/queued response) — both are
 * explicit, bounded, caller/image-driven triggers, not a background
 * reconnect loop.
 */

export type IstAssetUrlLoaderState =
    | { status: 'loading' }
    | { status: 'ready'; url: string }
    | {
          status: 'error';
          outcome: Exclude<IstAssetUrlOutcome, { type: 'available' }>;
      };

export type IstAssetUrlLoaderOptions = {
    assetId: string;
    fetchAssetUrl: FetchIstAssetUrl;
};

export type IstAssetUrlLoader = {
    getState: () => IstAssetUrlLoaderState;
    subscribe: (
        listener: (state: IstAssetUrlLoaderState) => void,
    ) => () => void;
    /** Starts the first attempt. Call once. */
    start: () => void;
    /** Re-fetches a fresh URL for the same asset — wire to the <img>'s
     * onError. Safe to call from any state. */
    reload: () => void;
    /** Cancels any in-flight attempt so it can never update state again
     * — call on unmount or when the assetId this loader was created for
     * changes (create a new loader for the new assetId instead of
     * reusing this one). */
    dispose: () => void;
};

export function createIstAssetUrlLoader(
    options: IstAssetUrlLoaderOptions,
): IstAssetUrlLoader {
    let state: IstAssetUrlLoaderState = { status: 'loading' };
    const listeners = new Set<(state: IstAssetUrlLoaderState) => void>();
    let attemptId = 0;
    let disposed = false;

    function setState(next: IstAssetUrlLoaderState): void {
        state = next;

        for (const listener of listeners) {
            listener(state);
        }
    }

    async function fetchOnce(): Promise<IstAssetUrlOutcome> {
        try {
            return await options.fetchAssetUrl(options.assetId);
        } catch {
            return { type: 'network_error' };
        }
    }

    async function attempt(): Promise<void> {
        const thisAttemptId = ++attemptId;

        const first = await fetchOnce();

        if (disposed || attemptId !== thisAttemptId) {
            return;
        }

        if (first.type !== 'available') {
            setState({ status: 'error', outcome: first });

            return;
        }

        if (!isIstAssetUrlExpired(first.expiresAt, new Date())) {
            setState({ status: 'ready', url: first.url });

            return;
        }

        // Arrived already expired (a very slow/queued response) — one
        // bounded re-fetch, not a loop: if the second attempt is ALSO
        // already expired, surface it as an error rather than retrying
        // again.
        const second = await fetchOnce();

        if (disposed || attemptId !== thisAttemptId) {
            return;
        }

        if (
            second.type === 'available' &&
            !isIstAssetUrlExpired(second.expiresAt, new Date())
        ) {
            setState({ status: 'ready', url: second.url });

            return;
        }

        setState({
            status: 'error',
            outcome:
                second.type === 'available'
                    ? { type: 'network_error' }
                    : second,
        });
    }

    return {
        getState: () => state,
        subscribe(listener) {
            listeners.add(listener);

            return () => listeners.delete(listener);
        },
        start() {
            // No setState('loading') here: the initial `state` above is
            // already `{status:'loading'}` from this loader's own
            // creation, so start() (called from a useEffect body) never
            // needs to synchronously change state before its first
            // `await` — see this module's doc for why that distinction
            // matters (react-hooks/set-state-in-effect).
            void attempt();
        },
        reload() {
            // Unlike start(), reload() is always invoked from an event
            // handler (the <img>'s onError, or an explicit "Coba lagi"
            // click), never from inside an effect body, so resetting to
            // 'loading' synchronously here is fine.
            setState({ status: 'loading' });
            void attempt();
        },
        dispose() {
            disposed = true;
        },
    };
}
