# Backend P16-pay-f: explicit zero-price checkout settlement

Date: 2026-09-05

## Outcome

This increment adds the internal `SettleZeroPriceCheckout` boundary accepted by
the amended ADR-014. Its only inputs are
`CheckoutSessionMutationCredentials` and the explicit
`consultationRequested` boolean. It rejects ambient RLS context or transaction,
owns one service transaction, and consumes
`CheckoutSessionLifecycle::lockMutation()` before reading any business identity.

Within that same outer transaction it reloads the canonical organization, client,
source, package/items, attempt and participant in organization-first order. The
package must contain DASS-21 plus at least one primary assessment. Both current
psychotest and DASS consent rows are locked and must match the exact current server
document version/hash, be accepted and unwithdrawn, and have `consented_at` no
later than one database instant.

Payer remains the persisted funding decision already validated by the session
mutation scope: `COMMERCIAL_SELF_PAY` maps to self and
`INVOICED_TO_ORGANIZATION` maps to organization. The action reuses
`ResolvePayerPolicy`, `PreviewAssessmentBill`, and `AssessmentPriceSnapshot`.
Create consumes Preview's canonical price and seven-field policy snapshot without
accepting payer, amount, currency, package, attempt, or policy from the caller.

An exact positive consultation total returns the typed internal state
`not_applicable` with no writes. It is not an authorization result; the future
payment coordinator may then select the existing positive self-payment path. Only
an exact IDR total of zero is settled. Create writes one attempt-unique
`AssessmentCharge`, immutable price/policy snapshots, the database-time
`free_settled_at`, and one non-PII `assessment_charge.free_settled` audit. It
creates no bill, bill item, provider request, claim, or invoice intent.

The action calls `ActivateSettledAssessment` before the outer service transaction
returns. Laravel's nested transaction/savepoint remains inside that outer atomic
boundary. Charge, marker, free audit, ready entitlements, attempt status,
activation outbox, and activation audit therefore commit or roll back together.
Missing identity remains the intentional P8b no-op: payment is explicitly settled
while the attempt stays PROVISIONED and locked.

Replay locks and validates every charge scope field, zero money/currency,
consultation choice, canonical price snapshot, payer policy snapshot, marker time,
absence of bill-item linkage, and exactly one free-settlement audit. The audit may
belong to a prior valid checkout session for the same attempt, allowing a securely
recovered session to replay the attempt-scoped settlement. It cannot belong to a
foreign graph. Replay calls P8b again so identity completed later may activate;
existing rights, audit, and outbox deduplication prevent duplicates. Missing or
corrupt marker/snapshot/payer/policy/audit, stale consent, revoked/finalized scope,
or bill linkage fails generically without inference, repair, or backfill.

`CheckoutZeroPriceResult` permits only `settled` with a validated list of newly
activated test types, or `not_applicable` with an empty list. It is internal
routing data, not settlement evidence or an HTTP response.

## TDD and verification

The authoritative RED was run after adding the focused feature test and before
either production class existed. It produced **8 tests, 0 assertions, 8 errors**;
each error was the expected missing `SettleZeroPriceCheckout` class. No broken RED
commit was retained.

Final isolated SQLite-memory verification with
`phpunit.organization-payment.xml`:

- focused zero settlement: **9 tests / 144 assertions**;
- related zero/self/confirmation/activation/preview regression:
  **121 tests / 749 assertions**.

The feature matrix proves self and organization zero settlement, exact create and
replay, recovered-session replay, missing identity settled-but-locked, complete
identity activating IST and DASS, true consultation producing a positive
not-applicable result, both consents separately missing, withdrawn, wrong hash,
wrong version, or future-dated, missing DASS package item, policy/session/revoke/
finalization changes, corrupt price/policy snapshot, payer, marker, audit, audit
context, and bill linkage. Injected free-audit and activation-outbox failures roll
back the full zero/activation state. A strict mock asserts zero calls to all five
`PaymentProvider` methods in every feature case.

PostgreSQL disposable history:

1. First run reached **373 tests / 3,174 assertions** with one test-harness error:
   runtime `psikotes_runtime` correctly could not create the proposed synthetic
   trigger function. The injection was replaced by the established
   `QueryExecuted` failure pattern; no privilege or runner was weakened.
