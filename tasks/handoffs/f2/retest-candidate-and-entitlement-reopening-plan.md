# F2 — Retest candidate eligibility and entitlement reopening (follow-up to item 19) plan (2026-09-22)

**Status: plan only, no code — revised.** Lead's review of the first
revision confirmed the overall direction and the Q3 approach (a parallel
branch with retest-shaped invariants, not a flipped flag) but required
three things resolved to a definite conclusion before code, not left as
open items: the exact `entitlement.status` value after attempt 1, whether
the production entry point automatically reaches the candidate-selection
path for a single-case participant, and whether the `$sessions->count() > 1`
guard also protects against real corruption (not just "retests aren't
built yet"). All three are traced and resolved below, inline in the
sections they belong to — see "Q1" for the entitlement-status trace, "Q2"
for the guard's corrected replacement, and the new "Confirmed:" block
under Q3 for the production entry-point trace.

Follow-up to PR #116
(`f2/retest-limit`, draft), which wires `AssessmentRetestGrant` into
`AssessmentAttemptAllocationPolicy` and `AllocateAndStartAssessmentSession`
but ships with an explicit caveat in its own body:

> ⚠️ Not yet usable end-to-end by a real participant. A retest grant can be
> created and approved... but a participant still cannot actually reach a
> retest session today. `CaseAuthorizationResolver::readyEntitlement()`
> rejects once an entitlement's `started_at` is set (no reopening path
> exists yet), and `resolveSelectedParticipantForUpdate()` explicitly
> rejects any candidate with `retestCandidate=true`.

Lead required this plan before any further code, with three specific
questions to answer by reading the actual code, not guessing:

1. **Entitlement reopening** — "verifikasi ke kode (bukan tebak)... kalau
   ternyata tidak sesederhana itu, laporkan sebelum memutuskan."
2. **`retestCandidate` in `ParticipantAssessmentSessionCandidates::selectionCandidate()`**
   — "perlu logika nyata: cek apakah peserta punya grant retest aktif yang
   belum dipakai untuk instrumen itu, baru tandai true."
3. **`resolveSelectedParticipantForUpdate()`'s reject** — "telusuri
   alasannya dulu (invarian apa yang belum tentu benar untuk kasus
   retest), baru rancang syarat yang harus dipenuhi supaya boleh tidak
   ditolak. Jangan sekadar membalik flag-nya."

Everything below was verified by reading
`app/Services/AssessmentSessions/CaseAuthorizationResolver.php`,
`app/Services/AssessmentSessions/ParticipantAssessmentSessionCandidates.php`,
`app/Domain/AssessmentSessions/AssessmentSessionSelectionCandidate.php`,
and `app/Domain/AssessmentSessions/AssessmentSessionSelectionPolicy.php` in
full, plus a repo-wide grep for every consumer of
`entitlements.started_at`/`completed_at`.

## Q1 — Is resetting the entitlement row safe?

**Yes, confirmed by exhaustive grep, and the reset belongs at allocation
time, not at candidate-read time.**

`entitlements` carries `unique(['participant_id', 'test_type'])` — one row
per participant per instrument, ever. There is no way to add a second row
for attempt 2; the existing row must be reused. Grepping every reference to
`started_at`/`completed_at` on this table across `app/` turns up exactly
two consumers, both already identified in PR #116's own research:

