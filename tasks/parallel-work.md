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

Use no more than five **ACTIVE worker tasks** in parallel. Product Manager,
Tech Lead/coordinator-integrator, and PMO governance/review tasks do not consume
worker slots.

Count capacity by worker task, not by branch, worktree, candidate commit, or
handoff record:

- `SETUP` does not count until the real task and isolated worktree are reported,
  the exact baseline and clean status are verified, exclusive scope is accepted,
  and work begins.
- A worker executing implementation, documentation, audit, test, or independent
  QA evidence is `ACTIVE` and consumes one slot.
- A finished worker output in the QA queue does not consume a slot;
  the independent QA worker consumes one only while its QA task is `ACTIVE`.
- `IDLE`, `COMPLETED`, and genuinely `BLOCKED` tasks do not consume slots. A task
  that resumes work must reacquire an available slot before becoming `ACTIVE`.

The five-worker ceiling never relaxes exclusive-path ownership, sole migration
ownership, shared-contract ownership, or the serial integration rules below.

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
| F5 authoritative signing snapshot | `/root/review_ist` | `ReportSigningSnapshotComposer.php`, its unit test, signing transition policy/test, and Review acceptance | Derive transition prerequisites from exact typed G7/override outcomes; bind canonical target field; fail closed on missing structural audit/recalculation evidence | repair-required after adversarial review: forged recommendation, stale level recalculation, and boolean-only G7 resolution can still sign |
| F5 signing bypass architecture guard | `/root/review_p17c` | `tests/Architecture/ReportSigningBypassGuardTest.php` only | Prevent application code outside the signing transition policy from requesting literal `SIGNED` through the generic state machine | accepted `e6de244` (Architecture 22/1,763); dynamic-expression bypass remains outside this static proof |
| F3 G8 version-isolation acceptance | `/root/review_p17c` | `tests/Integration/Eligibility/EligibilityStandardVersionIsolationAcceptanceTest.php` only | Prove pure-domain version provenance/isolation and prevent silent cross-version recommendation composition; persistence non-retroactivity remains separate | accepted `8c7498b` (Eligibility 102/295) |
| F3/F5 persistence gap audit | `/root/review_p17c` | Read-only schema/model/migration/action inspection | Identify exact G6/G7/G8/signing persistence gap and smallest serial PostgreSQL migration slice after snapshot contract freezes | complete; no report/eligibility/G6/G7 durable authority exists yet |
| G8 instrument-version source hardening | `/root/review_p17c` | New `2026_09_09_000100_harden_instrument_versions_history.php`, `InstrumentSeeder.php`, its Feature test, and new PostgreSQL security test only | FORCE RLS service-only, immutable history, one active version per code, trusted seeder context, disposable non-owner verification | accepted `25d6b37` + `d7b5359`; independent PG 426/4,717, no P1/P2 |
| F4 T-20 bilingual determinism acceptance | `/root/review_session` | `tests/Integration/Narrative/BilingualNarrativeDeterminismAcceptanceTest.php` only | Prove canonical same-input repeatability, order independence rejection, and deterministic G7 omission without changing production code | accepted `fc9e78c` (Narrative 114/202) |
| F4 T-21 connector rotation acceptance | `/root/review_session` | `tests/Integration/Narrative/ConnectorRotationAcceptanceTest.php` only | Prove deterministic direction-aware ID connector rotation/no-repeat and connector-free JP output against canonical data | provisional `28e2969`; reopened because supporting DOCX requires independent per-pool counters not yet covered for mixed directions |
| F4 seven-slot authority audit | `/root/review_session` | Read-only SPEC/PRD/supporting-document inspection | Locate authoritative S1-S7 content/tie rules and define the next safe implementation slice without inventing templates | complete; multiple S1-S7 authority gaps recorded; automated summarization remains blocked |
| F4 T-21 independent connector counters | `/root/review_session` | `ClusterNarrativeAssembler.php`, its unit test, and T-21 acceptance test only | Repair mixed-direction connector selection to use separate additive/contrast counters per psychologist §8.1 | accepted `9675c4d` (Narrative 115/257) |
| F4 S5 development-area ordering | `/root/review_session` | New `IntegrationDevelopmentAreaOrder.php` and its unit test only | Deterministically order A1-C7 BELUM/GREY candidates with typed G7 omissions; no prose or D-aspect decision | accepted partial `951651c`; full S5/D/prose remain open |
| F4 integration extrema candidates | `/root/review_session` | `IntegrationClusterExtremaCandidates.php` and its unit test only | Preserve true B/C extrema while omitting G7-blocked extrema without runner-up promotion | accepted across `e0b5d62` + repair `f50f75e`; no S2/S3 prose claim |
| F5 signing snapshot adversarial audit | `/root/review_ist` | Read-only accepted snapshot/signing code and tests | Separate repairable pure-domain inconsistencies from persistence-only authority gaps | complete; four P1 inputs remain caller-forgeable, including G7 source substitution; typed artifacts required |
| F5 manual SIGNED construction guard | `/root/review_ist` | `tests/Architecture/ReportSigningBypassGuardTest.php` only | Extend static guard to successful literal SIGNED transition arrays outside the signing policy | accepted `7891d09`; defense-in-depth only, P1 authority gaps remain open |
| F3 typed eligibility decision snapshot | `/root/review_session` | New `EligibilityDecisionSnapshot.php` and its unit test only | Derive zones/recommendation from exact 18-aspect/versioned inputs; reject caller-authored summaries; V3 blocked | accepted partial `a025c4b`; persistence must still bind raw configuration/source versions |
| F5 typed G7 review set | `/root/review_ist` | New `G7AspectResolution.php`, `G7ReviewSet.php`, and their unit tests only | Replace boolean G7 evidence with exact 18-aspect discrepancy/final-level decisions and conditional G6 reason | accepted partial `3dac6c5`; persistence must still bind discrepancy sources |
| F5 typed reviewed eligibility decision | `/root/review_session` | `EligibilityDecisionSnapshot.php` plus new `ReviewedEligibilityDecision.php` and their unit tests only | Apply exact level overrides, recompute zone/recommendation, then validate optional label override without boolean recalculation evidence | accepted partial `2ac749f`; raw source authority still waits for persistence reader |
| F5 typed signing composer integration | `/root/reviewed_eligibility` | Signing composer/policy and assigned unit/integration tests only | Replace raw recommendation/G7/override booleans with typed reviewed-decision and G7 set; replay pure-domain P1 probes | accepted across `a9375ed` + evidence-binding repair `9b59cd6`; persistence authority remains separate |
| F3/F5 assessment-case identity | coordinator | `docs/decisions/0029-assessment-case-report-identity.md` | Freeze one universal battery/report root across direct, legacy Selection, and integrated flows | accepted; migration/backfill remains serial |
| F3/F5 assessment-case schema phase 1 | `/root/report_identity_audit` | Assessment-case migration, isolated schema/security tests, and the existing billing rollback regression only | Create universal case root and nullable links without backfill/provisioning/report tables; service-only RLS, participant-tenant FK, indexed FKs, and fail-closed rollback | accepted across `542cebd` + `fef865c`; independent PostgreSQL 431/4,784 |
| F3/F5 typed artifact adversarial audit | `/root/verify_pg_history` | Read-only commits `a025c4b`, `3dac6c5`, `2ac749f`, then bounded signing snapshot repair | Probe cross-artifact consistency and separate pure-domain repairs from persistence-only authority | complete; repair `9b59cd6` independently accepted at 382 tests/1,726 assertions; persistence authority remains open |
| F3/F5 assessment-case backfill audit | `/root/verify_pg_history` | Read-only schema/model/action inspection | Map deterministic DIRECT_PUBLIC, LEGACY_SELECTION, and INTEGRATED case backfill plus fail-closed ambiguity checks | complete; only INTEGRATED has a lossless mapping now; phase 2 must dual-write then backfill/enforce that lane only |
| F3/F5 persistence schema audit | `/root/reviewed_eligibility` | Read-only F3/F5 typed artifacts, schema, and ADR inspection | Define the serial eligibility/G6/G7/signing/report-version persistence sequence after case identity is enforceable | complete; signing remains blocked on case enforcement and universal normalized-result authority |
| F2 normalized-result persistence audit | `/root/reviewed_eligibility` | Read-only scoring/session/result inspection | Define the smallest immutable case-bound source/version authority without crossing the DASS boundary | complete; schema-only instrument-result/source-level ledger is next after case enforcement |
| F3/F5 integrated case dual-write phase 2A | `/root/verify_pg_history` | `AssessmentCase` model, `AssessmentParticipant` relation, both integrated provisioners, focused Integration Feature tests, and authorized PostgreSQL acceptance tests | Atomically create one INTEGRATED case per new attempt with identical public/attempt ULID and fail-closed replay validation | accepted across `a48e2af`, `8002ba8`, and deterministic race repair `5f3ee01`; independent PostgreSQL 435/4,935 |
| F3/F5 integrated dual-write adversarial review | `/root/report_identity_audit` | Read-only provisioner/transaction/idempotency inspection | Probe alias/scope binding, replay/concurrency, field provenance, and orphan rollback before phase 2A acceptance | complete; final review accepts `5f3ee01` with deterministic post-read barrier and catch/refetch proof |
| F4 authority resume audit | `/root/reviewed_eligibility` | Read-only accepted Narrative primitives plus PRD/supporting sources | Recalculate F4 acceptance and select one dependency-unblocked pure-domain/test increment without inventing S1-S7 rules | complete; S5 D support, S6 ties/UMUM/label, and system-vs-final G6 remain authority blockers |
| F4 target-interest risk evidence | `/root/reviewed_eligibility` | New `IntegrationTargetInterestRiskEvidence.php` and its unit test only | Emit exact five D1-D5 structural rows for the configured target field, with risk iff target is GREY/BELUM and fail-closed review evidence | accepted `db3dce3`; independent combined Narrative 229/379, scoped PHPStan/Pint |
| F4 S5/S6 authority boundary | `/root/reviewed_eligibility` | New `S5S6AuthorityBoundaryAcceptanceTest.php` only | Lock the two accepted primitives as separate partial evidence without inferring unsupported D support, RMIB winner, suitability label, or prose | accepted `b8efab2`; independent combined Narrative 212/638, scoped PHPStan/Pint |
| F3 D5 RMIB fixture authority | `/root/report_identity_audit` | `AspectSourceDiscrepancyPolicyTest.php` only | Correct D5 source provenance from Persuasive to Social Service | accepted `f9bd44a`; independent combined Eligibility 156/390, scoped PHPStan/Pint |
| F2 case-bound result-ledger freeze | `/root/reviewed_eligibility` | Read-only SPEC/scoring/schema inspection | Freeze the next immutable instrument-result/source-level schema and its dependencies after case phase 2B | complete; implementation blocked on immutable case-bound test sessions and versioned aspect-source mapping outside prose |
| F3/F5 integrated case backfill and enforcement phase 2B | `/root/verify_pg_history` with disjoint adapter lanes | Serial migration `000300`, focused schema/security tests, frozen billing fixture, and explicitly assigned PostgreSQL/Feature adapters | Backfill every historical integrated attempt losslessly, enforce exact immutable case identity, preserve RLS/history, and keep sessions/direct/legacy/DASS outside scope | accepted across `2dded9c`, `1d4354e`, `1a8033e`, and `be98ec7`; independent PostgreSQL 444/5,007 and Feature 106/689 |
| F2 test-session case identity audit | `/root/verify_pg_history` | Read-only session schema/action/flow inspection | Determine lossless session-to-case mappings and the smallest safe persistence increment after phase 2B | complete; only exact pre-bound or single-candidate rows are deterministic, so nullable integrity foundation precedes final NOT NULL |
| F2 nullable test-session case identity foundation | `/root/verify_pg_history` | Serial migration `000400`, focused SQLite/PostgreSQL tests, and exact schema-boundary compatibility files | Backfill only single-case candidates, preserve zero-candidate NULLs, reject ambiguity, and enforce immutable exact case/participant identity for bound sessions | accepted `7e5fbba`; worker PostgreSQL 464/5,124 green, independent focused 195/1,027 and adversarial review clean |
| F0 versioned aspect-source mapping | `/root/reviewed_eligibility` | Workbook-derived extractor, new `aspect_sources.json`, seeder registration, and focused Python/Feature/PostgreSQL tests | Freeze exact 18-aspect/42-association source data without hardcoding or crossing DASS | accepted `07eaa98`; LF-only 1,148 bytes, SHA-256 `cdd6c3b8...b4d9a9`, independent Python 20 and Feature 5/59 |
| F2 DIRECT_PUBLIC case identity audit | `/root/audit_direct_case_identity` | Read-only registration/order/case authority inspection | Freeze the lossless direct case dual-write and history boundary | complete; order ULID is the case alias, DASS-only stays unbound, order→case relation and strict backfill required |
| F2 LEGACY_SELECTION case identity audit | `/root/audit_legacy_case_identity` | Read-only Selection mapping/provisioning/case inspection | Freeze the lossless legacy case identity and replay boundary | complete; mint opaque case ULID, package/history field remain NULL, split-brain replay must fail closed |
| F2 LEGACY_SELECTION case identity implementation | `/root/audit_legacy_case_identity` | Serial migration `000500`, Selection provisioner/model relations, focused SQLite/PostgreSQL/Feature/concurrency tests | Backfill and enforce exact Selection→case identity, then dual-write new provisioning | accepted `7aaa00b` + `5b11f0b` + `6f3feb1` + `1200ba0`; PostgreSQL 468/5,189, adversarial review clean |
| F2 case-aware session allocator audit | `/root/audit_direct_case_identity` | Read-only session writer/entitlement/case inspection | Freeze allocator authority, idempotency, locking, and prerequisites without guessing a participant case | complete; no production writer exists, case must resolve through origin grant, and duration/config/version authority remains prerequisite |
| F2 instrument session-definition authority audit | `/root/audit_direct_case_identity` | Read-only SPEC/PRD/config/data inspection | Locate authoritative duration/config/seed/version inputs required by the allocator | complete; IST/Kraepelin basics known, but ME split, PAPI/RMIB duration, seeded generator, item assets, and provenance persistence remain unresolved |
| F2 typed session-definition contract | `/root/audit_direct_case_identity` | New pure domain value object and synthetic unit tests only | Establish a strict fail-closed definition boundary without inventing production data or wiring allocator | accepted `804a414` + `a5762bb`; adversarial re-review clean, AssessmentSessions 142/338, PHPStan/Pint green |
| F2 DIRECT_PUBLIC implementation-readiness audit | `/root/verify_pg_history` | Read-only order/package/entitlement/migration-boundary inspection | Freeze strict direct backfill, DASS-only classification, guards, lock order, and test adapters | complete; main package is relational DASS+non-DASS, exact entitlement set required, migration `000600` is next serial lane |
| F2 DIRECT_PUBLIC case identity implementation | `/root/audit_direct_case_identity` | Serial migration `000600`, RegisterParticipant/order/case relations, focused schema/registration/concurrency tests, explicit adapters only | Backfill and enforce exact order→case identity for main packages while DASS-only remains unbound | accepted `43a21f0` + `4f611c6` + `87c530e`; PostgreSQL 475/5,258, adversarial review clean |
| F7 proctoring authority audit | `/root/audit_legacy_case_identity` | Read-only SPEC/PRD/schema/UI inspection | Freeze event-to-validity, human adjudication, privacy, retention, and missing implementation boundaries | complete; policy authority available, persistence/API/UI wait for case-aware sessions and later migration ownership |
| F7 pure proctoring validity policy | `/root/audit_legacy_case_identity` | New pure domain types/policy and unit tests only | Encode T25-T28 fail-closed without claiming storage, provider, UI, or global publication authority | accepted `63b002f` + `13a2847` + `a92ffc2`; adversarial review clean, focused 30/113, Unit 1,101/3,043 |
| F7 commission/dashboard authority audit | `/root/verify_pg_history` | Read-only PRD/SPEC/Filament/RLS/payment inspection | Freeze ledger, roles, forward-only rates, withdrawal, dashboard, and missing decisions | complete; implementation blocked on rate/base/rounding, eligible organizations, timezone, free/refund, and payout decisions |
| F9 hardening evidence audit | `/root/verify_pg_history` | Read-only CI/queue/cache/retention/load/recovery inspection | Separate executable synthetic gates from runtime/authority blockers | complete; static queue partial, secret/PII scan/cache drift/retention/load/backup gates remain open |
| F2 final session-case readiness re-audit | `/root/audit_legacy_case_identity` | Read-only post-`000500`/`000600` session/grant inspection | Determine whether universal session case NOT NULL and allocator are now lossless | complete; opaque authorization/allocation IDs still lack durable grant relation, so final NOT NULL and production writer remain blocked |
| F2 origin-aware case authorization resolver | `/root/audit_direct_case_identity` | New read-only resolver/domain files and focused tests only | Resolve INTEGRATED, DIRECT_PUBLIC, and LEGACY_SELECTION grants to one exact case; fail closed without wiring a session writer | accepted `87946e7` + `0cccb43` + `66d0f86`; SQLite 12/39, PostgreSQL 476/5,274, final adversarial review clean |
| F2 resolver contract adversarial audit | `/root/audit_legacy_case_identity` | Read-only schema/model/query inspection | Freeze cardinality, tenant, instrument, origin, and DASS-only rejection semantics before integration review | complete; typed principal entrypoints, transaction/RLS preconditions, exact graph checks, lock order, and adversarial matrix frozen |
| F9 secret/PII scan gate readiness audit | `/root/verify_pg_history` | Read-only CI/script/test inventory | Define a bounded synthetic scanning gate and safe exclusive files for a later increment | complete; executable gate absent, exact tracked-blob/redacted/fail-closed boundary frozen |
| F9 synthetic repository secret/PII gate | `/root/verify_pg_history` | New `tools/security/repository-content-scan*`, `package.json`, and `composer.json` only | Add separately invocable secret and PII profiles to the existing `ci:check` path with exact expiring exceptions | provisional `1358d66` + `b344a0f` + `727b294`; 31/31 and adversarial review clean, but root profiles fail closed on tracked DOCX |
| F9 OOXML content inspection | `/root/audit_direct_case_identity` | New `tools/security/ooxml-content.mjs` plus scanner source/test only | Inspect DOCX XML/RELS with bounded pure-Node ZIP validation so both repository profiles cover the tracked PRD | accepted-provisional `5a0ec7f` + `ee38773`; 39/39 and adversarial review clean, PRD DOCX covered |
| F9 generic text-ZIP content inspection | `/root/audit_direct_case_identity` | New strict text-ZIP helper plus security scanner/OOXML test integration only | Inspect the tracked Markdown archive without exposing inner names or allowing binary/nested content | accepted-provisional `fd2d3f0`; 43/43 and adversarial review clean, tracked archive has zero findings |
| F9 binary asset gate readiness | coordinator decision | Exact two PNG and one ICO only | Preserve fail-closed scanning while defining authenticated structural/digest approval for static images | blocked on security owner/CODEOWNERS or signed-attestation authority; no extension allowlist accepted |
| F2 durable session-grant identity audit | `/root/verify_pg_history` | Read-only post-resolver schema/RLS/history inspection | Freeze the smallest immutable typed grant-to-session binding required before allocator wiring | complete; append-only 1:1 origin-FK relation frozen, and zero historical sessions are safely backfillable |
| F2 test-session grant schema `000700` | `/root/verify_pg_history` | Sole serial migration owner plus new SQLite/PostgreSQL schema-security tests and explicit runner registration | Add immutable typed grant binding for future allocator dual-write without guessing historical sessions | accepted-provisional `dea05e2` + `9a6b1ae` + `96a2c01` + `7d00402` + `d9c0b55`; SQLite boundary 57/305 and static review clean, PostgreSQL runtime still unknown |
| F9 Git-history secret gate audit | `/root/audit_legacy_case_identity` | Read-only CI/tool/version/baseline inspection | Define a pinned reproducible history scan without exposing findings or rewriting history | complete; design ready, enforcement awaits initial human triage plus security review/CODEOWNERS authority |
| F9 pre-existing ESLint blocker audit | `/root/audit_legacy_case_identity` | Read-only lint output and Git-history inspection | Partition the 210 cross-lane errors into safe, non-overlapping repair slices without running auto-fix | complete; 210 mechanical errors isolated to four historical frontend/browser files |
| F9 targeted ESLint blocker repair | `/root/audit_legacy_case_identity` | Exact four audited frontend/browser test-driver files only | Apply targeted mechanical ESLint fixes and preserve source-text/browser semantics through focused checks | accepted `0a53002`; repo lint green and focused semantic/source-integrity review clean |
| F5 signing bypass + V3 repair | `/root/review_ist` | Assigned existing Review state/signing policy tests plus the Review acceptance test only | Remove raw UNDER_REVIEW-to-SIGNED bypass and make V3 no-label output composable while deterministically blocked | accepted `44d047e` |
| F3 exact field configuration repair | `/root/review_session` | `app/Domain/Eligibility/EligibilityZoneCalculator.php` and `tests/Unit/Eligibility/EligibilityZoneCalculatorTest.php` only | Require exact six canonical fields and exact field-record schema | accepted `34f35c1` |
| F3 G7 single-source repair | `/root/review_p17c` | `app/Domain/Eligibility/AspectSourceDiscrepancyPolicy.php` and `tests/Unit/Eligibility/AspectSourceDiscrepancyPolicyTest.php` only | Accept valid one-source aspects as spread zero while preserving multi-source behavior | accepted `5caba7c` |

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
44 assertions); canonical data adaptation is accepted across `c327f2b` and
`c7618b9` (Narrative suite 62 tests, 80 assertions). The latter split was a
shared-index coordination race recovered without reset or lost changes.
The standalone JP assembler is accepted as `7eb1fdc` (Narrative suite 92 tests,
118 assertions); it emits no additive/contrast connectors and omits unresolved
G7 aspects.
Canonical JP projection is accepted as `c1e8b93` (Narrative suite 94 tests,
126 assertions) without changing the existing ID assembler input contract.
The F5 state machine is accepted as `74b92ca` (81/81 focused; Review suite
165/199) and the G6 override policy as `204c226` (50/68 focused; Review suite
165/199). Both passed independent formatter, static-analysis, architecture,
and diff gates.
G6 behavioral recalculation evidence is accepted as `49c9c4c` (2 tests,
27 assertions; combined relevant suite 260/514). Signing transition composition
is accepted as `b6bdfbc` (23/49 focused; Review suite 188/248).
The bilingual F4 composer is accepted as `6187b9b` (Narrative 112/178), and
F5 guardrail evidence as `e3200f8` (12/37; Review+acceptance 200/285).
Adversarial review then found one P1 signing bypass and four P2 contract gaps.
The bypass/V3 repair `44d047e`, exact field configuration repair `34f35c1`,
and G7 single-source repair `5caba7c` are independently accepted. Their combined
gate passed 440 tests/1,059 assertions, Pint, scoped PHPStan, architecture, and
diff checks. The authoritative derived signing snapshot is the remaining P2
implementation boundary and is now dependency-unblocked.
Recommendation acceptance evidence is accepted as `f981c92` (7 focused tests,
49 assertions; combined Eligibility 77/288). The pure F5 signing prerequisite
gate is accepted as `4933c12` (34 tests, 50 assertions). Both passed independent
Pint, scoped PHPStan, architecture, and diff checks.

