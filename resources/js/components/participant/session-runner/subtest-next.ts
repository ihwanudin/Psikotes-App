/**
 * `POST /sessions/:id/subtest/next` — pure types, no React, no fetch,
 * testable via `node --test`. Mirrors the pure-logic/thin-transport split
 * every other session-runner module uses (see submit-session.ts).
 *
 * Error-code mapping (`SubtestNextController.php`, verified by reading it,
 * not guessed): SESSION_NOT_FOUND -> 404 -> `not_found`;
 * SESSION_NOT_STARTED/SESSION_CLOSED/DEADLINE_EXCEEDED -> 409 -> their own
 * variants; INVALID_SESSION_TRANSITION -> 422 -> `invalid_transition` — the
 * EXPECTED outcome of calling this while the current segment is already
 * mid-window and `allow_early_finish` is false (every real segment today —
 * see `TimedSegmentTransitionPolicy`'s doc), not an error state to hide.
 *
 * Unlike submit, this has no "verify on ambiguous network_error" wrapper:
 * `allow_early_finish=false` (everything real today) already makes a
 * repeated call safe by construction (`SubtestNext.php`'s own doc — the
 * row lock serializes a retry behind the first call, whose fresh sweep
 * correctly rejects the second as INVALID_SESSION_TRANSITION rather than
 * repeating the effect). A caller that gets `network_error` here can just
 * retry the call itself; there is nothing this module needs to reconcile
 * against `GET /sessions/:id` first.
 */
export type SubtestNextOutcome =
    | {
          type: 'accepted';
          segmentIndex: number;
          becameCurrentAt: string;
          startedAt: string | null;
      }
    | { type: 'not_found' }
    | { type: 'not_started' }
    | { type: 'closed' }
    | { type: 'deadline_exceeded' }
    | { type: 'invalid_transition' }
    | { type: 'network_error' };

export type SubtestNextSend = () => Promise<SubtestNextOutcome>;
