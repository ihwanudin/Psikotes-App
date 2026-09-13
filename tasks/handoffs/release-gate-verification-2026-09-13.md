# Release gate verification — 2026-09-13

## Verdict

**REPAIR-REQUIRED.** The security gate is green, but the full ordered disposable
PostgreSQL suite is not. This is verification evidence only; it does not approve
a release, deployment, migration, route, feature activation, or any F1-F9 phase
exit.

## Identity and scope

- Task: `/root/release_gate_verification`.
- Lane: F9 release-gate verification.
- Branch: `codex/release-gate-verification`.
- Worktree:
  `C:/Users/ThinkPad/.codex/worktrees/release-gate-verification/Psikotes`.
- Immutable baseline and pre-evidence HEAD:
  `06bb24519f805ba7a49ca8004ccd1e9112f72c5a`.
- Result commit: the evidence-only commit containing this record; its immutable
  SHA is reported to the coordinator because a commit cannot embed its own SHA.
- Exclusive repository write: this new handoff file only.
- Explicitly untouched: production code, tests, tools, migrations, routes,
  API/DTO/ADR/domain files, canonical checklists, configuration, manifests,
  lockfiles, and feature state.

The tracked worktree was clean before verification. The worktree-local `vendor`
directory was populated from the existing lockfile-matched installation at
`D:/LSI/Web/Psikotes/vendor`; it is ignored runtime material and did not change
the Git snapshot. No `.env`, production secret, participant data, real payment,
outbound action, live migration, or application container was used.

## Acceptance status recalculation

The canonical `tasks/f2-f9-acceptance.md` matrix still has no complete phase
exit: F1-F5, F7, and F9 are `partial`; F6 is `open`; F8 is `deferred`. No
T-01..T-28 row has complete domain-through-browser evidence. A green regression
run could only have been a release-gate verification PASS, not a release or
go-live approval. This failed PostgreSQL run leaves F9 partial.

## Ordered disposable PostgreSQL evidence

Command:

```powershell
./tools/testing/run-org-postgres.ps1
```

The integrated runner preserved `phpunit.organization-postgres.xml` ordering,
used PostgreSQL `17.6-alpine` and `psikotes-app:dev`, created an internal Docker
network with no published ports, and emitted exact label:

```text
oncam.org-test-run=0ac3a60a8096423fb3d49ec3c22ad714
```

Terminal result:

```text
PHPUnit 12.5.33; PHP 8.3.26
Tests: 553, Assertions: 5522, Errors: 43, Failures: 1
Time: 02:31.106; Memory: 103.00 MB
Skipped: 0; Risky: 0
```

The suite is configured with `failOnSkipped="true"`; the terminal summary
reported no skipped or risky tests. The nonzero result is therefore a genuine
failure, not a skip classification.

### First root cause

The first failing test was
`AssessmentBillingMigrationTest::test_populated_policy_and_schema_rollback_preserve_legacy_and_reupgrade`.
Its direct invocation of the older `2026_09_09_000700` grant migration `down()`
attempted:

```sql
DROP INDEX test_sessions_grant_scope_unique
```

PostgreSQL rejected it with SQLSTATE `2BP01`: the later accepted constraint
`generic_instrument_results_session_scope_fk` depends on that unique index.
This is deterministic historical-migration fixture isolation debt introduced by
the accepted result ledger. In a chronological rollback, the later
`2026_09_13_000100` result-ledger migration would be removed first; these tests
exercise older migrations directly while leaving the later schema installed.

### Failure taxonomy

1. **37 errors — dependent session-scope index.** The billing, case backfill,
   case security, session-case identity, legacy Selection, direct-public case,
   and assessment-session schema fixtures directly roll back the older grant
   migration while the result ledger still references
   `test_sessions_grant_scope_unique`. All fail with SQLSTATE `2BP01`.
2. **3 errors — dependent instrument-version FK.** The instrument-version
   history fixture runs `TRUNCATE TABLE instrument_versions RESTART IDENTITY`
   while `generic_instrument_results` retains its FK to that table. PostgreSQL
   rejects the parent-only truncate with SQLSTATE `0A000` even when child data is
   empty.
3. **3 cascade errors — dirty immutable history.** Because fixture cleanup could
   not reset the referenced table, later history tests reached the populated
   downgrade guard and correctly raised `Cannot weaken populated instrument
   version history; downgrade refused.`