## Restart dispatch — 2026-09-10

Coordinator resume baseline is `a369745` on
`codex/organization-payment-spec`. Docker Engine 29.7.2 and the local project
PostgreSQL container were healthy after restart. The untracked ADR-0026 remains
user-owned and is excluded from every worker scope and coordinator commit.

| Task setup ID | Lane | Exclusive ownership | First increment | Status / next checkpoint |
|---|---|---|---|---|
| `01a0881b-8590-7123-a9ce-640f7cbf75c3` (`2469`) | F2 definition authority reconciliation | Read-only source matrix; migration/catalog/provider files are frozen after acceptance | Reconcile supporting documents against exact catalog payload fields without importing data | Catalog workers `76a3e8f` + `c97be3a` + `c0b2a36` accepted as coordinator `edb4cd5` + `ee3b3c3` + `70b3856`. Main SQLite/provider 12/44 and PHPStan 0; disposable PostgreSQL 510/5529 with only the known unrelated grant-security baseline error, catalog tests green, cleanup complete. Recovery audit recalculated 0/4 instruments import-ready: every instrument lacks approved definition version/provenance, PAPI/RMIB timing is unapproved, IST ME split is missing, and Kraepelin seeded-vs-fixed authority conflicts. No seed or active row is allowed until a psychologist-approved four-entry manifest resolves those fields |
| `01a0881b-86ec-7fc1-b0f3-99e1e5b99cb1` (`fa77`) | F2 pure multi-case selection boundary | No active file ownership after accepted domain slice | Await serial schema expansion after source reconciliation and explicit migration reassignment | Worker `9d5c4af` passed independent 34/64 plus PHPStan/Pint/diff and was accepted as coordinator `ec8d4d5`; main focused 34/64 plus PHPStan 0. ADR-0031 freezes no caller case ID, replay precedence, fail-closed multiple-ready ambiguity, case+instrument history, and the serial migration order; FIFO and durable retest authority remain blocked |
| `/root/design_multi_case_schema` (`case-schema-sept10`, next increment by `/root/review_f2_conditional`) | F2 entitlement case-identity expansion | One new uniqueness-contraction migration plus its dedicated SQLite/PostgreSQL tests only | Add case+instrument uniqueness for generic entitlements and DASS participant singleton, then safely remove the legacy participant+instrument uniqueness | Conditional invariant `7a80180` + writer repair `f906cf9` + compatibility `1a51347` passed worker full PostgreSQL 530/5,646, independent boundary PostgreSQL 89/679, focused SQLite 25/183, and static gates; they are integrated as `884cbed` + `297d2ea` + `7209dc0`, with main focused 16/127. A main full-run attempt was invalidated by unrelated nondeterministic child-process pollution (duplicate payment methods, leaked audit/storage facades, broken pipe); disposable resources were cleaned and no `000400` test failed. Uniqueness slice 4/6 is active from worker baseline `1a51347`; it must preserve the requirement/guard, grants/sessions/readers/writers, shared routes/contracts, and historical migrations, and down must reject multi-case history that cannot fit the legacy unique constraint |
| `01a0890c-53b1-7bf1-a6b3-59addda5b722` (`1747`, continued by `/root/review_f9_lifecycle`) | F9 retention execution | No active ownership after the accepted audit purge action | Design command/scheduler wiring only after an explicit operational safety review | Audit hard-code migration is complete: all 35 audit writers across 29 files use `RetentionDataClass::Audit`; the five remaining production two-year literals are verified outbox/business retention and remain unchanged. Denied-poll audit `98511a4` is integrated as `fa8a000`. Audit purge worker `68de0d9` passed independent review and is integrated as `9559237`; main Feature passed 6/26 and independent disposable PostgreSQL FORCE-RLS passed 1/10. The executor is service-context, active-transaction, deterministic, and capped at 1,000 rows, but no command/scheduler is wired or active. No live data, migration, route, config, other table, or F2 file is owned here |

