# F2 — Retest limit and authorization (item 19) plan (2026-09-22)

**Status: plan only, no code — revised.** Closes item 19
(`tasks/handoffs/decisions/owner-decisions-2026-09-21.md`, commit
`77ef777`), queued after #100 and the MFA plan per Lead's stated priority
order. **This revision resolves the first draft's flagged ambiguity**:
Lead confirmed, quoting the owner's decision text directly, that the first
3 attempts are automatic and need no approval from anyone — only attempt 4
and onward needs one of the three approved roles. That is a real behavior
change to the already-tested `AssessmentAttemptAllocationPolicy` (the
first draft wrongly assumed the safer, zero-change reading was correct);
see "Resolved" below for the corrected design, the exact test-by-test
impact, and the config-driven threshold Lead also asked for.

## Source of the requirement

> **19. Batas percobaan ulang tes (retest) — SELESAI**
>
> - **Batas: 3 kali percobaan per peserta di lembaga yang sama.** Dihitung
>   per kasus/lembaga seperti cara sistem bekerja sekarang, **bukan**
>   lintas lembaga — ... tanpa perlu menyimpan nomor identitas resmi
>   untuk mencocokkan orang yang sama lintas lembaga. Peserta yang pindah
>   lembaga otomatis mendapat hitungan baru, sesuai cara data peserta
>   tersimpan hari ini (baris peserta baru per lembaga).
> - **Yang boleh menyetujui percobaan ke-4 dan seterusnya:** super_admin,
>   admin aplikasi pusat (butir 17), dan psikolog. Admin cabang dan staf
>   cabang **tidak** termasuk.
>
> Konsekuensi teknis: mekanisme "izin mengulang tes"
> (`AssessmentRetestGrant`) sudah ada di kode tapi belum pernah dipakai —
> saat ini mengulang tes SELALU ditolak. Menyambungkannya perlu: ability
> baru untuk menyetujui retest, hitungan percobaan per
> `assessment_case`/lembaga, dan audit trail yang sama polanya seperti
> verifikasi pembayaran manual (aktor, alasan, waktu).

## What already exists — more than the owner's note implies

The owner's framing ("consequence for the team") undersells how much is
already built. Reading the actual code, not just the note:

- **`App\Domain\AssessmentSessions\AssessmentRetestGrant`** — a fully-typed
  value object (`grantId, authorized, auditReason, authorizedBy,
  authorizationId, attemptNumber`), already the exact shape a persisted
  grant needs to hydrate into.
- **`App\Domain\AssessmentSessions\AssessmentAttemptAllocationPolicy::decide()`**
  already accepts a `?AssessmentRetestGrant $retestGrant` parameter and its
  private `allowsRetest()` already implements real validation: the grant
  must be `authorized`, carry a non-blank `auditReason`/`authorizedBy`,
  match the requested `authorizationId` and exact next `attemptNumber`, and
  the same `authorizationId` must never have been used by a prior attempt.
  **Fully unit-tested already**
  (`tests/Unit/AssessmentSessions/AssessmentAttemptAllocationPolicyTest.php`,
  7 tests including a dedicated "valid retest grant allocates the next
  attempt" case and 6 rejection variants).
- **`docs/decisions/0031-multi-case-session-selection-boundary.md`**
  (2026-09-10, Accepted) explicitly anticipated this exact gap. Its
  "Authority still required" section lists, verbatim: *"issuer/actor,
  alasan minimum, expiry, revocation, pembayaran, dan **batas jumlah
  retest**"* as PRD decisions not yet made, and its consequences section
  states *"Retest tetap fail-closed sampai grant durable dan authority
  bisnis tersedia"* (retest stays fail-closed until a durable grant and
  business authority exist). **Item 19 is that exact missing authority.**
  ADR-0031's own migration/wiring order (step 7) explicitly defers retest
  *persistence* to "a separate slice, after its business rules are
  complete" — this plan is that slice.

