# F9 local PostgreSQL backup and restore rehearsal — 2026-09-14

## Verdict

**PASS for the bounded local logical-dump rehearsal.** Two consecutive runs
created and inspected a PostgreSQL custom archive, restored it atomically into a
clean destination, reproduced the accepted synthetic session/result integrity
graph, matched source and destination schema/data/sequence fingerprints, and
rejected a truncated archive without partial accepted destination state.

This evidence does **not** prove production backup readiness, encryption,
off-host/object-storage retention, WAL archiving, PITR, or production RPO/RTO.
F9 remains `partial`, every phase exit remains open/partial/deferred as recorded
in the canonical acceptance matrix, and no T-01..T-28 status changes here.

## Identity and ownership

- Task: `/root/f9_backup_restore_rehearsal`.
- Branch: `codex/f9-backup-restore-rehearsal`.
- Worktree:
  `C:/Users/ThinkPad/.codex/worktrees/f9-backup-restore/Psikotes`.
- Exact baseline: `8bde313b0feafcbe5b79038267c3a3890e42f4d3`.
- Rehearsed implementation commit:
  `3f4012d3433a07eb8d642af8094b3eedc274ab1c`.
- Final evidence commit: reported to the coordinator after commit because a
  commit cannot embed its own SHA.
- Exclusive repository writes:
  - `tools/testing/run-postgres-backup-restore.ps1` (new);
  - `tools/testing/tests/postgres-backup-restore-contract.ps1` (new);
  - this handoff (new).

No existing file changed. Migrations, schema, application/production code,
routes, API/DTO/ADR/contracts, configuration, Compose, lockfiles, checklists,
and feature state are byte-untouched.

## Existing authority and boundary

- The PRD reliability row requires scheduled backup plus restore testing.
- `DEPLOYMENT.md` requires encrypted daily PostgreSQL dumps and quarterly
  restore tests.
- `DATABASE_SCHEMA.md` requires PITR plus daily dumps.
- `tasks/f2-f9-acceptance.md` explicitly leaves F9 backup/recovery evidence
  open.

Only the locally provable logical restore subset is exercised. Encryption,
S3-compatible storage, retention, WAL/base backup/PITR, alerting, deployment,
production data, and recovery objectives require separate operational
authority and infrastructure.

## Fail-closed runner contract

The runner requires a full lowercase `ExpectedCommit`, exact HEAD equality, a
clean tracked/untracked worktree, and a real Composer vendor directory mounted
read-only. It creates a Git archive from the expected commit so the migrated
application tree is immutable. The application runtime is
`psikotes-app:dev`; PostgreSQL is pinned to `postgres:17.6-alpine`.

Every run creates one GUID-scoped Docker label and internal-only network, with
no published ports. PostgreSQL storage is tmpfs. Source, successful destination,
and corrupt-probe destination have fixed task-local names within that disposable
cluster. The archive and immutable source tar live under a GUID-scoped system
temporary directory outside the repository. No `.env`, production secret, real
participant data, payment, notification, scheduler, deployment, or live
application container is used.

The source is migrated through the exact baseline. A bounded synthetic graph is
then inserted:

```text
branch 1 -> participant 1 -> assessment case 1 -> submitted IST session 1
         -> inactive synthetic instrument version 1
         -> immutable generic result 1 -> ordered sources 9 (SE..ME)
```

The custom archive is created with `pg_dump --format=custom`, inspected with
`pg_restore --list`, and restored with `pg_restore --single-transaction
--exit-on-error --no-owner`. Source and destination must match on:

- relations, columns/defaults/nullability, constraints including compared CHECK
  expressions, indexes including compared expression/predicate definitions,
  triggers, policies including compared USING/WITH CHECK expressions, functions,
  RLS/FORCE-RLS flags, relation owners, and explicit relation ACLs;
- exact row/value JSON for the seven graph tables, including stored checksums;
- public and DASS sequence definitions (type, start, minimum, maximum,
  increment, cycle, and cache) plus direct per-sequence `last_value` and
  `is_called` state;
- explicit graph cardinality `1|1|1|1|1|9`.

PostgreSQL can re-deparse one observed family of equivalent string-literal array
casts differently after dump/restore. The schema manifest applies one narrow,
token-preserving canonicalization: an array whose every member is a quoted
string literal cast from `character varying`, represented either with an outer
`text[]` cast or per-member `text` casts, is rendered in one common form. No
case-folding, whitespace removal, or generic parenthesis removal occurs. Literal
bytes, quoted-identifier case, and unrelated Boolean grouping therefore remain
distinguishable. Contract regressions exercise both equivalence and those three
non-equivalences. Expressions are base64-delimited before the manifest transform
so unrelated catalog definitions are untouched. Archive inspection plus
exit-on-error restoration and the structural catalog manifest remain independent
gates.

A byte-truncated copy is restored into a separate clean database using the same
single-transaction/exit-on-error flags. Success is forbidden; after the expected
nonzero exit, `migrations`, `branches`, and both result-ledger tables must all be
absent. Cleanup selects only the exact label, validates the network label before
removal, attests zero remaining containers/networks, and removes the task temp
directory on success or ordinary failure.