The coordinator owns this canonical dispatch record and all acceptance updates.
Only `/root/design_multi_case_schema` in worktree `case-schema-sept10` may edit
the new F2 migration in this wave. No worker may edit routes, lockfiles, ADR
numbering, or canonical checklists in this wave. Shared contracts must use the
exclusive ownership recorded above and be integrated by the coordinator before
any dependent allocator slice begins.

## Resume dispatch — 2026-09-13

Coordinator resume baseline is `7567ad6` on
`codex/organization-payment-spec`. The root worktree contains five pre-existing
modified checkout integration tests and untracked ADR-0026; they remain outside
all worker scopes. Formal checklist completion was recalculated as 81/100 in
`tasks/todo.md` and 92/120 in `tasks/organization-payment/todo.md` (173/220
combined); unchecked acceptance remains open until its evidence passes review.

| Task/thread ID | Lane and phase | Branch/worktree | Exclusive ownership | Increment and acceptance | Status / next checkpoint |
|---|---|---|---|---|---|
| `01a05839-3b39-7d83-b59f-9e7432d7883e` | P15/P16 participant browser preparation | preserved detached worktree `d4ea`; implementation uses a task-owned temporary worktree from main `7567ad6` | `tools/testing/tests/Browser/checkout-session.browser.mjs` and `tools/testing/tests/Browser/serve-checkout-session.php` only | R1-R2 only: add one fixed synthetic mounted-confirmation alias and verify the missing-profile/current-consent/DASS-required form plus its test-only enhancer; no submit/provider/outbound | prior contract checkpoint accepted as main-equivalent `f0f2af1`/`563fa6e`; new increment active at cursor `62e007d3-56d1-4cb5-b394-bdcad652fb3e:3`; stop before R3 |
| `01a05839-3b48-7801-8175-0392e8764c23`, repair `/root/f2_pg_regression` | F2 durable session-grant verification | read-only audit at main `7567ad6`; repair on coordinator worktree after task-service retry failed | `tests/Postgres/TestSessionGrantSecurityTest.php` only for repair; migration stays frozen | Add PostgreSQL canonical unchanged-rerun and populated-down/no-delta regressions after static migration audit found no source P1/P2 | audit repair-required: disposable runner produced zero tests due bind-mount D-state and cleaned its labeled resources; repair worker active, acceptance remains provisional |
| `01a05839-3b18-73e0-8fdc-8db3b02f835d` | F9 retention command boundary | preserved detached worktree `6e61`; implementation uses a task-owned temporary worktree from main `77ec241` | new `PurgeExpiredAuditLogsCommand.php` and its new Feature test only | Add a one-batch, exact-true-config, redacted command boundary with strict limit, rollback/context proof, and no retry; config and scheduler remain coordinator-owned | safety audit accepted; implementation active; no real purge/runtime and no config/routes edit |