**What's missing, confirmed by grep, not assumed**: no database table
persists a retest grant anywhere in this codebase today. `AssessmentRetestGrant`
is a transient DTO only. `App\Actions\AssessmentSessions\AllocateAndStartAssessmentSession::allocateNew()`
— the only production call site — **always** passes `null` for
`$retestGrant` (with an explicit comment: *"No retest grant is ever passed
from this call site (retest authority is out of scope, ADR-0031)"*),
confirming the owner's "always rejected today" claim exactly.

## Resolved: "3 kali percobaan" means the first 3 are automatic, no approval

Lead confirmed directly from the owner's decision text, which settles this
precisely (item 19, quoted verbatim): *"Yang boleh menyetujui **percobaan
ke-4 dan seterusnya**: super_admin, admin aplikasi pusat, dan psikolog."*
The literal wording — approval is for "attempt 4 and onward," not "attempt
2 and onward" — means attempts 1–3 need **no approval from anyone**.

**This is a real behavior change, not zero-change as the first draft of
this plan assumed.** `AssessmentAttemptAllocationPolicy::decide()` today
rejects *every* retest (attempt #2 onward) without a grant — confirmed by
`AssessmentAttemptAllocationPolicyTest::test_consumed_attempt_never_automatically_opens_a_new_attempt`'s
existing, currently-correct assertion that attempt #2 with no grant is
rejected. That assertion becomes **wrong** under the confirmed policy and
must change: attempt #2 (and #3) with no grant must now be **accepted**.
Only attempt #4 and beyond keeps requiring a valid grant, using the exact
validation logic that already exists and is already tested.

### The new ability is not "authorize retests," it's "authorize attempts beyond the free limit"

Renamed from the first draft's `AuthorizeRetest` to **`AdminAbility::AuthorizeRetestBeyondLimit`**
(Lead's suggested name) — precise about what it actually gates. It has no
bearing on attempts 1–3 at all; those need no ability check because they
need no admin action.

### The free-attempt threshold is config, not a constant

Same pattern as the bridge-funding plan's amount cap
(`config('bridge_funding.max_amount')`): a new `config/assessment_retests.php`,
`'free_attempt_limit' => (int) env('ASSESSMENT_RETEST_FREE_ATTEMPT_LIMIT', 3)`.
`AssessmentAttemptAllocationPolicy` itself stays a pure domain class with no
framework dependency (it is constructed bare, `new AssessmentAttemptAllocationPolicy()`,
in all 7 existing tests, and has no constructor today) — the threshold is
read from config **by the caller**
(`AllocateAndStartAssessmentSession::allocateNew()`) and passed into
`decide()` as a new, explicit parameter with a `3` default (so the three
existing tests that never exercise retest behavior at all — attempt-one
allocation, replay, and the active-attempt-exists check — need no change
merely to keep compiling). This keeps the "no thresholds hardcoded in
code" principle CLAUDE.md already states for scoring Lookup Tables, applied
here to a different kind of threshold with the same shape of risk (the
owner changing their mind about "3" later must not require a code
deploy).

### Restructured `allowsRetest()` — minimal, explained precisely

```php
private function allowsRetest(
    array $attempts,
    ?AssessmentRetestGrant $grant,
    string $authorizationId,
    int $nextAttemptNumber,
    int $freeAttemptLimit,
): bool {
    // Authorization-identity reuse is barred unconditionally -- every
    // attempt, free or gated, must run under a genuinely new
    // authorization. This part of today's logic is unchanged and stays
    // hoisted above the threshold branch so it still applies to attempts
    // 2-3 even though they no longer need a grant.
    foreach ($attempts as $attempt) {
        if ($attempt->authorizationId === $authorizationId) {
            return false;
        }
    }

    if ($nextAttemptNumber <= $freeAttemptLimit) {
        return true;
    }

    // Everything below is today's existing, already-tested grant
    // validation, completely unchanged -- only now reachable at
    // nextAttemptNumber > $freeAttemptLimit instead of at every retest.
    if ($grant === null
        || ! $grant->authorized
        || trim($grant->grantId) === ''
        || trim($grant->auditReason) === ''
        || trim($grant->authorizedBy) === ''
        || $grant->authorizationId !== $authorizationId
        || $grant->attemptNumber !== $nextAttemptNumber) {
        return false;
    }

    return true;
}
```

### Existing test impact — every one of the 7 checked individually, per Lead's explicit ask, not silently reinterpreted

| Test | Exercises retest gating? | Verdict |
|---|---|---|
| `test_first_authorized_intent_allocates_attempt_one_with_supplied_identity` | No — empty history, attempt 1 never reaches `allowsRetest()` at all (`$attempts !== []` guard) | **Unaffected**, no change |
| `test_same_intent_replays_the_same_attempt_without_persistence` | No — replay path returns before the retest-gating check | **Unaffected**, no change |
| `test_different_intent_cannot_allocate_while_an_active_attempt_exists` (2 variants) | No — the active-attempt check fires before `allowsRetest()`; this is about concurrent/duplicate allocation, not authorization | **Unaffected**, no change |
| `test_consumed_attempt_never_automatically_opens_a_new_attempt` (4 variants: submitted/scored/expired/voided) | Yes — 1 prior attempt, requests attempt #2, no grant, asserts **rejected** | **Must flip.** Attempt #2 ≤ the free limit, so this must now assert **accepted**. Renamed to `test_consumed_first_attempt_automatically_allows_the_free_retest_without_a_grant`, same 4 status variants (the terminal status of attempt #1 must not matter for whether the free retest is granted — worth keeping as its own coverage). **New test added** to replace the invariant this one used to weakly express ("you eventually do need authorization"): 3 consumed prior attempts, requesting attempt #4, no grant → still asserts **rejected** with `RetestNotAuthorized` — this is the one that actually proves the ceiling now. |
| `test_valid_retest_grant_allocates_the_next_attempt_with_new_authorization` | Yes — 1 prior attempt, valid grant for attempt #2, asserts **accepted** | **Must move to attempt #4.** At attempt #2 the supplied grant is no longer doing any real work (accepted either way now), so the test would stop proving what its name says. Rebuilt with 3 prior consumed attempts and a valid grant for attempt #4 — same assertion shape, now actually exercising grant validation. |
| `test_retest_requires_authorization_reason_actor_new_entitlement_and_exact_next_attempt` (6 invalid-grant variants: not authorized, blank reason, blank actor, reused authorization, wrong-bound authorization, wrong attempt number) | Yes — 1 prior attempt, various invalid grants, asserts **rejected** | **5 of 6 must move to attempt #4** (not authorized, blank reason, blank actor, wrong-bound authorization, wrong attempt number) — left at attempt #2 they'd all flip to accepted regardless of grant validity, silently deleting this coverage rather than preserving it. **The "reused entitlement authorization" variant is different**: it's actually testing the universal reuse-check (hoisted above the threshold branch above), which now applies at *every* attempt number, free or gated — this one can stay at attempt #2 unchanged, since reuse must still be rejected there too, or move to attempt #4 for consistency with its siblings. Recommend moving all 6 together for one coherent data provider, simplest to read. |

No test's *meaning* is deleted — each rejection case that genuinely tests
grant validation moves to the attempt number where grant validation is
actually invoked; the one case that tested reuse-prevention specifically
is confirmed to still hold at any attempt number under the restructured
function above.

### How does the approving admin know which attempt number they're authorizing?

Lead's explicit question. The granting action (`AuthorizeRetestBeyondLimit`,
renamed to match the ability — see below) computes the participant's
attempt history the same way the allocator does (identical `test_sessions`
query shape), and its response/confirmation step surfaces that count
explicitly — e.g. *"Peserta ini telah mengikuti tes 3 kali. Anda akan
mengizinkan percobaan ke-4."* — matching `VerifyManualTransfer`'s existing
pattern of surfacing the specific order/proof being acted on rather than a
blind approve button. The action also **refuses outright** (a clear
"tidak perlu izin" response, not a silently-created moot grant row) if
`nextAttemptNumber <= freeAttemptLimit` when invoked — an approving admin
can never end up authorizing an attempt that didn't need authorization in
the first place, which doubles as confirmation they're always looking at
attempt 4+ when the action succeeds.

## New table: a durable place for the grant to live

`AssessmentRetestGrant` is a DTO, not a persistence model — something has
to create, store, and look up real grants for
`AllocateAndStartAssessmentSession::allocateNew()` to pass into `decide()`.
Proposed `assessment_retest_grants` table, modeled directly on the DTO's
own fields plus the audit-trail columns the owner asked for (mirrors the
existing `verified_by_admin_id`/`verified_at` pairing pattern from
`orders`, same as bridge funding's `bridge_funding_grants` reused it):

- `id`, `public_id` (ulid) — becomes `AssessmentRetestGrant::$grantId`.
- `participant_id` (FK), `test_type` (string, matches `entitlements.test_type`'s
  constraint list), `assessment_case_id` (nullable FK — multi-case isn't
  active yet per ADR-0031, but the column exists on `test_sessions`
  already; carrying it here now avoids a second migration once multi-case
  wiring lands).
- `attempt_number` (unsigned int, the exact attempt this grant authorizes
  — matches `AssessmentRetestGrant::$attemptNumber`).
- `authorization_id` (string, matches `AssessmentRetestGrant::$authorizationId`
  — the new authorization identity this attempt will run under).
- `reason` (text, NOT NULL, non-blank CHECK — `AssessmentRetestGrant::$auditReason`).
- `approved_by_admin_id` (FK admins, NOT NULL) + `approved_at` (NOT NULL) —
  `AssessmentRetestGrant::$authorizedBy` is a *string* in the DTO (an
  opaque actor identifier, e.g. `"admin:{id}"`), constructed from this
  column at lookup time, not stored redundantly as a string.
- `status` (`'active'`/`'consumed'`/`'revoked'`, default `'active'`) +
  `consumed_at` (nullable) — a grant is consumed the moment the retest it
  authorizes actually starts, so it can never be reused for a *different*
  attempt (the DTO's own `authorizationId`/`attemptNumber` matching already
  prevents reuse at the domain layer; `status` makes that observable at
  the persistence layer too, for the admin-facing history view).
- Unique constraint on `(participant_id, test_type, attempt_number)` where
  `status = 'active'` (partial index, Postgres) — at most one active grant
  per attempt slot, same partial-unique-index pattern
  `bridge_funding_grants` already established for exactly this "at most
  one active X" shape.
- RLS: same treatment as every other admin-approval table this session
  (`REVOKE ALL` / narrow `GRANT` / `ENABLE`+`FORCE` / service-write
  policy). Read: the three approving roles (see ability section) — this
  is case-visible-to-those-roles data, not branch-admin/staff-visible.

## New ability

`AdminAbility::AuthorizeRetestBeyondLimit` (Lead's suggested name — precise
that this gates attempts past the free limit, not retests in general).
`Admin::canPerform()`:

```php
AdminAbility::AuthorizeRetestBeyondLimit => in_array(
    $this->role,
    [AdminRole::SuperAdmin, AdminRole::Psychologist], // + CentralAdmin once it exists
    true,
),
```

`central_admin` is deliberately not in the list yet — the role doesn't
exist (`tasks/handoffs/f2/central-admin-role-plan.md`, still its own
pending plan). Extends with a one-line addition once it lands, same
extensibility pattern already used for `ApproveBridgeFunding`,
`RlsContext::ROLES`, and `RlsContextRunner::ADMIN_ROLES`. Not reusing an
existing ability (e.g. `ReviewReports`, which is psychologist-only anyway
and semantically unrelated) — same separation-of-concerns reasoning
already applied twice this session (`GenerateReports` vs `ReviewReports`;
`ApproveBridgeFunding` vs `VerifyPayments`).

`branch_admin`/`staff` explicitly excluded, matching the owner's decision
verbatim ("Admin cabang dan staf cabang tidak termasuk").

## Counting attempts "per lembaga" — already free, no new mechanism needed

The owner's "dihitung per kasus/lembaga... peserta yang pindah lembaga
otomatis mendapat hitungan baru" requirement is **already exactly what the
existing code computes**, with no new counting infrastructure needed:
`AllocateAndStartAssessmentSession::lockHistory()` already queries
`test_sessions` scoped to `participant_id + test_type [+ assessment_case_id]`,
and every `participants` row already belongs to exactly one `branch_id`
(confirmed: a participant who registers at a different institution gets an
entirely new `participants` row, not a shared one — this is how the schema
already works, not a new design). Counting "attempts at this institution"
*is* counting "attempts for this `participant_id`" — no join to `branches`
needed, no new column needed. The granting action (below) reuses this
exact same history query to compute the next attempt number and enforce
the ≤3 ceiling.

## New action: `AuthorizeRetestBeyondLimit` (matches the ability name)

Mirrors `VerifyManualTransfer`'s shape (the pattern the owner explicitly
asked to match): `lockForUpdate()` on the relevant `test_sessions` history
rows (already locked by `AllocateAndStartAssessmentSession::lockHistory()`
today — this action needs its own equivalent lock, since it runs as a
separate request, not inside the allocator's own transaction), compute the
next attempt number the same way the allocator does, and:

- If `nextAttemptNumber <= config('assessment_retests.free_attempt_limit')`:
  refuse with a clear "this attempt does not need authorization" response
  — never silently create a moot grant row (see "how does the approving
  admin know" above).
- Otherwise (attempt 4, 5, 6...): `insertOrIgnore()` the grant row keyed by the same partial-unique
  index the migration adds (matching the `GrantBridgeFunding`/F5
  `insertOrIgnore()` pattern established this session — real risk here
  too: a grant read and written under a service-elevated context inside
  `runAsService()`, so the same PostgreSQL 25P02-on-QueryException trap
  applies), write one `audit_logs` row (actor, reason, timestamp — same
  shape as `VerifyManualTransfer`/`GrantBridgeFunding`).

`Gate::forUser($admin)->authorize('authorizeRetestBeyondLimit', ...)` needs
a policy method; likely on a new lightweight policy scoped to `Participant`
or `AssessmentCase` (whichever the eventual UI surface uses — see below),
following `OrderPolicy`'s existing two-line shape (`canPerform()` check +
branch-ownership check, though branch-ownership is moot here since only
`super_admin`/`psychologist`/eventually `central_admin` can reach this
ability at all, and none of those three roles are branch-scoped).

## Wiring the grant back into the allocator

`AllocateAndStartAssessmentSession::allocateNew()` currently always passes
`null` (line ~152) and never reads the free-attempt limit at all. Needs:

- Read `$freeAttemptLimit = (int) config('assessment_retests.free_attempt_limit', 3)`
  and pass it as `decide()`'s new parameter — always, not just on retests,
  since `decide()` needs it to know whether the *current* request even
  requires a grant.
- A grant lookup, but **only when `$nextAttemptNumber > $freeAttemptLimit`**
  (not "whenever `$attempts !== []`" as the first draft of this plan said —
  attempts within the free limit need no lookup at all, the common case
  stays a single cheap history query with no extra round trip): look up an
  `active` `assessment_retest_grants` row for `(participant_id, test_type,
  attempt_number = nextAttemptNumber)`, hydrate it into a real
  `AssessmentRetestGrant`, and pass that instead of `null`.
- On successful allocation *of a gated attempt* (i.e. only when a grant was
  actually looked up and used), mark that grant `consumed` in the same
  transaction (the allocator already holds the relevant locks). Attempts
  within the free limit never touch `assessment_retest_grants` at all, so
  there is nothing to mark consumed for them.

The class's own docblock ("This unwired slice ... deliberately does not
authorize retests") and the inline comment at the `null` argument both need
updating to reflect that this is no longer true once this ships — not
treated as optional documentation cleanup, since a future reader relying on
that comment would be reading stale intent.

## UI/endpoint surface — left to implementation-time judgment, not decided here

No existing Filament resource currently shows `test_sessions`/attempt
history for a `DIRECT_PUBLIC`/generic-instrument participant (confirmed:
`AssessmentParticipantResource` maps to the *checkout-v2* population,
`assessment_participants`, a different table entirely). Bridge funding
(#100/PR #112) shipped the same way — action class + policy, no UI wired
yet — so this plan follows the same precedent rather than inventing a new
one: ship the action + policy first, decide the concrete trigger (a new
Filament page/resource for case-level admin actions, or a small dedicated
one-off page) at implementation time, matching whatever shape is most
consistent with the codebase by then.

## Explicitly not designed here

- **Whether there is any hard ceiling past attempt 4** — the owner's
  decision states who may approve "attempt 4 and onward" but never states
  whether onward is unbounded. As designed, `AuthorizeRetestBeyondLimit`
  can keep authorizing attempt 5, 6, 7... indefinitely, each individually,
  by any of the three approved roles, with no system-enforced upper bound.
  If the owner intends a true hard cap (e.g. "5 total, full stop"), that
  is a second, separate config value this plan does not add — flagging
  rather than assuming silence means "unbounded."
- **Whether a revoked/expired grant needs its own admin-facing action** (a
  "cancel this retest authorization before it's used" workflow) — the
  `status` column supports it structurally, but no action is designed for
  it; `active`/`consumed` are the only states this plan's own action
  writes.
- **Multi-case retest scoping precisely** — `assessment_case_id` is carried
  on the new table for forward compatibility, but multi-case selection
  itself isn't active yet (ADR-0031's own consequences section), so this
  plan's action only ever operates against the single-case-per-instrument
  reality that exists today.
- **Tests** (designed, not written; see the per-test table above for the
  existing 7): new tests for `AuthorizeRetestBeyondLimit` (refuses when
  invoked for an attempt still within the free limit, role gating, audit
  row shape, idempotent re-authorization) and for the allocator's new
  free-vs-gated branch (attempts 2–3 start with no grant lookup at all, a
  4th attempt is rejected without one even for `super_admin`, a granted 4th
  attempt actually starts and consumes the grant); a Postgres concurrency
  test for the same insertOrIgnore-under-service-context shape
  `GrantBridgeFunding` already proved out, since the identical PostgreSQL
  transaction-poisoning risk applies here too.

## Nothing changed yet

Retests are still always rejected, exactly as today. This document is
design only, per Lead's explicit "rencana dulu."
