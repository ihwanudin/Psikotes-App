# P14c — diagnosis combined PHPUnit database state

2026-09-04. **Diagnosis only; no fix applied.** The `branches` error is an
order-dependent SQLite-memory test lifecycle problem that exists with both the
pre-extraction and current settlement callers. It is separate from the sandbox
skip and the missing Vite manifest. Baseline retained at worker `c15bdbf`; only
this report is committed. No projector/HTTP/consent/P15 work was performed.

## Smallest existing-test reproduction

Run from the worker repository with installed PHP 8.3.26 / PHPUnit 12.5.33 /
Laravel v13.26.1 and
the unchanged guarded organization-payment XML. Two selected test methods are
sufficient; neither entire classes nor the full suite are needed:

```powershell
php vendor/bin/phpunit -c phpunit.organization-payment.xml tests/Feature/Payments/AssessmentBillPaymentFinalizationTest.php tests/Feature/Payments/AssessmentInvoiceClaimTest.php --filter 'test_collective_payment_settles_every_item_and_activates_only_complete_attempts|test_claim_is_atomic_and_replay_does_not_extend_or_dispatch' --do-not-cache-result
```

Actual result: **2 tests, 1 passed, 9 assertions, 1 error, exit 1**. The finalizer
test passes; claim fails in fixture creation after its parent setup:

```text
AssessmentInvoiceClaimTest::test_claim_is_atomic_and_replay_does_not_extend_or_dispatch
SQLSTATE[HY000]: General error: 1 no such table: branches
(Connection: sqlite, Database: :memory:, SQL: insert into "branches" ...)
```

This fails before the claim action or settlement reader runs in the second test.
The synthetic insert values are not relevant to the cause and are omitted here.
Reverse only the two file arguments, retaining the same filter:

```powershell
php vendor/bin/phpunit -c phpunit.organization-payment.xml tests/Feature/Payments/AssessmentInvoiceClaimTest.php tests/Feature/Payments/AssessmentBillPaymentFinalizationTest.php --filter 'test_collective_payment_settles_every_item_and_activates_only_complete_attempts|test_claim_is_atomic_and_replay_does_not_extend_or_dispatch' --do-not-cache-result
```

Actual result: **2 tests, 2 passed, 40 assertions, exit 0**. Each complete class
also passed separately in the preceding accepted extraction evidence: finalizer
10/65 and claim 60/444. Reordering or separate processes demonstrates the defect;
neither is proposed as a substitute for fixing shared test-state isolation.

## Installed-source cause and runtime trace

The hierarchy is PHPUnit → Laravel Foundation TestCase → `Tests\TestCase` →
`Tests\OrganizationPaymentTestCase` → these concrete feature classes.
OrganizationPaymentTestCase creates a fresh application, installs safety guards
and fakes, and permits only testing + SQLite `:memory:` without DB_URL. It does
not reset Laravel's static migration state or preserve a truncation PDO.

Relevant source locations, all read from the installed working copy:

| Source | Relevant behavior |
| --- | --- |
| `tests/Feature/Payments/AssessmentBillPaymentFinalizationTest.php:25` | Uses `DatabaseTruncation`. Setup at line 31 sets `RefreshDatabaseState::$migrated=false`; the class has no teardown reset. |
| `tests/Feature/Payments/AssessmentInvoiceClaimTest.php:38` | Uses `RefreshDatabase`, then creates fixtures after parent setup. It reasonably expects parent setup to provide migrated schema. |
| `vendor/laravel/framework/src/Illuminate/Foundation/Testing/DatabaseTruncation.php:27` | When migrated is false, runs `migrate:fresh`, marks the shared flag true at line 37, returns. It does not populate `inMemoryConnections` or register RefreshDatabase's transaction cleanup. |
| `vendor/laravel/framework/src/Illuminate/Foundation/Testing/RefreshDatabaseState.php` | `migrated` and `inMemoryConnections` are static process state, not scoped to an application/PDO. |
| `vendor/laravel/framework/src/Illuminate/Foundation/Testing/Concerns/InteractsWithTestCaseLifecycle.php:96` | Creates the application then runs testing traits. `setUpTraits` at line 222 invokes the selected database trait. |
| Same lifecycle file, line 126 | Teardown invokes callbacks, flushes the application and clears the test's app reference. It does not reset those two RefreshDatabase static fields. |
| `vendor/laravel/framework/src/Illuminate/Foundation/Testing/RefreshDatabase.php:65` | Restores a cached PDO only if a matching entry exists. A fresh truncation-first process has no such entry. |
| Same file, line 81 | Skips migration when the shared flag is already true, without checking that it describes the current SQLite PDO. |
| Same file, lines 101 and 127 | Caches PDO after its own migration, or with `??=` when beginning its transaction. In the failing sequence, the latter caches the new **empty** PDO. |