The F2 repair owns only its recorded PostgreSQL test file. Frontend and F9 each
own only their two recorded files in task-owned temporary worktrees. None owns
migrations, routes, config, lockfiles, ADR numbering, canonical checklists, or
untracked ADR-0026. The next increment is sent only after each result is
independently reviewed.

Coordinator fixture repair `48a5bd8` binds the five previously modified
checkout/payer test fixtures to their exact assessment cases and preserves the
main-package DASS-21 inventory. The focused organization-payment run passed
70 tests/2,181 assertions, Pint passed, and `git diff --cached --check` was
clean. Untracked ADR-0026 remains excluded and unmodified.

## Wave 1 acceptance and next dispatch — 2026-09-13

Wave 1 is accepted in
[`tasks/handoffs/integration-2026-09-13-wave1.md`](handoffs/integration-2026-09-13-wave1.md).
F2 fixture-time repair is integrated as `83cd0a2`, F9 suite isolation as
`a522f47`, and the inert retention command as `7b496e0`. Independent focused
PostgreSQL passed 15 tests/74 assertions and the fresh full suite passed
533 tests/5,679 assertions. The old frontend R1-R2 dispatch is superseded by
the broader main-history confirmation coverage and must not be re-dispatched.

The canonical F2-F9 phase and T-01..T-28 matrix is
[`tasks/f2-f9-acceptance.md`](f2-f9-acceptance.md). Three disjoint, report-only
workers are active from baseline `a522f47`: four-instrument authority manifest,
ADR-0030 start-flow readiness, and vertical instrument UI readiness. Their exact
outputs and ownership are recorded in the integration handoff. No production
file is worker-owned in this audit wave.

