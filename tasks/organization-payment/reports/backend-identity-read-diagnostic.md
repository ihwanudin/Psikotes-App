# Identity evidence read consistency diagnostic

Scope: diagnostic tests and report only, after composition proposal `adce9c3`.
No identity writer, gate, activation, summary DTO, schema, route or harness change.
This report does not approve the proposed summary composition or clock/form-key seams.

## Reproduction and safety

`tests/Postgres/IdentityEvidenceReadConsistencyTest.php` invokes the actual
`StoreIdentityEvidence` action with synthetic PNG uploads and the actual local
`ManualReviewIdentityMatcher` (returns pending). The initial verification is
explicitly seeded as a synthetic match to establish an initially valid gate state.
No external identity matcher or real identity file is used.

Each writer is a separate process/connection. The reader holds organization,
attempt and participant `FOR UPDATE` locks in that order. A socket barrier starts
the writer after those locks are held. Completion is distinguished from an actual
PostgreSQL lock wait using `pg_stat_activity` and `pg_blocking_pids`; a timer alone
is not treated as evidence of blocking. Connections assert `psikotes_runtime`,
non-owner/non-superuser/NOBYPASSRLS, and clean context/transaction on writer exit.

Gate races use the real `AssessmentEntitlementGate`. A test-only `QueryExecuted`
listener pauses after the verification SELECT has fetched its result and lets
the writer finish before the evidence count. Installed Laravel Connection source
fetches SELECT results before emitting that event. The listener is disarmed after
one use and in cleanup. It does not replace either query or the production gate.

The unique fake disk is asserted to be under the runner's tmpfs
`/workspace/storage/framework/testing/disks/idrd-*`. The initial attempt
to generate images with Laravel's GD helper failed because that disposable image
does not install GD. Only the new test fixture changed to a valid embedded 1x1 PNG
and `UploadedFile::fake()->createWithContent`, with dimensions asserted. This
tests the action's real image metadata/storage path, not HTTP image validation.
The runner/image, production validation and test expectations were not weakened.

## Distinct questions under test

1. **Existing replacement versus participant mutex:** replacing existing evidence
   and verification must wait if the proposed participant mutex is to guarantee
   serialization. The test deliberately retains that safety assertion; a completed
   writer while the lock is held must remain a failure, not an expected pass.
2. **Initial insert control:** removing only synthetic fixture rows forces INSERT.
   The control distinguishes FK-related parent blocking from existing-row UPDATE
   behavior. It does not model a production deletion workflow.
3. **Later timestamp:** old verification at T-5 seconds, actual replacement at T.
   The gate must not accept new evidence through the old timestamp predicate.
4. **Same second:** both revisions share T at the schema's timestamp precision.
   The test separately measures the raced decision and the final decision. An
   initially ready state followed by a pending state means raced ready alone can
   linearize before replacement; it is not proof of readiness that was never valid.
5. **Rollback and file cleanup:** a test-only exception after verification UPDATE
   forces rollback of the real action. Full identity row snapshots and file lists
   must remain equal to the initial state. New files must be removed; old files
   must remain. Successful replacement separately checks old removal/new existence.

## Writer and lock-order audit

The worker and read-only root writer have the same SHA-256:
`d6498f98ef3b07db5997bcbdb7afc63ce2507bf56d000ff06c1e120f7027ef05`.

`StoreIdentityEvidence::handle` stores both files before its service transaction,
loads Participant without an explicit row lock, then saves identity_document and
initial_selfie evidence before `IdentityVerification::updateOrCreate`. It resets
manual review to pending and clears reviewer/reviewed_at. Catching an exception
removes newly stored files; replaced old keys are deleted only after DB success.
The local matcher performs no network request. Matcher errors are converted to an
error result, which is why rollback injection occurs after SQL rather than inside
the matcher.

The application writer search found no separate manual-review decision writer:
the current manual_status/reviewed_at writes are in StoreIdentityEvidence.
Registration creates consent records with withdrawn_at null; no implemented
consent-withdrawal writer was found. Model casts/relations and read/URL issuance
paths are not evidence of a compliant mutation boundary. Future manual-review or
withdrawal writers therefore still need an explicit lock-order review.

