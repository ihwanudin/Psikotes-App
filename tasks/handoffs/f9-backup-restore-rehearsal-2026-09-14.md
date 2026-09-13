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
  `a2aee8e9df8e6233211a27d749dc2513322b943c`.
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

- relations, columns/defaults/nullability, constraints including normalized
  CHECK semantics, indexes including normalized expression/predicate semantics,
  triggers, policies including normalized USING/WITH CHECK semantics, functions,
  RLS/FORCE-RLS flags, owners, and explicit ACLs;
- exact row/value JSON for the seven graph tables, including stored checksums;
- public and DASS sequence state;
- explicit graph cardinality `1|1|1|1|1|9`.

PostgreSQL can re-deparse equivalent array casts differently after dump/restore.
The schema manifest therefore normalizes only redundant `character varying` and
`text` casts, parentheses, whitespace, and case; operators, literals, columns,
functions, membership, and Boolean logic remain in the fingerprint. Archive
inspection plus exit-on-error restoration and the structural catalog manifest
remain independent gates.

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

GREEN contract command:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass `
  -File ./tools/testing/tests/postgres-backup-restore-contract.ps1
```

Result:

```text
postgres-backup-restore-contract: PASS (24 assertions)
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
label=oncam.f9-backup-restore=6eaa4f92231e4db4bf6f2591baaf077d
dump_elapsed_ms=397 restore_elapsed_ms=759 archive_bytes=452708
schema_sha256=d1b181e91d7e32595e56919a2273aeb19c71cfb86f7ef80971d75cdc7f595cf7
data_sha256=9be4a78414c35d41becabb3c844141c1dc7b7dd505677595b9212e906417e125
sequence_sha256=abfe78e60afb6c85de3d025ac016553fff1cc17ac0d75cdc8fb11800f371500b
graph=1|1|1|1|1|9 corrupt_restore_exit=1 corrupt_partial_state=0
cleanup containers=0 networks=0 temp_removed=True
```

Run 2:

```text
label=oncam.f9-backup-restore=1452937a1f504433a260f8f0d4c28e05
dump_elapsed_ms=381 restore_elapsed_ms=676 archive_bytes=452708
schema_sha256=d1b181e91d7e32595e56919a2273aeb19c71cfb86f7ef80971d75cdc7f595cf7
data_sha256=9be4a78414c35d41becabb3c844141c1dc7b7dd505677595b9212e906417e125
sequence_sha256=abfe78e60afb6c85de3d025ac016553fff1cc17ac0d75cdc8fb11800f371500b
graph=1|1|1|1|1|9 corrupt_restore_exit=1 corrupt_partial_state=0
cleanup containers=0 networks=0 temp_removed=True
```

The measured times and archive size describe this small synthetic local run
only. They are not capacity results and do not establish RPO or RTO.

Security verification before staging this handoff passed the repository
scanner suite at 50/50 and both PII and SECRET profiles at 1,397 tracked paths
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

Review status: **implementation complete; pending Tech Lead review and
independent QA. Do not integrate from this handoff alone.**
