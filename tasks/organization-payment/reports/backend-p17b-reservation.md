# P17b-a reservation and invoice recovery evidence audit

Date: 2026-09-05
Audited root: `b38e38a8b17e3c159d0378d34b938a3588fd9182` (the root had advanced
beyond the requested `9c7aac8` when this audit began).
Result: report-only. No new PostgreSQL test is justified for this backend slice.

## Evidence standard

Only tests that fork independent PHP processes, coordinate them with socket
barriers, and observe a PostgreSQL backend in `wait_event_type = Lock` are counted
as concurrency evidence. A sequential retry, a database purge, or an injected
exception is recorded as recovery or rollback evidence, not relabeled as
concurrency.

The four audited suites require `pcntl_fork` and fail instead of skipping when it
is unavailable. Their race helpers use independent process database connections;
the parent holds a canonical row lock, waits until the children report their
backend PIDs, observes the lock wait through `pg_stat_activity`, and only then
releases the contenders. The application actions retain the canonical
organization-first lock order. Providers in the issuance suite are synthetic
in-process implementations; no real provider request is made.

## Acceptance map

### Reservation overlap, replay, and ordering

`AssessmentBillReservationTest::test_two_admins_retry_same_intent_get_the_same_single_bill`
uses the two-process race helper and reverses the second worker's selection. It
asserts identical results for both workers and exactly one bill, three items,
three charges, total 300, one reservation audit, no settlements, and no
entitlements. This covers idempotent replay plus input reordering without a
duplicate bill.

`AssessmentBillReservationTest::test_overlapping_batches_have_one_winner_and_no_partial_loser`
races selections `[A,B]` and `[B,C]`. It asserts one successful reservation, a
`PREVIEW_CHANGED` loser, and exactly one two-item bill totaling 200. This is the
required overlapping-batch concurrency proof and demonstrates that the losing
batch leaves no partial bill, item, charge, audit, settlement, or entitlement.

`AssessmentBillReservationTest::test_self_and_organization_compete_for_the_same_attempt_claim`
races a participant self-pay selection against an organization batch containing
the same attempt. It asserts the self reservation wins, the batch reload fails
with `PREVIEW_CHANGED`, and only the one-attempt self bill remains. The helper
observes the PostgreSQL lock wait, so this is not a sequential self-versus-batch
test.

The policy, payment-method, and price mutation tests in the same class also use
the observed-lock helper and prove the waiting reservation reloads committed
authority. They strengthen fail-closed behavior but are not substitutes for the
three acceptance cases above.

### Invoice claim, rollback, and replay

`AssessmentInvoiceClaimTest::test_waiting_worker_observes_outer_commit_or_rollback`
runs for both commit and rollback. Its child process is observed waiting on the
organization lock. After commit the waiter returns `replayed` with the same
message ID. After rollback it returns `claimed` with a different message ID. Both
paths end with one canonical bill intent; a later exact replay preserves every
outbox attribute and both legacy consumers ignore the invoice topic.

`AssessmentInvoiceClaimTest::test_waiting_claim_reloads_disabled_method_committed_by_other_process`
proves a waiter cannot claim after the payment method is disabled while it waits.
It returns `PAYMENT_METHOD_NOT_AVAILABLE` and leaves no intent, status change, or
audit.

`AssessmentInvoiceClaimTest::test_insert_failure_rolls_back_status_outbox_and_audit`
injects failures at the outbox and audit inserts. It proves atomic rollback, but
it is sequential failure injection and is not counted as concurrency evidence.

### Issuance permit, crash boundary, and unknown recovery

`AssessmentBillInvoiceIssuanceTest::test_two_runtime_processes_have_one_permit_winner_and_one_create`
runs two independent issuers behind the observed organization lock. It asserts
one `issued` result, one `recovery_required` result, exactly one `createInvoice`,
exactly one strict lookup, attempts fixed at one, one permit audit, one issued
audit, a pending bill with the provider reference, and no entitlement.

`AssessmentBillInvoiceIssuanceTest::test_committed_crash_boundary_is_visible_and_never_rearmed`
commits the permit, purges the connection to model loss of the issuing worker,
then observes durable `processing/attempts=1`. A retry returns
`recovery_required` with zero create and zero lookup. This proves the documented
post-permit crash boundary does not rearm issuance. It does not claim an OS-level
kill at an arbitrary instruction.

`AssessmentBillInvoiceIssuanceTest::test_two_runtime_reconcilers_persist_one_exact_result_without_create`
and `::test_two_runtime_reconcilers_move_unknown_once_without_create_or_audit_spam`
use the same independent-process/observed-lock race. Exact recovery permits zero
creates and only one persisted invoice/audit. Unknown recovery permits zero
creates, keeps attempts at one, stores canonical
`failed/INVOICE_OUTCOME_UNKNOWN`, and writes exactly one unknown audit. The suite
correctly allows one or two read-only lookups because that older recovery path has
no durable lookup lease; it does not claim global single-GET behavior.

`AssessmentInvoiceReconciliationLeaseValidationTest::test_two_validators_yield_one_rotated_permit_while_phase_one_bypasses_organization_lock`
adds the durable-lease proof: two validators serialize to one rotated permit and
one lookup generation while provisional phase one reserves a different hint
without waiting on the deliberately held organization lock.

`AssessmentInvoiceReconciliationLeaseValidationTest::test_issuance_and_leased_exact_results_serialize_to_one_persistence`
races original issuance against a leased exact result. It asserts one issued
outcome, a fenced `recovery_required` stale result, one persisted invoice/audit,
and cleared lease metadata. `::test_leased_exact_response_is_discarded_after_token_is_stolen_during_lookup`
proves a late response cannot overwrite a newer lease owner.

## What this backend audit does not prove

- The reservation rollback test fails on its third item, not after a fifth item,
  and is sequential. The P17b acceptance phrase "crash after item kelima" belongs
  to the finalization/settlement lane, where a ten-item bill can be partially
  traversed and the whole settlement transaction must roll back.
- Parallel webhook versus manual review and reordered paid/expired/rejected events
  are finalizer/manual-review concerns. They are outside this backend reservation
  and issuance ownership and must be mapped by the portal P17b audit.
- The committed issuance crash test models the durable boundary directly; it does
  not kill an operating-system process between arbitrary PHP statements.
- No browser, scheduler, command registration, live endpoint, provider network,
  active database, or deployment behavior is covered or enabled here.

These limits do not reveal a missing backend reservation/issuance invariant. A
new `OrganizationBillingReservationRecoveryTest.php` would duplicate existing
fork/barrier/lock evidence, so it was intentionally not created.

## Verification

The repository-supported full disposable command is:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tools/testing/run-org-postgres.ps1
```

It was run from root at the audited commit. Actual result: **392 tests / 3,799
assertions**, 85.722 seconds, success. The runner used an internal Docker network
with no published ports and removed its labeled runner, database container, and
network afterward.

For a focused invocation, the PHPUnit payload inside the same disposable runner
environment is:

```text
php vendor/bin/phpunit --configuration phpunit.organization-postgres.xml tests/Postgres/AssessmentBillReservationTest.php tests/Postgres/AssessmentInvoiceClaimTest.php tests/Postgres/AssessmentBillInvoiceIssuanceTest.php tests/Postgres/AssessmentInvoiceReconciliationLeaseValidationTest.php
```

The current PowerShell harness does not forward PHPUnit path arguments, so the
payload above must be supplied inside an equivalently isolated disposable runner;
it must not be run against a host or active PostgreSQL database. The full supported
runner already executed all four files successfully in this audit.
