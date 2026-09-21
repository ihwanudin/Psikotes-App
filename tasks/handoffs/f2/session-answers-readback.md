# F2 session-answers-readback — GET /sessions/{id}/answers (2026-09-21)

Scope: a new, separate read endpoint so a participant who refreshes mid-test
sees their own already-autosaved answers rehydrated, instead of every field
blank (found by GLM while designing the runner; PR #49's `GET /sessions/{id}`
carries `answers_revision` but not the answer values themselves). Depends on
PR #49 (`GetAssessmentSession`, the sealed-controller pattern, the
`participant.jwt`-without-`rls` route group) -- this branch was rebased onto
`origin/main` after #49 merged (`8fb4fa0`).

## Why a separate endpoint, not a field on `GET /sessions/{id}`

`AssessmentSessionResource` (from #49) is already shared by two different
producers: the start/replay command and the resume read. Both would have to
agree on whether/how to carry answers if the field lived there, widening the
change surface of an already-settled, already-tested resource for a concern
(full answer payload) that a lightweight status poll doesn't need. A separate
`GET /sessions/{id}/answers` keeps that resource untouched and lets a client
fetch answers only when actually entering the question UI. Approved by Lead,
2026-09-21.

## Ownership and the byte-identical 404 -- same mechanism as #49

`GetAssessmentSessionAnswers` uses the identical `WHERE public_id = ? AND
participant_id = ?` shape as every other session action in this lane: a
nonexistent session and a foreign-owned session are structurally
indistinguishable, not a behaviour to remember. Proven by
`test_readback_nonexistent_and_foreign_session_are_byte_identical_404`
(status + body + headers, `Date` excluded), same pattern as #49's three
endpoints.

## Readable exactly when writable

Per Lead's sign-off: answers are readable **only** while `status ===
in_progress` **and** the server clock has not passed `ends_at`. No new error
codes -- this reuses the exact same three codes autosave itself uses for the
matching write-side rejection:

- `created` -> `SESSION_NOT_STARTED` (nothing autosaved yet, structurally).
- `submitted` / `scored` / `expired` / `void` -> `SESSION_CLOSED`.
- `in_progress` in storage but the server clock is past `ends_at` (not yet
  swept to `expired` by a write) -> `DEADLINE_EXCEEDED`, not `SESSION_CLOSED`.
  This is a deliberate choice, not the more generic code: it mirrors
  `AssessmentSessionDeadlinePolicy::evaluateAnswerWrite()`'s own split
  exactly -- `SESSION_CLOSED` there means the *stored* status already isn't
  `in_progress`; `DEADLINE_EXCEEDED` means the stored status still says
  `in_progress` but time has actually run out. Reusing that same split down
  to the error code keeps "readable exactly when writable" true at the
  detail level, not just as a general rule.

Whether a participant should be able to review answers *after* submitting is
a psychometric-validity question for the psychologist/project owner, not an
engineering one -- explicitly out of scope here, noted as an open question
rather than decided unilaterally. The concrete problem this endpoint solves
(resume-refresh mid-test) doesn't need it.

## The read never writes

`GetAssessmentSessionAnswers` performs no `UPDATE`/`INSERT` anywhere --
unlike the write actions, it never seals an overdue `in_progress` session to
`expired` itself. That stays `AutosaveAssessmentAnswers`/
`SubmitAssessmentSession`'s job. A read endpoint with a side effect would
complicate reasoning about races for no benefit here (Lead's explicit
instruction). Proven by
`test_readback_exact_deadline_is_readable_and_one_tick_after_is_deadline_exceeded_without_side_effects`:
asserts `test_sessions.status`/`expired_at` are byte-identical before and
after the rejected read, and the `answers` row count is unchanged.

## Revision/answers consistency -- one statement, not two

`GetAssessmentSessionAnswers::load()` reads `test_sessions.answers_revision`
and every `answers` row in a single `LEFT JOIN` statement, not two separate
`SELECT`s. PostgreSQL READ COMMITTED gives a fresh snapshot per **statement**,
not per transaction, so two separate SELECTs could let a concurrent
autosave's commit land in between them -- the reported `answers_revision`
would not match the answers actually returned (a torn read). A single
statement is guaranteed one consistent snapshot for its whole execution, so
this can't happen by construction.

This can only be demonstrated against real PostgreSQL, not SQLite (SQLite's
single-writer model can't reproduce the interleaving). Proven by
`tests/Postgres/AssessmentSessionAnswersReadbackControllerTest.php::test_a_read_during_an_uncommitted_concurrent_autosave_never_returns_a_torn_revision_and_answers_pair`,
same fork+socket-barrier convention as
`AssessmentSessionHttpControllerTest.php`'s race test: a worker holds
`test_sessions` locked `FOR UPDATE` inside an uncommitted autosave
transaction; a concurrent read proceeds immediately (a plain `SELECT` never
blocks on a row lock in PostgreSQL) and must see a self-consistent pre-commit
snapshot (`revision 0`, no answers) -- never `revision 1` with no answers or
`revision 0` with an answer row already visible. After the worker commits, a
second read must see the equally consistent post-commit snapshot (`revision
1`, one answer). 3 tests, 23 assertions, green on the first run.

## Response: strict whitelist

```json
{"session_id": "...", "answers_revision": 7, "answers": [{"item_no": 1, "value": "A"}]}
```

Only `item_no` + `value` per row (read verbatim from `answers.value`, no
transformation) plus `answers_revision`. No `status`, answer keys, scores, or
per-item correctness -- not because the controller is careful to filter them
out, but because the `answers` table has no such columns at all
(`session_id, item_no, value, revision, answered_at`). There is nothing to
leak structurally. Proven by
`test_readback_response_is_a_strict_whitelist` (asserts the exact top-level
and per-item key sets).

## DASS-21 exclusion

Reuses the exact mechanism already proven in #49:
`GenericAssessmentInstrument::fromExternal()` throws
`UnsupportedGenericAssessmentInstrument` for `'dass21'`, mapped to the same
`SESSION_NOT_FOUND` as a nonexistent session. Proven by
`test_readback_never_exposes_a_dass21_row_planted_in_the_generic_table`,
mirroring `AutosaveAssessmentAnswersTest.php`'s equivalent corrupt-row test.

## Test coverage

- `tests/Architecture/AssessmentSessionHttpBoundaryTest.php` -- extended with
  a fourth data-provider case (route/rls exclusion, no forbidden dependency,
  no DB facade/`runAsService` reference in source), same proof as the other
  three session-http controllers.
- `tests/Feature/AssessmentSessions/AssessmentSessionAnswersReadbackTest.php`
  -- 11 SQLite tests: happy path with correct item ordering, empty-list for
  no autosaves yet, all four non-readable states mapped to the right code,
  the exact-deadline boundary with the no-side-effects proof, byte-identical
  404, the DASS-21 exclusion, and the response whitelist. Fixtures build
  answer state through the real `AutosaveAssessmentAnswers` action rather
  than direct table writes -- `test_sessions_identity_revision_guard`
  requires every `answers_revision` bump to be backed by a matching
  `assessment_autosave_mutations` ledger row, and a hand-crafted revision
  bump trips that trigger. (Hit this directly: the first draft wrote
  `answers_revision` straight into the fixture and got `SQLSTATE[23000]
  test session identity or revision contract violation` on SQLite;
  fixed by using the real action to build fixtures, same as every other
  session-http test file already does.)
- `tests/Postgres/AssessmentSessionAnswersReadbackControllerTest.php` -- 3
  tests, 23 assertions: happy path and foreign-session-404 through the real
  controller under the `psikotes_runtime` non-owner/`NOBYPASSRLS` role, plus
  the torn-read race test above.
- Full SQLite sweep (`tests/Feature/AssessmentSessions`,
  `tests/Unit/AssessmentSessions`, `tests/Architecture`): 341 tests, 2428
  assertions, green. Full `phpstan` gate (level 7): 0 errors. `pint --test`:
  clean.
