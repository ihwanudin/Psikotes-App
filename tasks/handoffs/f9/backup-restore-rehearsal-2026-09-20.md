# F9 local PostgreSQL backup and restore rehearsal — 2026-09-20 (refresh)

## Verdict

**PASS.** Refreshes the 2026-09-14 rehearsal on top of current `main` after 60+
migrations landed since the original run. Same bounded scope, same harness,
unchanged: two consecutive runs, deterministic fingerprints, exit-on-error
restore, and a rejected corrupt archive with no partial destination state.

This evidence does **not** prove production backup readiness. It does not
implement the targets recorded in
[`tasks/handoffs/f9/backup-restore-decision-2026-09-20.md`](f9-backup-restore-decision-2026-09-20.md)
(encryption, off-host copy, 30-day retention, 8h RTO) — those remain blocked
on two user decisions (key custody, off-host storage target) recorded there.
F9 remains `partial`.

## Identity and ownership

- Lane: Codex#2 channel (infra + docs).
- Worktree: this worktree, detached at the exact commit below (clean,
  no uncommitted changes at rehearsal time).
- Exact commit rehearsed: `ec544003ba7a9fb7cb9631faf1da34763e617bdc` (`main`
  immediately after PR #24 merged).
- No repository files changed by the rehearsal itself. This document is the
  only write.

## Why refresh now

The harness (`tools/testing/run-postgres-backup-restore.ps1`,
`tools/testing/tests/postgres-backup-restore-contract.ps1`) is unchanged since
2026-09-14 — confirmed by identical blob hashes between `main` and the old
`codex/f9-backup-restore-rehearsal` branch before this run. What changed is
the schema being backed up: 60+ migrations landed since the original
rehearsal (`8bde313` → `ec54400`). A rehearsal is only evidence for the schema
it actually exercised; six-day-old evidence against a much smaller schema was
the gap this closes.

## Contract command and result

```powershell
powershell -NoProfile -ExecutionPolicy Bypass `
  -File tools/testing/tests/postgres-backup-restore-contract.ps1
```

```text
postgres-backup-restore-contract: PASS (37 assertions)
```

Identical assertion count to 2026-09-14. The harness's own correctness checks
are unaffected by schema growth.

## Rehearsal command (run twice, per the established protocol)

```powershell
powershell -NoProfile -ExecutionPolicy Bypass `
  -File tools/testing/run-postgres-backup-restore.ps1 `
  -ExpectedCommit ec544003ba7a9fb7cb9631faf1da34763e617bdc `
  -VendorDirectory <path>/vendor
```

Run 1:

```text
F9_BACKUP_RESTORE_PASS label=oncam.f9-backup-restore=09654e30b39740ec8c4eef7d875e8823 commit=ec544003ba7a9fb7cb9631faf1da34763e617bdc
dump_elapsed_ms=1938 restore_elapsed_ms=4035 archive_bytes=531607
schema_sha256=93271428d5765497718224ee1f8f930d5db98da7e2c2aa9384d0eb4f05aff976
data_sha256=9be4a78414c35d41becabb3c844141c1dc7b7dd505677595b9212e906417e125
sequence_sha256=1b1856ff6af90e69007fc3e721bb54db0bcec4ca51995982cae48821c45d786b
graph=1|1|1|1|1|9 corrupt_restore_exit=1 corrupt_partial_state=0
cleanup containers=0 networks=0 temp_removed=True
```

Run 2:

```text
F9_BACKUP_RESTORE_PASS label=oncam.f9-backup-restore=5e7c4ccc61b34a8ba9e65e8269340511 commit=ec544003ba7a9fb7cb9631faf1da34763e617bdc
dump_elapsed_ms=1013 restore_elapsed_ms=1948 archive_bytes=531607
schema_sha256=93271428d5765497718224ee1f8f930d5db98da7e2c2aa9384d0eb4f05aff976
data_sha256=9be4a78414c35d41becabb3c844141c1dc7b7dd505677595b9212e906417e125
sequence_sha256=1b1856ff6af90e69007fc3e721bb54db0bcec4ca51995982cae48821c45d786b
graph=1|1|1|1|1|9 corrupt_restore_exit=1 corrupt_partial_state=0
cleanup containers=0 networks=0 temp_removed=True
```

`schema_sha256`, `data_sha256`, `sequence_sha256`, and `archive_bytes` are
**identical between run 1 and run 2** — deterministic across two independent
dump/restore cycles against a fresh disposable cluster each time.

Each printed fingerprint is the *source* value only because the harness
asserts source-equals-destination for all three (`-cne` inequality checks
that `throw` on mismatch, `run-postgres-backup-restore.ps1:389,396,397`)
before printing `F9_BACKUP_RESTORE_PASS`. Reaching that line is proof the
restored database matched the source on schema, row/value data, and sequence
state — not just that a value was printed.

## Comparison against the 2026-09-14 baseline

| Field | 2026-09-14 (`8bde313`) | 2026-09-20 (`ec54400`) | Same? |
|---|---|---|---|
| `data_sha256` | `9be4a78414c3...417e125` | `9be4a78414c3...417e125` | **identical** |
| `schema_sha256` | `d4ddb82b...` | `93271428...` | different |
| `sequence_sha256` | `f848bfc8...` | `1b1856ff...` | different |
| `graph` | `1\|1\|1\|1\|1\|9` | `1\|1\|1\|1\|1\|9` | identical |
| `corrupt_restore_exit` | `1` | `1` | identical |
| `corrupt_partial_state` | `0` | `0` | identical |
| cleanup | `containers=0 networks=0 temp_removed=True` | same | identical |

`data_sha256` matching exactly is expected and correct: the harness inserts
the same bounded synthetic fixture graph regardless of schema version, so
identical fixture data hashes identically. `schema_sha256` and
`sequence_sha256` differing is also expected: `main` gained dozens of tables,
constraints, and sequences between the two rehearsals. Neither difference is
a regression; both are exactly what a schema-refresh rehearsal should show.

## Post-run verification

- `docker ps -a` and `docker network ls` filtered for the run labels: **no
  containers or networks remained** after either run.
- `git status --short` in the rehearsing worktree: **clean** — the rehearsal
  wrote nothing to the tracked tree, consistent with the harness's documented
  behavior (archive and source tar live under a GUID-scoped OS temp
  directory, removed on success).

## What this does NOT close

Per [`backup-restore-decision-2026-09-20.md`](f9-backup-restore-decision-2026-09-20.md)
§5, this rehearsal satisfies only item 1 (refresh local evidence). Items 2–5
(encryption, off-host copy, retention enforcement, RTO-targeted recovery
drill) are untouched and remain blocked as recorded there.