A temporary PHPUnit bootstrap subscribed to preparation-started, prepared,
errored and finished events. It only read the static flag/cache and already-open
PDOs via `getRawPdo()`; `SELECT count(*) FROM sqlite_master ... name='branches'`
observed schema presence. It did not create connections, reset flags, change
assertions/FKs, migrate, mutate data or intercept production methods. The
un-instrumented two-test command already reproduced the same error.

Observed current-code trace (PDO numbers are only local object identities):

| Event | migrated | Cached SQLite PDO | Active SQLite PDO / branches exists |
| --- | --- | --- | --- |
| Finalizer preparation starts | false | absent | absent |
| Finalizer prepared | true | absent | 3285 / yes |
| Finalizer finished | true | absent | absent from flushed application |
| Claim preparation starts | true | absent | absent |
| Claim errors | true | 4590, no branches | 4590 / no |

Thus the flag describes migration on an earlier PDO, while the next test opens a
different empty SQLite database. RefreshDatabase skips migration, begins its
transaction and caches that empty PDO. Later RefreshDatabase tests can keep
restoring the same empty PDO while the flag stays true. Its normal rollback
callback does not necessarily set the flag false: the transaction is still
active, so the special “not in transaction” reset does not apply. This explains
why missing-table errors can cascade through many methods in a combined run.

If a preceding RefreshDatabase test has already cached a valid PDO, it can mask
the defect. In the reverse-order trace, claim caches PDO 3286 with branches;
finalizer then migrates a different PDO 4668 while the original cached PDO remains
available. That ordering passes, but does not make the shared state correct for
all sequences. This is not an asynchronous race, payment lock-order failure,
active-database issue, or missing migration file.

## Pre-extraction comparison without changing baseline

Temporary diagnostics live outside Git at
`C:/Users/ThinkPad/AppData/Local/Temp/oncam-p14c-diagnosis-b53a9c4d0ad6402d9fdfbf5ea4034d4c`.
Only source snapshots, bootstrap, trace and synthetic PHPUnit report are stored
there; no .env, credentials, real participant data, active DB or cache was copied.
The existing blocked browser scratch cleanup was not touched.

The alternate bootstrap first requires the normal `vendor/autoload.php`, then,
only under process-local `P14C_OLD_CLASSES=1`, preloads the original classes:

- `ActivateSettledAssessment.php` from `git show
  3b20892:app/Actions/Payments/ActivateSettledAssessment.php`, written with LF;
  SHA256 `b08170681749bec18c225606d0543c4550fa68cc0e2f4136fba0cb0962e868ef`.
- Gate from the retained pre-extraction source copy, SHA256
  `d9feedeeea8567e023fe813cd2ad53b3009696910839216920decb27caead34f`, already matched
  to the root baseline before extraction. The gate was untracked in this worker,
  so it was not reconstructed from a guessed commit.

Both classes retain their real namespaces and run against the same current
test fixtures/framework. Preloading prevents Composer from loading their current
versions; no baseline checkout, class alias, mock, reset or application edit is
used. These were the only existing production classes changed by extraction;
the new reader is not called by the old callers. This is a controlled comparison
of that refactor, not a claim to have executed an entire historical checkout.

Reproduction with the retained temporary bootstrap:

```powershell
$env:P14C_OLD_CLASSES = '1'
php vendor/bin/phpunit -c phpunit.organization-payment.xml --bootstrap C:/Users/ThinkPad/AppData/Local/Temp/oncam-p14c-diagnosis-b53a9c4d0ad6402d9fdfbf5ea4034d4c/bootstrap.php tests/Feature/Payments/AssessmentBillPaymentFinalizationTest.php tests/Feature/Payments/AssessmentInvoiceClaimTest.php --filter 'test_collective_payment_settles_every_item_and_activates_only_complete_attempts|test_claim_is_atomic_and_replay_does_not_extend_or_dispatch' --do-not-cache-result
```

| Classes | Ordered pair | Actual PHPUnit result |
| --- | --- | --- |
| Current, normal bootstrap | Finalizer → claim | 2 tests; 1 pass, 1 error; 9 assertions |
| Current, observer bootstrap | Finalizer → claim | Same result; empty cached PDO observed |
| Pre-extraction, observer bootstrap | Finalizer → claim | Same result; empty cached PDO 4592 observed |
| Current, normal bootstrap | Claim → finalizer | 2 pass; 40 assertions |
| Pre-extraction, observer bootstrap | Claim → finalizer | 2 pass; 40 assertions |

The diagnostic shell sometimes read the trace after PHPUnit, so its wrapper exit
became the successful read command's status. The PHPUnit result itself remained
one error; the standalone failing command's exit 1 was independently observed.
**The extraction did not introduce this reproduced missing-table defect.**

## Skip and manifest: independent classifications

