# Backend P16-pay-a: transaction-bound checkout mutation scope

Date: 2026-09-05

## Outcome

This increment adds an internal mutation seam to the existing checkout-session
lifecycle. The seam accepts only the persisted selector and CSRF credentials in
`CheckoutSessionMutationCredentials`. It does not accept a browser-provided
organization, participant, attempt, package, funding choice, amount, or a
previously hydrated principal.

`CheckoutSessionLifecycle::lockMutation()` may only run inside a caller-owned
service transaction. It reuses the lifecycle's existing canonical lock and
validation path:

1. organization;
2. integration client;
3. integration source;
4. package and package items;
5. assessment attempt;
6. participant;
7. complete handoff history; and
8. checkout-session history.

The shared path validates the unique selector, exact CSRF digest, latest consumed
handoff, active exact session and generation, tenant/client/source/package/
attempt/participant linkage, checkout-v2 marker, current database time,
effective registry records, non-deleted participant, and a non-revoked,
non-void, non-finalized attempt. For mutation use it additionally reloads the
current payer policy from the locked records and requires that the persisted
funding mode remains selected and allowed.

The returned `CheckoutSessionMutationScope` exposes the freshly validated
principal only while the same service transaction and transaction depth remain
active. Commit and rollback callbacks invalidate it. Code cannot retain the
authority result after the transaction or receive it through an unrestricted
callback.

This seam deliberately does not reserve a bill, choose a payment method, invoke
a provider, settle a payment, activate an attempt, write an outbox message, or
register an HTTP route. Those remain later reviewed increments under ADR-014.

## TDD evidence

The initial RED run used only
`tests/Feature/Integrations/CheckoutSessionMutationScopeTest.php`. It reported
**9 tests, 15 assertions, 9 errors**. Every error was the expected missing
`CheckoutSessionLifecycle::lockMutation()` method. A separate RED commit was not
kept because it would leave the lane branch deliberately broken; this report
preserves the exact failure evidence.

After implementation, the focused test first passed **9 tests / 72 assertions**.
The final feature regression, after adding explicit client-disabled,
participant-deleted, and attempt-revoked cases, passed **66 tests / 1,760
assertions**:

```text
tests/Feature/Integrations/CheckoutSessionMutationScopeTest.php
tests/Feature/Integrations/CheckoutSessionLifecycleTest.php
tests/Feature/Integrations/CheckoutSessionHttpTest.php
tests/Feature/Registration/IntegratedCheckoutConsentTest.php
```

These tests prove that an authentication snapshot does not authorize a later
mutation after direct session revocation, recovery generation change, payer
policy change, client/source/package disablement, participant deletion, or
attempt revocation/finalization. A foreign selector/CSRF pair is rejected.
Commit and rollback both invalidate the capability, and a synthetic rollback
restores session idle data, service context, and transaction depth. Query
evidence also checks the canonical lock ordering.

The disposable PostgreSQL runner passed **370 tests / 3,138 assertions** as the
runtime non-owner `psikotes_runtime`, with neither superuser nor `BYPASSRLS`.
`CheckoutSessionMutationScopeConcurrencyTest` holds the organization lock in one
process while changing payer policy. The mutation worker is observed waiting on
a PostgreSQL lock; after the policy commit it resumes, reloads the committed
policy, and rejects the stale authority. No bill, charge, or attempt outbox row
is created. Disposable resources were cleaned up and no application container or
active database was targeted.

Final checks:

- focused Pint check passed for all four PHP files;
- PHP syntax passed for all four PHP files;
- full application PHPStan passed with **0 errors** using process-local testing
  values, SQLite memory, and no active environment file;
- `git diff --check` passed for the lane files.

## Files

- `app/Actions/Integrations/CheckoutSessionLifecycle.php`
- `app/Data/Integrations/CheckoutSessionMutationScope.php`
- `tests/Feature/Integrations/CheckoutSessionMutationScopeTest.php`
- `tests/Postgres/CheckoutSessionMutationScopeConcurrencyTest.php`
- `tasks/organization-payment/reports/backend-p16-pay-a.md`

## Verification boundary

The PostgreSQL result proves the lock-and-policy race covered above, not a future
billing reservation or provider race. The seam remains internal and default
unreachable from public HTTP. P16 payment writing, zero-price settlement,
provider issuance/reconciliation, public route wiring, deployment, source
activation, and P17 acceptance remain outside this increment.
