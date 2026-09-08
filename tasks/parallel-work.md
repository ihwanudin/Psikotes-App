# Parallel Execution Reference: F1-F9

## Purpose and authority

This document is the canonical coordination reference for continuing the project across Codex sessions, closed applications, context compaction, and parallel worker tasks. It defines dependencies and ownership; functional behavior remains governed by `SPEC.md`, `SCORING_ALGORITHM.md`, the current PRD, and accepted ADRs.

If chat history disagrees with repository evidence, repository evidence wins. Progress must be recalculated from the acceptance checklists and test evidence on every resume.

F0 is treated as complete, frozen, read-only source data. The nine implementation phases below are F1-F9.

## Parallelization map

| Phase | Parallelization | Safe ownership slice | Dependency / gate |
|---|---|---|---|
| F1 — Foundation | Yes | Close P17c/P18, browser/security regression, acceptance evidence | Must not silently change application contracts |
| F2 — Assessment engine | High | Session backend, instrument UI, and pure psychometric/scoring engine | Freeze DTO/API contracts first |
| F3 — Grey Area | Yes | Eligibility, guardrails, validity flags, recommendations | Stable normalized scoring output from F2 |
| F4 — Narrative | Yes, alongside F3 | Narrative assembler and bilingual templates | Stable F2 result DTO; no mutation of F3 rules |
| F5 — Psychologist review | Partial | Review state machine and fixture-driven review UI | Final backend integration waits for F3/F4 contracts |
| F6 — Result documents | Yes | Participant HPP and internal report rendering | Stable F5 final/signature contract |
| F7 — Admin and branch | High | Dashboards, commission, proctoring view, operational reporting | Each consumed API/state contract must be frozen |
| F8 — Additional gateway | Independent but deferred | Provider-specific adapter and contract tests | Start only after an explicit provider decision; Xendit is sufficient now |
| F9 — Hardening | Continuous plus final gate | Security, performance, observability, queue/cache, retention, backup | Final load/recovery evidence follows stable runtime |

## Dependency graph

```text
Contract freeze
  -> F2 scoring/session
       -> F3 Grey Area ---------+
       -> F4 narrative ---------+-> F5 review/signature -> F6 documents
       -> F7 dashboards/proctoring

F1 closeout and incremental F9 verification accompany the graph.
Final F9 launch gate runs only after the required product paths are stable.
F8 remains deferred until explicitly approved.
```

## Maximum active layout

Use no more than three worker tasks in parallel while the current task acts as coordinator/integrator.

### Initial wave

| Owner | Scope | Exclusive paths / artifacts | Must not change |
|---|---|---|---|
| Worker A — F1 closeout and QA | P17c/P18, browser/security regression, validation evidence | `tests/Browser/**`, assigned validation/runbook files, assigned organization-payment reports | Domain behavior, shared API, migrations |
| Worker B — F2 session backend | Session lifecycle, answer persistence, timer, autosave, idempotent submit, status API | Assigned assessment-session domain/actions/controllers and backend tests | React UI, scoring algorithms, unrelated payment code |
| Worker C — F2 psychometric engine | IST/PAPI/RMIB/Kraepelin/DASS-21 scoring, validity, normalized result | `app/Services/Scoring/**` or the explicitly assigned psychometric domain plus unit/golden tests | Session HTTP flow, UI, migrations, F0 source data |
| Coordinator — contracts/integration | Freeze contracts, common frontend shell, review commits, merge order, regression gate, checklist updates | Shared DTO/API, common routes/components, canonical plans/checklists | Worker-owned files while the worker is active |

After a worker finishes and its increment passes review, reuse that task for the next unblocked slice. Do not create another task for the same ownership lane.

### Second wave

Start only after assessment contracts and the common shell are stable:

- IST/PAPI instrument UI.
- RMIB/DASS-21 instrument UI.
- Kraepelin instrument UI.
- Proctoring/heartbeat may run as a separate later slice, but it must not share common assessment files with an instrument worker.

Each instrument must use its own directory. The coordinator alone owns common layout, routing, global state, and the shared API client.

### Third wave

After normalized scoring output is stable:

- F3 owns `app/Domain/Eligibility/**` or its established equivalent.
- F4 owns `app/Domain/Narrative/**` or its established equivalent.
- F5 review UI may be developed against frozen fixtures; the final state-machine integration waits for F3 and F4.

The shared result DTO may be changed only by the coordinator through a reviewed contract change.

### Fourth wave

After the final review/signature contract is stable:

- F6 owns report services, report views, and report tests.
- F7 owns commission/dashboard resources and their tests.
- F9 owns load tooling, retention jobs, backup/runbooks, and observability evidence.

Schema changes from these lanes are queued and applied serially by the single migration owner.

## Resources that must have one owner

Never assign these concurrently:

- Database schema, migrations, PostgreSQL RLS policies, and seed structure.
- Shared DTOs, public API contracts, common routes, shared frontend state, and API clients.
- Payment state machine, entitlement transitions, and financial idempotency.
- Psychologist review/signature and report publication state machine.
- `composer.json`, `composer.lock`, `package.json`, and package lockfiles.
- `tasks/plan.md`, canonical `tasks/**/todo.md`, ADR numbering, changelog, and progress calculation.
- F0 JSON, norms, lookup tables, scoring keys, and psychometric source material.
- Deployment configuration, environment contracts, and feature flags.

DASS-21 package behavior is already decided: the standalone DASS-21 package remains available, while every main psychotest package includes DASS-21 automatically without an add/remove choice and without user-facing narration that emphasizes this rule. Do not reopen this decision without an explicit user request and a superseding ADR.

