# F2 item-delivery Stage 1 — start gate for item content (2026-09-21)

Scope: the mandatory requirement from the approved item-delivery plan —
**a session must never start for an instrument whose item content cannot
be delivered**, so a participant's timer never runs against a question
screen with nothing to show. `GET /sessions/{id}/items` itself (the read
endpoint) is a deliberately separate, immediate follow-up PR (Lead
decision, 2026-09-21): the read endpoint adds no protection on its own
while no reader is registered (it would just always 503), so splitting
does not open a gap, and keeps this PR small enough to review the many
existing-test changes precisely.

## What shipped

- `App\Contracts\AssessmentItemContentAuthority` — one method,
  `contentFor(GenericAssessmentInstrument, SessionDefinition): AssessmentItemContent`,
  reused for two roles: the top-level authority a caller depends on, and a
  per-instrument reader registered into the composite below. Avoids a
  second interface for an identical method shape.
- `AssessmentItemContentUnavailable` — flat `RuntimeException`, same
  pattern as `AssessmentSessionDefinitionUnavailable`.
- `AssessmentItemContent` — structural DTO only (`instrument`, `version`,
  `subtests: [{code, items}]`). Per-instrument item field shape is
  deliberately not fixed yet — depends on the "Soal" extraction session's
  file formats, not final at time of writing.
- `RegistryAssessmentItemContentAuthority` — dispatches to a per-instrument
  reader keyed by `GenericAssessmentInstrument::value`. **Fail-closed by
  design**: an instrument with no registered reader throws
  `AssessmentItemContentUnavailable`. Bound in `AppServiceProvider` **now**,
  in production, with an **empty** readers map — every instrument rejects
  until a real reader is added. Stage 2 only ever adds entries; the default
  never becomes permissive.
- Gate wired into `AllocateAndStartAssessmentSession::allocateNew()`,
  immediately after `issueForNewSession()` succeeds and the instrument
  match is checked — before any row is written. A thrown
  `AssessmentItemContentUnavailable` rolls back the whole transaction via
  the same mechanism as a definition-authority failure; no new rollback
  logic needed.
- `StartParticipantSessionController` maps `AssessmentItemContentUnavailable`
  → `503 ASSESSMENT_ITEM_CONTENT_UNAVAILABLE`.
- `API_CONTRACT.md` updated: new code added to the stable list, and to the
  non-enumerating start mapping with its ordering (checked after
  definition, before allocation) explained inline.

## Why fail-closed, not "always available" (the corrected premise)

The original instinct was to bind an "always available" placeholder so
`/start` behaviour wouldn't change today. Lead corrected this before any
container wiring landed: `assessment_session_definitions` is empty in
every environment (no seeder populates it), so `/start` for all four
instruments **already** rejects with `503 ASSESSMENT_DEFINITION_UNAVAILABLE`
today — there is no live "success" path to protect. Worse, an "always
available" default would only fail once it mattered: the moment PR #46's
Kraepelin grid and a catalog row exist, `/start` would start **succeeding**
under a still-permissive item-content default, and nothing would fail to
flag it — exactly the gap this gate exists to prevent, reopened by the
"safe-looking" default itself. Fail-closed with an explicit, reviewable
empty map is the only version of Stage 1 that actually holds.

## Test coverage

- `tests/Architecture/AssessmentItemContentAuthorityBindingTest.php` (new):
  no `AlwaysAvailable*` class exists anywhere under `app/` (scans every
  file), and the real container binding resolves to
  `RegistryAssessmentItemContentAuthority`.
- `tests/Feature/AssessmentSessions/AllocateAndStartAssessmentSessionTest.php`:
  new `test_item_content_failure_leaves_no_writes_or_context_state`,
  mirroring the existing definition-failure test exactly (same
  `assertAllocatorRolledBack()` assertions: no RLS context, transaction
  level 0, zero `test_sessions`/`test_session_grants` rows, entitlement
  untouched). New `ThrowingAssessmentItemContentAuthority` fake alongside
  the existing `ThrowingAssessmentSessionDefinitionAuthority`.
- `tests/Feature/AssessmentSessions/StartParticipantSessionHttpTest.php`
  (new): `test_real_container_binding_rejects_start_for_an_instrument_with_no_registered_item_content_reader`
  — through the real `/start` HTTP route, with a valid active catalog row
  (so the earlier definition gate passes and this gate is what's actually
  under test), using the **real, unmodified** container binding for
  `AssessmentItemContentAuthority` (only the definition authority is
  faked) — asserts `503 ASSESSMENT_ITEM_CONTENT_UNAVAILABLE` and zero
  `test_sessions` rows.
