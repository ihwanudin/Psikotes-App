# F9-O1 local observability rehearsal

## Verdict

**PASS after repair.** The disposable F9-O1 rehearsal now proves the health
failure contract and the queue/outbox aggregate snapshot end to end:

- healthy `/health` returns exact `200 {"status":"ok"}`;
- PostgreSQL-down `/health` returns exact `503 {"status":"degraded"}`;
- Redis-down `/health` returns exact `503 {"status":"degraded"}`;
- queue/outbox visibility is aggregate-only and passes the synthetic leak gate;
- exact-label cleanup ends with `containers=0 networks=0 temp_removed=True`.

This remains local disposable evidence only. It does not set or imply
production service objectives, telemetry backend, thresholds, destinations,
alert routing, operator ownership, scheduler activation, notifications, or
live-resource readiness.

## Identity and scope

- Lane: F9-O1 disposable queue/outbox visibility and health-failure rehearsal.
- Branch: `codex/f9-o1-observability-rehearsal`.
- Current `origin/main` incorporated before repair:
  `bdc779e` (`Merge pull request #8 from ihwanudin/docs/instrument-authority-pack-ledger-2026-09-18`).
- Runtime repair commit:
  `91176fc4a9774c67bef32d7118242e19dc8abf49`.
- Harness leak-sentinel repair commit and implementation commit under test:
  `5632bdcfb3074a1169dc3dd034090cc3879c6394`.
- Prior repair-required evidence commits:
  `6d222cc6e9054e5a2b580afa95ce49c311895c69`,
  `bb4be3a`.

Changed files in this increment:

- `routes/web.php`
  - `/health` is now excluded from the stateful `web` middleware group so
    session startup cannot fail before `HealthCheckController`.
- `tests/Feature/HealthCheckTest.php`
  - Adds a regression using plain `$this->get('/health')`, not `getJson()`,
    and a real broken database connection configuration rather than direct
    `DB` facade mocking.
- `tools/testing/run-observability-rehearsal.ps1`
  - Narrows the forbidden snapshot token from generic `outbox-` to the actual
    synthetic identifier prefixes `outbox-participant-` and `outbox-generic-`.
    The previous token also matched the legitimate schema name
    `oncam.f9-o1.queue-outbox-visibility.v1`.
- `tasks/handoffs/observability/f9-local-observability-rehearsal.md`
  - Records this repaired evidence.

No production config, migrations, scheduler, queue worker, notification,
outbound integration, lockfile, canonical checklist, or ADR was changed.

## Authority and boundaries

The PRD non-functional requirements require scheduled backup plus restore
testing, async retry reliability, idempotent webhook/ingest behavior, and
auditability. They do not define correlation headers, metric names, tracing,
monitoring backend, alert destinations, service objectives, or operational
owners. `AGENTS.md` grants F9-O1 only this disposable rehearsal lane plus the
narrow `/health` route/test repair recorded in the ownership ledger.

The runner uses only synthetic local Docker resources:

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

### Regression test authoring

Host-level PHPUnit could not be executed in this checkout because the worktree
does not include Composer dependencies:

```text
Warning: require(.../vendor/autoload.php): Failed to open stream: No such file or directory
Fatal error: Uncaught Error: Failed opening required '.../vendor/autoload.php'
```

The new regression is nevertheless present in `tests/Feature/HealthCheckTest.php`
and intentionally avoids both `getJson()` and direct `DB` facade mocking.

### Static contract

```powershell
powershell -NoProfile -ExecutionPolicy Bypass `
  -File ./tools/testing/tests/observability-rehearsal-contract.ps1
```

Result:

```text
observability-rehearsal-contract: PASS (54 assertions)
```

### Application image note

The existing local `psikotes-app:dev` image was older than the merged
`origin/main` source. A full Dockerfile rebuild failed before producing a new
image at the pre-existing build-time cache-path check:

```text
In Compiler.php line 75:
  Please provide a valid cache path.
```

For this local verification run only, the existing local image was converted
into a derived `psikotes-app:dev` by copying the current source runtime
directories (`app/`, `routes/`, `config/`, and `bootstrap/app.php`) into the
container, clearing Laravel cache files, regenerating the authoritative
Composer autoload map inside the container, and restoring the runtime CMD to
supervisord. This changed only the local Docker image artifact used by the
rehearsal; it did not create or change repository files.

The image-side route inspection showed the repaired route:

```text
Route::get('/health', HealthCheckController::class)->withoutMiddleware('web')->name('health');
```

### Runtime rehearsal

Command from a clean worktree at
`5632bdcfb3074a1169dc3dd034090cc3879c6394`:

```powershell
$sha = (git rev-parse HEAD).Trim()
powershell -NoProfile -ExecutionPolicy Bypass `
  -File ./tools/testing/run-observability-rehearsal.ps1 `
  -ExpectedCommit $sha
```

Result:

```text
F9_O1_OBSERVABILITY_REHEARSAL_PASS label=oncam.f9-o1-observability=bb9460fc0ede431ea50927727e043fe1 commit=5632bdcfb3074a1169dc3dd034090cc3879c6394
health_healthy=200 health_db_down=503 health_redis_down=503
queue_notifications_depth=4 queue_notifications_oldestEligibleAgeSeconds=600 queue_notifications_retryCount=1 queue_notifications_terminalCount=1 queue_notifications_staleLeaseCount=1
outbox_participant_pendingCount=1 outbox_participant_retryCount=1 outbox_participant_terminalCount=1 outbox_participant_staleLeaseCount=1
cleanup label=oncam.f9-o1-observability=bb9460fc0ede431ea50927727e043fe1 containers=0 networks=0 temp_removed=True
```

## Current verdicts

| Capability | Verdict | Evidence |
|---|---|---|
| Harness/contract shape | PASS | 54-assertion PowerShell contract passes. |
| Healthy `/health` in disposable stack | PASS | Runtime rehearsal reports `health_healthy=200`. |
| PostgreSQL-down `/health` exact `503` JSON | PASS | Runtime rehearsal reports `health_db_down=503`. |
| Redis-down `/health` exact `503` JSON | PASS | Runtime rehearsal reports `health_redis_down=503`. |
| Queue/outbox aggregate snapshot | PASS | Runtime rehearsal reports expected aggregate counts/ages without forbidden synthetic identifiers. |
| Exact-label cleanup | PASS | Runtime run ended with `containers=0 networks=0 temp_removed=True`. |

## Next dependency

Coordinator audit can now inspect this pushed branch and rerun the same
rehearsal. The remaining production observability decisions are intentionally
out of scope for F9-O1: service objectives, thresholds, telemetry backend,
destinations, alert routing, operator ownership, notifications, scheduler
activation, and live-resource readiness.
