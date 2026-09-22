# F2 — Legacy entitlement provisioning: SPEC.md:259 gap and closure plan (2026-09-22)

**Status: unblocked, revised twice.** The owner has now answered both
blocking questions (item 18, PR #81 `b7ffd0a`/`77ef777`): the system is not
live yet (no organization uses any production integration, including the
legacy API), and the "dana talang" (bridge funding) direction is decided.
Direction A applies in full, immediately. Direction B is now designed below.
**This revision replaces the single-site `resolveDirect()` widening from the
previous draft** after Lead's review found it dangerously incomplete — see
"Every site that checks `orders.status` against `'paid'`" below for the full
audit, and "Does `assessment_bills` actually fit?" for why the billing design
also changed. Still no code in this PR — plan only, revised per Lead's
request before implementation.

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

Bridge funding gets a **real `orders` row**, with a new `OrderStatus` case:

- **New `OrderStatus` case**, `BridgeFunded = 'bridge_funded'`
  (`app/Enums/OrderStatus.php`, plus widening the `orders_status_check`
  CHECK constraint). Reusing `'paid'` for this would be dishonest in the
  ledger — `'paid'`/`paid_at` mean cash was actually received, which isn't
  true here (the holding company is billed *after* approval, not before).
  A distinct status keeps that distinction real and auditable.
- **`payment_method_id` (NOT NULL FK, no schema change needed)**: a
  synthetic, non-selectable `payment_methods` row (e.g. `code =
  'bridge_funding'`, `is_active = false` so it never appears in the public
  registration payment-method list, which is already filtered to `active()`
  — confirmed in `ParticipantRegistrationController`). Reuses the existing
  mechanism instead of loosening a NOT NULL column.
- **Participant invisibility**: `resolveDirect()`'s/`resolveSelectedDirect()`'s
  own output (a `CaseAuthorization`) doesn't leak order status to the
  participant either way, which is the right shape to preserve. Confirmed no
  other participant-facing code (session-start responses, receipts, the
  registration-received page) reads `order.status` at all — the only
  participant-visible consumer of order status is the activation
  notification, covered below.

### Every site that checks `orders.status` against `'paid'` (Lead's audit,
### cross-checked and extended)

Lead grepped `origin/main` for literal `'paid'`/`OrderStatus::Paid`
comparisons and found the previous draft's single-site fix (`resolveDirect()`
only) dangerously incomplete. Re-grepping independently (`grep -rn
"OrderStatus::Paid\|status(->value)?\s*(===|!==)\s*(OrderStatus::Paid|'paid')"`)
confirms Lead's count and finds **two more real gate sites** neither of us
had listed yet (`resolveSelectedDirect()`, and the entire
`ParticipantAssessmentSessionCandidates` class — a second, independent
session-start authorizer). Every site touching `orders.status`, not just the
ones Lead named:

