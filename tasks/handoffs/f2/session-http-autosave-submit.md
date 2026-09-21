# F2 session-http — resume, autosave, submit HTTP layer (2026-09-21)

Scope: `GET /sessions/{id}` (resume), `POST /sessions/{id}/answers` (autosave),
`POST /sessions/{id}/submit`, on top of the pre-existing
`AutosaveAssessmentAnswers`/`SubmitAssessmentSession` actions and a new
`GetAssessmentSession` action. Explicitly out of scope for this PR (later
increments): `/events` (Kraepelin batch), `/subtest/next` (IST, two-phase
180+360s ME timer), `/proctor`, and a new answer-readback capability found by
GLM mid-PR (see the dedicated section at the end).

## Why these routes exclude the `rls` middleware — the real mechanism

Every other participant route (`/me`, `/me/entitlements`, `/me/order`) uses
`['participant.jwt', 'rls']`. The three routes in this PR use
`participant.jwt` only, same as `/sessions/{testType}/start` from S5 — but for
a **different reason** than S5's, worth spelling out because the two look
identical from the route table alone.

S5's start command (`StartParticipantAssessmentSession`) calls
`assertCleanOuterBoundary()`, which explicitly asserts
`DB::transactionLevel() === 0` before touching SQL. None of the three actions
in this PR (`GetAssessmentSession`, `AutosaveAssessmentAnswers`,
`SubmitAssessmentSession`) has that assertion — confirmed by reading all
three end to end, not assumed.

The actual mechanism that still forces these routes outside `rls` is
`App\Security\RlsContextRunner::runAsService()` itself:

- If no RLS context is currently active, it establishes a fresh `service`
  context via `run()` and executes the callback inside it.
- If the current context's role is already `service`, it calls the callback
  directly (reentrant no-op).
- If the current role is one of `super_admin`, `branch_admin`, `staff`,
  `psychologist` (`RlsContextRunner::ADMIN_ROLES`), it temporarily elevates to
  `service` and restores the admin context afterward.
- If the current role is `participant` (not in `ADMIN_ROLES`), it throws
  `LogicException('Only administrator contexts may elevate to service.')`.

The `rls` middleware (`ApplyRlsContext`) establishes a `participant` RLS
context before the controller runs. If any of these three routes kept that
middleware, the action's own `runAsService()` call would immediately hit the
last branch above and throw — not a permissions problem, a hard
`LogicException` on every single request. So the route-placement conclusion
(`participant.jwt` only, no `rls`) ends up identical to S5's, but the
underlying reason is `runAsService()`'s reentrancy/elevation guard, not
`assertCleanOuterBoundary()`. `tests/Architecture/AssessmentSessionHttpBoundaryTest.php`
proves this statically for all three controllers, mirroring
`AssessmentSessionStartBoundaryTest.php`.

## Session ownership and the byte-identical 404 requirement

All three actions load the session with
`WHERE public_id = ? AND participant_id = ?` — a nonexistent session and a
session owned by a different participant are structurally indistinguishable:
same query shape, same empty result, same `SESSION_NOT_FOUND` outcome. This
isn't a behaviour to remember to preserve; it's the only outcome the query
shape can produce.

Per Lead's hard requirement (2026-09-21), each of the three HTTP test suites
includes one test that directly compares the two responses' status, raw body
bytes, and headers (minus `Date`, which the transport layer sets and which is
not under application control) rather than two independent 404-only
assertions:
`test_get_nonexistent_and_foreign_session_are_byte_identical_404`,
`test_autosave_nonexistent_and_foreign_session_are_byte_identical_404`,
`test_submit_nonexistent_and_foreign_session_are_byte_identical_404`
(`tests/Feature/AssessmentSessions/AssessmentSessionHttpTest.php`).

## Deadline: server/DB time, exact boundary

Both `AutosaveAssessmentAnswers` and `SubmitAssessmentSession` already had
exhaustive PostgreSQL evidence for the exact-`ends_at`-accepted /
one-microsecond-late-rejected-atomically boundary before this PR
(`test_exact_deadline_is_accepted_and_one_microsecond_late_is_expired`,
`test_exact_deadline_submits_and_one_microsecond_late_expires_under_service_rls`).
This PR adds a thin HTTP-level counterpart
(`test_autosave_exact_deadline_is_accepted_and_one_tick_after_expires`,
`test_submit_deadline_exceeded_is_409_and_expires_the_session`) proving the
controller layer doesn't accidentally substitute client time or otherwise
undermine that guarantee — it does not re-prove the underlying atomicity,
which the existing action-level PostgreSQL suite already covers.

