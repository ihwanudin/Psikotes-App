# F2 S5 — HTTP cutover: classification, rules, and decisions (2026-09-21)

Coordinator-owned files (`routes/api.php`, `StartParticipantSessionController.php`,
`StartAssessmentSessionRequest.php`) were delegated to F2 for this one PR by
explicit Lead sign-off, 2026-09-21 ("coordinator-owned S5 files, delegated by
Lead"). This doc is the durable record of the decisions made while building
that PR, referenced from several inline code comments.

## Rule: any test calling the real start route through the real command needs `DatabaseTruncation`, not `RefreshDatabase`

`StartParticipantAssessmentSession::assertCleanOuterBoundary()` and
`AllocateAndStartAssessmentSession::assertCleanOuterBoundary()` both require
`DB::transactionLevel() === 0` before any SQL runs — the ADR-0030 boundary
that rejects a pre-existing context/transaction. `RefreshDatabase` wraps every
test method in its own outer transaction for rollback-based isolation, so
`DB::transactionLevel()` is never 0 inside a test using that trait —
structurally, regardless of what the test body does. Hitting this produces a
`LogicException`, mapped by the controller's catch-all to a generic 500 — it
looks exactly like a code bug, not a test-setup problem, unless you already
know this rule.

`DatabaseTruncation` was already the established choice for the command's own
tests (`AllocateAndStartAssessmentSessionTest.php`,
`StartParticipantAssessmentSessionTest.php`) before S5, for this exact reason.
`tests/Feature/AssessmentSessions/StartParticipantSessionHttpTest.php`
(new, S5) follows the same convention for HTTP-level coverage of the real
route. `tests/Postgres/*` files are unaffected — they extend bare
`PHPUnit\Framework\TestCase`, which does not wrap anything in an implicit
transaction.

Two assertions were **moved**, not thinned, out of `RefreshDatabase`-based
files into `StartParticipantSessionHttpTest.php` for this reason (coordinator
sign-off, 2026-09-21):
- `tests/Feature/Auth/AssessmentSessionAuthorizationTest.php`: the "legacy
  ParticipantJwt reaches the real production route and is rejected" case
  (now `test_unrecognized_participant_origin_is_rejected_with_403`, asserting
  403 `ASSESSMENT_NOT_AVAILABLE` instead of the old unconditional 501). Every
  other assertion in that 283-line file is unchanged — it still exercises the
  pre-ADR-0030 "Integrated" path via `StartIntegratedAssessmentSessionController`
  and its own test-only route, which is untouched by this cutover.
- `tests/Feature/F1/ManualActivationFlowTest.php`: the "freshly, fully
  legitimately activated participant hits the real route" case (now
  `test_freshly_activated_participant_with_no_catalog_entry_gets_503`,
  asserting 503 `ASSESSMENT_DEFINITION_UNAVAILABLE`). The rest of that file's
  registration/payment/activation coverage is unchanged.
- `tests/Feature/Auth/ParticipantApiAuthorizationTest.php`: the "locked
  entitlement cannot start a session" case (now
  `test_locked_entitlement_is_rejected_with_403`). This one is not just a
  relocation — see the dedicated section below, it's a genuine contract
  change (`ENTITLEMENT_LOCKED` no longer exists for this route). The other
  three tests in that file (`/api/me`, `/api/me/entitlements`, token
  tampering) are unrelated to session-start and unchanged.

Both old locations carry a comment pointing at the new file.

**How this third file was found, and why grep wasn't enough**: the first two
moved cases were found by grepping for the literal string
`SESSION_ENGINE_PENDING` — every test asserting the old placeholder response.
`ParticipantApiAuthorizationTest.php` asserted a *different* old code
(`ENTITLEMENT_LOCKED`, from the controller's own entitlement-gate check, not
the placeholder), so it never matched that grep and was invisible until a
full targeted test sweep actually ran it and hit the same
`assertCleanOuterBoundary()`/`RefreshDatabase` conflict. The lesson: for a
route cutover, the reliable audit is "every test that issues a request to
this URL" (grep the route string/name, or its middleware/name), not "every
test that asserts the specific old response" — the latter only finds what
you already know to look for. If this route is ever cut over again (or a
sibling route is), start from the URL/route-name query, not a response-body
string.

## `ENTITLEMENT_LOCKED` retirement for this route

