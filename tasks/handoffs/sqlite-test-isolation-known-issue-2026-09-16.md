# SQLite test-isolation known issue — 2026-09-16

## Status

RESOLVED — test-lifecycle repair is committed as the root-cause fix for the
SQLite local test isolation issue. It does not change PostgreSQL schema or
production behavior.

## Evidence

Full SQLite PHPUnit runs intermittently reached five tests with a partial
in-memory schema: `branches` was absent for CheckoutAcceptanceMatrix,
CheckoutZeroPriceSettlement, and CollectiveBillLifecycleComposition;
`payment_methods` was absent for CheckoutPaymentHttp and
CheckoutSelfPaymentPreparation. The resolved PHPUnit order placed these
predecessors immediately before them: AssessmentResultIntegration,
CheckoutPaymentHttpBoundary, CheckoutProvisioning, CheckoutSummaryLifecycle,
and AssessmentSettlementSnapshot.

An audit covered 22 Feature/Database classes that load migration files or
mutate schema manually. Historical migration tests without a schema restore
received a final SQLite baseline restoration. Classes using `RefreshDatabase`
instead reset `RefreshDatabaseState::$migrated` after their transaction
teardown; running `migrate:fresh` inside that transaction is invalid SQLite
because Laravel runs `VACUUM`.

The combined audit suite reached 266/267 tests. The remaining failure was
CheckoutAcceptanceMatrix with `no such table: branches`, so a single root
contaminator could not be proved.

The repair applies `RefreshDatabaseState::$migrated = false` before
`OrganizationPaymentTestCase::setUp()` for every `DatabaseTruncation` subclass,
while retaining the final `migrate:fresh` teardown of manually rolled-back
schema tests. The five local guards were removed. A full SQLite run without
those guards passed 3,380 tests / 21,840 assertions with 0 errors, 0 failures,
and 9 known skips (2026-09-16). The PostgreSQL disposable suite passed 554
tests / 5,863 assertions with 0 errors and 0 failures after the guard removal.
The additional assessment-case rollback preservation test passed 1 test / 38
assertions.

## Replaced mitigation

The five affected `DatabaseTruncation` tests no longer contain local
`branches`/`payment_methods` guards. The shared pre-setup reset repairs the
stale migration marker before Laravel selects the in-memory PDO. This restores
the normal test lifecycle without changing product constraints, assertions,
PostgreSQL paths, or production migrations.
