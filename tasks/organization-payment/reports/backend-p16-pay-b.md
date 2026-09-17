# Backend P16-pay-b: internal self-payment preparation

Date: 2026-09-05

## Outcome

This increment adds `PrepareCheckoutSelfPayment`, an internal action with only two
inputs: `CheckoutSessionMutationCredentials` and the typed boolean consultation
choice. It owns one `RlsContextRunner` service transaction and first invokes the
P16-pay-a `CheckoutSessionLifecycle::lockMutation()` seam. The action then reloads
the persisted participant model already locked by that lifecycle; it never builds
an Eloquent payer from the DTO or browser data.

Only persisted `COMMERCIAL_SELF_PAY` may proceed. Organization funding, zero
total, stale session/handoff authority, changed payer policy, disabled registry,
deleted participant, revoked/finalized attempt, and unavailable catalog fail with
the same `CHECKOUT_PAYMENT_UNAVAILABLE` domain result. No ID, policy reason, SQL,
credential, digest, reference, or provider detail is returned.

The stable key is `checkout-self-v1:<assessment_attempt_id>`. It does not depend
on a browser retry or session generation. The consultation choice remains bound
by the canonical P7 selection hash and reservation request hash, so changing the
choice conflicts with the existing intent and cannot create a second bill.

Before any new preview, the action finds an existing intent by that stable key and
by the attempt's bill-item/charge linkage under the organization lock already held
by the lifecycle. It locks and validates exactly one bill, item, charge, participant
scope, positive IDR total/count, consultation price snapshot, payer policy snapshot,
Xendit method code, stable key, request hash, and status coherence. Existing
`reserved`, `pending`, and fully allocated `paid` states return the same typed
bill identity. Xendit may be inactive on replay; create alone requires the unique
persisted `code=xendit` method to be active. `issuing`, `unknown`, `expired`,
`rejected`, or corrupt state fails closed without preview or replacement.

For a new positive intent, the action calls `PreviewAssessmentBill` and
`ReserveAssessmentBill` with one server-mapped selection and the persisted
participant. Pricing, consultation eligibility, policy reload, snapshot creation,
locks, uniqueness, audit, bill, item, and charge persistence remain in the P7
primitives. The outer service transaction commits before the typed
`CheckoutSelfPaymentPreparation` result becomes available.

The result contains only organization ID, bill ID, persisted status, and whether
this invocation created the reservation. It is internal routing data, not a
provider permit or HTTP response. It contains no reference or payment URL.

## TDD and verification

After correcting the test harness to use the existing `DatabaseTruncation`
boundary needed by transaction-owning actions, the authoritative RED run produced
**25 tests, 9 assertions, 25 errors**. Every error was the expected missing
`PrepareCheckoutSelfPayment` class. No broken RED commit was retained.

The final focused P16-pay-b suite passed **27 tests / 165 assertions**. It covers:

- create plus exact replay after catalog price and method activation change;
- changed consultation without a second bill;
- organization and zero-price denial without bill/charge/item/outbox;
- session revoke, recovery generation, policy, client, source, package,
  participant, attempt revoke, and finalization changes after hydration;
- missing/inactive/non-Xendit create method;
- existing reserved, pending, and fully allocated paid replay while Xendit is off;
- issuing/unknown recovery states and expired/rejected terminal states;
- corrupt bill total, request hash, item state, price snapshot, and method;
- ambient context/transaction denial; and
- injected audit failure rolling back bill, charge, item, and audit while restoring
  transaction and RLS context.

The related regression passed **173 tests / 1,113 assertions** across the new
suite, mutation scope/lifecycle, P7 preview/reservation, and P10 invoice claim.
Pint passed for all three PHP files. PHP syntax, full application PHPStan with
process-local testing configuration, and lane `git diff --check` passed; PHPStan
reported **0 errors**.

No PostgreSQL test file was added because this action composes two already accepted
serialization boundaries without changing either: P16-pay-a proves organization
lock wait followed by current policy reload, while P7 PostgreSQL tests prove
self-versus-organization reservation serialization, same-intent replay, policy/
method/catalog writer ordering, uniqueness, and rollback. A duplicate two-process
test would add no new race. Therefore this report does not claim a fresh
PostgreSQL execution for P16-pay-b; root's accepted P16-pay-a disposable baseline
remains **395 tests / 3,877 assertions**.

## Files

- `app/Actions/Integrations/PrepareCheckoutSelfPayment.php`
- `app/Data/Integrations/CheckoutSelfPaymentPreparation.php`
- `tests/Feature/Integrations/CheckoutSelfPaymentPreparationTest.php`
- `tasks/organization-payment/reports/backend-p16-pay-b.md`

## Boundary

This increment creates no invoice issuance outbox intent and does not call
`ClaimAssessmentBillInvoice`, `IssueAssessmentBillInvoice`, or a payment provider.
P16-pay-c must consume the committed typed result, invoke the existing claim in a
separate service transaction, and execute any provider permit only after that
transaction and RLS context end. HTTP/controller/route/config work, URL response
mapping, zero-price settlement, entitlement, activation, frontend changes, source
activation, deploy, and active data remain outside this slice.