## Tech Lead Wave 1 dispatch — 2026-09-13

PM accepted a new integration target on `codex/f2-wave1-integration`. The clean
functional base is the atomic S1/S2 stack at `5d043a1`; governance-only overlay
`11763b3` was replayed as `3c92c5c`. The next accepted order is R0, then S3.
F5 commit `2a078db`, the dirty S4 candidate, and release report `686b189` remain
outside implementation acceptance. Product/release status remains partial.

Only two workers are initially dependency-unblocked. S3 is intentionally not
dispatched until the R0 integration commit has passed Tech Lead review and can
serve as its exact base.

| Task/thread ID | Lane and phase | Branch/worktree | Baseline | Exclusive ownership | Increment and acceptance | Status / next checkpoint |
|---|---|---|---|---|---|---|
| `/root/wave1_r0_r1` | F2 R0 integration and smallest R1 IST result contract | `codex/f2-r0-persistence-seam`; `C:/Users/ThinkPad/.codex/worktrees/r0-wave1/Psikotes` | functional `5d043a1`; governance overlay `3c92c5c` | `app/Domain/AssessmentResults/**`, `app/Services/AssessmentResults/**`, `tests/Unit/AssessmentResults/**`, and `tests/Feature/AssessmentResults/**` only | Integrate accepted `db8b1c1` without persistence/HTTP claims, then add one synthetic, unwired IST adapter and strict immutable result DTO test-first. No migration, queue, route, active catalog, or DASS/Kraepelin expansion. | R0 integrated as `24af4e7`; R1 `108dad8` required checksum repair `f68966c`, then was accepted as `858f80b` + `5704a50`; independent R0/R1/IST gate 89 tests/175 assertions; R2 waits for serial migration ownership |
| `/root/wave1_s4_pg` | F2 S4 PostgreSQL runtime-role repair; sole migration owner | `codex/f2-s4-runtime-role-repair`; `C:/Users/ThinkPad/.codex/worktrees/case-schema-sept10/Psikotes` | divergent six-commit stack ending `1a51347` | migration `2026_09_10_000500_contract_generic_entitlement_uniqueness.php`, `phpunit.organization-postgres.xml`, and its four recorded focused migration tests only | Keep DDL owner-only; prove behavioral insert/duplicate/tenant denial through `psikotes_runtime`; require green disposable PostgreSQL, RLS/concurrency/rollback evidence, and exact-label cleanup. | `2e97dee` accepted and integrated as `9312914` after prerequisite patch-equivalence proof; scoped SQLite 13/84 and independent disposable PG 5/52 remain valid. The former full-suite 535/5,698 claim is not independently verifiable after QA observed 538 tests followed by unrelated duplicate payment-method fixtures, leaked Storage/Mockery state, and a broken pipe; migration ownership released |
| `/root/wave1_s3` | F2 S3 trusted participant start integration | `codex/f2-s3-start-integration`; `C:/Users/ThinkPad/.codex/worktrees/s3-wave1/Psikotes` | accepted R0 integration `24af4e7` | `StartParticipantAssessmentSession.php`, `AllocateAndStartAssessmentSession.php`, and their focused tests only | Reintegrate the audited S3 delta, preserve `93dd8ea` patch equivalence, and rerun the 56-test/242-assertion focused matrix plus static gates. No S5 HTTP wiring. | `4230860` accepted and integrated as `29b5f32`: all four blobs equal the audited source, focused 56/242, syntax/Pint/diff/secret gates green; PostgreSQL remains S4-owned |
| `/root/wave1_f5_fixture` | F5 testing-only review-contract fixture repair | `codex/f5-fixture-contract-repair`; `C:/Users/ThinkPad/.codex/worktrees/f5-wave1/Psikotes` | accepted integration head `4bb3464`; quarantined candidate `2a078db` | exactly the fixture page class/view and their Feature/browser tests | Close audited V2/blocker/recalculation/G7 ordering/focus/draft-loss/role-denial/format gaps test-first while preserving testing-only discovery, dual abilities, and permanently unbound persistence. No production signing, publishing, route, DTO, or state-machine work. | accepted as `3ca31c4` + `fef6add` + `e1cdf73` after two repair reviews; independent 24 tests/301 assertions plus PHPStan/Pint/ESLint/Prettier/diff and refreshed 320/1280/V3 visual inspection green; testing-only evidence, not production F5 completion |
| `/root/wave1_s4_pg` (reused) | F2 R2 immutable IST result ledger; sole migration owner | `codex/f2-r2-ist-result-ledger`; `C:/Users/ThinkPad/.codex/worktrees/r2-wave1/Psikotes` | accepted schema stack ends at `0ae490b`; R2b worker `a704bc8` is integrated as `bf87484` | migration and existing PostgreSQL ledger test are frozen; writer scope was exactly new `PersistSealedIstResult.php` and its new Feature test | Add the exact-replay, caller-transaction-owned writer over the accepted append-only schema. No reuse of Selection result history; no DASS, A1-D5, correction/retest, route, queue, report, or activation. | **R2 ACCEPTED for schema plus unwired IST writer.** Independent QA passed exact two-file scope, SQLite 4/88, explicit PG runtime NOBYPASSRLS concurrency 1/26, Pint/PHPStan/syntax/diff, and cleanup 0/0; PM authorized canonical integration. F2 remains PARTIAL and all HTTP/queue/report/session mutation/activation stays out of scope |
| `/root/wave1_r2c_reader` | F2 R2c immutable IST ledger reader | `codex/f2-r2c-ist-result-reader`; `C:/Users/ThinkPad/.codex/worktrees/r2c-wave1/Psikotes` | canonical `dabe77e` | exactly four new files: `PersistedIstResult.php`, `LoadPersistedIstResult.php`, and their Unit/Feature tests | Add a service-context, caller-transaction-owned, read-only fail-closed boundary that verifies the accepted IST parent/payload/checksum and nine ordered source rows. Include SQLite adversaries and an explicit PostgreSQL runtime-role sandbox proof. | **R2c ACCEPTED.** QA passed the strict decoded-JSON numeric repair; the patch-equivalent canonical stack is `d5989e8` + `4fb3731`, with 9/60 local and 1/15 PostgreSQL runtime-role evidence. No migration, manifest, HTTP, queue, report, session mutation, raw-answer reconstruction, or activation work |