## TDD and verification evidence

RED was recorded before the runner existed:

```text
Backup/restore runner is missing.
```

The Tech Lead repair contract was then made RED before implementation:

```text
Sequence fingerprint must include the sequence data type.
```

That repair contract also added executable expression regressions and static
coverage requirements for every sequence definition/state field described
above.

GREEN contract command:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass `
  -File ./tools/testing/tests/postgres-backup-restore-contract.ps1
```

Result:

```text
postgres-backup-restore-contract: PASS (37 assertions)
```

Rehearsal command (run twice from the clean implementation commit):

```powershell
$sha = (git rev-parse HEAD).Trim()
powershell -NoProfile -ExecutionPolicy Bypass `
  -File ./tools/testing/run-postgres-backup-restore.ps1 `
  -ExpectedCommit $sha `
  -VendorDirectory D:/LSI/Web/Psikotes/vendor
```

Run 1:

```text
label=oncam.f9-backup-restore=e94e799e1abd46e2aee8903868efbe17
dump_elapsed_ms=434 restore_elapsed_ms=659 archive_bytes=452708
schema_sha256=d4ddb82b5f96267e40f110f745632e6f2f8781ba83bbb5eff6990701ca2d9f17
data_sha256=9be4a78414c35d41becabb3c844141c1dc7b7dd505677595b9212e906417e125
sequence_sha256=f848bfc8f2debe756e02d53aa8576b4fb851c1c75ece2e59cd7767ad01985768
graph=1|1|1|1|1|9 corrupt_restore_exit=1 corrupt_partial_state=0
cleanup containers=0 networks=0 temp_removed=True
```

Run 2:

```text
label=oncam.f9-backup-restore=65f6aa6acf11487fb2a90c3fae583e40
dump_elapsed_ms=424 restore_elapsed_ms=674 archive_bytes=452708
schema_sha256=d4ddb82b5f96267e40f110f745632e6f2f8781ba83bbb5eff6990701ca2d9f17
data_sha256=9be4a78414c35d41becabb3c844141c1dc7b7dd505677595b9212e906417e125
sequence_sha256=f848bfc8f2debe756e02d53aa8576b4fb851c1c75ece2e59cd7767ad01985768
graph=1|1|1|1|1|9 corrupt_restore_exit=1 corrupt_partial_state=0
cleanup containers=0 networks=0 temp_removed=True
```

The measured times and archive size describe this small synthetic local run
only. They are not capacity results and do not establish RPO or RTO.

## Canonical integration verification

After independent QA PASS and explicit PM approval, the audited patch was
integrated patch-equivalently onto canonical baseline
`8bde313b0feafcbe5b79038267c3a3890e42f4d3`. The integrated implementation and
evidence stack was `1d8c09a693ce14db4e46ee4bee5e6a0babeb2e0e`; its aggregate stable patch ID
was `9c795252c45f2cf0df378e9da12c715174228054`, and all three file blobs matched
candidate `0f1fabdc998ea2e196751398d851e282fa6cbe4d` exactly.

The 37-assertion contract passed on the clean canonical worktree. A fresh full
canonical rehearsal then reported:

```text
label=oncam.f9-backup-restore=0a495a6db7e8461992c9bcb3721cbbaa
dump_elapsed_ms=596 restore_elapsed_ms=1283 archive_bytes=452708
schema_sha256=d4ddb82b5f96267e40f110f745632e6f2f8781ba83bbb5eff6990701ca2d9f17
data_sha256=9be4a78414c35d41becabb3c844141c1dc7b7dd505677595b9212e906417e125
sequence_sha256=f848bfc8f2debe756e02d53aa8576b4fb851c1c75ece2e59cd7767ad01985768
graph=1|1|1|1|1|9 corrupt_restore_exit=1 corrupt_partial_state=0
cleanup containers=0 networks=0 temp_removed=True
```

These canonical results preserve the same bounded claim: local logical restore
rehearsal only. F9 remains `partial`, and release status remains `NO-GO`.

Security verification before staging this handoff passed the repository
scanner suite at 50/50 and both PII and SECRET profiles at 1,398 tracked paths
across index and working-tree snapshots. The final staged three-file candidate
is rerun after this file enters the index; that result is reported with the
immutable commit because this handoff is not edited afterward.

During TDD, ordinary failures at bootstrap, payload validation, catalog
comparison, and the negative restore all exercised exact-label teardown. Every
completed attempt attested zero labeled containers/networks and temp removal.
One deliberately interrupted performance experiment was inventoried by exact
label/name before manual removal and independently confirmed at 0/0; no broad
Docker cleanup was used.

## Remaining gates and next dependency

Before any production claim, a separately authorized operational lane must
choose and prove encryption/key custody, destination/account isolation,
retention/immutability, scheduling, alert ownership, access control, WAL/base
backup and PITR procedure, representative production-like data volume, and
approved RPO/RTO. None may be inferred from this rehearsal.

Review status: **Tech Lead and independent QA passed; PM approved; canonical
integration and post-integration execution verification completed.** The
bounded local rehearsal is accepted as evidence, while F9 remains `partial` and
release status remains `NO-GO` pending the operational gates above.
