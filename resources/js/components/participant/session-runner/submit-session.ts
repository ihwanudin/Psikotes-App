import type { AssessmentSessionState } from './use-assessment-session.ts';

/**
 * `POST /sessions/:id/submit` — pure types + the double-submit/dropped-
 * response recovery orchestration, no React, no fetch, testable via
 * `node --test`. Mirrors the pure-logic/thin-transport split every other
 * session-runner module uses.
 *
 * Error-code mapping (`SubmitAssessmentSessionController.php`, verified
 * by reading it and `SubmitAssessmentSession.php`, not guessed):
 * SESSION_NOT_FOUND -> 404 -> `not_found`; SESSION_NOT_STARTED,
 * SESSION_CLOSED, DEADLINE_EXCEEDED -> 409 -> `not_started`/`closed`/
 * `deadline_exceeded`.
 */
export type SubmitOutcome =
    | {
          type: 'accepted';
          sessionId: string;
          status: 'submitted' | 'scored';
          submittedAt: string | null;
          answersRevision: number;
      }
    | { type: 'not_found' }
    | { type: 'not_started' }
    | { type: 'closed' }
    | { type: 'deadline_exceeded' }
    | { type: 'network_error' };

export type SubmitSend = () => Promise<SubmitOutcome>;

export type SubmitResult =
    | {
          status: 'accepted';
          sessionId: string;
          sessionStatus: 'submitted' | 'scored';
          submittedAt: string | null;
          answersRevision: number;
          /** True when this result came from verifying session state
           * after an ambiguous network failure, not from the submit
           * call's own response — see submitWithVerification's doc. */
          verifiedViaSession: boolean;
      }
    | { status: 'not_found' }
    | { status: 'not_started' }
    | { status: 'closed' }
    | { status: 'deadline_exceeded' }
    | { status: 'network_error' };

/** `GET /sessions/:id`, reused as-is — same contract `useAssessmentSession`
 * already uses (throws on failure, resolves with the mapped session on
 * success). */
export type VerifySubmitBySessionState = () => Promise<AssessmentSessionState>;

/**
 * Submits, and — ONLY when the direct attempt comes back ambiguous
 * (`network_error`, meaning "no definitive response was received", not
 * "the server rejected it") — verifies via `GET /sessions/:id` before
 * telling the caller submit failed.
 *
 * Why this exists (Lead's 2026-09-21 instruction, verified against
 * `SubmitAssessmentSession.php` before implementing, not assumed): the
 * submit action's own policy already replays safely when the session is
 * already `Submitted`/`Scored` (`AssessmentSessionSubmitPolicy::decide()`
 * — a second `POST /submit` against an already-submitted session returns
 * an ACCEPTED replay, not a rejection). So a participant whose connection
 * drops right as they submit — where the server actually committed the
 * transition but the response never made it back — has a session that
 * genuinely IS submitted, even though their client only saw a network
 * failure. This function's ambiguous-outcome branch confirms that via a
 * plain read (safe to repeat, no side effects) instead of ever showing
 * "gagal" for a test that already went through.
 *
 * A `network_error` outcome from `send()` itself (as opposed to a
 * rejected/thrown `send()`, which is caught and treated identically —
 * same "both shapes mean the same thing" convention every other
 * session-runner module uses) is handled the same way.
 *
 * If `verify()` shows the session is genuinely NOT submitted (still
 * `in_progress`, or anything other than `submitted`/`scored`/`expired`),
 * the original submit attempt really did fail — returned as
 * `network_error`, safe for the caller to offer a retry. If it shows
 * `expired`, the deadline passed while the outcome was unknown; reported
 * as `deadline_exceeded` (Lead's instruction: show the server's status
 * as-is, never a client-side countdown or guess) rather than a
 * misleading "try again". If `verify()` itself fails, the ambiguity is
 * unresolved and this also resolves as `network_error` — never invents
 * a definitive answer it doesn't have.
 */
export async function submitWithVerification(
    send: SubmitSend,
    verify: VerifySubmitBySessionState,
): Promise<SubmitResult> {
    let outcome: SubmitOutcome;

    try {
        outcome = await send();
    } catch {
        outcome = { type: 'network_error' };
    }

    if (outcome.type !== 'network_error') {
        return submitOutcomeToResult(outcome);
    }

    let session: AssessmentSessionState;

    try {
        session = await verify();
    } catch {
        return { status: 'network_error' };
    }

    if (session.status === 'submitted' || session.status === 'scored') {
        return {
            status: 'accepted',
            sessionId: session.sessionId,
            sessionStatus: session.status,
            submittedAt: session.submittedAt,
            answersRevision: session.answersRevision,
            verifiedViaSession: true,
        };
    }

    if (session.status === 'expired') {
        return { status: 'deadline_exceeded' };
    }

    return { status: 'network_error' };
}

function submitOutcomeToResult(
    outcome: Exclude<SubmitOutcome, { type: 'network_error' }>,
): SubmitResult {
    if (outcome.type === 'accepted') {
        return {
            status: 'accepted',
            sessionId: outcome.sessionId,
            sessionStatus: outcome.status,
            submittedAt: outcome.submittedAt,
            answersRevision: outcome.answersRevision,
            verifiedViaSession: false,
        };
    }

    return { status: outcome.type };
}