`ActivateSettledAssessment` locks organization, bill/items when present, attempt,
participant, charge and entitlements, then consent_records, identity_verifications
and identity_evidence. Its verification-before-evidence ordering differs from
StoreIdentityEvidence's evidence-before-verification writes. Adding child locks
to a reader without resolving that ordering is not an approved fix; this increment
does not claim to reproduce an activation deadlock or test a modified activation.

`AssessmentAccessPrerequisites` reads verification first and then counts evidence
whose updated_at is no later than that captured verification's checked_at.
PostgreSQL READ COMMITTED allows successive statements to see different committed
snapshots, and parent row locks are not a blanket lock on existing child updates.
See official [transaction isolation](https://www.postgresql.org/docs/17/transaction-iso.html)
and [explicit locking](https://www.postgresql.org/docs/17/explicit-locking.html).
Runtime results below, not those general rules alone, determine the finding.

## Actual verification

Final established `tools/testing/run-org-postgres.ps1` run: **355 tests / 2,687
assertions / 1 failure / 0 errors / 0 skips**, exit 1. This is an intentionally
RED diagnostic handoff, not a green suite. The 350 existing tests and four new
characterization/control tests pass; the new mutex regression fails unchanged.
The runner confirmed disposable cleanup and did not target application containers.

| New test | Actual result | Supported conclusion |
| --- | --- | --- |
| Existing replacement mutex | RED: expected blocked_by_parent, actual completed | Real existing-row replacement commits while organization/attempt/participant locks remain held. The proposed parent mutex alone does not serialize this writer. |
| Initial INSERT control | PASS: observed parent blocker, then completion after release | FK-related insertion blocking does not establish the same protection for replacement. |
| Verification T-5, replacement T | PASS: raced gate locked, final gate locked | Old verification does not admit later evidence through the timestamp predicate in this interleaving. |
| Same-second replacement | PASS: initial ready, raced ready, final locked | Old verification plus new evidence is observed, but raced ready can linearize before replacement. No never-valid readiness is proved. |
| Post-verification UPDATE exception | PASS: rows and file list restored exactly | Actual action rolls back SQL, removes new objects and retains prior objects for this caught failure. |

The failure is exactly:

```text
Existing-row StoreIdentityEvidence completed while canonical participant FOR UPDATE was held.
Expected: blocked_by_parent
Actual:   completed
```

**Finding:** parent-mutex bypass and a mixed read are reproduced separately.
**Not established:** an access decision that was invalid at every instant, a
full-summary inconsistency, or an activation deadlock. The scope remains diagnostic;
no production correction was authorized or made.

The first run completed cleanup but reported
**355 tests / 2,597 assertions / 5 errors**, all five during GD fixture setup.
It did not establish the concurrency finding. The second run also reported
**355 tests / 2,597 assertions / 5 errors** during setup: the unique fake disk name
exceeded the existing 32-character column. The fixture prefix was shortened while
retaining the full random ULID and a maximum-length assertion; schema stayed
unchanged. Both runs cleaned up. No skips or suite changes were used.

Scoped Pint and PHP syntax checks passed for the new test. The application
PHPStan configuration excludes tests; no new PHPStan result is claimed for this
test-only increment. `git diff --check` and explicit cached-path checks passed
before the diagnostic commit.

## Limits and review checkpoint

No production fix, summary composition or new writer contract is implemented.
These controlled interleavings cannot prove absence of every race, wrong-ready
case or storage failure. The rollback test covers a caught exception, not process
termination between filesystem and DB operations; crash-orphan cleanup remains
outside this diagnostic. Successful deletion behavior is exercised on synthetic
local storage, not a remote storage outage. No browser, public HTTP, full SQLite
application regression or operational readiness claim is made.

Baseline dirty/untracked overlays remain unchanged and unstaged. No active DB,
`.env`, real data, provider calls, notifier, task/agent, deployment or push.
STOP for coordinator review; do not proceed to a mutex fix or composition from
this diagnostic alone.