2. The next full run passed **373 / 3,180**.
3. After adding recovered-session audit validation, a full run reached
   **373 tests / 3,176 assertions** with one new concurrency failure. Its worker
   detail exposed a real PostgreSQL incompatibility in the new code: `FOR UPDATE`
   had been applied to `count(*)` while validating the historical session audit.
   The query was corrected to lock at most two concrete session rows and count the
   returned collection. SQLite behavior was not used to dismiss this failure.
4. Final full disposable run
   `oncam-org-test-57438d9f839545dfb3e28dc09941cf9e` passed
   **373 tests / 3,180 assertions** in 69.290 seconds. Cleanup completed; no ports
   were published and application containers were not targeted.

The new PostgreSQL tests assert the parent and both workers are
`psikotes_runtime`, non-superuser, and without BYPASSRLS. Two settlement workers
are observed waiting on the same organization lock, then produce one charge,
marker, free audit, two ready entitlements and one activation outbox. Separate
policy and consent writers are observed holding the relevant lock; the worker
reloads their committed stale state and rejects without writes. A failure injected
after the activation outbox insert but at the activation audit rolls back the
outer charge/free audit/entitlements/outbox/status transaction.

Final PHP syntax checks and focused Pint passed for all four PHP files. Full
application PHPStan passed with **0 errors**. Lane `git diff --check` is clean.

## Review correction: exact replay state and authoritative time

Coordinator review held the initial implementation commit `99c700f` and required
the replay and clock-skew rules to be tightened before integration. The correction
keeps the initial persisted price and policy decisions immutable while also
requiring them to match the current authoritative catalog and payer policy on
every replay. The locked package is passed into replay and
`AssessmentPriceSnapshot::capture()` is compared strictly with the stored charge
snapshot. The current policy snapshot is reconstructed directly from the resolved
`PayerDecision` as the exact seven-field shape, including the enum-ordered allowed
payer list and locked payer. Changes to package price, name, items, allowed payer
types, or payer lock therefore fail with the generic unavailable result and do
not rewrite the charge, audit, or bill state.

`ActivateSettledAssessment::execute()` now accepts an optional authoritative
`CarbonImmutable` instant. Existing callers remain source-compatible and retain
their prior behavior. The zero-price writer passes its single database instant,
which is then used for settlement evaluation, prerequisite evaluation, ready
timestamps, and the activation audit. This removes dependence on a PHP clock that
may temporarily be behind the database clock. A frozen-early PHP clock test proves
both initial activation and replay still use the persisted database instant.

The organization-funded zero-price branch is an explicit no-money/no-bill
exception only. It does not introduce a positive-price organization checkout
command, invoice, URL, or provider operation, and it does not make organization
funding an entitlement signal. Canonical documentation clarification remains a
root-owned follow-up; this lane does not edit ADR-014 or other canonical docs.

Correction TDD and final verification:

- The first correction-focused run reached **42 tests / 278 assertions** with one
  test-fixture assertion failure: the new catalog/policy drift loop compared a
  global audit count instead of the case-local baseline. The assertion was fixed;
  no production code changed for that failure.
- Focused zero-settlement plus activation regression passed
  **42 tests / 292 assertions**.
- Related zero/self/confirmation/activation/preview regression passed
  **124 tests / 790 assertions**.
- The full PostgreSQL disposable runner
  `oncam-org-test-aba263c85a9e4c5ba33096f70f3be5b6` passed
  **373 tests / 3,180 assertions** in 61.983 seconds and confirmed cleanup.
- Syntax checks for the three changed PHP files, focused Pint, full PHPStan
  (**0 errors**), and `git diff --check` all passed.

## Owned files

- `app/Actions/Integrations/SettleZeroPriceCheckout.php`
- `app/Data/Integrations/CheckoutZeroPriceResult.php`
- `tests/Feature/Integrations/CheckoutZeroPriceSettlementTest.php`
- `tests/Postgres/CheckoutZeroPriceSettlementTest.php`
- `tasks/organization-payment/reports/backend-p16-pay-f.md`

## Boundary

This increment does not route the zero action from a controller or payment
coordinator. It adds no HTTP request, middleware, route, schema, migration,
configuration, UI, browser work, provider call, Claim/issuance/reconciliation,
payment URL, source/gate activation, command, job, scheduler, notification
delivery, `.env` or active-data operation. It does not modify canonical docs.
P16-pay-g and all HTTP/public wiring remain out of scope pending review.