## Contract-freeze checklist

Before backend, frontend, and psychometric tasks run concurrently, the coordinator records the version and ownership of:

- `AssessmentSession`
- `AnswerSubmission`
- `InstrumentResult`
- `ScoredAspect`
- `ValidityResult`
- `ReportState`
- Stable error codes and authorization outcomes
- Route names and request/response examples

Any later breaking change pauses affected workers and is integrated as a dedicated contract commit before work resumes.

## Worker handoff record

Each dispatched increment must record:

```text
Task/thread ID:
Lane and phase:
Branch/worktree:
Baseline commit:
Owned files/directories:
Acceptance criteria:
Verification commands:
Result commit:
Tests and evidence:
Known blockers:
Next dependency or increment:
Review status: pending | accepted | repair-required
```

Worker completion is not acceptance. The coordinator inspects the Git delta, runs risk-proportionate tests, confirms no ownership violation, and only then marks the increment accepted or sends a specific repair request.

## Resume checklist after Codex closes or work is interrupted

1. Read `AGENTS.md`, this document, `SPEC.md`, `SCORING_ALGORITHM.md`, the relevant plan/todo, and the latest applicable ADR/integration report.
2. Run `git status --short`, inspect recent commits, branches/worktrees, and preserve all unrelated or untracked changes.
3. Inventory existing tasks and take one immediate status snapshot. Do not create duplicates or re-send work to tasks still running.
4. Match each task to its recorded baseline, ownership, result commit, and review status.
5. Review completed deltas and verification evidence before integration or further dispatch.
6. Recalculate completion from the current acceptance checklists. A checked item without passing required evidence is reopened or reported as provisional.
7. Select only dependency-unblocked work from the waves above, then issue one bounded increment per idle task.
8. Record the new dispatch and next checkpoint in this file or a linked integration report before ending the coordination turn.

## Current known gates at creation

These are resume pointers, not permanent truth; verify them against the current checklists:

- F1 is not formally closed while PostgreSQL/RLS acceptance, P17c/P18 evidence, Xendit sandbox E2E, or required unskipped gates remain open.
- Organization-payment completion must be read from `tasks/organization-payment/todo.md`; the historical 173/220 figure describes combined checklists at one point and is not the percentage for the complete PRD.
- F2-F6 remain the main product-delivery path and must not be treated as complete merely because foundation and organization-payment work are advanced.
- Provider selection, legal/consent approval, licensed psychometric materials, Japanese-language review, and live credentials remain explicit human/operational gates where applicable.

## Integration order

Within a wave, prefer this reviewed merge order:

1. Contract-only commit.
2. Pure psychometric/domain implementation.
3. Session/backend implementation.
4. Frontend implementation.
5. QA, validation evidence, and canonical checklist update.

Migrations are always integrated serially. Deployments, production migrations, active endpoints/gates, real payments, and outbound notifications require separate explicit authorization.

## Active dispatch — 2026-09-09 F2/F3/F4 continuation

Coordinator branch: `codex/organization-payment-spec`. Detailed reviewed commit
and test evidence is recorded in
`tasks/handoffs/integration-2026-09-08-f2-continuation.md`.

| Task | Active agent | Exclusive ownership | Current increment | Review status |
|---|---|---|---|---|
| F5 signing prerequisites | `/root/review_ist` | New `app/Domain/Review/ReportSigningPrerequisitePolicy.php` and `tests/Unit/Review/ReportSigningPrerequisitePolicyTest.php` only | Implement the pure G3/G5/G7/G9 signature gate and exact reason codes; no persistence/state transition | queued after accepted G7 `eb5e946`; review pending |
| F4 canonical narrative catalog | `/root/review_session` | New `app/Domain/Narrative/ReportingNarrativeCatalog.php` and `tests/Unit/Narrative/ReportingNarrativeCatalogTest.php` only | Adapt and validate injected canonical narrative/connector data for the accepted assembler | active after accepted assembler `457c8b6`; review pending |
| Recommendation acceptance evidence | `/root/review_p17c` | New `tests/Integration/Psychometric/RecommendationGuardrailsAcceptanceTest.php` only | Add explicit T-12/T-13/T-14/T-19 boundary evidence against accepted zone/label services | queued after accepted T-07 `3c5b164`; review pending |

The recommendation-label policy `7334fa0`, session submit action `87b6346`, and
static separation guard `549b2e4` are accepted after independent coordinator
verification. The submit action deliberately seals at `submitted`; scoring and
the public synchronous `status: scored` contract remain a later coordinator
integration boundary. The assessment-session schema/RLS commits `bf72c29` and `2b3937a`, autosave
action/evidence `4a73141` and `c070ba2`, and start/resume action `3cc0e82` are
accepted. ADR-0028 resolves the former IQ/PAPI/RMIB/Kraepelin authority
conflicts. There is no active migration owner. Do not dispatch overlapping work
when a lane becomes active again. The former client setup IDs are historical
only; they must not receive continuation prompts.

Behavioral T-07 is accepted as `3c5b164` (1 test, 10 assertions), with the
architecture guard still passing 3 tests and 19 assertions. G7 source-spread
policy is accepted as `eb5e946` (Eligibility suite 70 tests, 239 assertions).
Both increments passed independent Pint, scoped PHPStan, and diff checks.
The deterministic ID cluster assembler is accepted as `457c8b6` (35 tests,
44 assertions); canonical data adaptation is the active next F4 increment.