`test_locked_entitlement_is_rejected_with_403` asserts 403
`ASSESSMENT_NOT_AVAILABLE` where the pre-cutover test asserted 403
`ENTITLEMENT_LOCKED`. This is deliberate, not a regression: ADR-0030 removes
the controller's own entitlement-gate read entirely ("controller tidak boleh
memakai DB, Eloquent, `RlsContextRunner`, atau membaca entitlement"), and its
error-mapping section names entitlement state explicitly inside the generic
403 bucket: "`403` `ASSESSMENT_NOT_AVAILABLE` untuk source/case/grant/
**entitlement** yang missing, foreign, revoked, stale, belum siap ...; semua
kondisi ini memakai pesan generik dan tanpa `details`." A locked entitlement
now produces the same `Unavailable` selection outcome as no entitlement at
all (`ParticipantAssessmentSessionCandidates`'s eligibility check requires
`status === 'ready'`), and the same generic bucket as every other
not-currently-available reason — by design, so the response can never be
used to enumerate *why* access isn't available.

Blast radius checked before making this change: `ENTITLEMENT_LOCKED`
appeared in exactly one non-test location in the whole repository — the old
controller line this PR replaces. No client code (`resources/js` or
otherwise) reads that code, so nothing breaks from its retirement.

Participant-experience note (raised to the coordinator, carried to the
frontend lane, not S5's to act on): removing the distinguishable code means
a participant who attempts to start with a locked entitlement can no longer
learn *why* from the start response — this is deliberate anti-enumeration at
the API boundary, but it means the lobby UI must surface "your access isn't
active yet" via `/me/entitlements` (which still reports entitlement status
directly) rather than by attempting a start and reading its error.

## Controller split: `StartParticipantSessionController` vs `StartIntegratedAssessmentSessionController`

Before S5, one controller class served two incompatible callers: the real
production route (`participant.jwt`+`rls`, `ParticipantPrincipal`) and a
test-only route (`AuthenticateAssessmentToken`, `AssessmentPrincipal`/
Integrated) exercised by `AssessmentSessionAuthorizationTest.php`. ADR-0030
requires the production controller to have zero DB/Eloquent/RlsContextRunner/
entitlement dependency — a property of the *class*, not of a branch inside
it — which is incompatible with the Integrated branch's entitlement gates
living in the same class.

