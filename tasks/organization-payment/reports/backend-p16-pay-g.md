# Backend P16-pay-g: final persisted self-payment projection

Date: 2026-09-05

## Outcome

This increment adds the final credential-bound projection required before a
self-payment result can be returned to a browser adapter. The additive
`PrepareCheckoutSelfPayment::readPersisted()` method rejects ambient RLS context
or transactions, owns a service transaction, and calls
`CheckoutSessionLifecycle::lockMutation()` with the raw selector/CSRF credentials.
It requires an existing self-payment intent and reuses the existing
`existing()` plus `validated()` bill/item/charge path. It never calls Preview,
Reserve, Claim, issuance, reconciliation, or a payment provider, and it never
creates or repairs a row.

The final projection accepts only two canonical persisted states:

- `pending` returns the exact HTTPS `assessment_bills.invoice_url` after URL
  validation rejects missing host, insecure scheme, or userinfo;
- `paid` returns a null payment URL after the existing paid allocation and audit
  checks succeed.

Reserved, issuing, unknown, expired, rejected, corrupt, foreign, organization,
missing-intent, revoked-session, and consultation-mismatch paths fail with the
same `CHECKOUT_PAYMENT_UNAVAILABLE` error. No bill, organization, message,
merchant reference, amount, provider reference, or prior URL enters the result or
error.

The final read also compares the immutable persisted price snapshot with a fresh
capture of the currently locked package and items. It resolves the current payer
policy from the authoritative organization/client/source/package and compares the
exact seven-field policy snapshot, including the enum-ordered allowed payer list
and payer lock. Catalog price/name/item drift or allowed-list/lock drift therefore
fails closed without rewriting the original charge or bill.

`IssueCheckoutSelfPayment` now performs this projection after every current
pending/paid claim result and after successful invoice persistence. It does not
use `PaymentInvoice.paymentUrl` or any provider return as browser authority. A
pending bill that becomes paid before the final read returns `paid` with null; a
session, policy, catalog, bill, or state change fails generically and cannot leak
the URL observed earlier in the operation.

`CheckoutSelfPaymentIssuanceResult` now exposes exactly `state` and `paymentUrl`.
Its constructor permits only pending plus a safe absolute HTTPS URL without
userinfo, or paid plus null.

## TDD and verification

The first RED run was made after the focused tests and before production changes.
It ran **62 tests / 272 assertions**: 40 passed, two assertions failed, and 20
errors reported the missing `readPersisted()` method or `paymentUrl` property.
After the first implementation, **62 tests / 346 assertions** reached 60 passed
with only two synthetic-fixture defects: an invalid FK mutation and a race hook
positioned after the intended transition. Those fixtures were corrected without
weakening production checks. A later focused run passed **62 / 352**, and the
completed test matrix passed **65 tests / 361 assertions** after Pint.

The focused matrix proves persisted pending URL, paid/null, create and replay,
no-provider replay, no-create read, absent intent, organization isolation,
consultation mismatch, invalid HTTPS/userinfo URLs, request hash and stable-key
graph mismatch, unknown/terminal states, catalog price/name/item drift, policy
allowed-list/lock drift, session revocation, provider-create URL differing from
the persisted lookup URL, and pending-to-paid movement before the final read.
Changes to session, policy, and catalog injected during the provider gap are
rejected only by the final credential-bound reread, as required.

A combined SQLite invocation reached **359 tests / 2,457 assertions**, with 358
passing and one harness-order error: `DatabaseTruncation` reused migrated state
while a fresh memory connection no longer contained `payment_methods`. The same
file passed independently; no test harness or application behavior was relaxed.
Final isolated runs were:

- pay-g focused: **65 tests / 361 assertions**;
- P16 payment readers plus P10 claim, lookup, issuance, lease, and reconciliation:
  **294 tests / 2,109 assertions**.

PHP syntax checks passed for all four changed PHP files. Focused Pint passed, full
application PHPStan passed with **0 errors**, and `git diff --check` is clean.

No new PostgreSQL run was needed. The lifecycle already acquires the canonical
organization/client/source/package/items locks before the existing bill validator;
the final comparison rereads those already-held rows and does not change the
effective lock order or write path. The fresh root baseline supplied by the
coordinator remains **398 PostgreSQL tests / 3,919 assertions**; this lane does not
claim a new concurrency result.

## Owned files

- `app/Actions/Integrations/PrepareCheckoutSelfPayment.php`
- `app/Actions/Integrations/IssueCheckoutSelfPayment.php`
- `app/Data/Integrations/CheckoutSelfPaymentIssuanceResult.php`
- `tests/Feature/Integrations/CheckoutSelfPaymentPreparationTest.php`
- `tasks/organization-payment/reports/backend-p16-pay-g.md`

## Boundary

This increment adds no HTTP controller, request, middleware, route, configuration,
schema, migration, frontend, zero-price writer change, canonical documentation,
provider implementation, browser/server operation, credential, `.env`, active
database access, deployment, or push. It performs no real provider request and
does not expose a payment URL outside the typed internal result. P16 payment HTTP
wiring remains pending review.
