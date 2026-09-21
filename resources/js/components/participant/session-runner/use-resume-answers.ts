import { useEffect, useRef, useState } from 'react';

import type {
    FetchResumeAnswers,
    ResumeAnswersOutcome,
} from './resume-answers.ts';

/**
 * Thin one-shot wrapper around `GET /sessions/:id/answers` (see
 * resume-answers.ts module doc). Fetches exactly once, on mount — unlike
 * `useAssessmentSession`, this isn't polled: the caller re-fetches by
 * remounting (e.g. after a `needsReload` recovery), not on a timer.
 */

export type UseResumeAnswersState =
    | { status: 'loading' }
    | { status: 'ready'; outcome: ResumeAnswersOutcome }
    | { status: 'error'; error: unknown };

export function useResumeAnswers(
    fetchResumeAnswers: FetchResumeAnswers,
): UseResumeAnswersState {
    // Synced via an effect, not assigned during render — same reason as
    // use-assessment-session.ts's fetchSessionRef: the project's
    // react-hooks/refs rule (React Compiler) forbids ref writes in the
    // render body.
    const fetchRef = useRef(fetchResumeAnswers);
    useEffect(() => {
        fetchRef.current = fetchResumeAnswers;
    }, [fetchResumeAnswers]);

    const [state, setState] = useState<UseResumeAnswersState>({
        status: 'loading',
    });

    useEffect(() => {
        let cancelled = false;

        fetchRef
            .current()
            .then((outcome) => {
                if (!cancelled) {
                    setState({ status: 'ready', outcome });
                }
            })
            .catch((error: unknown) => {
                if (!cancelled) {
                    setState({ status: 'error', error });
                }
            });

        return () => {
            cancelled = true;
        };
        // Runs once on mount by design — see module doc.
    }, []);

    return state;
}
