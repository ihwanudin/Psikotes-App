# Backend P16-pay-d: internal canonical invoice issuance orchestration

Date: 2026-09-05

## Outcome

`IssueCheckoutSelfPayment` is the internal P16 issuance orchestrator. Its only
inputs remain `CheckoutSessionMutationCredentials` and the consultation boolean.
It starts with an empty RLS context and transaction, calls the accepted
`CoordinateCheckoutSelfPayment`, and verifies the Claim transaction has ended
before any issuance action can run.

The state mapping is deliberately small:

- coordinator `paid` returns typed `paid` without Claim or provider work;
- coordinator `pending` returns typed `pending` without provider work;
- coordinator `issuance_required` passes its internal message ID directly to the
  canonical P10 `IssueAssessmentBillInvoice::execute()` while context and
  transaction remain empty;
- exact P10 decision `issued` becomes typed `pending`;
- `unknown`, `recovery_required`, malformed, stale, terminal, and other invalid
  domain decisions become the same `CHECKOUT_PAYMENT_UNAVAILABLE` result.

The action verifies context and transaction are empty again after issuance.
`CheckoutSelfPaymentIssuanceResult` contains exactly one field, `state`, whose
only values are `pending` and `paid`. It cannot expose the invoice message ID,
bill/organization/participant/attempt IDs, amount, reference, URL, provider
payload, model, credential, or raw provider error.

The orchestrator does not reconstruct provider requests or inspect invoice
payloads. Permit consumption, maximal one-create behavior, strict exact lookup,
late-state fencing, unknown persistence, audit, and processed/failed outbox state
all remain inside `IssueAssessmentBillInvoice` and
`PersistAssessmentInvoiceOutcome`.

## TDD and verification

The authoritative RED run passed all **36** existing P16 preparation/claim tests
and failed the **7** new issuance cases because `IssueCheckoutSelfPayment` did
not exist: **43 tests, 232 assertions, 7 errors**. No broken RED commit was kept.

After correcting one test-only closure that had captured its synthetic invoice by
value instead of reference, the focused combined P16 file passed **44 tests / 285
assertions**. The new cases prove:

- a new self payment performs exactly one fake create and one exact lookup,
  persists one pending bill plus one processed/attempts=1 canonical outbox row,
  and returns only `pending`;
- both provider callbacks observe null RLS context and transaction depth zero;
- exact pending replay performs no provider call;
- fully allocated paid replay performs no provider call and returns `paid`;
- a create exception followed by an exact lookup still persists pending;
- mismatched lookup and lookup failure persist canonical unknown/failed state,
  return generic unavailable, and a retry performs neither a second create nor a
  lookup;
- a consumed processing/1 permit and a session revoked after hydration fail
  before provider calls; and
- ambient RLS context or transaction is rejected before any preparation.

The related P16/P10 regression passed **155 tests / 1,294 assertions** across the
combined P16 preparation/claim/issuance test, Claim, issuance, and reconciliation
outcome suites. PHP syntax and Pint pass for the three PHP files. Full application
PHPStan passes with **0 errors** using process-local testing configuration. Lane
`git diff --check` passes.

All provider interactions in the new tests use an in-memory PHPUnit mock of the
existing `PaymentProvider` interface. No binding, credential, `.env` value, HTTP
request, or provider endpoint was read or used.

No PostgreSQL test was added or rerun because this slice does not modify schema,
RLS, Claim, permit consumption, persistence fencing, or transaction/lock behavior.
The accepted disposable baseline remains **395 tests / 3,877 assertions**. This
report does not claim a new P16-pay-d PostgreSQL race.

## Files

- `app/Actions/Integrations/IssueCheckoutSelfPayment.php`
- `app/Data/Integrations/CheckoutSelfPaymentIssuanceResult.php`
- `tests/Feature/Integrations/CheckoutSelfPaymentPreparationTest.php`
- `tasks/organization-payment/reports/backend-p16-pay-d.md`

## Boundary

This increment returns no payment URL and adds no HTTP request/controller,
middleware, route, config, schema, zero-price writer, UI binding, command, job,
scheduler, settlement, entitlement, feature activation, deploy, or active-data
operation. Payment URL presentation and HTTP wiring remain separate reviewed
increments. Unknown issuance remains recovery-required and never authorizes a
second provider create.