Coordinator retains sole ownership of shared routes, DTO/API contracts, ADRs,
lockfiles, canonical checklists, and this dispatch record. No live migration,
deployment, real payment, scheduler, outbound action, or feature activation is
authorized. Worker completion remains review-pending until the Tech Lead
inspects the exact commit and independently reruns risk-proportionate gates.

S5 HTTP cutover was evaluated immediately after S4 acceptance and is held. The
four-instrument authority manifest remains 0/4 start-ready, so production cannot
bind `AssessmentSessionDefinitionAuthority` to an approved IST, PAPI, RMIB, or
Kraepelin definition. A controller lane would therefore provide either a
synthetic success path or only `SESSION_ENGINE_PENDING`; both violate the S5
readiness stop condition and would not be meaningful implementation. No S5
worker is dispatched, the open slot is reserved for review, and shared route,
request, controller, and API-contract files remain Tech Lead-only. Re-evaluate
only after at least one approved start-ready manifest entry exists and the S0-S4
proof required by ADR-0030 remains green.

Independent QA keeps product status NO-GO at 0/9 phase exits and 0/28
end-to-end rows. The repository secret/PII gate is also not independently
verifiable because its current scanner cannot decode
`public/apple-touch-icon.png`. PM opened disjoint project tasks for test-harness
reliability (factory/teardown only) and scan-gate reliability
(scanner/profile/tests only). Their task IDs, exact branches, and ownership are
recorded here only after worktree setup is ready; both forbid migrations,
shared routes/API/DTO/ADR/checklists, and feature implementation.