- `tests/Postgres/StartParticipantSessionControllerSecurityTest.php`
  (new): `test_participant_with_a_ready_entitlement_is_rejected_with_503_when_no_item_content_reader_is_registered`
  — same proof against real PostgreSQL RLS/transaction semantics, using
  the actual `RegistryAssessmentItemContentAuthority` class (not a
  throwing test double) with an empty readers array, through the
  controller. Asserts zero `test_sessions`/`test_session_grants` rows.
  New fixture `participantGraphWithReadyIstEntitlement()` (the existing
  fixture in this file deliberately has no ready entitlement, so a new one
  was needed to reach past authorization resolution into the gates this
  test targets).

## `tests/Support/AlwaysAvailableAssessmentItemContentAuthority.php` (new, test-only)

Lives only under `tests/Support/` — never `app/` (enforced by the new
architecture test above). Exists purely so tests whose actual subject is
something else (allocation mechanics, definition-authority behaviour,
concurrency, controller security boundaries) can keep constructing
`AllocateAndStartAssessmentSession` without caring about item content.
Never bound to the real container.

## Existing tests updated to satisfy the new constructor dependency

Every call site constructing `AllocateAndStartAssessmentSession` directly
needed a new argument. Per Lead's explicit requirement, listed here by
file, with an explicit note on which ones test **end-to-end start
success** (where the item-content gate's real behaviour is now faked out
for that test, by design) versus which reject before ever reaching the
gate (where the fake is inert -- present only to satisfy the constructor):

- **`tests/Feature/AssessmentSessions/AllocateAndStartAssessmentSessionTest.php`**
  — `allocator()`/`action()` helpers now default to
  `AlwaysAvailableAssessmentItemContentAuthority` unless a test passes its
  own. **End-to-end success, content now faked**: every test that proves a
  session actually gets allocated/started (e.g.
  `test_direct_public_allocation_persists_the_full_snapshot_grant_and_exact_source_transition`
  and the other successful-allocation/replay tests in this file) — their
  subject is allocation/grant/transition mechanics, not item content, and
  none of them were ever meant to prove content availability.
- **`tests/Postgres/StartParticipantAssessmentSessionConcurrencyTest.php`**
  — `action()` helper. **End-to-end success, content now faked**: both
  concurrency tests
  (`test_two_concurrent_direct_public_starts_produce_one_new_session_and_one_exact_replay`,
  `test_real_postgresql_trigger_failure_on_final_transition_rolls_back_every_prior_write`)
  require a session to actually start to exercise real PostgreSQL
  lock/retry behaviour; their subject is concurrency, not content.
- **`tests/Feature/AssessmentSessions/StartParticipantSessionHttpTest.php`**
  — `bindFakeAuthority()` now also binds the always-available fake.
  **End-to-end success, content now faked**:
  `test_new_allocation_returns_200_with_the_assessment_session_shape` and
  `test_second_request_replays_the_same_session`. **Reaches past the gate
  but content-faking is incidental to the actual subject**:
  `test_retries_exhausted_on_the_real_route_returns_503` (subject is the
  transaction-retry loop, needs to get past the content gate to reach the
  injected final-transition failure). **Inert** (rejected earlier, before
  the gate, for unrelated reasons):
  `test_dass21_and_scope_selectors_are_rejected_with_422`,
  `test_unrecognized_participant_origin_is_rejected_with_403`,
  `test_locked_entitlement_is_rejected_with_403`,
  `test_multiple_ready_cases_are_rejected_as_ambiguous_with_409` — all call
  `bindFakeAuthority()` but reject during authorization/candidate
  resolution, never reaching the definition or item-content gates.
- **`tests/Feature/AssessmentSessions/StartParticipantAssessmentSessionTest.php`**
  — one `AllocateAndStartAssessmentSession` construction updated. **Inert**:
  every test in this file rejects before the allocator's gates (zero/
  ambiguous candidates, pre-existing RLS context, pre-existing
  transaction) — the fake is present only to satisfy the constructor
  signature, no test here relies on its "always available" behaviour.
- **`tests/Postgres/StartParticipantSessionControllerSecurityTest.php`** —
  `command()` helper now passes a throwing item-content fake ("must never
  be reached"), consistent with the file's own existing pattern for the
  definition authority in that same test (an unentitled participant is
  rejected before either gate). Not a behaviour change; no success case
  faked here — the new success/rejection tests for the item-content gate
  itself use their own dedicated helper (`commandWithRealItemContentGate()`),
  not this one.

## Test suite status

- SQLite: `tests/Feature/AssessmentSessions`, `tests/Unit/AssessmentSessions`,
  `tests/Architecture` — 344 tests, 2965 assertions, green.
- PostgreSQL: `StartParticipantAssessmentSessionConcurrencyTest` (4 tests),
  `StartParticipantSessionControllerSecurityTest` (2 tests, including the
  new real-registry test) — all green, first run.
- Full `phpstan` gate (level 7): 0 errors. `pint --test`: clean.

## Deferred to the immediate follow-up PR

`GET /sessions/{id}/items` itself — ownership/lifecycle gate (mirroring
`GET /sessions/{id}/answers`), controller, route, response whitelist. Design
already covered in the approved plan; not blocked on anything new from
this PR.
