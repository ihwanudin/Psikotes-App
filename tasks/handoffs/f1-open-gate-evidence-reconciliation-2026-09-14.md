# F1 Open Gate Evidence Reconciliation — 2026-09-14

## Verdict

**NO-GO.** This evidence-only reconciliation does not approve a release,
deployment, migration, provider activation, notification delivery, checklist
change, or F1 phase exit. The current dependency and repository-security audits
are green within the commands run, and accepted PostgreSQL evidence remains
applicable to unchanged schema/RLS paths. However, this worktree cannot execute
the requested PHP or focused PostgreSQL tests because development `vendor`
dependencies are absent. Referral registration concurrency has no canonical
test, and the Xendit, real-delivery, production queue/scheduler, credential,
legal, and retention gates remain open.

## Identity, baseline, and authority

- Role: independent QA engineer; report-only evidence reconciliation.
- Audience: PM, Tech Lead, and PMO.
- Registered worktree:
  `C:/Users/ThinkPad/.codex/worktrees/25e7/Psikotes`.
- Required baseline and before-evidence HEAD:
  `30cd06e1a7320bcbc659cecc3642fc7771b227cb`.
- Initial state: clean and detached.
- Exclusive repository write: this new report only.
- Everything else remained read-only. No production code, test, tool,
  migration, route, configuration, lockfile, ADR, canonical checklist, or
  existing document was repaired or edited.
- The immutable result commit cannot embed its own SHA. The after SHA must be
  reported to PM, Tech Lead, and PMO with this report.

The required sources were read before evidence execution: `SPEC.md`,
`SCORING_ALGORITHM.md`, the relevant sections of
`PRD_Sistem_Psikotes_CPMI.docx`, `tasks/parallel-work.md`, `tasks/plan.md`,
`tasks/todo.md`, ADR-0030, ADR-0031, and the latest applicable integration,
release-gate, scanner, and test-harness handoffs. The PRD functional
requirements require Xendit at release, while its older phase table defers the
gateway. `SPEC.md` and the F1 plan explicitly reconcile this conflict by placing
Xendit in F1; this report follows that canonical resolution.

## Scope and resource controls

- No live or sandbox Xendit, n8n, WAHA, notification, payment, or participant
  data call was made.
- No dependency install/update/fix, Docker image pull, shared container,
  shared/live database, host port, `.env`, or production secret was used.
- The PostgreSQL command used only the supplied runner. It verified the pinned
  local images, then stopped on the missing-development-vendor precondition
  before network or database creation. The runner uses GUID-derived exact names
  and labels, internal networking, `--pull=never`, no published ports, and
  exact-name-plus-label cleanup in `finally`.
- No OS temporary directory was needed or created.

## Progress recalculation

`tasks/todo.md` contains exactly 100 checklist rows: 81 checked and 19 open,
therefore F1 checklist progress is **81/100 (81%)**. This is a checklist ratio,
not a phase-exit or release percentage. Tasks 5, 6, 7, 9, 18, and 19 contain 18
of the 19 open rows. The remaining open row is Task 15's skipped Xendit sandbox
contract evidence, which is also an upstream blocker for Task 18.

Status meanings used below:

- **PROVEN**: canonical evidence directly proves the checkbox at this immutable
  snapshot, within the stated limitations.
- **PARTIAL**: some required behavior is evidenced, but the checkbox is broader
  than the proof.
- **NOT-VERIFIABLE**: the requested current execution could not start or
  discover tests in this worktree.
- **BLOCKED**: required authority, coverage, credential, runtime, or operational
  evidence does not exist and must remain open.

## Canonical inherited evidence

The accepted fixture-compatibility integration `539f372` and accepted security
scanner integration `c6b1ca5` are ancestors of the required baseline. The
canonical release-gate handoff records a fresh ordered disposable PostgreSQL run
of **554 tests / 5,863 assertions**, with zero failures, errors, skips, or risky
tests, followed by exact-label cleanup at zero containers and zero networks.
Fresh canonical PII and SECRET profiles also passed there. A path-level diff
from `539f372` to this baseline contains no changes under migrations, schema,
PostgreSQL tests, the focused database/RLS/context tests, RLS middleware/job
paths, routes, `bootstrap/app.php`, dependency manifests/lockfiles, or the
security scanner. That accepted proof therefore remains applicable to those
unchanged paths; it is not presented as a successful rerun from this worktree.