Both recovery setups are now addressable from their intended integration
baseline. Test Reliability resolved to `codex/test-harness-reliability` at
`C:/Users/ThinkPad/.codex/worktrees/5ffe/Psikotes`; its repair stack ends at
`71fd720` and its evidence record at `6425d0b`. Security Scan Gate resolved to
`codex/security-binary-content-gate` at
`C:/Users/ThinkPad/.codex/worktrees/c4e5/Psikotes`; its implementation is
`310b8a1` and evidence record `a053e56`. The original client setup IDs were
`client-new-thread:32abbed9-740e-4c30-a6c6-4de72fa7a89d` and
`client-new-thread:cf785283-cd57-46cf-83f7-649cdd94bfd3`; they remain setup
identifiers, not task IDs. Both candidate stacks are review-pending and confer
ownership only over the files enumerated in their handoff reports; neither is
implicitly integrated by this resolution.

Independent QA subsequently downgraded R2a at the PM gate despite reproducing
12 PostgreSQL tests/97 assertions. The verifier does not yet prove exact ACL
topology because boolean privilege checks can admit `WITH GRANT OPTION`; unique
constraint verification omits `condeferrable` and `condeferred`; and the
evidence exercises only serial duplicate rejection rather than two live
connections racing the same session. Commit `22ff5dc` is therefore historical,
not an acceptance claim. R2b writer files are quarantined and frozen until the
sole migration owner closes all three R2a gaps and PM accepts QA re-verdict.

The three repairs are integrated as `0ae490b` from worker `4a38496`. Worker
evidence passed full PostgreSQL 15 tests/122 assertions and a final-byte focused
3/25 rerun; the Tech Lead independently repeated the full 15/122 against the
exact repair with run label `oncam.r2.tl=fcfeeeb6196e4d3694d0bb87e49b745e`
and verified cleanup at 0 containers/0 networks. R2a remains re-verdict-pending,
not accepted, and R2b stays frozen until PM receives independent QA approval.

Independent QA then repeated full PostgreSQL 15/122 and focused 3/25 on fresh
databases, observed the two-backend lock wait and one-commit/one-`23505`
outcome, passed Pint and corrected-bootstrap PHPStan, verified cleanup 0/0 for
both runs, and confirmed the accepted tree contains no writer. QA returned PASS
with a schema-only boundary, which PM accepted. R2a is therefore accepted at
`0ae490b`; migration ownership is frozen again, and R2b resumes only on its
previously quarantined writer/service tests without wiring or activation.

