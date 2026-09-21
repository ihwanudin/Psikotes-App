# F2 — Legacy entitlement provisioning: SPEC.md:259 gap and closure plan (2026-09-22)

**Status: unblocked, revised.** The owner has now answered both blocking
questions (item 18, PR #81 `b7ffd0a`/`77ef777`): the system is not live yet
(no organization uses any production integration, including the legacy API),
and the "dana talang" (bridge funding) direction is decided. Direction A
applies in full, immediately. Direction B is now designed below. Still no
code in this PR — plan only, revised per Lead's request before implementation.

## The gap

`SPEC.md:259` (literal): "Aturan gating akses tes (WAJIB): `POST /sesi/{tipe}/start`
menolak (403) bila entitlement peserta untuk tipe tsb bukan `ready`. Entitlement
berubah `locked→ready` HANYA oleh: (a) webhook Xendit status PAID/SETTLED
terverifikasi, atau (b) verifikasi transfer manual oleh admin, atau (c) aktivasi
manual (super_admin, teraudit). Tak ada jalur lain."

Two legacy integration-provisioning actions violate this directly, not just in
theory:

- `app/Actions/Integrations/ProvisionAssessmentParticipant.php:120-127` — one
  unconditional `foreach`, no branch on funding mode at all. Every `$testType`
  gets `order_id => null, status => 'ready'`. `fundingMode` (validated against
  an organization allow-list at lines 72-76, which can include
  `COMMERCIAL_SELF_PAY`) is recorded on the participant mapping row but never
  consulted when creating entitlements.
- `app/Actions/Integrations/ProvisionSelectionParticipant.php:146-157` — no
  payer/funding-mode concept in its input schema at all. Every participant
  provisioned through this legacy selection endpoint gets `ready`/`order_id=null`
  entitlements for every configured test type.

This is not inert data. `App\Services\AssessmentSessions\CaseAuthorizationResolver::resolveLegacy`
(lines 421-449) and its `readyEntitlement()` helper (lines 451-460) actively
search for an `Entitlement` with `status='ready' AND order_id IS NULL` and use
it to authorize starting a real, scored assessment session. The structurally
parallel `resolveIntegratedForUpdate` path (for participants provisioned via
`ProvisionAssessmentParticipant`) accepts a `ready` entitlement the same way,
regardless of how it was funded.

## Reachability — confirmed, no longer a hedge

- `POST /api/integrations/v1/selection/participants` (→ `ProvisionSelectionParticipant`):
  off by default (`SELECTION_INTEGRATION_ENABLED` defaults to `false`), live if
  an operator has set that env var and configured credentials.
- `POST /api/integrations/v1/assessments/participants` (→ `ProvisionAssessmentParticipant`):
  **no global kill switch**. Live for any `IntegrationClient` row with
  `enabled=true` whose organization has not yet been given a `checkout-v2`
  `IntegrationSource` row (`CheckoutContractAdapter::assertLegacyAllowed`
  closes this path automatically, organization by organization, once that
  migration happens — until then it stays open).
- **Owner confirmed (item 18, PR #81 `b7ffd0a`): the system is not live.**
  No organization uses any production integration today, including this
  legacy API. There is no active client to disrupt — Direction A can land
  with no transition period, no backward-compatibility shim, no phased
  rollout by organization.

## Clause (c) has no existing implementation

Searched for a super-admin manual entitlement-activation action; found none
(the only "manual activation" hit, `SetPaymentMethodActivation`, activates a
*payment method*, not an entitlement). SPEC.md's clause (c) is currently
written intent, not a real code path.

## Two closure directions considered

**Direction A — reject money-verified modes at provisioning time.** For
`COMMERCIAL_SELF_PAY` and any other mode where real money must be verified,
create the entitlement `locked` instead of `ready`, and let it proceed through
the normal (a)/(b) paths (Xendit webhook / admin-verified manual transfer)
exactly like public registration does. SPEC is explicit here — this is not an
interpretation call.

**Direction B — leave sponsored/free modes open for later, unified design.**
For modes with no participant payment at all (`SPONSORED`/`INTERNAL`/`WAIVED`,
and the scholarship-only `ProvisionSelectionParticipant` integration), do not
decide now. These modes are asking the same question as the "dana talang"
proposal the owner is weighing: access without payment, after some approval,
with an audited trail. Both will very likely resolve to the *same* mechanism —
clause (c), audited manual activation, whether per-participant or
per-integration. Designing a separate mechanism for these modes now, ahead of
that decision, risks two colliding paths later.

## Direction A — final, ready for implementation

For `COMMERCIAL_SELF_PAY` and any other mode where real money must be
verified: `ProvisionAssessmentParticipant`/`ProvisionSelectionParticipant`
create the entitlement `locked`, not `ready`, and it proceeds through the
normal (a)/(b) paths (Xendit webhook / admin-verified manual transfer)
exactly like public registration does. SPEC is explicit here — not an
interpretation call, and per the reachability confirmation above, no
transition period or compatibility shim is needed. Nothing else about this
direction changed from the original plan; it's unblocked, not redesigned.

## Direction B — bridge funding ("dana talang") design, now unblocked

Owner's decision (item 18, PR #81 `b7ffd0a`, literal): the **holding
company** (ONCAM's parent) funds it, not the LPK and not ONCAM itself. An
**admin approves**, acting on management instruction — the approval must
record which admin and a reference to that instruction (a letter/instruction
number or similar), not a bare approve button with no trail. The
**participant experience must be identical** to a paying participant — no
visible special status. After approval, the system issues a **billing
invoice** to collect later; that billing history must not be lost or
overwritten. **Mandatory at go-live.** A per-participant/per-branch amount
cap is explicitly undecided by the owner — design with an optional,
config-driven cap, unlimited by default, addable later without a schema
change.

The owner separately confirmed this and SPEC.md:259 clause (c) ("aktivasi
manual, super_admin, teraudit") are **one mechanism**, not two — this
section designs both together.

### Critical finding: this does NOT fit the null-`order_id` pattern the
### earlier draft of this plan assumed

The earlier version of this plan (and Lead's framing) drew on
`CaseAuthorizationResolver::resolveLegacy`'s pattern — `ready`, `order_id
IS NULL` — as the shape a manually-activated entitlement should take.
That pattern is real, but it belongs to `resolveLegacy`, the resolver for
`source_system = 'SELEKSI_BEASISWA_JEPANG'` participants only (the legacy
scholarship integration). Bridge funding's actual population is normal
`DIRECT_PUBLIC` participants — people who registered through the ordinary
public `/register` flow, who would ordinarily pay themselves, for whom the
holding company is now covering the cost. That population is authorized by
a **different** method, `resolveDirect()`
(`CaseAuthorizationResolver.php:377-419`), which requires:

```php
$order = $this->one(Order::query()->where('participant_id', $participant->id)...);
$grant = $this->readyEntitlement($participant->id, $instrument, $order->id);
// ...
if ($entitlements->contains(fn (Entitlement $row): bool => $row->order_id !== $order->id)
    || $order->status->value !== 'paid' || $order->paid_at === null
    // ...
) { $this->reject(); }
```

`resolveDirect()` has **no path that accepts a null `order_id`** — it
requires a real `orders` row, with `status === 'paid'` and `paid_at` set,
and every entitlement's `order_id` must equal that order's id. Designing
bridge funding around a null `order_id` (the legacy pattern) would mean
either inventing a second, parallel acceptance path inside this
security-critical resolver (real risk: this is the exact function that
gates whether an assessment session can start at all), or leaving bridge-
funded participants structurally unable to start a session through the
normal flow. Neither is acceptable, and this is exactly the kind of
mismatch worth surfacing rather than quietly designing around.

### Design: extend `orders`, not bypass it

Instead, bridge funding gets a **real `orders` row**, so `resolveDirect()`
needs the smallest possible change and every existing invariant it checks
keeps holding:

- **New `OrderStatus` case**, e.g. `BridgeFunded = 'bridge_funded'`
  (`app/Enums/OrderStatus.php`, plus widening the `orders_status_check`
  CHECK constraint). Reusing `'paid'` for this would be dishonest in the
  ledger — `'paid'`/`paid_at` mean cash was actually received, which isn't
  true here (the holding company is billed *after* approval, not before).
  A distinct status keeps that distinction real and auditable.
- **`resolveDirect()`'s one status check widens** from `$order->status->value
  !== 'paid'` to accepting `'paid'` OR `'bridge_funded'` (and the
  `paid_at IS NOT NULL` check needs an equivalent for the new status — an
  `approved_at`-equivalent timestamp, see below). This is the one, minimal,
  reviewed change to session-start authorization this design requires —
  everything else routes around it, not through it.
- **`payment_method_id` (NOT NULL FK, no schema change needed)**: a
  synthetic, non-selectable `payment_methods` row (e.g. `code =
  'bridge_funding'`, `is_active = false` so it never appears in the public
  registration payment-method list, which is already filtered to `active()`
  — confirmed in `ParticipantRegistrationController`). Reuses the existing
  mechanism instead of loosening a NOT NULL column.
- **The actual invoice-to-collect-later**: a linked `assessment_bills` row,
  which already has almost the exact right shape for "an amount owed,
  tracked against an organization/participant, with an admin-verification
  trail" — `verified_by_admin_id`/`verified_at` (existing columns) *are*
  the approving-admin trail; add a new `payer_type` value (e.g.
  `'bridge_funding'`, alongside the existing `'organization'`/`'self'`,
  requires widening `assessment_bill_payer_check`). This is reuse, not a
  new table, per the explicit ask — the two existing tables (`orders` for
  the access-gating side `resolveDirect()` checks, `assessment_bills` for
  the actual billing-history side) already model the two genuinely
  distinct concerns here; a new table would duplicate one or the other.
- **Management-reference field (new, required)**: neither table has a
  free-text/structured "which instruction authorized this" column today.
  Add one nullable-except-for-this-payer-type text column to
  `assessment_bills` (e.g. `management_reference`), required by a CHECK
  constraint when `payer_type = 'bridge_funding'`, mirroring the existing
  `assessment_bill_payer_check` pattern of a status/type-conditional
  constraint. Also recorded in the `audit_logs` row for the approval
  action (`context`), matching the existing manual-payment-verification
  audit shape (actor, reason/reference, timestamp — same pattern as
  `VerifyManualTransfer`/`SetPaymentMethodActivation`).
- **Participant invisibility**: needs one explicit check at implementation
  time, not assumed — confirm no participant-facing code (session-start
  responses, receipts, the registration-received page) branches on
  `order.status === 'paid'` specifically in a way that would visibly differ
  for `'bridge_funded'`. `resolveDirect()`'s own output (a `CaseAuthorization`)
  doesn't leak order status to the participant either way, which is the
  right shape to preserve.

### Who can approve — this is my technical call, not the owner's

The owner's item 18 explicitly leaves "which admin role(s)" to Lead/the
team. Recommendation: **`super_admin` only for now**, extended to
`central_admin` automatically once that role exists (`tasks/handoffs/f2/central-admin-role-plan.md`,
still its own separate plan). Reasoning:

- Central_admin's ability matrix is now finalized (Lead, item 20) to include
  `VerifyPayments`, `EditParticipants`, `ManageTestPackages`, and read
  access to the payment-methods list — bridge-funding approval is
  conceptually adjacent to `VerifyPayments` (both are "an admin attests
  money has been handled outside the normal participant-pays flow"), so
  central_admin is a natural eventual fit, consistent with the owner
  already trusting that role with payment verification.
  - **Not reusing `VerifyPayments` itself for this**, though: a dedicated
    ability (e.g. `AdminAbility::ApproveBridgeFunding`) keeps the same
    separation-of-concerns reasoning already applied to `GenerateReports`
    vs `ReviewReports` in this codebase — widening one ability for a
    narrow need risks silently widening unrelated access later.
- Same extensibility pattern already established in the admin
  bootstrap/lifecycle commands (PR #104): gate on an explicit role list
  admins actually control (not derived from `AdminRole::cases()` blindly),
  so adding `central_admin` later is a one-line change to that list, not a
  redesign.

### Amount cap

One optional config key (e.g. `config('bridge_funding.max_amount')`,
`null`/absent = unlimited), read at approval time, not stored as a schema
constraint — the owner may set a limit later without a migration, per their
explicit request.

## Explicitly not designed here (noted only)

- Manual payment-proof uploads are overwritten with no retained history:
  `StoreManualPaymentProof.php:43-56` and `StoreAssessmentBillProof.php:206-221`
  both physically delete the prior proof object from storage once a new one is
  stored; only a checksum fingerprint guards concurrent replacement of the
  *current* value, nothing preserves the prior one.
- `orders`/`assessment_bills`/`assessment_charges` have no append-only
  enforcement (no trigger, no revoked UPDATE/DELETE grant), unlike
  `report_documents` and `test_session_grants`, which do. A service-role write
  can still mutate a row after it's been paid/verified/settled.
- `audit_logs` purge (`retention:purge-expired-audits`) exists and is unit
  tested, but is inert: `config('retention.audit_purge_enabled')` resolves to
  `null` (no `config/retention.php` file exists in this repo) so the command
  exits immediately, and it is not scheduled anywhere in `routes/console.php`.
  Corroborated in `DEPLOYMENT.md:238`: "Command ... tersedia tetapi inert dan
  tidak dijadwalkan."

## Still not designed here

- The exact `management_reference` column's format (free text vs. a
  structured reference number pattern) — the owner's decision only said
  "a reference to management's instruction," not a specific shape.
  Free text, unconstrained beyond non-blank, is the simplest default;
  revisit if the owner wants a specific format later.
- `SPONSORED`/`INTERNAL`/`WAIVED` funding modes for
  `ProvisionAssessmentParticipant`/`ProvisionSelectionParticipant`
  specifically (as opposed to the `/register` bridge-funding flow this
  section designs) — whether those legacy-integration modes should also
  route through this same bridge-funding mechanism, or stay a separate
  question, isn't decided. Worth a quick check once implementation starts,
  not designed further here.

## Status: both original blockers resolved

1. ~~Owner's answer on funding-bridge (dana talang)~~ — answered, item 18,
   designed above.
2. ~~Fact check: does any organization already use the legacy integration
   API in production today?~~ — confirmed no; the system is not live.

This plan is ready for Lead's review before implementation begins.