The requested source inventory contains 39 declared test methods across the
nine focused PHP files and 17 declared test methods across the four filtered
PostgreSQL files. These are source counts, not executed-test counts, because
today's commands stopped before discovery.

## Open checkbox reconciliation

### Task 5 — Create core tenant schema

| Open checkbox | Status | Canonical evidence and limitation |
|---|---|---|
| Foreign keys, enum/check constraints, indexes, and unique/partial unique constraints match the SPEC | **PARTIAL** | `CoreTenantSchemaTest`, the migration/schema sources, and the accepted ordered PostgreSQL 554/5,863 regression cover critical tables and constraints. The requested local suite could not run, and no exhaustive row-by-row mapping of every SPEC constraint exists in one canonical artifact; absence is not treated as a pass. |
| Migration up/down passes on PostgreSQL | **PROVEN** | The accepted ordered disposable PostgreSQL 554/5,863 run includes the migration compatibility and rollback suite with zero skipped/risky tests. Relevant migration/schema/test paths are unchanged through this baseline. Today's focused rerun is separately NOT-VERIFIABLE because `vendor` is absent. |

### Task 6 — Enforce PostgreSQL RLS

| Open checkbox | Status | Canonical evidence and limitation |
|---|---|---|
| Runtime role is not table owner and has no `BYPASSRLS` | **PROVEN** | `OrganizationPaymentRlsTest::test_runtime_is_not_owner_superuser_or_bypassrls` and its forced-RLS ownership checks are within the accepted 554/5,863 PostgreSQL run; unchanged paths preserve applicability. |
| Super-admin, branch-admin, participant, service, and psychologist policies match the access matrix | **PARTIAL** | The four filtered PostgreSQL classes exercise the principal role matrix across tenant, finance, consent, DASS, and audit data, and passed within the accepted full run. The wording covers the complete application matrix, while the requested filter is a critical subset rather than an exhaustive table-by-role proof. |
| Request without context fails closed | **PROVEN** | Accepted PostgreSQL evidence includes absent-context read/write denial, and source guards use transaction-local settings without privileged defaults. The relevant paths have not changed. |
| Negative SQL tests across branches and participants pass | **PROVEN** | Accepted PostgreSQL evidence includes cross-branch denial, participant-only visibility, foreign-participant denial, and absent-context denial in the named filtered classes. |
| DASS is unreadable to branch/central non-psychologist admins | **PROVEN** | `OrganizationPaymentRlsTest` and `DassConsentBranchPrivacyTest` explicitly distinguish admin denial from psychologist/participant-authorized reads and passed in the accepted run. |

### Task 7 — Inject RLS context

| Open checkbox | Status | Canonical evidence and limitation |
|---|---|---|
| Every tenant route and tenant job uses context middleware | **PROVEN** | `RlsMiddlewareCoverageTest` fails on an unprotected tenant controller; the job contract and middleware source enforce `ProvidesRlsContext` plus `ApplyRlsContextToJob`. The accepted suite covers these unchanged paths. ADR-0030's future generic start-route exception remains a separate, not-yet-wired trusted command boundary and does not weaken this snapshot. |
| Two sequential requests/jobs from different tenants do not see each other's data | **PARTIAL** | `RlsContextRunnerTest` proves sequential process-context cleanup, middleware/job tests prove scoped wrapping, and accepted PostgreSQL tests prove database context cleanup after exceptions plus cross-tenant denial. The requested current focused execution is NOT-VERIFIABLE, and the selected files do not provide one explicit end-to-end two-request plus two-job database visibility test. |

### Task 9 — Implement first-touch referral

