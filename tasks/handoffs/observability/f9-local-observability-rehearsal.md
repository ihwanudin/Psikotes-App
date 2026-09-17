# F9-O1 local observability rehearsal

## Verdict

**REPAIR-REQUIRED for the runtime health-failure rehearsal.** The new harness is
in place and its static contract passes, but the disposable runtime proof fails:
after the app reports healthy, stopping PostgreSQL makes `/health` return a
generic HTML 500 response instead of the required exact
`503 {"status":"degraded"}` response. The runner fails closed before collecting
queue/outbox visibility, so it does not publish a partial success snapshot.

This is local disposable evidence only. It does not set or imply production
service objectives, telemetry backend, thresholds, destinations, alert routing,
operator ownership, scheduler activation, notifications, or live-resource
readiness.

## Identity and scope

- Lane: F9-O1 disposable queue/outbox visibility and health-failure rehearsal.
- Branch: `codex/f9-o1-observability-rehearsal`.
- Base from `origin/main`: `9209db8cea8b063999c76632841d367fbfe60ad0`.
- Implementation commit under test:
  `6d222cc6e9054e5a2b580afa95ce49c311895c69`.
- Required predecessor check: `537ae7b41e921e252c2dbef9bc983e23f00cd0d4`
  is an ancestor of fetched `origin/main`.
- `main` checkout note: this worktree could not check out local `main` because
  that branch is already attached to `D:/LSI/Web/Psikotes`; the feature branch
  was created directly from fetched `origin/main`.

Exclusive new files only:

- `tools/testing/run-observability-rehearsal.ps1`
- `tools/testing/tests/observability-rehearsal-contract.ps1`
- `tools/testing/observability/collect-queue-outbox.ps1`
- `tasks/handoffs/observability/f9-local-observability-rehearsal.md`

No application code, production config, routes, migrations, scheduler, queue
worker, notification, outbound integration, lockfile, canonical checklist, or
ADR was changed.

## Authority and boundaries

The PRD non-functional requirements require scheduled backup plus restore
testing, async retry reliability, idempotent webhook/ingest behavior, and
auditability. It does not define correlation headers, metric names, tracing,
monitoring backend, alert destinations, service objectives, or operational
owners. `AGENTS.md` grants F9-O1 only this disposable rehearsal lane.

The runner therefore uses only synthetic local Docker resources:

- exact expected commit and clean worktree required;
- internal Docker network only, no published ports;
- pinned local images: `postgres:17.6-alpine`, `redis:8.2-alpine`, and
  `psikotes-app:dev`;
- synthetic app secrets supplied directly as process environment;
- notification/callback-related configuration disabled;
- no queue workers and no scheduler;
- exact-label cleanup with final `containers=0 networks=0`.

The collector is read-only and aggregate-only. It reports fixed queue/outbox
names and bounded counts/ages only: depth, eligible/oldest age, retry, terminal,
processing, and stale lease counts. It does not select or emit tenant,
participant, message, payload, or error-text identifiers.

## Verification evidence

RED before implementation:

```text
Observability rehearsal runner is missing.
```

Static contract after implementation:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass `
  -File ./tools/testing/tests/observability-rehearsal-contract.ps1
```

Result:

```text
observability-rehearsal-contract: PASS (54 assertions)
```

Runtime rehearsal command:

```powershell
$sha = (git rev-parse HEAD).Trim()
powershell -NoProfile -ExecutionPolicy Bypass `
  -File ./tools/testing/run-observability-rehearsal.ps1 `
  -ExpectedCommit $sha
```

Result against `6d222cc6e9054e5a2b580afa95ce49c311895c69`:

```text
cleanup label=oncam.f9-o1-observability=305381c1a6134b4b887ed8d24a239e5a containers=0 networks=0 temp_removed=True
Health dependency failure did not return the exact degraded response.
status=500
body_preview=<!DOCTYPE html><html lang="en"> ...
exit=1
```

The healthy path was reached before the failure gate; the failure occurred on
the induced PostgreSQL-down check. The runner intentionally did not continue to
queue/outbox collection after the health contract failed.

## Current verdicts

| Capability | Verdict | Evidence |
|---|---|---|
| Harness/contract shape | PASS | 54-assertion PowerShell contract passes. |
| Healthy `/health` in disposable stack | PASS | Runner passed the healthy gate before dependency-down. |
| PostgreSQL-down `/health` exact `503` JSON | REPAIR-REQUIRED | Actual response was HTML 500. |
| Redis-down `/health` exact `503` JSON | NOT-VERIFIABLE | Runner failed earlier at PostgreSQL-down. |
| Queue/outbox aggregate snapshot | NOT-VERIFIABLE | Collector exists, but runner correctly refused partial success after health failure. |
| Exact-label cleanup | PASS | Failed runtime run ended with `containers=0 networks=0 temp_removed=True`. |

## Next dependency

The next repair is outside F9-O1's write scope because it requires production
route/middleware/runtime behavior changes. A coordinator-owned decision is
needed for `/health` versus Laravel stateful middleware semantics before this
rehearsal can pass end to end. After that repair lands, rerun this same harness
from a clean commit to verify DB-down, Redis-down, and then the queue/outbox
aggregate snapshot.
