import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * `AssessmentSession`, mirrored from f2-assessment-session-contract.md
 * §Stable DTOs / API_CONTRACT.md's session section. Field names here are
 * camelCase; the transport layer (not this file) is responsible for
 * mapping the wire JSON's snake_case to this shape.
 */
export type AssessmentSessionState = {
    sessionId: string;
    testType: 'ist' | 'papi' | 'rmib' | 'kraepelin';
    status:
        'created' | 'in_progress' | 'submitted' | 'scored' | 'expired' | 'void';
    attemptNo: number;
    startedAt: string | null;
    endsAt: string | null;
    writeDeadline: string | null;
    submittedAt: string | null;
    serverTime: string;
    remainingSeconds: number;
    answersRevision: number;
    config: unknown;
    seed: string | null;
};

export type FetchSession = () => Promise<AssessmentSessionState>;

export type UseAssessmentSessionOptions = {
    fetchSession: FetchSession;
    /** Lead's 2026-09-21 decision: 30 seconds. */
    pollIntervalMs?: number;
};

export type UseAssessmentSessionResult = {
    session: AssessmentSessionState | null;
    /** The number shown to the participant. Ticks down locally once per
     * second between real responses purely for visual smoothness — CLAUDE.md
     * forbids frontend timer authority, so this is never used to decide
     * anything, only to display something less static than a number that
     * jumps once every 30 seconds. Every real server response (poll,
     * resume, or an autosave outcome the caller reports via
     * `applyServerState`) unconditionally overwrites it. */
    displayRemainingSeconds: number | null;
    loadError: unknown;
    /** Re-fetches GET /sessions/:id immediately (e.g. after `needsReload`
     * from the autosave engine, or after an autosave outcome the caller
     * wants reflected in the timer — see module doc). */
    reload: () => Promise<AssessmentSessionState>;
};

export function useAssessmentSession({
    fetchSession,
    pollIntervalMs = 30_000,
}: UseAssessmentSessionOptions): UseAssessmentSessionResult {
    // Synced via an effect, not assigned during render — the project's
    // react-hooks/refs rule (React Compiler) forbids ref writes in the
    // render body; `reload`'s closure below reads this only when called,
    // never during render.
    const fetchSessionRef = useRef(fetchSession);
    useEffect(() => {
        fetchSessionRef.current = fetchSession;
    }, [fetchSession]);

    const [session, setSession] = useState<AssessmentSessionState | null>(null);
    const [displayRemainingSeconds, setDisplayRemainingSeconds] = useState<
        number | null
    >(null);
    const [loadError, setLoadError] = useState<unknown>(null);

    const reload = useCallback(async (): Promise<AssessmentSessionState> => {
        try {
            const next = await fetchSessionRef.current();
            setSession(next);
            setDisplayRemainingSeconds(Math.max(0, next.remainingSeconds));
            setLoadError(null);

            return next;
        } catch (error) {
            setLoadError(error);

            throw error;
        }
    }, []);

    // Initial load and the 30s poll.
    useEffect(() => {
        void reload();
        const interval = setInterval(() => void reload(), pollIntervalMs);

        return () => clearInterval(interval);
    }, [reload, pollIntervalMs]);

    // Resume-on-return: a mobile browser stops JS timers in the
    // background (per CLAUDE.md's camera-stream note, the same applies to
    // interval timers), so returning to the tab/window is treated as its
    // own trigger rather than waiting for the next 30s tick, which could
    // be arbitrarily stale after a long backgrounding.
    useEffect(() => {
        function onVisible(): void {
            if (document.visibilityState === 'visible') {
                void reload();
            }
        }
        function onFocus(): void {
            void reload();
        }

        document.addEventListener('visibilitychange', onVisible);
        window.addEventListener('focus', onFocus);

        return () => {
            document.removeEventListener('visibilitychange', onVisible);
            window.removeEventListener('focus', onFocus);
        };
    }, [reload]);

    // Cosmetic local ticking, only while a deadline is actually running.
    useEffect(() => {
        if (session?.status !== 'in_progress') {
            return;
        }

        const tick = setInterval(() => {
            setDisplayRemainingSeconds((current) =>
                current !== null && current > 0 ? current - 1 : 0,
            );
        }, 1_000);

        return () => clearInterval(tick);
    }, [session?.status]);

    return { session, displayRemainingSeconds, loadError, reload };
}