## Error-code → HTTP status mapping (Correction A: no string matching, no 4xx for an invariant/bug)

Autosave (`AutosaveAssessmentAnswersController`):

| Code | HTTP |
|---|---|
| `SESSION_NOT_FOUND` | 404 |
| `SESSION_NOT_STARTED`, `SESSION_CLOSED`, `DEADLINE_EXCEEDED`, `AUTOSAVE_STALE_REVISION`, `AUTOSAVE_REVISION_GAP`, `MUTATION_PAYLOAD_MISMATCH` | 409 (optimistic-concurrency/lifecycle conflict against current session state) |
| `INVALID_ANSWER_BATCH` | 422 (request-shape/domain-batch rejection) |
| anything else (including the structurally-unreachable `INVALID_SESSION_TRANSITION`, and any untyped `Throwable`) | 500, reported |

Submit (`SubmitAssessmentSessionController`) uses the same table for the
subset of codes it can actually produce: `SESSION_NOT_FOUND` → 404;
`SESSION_NOT_STARTED`/`SESSION_CLOSED`/`DEADLINE_EXCEEDED` → 409; anything
else → 500, reported. `INVALID_SESSION_TRANSITION` is confirmed structurally
unreachable from `AssessmentSessionSubmitPolicy`'s call graph:
`AssessmentSessionStateMachine::submit()` only calls `transition()` when
status isn't already `Submitted`/`Scored`, and `transition(InProgress,
Submitted)` is always allowed by the state map.

## `details` field policy

Envelope is `{error:{code,message,details}}`. `details` is populated **only**
for `AUTOSAVE_REVISION_GAP`, with `current_revision` (from the participant's
own session row) and `proposed_revision` (from the participant's own
request) — never raw client `value`. Every other code gets `details: null`,
including for the nonexistent/foreign 404 case (proven identical by the
byte-identical tests above). `AssessmentAutosaveResult` gained an optional
trailing `?int $currentRevision = null`, populated by the action at its two
rejection call sites where the session row is already in scope; this is
additive and doesn't disturb any existing positional constructor call.

`INVALID_ANSWER_BATCH` does **not** carry item-level detail in this
increment — the domain policy's `canonicalize()` returns `null` on any
validation failure without identifying which item_no failed, and widening
that return contract was judged out of scope for this PR (it's a policy
change, not a transport one). Noted here as a known, intentional limitation
for a future increment if the psychologist/Lead want finer detail on which
item was rejected.

## FormRequest size/depth guard (`AutosaveAssessmentAnswersRequest`)

Lead's original instruction was "`value` wajib skalar" — this was corrected
after evidence was raised that the **already-tested** `AssessmentAutosavePolicy`
(a happy-path default fixture across most of its own test suite, not an edge
case) already accepts one level of associative-array nesting with scalar
leaves, e.g. `['rank' => 3]`, `['z' => 2, 'a' => 1]`, `['choice' => 'A']`.
Verified before implementing: every currently-`accepted=true` test across
`AssessmentAutosavePolicyTest`, `AutosaveAssessmentAnswersTest`, and
`AssessmentSessionAutosaveActionTest` (PG) uses either a scalar or exactly
one level of nesting — none goes deeper. The two "nested NAN"/"nested
infinity" cases that go two levels deep are in the *rejected* batch, and are
unreachable through real HTTP anyway (JSON has no NAN/INF literal, so
`json_decode` could never produce them from a real request body).

Revised, approved design: transport-layer guard, not a contract change.
`value` must be a scalar, or a shallow (depth-1) associative array of scalar
leaves; anything deeper or a bare list is rejected 422
`INVALID_ANSWER_BATCH` before reaching the action.

Checked against the real scoring readers before picking numbers: all three
that exist today — `ScoreSealedIstAnswerSet`, `ScoreSealedPapiAnswerSet`,
`ScoreSealedRmibAnswerSet` — require `value` to be a plain string (RMIB
specifically requires `/^(?:[1-9]|1[0-2])$/`, a one/two-digit rank).
**No shipped instrument currently reads an object-shaped value at all.** The
depth-1 object support that exists in the policy's tests is therefore
forward-compatible headroom, not something any live scoring path consumes
today; a client that sends an object where no instrument expects one isn't
silently miscored — every one of the three readers above fails closed
(`self::invalid()`, never guesses) on a non-string value. This is stated
plainly rather than assumed, per Lead's instruction not to invent RMIB's
answer shape: **RMIB's real, current wire shape is a scalar rank string, not
`{"rank": N}`** — the object shape is a synthetic test fixture, not a
documented instrument contract.

Numbers, with reasoning (`AutosaveAssessmentAnswersRequest`):
- `MAX_VALUE_STRING_LENGTH = 64`: the only known real values are short codes
  (RMIB `"1".."12"`, PAPI/IST short choice codes). Generous headroom over any
  known real answer, still blocks a client stuffing megabytes into one leaf.
- `MAX_VALUE_OBJECT_KEYS = 16` / `MAX_VALUE_KEY_LENGTH = 64`: no shipped
  instrument reads an object value at all; tested shapes use 1-2 keys. 16
  short keys leaves room for a richer future shape without allowing an
  unbounded object.
- `MAX_ITEMS_PER_REQUEST = 300`: PAPI is fixed at 90 items
  (`ScoreSealedPapiAnswerSet::ITEM_COUNT`), RMIB at 108
  (`ScoreSealedRmibAnswerSet::GROUP_COUNT * POSITIONS_PER_GROUP`). IST's total
  is schema-driven per `session_definition` rather than a single fixed
  constant — **no canonical IST item-count ceiling could be located in the
  repo**, stated plainly rather than guessed. 300 is a generous multiple of
  the largest known real total (RMIB's 108) to leave headroom while still
  bounding one request's size. The exact per-session ceiling (this session's
  real item count) stays a separate, precise domain rule — see below.

## `item_no` upper bound — stays a domain rule, not a transport one

Per Lead's explicit instruction, the check that `item_no` cannot exceed the
session's real item count lives in `AssessmentAutosavePolicy::decide()`
(`?int $maxItemNo = null`, optional/trailing so every pre-existing call site
keeps working unmodified), not the FormRequest — future non-HTTP callers must
be bound by it too. `AutosaveAssessmentAnswers` reads the true count from the
`session_definition_payload` snapshot the server itself stored at start time
(S3) — never from the client. Existing policy tests stayed green unmodified;
two existing test fixtures (`AutosaveAssessmentAnswersTest.php`,
`AssessmentSessionAutosaveActionTest.php`) needed a real
`session_definition_*` snapshot added to their session-row fixtures, because
`maxItemNo()` throws if that snapshot is absent — a fixture-correctness fix,
not a behaviour change, reported per Lead's "if something must change,
report it" instruction. `tests/Feature/AssessmentSessions/AssessmentSessionHttpTest.php`
adds the HTTP-level counterpart
(`test_autosave_item_no_beyond_session_bound_is_422_through_http`).

## GET `/sessions/{id}` — representing all six states

`AssessmentSessionAllocationResult` (S5's start/replay result) has a strict
constructor invariant (always `InProgress`) inappropriate for a general
resume read. New plain DTO `AssessmentSessionSnapshot` carries no such
invariant; `AssessmentSessionResource` was widened to a union type
(`AssessmentSessionAllocationResult|AssessmentSessionSnapshot`), verified
against S5's existing 9/9 `StartParticipantSessionHttpTest` suite (unchanged
behaviour for the allocation-result branch). `remaining_seconds` is 0 for
every status except `InProgress`, where it's
`max(0, ceil(ends_at - server_time))` — proven never negative even when read
past the deadline for a row that hasn't yet been swept to `expired` by a
write (`test_get_remaining_seconds_is_never_negative_past_the_deadline`).
`submitted_at` is populated only for `submitted`/`scored`. All six states are
covered by a data-provider test
(`test_get_represents_all_six_states_with_correct_remaining_seconds_and_submitted_at`),
with fixture rows built to satisfy the same lifecycle CHECK constraint/SQLite
trigger the migration enforces in production
(`test_sessions_lifecycle_check` / `addSqliteContract()`).

**Pitfall hit and fixed**: an early fixture draft wrote `started_at`/`ends_at`
using the ISO `T` date separator while the action writes timestamps with a
space separator (`AutosaveAssessmentAnswers::timestamp()`'s `'Y-m-d
H:i:s.uP'`). The SQLite lifecycle trigger compares these as raw TEXT
(`NEW.expired_at > NEW.ends_at`), so a `T`-separated `ends_at` sorted
differently than a space-separated `expired_at` even though both parsed to
the same instant — a false contract violation, not a real one. Fixed by
matching the production timestamp format exactly in the test fixture; noted
here in case another test file hits the same trap.