4. **1 cascade failure — leaked synthetic row.** The same failed reset left a
   synthetic IST version in the ordered suite, so the seed equality assertion
   observed one extra row.

No broken-pipe, duplicate payment-method, Storage, Mockery, random-order, or
container-cleanup symptom appeared. The integrated fork-result helper allowed
the suite to reach its complete terminal summary.

### Exact cleanup

After runner teardown, exact-label queries returned:

```text
containers=0
networks=0
```

Commands:

```powershell
$label = 'oncam.org-test-run=0ac3a60a8096423fb3d49ec3c22ad714'
docker ps -aq --filter "label=$label"
docker network ls -q --filter "label=$label"
```

Both commands returned no IDs. No broad prefix or unrelated Docker resource was
selected.

## Security gate evidence

Commands against the same clean baseline snapshot:

```powershell
npm run security:scan:test
node tools/security/repository-content-scan.mjs --kind=pii
node tools/security/repository-content-scan.mjs --kind=secret
```

Results:

- scanner regression suite: **50 tests, 50 passed, 0 failed, 0 skipped**;
- PII profile: **PASS**, 1,393 tracked paths, index and working-tree snapshots;
- SECRET profile: **PASS**, 1,393 tracked paths, index and working-tree snapshots.

The finalized staged candidate is expected to contain 1,394 tracked paths due
solely to this handoff file; both profiles are rerun before commit without
changing the file afterward.

## Proposed disjoint repair charter

Open one test-infrastructure lane; do not repair in this verification lane.

Purpose: make historical PostgreSQL migration fixtures explicitly suspend and
restore the later immutable result-ledger schema before they invoke older
`down()` methods or parent-only instrument-history resets. Preserve the accepted
production migrations, FK topology, append-only behavior, RLS, ACLs, and normal
reverse-chronological migration semantics.

Candidate exclusive ownership:

- one new helper under `tests/Support/` if shared lifecycle code is needed;
- `tests/Postgres/AssessmentBillingMigrationTest.php`;
- `tests/Postgres/AssessmentCaseBackfillMigrationTest.php`;
- `tests/Postgres/AssessmentCaseSecurityTest.php`;
- `tests/Postgres/TestSessionCaseIdentityMigrationTest.php`;
- `tests/Postgres/LegacySelectionCaseIdentityMigrationTest.php`;
- `tests/Postgres/DirectPublicOrderCaseIdentityMigrationTest.php`;
- `tests/Postgres/AssessmentSessionSchemaTest.php`;
- `tests/Postgres/InstrumentVersionHistorySecurityTest.php`;
- `phpunit.organization-postgres.xml` only if an explicit ordered compatibility
  boundary is required.

The lane must not edit any migration or production file. It should prove RED on
the exact 44 failing/error cases, then GREEN in focused and full ordered runs;
verify exact pre/post schema definitions and data cleanup; restore the result
ledger in `finally` paths; preserve fail-closed populated downgrade behavior;
show 0 skipped/risky tests; rerun PII/SECRET; and prove exact labeled Docker
cleanup at 0 containers/0 networks. If safe suspension/restoration cannot be
proved without changing a production migration, stop and return a narrower
architecture decision to the coordinator rather than weakening constraints.

## Other current blockers and next dependency

- F2 real session start and S5 HTTP remain blocked because the approved
  four-instrument definition manifest is still 0/4 start-ready; active catalog
  data or synthetic production success must not be invented.
- PAPI, RMIB, and Kraepelin authoritative result persistence/composition remain
  blocked on their complete source contracts; Kraepelin additionally needs its
  separate event persistence/sealing boundary. DASS remains a separate
  lifecycle and must not enter the generic ledger.
- F3-F6 runtime integration remains downstream of complete immutable
  multi-instrument result/aspect authority and reviewed report persistence; the
  accepted IST reader alone is insufficient.
- F1 still lacks Xendit end-to-end, full browser/operations evidence, and human
  credential authority. F7 commission work still lacks product decisions for
  rate/base/rounding, eligibility, timezone, free/refund, and payout rules.
- Go-live additionally remains blocked on the recorded licensing, legal consent,
  responsible-psychologist, Japanese translation, backup/restore,
  observability, load, and recovery authorities.

Immediate next dependency: independent review of this evidence, then a
PM-authorized test-fixture compatibility lane. Only after its immutable commit
passes independent QA should the full ordered disposable PostgreSQL suite and
security profiles be rerun for a new release-gate verdict.

Review status: **repair-required; pending independent QA review**.