| Open checkbox | Status | Canonical evidence and limitation |
|---|---|---|
| Feature tests cover first-touch, unknown code, expired cookie, and concurrent registration | **BLOCKED** | `ReferralAttributionTest` contains first-touch, unknown-code/default fallback, expired-cookie replacement, and PII minimization coverage. `ParticipantRegistrationTest` contains idempotent repeated-token registration, but there is no canonical two-connection referral/registration concurrency test. The three non-concurrent scenarios have historical green evidence; referral concurrency is **NOT EVIDENCED** and must not be inferred from idempotent sequential replay. |

### Task 18 — Prove the F1 gate

| Open checkbox | Status | Canonical evidence and limitation |
|---|---|---|
| Xendit activation → invoice → verified webhook → ready entitlement → notification → successful login | **BLOCKED** | No credential-backed sandbox end-to-end evidence exists. `F1_VALIDATION.md` explicitly leaves the method OFF and the flow unrun. Fake/contract tests, manual-transfer success, and adapter logic do not prove the provider path. |
| All PHP/frontend/integration/browser tests pass without gate skips | **BLOCKED** | The accepted PostgreSQL subgate is green, but the requested PHP focus and PostgreSQL filter are NOT-VERIFIABLE here due absent `vendor`. The older F1 full-suite record explicitly contained three sandbox skips, and no complete current frontend/browser/provider run without gate skips exists. |
| Dependency audit and secret/PII scan have no unmitigated critical finding | **PROVEN** | Current lockfile commands passed: Composer reported no advisories, production npm audit reported zero vulnerabilities, scanner regression passed 50/50 with zero skipped/todo, and current SECRET/PII profiles passed 1,423 tracked paths before this report. Candidate-inclusive scans are recorded in final candidate checks below. Limit: `npm audit --omit=dev --audit-level=high` excludes dev-only packages by instruction; a green network response is snapshot evidence, not a permanent guarantee. |

### Task 19 — Document F1 operations

| Open checkbox | Status | Canonical evidence and limitation |
|---|---|---|
| A new developer can run the dev stack and tests without real secrets | **PARTIAL** | `README.md` and `DEPLOYMENT.md` document placeholder-only development setup, separated owner/runtime roles, internal services, and health checks. This clean detached worktree could not run tests because ignored development dependencies were absent, so reproducibility from a dependency-free checkout is not proven. |
| Mandatory RLS middleware, webhook incident flow, and notification retry are documented | **PROVEN** | `SECURITY.md` documents route/job context contracts; `DEPLOYMENT.md` documents Xendit callback/reconciliation incident handling; `README.md` documents notification queues, retry, failure states, and deduplication. |
| Every limitation and unselected provider is recorded openly | **PARTIAL** | Canonical documents record unresolved face matching, Xendit/KYB credentials, legal consent/privacy review, instrument rights, Japanese review, object-retention decisions, queue/monitoring gaps, and provider/runtime limits. The universal word “every” cannot be proven by finite inventory, and operational decisions can change outside the repository. |
| Documentation commands are tested from a clean checkout or Docker absence is recorded | **NOT-VERIFIABLE** | This checkout is clean/detached but lacks `vendor`; Docker and pinned images are present, yet the prescribed runner correctly refuses execution without dependencies. No install was authorized. Existing historical Docker evidence is useful but does not satisfy a fresh clean-checkout command rehearsal at this baseline. |
| The F1 validation report maps evidence to every gate | **PARTIAL** | `F1_VALIDATION.md` maps major F1 flows but predates the current 554/5,863 regression and does not enumerate every open checklist row. This report maps all 18 open rows in the six requested tasks and notes Task 15's upstream sandbox blocker, but it does not edit the canonical validation report or claim checklist acceptance. |

## Current command evidence

### Focused PHP command

