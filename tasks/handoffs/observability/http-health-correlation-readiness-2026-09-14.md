# HTTP health and correlation readiness audit

Status: **NOT-VERIFIABLE; no production readiness claim**

## Verdict

The repository contains a narrow controller-level `/health` contract that checks
PostgreSQL and Redis and redacts the caught dependency error. It does not yet
prove the deployed HTTP boundary fails closed with the documented `503` JSON
shape because the route remains in Laravel's stateful `web` middleware group
while the Compose runtime uses Redis sessions. A Redis failure can therefore
occur before `HealthCheckController` executes.

HTTP correlation propagation, deterministic structured failure output, and an
HTTP metrics label contract are absent from the current authoritative runtime.
They cannot be simulated into acceptance by a test-only route or invented
configuration. The accompanying probe reports each missing boundary explicitly
and exits `2` for `NOT_VERIFIABLE`.

F9 remains `partial`. Recalculation from `tasks/f2-f9-acceptance.md` yields zero
of nine phase exits complete and zero of 28 acceptance rows complete across all
required end-to-end layers. This audit changes no checklist status.

## Identity and scope

- Source task: `01a099c6-4c0d-7123-a453-3a98e0a695fa`.
- Requested branch: `codex/f2-wave1-integration`.
- Worktree: `C:/Users/ThinkPad/.codex/worktrees/c586/Psikotes`.
- Dispatch SHA: `274c43de45f9f16e4a3f8cb33e377f697d6e9f73`.
- Observed SHA before work: exact dispatch SHA.
- Checkout drift: detached `HEAD`; `codex/f2-wave1-integration` contains the
  dispatch SHA but was not checked out.
- Initial worktree state: clean.
- Exclusive new files:
  - `tools/testing/observability-http-health-contract.ps1`;
  - this handoff.

No production/application code, configuration, route, middleware, test,
migration, API/DTO/ADR, shared helper/checklist, lockfile, scheduler,
notification, deployment, or live resource was changed.

## Authority and operational questions

`SPEC.md:298-300` assigns hardening and the final 28-test gate to F9.
`tasks/f2-f9-acceptance.md:56` explicitly keeps F9 partial on observability and
the final launch gate. The PRD 1.3 non-functional requirements require scheduled
backup plus restore testing, idempotent reliability, performance evidence, and
auditability, but do not define a correlation header, structured log schema,
HTTP metric names/dimensions, SLO, monitoring backend, alert destination, or
operational owner.

The audit therefore asks only questions supported by the assigned scope:

1. Can an operator distinguish process liveness from dependency readiness?
2. Does every response expose one safe correlation value that is also attached
   to request failure logs?
3. Can a dependency failure be found without exposing exception or connection
   details?
4. Are aggregate HTTP dimensions bounded independently of participant,
   request, tenant, raw URL, and error-message values?

No monitoring backend, threshold, destination, escalation owner, sampling rule,
or service-level objective is selected by this report.

## Existing contracts inspected

### Health endpoints

- `routes/web.php:61` registers `/health` to `HealthCheckController`.
- `app/Http/Controllers/HealthCheckController.php:18-23` checks `select 1` and
  Redis `PING`, catches `Throwable`, logs a redacted warning, and returns
  `{"status":"degraded"}` with HTTP 503.
- `tests/Feature/HealthCheckTest.php:14-32` covers the controller success and DB
  exception paths and asserts the synthetic secret is absent.
- `compose.yaml:71-75` and `docker/app/Dockerfile:84-85` use `/health` for the app
  container health check.
- `bootstrap/app.php:21` separately registers Laravel's framework `/up` route.
  The repository does not document a liveness/readiness semantic split between
  `/up` and `/health`.

The controller behavior is useful partial evidence. It is not an end-to-end
failure proof: `/health` is declared in `routes/web.php` without the repository's
existing `withoutMiddleware('web')` isolation pattern, and Compose sets
`SESSION_DRIVER=redis`. The focused test mocks the controller's Redis facade; it
does not prove that a Redis-backed session middleware failure reaches the
controller or preserves the exact 503 response.

### Correlation and logging

- No HTTP boundary middleware accepts a named request/correlation header,
  creates a fallback identifier, emits it on the response, and attaches it to
  the request logger.
- Existing `correlationId` fields are integration-domain payload values, not a
  global inbound/outbound HTTP correlation contract.
- `config/logging.php:112` delegates the stderr formatter to an environment
  value. No committed deterministic JSON formatter contract exists.
- The health failure event is prose (`Health dependency check failed.`) with no
  stable machine event field or correlation context. It correctly avoids caught
  exception interpolation.

### Metrics and cardinality

- `composer.json` has no OpenTelemetry, Prometheus, StatsD, or equivalent metrics
  dependency.
- No application HTTP rate/error/duration instrument with route-template and
  status-class dimensions exists.
