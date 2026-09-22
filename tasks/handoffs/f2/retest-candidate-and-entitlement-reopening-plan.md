# F2 — Retest candidate eligibility and entitlement reopening (follow-up to item 19) plan (2026-09-22)

**Status: plan only, no code.** Follow-up to PR #116
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
`attemptNumber > 1`. Concretely: add a private `reopenEntitlementForRetest(int $entitlementId): void`
that runs `UPDATE entitlements SET status = 'ready', started_at = NULL,
completed_at = NULL WHERE id = ? AND status <> 'ready'` under the same
`lockForUpdate()` the method already takes on the entitlement row, then let
`consumeSourceGrant()` run completely unchanged — its existing
`WHERE status = 'ready' AND started_at IS NULL AND completed_at IS NULL`
clause is satisfied again once the reset has run in the same transaction.
This keeps the change to one additional call in one method; no new method
on `CaseAuthorizationResolver` reads or requires the entitlement to already
be reset before allocation is attempted, and no reset happens speculatively
for a candidate that never gets to allocation (e.g. it's rejected for
insufficient authorization).

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

When the latest row is **terminal** (`submitted`/`scored`/`expired`/`voided`
— i.e. not `created`/`in_progress`), this is new: today `candidate()` never
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

Note this branch **does not** assert `$entitlement->started_at === null`
— for a retest, the entitlement's prior `started_at` from attempt 1 is
expected and correct, not a corruption signal. It does still assert
`$entitlement->status !== 'ready'` is **not** required either way — the
row's `status` after a terminal attempt 1 could be `'in_progress'` (if
`consumeSourceGrant()` never advances status past what it set — needs
confirming against that method's exact write, flagged below) or whatever
value that method leaves behind; the branch deliberately does not pin an
exact prior status because Q1's reset is what normalizes it before
allocation, not this resolver.

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

This has a real consequence worth flagging to Lead rather than deciding
silently: **it means a retest is only reachable if the participant's
session-selection call site actually goes through `ParticipantAssessmentSessionCandidates`/`AssessmentSessionSelectionPolicy`
first**, even for a participant with only one case. Confirming that the
production entry point (participant lobby → start-session flow) always
takes the selection path rather than ever calling `resolveParticipantForUpdate()`
directly for a single-case participant is **out of this plan's research
scope** — flagged as a verification step for implementation time, not
assumed here.

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

- **Exact prior `status` value `consumeSourceGrant()` leaves on the
  entitlement row after attempt 1**, to confirm Q3's branch doesn't need
  to assert a specific value. Needs one read of
  `AllocateAndStartAssessmentSession::consumeSourceGrant()`'s exact
  `UPDATE`/`update()` call at implementation time (not re-derived here to
  keep this plan scoped to the three questions Lead asked).
- **Whether the production single-case entry point ever calls
  `resolveParticipantForUpdate()` directly** (bypassing candidate
  selection) — if it does, a retest would silently be unreachable from
  that entry point even after this plan ships, since `readyEntitlement()`
  is deliberately not touched. Needs tracing the actual controller/action
  that participants hit today.
- **`latestSessionIsTerminal()`'s exact status set** — `submitted`,
  `scored`, `expired`, `voided` per `AssessmentSessionStatus`, mirrors
  `AssessmentAttemptAllocationPolicyTest::consumedStatuses()` in PR #116;
  should reuse a shared helper rather than duplicating the enum
  membership check in two files.

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
