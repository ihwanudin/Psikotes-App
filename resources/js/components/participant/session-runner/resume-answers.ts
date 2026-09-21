/**
 * `GET /sessions/:id/answers` (tasks/handoffs/f2/session-answers-readback.md,
 * merged PR #52) — rehydrates a participant's already-autosaved answers on
 * resume/refresh, and gives the caller the `answers_revision` needed to
 * construct `useAutosave`'s `initialRevision` so the next autosave doesn't
 * trip `AUTOSAVE_REVISION_GAP` (API_CONTRACT.md: a new revision must be
 * exactly `current_revision + 1`).
 *
 * Transport (HTTP call, snake_case mapping, auth headers) is the caller's
 * job, same as `FetchSession`/`AutosaveSend` — this file only defines the
 * shape and interprets the outcome, no fetch/DOM.
 *
 * The endpoint is readable "exactly when writable": `created` ->
 * `not_started` (nothing autosaved yet, structurally — revision 0 is
 * correct, same as never having called this endpoint at all);
 * `submitted`/`scored`/`expired`/`void` -> `closed`; `in_progress` in
 * storage but past `ends_at` -> `deadline_exceeded`; nonexistent or
 * foreign-owned session -> `not_found` (byte-identical, not
 * distinguishable by design). None of those four carry a meaningful
 * revision — a page hitting one of them has no business rendering an
 * editable answer UI at all, so `initialAutosaveRevisionFromResume`
 * returns `null` for all of them rather than guessing a number.
 */

export type ResumedAnswer = { itemNo: number; value: unknown };

export type ResumeAnswersOutcome =
    | {
          type: 'available';
          sessionId: string;
          answersRevision: number;
          answers: ResumedAnswer[];
      }
    | { type: 'not_started' }
    | { type: 'closed' }
    | { type: 'deadline_exceeded' }
    | { type: 'not_found' }
    | { type: 'network_error' };

export type FetchResumeAnswers = () => Promise<ResumeAnswersOutcome>;

/**
 * The revision `useAutosave({ initialRevision, ... })` should be
 * constructed with, derived from a resume-answers outcome. `null` means
 * there is no valid revision to autosave against — the caller should show
 * a non-editable state instead of mounting the autosave-backed UI.
 */
export function initialAutosaveRevisionFromResume(
    outcome: ResumeAnswersOutcome,
): number | null {
    switch (outcome.type) {
        case 'available':
            return outcome.answersRevision;
        case 'not_started':
            return 0;
        case 'closed':
        case 'deadline_exceeded':
        case 'not_found':
        case 'network_error':
            return null;
    }
}