- No observed metric contract currently uses participant, request, correlation,
  email, raw URL, or error-message labels; however, absence of an HTTP metric is
  not evidence that cardinality is bounded.
- Existing local load measurements and queue snapshot analysis remain
  baseline/report-only evidence. They do not supply a production HTTP metric
  contract or authorize an SLO.

## Decision matrix

| Capability | Existing positive evidence | Blocking evidence | Verdict | Required authority or next proof |
| --- | --- | --- | --- | --- |
| Dependency health controller | DB query, Redis ping, redacted 503 body, focused mocked test, Compose probe | Stateful `web` middleware can fail before controller; `/up` semantics are not frozen; no executable dependency-down HTTP probe in this checkout | **NOT-VERIFIABLE** | Coordinator-owned route/middleware decision, explicit `/up` versus `/health` semantics, then disposable real HTTP probes for DB-down and Redis-down paths |
| Correlation propagation | Safe public ULIDs exist in narrow domain contracts | No accepted header name/trust rule, generation rule, response header, log-context middleware, proxy rule, or outbound propagation contract | **NOT-VERIFIABLE** | Freeze the HTTP correlation contract and trusted-boundary behavior before implementation |
| Structured failure signal | Health exception detail is not logged or returned | Prose event, no stable event/context schema, stderr JSON formatting is environment-dependent | **NOT-VERIFIABLE** | Freeze event schema and committed structured formatter behavior; verify actual stderr output under induced local failure |
| Bounded HTTP cardinality | No observed forbidden HTTP metric labels | No HTTP RED instrument, exporter, dimension schema, or approved backend | **NOT-VERIFIABLE** | Freeze vendor-neutral metric names and bounded dimensions; backend/export destination remains an operational decision |
| Alerts/SLO/ownership | Deployment prose names possible monitoring categories | No approved threshold, duration, notification destination, runbook owner, or SLO | **NOT-VERIFIABLE** | PM/operations must supply authority; this lane must not invent it |

## Probe contract

`tools/testing/observability-http-health-contract.ps1` is read-only and creates
no temporary, Docker, network, database, application, notification, or outbound
resource. It accepts an optional exact lowercase commit and refuses drift. It
reports a stable JSON schema with independent results for health, correlation,
structured failure, cardinality, and the focused health test.

Exit codes are fail-closed:

- `0`: all inspected contracts verified;
- `1`: a present health contract is violated or an executable focused test
  fails;
- `2`: one or more required contracts are absent or cannot be executed.

The in-memory adversarial self-test rejects a health mutation without dependency
failure handling, DTO-only correlation, prose-only logging, and a metric label
containing `user_id`. It accepts only the corresponding complete synthetic
contracts. These synthetic vectors test the scanner, not the application.

## Verification evidence

PowerShell parser:

```text
PASS; zero parse errors
```

Adversarial scanner contract:

```text
SELF_TEST=PASS cases=8 temp_resources=0
exit=0
```

Repository probe at the exact dispatch SHA:

```text
overall=NOT_VERIFIABLE
healthFailClosed=NOT_VERIFIABLE
correlationPropagation=NOT_VERIFIABLE
structuredFailureSignals=NOT_VERIFIABLE
boundedCardinality=NOT_VERIFIABLE
focusedHealthTest=NOT_VERIFIABLE
exit=2
cleanup temporaryResourcesCreated=0 residualResources=0
```

The focused PHP test was not run because this checkout has no
`vendor/autoload.php`; installing dependencies or changing lockfiles was not
authorized. The checked-in test was inspected statically. The PRD was extracted
structurally with the bundled document runtime. Visual page rendering was not
available because the bundled renderer could not resolve LibreOffice on this
host; no visual-layout claim is made.

Repository security gates on the final staged two-file candidate:

```text
repository-content-scan.test.mjs: 50/50 PASS
SECRET profile: PASS
PII profile: PASS
git diff --cached --check: PASS
```

## Security and privacy review

- The probe reads only committed source text and Git identity.
- It does not read `.env`, application logs, credentials, participant data,
  request payloads, or external services.
- Output contains only fixed check names, booleans, statuses, commit identity,
  and bounded reasons; it never prints matched source text.
- Correlation IDs, participant IDs, tenant IDs, raw URLs, and error messages are
  explicitly rejected as metric labels in the adversarial contract.
- Exact cleanup is `0` created resources and `0` residual resources.

## Blockers and next dependency

The coordinator must first freeze the semantics of `/up` and `/health`, the
stateful-middleware boundary, and the accepted correlation header/trust policy.
A later implementation lane may then add production middleware/config/tests
under explicit ownership. Only after that immutable change exists can an
independent QA lane run disposable real HTTP probes for correlation echo,
cross-request isolation, DB-down and Redis-down response behavior, actual JSON
stderr events, secret/PII redaction, and bounded metric dimensions.

Review status: **candidate pending Tech Lead review, then independent QA; slot
closed without production edits.**