Resolution (coordinator-approved, 2026-09-21): renamed the pre-existing
class to `StartIntegratedAssessmentSessionController` (git mv, zero behaviour
change, still returns `SESSION_ENGINE_PENDING` 501 — that path is explicitly
not part of this cutover per ADR-0030's "Consequences" section). The name
`StartParticipantSessionController` — which the real route already
referenced — now holds the fresh ADR-0030 implementation.
`AssessmentSessionAuthorizationTest.php` needed only class-name reference
renames (4 sites) for this split; verified via `git diff` that nothing else
in that file changed for the rename itself (the 2 assertions that also moved,
above, are a separate, explicitly-authorized change).

## Request split: `StartAssessmentSessionRequest` vs `StartGenericAssessmentSessionRequest`

Both start routes share `StartAssessmentSessionRequest` before S5, which
allows `dass21`. ADR-0030 requires the new route to reject `dass21` with a
real `422 INVALID_ASSESSMENT_START_REQUEST` (not a route-level 404 — see
below). Narrowing the shared request would have silently changed the
Integrated test route's `dass21` behaviour and broken its currently-green
assertions (`AssessmentSessionAuthorizationTest.php:145,148`).

Resolution: `StartAssessmentSessionRequest` is untouched, still wired to
`StartIntegratedAssessmentSessionController`. New
`StartGenericAssessmentSessionRequest` (drops `dass21` from `Rule::in`, adds
`failedValidation()` → the closed error envelope) is wired to the new
`StartParticipantSessionController`.

## `dass21` stays in the route's `whereIn`

ADR-0030: "`422` `INVALID_ASSESSMENT_START_REQUEST` untuk instrumen yang
tidak termasuk empat instrumen generik ... pada body/query" — `dass21` must
reach validation and produce a real 422 envelope, not a bare 404 from a route
pattern mismatch. `routes/api.php`'s `whereIn('testType', [...])` is
unchanged (`dass21` still listed); `StartGenericAssessmentSessionRequest`'s
`Rule::in` is what rejects it.

## Typed HTTP-failure classification for `InvalidAssessmentSessionState`

New `AssessmentSessionStartFailureCode` enum (`NotAvailable` = 403
`ASSESSMENT_NOT_AVAILABLE`, `Conflict` = 409 `ASSESSMENT_START_CONFLICT`) as
an optional, backward-compatible named constructor param on
`InvalidAssessmentSessionState`. Not `AssessmentSessionErrorCode`: that enum
is shared decision-level vocabulary for the deadline/allocation/autosave
policies of *other* endpoints (session resume/autosave/submit), and widening
it to also carry this endpoint's HTTP mapping would couple those endpoints to
this one's response contract for no reason. Untyped (`null`) means an
internal invariant violation, mapped to a generic 500 — never guessed at as a
409, per Lead's explicit instruction; a test that needs a 500 has found a
real defect, not a case to code around.

There are two independent mechanisms that reach a typed
`InvalidAssessmentSessionState`, not one:

1. `AssessmentSessionSelectionPolicy::select()` returns an
   `AssessmentSessionSelectionOutcome` with `kind: AssessmentSessionSelectionKind`.
   `StartParticipantAssessmentSession::withinTransaction()` throws when
   `$selection->candidate === null` (kinds `Unavailable`, `HistoryAmbiguous`,
   `SelectionAmbiguous`, `ScopeRejected` — `Replay`/`Selected` always carry a
   candidate and never reach this branch).
2. `AssessmentAttemptAllocationPolicy::decide()` returns an
   `AssessmentAttemptAllocationDecision` with `errorCode: ?AssessmentSessionErrorCode`.
   From `AllocateAndStartAssessmentSession::allocateNew()`'s call site, only
   `AttemptAlreadyExists` and `RetestNotAuthorized` are reachable — no retest
   grant is ever passed from this call site (ADR-0031, retest authority is
   out of scope), so every other case of that policy's rejection path is
   structurally unreachable here. The policy's other enum cases
   (`SessionNotStarted`, `SessionClosed`, `DeadlineExceeded`, the autosave
   codes) belong to other endpoints and can never reach this call at all.

Full site-by-site classification (also reproduced in the PR description):

**Typed 403 `NotAvailable`:**
- `StartParticipantAssessmentSession.php`, kind `Unavailable` — no eligible
  candidate (no ready entitlement).
- `AllocateAndStartAssessmentSession.php`, `decide()` → `RetestNotAuthorized`
  — ADR-0030 verbatim: "... tidak memiliki authority retest."
- `AllocateAndStartAssessmentSession.php`, "Expired assessment sessions
  cannot be replayed." — genuinely reachable: stored status is still
  `in_progress` (lazy expiry — no sweep job has transitioned it yet), so
  `isLiveReplay()` legitimately selects it as a replay candidate, but its
  `ends_at` has already passed by the time `replay()`'s deadline check runs.
  ADR-0030's "stale" bucket, not a retryable conflict.

**Typed 409 `Conflict`:**
- `StartParticipantAssessmentSession.php`, kinds `HistoryAmbiguous` and
  `SelectionAmbiguous` — ADR-0030 verbatim: "history/kandidat yang ambigu."
- `AllocateAndStartAssessmentSession.php`, `decide()` → `AttemptAlreadyExists`
  — ADR-0030's "konflik start yang aman diulang."

**Untyped → 500 generic (invariant/code-bug; none reachable by a legitimate
participant given the locks already held by the time each check runs):**
- "Selected participant authorization scope changed before allocation." — S2
  just built this authorization inside the same transaction/locks.
- "Definition authority returned the wrong instrument." — authority is
  trusted internal code.
- "A new assessment session must have an active deadline." — freshly
  computed deadline should always be valid by construction.
- "Assessment session grant history is ambiguous." (inside `replay()`,
  case+entitlement-scoped) — `test_session_grants_entitlement_unique` should
  make >1 row here a DB-integrity violation.
- "Assessment session grant has no session." — FK integrity violation.
- "Only one first attempt can be replayed." — no retest wired yet
  (ADR-0031).
- "Stored session replay identity is inconsistent." — origin/scope changes
  are already caught earlier at the resolver (`CaseAuthorizationRejected`).
- "Stored assessment allocation cannot be replayed." — deep invariant on the
  allocation policy's own replay match.
- "Stored assessment session status is invalid." — corrupt DB data.
- "Terminal and unstarted sessions cannot be replayed." —
  `AssessmentSessionSelectionCandidate::isLiveReplay()` is strictly
  `sessionStatus === InProgress`, so a terminal candidate is never selected
  as a replay candidate in the first place; unreachable given current
  selection logic.
- "Exact {orders|selection_participants} is unavailable/invalid." — S2
  already verified exactly one row under the same locks; can't diverge
  within the same transaction.
- "Session definition snapshot cannot be persisted." (JSON encode failure on
  our own well-formed object) — code bug.
- "The exact session source grant could not be consumed." — entitlement was
  already locked in S2's phase; can't be raced within the same transaction.
- "The integrated participant could not be started." — unreachable from this
  route (`AssessmentPrincipal`-only branch).
- "The allocated assessment session could not be started." — UPDATE on the
  row just inserted, same transaction.
- "Stored session grant shape is inconsistent." / "Stored session definition
  snapshot is invalid/incomplete/must be an object/identity is inconsistent."
  / "Stored assessment session {field} is missing." — all corrupt-data
  invariants on data this same code path already wrote and validated moments
  earlier.
- `ParticipantAssessmentSessionCandidates.php`: "Multiple case-scoped session
  histories are not yet supported." (same "not yet supported" guard as
  above) and "Persisted session status is invalid." (corrupt DB data).
