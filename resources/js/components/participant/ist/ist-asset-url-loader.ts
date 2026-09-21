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
 *
 * `reportImageLoadFailure()` (Lead's 2026-09-21 follow-up, after fixing
 * the error state's "Coba lagi" touch target surfaced the question of
 * what happens when the browser can never actually load an otherwise-
 * "available" URL — a genuinely broken/missing storage object, or a phone
 * network that keeps dropping the image download): the `<img>`'s onError
 * previously called `reload()` directly, with nothing counting how many
 * times that had already happened for this assetId. If `fetchAssetUrl`
 * kept returning `available` for a URL the browser can never load, that
 * was an unbounded loading/ready/onError cycle — never surfacing the
 * retry button, and a participant on a phone would just see a broken
 * image forever with no way to unblock an item that image made
 * unanswerable. `reportImageLoadFailure()` is a SEPARATE signal from
 * `reload()`, wired to the `<img>`'s onError instead: it counts
 * consecutive calls, and once `MAX_CONSECUTIVE_IMAGE_LOAD_FAILURES` is
 * reached, gives up and surfaces the same `error` state `reload()`'s own
 * caller-visible button already handles, rather than fetching again.
 * `reload()` (the manual "Coba lagi" click) always resets that count —
 * the participant asked for a fresh start, not one more spin of a loop
 * that was already failing.
 */

const MAX_CONSECUTIVE_IMAGE_LOAD_FAILURES = 2;

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
    /** The participant's own "Coba lagi" click — re-fetches a fresh URL
     * for the same asset AND resets the `reportImageLoadFailure()` count.
     * Safe to call from any state. */
    reload: () => void;
    /** Wire to the <img>'s onError — a DIFFERENT signal from `reload()`.
     * Counts consecutive calls (not reset by a successful re-fetch, only
     * by `reload()`); once `MAX_CONSECUTIVE_IMAGE_LOAD_FAILURES` is
     * reached, surfaces the `error` state instead of fetching again, so a
     * URL the browser can never actually load can't cycle forever. */
    reportImageLoadFailure: () => void;
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
    let consecutiveImageLoadFailures = 0;

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
            // handler (the explicit "Coba lagi" click), never from inside
            // an effect body, so resetting to 'loading' synchronously
            // here is fine. A fresh manual attempt earns a fresh budget.
            consecutiveImageLoadFailures = 0;
            setState({ status: 'loading' });
            void attempt();
        },
        reportImageLoadFailure() {
            consecutiveImageLoadFailures++;

            if (
                consecutiveImageLoadFailures >=
                MAX_CONSECUTIVE_IMAGE_LOAD_FAILURES
            ) {
                setState({
                    status: 'error',
                    outcome: { type: 'network_error' },
                });

                return;
            }

            // Also invoked from an event handler (the <img>'s onError),
            // same as reload() above — safe to setState synchronously.
            setState({ status: 'loading' });
            void attempt();
        },
        dispose() {
            disposed = true;
        },
    };
}