## Submit response status wording

`API_CONTRACT.md`'s literal example shows `status:'scored'` for submit's
response. `SubmitAssessmentSession`/`AssessmentSessionSubmitPolicy` only ever
transition `InProgress -> Submitted` — scoring is a separate, later pipeline
(F2's G7 lane) that this action does not run. `SubmitAssessmentSessionController`
reports the action's real post-state (`'submitted'`), not a fabricated
`'scored'`. Flagging this as a contract-wording note rather than silently
resolving it either way.

## Known, deferred gap: per-instrument `value` domain validation

Neither the FormRequest nor `AssessmentAutosavePolicy` validates that
`value`'s content matches a specific instrument's real answer shape (a valid
IST answer, a PAPI forced-choice code, an RMIB rank digit) — only structural
size/depth bounds are enforced at the transport layer. This was true before
this PR and remains true after it; explicitly not attempted here. The
requirement for whoever builds that check later: **any scoring path reading
these answers must already fail closed on an invalid `value`, never crash,
never produce a garbage score** — and, per the investigation above, all three
existing scoring readers (`ScoreSealedIstAnswerSet`, `ScoreSealedPapiAnswerSet`,
`ScoreSealedRmibAnswerSet`) already do exactly that today.

## Forward-looking, NOT fixed in this PR: Kraepelin `SessionDefinition.php` bug

`SessionDefinition.php:270-271`'s `kraepelinConfiguration()` throws
`InvalidArgumentException('Kraepelin definitions must use seeded
randomization.')` if `$randomization !== 'seeded'` — contradicting the
2026-09-21 owner decision that Kraepelin numbers are **fixed**, identical for
every participant, sourced from the official answer sheet via
`tools/extract/`, not generated/seeded. DS found this during Kraepelin
extraction work (PR #46, unmerged at time of writing — grid data being moved
to a separate `kraepelin_grid.json` file). Per Lead's direction, F2 will send
a separate plan for this fix after PR #46 merges; not touched here.

## Deferred to a follow-up PR: answer readback on resume

Found by GLM mid-PR, raised by Lead 2026-09-21: `AssessmentSession` (the GET
resume shape) carries `answers_revision` but not the answer values
themselves, so a participant who refreshes mid-test sees every question
blank even though the server has their autosaved answers. This is a real,
user-facing gap, not a hypothetical — but it touches the API contract (a new
endpoint or a new field) and has its own unresolved design questions
(whether `submitted`/`scored`/`expired`/`void` sessions may still have their
answers read back, and why; separate endpoint vs. an addition to the GET
response), so per Lead's own new "plan first for anything touching security,
instrument data, or the API contract" policy, it goes through its own plan
rather than being bundled into this already-large PR. F2 will send that plan
after this PR's SHA is reported.

## Test coverage added this PR

- `tests/Architecture/AssessmentSessionHttpBoundaryTest.php` — route/`rls`
  exclusion and no-DB/Eloquent/RlsContextRunner-dependency proof for all
  three controllers (mirrors `AssessmentSessionStartBoundaryTest.php`).
- `tests/Feature/AssessmentSessions/AssessmentSessionHttpTest.php` — 29
  SQLite HTTP-level tests: full error-code/status table for autosave and
  submit, exact-deadline boundary through real HTTP, all six GET states,
  byte-identical 404 (all three endpoints), `details` population, item_no
  bound through HTTP, FormRequest value-shape/size/depth/items-count
  boundaries, RMIB-shaped value accepted end-to-end.
- 3 new unit tests in `tests/Unit/AssessmentSessions/AssessmentAutosavePolicyTest.php`
  for the `maxItemNo` domain bound (boundary-inclusive, unbounded-when-null).
- Existing PostgreSQL evidence re-verified green against the real disposable
  harness after the fixture changes (`tests/Postgres/AssessmentSessionAutosaveActionTest.php`,
  `tests/Postgres/AssessmentSessionSubmitActionTest.php`): 7 tests, 84
  assertions, OK.
- Full SQLite sweep (`tests/Feature/AssessmentSessions`,
  `tests/Unit/AssessmentSessions`, `tests/Architecture`): 326 tests, 2360
  assertions, all green. Full `phpstan` gate (`app/`, `bootstrap/app.php`,
  `config/`, `database/`, `routes/`, level 7): 0 errors.