| Site | What it does | Bridge-funded included? | Why |
|---|---|---|---|
| `CaseAuthorizationResolver::resolveDirect()` (`:408`) | Gates session-start authorization for a DIRECT_PUBLIC participant's main case | **Yes — must include** | This is the primary access gate the whole plan exists to satisfy; item 18 requires identical access to a paying participant. |
| `CaseAuthorizationResolver::resolveSelectedDirect()` (`:226`) | Gates session-start authorization for the multi-case/selected-case DIRECT_PUBLIC path | **Yes — must include** | Structurally identical gate to `resolveDirect()`, same population, same requirement. Missed entirely by the previous draft — a participant landing on this path instead of `resolveDirect()` would have been rejected even after the original fix. |
| `ParticipantAssessmentSessionCandidates::directCandidate()` → `candidate()`'s `$eligible` (`:138`, consumed at `:221`) | A **second, independent** session-start eligibility computation (raw `DB::table('orders')` array read, not the `Order` model) | **Yes — must include** | Same population, same requirement, different code path entirely from `CaseAuthorizationResolver`. Confirms Lead's flag on this file/line was correct and non-obvious — this class was not mentioned anywhere in the previous draft. |
| `Services\Notifications\DeliverParticipantActivation::claimErrorCode()` (`:156`) | Gates whether the `participant.activation` outbox notification is sent | **Yes — must include** | Lead's headline finding: without this, a bridge-funded participant never receives the activation notification a paying participant gets — directly visible to the participant, contradicting item 18's "no difference from a paying participant." |
| `Services\Commissions\RecordBranchCommissionLedger::recordDirectOrderInService()` (`:90`) | Records a branch commission-ledger entry when an order is paid | **No — stays `'paid'` only** | Explicit decision, not a side effect: the holding company hasn't paid yet at approval time (it's billed *after*, per item 18), so no real revenue exists for the branch to earn commission on. Recording it now would overstate branch commission before the money is actually collected. |
| `Actions\Payments\VerifyManualTransfer::handle()` (`:129`) | After a manual-transfer review, records the commission-ledger entry if the order is now `Paid` | **No — unrelated to this design** | Only fires on the manual-transfer review flow, which bridge funding never goes through (bridge funding is a dedicated approval action, not a transfer review). No change needed. |
| `Services\Payments\OrderPaymentEventHandler::apply()` (`:59`) | Sets `paid_at` when a payment-gateway event transitions an order to `Paid` | **No — unrelated to this design** | Only fires on real Xendit/gateway events. Bridge funding never produces a `PaymentStatus` event, so this handler is never invoked for it. No change needed. |
| `Filament\Resources\Orders\OrderResource.php` (`:88-93` `formatStateUsing`) | Displays the order-status badge label in the admin UI | **New match arm required — not optional** | **Found independently, not on Lead's list, and it's a real break, not a style nit**: this `match ($state) { ... }` over `OrderStatus` has **no `default` arm**. The moment `OrderStatus::BridgeFunded` exists, any admin viewing an order list containing a bridge-funded row hits `UnhandledMatchError` — a 500, not a cosmetic gap. Needs an explicit `OrderStatus::BridgeFunded => 'Ditalangi'` (or similar) arm. The adjacent `color` match already has a `default => 'gray'` fallback, so only the label match is a hard break, though both should get an explicit arm for a sane color. |
| `Services\Payments\OrderStateMachine::transition()` (`:43`, `unlocksEntitlements: $target === OrderStatus::Paid`) | Decides whether a state transition unlocks `locked` entitlements to `ready` | **New transition method required** | Bridge funding doesn't go through `apply()`/`applyManualReview()` (no gateway event, no transfer review) — it needs its own `applyBridgeFunding(OrderStatus $current): OrderTransition` mirroring `applyManualReview()`'s shape (`Pending → BridgeFunded`, calling the same private `transition()`). That means `transition()`'s `unlocksEntitlements` line must also become `in_array($target, [OrderStatus::Paid, OrderStatus::BridgeFunded], true)` — otherwise the new transition method would report `unlocksEntitlements: false` and bridge-funded entitlements would stay `locked` forever, silently breaking the whole feature at the one step that actually grants access. |
| `Services\Commissions\RecordBranchCommissionLedger::recordAssessmentBillInService()` (`:120`) | Records commission for a *different* population entirely | **N/A — false positive** | Reads `assessment_bills.status`, the checkout-v2/selection-integration billing table, not `orders.status`. Unrelated to DIRECT_PUBLIC/bridge funding. |
| `Filament\Widgets\F7OperationalOverview.php` (`:56`) | Operational dashboard metric, `assessment_bills.status = 'paid'` | **N/A — false positive** | Same table as above, same reason. |
| Everything else matching `'paid'` in the codebase (`FinalizeAssessmentBill.php`, `CheckoutPaymentFactsReader.php`, `CheckoutSelfPayment*`, `PrepareCheckoutSelfPayment.php`, `IssueCheckoutSelfPayment.php`) | Checkout-v2 self-payment / `assessment_bills` flows | **N/A — false positive** | All read `assessment_bills.status` or a `CheckoutSelfPayment*` DTO's `state`, never `orders.status`. Confirmed by grepping every remaining `'paid'` match in `app/` and checking each one's source table. |

### The fix: one semantic method, not four repeated literal checks

Lead's proposed alternative — a semantic method distinguishing
"grants participant access" from "money actually received" — is the right
shape, refined one level: put it on **`OrderStatus` itself**, not the `Order`
model, because one of the four access-gate sites
(`ParticipantAssessmentSessionCandidates::directCandidate()`) reads the order
as a raw array via `DB::table()`, never hydrating an `Order` model at all. An
enum method works identically for both:

```php
// app/Enums/OrderStatus.php
public function grantsAccess(): bool
{
    return match ($this) {
        self::Paid, self::BridgeFunded => true,
        default => false,
    };
}
```