- `StartParticipantAssessmentSession.php`, kind `ScopeRejected` — the scope
  comes from the same principal that produced the candidates; can't
  legitimately mismatch.

`CaseAuthorizationRejected` (from `CaseAuthorizationResolver`) is mapped as a
whole class, uniformly, to 403 `ASSESSMENT_NOT_AVAILABLE` — it is a single,
argument-less exception, and every one of its ~15 `reject()` call sites
already means exactly "this authorization is not currently valid"
(foreign/stale/revoked/mismatched), which is one ADR bucket by definition. No
per-site typing needed.

## Retry-exhaustion: `AssessmentSessionStartRetriesExhausted`

`StartParticipantAssessmentSession::execute()`'s retry loop used to re-throw
the raw `QueryException` once retries were exhausted. Mapping that to 503
`ASSESSMENT_START_TEMPORARILY_UNAVAILABLE` at the HTTP layer would mean
re-implementing the SQLSTATE 40001/40P01 check in the controller — the same
DB-detail leak Correction A is about, one layer up. Fixed by wrapping the
exhausted-retry case in a new, dedicated exception inside the command (the
one place that already knows the retry policy); a non-retryable
`QueryException` still escapes raw and unwrapped, proven by the pre-existing
`AllocateAndStartAssessmentSessionTest::test_non_retryable_sqlstates_escape_after_one_transaction_attempt`
data-provider test (unchanged by this work — its behaviour is not affected by
the wrapper, since it only fires on the *retryable-and-exhausted* branch).

## `config`/`seed` response shape — security check (Lead's question, 2026-09-21)

`config` is sent to the browser, so the question is not "what does the client
need" but "what must never leave the server." Checked
`SessionDefinition::fromArray()`/`toArray()`/`checksumFor()` directly: every
level (top-level fields, each subtest, the Kraepelin generator) is validated
with `assertExactFields()` against a fixed, named constant list
(`REQUIRED_FIELDS`, `SUBTEST_FIELDS`, `KRAEPELIN_GENERATOR_FIELDS`) — an
explicit allowlist by construction, not a field-exclusion approach that could
fail open if an upstream field were added later. A `SessionDefinition` object
structurally cannot hold an answer key, a scoring key, a norm table, or a
PAPI/RMIB weight — there is no field slot for any of them anywhere in the
object, and `DatabaseAssessmentSessionDefinitionAuthority::decodeTemplate()`
runs the exact same validation again on every catalog row before it ever
becomes a `SessionDefinition`. `AssessmentSessionResource`'s `config` array
(`total_duration_seconds`, `subtests`, `randomization`, `generator`) is a
subset of `SessionDefinition`'s own already-allowlisted fields, not a
separately-trusted exclusion list. `version`/`provenance`/`checksum` are left
out of `config` as integrity/audit metadata for the server and for replay
verification, not data a rendering client consumes.

Kraepelin note: `randomization` stays `'seeded'` and `seed` stays a real,
per-session server-issued string in `SessionDefinition`'s current shape —
this predates the 2026-09-21 owner decision that Kraepelin's *numbers* are
now fixed (identical for every participant, from the official sheet) rather
than generated. That decision is about the digit *content* a participant
sees, produced later by whatever Kraepelin generator implementation
eventually reads this seed — it is not yet reflected in
`SessionDefinition`'s randomization/seed vocabulary, and reconciling that is
explicitly out of scope for S5 (raw Kraepelin digit extraction/generation
remains blocked pending the psychologist's source-file verification). Not
changed here; flagged so it isn't mistaken for an oversight.

`numbers_per_column`/`answer_slots_per_column` are already `28`/`27`
everywhere in the codebase (`SessionDefinition.php`'s own constants, both
PostgreSQL and SQLite migration-level triggers, and every existing test) —
verified by grep across the whole tree, not just S5's own new files. Matches
the owner's confirmation exactly; nothing to fix.
