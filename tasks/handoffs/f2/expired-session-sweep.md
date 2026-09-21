# F2 — expired-session sweep command (2026-09-21)

Scope: Lead's second task after the RMIB reader, independent of ADR-0032's
open Titik A/B. Finding from the expired-session-scoring investigation
stands regardless of any scoring decision: an abandoned `in_progress`
session (no further participant request ever arrives) stays
`in_progress` in the database **forever** — not just "unscored". The
status transition itself is currently lazy, discovered only on the
participant's own next autosave/submit request, which never comes if
they simply walk away.

## What shipped

- **`SealExpiredAssessmentSession`** (new) — the ONE place that
  transitions an overdue `in_progress` session to `expired`. Extracted
  from `AutosaveAssessmentAnswers` and `SubmitAssessmentSession`, which
  carried byte-identical copies of this block. Two entry points:
  `sealWithinTransaction()` for callers that already own a service
  transaction (autosave/submit), `seal()` for callers that don't (the
  sweep, once per candidate). Implements a small new interface,
  `App\Contracts\SealsExpiredAssessmentSessions`, so a test can inject a
  genuine per-session failure without needing true concurrency to
  reproduce it (see test coverage below).
- **`SweepExpiredAssessmentSessions`** (new) — finds `in_progress`
  sessions with `ends_at` already passed (`ends_at < cutoff`, `cutoff`
  computed from an injectable clock, UTC-normalized), seals each **in its
  own service-context transaction**, catches and reports (`report()`) any
  per-session failure, and continues to the next candidate. Never scores
  anything.
- **`sessions:sweep-expired {--limit=200}`** console command, scheduled
  `everyMinute()->withoutOverlapping()->onOneServer()` in
  `routes/console.php`, matching the 5 existing scheduled commands'
  conventions exactly.
- **Documented empty hook** (`SealExpiredAssessmentSession::hookForFutureOrchestrator()`):
  ADR-0032's Titik A (whether `expired` sessions may ever be scored) is
  not decided. If it's answered "yes", the hook point already exists,
  already runs inside the SAME transaction as the seal itself (per
  ADR-0032 §1's atomicity requirement) — do not wire a real call there
  without Titik A/B being answered first.

## Bug found and fixed while wiring the extraction: timestamp normalization

The original inline blocks in `AutosaveAssessmentAnswers`/
`SubmitAssessmentSession` format the clock's `DateTimeImmutable` **as-is**
for `expired_at`/`updated_at` — no UTC conversion. My first draft of
`SealExpiredAssessmentSession::timestamp()` added a `setTimezone(UTC)`
call "for consistency." That broke `AutosaveAssessmentAnswersTest`'s
`+07:00`-fixture test: the SQLite `test_sessions_contract_update`
trigger's `expired_at > ends_at` check is a **lexicographic string
comparison**, not a real datetime comparison — it only holds if both
timestamps are written in the same offset. `ends_at` stored as
`...+07:00` compared against a UTC-normalized `expired_at` (`...+00:00`,
crossing a day boundary) failed the string comparison even though the two
instants were the same moment. Fixed by matching the original behavior
exactly (no normalization) rather than "correcting" it — the codebase's
existing convention here is deliberate, not an oversight, given both
original call sites already agreed on it independently.

## Per-row isolation, proven two ways

1. **SQLite, deterministic**: `SweepExpiredAssessmentSessionsTest`
   injects a genuine failure for exactly one candidate via a test double
   implementing `SealsExpiredAssessmentSessions` (not a DB-level race,
   which can't be reproduced synchronously in one process) and asserts
   the other two candidates still get sealed.
2. **PostgreSQL, real concurrency**: `SweepExpiredAssessmentSessionsConcurrencyTest`
   forks two real workers targeting the SAME session — one running the
   participant's own path (`AutosaveAssessmentAnswers`, discovering
   expiry incidentally), one running exactly what the sweep calls
   (`SealExpiredAssessmentSession::seal()`). PostgreSQL's row lock on
   `test_sessions` (held by whichever worker's transaction gets there
   first) serializes them regardless of interleaving. Distinguishing "this
   worker performed the seal" from "this worker merely observed an
   already-sealed row" required reading the autosave path's own
   `DEADLINE_EXCEEDED` vs `SESSION_CLOSED` error code -- the sweep-seal
   path's bare conditional `UPDATE` always throws cleanly when it loses,
   but the autosave path's *own* `lockForUpdate()` read only reaches the
   seal call when it wins; when it loses, it takes the ordinary
   session-closed rejection path with no exception at all. Asserts
   exactly one worker performed the seal, the other failed cleanly, and
   the final row is self-consistent (`status='expired'`, exactly one
   `expired_at`). Ran 3 times manually to check for flakiness; stable.

## Test coverage

- `SweepExpiredAssessmentSessionsTest.php` (SQLite, 5 tests): only overdue
  `in_progress` sessions are swept (every other status/timing left
  untouched), the exact `ends_at` boundary (not swept exactly at, swept
  one tick after), one session's failure doesn't stop the others,
  `--limit` caps candidates per run, a non-positive limit is rejected.
- `SweepExpiredAssessmentSessionsConcurrencyTest.php` (PostgreSQL, 1 test,
  11 assertions): the race above.
- Existing `AutosaveAssessmentAnswersTest`/`SubmitAssessmentSessionTest`/
  `AssessmentSessionHttpTest`/`AssessmentSessionAnswersReadbackTest` (and
  their Postgres equivalents) updated for the new constructor dependency
  — all still green, proving the extraction is behavior-preserving.

## Test suite status

- SQLite: `tests/Feature/AssessmentSessions`, `tests/Unit/AssessmentSessions`,
  `tests/Architecture` — green (exact counts in PR description).
- PostgreSQL: `SweepExpiredAssessmentSessionsConcurrencyTest` (1/1, 11
  assertions, stable across 3 manual runs), plus the 13 previously-existing
  autosave/submit/http/answers-readback Postgres tests, all still green
  after the sealer extraction.
- Full `phpstan` gate: 0 errors. `pint --test` (full repo): clean.

## CI note

GitHub Actions still down repo-wide (billing issue, unrelated, project
owner notified). Local results stand in until it recovers; merge held
until then.

## Deferred, still open

Whether/how an `expired` session ever gets scored is ADR-0032's Titik A,
not decided. This PR only makes the STATUS transition honest; it adds no
scoring behavior at all.
