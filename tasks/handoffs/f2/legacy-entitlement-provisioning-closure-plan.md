# F2 — Legacy entitlement provisioning: SPEC.md:259 gap and closure plan (2026-09-22)

**Status: parked.** Blocked on the project owner's "dana talang" (funding-bridge)
decision and on a fact from Lead: whether any organization already uses the
legacy integration API in production. No code in this PR — plan only.

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

## Reachability (code-level facts; production state unconfirmed)

- `POST /api/integrations/v1/selection/participants` (→ `ProvisionSelectionParticipant`):
  off by default (`SELECTION_INTEGRATION_ENABLED` defaults to `false`), live if
  an operator has set that env var and configured credentials.
- `POST /api/integrations/v1/assessments/participants` (→ `ProvisionAssessmentParticipant`):
  **no global kill switch**. Live for any `IntegrationClient` row with
  `enabled=true` whose organization has not yet been given a `checkout-v2`
  `IntegrationSource` row (`CheckoutContractAdapter::assertLegacyAllowed`
  closes this path automatically, organization by organization, once that
  migration happens — until then it stays open).
- Neither fact (which orgs are on `checkout-v2`, whether either route is
  actually being called in production today) is readable from source; it's a
  question for Lead/the owner, forwarded separately.

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

## Lead's direction (2026-09-22, not final)

- **Direction A applies now, in principle**, for `COMMERCIAL_SELF_PAY` and any
  money-verified mode: entitlement `locked`, then the normal (a)/(b) paths.
  Not implemented in this PR (plan only) — implementation is unblocked once
  the parked items below resolve, since the two questions are independent
  (Direction A doesn't depend on the funding-bridge answer), but landing them
  together avoids touching this action twice.
- **Sponsored/free/no-payment modes are explicitly parked** until the owner
  answers the funding-bridge question, so this plan does not design that half
  at all.

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

## Explicitly not designed at all

"Dana talang" / funding-bridge: searched the entire repository (code, config,
tests, docs, task notes) for any trace — none exists. Nothing here assumes or
designs toward that feature; it is the owner's open decision, referenced only
as the reason Direction B is parked.

## Unblocking this plan

1. Owner's answer on funding-bridge (dana talang) — determines whether
   sponsored/free-mode entitlement activation shares one mechanism with a
   future funding-bridge feature, or needs its own.
2. Fact check (forwarded to the owner by Lead): does any organization already
   use the legacy integration API in production today? This affects how
   urgently, and how carefully (backward-compatibility-wise), Direction A
   needs to land once unblocked.
