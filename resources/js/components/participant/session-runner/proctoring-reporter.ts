/**
 * Proctoring event reporter interface + a no-op stub, mirroring the
 * pattern already used in the F6 lane for an unimplemented input
 * (UnavailableReportSupplementalData): the backend ingest endpoint for
 * proctoring events does not exist yet (tasks/handoffs/f7/proctoring-
 * persistence-proposal.md is explicitly "PROPOSAL ONLY — no migration,
 * code, route, controller, test authorized in this increment"), so
 * useProctoringCamera is built against this interface now and a real
 * implementation can be dropped in once F7's backend lands, without
 * touching the hook.
 *
 * The stub is honest about doing nothing: it does not throw, does not
 * queue, does not pretend to persist. Callers must not render UI copy
 * implying events are recorded or sent while this stub is in use — see
 * the "aktif" vs "tercatat" distinction in session-runner-shell.tsx.
 */

export type ProctoringEventKind =
    | 'camera_permission_denied'
    | 'camera_unavailable'
    | 'camera_interrupted'
    | 'camera_reactivation_attempted'
    | 'camera_reactivation_succeeded'
    | 'camera_reactivation_failed';

export type ProctoringEvent = {
    kind: ProctoringEventKind;
    occurredAt: string;
};

export type ProctoringReporter = {
    report: (event: ProctoringEvent) => void;
};

export const noopProctoringReporter: ProctoringReporter = {
    report: () => {
        // Intentionally does nothing. See module doc: no backend exists
        // yet to send this to, and this must never silently pretend
        // otherwise (e.g. by queuing events that will never be flushed).
    },
};
