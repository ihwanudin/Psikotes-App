import { useCallback, useEffect, useState } from 'react';

import { createResumeAnswersLoader } from './resume-answers-loader.ts';
import type { ResumeAnswersLoaderState } from './resume-answers-loader.ts';
import type { FetchResumeAnswers } from './resume-answers.ts';

/**
 * Thin React wrapper around resume-answers-loader.ts — see that module's
 * doc for the retry design (network_error is retryable, everything else
 * is final).
 */

export type UseResumeAnswersOptions = {
    fetchResumeAnswers: FetchResumeAnswers;
    /** From `useOfflineQueue()` (or `SessionRunnerContext.connectivity`)
     * — the SAME connectivity signal the shell's offline banner uses.
     * Not a second connectivity detector. */
    queueRetry: (retry: () => void) => () => void;
};

export type UseResumeAnswersResult = {
    state: ResumeAnswersLoaderState;
    /** Retries immediately, bypassing any queued reconnect wait — wire
     * this to a "Coba lagi" button while `state.status === 'reconnecting'`
     * or `'error'`. */
    retry: () => void;
};

export function useResumeAnswers(
    options: UseResumeAnswersOptions,
): UseResumeAnswersResult {
    // `options` is captured once, at mount — same "captured once"
    // contract as use-autosave.ts's `send` (see that module's doc for
    // why): the project's react-hooks/refs rule (React Compiler) forbids
    // feeding anything ref-derived into a function call that runs during
    // render, and in practice both `fetchResumeAnswers` and `queueRetry`
    // close over values that don't change during one session (session
    // id/token, and the offline queue's own stable `queueRetry`).
    const [loader] = useState(() => createResumeAnswersLoader(options));
    const [state, setState] = useState<ResumeAnswersLoaderState>(() =>
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