```powershell
php artisan test tests/Feature/Database/CoreTenantSchemaTest.php tests/Unit/Database/PostgresRlsDefinitionTest.php tests/Architecture/RlsMiddlewareCoverageTest.php tests/Feature/Security/ApplyRlsContextMiddlewareTest.php tests/Feature/Security/RlsContextRunnerTest.php tests/Unit/Security/ApplyRlsContextToJobTest.php tests/Feature/Referral/ReferralAttributionTest.php tests/Feature/Registration/ParticipantRegistrationTest.php tests/Feature/F1/ManualActivationFlowTest.php
```

Result: exit 1 before PHPUnit discovery because `vendor/autoload.php` does not
exist. **0 tests discovered; 0 assertions; skip count unavailable.** No install,
copy, symlink, or dependency mutation was attempted.

### Isolated PostgreSQL/RLS command

```powershell
pwsh -File tools/testing/run-org-postgres.ps1 -Filter 'AssessmentBillingRlsTest|OrganizationPaymentRlsTest|DassConsentBranchPrivacyTest|CheckoutConsentAuditPrivacyTest'
```

Result: exit 1 before network/database creation with `Development vendor
dependencies are required.` **0 tests discovered; 0 assertions; skip count
unavailable.** Both pinned images were available locally; the script forbids
pulls. Because the precondition fails before `docker network create`, no
invocation-owned network or database exists to query afterward. The runner's
`finally` path reported that disposable resources were cleaned and application
containers were not targeted; no broad-prefix cleanup/query was used.

### Dependency commands

The literal `composer` launcher was not on PATH, so both requested literal
commands exited 1 before Composer ran. The repository-documented existing
Laragon PHAR was then used without installation:

```powershell
php D:\laragon\bin\composer\composer.phar validate --strict --no-check-publish
# exit 0: composer.json is valid

php D:\laragon\bin\composer\composer.phar audit --locked --no-interaction
# exit 0: no security vulnerability advisories found

npm audit --omit=dev --audit-level=high
# exit 0: found 0 vulnerabilities
```

No manifest, lockfile, dependency tree, or repository file changed.

### Repository security commands

```powershell
npm run security:scan:test
# 50 tests; 50 passed; 0 failed; 0 cancelled; 0 skipped; 0 todo

npm run security:scan:secret
# passed 1,423 tracked paths across index and working-tree snapshots

npm run security:scan:pii
# passed 1,423 tracked paths across index and working-tree snapshots
```

These pre-report scans establish the baseline. The staged one-file candidate is
scanned again below so the report itself is included.

## Explicitly open operational and human gates

- Xendit sandbox/live operation, account/KYB, secret key, callback token, and
  credential authority.
- Real n8n/WAHA notification delivery and its external deduplication behavior.
- Production queue worker, Redis shared-lock behavior, scheduler, alerting, and
  operational monitoring.
- Legal approval of consent/privacy text, final retention policy and purge
  authority, psychometric instrument rights, responsible psychologist, and
  certified Japanese review.
- Referral registration concurrency under two live database connections.
- Full PHP/frontend/integration/browser execution with no gate skips.

None of these can be closed by source presence, fake adapters, historical prose,
or missing-environment assumptions.

## Final candidate checks

The staged candidate contained exactly this one new report: 238 inserted lines
and no other path. `git diff --cached --check` exited 0 with no output. Both
candidate-inclusive repository profiles exited 0 across index and working-tree
snapshots: SECRET passed **1,424 tracked paths** and PII passed **1,424 tracked
paths**. After recording that first candidate-inclusive pass, QA restaged the same
one-file, 238-line candidate and repeated both profiles plus final scope/diff checks.
Both final profiles passed 1,424 tracked paths, then QA created the immutable
candidate; its SHA is reported externally because self-embedding is impossible.

## Handoff

- Review status: **NO-GO; immutable evidence candidate complete and awaiting
  independent PM/Tech Lead/PMO review**.
- QA does not integrate or edit canonical checklists.
- Next dependency: provide a lockfile-matched development vendor tree in a new
  authorized clean evidence worktree, then rerun the exact focused commands;
  separately add and independently review a true referral/registration
  concurrency test; execute Xendit and notification/queue/scheduler operational
  gates only after the required credentials and human authorities exist.