R2b worker commit `a704bc8` was independently reviewed from an immutable
snapshot and integrated patch-equivalently as `bf87484`. Its diff is exactly the
new persistence service and Feature test; the R2a migration and PostgreSQL
ledger-test blobs remain unchanged. Independent QA passed SQLite 4 tests/88
assertions and the explicitly discovered PostgreSQL runtime-role two-process
writer test at 1/26 with no risky or skipped tests. Both workers waited on the
exact session lock, returned one uppercase replay identity, and left one parent
plus nine ordered children. Pint, scoped PHPStan, syntax, and diff gates passed,
and disposable PostgreSQL cleanup was 0 containers/0 networks. PM accepted R2b
for canonical integration with the boundary still excluding HTTP, queues,
reports, session mutation, feature activation, and any broader F2 completion
claim. Tech Lead post-integration verification on `bf87484` repeated SQLite at
4/88 and PostgreSQL at 1/26 with run label
`oncam.r2b-tl=5d7d9bc290d345b8b50429997140b7c5`; the latter again cleaned up to 0
containers/0 networks. The R2 worker is closed after handoff, and its sole
migration ownership is explicitly released; the accepted migration remains
frozen until a future lane is separately assigned.

R2c worker commits `ddcd0207` + `91f9f38` were independently reviewed and
integrated patch-equivalently as `d5989e8` + `4fb3731`; aggregate patch id
`55ee4702bec44f173336fc97c77ea7caa0ee9df2` matches on both sides. QA first
rejected numeric-string coercion in correctly re-signed decoded JSON, then
passed the repair which requires native JSON integers across all payload
numeric fields while retaining canonical database-driver numeric strings only
for parent/source rows. Tech Lead post-integration verification passed the
combined reader suite at 9 tests/60 assertions, Pint, scoped PHPStan, syntax,
diff, and the explicit PostgreSQL runtime-role reader at 1/15 under
`psikotes_runtime` without superuser or `BYPASSRLS`. That run used label
`oncam.org-test-run=c45447c0955f439e89071988b205ac4f` and cleaned up to 0
containers/0 networks. The lane adds only the four internal reader/DTO/test
files; the worker is closed and no migration, HTTP, queue, report, F3,
manifest, active-catalog lookup, raw-answer reconstruction, or activation is
claimed.

## DeepSeek F3/F4 persistence + HTTP projection acceptance — 2026-09-17

DeepSeek's assigned lane (F3 Eligibility + F4 Narrative: persistence,
HTTP/API projection, F5-consumable output; see the ownership ledger in
`AGENTS.md`) is accepted across three increments on
`deepseek/f3-f4-eligibility-narrative`, baseline `3e1538d`:

- `d1ad798` — `eligibility_decision_versions` and `bilingual_narrative_versions`
  migrations (ULID versioned, append-only trigger guard, RLS service-only
  read/insert, driver-conditional jsonb/json), three new controllers
  (`EligibilityDecisionController`, `BilingualNarrativeController`,
  `ReviewInputController`) using `RlsContextRunner::runAsService()`, and
  persistence integration tests.
- `e84b8cd` — five admin routes registered in `routes/web.php` (`whereUlid`
  case parameter, `panel:admin`+`AuthenticateFilament`+`ApplyRlsContext`+
  named-throttle middleware stack matching the existing
  `admin.assessment-participants.export` pattern), three new named rate
  limiters in `AppServiceProvider`, controller signatures changed to resolve
  the case ULID inside the `runAsService()` closure (not via `Route::bind`,
  which would run before RLS context is established — verified against
  Laravel's `SortedMiddleware`/`$middlewarePriority`), and 12 HTTP endpoint
  tests.
- `2fdcdf9` + `09be48b` — replaced raw `ModelNotFoundException` throws inside
  RLS closures with `return null`/`return [null, null]` plus the existing
  `$this->error(...)` envelope convention (`CASE_NOT_FOUND`), so the API's
  error shape stays consistent; endpoint tests now assert the error body,
  not just the status code.

Coordinator independently re-verified at each increment (git diff scope,
`runAsService()` usage, and re-running the test files directly — not
accepting self-reports). Final independent run:
`vendor/bin/phpunit tests/Integration/Eligibility/EligibilityDecisionEndpointTest.php
tests/Integration/Narrative/BilingualNarrativeEndpointTest.php
tests/Integration/Narrative/ReviewInputEndpointTest.php
tests/Integration/Eligibility/EligibilityDecisionPersistenceTest.php
tests/Integration/Narrative/BilingualNarrativePersistenceTest.php` — 33
tests, 33 passed, 86 assertions. Broader regression check: full Unit suite
1176 passed (+9 skipped) and full Integration suite 70 passed, both
unaffected.

Scope stayed exactly within `app/Domain/Eligibility/**`,
`app/Domain/Narrative/**`, new files under `app/Http/Controllers/`, and new
test files under `tests/**/Eligibility/**`/`tests/**/Narrative/**`, plus the
two narrowly-authorized exceptions (`routes/web.php` route additions and
`AppServiceProvider` limiter additions) — verified by diff inspection each
time, not by trusting the worker's own scope claim.

**Known limitation:** the PostgreSQL/RLS branch of both migrations
(`SECURITY DEFINER` guard functions, `REVOKE`/`GRANT`, `CREATE POLICY`) was
reviewed against the closest precedent
(`2026_09_13_000100_create_generic_instrument_result_ledger.php`) but never
executed against a live PostgreSQL instance — only the SQLite branch was
run and verified end-to-end (migrate, rollback, and real insert/update/
delete guard-trigger tests). This is recorded as `partial` rather than
`pass` in the PostgreSQL/RLS column of `tasks/f2-f9-acceptance.md` for
T-12..T-14 and T-17..T-21. A PostgreSQL runtime-role verification pass
(disposable container, real `psikotes_runtime` connection, adversarial RLS
probes) is the next dependency before this can be marked fully accepted for
that column.

**Known open gap (not DeepSeek's to close):** no table anywhere persists a
psychologist-edited version of narrative/INTEGRATION text distinct from the
system-generated baseline in `bilingual_narrative_versions`. Per
`CLAUDE.md`'s "Teks INTEGRATION tersunting psikolog TIDAK boleh tertimpa
saat regenerate tanpa perubahan data" rule, this must land before any F5/F6
review UI lets a psychologist hand-edit narrative text, or an edit will be
silently lost on the next F4 regenerate. Flagged for whichever lane owns
F5 review persistence or F6 report rendering.

`deepseek/f3-f4-eligibility-narrative` is **not merged** into any
integration/checkpoint branch. Promotion is a separate coordinator decision
pending confirmation of which branch is the current live integration line.
