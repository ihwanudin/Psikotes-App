# F2 — Retest limit and authorization (item 19) plan (2026-09-22)

**Status: plan only, no code.** Closes item 19 (`tasks/handoffs/decisions/owner-decisions-2026-09-21.md`,
commit `77ef777`), queued after #100 and the MFA plan per Lead's stated
priority order.

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

## Critical ambiguity: what does "3 kali percobaan" actually gate?

The owner's decision text supports two structurally different readings,
and the existing, already-tested `AssessmentAttemptAllocationPolicy`
contract only matches one of them. **Flagging this rather than guessing —
this changes tested behavior either way.**

**Reading A (recommended — zero change to already-tested policy code)**:
every retest (attempt #2 onward) requires an admin-issued grant, exactly
matching `AssessmentAttemptAllocationPolicyTest::test_consumed_attempt_never_automatically_opens_a_new_attempt`'s
existing, locked-in assertion (attempt #2 with *no* grant is rejected,
today, on purpose). "Batas: 3 kali percobaan" is then a **hard ceiling
enforced by the granting action itself**: once a participant already has 3
total attempts (original + 2 retests) at an institution, the granting
action refuses to issue a 4th grant *to anyone*, including the three
approved roles. "Yang boleh menyetujui percobaan ke-4 dan seterusnya" reads
as "who may approve a retest at all, up to that ceiling" — beyond the
ceiling, the action itself blocks it regardless of role.

**Reading B (contradicts existing tested behavior)**: the first 3 attempts
(original + 2 retests) happen freely, with *no* admin grant needed for
attempts 2–3, and only the 4th attempt onward requires one of the three
roles' approval. This would require changing
`AssessmentAttemptAllocationPolicy::decide()`'s retest-gating logic itself
(the "no grant → reject" branch would need a `nextAttemptNumber <= 3`
carve-out) — a real behavior change to code Lead/the team already reviewed
and shipped as correct.

Reading A is recommended: it requires no change to already-tested domain
logic, and the owner's own framing ("consequence for the team: wire up the
existing mechanism") reads as *connecting* the existing all-retests-need-
approval contract, not *loosening* it. But this is a genuine fork in
product behavior (does a participant's 2nd attempt need anyone's
sign-off, or not?) that only the owner can actually resolve — **flagging
for Lead/owner confirmation before implementation, not choosing silently.**

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

`AdminAbility::AuthorizeRetest` (name illustrative). `Admin::canPerform()`:

```php
AdminAbility::AuthorizeRetest => in_array(
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

## New action: `AuthorizeAssessmentRetest` (name illustrative)

Mirrors `VerifyManualTransfer`'s shape (the pattern the owner explicitly
asked to match): `lockForUpdate()` on the relevant `test_sessions` history
rows (already locked by `AllocateAndStartAssessmentSession::lockHistory()`
today — this action needs its own equivalent lock, since it runs as a
separate request, not inside the allocator's own transaction), compute the
next attempt number, reject with a clear error if it would exceed 3
(Reading A: reject unconditionally, for any role, once already at 3),
`insertOrIgnore()` the grant row keyed by the same partial-unique index the
migration adds (matching the `GrantBridgeFunding`/F5 `insertOrIgnore()`
pattern established this session — real risk here too: a grant read and
written under a service-elevated context inside `runAsService()`, so the
same PostgreSQL 25P02-on-QueryException trap applies), write one
`audit_logs` row (actor, reason, timestamp — same shape as
`VerifyManualTransfer`/`GrantBridgeFunding`).

`Gate::forUser($admin)->authorize('authorizeRetest', ...)` needs a policy
method; likely on a new lightweight policy scoped to `Participant` or
`AssessmentCase` (whichever the eventual UI surface uses — see below),
following `OrderPolicy`'s existing two-line shape (`canPerform()` check +
branch-ownership check, though branch-ownership is moot here since only
`super_admin`/`psychologist`/eventually `central_admin` can reach this
ability at all, and none of those three roles are branch-scoped).

## Wiring the grant back into the allocator

`AllocateAndStartAssessmentSession::allocateNew()` currently always passes
`null` (line ~152). Needs a grant lookup added there: when
`$attempts !== []` (a retest is being attempted), look up an `active`
`assessment_retest_grants` row for `(participant_id, test_type,
attempt_number = nextAttemptNumber)`, hydrate it into a real
`AssessmentRetestGrant`, and pass that instead of `null`. On successful
allocation, mark the grant `consumed` in the same transaction (the
allocator already holds the relevant locks). The class's own docblock
("This unwired slice ... deliberately does not authorize retests") and the
inline comment at the `null` argument both need updating to reflect that
this is no longer true once this ships — not treated as optional
documentation cleanup, since a future reader relying on that comment would
be reading stale intent.

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

- **Reading A vs Reading B** (above) — needs an explicit owner/Lead answer
  before implementation starts, not assumed.
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
- **Tests** (designed, not written): the existing 7
  `AssessmentAttemptAllocationPolicyTest` cases stay green unchanged
  (Reading A requires no change to that class at all); new tests for
  `AuthorizeAssessmentRetest` (ceiling enforcement, role gating, audit row
  shape, idempotent re-authorization) and for the allocator's new grant
  lookup (a real retest actually starts once granted, a 4th attempt is
  rejected even for `super_admin`); a Postgres concurrency test for the
  same insertOrIgnore-under-service-context shape `GrantBridgeFunding`
  already proved out, since the identical PostgreSQL transaction-poisoning
  risk applies here too.

## Nothing changed yet

Retests are still always rejected, exactly as today. This document is
design only, per Lead's explicit "rencana dulu."