- `CaseAuthorizationResolver::readyEntitlement()` (and the equivalent
  inline `whereNull` clauses in `resolveIntegratedForUpdate()` and
  `assertSelectedEntitlementAndHistory()`'s non-replay branch) — gating
  reads only.
- `AllocateAndStartAssessmentSession::consumeSourceGrant()` — the one and
  only writer, sets `started_at` on successful allocation. No code path in
  this repo ever sets `completed_at` on `entitlements` at all (confirmed by
  grep — it exists as a column but is dead for writes; reporting reads
  `test_sessions.scored_at`, not this column).

No reporting, billing, or display code reads these two columns. Resetting
them is not "loosening a constraint," it is un-sticking a gate that only
ever existed to serialize a single attempt against a single entitlement
row — the row itself carries no historical record worth preserving
(`test_sessions` is the real, append-only, per-attempt history; this
follow-up doesn't touch it).

**Where the reset happens:** inside
`AllocateAndStartAssessmentSession::allocateNew()`, immediately before its
existing `consumeSourceGrant()` call, and only when the accepted decision's
`attemptNumber > 1`. This keeps the change to one additional call in one
method; no new method on `CaseAuthorizationResolver` reads or requires the
entitlement to already be reset before allocation is attempted, and no
reset happens speculatively for a candidate that never gets to allocation
(e.g. it's rejected for insufficient authorization).

**Exact prior status, traced through `consumeSourceGrant()` (PR #116,
`app/Actions/AssessmentSessions/AllocateAndStartAssessmentSession.php:572-597`)
— this was left as an open item in the previous revision of this plan;
Lead required it resolved before code, so it's resolved here:**

```php
$updated = $query
    ->where('status', 'ready')
    ->whereNotNull('ready_at')
    ->whereNull('started_at')
    ->whereNull('completed_at')
    ->update([
        'status' => 'in_progress',
        'started_at' => $this->timestamp($serverTime),
        'updated_at' => $this->timestamp($serverTime),
    ]);
```

This is the **only** writer to `entitlements.status`/`started_at`/`completed_at`
in the entire codebase (Q1's grep already established this). It transitions
`'ready' → 'in_progress'` and sets `started_at` on allocation — and never
transitions the row again afterward, for any reason: no code path sets
`completed_at`, and no code path sets `status` back to anything else once
`in_progress`, regardless of how the session itself later ends
(`submitted`/`scored`/`expired`/`voided` all live only on `test_sessions`,
never on `entitlements`). **Consequence: after attempt 1 ends, the
entitlement row is deterministically stuck at exactly `status =
'in_progress', started_at = <attempt-1 timestamp>, completed_at = NULL` —
not "whatever value," a single, guaranteed value.**

This means `reopenEntitlementForRetest()` can and must assert the exact
prior state rather than a loose `status <> 'ready'`:

```php
private function reopenEntitlementForRetest(int $entitlementId): void
{
    $updated = DB::table('entitlements')
        ->where('id', $entitlementId)
        ->where('status', 'in_progress')
        ->whereNotNull('started_at')
        ->whereNull('completed_at')
        ->update([
            'status' => 'ready',
            'started_at' => null,
        ]);
    if ($updated !== 1) {
        throw new InvalidAssessmentSessionState('The retest entitlement was not in the expected in-progress state.');
    }
}
```

Asserting `status = 'in_progress'` exactly (not merely "not ready") is not
just precision for its own sake — it doubles as a corruption check: if the
row were ever found in any other state (e.g. still `'ready'` because
`consumeSourceGrant()` never actually ran for attempt 1, which should be
structurally impossible but would indicate a real bug if it happened), this
throws instead of silently reopening a row that was never legitimately
consumed.

**Rejected alternative:** resetting the entitlement at
candidate-projection time (inside `ParticipantAssessmentSessionCandidates::candidate()`
or `CaseAuthorizationResolver`'s read path). Both of those run under
read/selection locks that are held far more often than an actual retest
allocation attempt (every lobby load, every session-selection check), so
performing a write there would mutate state on paths that today are
side-effect-free reads, and would reopen the entitlement even for a
candidate the participant never actually starts. Allocation time is the
only point that already commits to "this attempt is really happening."

## Q2 — Real `retestCandidate` logic in `ParticipantAssessmentSessionCandidates`

### A constraint this research surfaced that PR #116 didn't need to touch

`candidate()` (line 214 today) has:

```php
if ($sessions->count() > 1) {
    throw new InvalidAssessmentSessionState('Multiple case-scoped session histories are not yet supported.');
}
```

`test_sessions` is unique per `(participant_id, test_type, attempt_no)` —
by design, a second accepted attempt is a **second row**, not an update to
the first. The moment a real retest is allocated (attempt 2), this method
will see 2 rows for that participant+case+instrument and throw. **This
guard has to change as part of this follow-up**, not just the
`retestCandidate=false` hardcoding — it's a prerequisite, not something
PR #116 broke, since PR #116 never reaches allocation for a second attempt
today (the resolver rejects first).

**Is the guard purely "retests aren't supported yet," or does it also
catch real corruption (e.g. two concurrently `in_progress` sessions)? Lead
required this confirmed explicitly, not assumed.** Traced: in today's
pre-retest state space, `AssessmentAttemptAllocationPolicy::decide()` has
always rejected any attempt beyond the first when no retest grant is
supplied, and production has never supplied one (`allocateNew()` always
passed `null` before PR #116) — so a second row for the same
participant+case+instrument was structurally impossible to create
correctly. **Any 2-row state observed today would in fact only ever be
reachable through corruption** (a bug that let `decide()`'s reject slip,
or a direct write bypassing the allocator entirely), which is exactly why
the guard fires unconditionally on `count() > 1` — it had no legitimate
case to distinguish from a corrupt one. **It is therefore not safe to
simply delete the guard or loosen it to "count() > 1 is fine now" —**
doing so would also silently accept a genuinely corrupt state, such as two
rows both `in_progress` at once, and hand the caller whichever one
`$sessions->last()` happens to be, with no signal anything was wrong.

The guard must become specific to the one new legitimate shape (a linear
history where only the most recent attempt can be non-terminal), not
removed:

```php
// Note: AssessmentSessionStatus::Voided's underlying string value is
// 'void', not 'voided' -- checked against app/Domain/AssessmentSessions/AssessmentSessionStatus.php
// directly rather than assumed, to avoid embedding a real typo bug here.
$nonTerminal = $sessions->filter(
    static fn (array $row): bool => ! in_array($row['status'] ?? null,
        ['submitted', 'scored', 'expired', 'void'], true),
);
if ($nonTerminal->count() > 1
    || ($nonTerminal->count() === 1 && $nonTerminal->keys()->first() !== $sessions->keys()->last())) {
    throw new InvalidAssessmentSessionState('Assessment attempt history is not a valid linear sequence.');
}
```

i.e.: at most one non-terminal (`created`/`in_progress`) row is ever
allowed, and if one exists it must be the row with the highest
`attempt_no` (the sessions collection is already `orderBy('attempt_no')`).
Any earlier row that is still non-terminal — two concurrently active
attempts, or a gap where an old attempt was never closed out before a
newer one exists — still throws exactly as today. This is strictly more
permissive than today's guard only for the one new legitimate case (a
terminal row followed by nothing yet), and strictly as strict as today's
guard for every corruption case it used to catch.

### Design

Fetch the full ordered history (`orderBy('attempt_no')`, no `limit(2)` —
replace with `limit` large enough to be a sane ceiling, e.g. 50, matching
"no upper bound but a human approves every one past the free limit," or
leave unlimited since `lockForUpdate()` on a handful of rows per
participant is cheap) instead of rejecting on `count() > 1`. Take the
**latest** row (`$sessions->last()`) as "the current/most recent attempt"
for the existing live-replay branch (unchanged: if its status is
`in_progress`, behave exactly as today — same grant-row cross-check,
`retestCandidate: false`, `eligibleForAllocation: false`, session identity
carried through for `isLiveReplay()`).

The linear-history guard above (replacing `count() > 1`) already guarantees
that when it doesn't throw, at most the last row can be non-terminal — so
"the latest row is terminal" is simply "`$nonTerminal` is empty." When the
latest row is **terminal** (`submitted`/`scored`/`expired`/`voided` — i.e.
not `created`/`in_progress`), this is new: today `candidate()` never
reaches this branch at all for `count() >= 1` non-live rows because
nothing calls it with more than one row and the single-row terminal case
currently falls through to `selectionCandidate(..., eligible: false, ...)`
with the live-session shape (`sessionPublicId`/`sessionStatus` set,
`retestCandidate: false`) — i.e. a terminal single session is currently
reported as a dead-end, not a retest opportunity. That reporting is what
must change:

```php
$latest = $sessions->last();
$attemptCount = $sessions->count();
$nextAttemptNumber = $attemptCount + 1;

if ($latestStatus->isTerminal()) { // submitted/scored/expired/voided — not created/in_progress
    $withinFreeLimit = $nextAttemptNumber <= (int) config('assessment_retests.free_attempt_limit', 3);
    $hasActiveGrant = DB::table('assessment_retest_grants')
        ->where('participant_id', $principal->participantId)
        ->where('test_type', $instrument->value)
        ->where('attempt_number', $nextAttemptNumber)
        ->where('status', 'active')
        ->lockForUpdate()
        ->exists();

    if ($withinFreeLimit || $hasActiveGrant) {
        // Genuinely-new-attempt shape: both session fields null, per
        // AssessmentSessionSelectionCandidate's own constructor invariant.
        return $this->selectionCandidate($case, $principal, $instrument, $durableGrantId, true,
            null, null, retestCandidate: true);
    }

    // Terminal, not within free limit, no active grant: not a candidate
    // at all today's caller can act on. Fall through to the existing
    // dead-end shape (eligible: false, session identity carried for
    // display/history purposes) rather than a new one — this is not a
    // live replay and not an allocatable retest.
}
```

`durableGrantId` stays `'entitlement:'.$entitlementId` — the **same**
entitlement row is reused (per Q1), so this is not a new grant identity;
it is exactly what `hasEligibleDurableSourceGrant()` already expects
(`sessionStatus === null && eligibleForAllocation && durableSourceGrantId !== null`).
`selectionCandidate()` itself needs its hardcoded final `false` argument
turned into a real parameter — trivial, it already takes every other field
positionally.

**Free-limit attempts (2, 3) need no grant lookup** — matching PR #116's
own allocator design, the common case (`$withinFreeLimit`) short-circuits
the query. Only when `$nextAttemptNumber` is past the free limit does the
`assessment_retest_grants` lookup run at all, so the cost of this change is
one extra indexed lookup only on the already-rare gated-retest path.

### What "belum dipakai" (not yet used) means concretely

Lead's phrasing maps directly to `status = 'active'` — PR #116's own
migration defines exactly three states (`active`/`consumed`/`revoked`) and
the partial unique index enforces at most one active grant per
`(participant_id, test_type, attempt_number)`. "Active and matching this
exact next attempt number" is the correct and only check; there is no
separate "used" boolean to test.

## Q3 — The precise condition for `resolveSelectedParticipantForUpdate()`

### The invariant that's false for retests, traced exactly

`resolveSelectedParticipantForUpdate()` (line 145) rejects outright when
`$candidate->retestCandidate`. Tracing why, rather than assuming: the
reject is not really about `retestCandidate` itself — it's a fail-fast
ahead of `assertSelectedEntitlementAndHistory()`'s non-replay branch
(lines 298–303), which unconditionally requires:

```php
if (! $candidate->eligibleForAllocation || $candidate->sessionPublicId !== null
    || $candidate->sessionStatus !== null || $entitlement->status !== 'ready'
    || $entitlement->started_at !== null || $entitlement->completed_at !== null
    || $sessions->isNotEmpty()) {
    $this->reject();
}
```

Two conditions in that branch are invariants that hold for a genuinely
**first** attempt but are **definitionally false** for any retest, by
design, not by bug:

- `$entitlement->started_at !== null` — true for a retest's entitlement
  row, because attempt 1 already set it (and per Q1, resetting it only
  happens later, at allocation time inside `AllocateAndStartAssessmentSession`,
  deliberately after this resolver has already authorized the attempt —
  see "ordering" below for why that order is required, not incidental).
- `$sessions->isNotEmpty()` — true for any retest by definition; a retest
  cannot exist without at least one prior terminal session row.

Both exist to guarantee "this is a fresh grant nobody has touched yet,"
which is exactly the right guarantee for a first attempt and exactly the
wrong one to apply unmodified to a retest. It is **not** safe to just
delete these two conditions for every caller — a genuinely fresh
first-attempt candidate must still be rejected if its entitlement is
somehow already started or already has session history (that would
indicate real corruption or a race). The fix is a **parallel branch**,
gated on `retestCandidate`, that asserts the *retest-shape* invariants
instead of the *first-attempt-shape* invariants:

```php
if ($candidate->retestCandidate) {
    $nextAttemptNumber = $sessions->count() + 1;
    $withinFreeLimit = $nextAttemptNumber <= (int) config('assessment_retests.free_attempt_limit', 3);
    $hasActiveGrant = DB::table('assessment_retest_grants')
        ->where('participant_id', $participant->id)
        ->where('test_type', $instrument->value)
        ->where('attempt_number', $nextAttemptNumber)
        ->where('status', 'active')
        ->lockForUpdate()
        ->exists();

    if (! $candidate->eligibleForAllocation || $candidate->sessionPublicId !== null
        || $candidate->sessionStatus !== null || $sessions->isEmpty()
        || ! $this->latestSessionIsTerminal($sessions)
        || (! $withinFreeLimit && ! $hasActiveGrant)) {
        $this->reject();
    }

    return;
}
```

This re-derives (does not trust) the free-limit/grant check the projection
already computed — `assertSelectedCandidateStillCanonical()` re-projects
and compares via `sameCandidate()` immediately before this method runs, so
in practice the two will already agree, but `assertSelectedEntitlementAndHistory()`
holds its own fresh locks and has always re-verified independently rather
than trusting the candidate object's fields at face value (see: it
re-fetches and re-locks `$entitlement`, `$sessions`, the order/selection
row, all over again even though the candidate was already projected once).
This branch follows that existing pattern rather than deviating from it.

Note this branch deliberately asserts **nothing** about
`$entitlement->started_at`/`$entitlement->status` in either direction —
neither requiring them null (that's the first-attempt branch's job) nor
pinning them to the specific prior value. Q1's trace of
`consumeSourceGrant()` established that a retest's entitlement is always
found at exactly `status = 'in_progress', started_at = <attempt-1 time>,
completed_at = NULL` at this point, but *asserting* that here would
duplicate a check `reopenEntitlementForRetest()` already makes explicit
and load-bearing (Q1) — this resolver's job is authorizing the attempt,
not validating the entitlement's exact prior state twice over in two
different classes. If the entitlement is ever found in some other,
unexpected state, `reopenEntitlementForRetest()`'s own `$updated !== 1`
guard is what catches it, one call later in the same transaction.

### Ordering: why the resolver must authorize *before* the entitlement is reset

`CaseAuthorizationResolver` runs to produce a `CaseAuthorization` that
`AllocateAndStartAssessmentSession` then consumes. If the entitlement were
reset earlier (e.g. inside the resolver itself, or inside
`ParticipantAssessmentSessionCandidates`), a participant could cause the
entitlement to flip back to `'ready'`/un-started state via a mere
selection/authorization call that never actually results in a started
session — reopening state on a read path, exactly the failure mode Q1's
"rejected alternative" section already ruled out. Keeping the reset inside
`allocateNew()`, strictly after this resolver has already returned a
`CaseAuthorization`, means the entitlement only ever flips back to
reusable state at the one point that's about to actually consume it in the
same transaction.

### `readyEntitlement()` is a first-attempt-only helper — retests need a separate lookup, not a loosened one

`resolveDirect()`/`resolveLegacy()` (the **unselected** resolve paths, used
when there's exactly one case and no explicit candidate was chosen) call
`readyEntitlement()`, which hard-requires `whereNull('started_at')->whereNull('completed_at')`.
This method is reached only when `ParticipantAssessmentSessionCandidates`
was never consulted at all — i.e., today's single-case, first-attempt-only
flow. Loosening `readyEntitlement()` itself would silently let a
same-instrument retest through the **unselected** path without ever
checking `assessment_retest_grants` or the free-attempt limit — the
opposite of what item 19 requires. `readyEntitlement()` must stay
first-attempt-only, unchanged. A retest can only ever be reached through
`resolveSelectedParticipantForUpdate()` (the selected path, which is where
`retestCandidate` lives) — the plan does not add any retest awareness to
`resolveDirect()`/`resolveLegacy()`.

This has a real consequence: **a retest is only reachable if the
participant's session-selection call site actually goes through
`ParticipantAssessmentSessionCandidates`/`AssessmentSessionSelectionPolicy`
first**, even for a participant with only one case. Lead required this
traced to a definite conclusion rather than left open, so:

**Confirmed: yes, every real participant automatically goes through the
selection path — no additional wiring needed anywhere.** Traced the actual
production entry point:

- `routes/api.php:53` wires the only production route for starting a
  generic-instrument session, `POST /sessions/{testType}/start`, to
  `StartParticipantSessionController`.
- That controller calls
  `App\Actions\AssessmentSessions\StartParticipantAssessmentSession::execute()`
  (`app/Actions/AssessmentSessions/StartParticipantAssessmentSession.php`),
  whose `withinTransaction()` (lines 62-97) **unconditionally** calls
  `$this->candidates->project(...)` → `$this->selectionPolicy->select(...)`
  → `$this->authorizations->resolveSelectedParticipantForUpdate(...)` —
  every single time, for every participant, whether they have one case or
  several. There is no branch that skips selection for the single-case
  case.
- Grepping `app/` for callers of `resolveParticipantForUpdate()` (the
  unselected method that internally reaches `readyEntitlement()`) outside
  `CaseAuthorizationResolver` itself, and for any controller under
  `app/Http` referencing `CaseAuthorizationResolver` directly, both return
  **zero matches**. `resolveParticipantForUpdate()`/`resolveDirect()`/`resolveLegacy()`/`readyEntitlement()`
  are exercised only by tests today — they are dead code on every
  production path, not a live alternate route this plan needs to also
  reach.

So the design in Q2/Q3 is complete as scoped: once
`ParticipantAssessmentSessionCandidates::candidate()` reports
`retestCandidate: true` for a single-case participant whose one entitlement
is terminal, `StartParticipantAssessmentSession` will reach it exactly the
same way it reaches any multi-case participant, with no further wiring.
`readyEntitlement()` staying first-attempt-only (deliberately not made
retest-aware, per the reasoning above) is therefore safe to leave exactly
as designed — it has no production caller to leave behind.

## Does `AssessmentSessionSelectionPolicy` need changes?

**No — confirmed by reading it in full, not assumed.** `select()` filters
candidates into `$live` (via `isLiveReplay()`) and `$eligible` (via
`hasEligibleDurableSourceGrant()`), both of which are generic boolean
predicates on the candidate's own fields. A `retestCandidate=true,
eligibleForAllocation=true, sessionPublicId=null, sessionStatus=null`
candidate satisfies `hasEligibleDurableSourceGrant()` exactly the same way
a first-attempt candidate does, and `retestCandidate` itself is never read
by this policy — it's carried through only for `sameCandidate()`'s
freshness comparison in the resolver (Q3). This policy is retest-agnostic
by construction; it does not need modification for this follow-up.

## What still needs a decision, not covered here

All three items Lead flagged in the prior revision (exact post-attempt-1
entitlement status, whether the production entry point auto-reaches
selection, and whether the `count() > 1` guard also gates corruption) are
now resolved above with evidence, not left open. One smaller item remains,
genuinely out of this plan's three-question scope:

- **A shared terminal-status helper.** The non-terminal/terminal status
  check now appears in three places once implemented: this plan's
  `candidate()` history guard, its `retestCandidate` branch, and
  `AssessmentAttemptAllocationPolicyTest::consumedStatuses()`'s existing
  enum membership list from PR #116. Should be a single named method
  (e.g. `AssessmentSessionStatus::isTerminal()` on the enum itself) rather
  than duplicating the four-value list in multiple files — a
  implementation-time cleanup, not a design decision.

## Test impact (designed, not written)

- `ParticipantAssessmentSessionCandidates`: new coverage for the
  terminal-with-free-limit-remaining case (`retestCandidate: true,
  eligibleForAllocation: true`, both session fields null), terminal
  with an active grant for the exact next attempt, terminal with no grant
  and past the free limit (falls through to today's dead-end shape), and
  the removed `count() > 1` throw replaced by a real multi-row read
  (2 and 3 prior terminal rows, not just 1).
- `CaseAuthorizationResolver`: new coverage for
  `assertSelectedEntitlementAndHistory()`'s new branch — accepts a retest
  candidate within the free limit, accepts one with a matching active
  grant, rejects one past the limit with no grant, rejects one whose
  `assessment_retest_grants` row exists but for the wrong attempt number
  or wrong test_type (cross-instrument leakage check).
- `AllocateAndStartAssessmentSession`: coverage that `reopenEntitlementForRetest()`
  only fires when `attemptNumber > 1` and only after a `CaseAuthorization`
  was already granted, plus a Postgres-level test that the reset and
  `consumeSourceGrant()`'s subsequent write land in the same transaction
  (no window where the entitlement is `ready`-but-unconsumed if the request
  fails between the two statements).

## Nothing changed yet

This document is design only, per Lead's "rencana dulu sebelum kode." No
production file listed above has been modified. PR #116 remains open as a
draft with its existing caveat unchanged until this plan is reviewed and
implemented as a further increment on top of it.
