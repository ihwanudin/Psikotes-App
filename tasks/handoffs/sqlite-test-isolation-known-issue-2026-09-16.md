# SQLite test-isolation known issue — 2026-09-16

## Status

Open, test-local technical debt. The root cause has not been isolated to one
test or Laravel framework transition. It does not change PostgreSQL schema or
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

## Mitigation

The five affected `DatabaseTruncation` tests now check for `branches` and
`payment_methods` immediately after their parent setup. If either is absent,
they rebuild the disposable SQLite test schema with `migrate:fresh` before
creating fixtures. This restores a test precondition only: no product
constraint, assertion, PostgreSQL path, or production migration changes.

Future work should instrument PHPUnit/Laravel database lifecycle events to
identify the transition that leaves `RefreshDatabaseState::$migrated` true
while the current SQLite PDO lacks the baseline schema, then remove these
guards once a root-cause fix is accepted.
