# Backend P16-pay-c: committed preparation to canonical invoice claim

Date: 2026-09-05

## Outcome

`CoordinateCheckoutSelfPayment` is an internal top-level coordinator that accepts
only `CheckoutSessionMutationCredentials` and the consultation boolean. It first
calls the accepted P16-pay-b `PrepareCheckoutSelfPayment`; that method owns and
commits the session-authority and reservation transaction before returning its
internal preparation identity.

The coordinator verifies that RLS context and transaction depth are both empty
after preparation. It then handles only these states:

- `paid` returns the typed state `paid`, with no message ID and no claim;
- `pending` returns the typed state `pending`, with no message ID and no claim;
- `reserved` starts a new service transaction and calls the canonical P10
  `ClaimAssessmentBillInvoice` with the internal organization and bill IDs;
- canonical `issuing` replay also enters Claim, allowing that primitive to
  distinguish pending/0 replay from processing/1 recovery or a corrupt intent.

Only Claim decisions `claimed` and `replayed` with a valid ULID message ID become
`issuance_required`. Recovery-required, not-applicable, malformed, terminal,
unknown, stale, and other domain failures collapse to
`CHECKOUT_PAYMENT_UNAVAILABLE`. The coordinator verifies context and transaction
are empty again after Claim commits.

`CheckoutSelfPaymentClaimResult` contains only `state` and an optional invoice
issuance message ID. Its constructor enforces the exact combinations:
`issuance_required` requires a ULID; `pending` and `paid` require null. It contains
no organization, participant, attempt, bill, amount, payer, method, reference,
URL, provider payload, model, credential, or raw error.

P16-pay-b preparation was narrowed only as required for this replay path:
`issuing` is accepted when its bill still has the pre-provider field shape. The
coordinator immediately delegates it to Claim, which owns the complete durable
outbox predicate. This avoids copying P10 message payload, counter, digest, and
recovery validation. The previously accepted `unknown`, expired/rejected, and
corrupt handling remains fail closed.

## TDD and verification

The authoritative RED run passed all **26** existing preparation tests and failed
the **10** new coordinator cases because
`CoordinateCheckoutSelfPayment` did not exist: **36 tests, 161 assertions, 10
errors**. No broken RED commit was retained.

The final focused file passed **36 tests / 231 assertions**. New coverage proves:

- a positive reservation commits, then creates exactly one canonical
  `assessment.bill.invoice-issuance` outbox row and moves the bill to `issuing`;
- issuing retry returns the same ULID and creates no duplicate message or audit;
- an `afterCommit` callback registered during reservation has fired before the
  first Claim outbox insert, proving the two transaction boundary;
- pending and fully allocated paid states bypass Claim and still replay when the
  persisted Xendit method is inactive;
- processing/1 recovery-required, terminal, corrupt, changed policy, and revoked
  session states return the generic unavailable result;
- a failure while inserting the Claim audit rolls the bill back to `reserved`,
  removes the outbox row, restores context/transaction state, and allows one
  successful retry; and
- ambient RLS context or transaction is rejected before preparation.

The related regression passed **204 tests / 1,452 assertions** across the combined
P16-pay-b/pay-c file, mutation scope and lifecycle, P7 preview/reservation, P10
claim, and P10 issuance. PHP syntax and Pint pass for the four changed PHP files.
Full application PHPStan passes with **0 errors** using process-local testing
configuration. Lane `git diff --check` passes.

No PostgreSQL test was added or rerun. This increment does not alter P7/P10 locks,
Claim persistence, schema, RLS, or concurrency behavior. The accepted disposable
evidence immediately preceding it remains **395 tests / 3,877 assertions**, and
already covers session/policy serialization, same-intent Claim replay, reservation
self-versus-batch, Claim rollback, uniqueness, and outbox recovery. This report
does not claim a new P16-pay-c PostgreSQL race.

## Files

- `app/Actions/Integrations/CoordinateCheckoutSelfPayment.php`
- `app/Data/Integrations/CheckoutSelfPaymentClaimResult.php`
- `app/Actions/Integrations/PrepareCheckoutSelfPayment.php`
- `tests/Feature/Integrations/CheckoutSelfPaymentPreparationTest.php`
- `tasks/organization-payment/reports/backend-p16-pay-c.md`

## Boundary

The returned message ID is only a durable routing hint. This increment does not
call `IssueAssessmentBillInvoice::consume()` or `execute()`, construct a provider
permit, make provider GET/POST requests, expose an invoice URL, or settle/activate
an attempt. It adds no HTTP controller, request, middleware, route, config, schema,
zero-price writer, command, job, scheduler, feature activation, browser work,
outbound traffic, deployment, or active-data operation. Provider execution and
HTTP mapping require separate reviewed increments.