`tests/Feature/Payments/XenditSandboxContractTest.php` extends generic TestCase,
is marked `Group('sandbox')`, and reads `XENDIT_SECRET_KEY` at line 21. It calls
`markTestSkipped('Xendit development credential is not configured.')` unless the
key starts with `xnd_development_`. Only after that branch would it allow real
Xendit traffic and create/check/expire an invoice. That branch was never enabled.

The organization XML explicitly forces the secret to empty (line 31) and sets
`failOnSkipped=true` (line 7). A single guarded invocation with the process key
also explicitly empty confirmed **1 test, 0 passed, 0 assertions, 1 skip, exit 1**:

```powershell
php vendor/bin/phpunit -c phpunit.organization-payment.xml tests/Feature/Payments/XenditSandboxContractTest.php --display-skipped --do-not-cache-result
```

The output formatter labels the overall JSON `result` as passed despite the skip;
the zero passed count and exit 1 are authoritative for this failed gate. The
reason is established directly by the sole conditional skip in installed test
source. Providing credentials is not an authorized fix for an offline suite.
Explicit directory arguments collect this sandbox file; the XML's default
four-file suite does not include it. Do not infer a new Fortify-feature skip.

The manifest failure also reproduces alone:

```powershell
php vendor/bin/phpunit -c phpunit.organization-payment.xml tests/Feature/Payments/ManualPaymentProofUploadTest.php --filter test_received_page_exposes_only_the_session_bound_manual_order_state --do-not-cache-result
```

Actual result: **1 test, 0 passed, 1 assertion, 1 failure, exit 1**. The route
returns 500 instead of 200 because `ViteManifestNotFoundException` reports missing
`public/build/manifest.json`, through `resources/views/app.blade.php`. This case
gets past DB setup and is not the missing-branches defect. No fake manifest or
view/middleware bypass was installed. Real frontend build/dependencies and page
validation remain separately owned; no build acceptance is claimed here.

## Proposed minimal fix and ownership — not applied

Recommend a bounded test-harness change to `tests/OrganizationPaymentTestCase.php`:
in guaranteed teardown cleanup, **only for tests using DatabaseTruncation in this
already-guarded SQLite-memory base**, invalidate
`RefreshDatabaseState::$migrated` after application teardown, including failure
paths. Preserve normal parent teardown/callbacks; do not replace them. The next
RefreshDatabase test will migrate whichever PDO it restores/opens. Do not mutate
business data, clear constraints, patch vendor code, or change production RLS.
An unconditional reset for every test would unnecessarily discard normal
RefreshDatabase caching. Caching a truncation PDO instead could retain committed
fixture data; it is not the minimal safe correction proposed here.

Add an explicit ordered-process regression under database harness tests covering
truncation → refresh, refresh → truncation → refresh, and a teardown failure path;
use the real guarded XML. Existing class-specific teardown resets may remain
temporarily as idempotent safeguards; removing them is a separate cleanup, not
required for the minimal fix. Preserve the reason these classes use truncation:
they test real commit/outer-transaction behavior and must not be converted to
RefreshDatabase just to hide this defect. No state-reset hypothesis was applied
even in the diagnostic observer; this recommendation still needs test-first
implementation/review.

Static inventory in Payments finds seven truncation classes that set the flag
false in setup but have no teardown reset: AssessmentBillPaymentFinalization,
AssessmentBillManualReview, AssessmentBillManualReviewHttp, AssessmentBillProofAccess,
AssessmentBillProofStorage, AssessmentBillStatusReconciliation and
AssessmentBillWebhookDispatch (all `*Test.php`). Five invoice issuance/reconciliation
classes already reset in teardown finally. Outside that directory, the two
AssessmentBillReviewer[Decision]Filament tests have the same setup-only pattern;
their admin lane should review any shared-base change. Inventory is not a claim
that every class pair was executed. Shared base + one regression file should be
the first small ownership request, not seven ad hoc test-file fixes.

Sandbox collection needs a separate, explicit suite/run-contract decision: keep
the external sandbox test intact and in an opt-in credential-authorized workflow,
while defining the offline synthetic suite so it does not accidentally collect
real-provider tests. Do not set failOnSkipped=false, fabricate a secret, enable
stray HTTP, delete the test, or silently filter it and claim all tests passed.
Any XML/runner/test-location change requires coordinator ownership approval.
Manifest/build readiness remains separate from both fixes.

## Verification boundary and handoff

No broad/full-suite repeat was used; only the two-test order comparisons, their
read-only traces/old-class control, and single skip/manifest probes. All database
execution used the existing SQLite-memory guard. PostgreSQL was not needed or
rerun: this diagnosis concerns PHP static test state and SQLite PDO lifetime.
Pint/PHPStan were not rerun and no new code-quality result is claimed for this
documentation-only increment. `git diff --check` and staged diff-check are checked
before the report-only commit. Existing dirty snapshot, gate overlay and all
application/shared harness files remain untouched. **STOP for review before any
fix, DTO/projector, HTTP, consent/P15, or operational work.**