- The four access-gate sites (`resolveDirect()`, `resolveSelectedDirect()`,
  `ParticipantAssessmentSessionCandidates::directCandidate()`,
  `DeliverParticipantActivation::claimErrorCode()`) switch from
  `$order->status->value !== 'paid'` / `$order->status !== OrderStatus::Paid`
  to `! $order->status->grantsAccess()` (or, for the raw-array read in
  `ParticipantAssessmentSessionCandidates`,
  `! OrderStatus::from($order['status'])->grantsAccess()`).
- The two financial-reporting sites (`RecordBranchCommissionLedger::recordDirectOrderInService()`
  and the `paid_at`-setting logic in `OrderPaymentEventHandler`) **keep**
  their literal `=== 'paid'` / `=== OrderStatus::Paid` checks unchanged —
  intentionally not routed through `grantsAccess()`, so "money actually
  received" stays a distinct, explicit question in the code, not silently
  merged with "may access the assessment."
- This is exactly the failure mode Lead is designing against: a future
  developer adding a *third* status that should grant access (or shouldn't)
  now has one place to update, and the two categories of check
  (access vs. money-received) are named differently in the code, not
  distinguished only by which literal string happens to appear.

### Does `assessment_bills` actually fit? Tested, not just read.

Lead asked this to be proven by an actual insert attempt in the worktree, not
schema-reading alone. Wrote a throwaway PHPUnit test
(`tests/Feature/Scratch/BridgeFundingAssessmentBillFitProbeTest.php`, run
against the in-memory SQLite test DB, then deleted — not part of this commit)
using the existing `Tests\Support\DirectPublicOrderFixture` to build a real
DIRECT_PUBLIC participant/order, then attempted to bill it through
`assessment_bills`:

1. An `assessment_bills` row itself can be created standalone — it has no FK
   to `orders` or `entitlements` at all, so this step alone proves nothing.
2. Attaching an `assessment_bill_items` row for that participant **fails
   with a real SQL integrity-constraint violation**: `charge_id` is `NOT
   NULL` + `UNIQUE` + a composite FK into `assessment_charges`
   (`database/migrations/2026_08_31_000300_create_assessment_bill_items.php:23-24,37-38`),
   and `assessment_charges.assessment_participant_id` is itself `NOT NULL` +
   `UNIQUE`, tied to the `assessment_participants` table
   (`2026_08_31_000200_create_assessment_billing.php:20-22`).
3. `assessment_participants` rows are created **exclusively** by
   `ProvisionAssessmentParticipant`/`ProvisionCheckoutParticipant`
   (`app/Actions/Integrations/`) — both selection-integration-only. A
   DIRECT_PUBLIC participant (normal `/register` flow) never gets one.

**Conclusion: `assessment_bills` cannot host a DIRECT_PUBLIC bridge-funding
case without fabricating a fake `assessment_participants`/`assessment_charges`
row for a participant who never went through selection-integration** — the
exact structural misuse Lead was right to be skeptical of. Reusing it would
mean either corrupting a table whose real purpose (checkout-v2 billing
reconciliation) depends on that row genuinely representing an integration
attempt, or loosening a `NOT NULL UNIQUE` FK that exists specifically to keep
that guarantee. Neither is acceptable.

### Revised billing design: one small, new, append-only-in-spirit table

A dedicated `bridge_funding_grants` table instead — small, and modeled
directly on the columns `assessment_bills` already proved are the right
shape for "an amount owed with an admin-approval trail," without forcing the
DIRECT_PUBLIC population through a table built for a different one:

- `id`, `order_id` (FK → `orders`, unique — exactly one grant per order),
  `participant_id` (FK, redundant convenience/audit column, same pattern as
  `assessment_bill_items.participant_id` alongside its `bill_id`),
  `branch_id` (participant's branch, for reporting scope, mirroring
  `assessment_bills.organization_id`).
- `amount`, `currency` — CHECK `amount > 0 AND currency = 'IDR'`, same style
  as `assessment_bill_money_check`.
- `management_reference` (text, `NOT NULL`, non-blank CHECK) — the
  "which instruction authorized this" field the owner requires; simpler
  here than in the original design since this table exists solely for
  bridge funding, so the column can just be required outright instead of
  conditionally required by a payer-type CHECK.
- `approved_by_admin_id` (FK → `admins`, `NOT NULL`) + `approved_at`
  (`NOT NULL`) — the admin-approval trail, mirroring the existing
  `verified_by_admin_id`/`verified_at` pairing pattern from `orders`/
  `assessment_bills`.
- `status` (default `'invoiced'`, CHECK IN `('invoiced', 'collected',
  'written_off')`) + `collected_at` (nullable) — minimal collection-lifecycle
  tracking so the "billing history must not be lost or overwritten"
  requirement has somewhere to record what happened later without
  ever touching the approval fields above. Full collection-lifecycle design
  (dispute handling, partial collection, etc.) is explicitly **not** designed
  here, consistent with how this doc already treats other out-of-scope
  lifecycle questions below.
- RLS: needs the same treatment as every other financial table in this repo
  (`REVOKE ALL` / narrow `GRANT` / `ENABLE`+`FORCE ROW LEVEL SECURITY` /
  service-role write policy). Read policy recommendation: `super_admin` only
  for now (holding-company-level financial data, not branch-operational
  data), extended to `central_admin` later under the same reasoning as the
  approval-role recommendation below — exact grant list is an implementation
  detail, not decided further here.
- Writing this row and transitioning the order to `BridgeFunded` happen in
  the same service-role transaction (mirrors `VerifyManualTransfer`'s
  shape: one action, one audit_logs row, one state change) — the approval
  action's `audit_logs` row still gets the `management_reference` in
  `context` too, matching the existing precedent of duplicating
  audit-relevant fields into both the domain row and the audit trail.

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

### Mandatory tests at implementation time (Lead's condition #3)

- **Activation notification parity**: a bridge-funded order's
  `participant.activation` outbox message resolves `claimErrorCode()` to
  `null` (sendable) exactly like a paid order — directly closes finding #1
  above as a regression test, not just an argument.
- **Session-start parity across all four gate sites**: `resolveDirect()`,
  `resolveSelectedDirect()`, and
  `ParticipantAssessmentSessionCandidates::directCandidate()` each authorize
  a bridge-funded order's participant exactly as they would a paid one
  (reusing `DirectPublicOrderFixture`, extended with a `bridgeFunded()`
  variant). Three sites, not one — the previous draft's gap was exactly
  "tested one, shipped three untested."
- **Commission-ledger exclusion**: a bridge-funded order does **not**
  produce a `RecordBranchCommissionLedger` entry (regression-protects the
  explicit exclusion decision above, so a future refactor that
  accidentally routes `BridgeFunded` through `grantsAccess()`-style logic
  in the commission path gets caught immediately).
- **`OrderResource` doesn't throw**: a Filament order-list render including
  a `BridgeFunded` row succeeds (closes the `UnhandledMatchError` finding
  above as a regression test, not just a code review note).
- **`OrderStateMachine::applyBridgeFunding()` unlocks entitlements**: asserts
  `unlocksEntitlements === true` for the `Pending → BridgeFunded` transition,
  specifically to catch the `transition()` widening finding above regressing
  silently.
- **`bridge_funding_grants` Postgres RLS**: mirrors the existing pattern from
  `AdminAccountLifecycleRlsSecurityTest`/`TestPackageCatalogRlsSecurityTest`
  — direct non-service insert denied, service-context insert succeeds, read
  restricted to the approved role list.

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

## Status: both original blockers resolved, second review round addressed

1. ~~Owner's answer on funding-bridge (dana talang)~~ — answered, item 18,
   designed above.
2. ~~Fact check: does any organization already use the legacy integration
   API in production today?~~ — confirmed no; the system is not live.
3. ~~Single-site `resolveDirect()` fix is incomplete — full audit of every
   `orders.status`/`'paid'` comparison~~ — done above: 4 access-gate sites now
   route through `OrderStatus::grantsAccess()`, 2 financial-reporting sites
   explicitly stay literal, 1 new UI break found and fixed
   (`OrderResource`'s unhandled match), 1 new state-machine gap found and
   fixed (`unlocksEntitlements` widening), 3 sites confirmed as false
   positives (different table, `assessment_bills`).
4. ~~Does `assessment_bills` actually fit DIRECT_PUBLIC bridge funding~~ —
   tested empirically, does not fit (FK chain requires a
   selection-integration-only `assessment_participants` row). Replaced with a
   small, dedicated `bridge_funding_grants` table.

Ability separation (dedicated `AdminAbility`, unconditional default) —
**approved by Lead, unchanged from the previous revision.**

This plan is ready for Lead's review before implementation begins.
