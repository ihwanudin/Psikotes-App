# Project continuation rules

These rules apply to every Codex session and delegated task in this repository.

## Required reading before work

1. Read `SPEC.md`, `SCORING_ALGORITHM.md`, and the relevant PRD section.
2. Read `tasks/parallel-work.md` before assigning, resuming, or implementing work.
3. Read the relevant `tasks/**/plan.md`, `tasks/**/todo.md`, latest ADRs, and latest integration report.
4. Recalculate progress from acceptance checklists; do not reuse an old percentage from chat history.

## Parallel-work authority

- `tasks/parallel-work.md` is the canonical ownership, dependency, and resume protocol for phases F1-F9.
- Use at most five worker tasks at once while the current task remains coordinator/integrator.
- A worker may modify only its assigned directories and files. Shared contracts, routes, migrations, lockfiles, ADR numbering, and canonical checklists belong to the coordinator unless explicitly assigned.
- One task owns database migrations at a time. Freeze shared DTO/API contracts before backend, frontend, or psychometric work proceeds in parallel.
- Do not dispatch a duplicate continuation to a task that is still running. Review its commit and verification evidence before giving it another increment.
- Do not count a task as complete merely because a worker reports completion; acceptance criteria and relevant tests must pass.

## Safety and continuity

- Preserve existing uncommitted and untracked files unless their ownership is explicitly assigned.
- Never use production secrets, real participant data, live payment/notification actions, active database migrations, deployment, or feature activation as synthetic verification.
- At every handoff, record task ID, branch/worktree, owned files, baseline commit, result commit, tests, blockers, and next dependency in `tasks/parallel-work.md` or a linked integration report.
- After an interrupted or closed session, follow the resume checklist in `tasks/parallel-work.md`; do not restart planning from chat memory.

## Coordination role

- A separate Claude Code session acts as coordinator/gatekeeper across every
  tool used on this project (Codex, GLM, others). It does not receive live
  messages from other tools — a human relays prompts and results manually
  between sessions.
- No branch is promoted to canonical/`main`, committed as a merge, pushed, or
  force-pushed until the coordinator has independently re-verified the
  evidence (re-running or re-inspecting tests/diffs directly in the
  repository), not merely accepted a worker's self-report.
- Any tool finishing an increment must push its branch to `origin` so the
  coordinator can audit it directly via `git`, even without direct access to
  that tool's own session or chat history.
- If a tool's chat history disagrees with the coordinator's git-based audit,
  the audit wins, per the existing "recalculate progress" rule above.
- A worker (any tool) that finds ambiguity between two already-merged
  branches' contracts (schema, DTO, fixture expectations) must report the
  ambiguity for a joint decision rather than silently resolving it by
  loosening a constraint, deleting a check, or picking one side without
  recording why.

## Ownership ledger (updated 2026-09-18, baseline commit 621d859 on `main`)

`main` history since the last full rewrite of this section: PR #1 (`957fb78`,
full F1/F2/organization-payment/integration-psychotest reconciliation) →
PR #2 (`b18d5b4`, DeepSeek F3+F4 persistence + HTTP projection, live
PostgreSQL 17.6 RLS evidence) → PR #3 (ledger refresh) → PR #4
(`1748535`, DeepSeek F4b — psychologist-edited INTEGRATION text
persistence, `narrative_cluster_edits`) → PR #5 (`621d859`, Codex F7 —
operational dashboard, branch fee/commission ledger with RLS, idempotent
commission recording that never blocks payment/entitlement on failure,
withdrawal request lifecycle with resubmission-after-reject support, plus
a root-cause fix for a pre-existing PHPUnit cross-test external
data-provider bug that predated this branch).

**Cross-coordinator note, still relevant:** two coordinator sessions have
independently hand-reconciled this file after a `git merge` silently chose
one side's version at least twice (2026-09-17). Whoever edits this file:
push the edit (as its own small branch/PR) to `main` immediately — do not
let it sit on a feature branch. Read this file fresh from `origin/main`
before editing, never from memory of an earlier version in this
conversation.

Claude Code stays coordinator-only in this project (human decision,
2026-09-16) — it does not write application code here. All feature work
below is delegated to Codex, GLM, and DeepSeek. Every lane branches from
current `main`.

| Owner / lane | Scope | Exclusive paths | Forbidden |
|---|---|---|---|
| Codex — F7 remainder (`codex/f7-admin-branch-dashboards` or a fresh branch from `main`) | Dashboard, fee ledger, commission service, and withdrawal resources are ACCEPTED and merged (PR #5). **Remaining F7 scope only: proctoring persistence (`proctor_photos`/`proctor_logs`) and its timeline UI** (photos+logs per participant/attempt, signed-URL access, tied to the existing pure `ProctoringValidityPolicy` domain). | New tables/migration for proctoring persistence (propose schema for coordinator review before creating the migration, per the standing migration rule below), `app/Filament/**` additions, `app/Domain/Proctoring/**` (extend) | `app/Domain/Eligibility/**`, `app/Domain/Narrative/**`, `app/Domain/Report/**`, `app/Domain/Review/**` (other lanes' territory); `composer.json`/lockfiles unless coordinated |
| Codex — F9-O1 (`codex/f9-o1-observability-rehearsal`) | Disposable queue/outbox visibility + health-failure rehearsal only. Explicitly does NOT set SLOs, alert thresholds, telemetry backend, or destinations. **Status unknown to this coordinator as of 2026-09-18** — ACK was given (rebase onto `main` first) but no progress report or push received since. Check for a push before dispatching new work. | New only: `tools/testing/run-observability-rehearsal.ps1`, `tools/testing/tests/observability-rehearsal-contract.ps1`, a new collector under `tools/testing/observability/`, `tasks/handoffs/observability/f9-local-observability-rehearsal.md` | Everything outside those new paths; no live/production resources, `.env`, outbound calls, notification, or scheduler activation |
| GLM (`glm/f6-hpp-report-draft`) | **F6 draft ACCEPTED 2026-09-17** (commit `d829295`, independently re-verified twice: 60 tests/196 assertions, DASS separation confirmed by reading the rendered Blade output directly — HPP shows only `general_category`/`narrative`/`follow_up`, full subscale detail only in the internal report — 1-5 scale confirmed, a real legend/zone CSS collision found and fixed). Pushed to `origin`, **not merged to `main`** (by design — still fixture-only). Proposed next slice, not yet dispatched: real PDF rendering pipeline (Blade → PDF, private storage, short-lived signed URL) using the same fixture dataset — does not need to wait for F5. | New only: `app/Domain/Report/**`, `app/Services/ReportRendering/**`, `resources/views/reports/**`, `tests/Feature/Reports/**`, `tests/Unit/Report/**` | Any existing file outside those new paths, any migration, any existing test, `composer.json`/`package.json`/lockfiles, `AGENTS.md`, `tasks/parallel-work.md`, `tasks/f2-f9-acceptance.md` |
| DeepSeek (`deepseek/f3-f4-eligibility-narrative` or a fresh branch from `main`) | **F3, F4, and F4b all ACCEPTED and merged** (PR [#2](https://github.com/ihwanudin/Psikotes-App/pull/2) `b18d5b4`, PR [#4](https://github.com/ihwanudin/Psikotes-App/pull/4) `1748535`; live PostgreSQL 17.6 RLS/trigger/SECURITY DEFINER evidence for both; F4b's cross-case integrity is enforced at two layers, app-level check + DB `SECURITY DEFINER` trigger guard; PR #4 needed two post-open CI fixes — Pint on a file the coordinator missed, and a PHPStan `property.notFound` from typing a DB-row helper param as generic `object` instead of a proper shape — both root-caused and verified with the actual tools before pushing). **New scope, authorized 2026-09-18: F5 — review/signing persistence.** Pure domain already exists in `app/Domain/Review/**` (state machine, G6 override, signing prerequisites, G7 review set, `ReportSigningSnapshotComposer`) — read all of it first, this is not greenfield. Build: (1) versioned append-only signing-snapshot persistence binding exact version references of the eligibility decision, narrative version, and F4b psychologist-edited text — never re-derive from current state; (2) `level_sistem`/`level_final` stored side by side, never overwritten; (3) enforce no DRAFT→PUBLISHED shortcut with a negative test; (4) prove DASS never enters the signing snapshot/label (T-07); (5) RLS restricted to the authorized psychologist + super_admin only (not branch_admin/staff — "Lembar Kerja Internal & data DASS" rule), verified on live PostgreSQL; (6) HTTP projection only, no browser UI yet. Propose the migration schema for coordinator review before creating it. | Extend `app/Domain/Review/**` (newly granted — previously read-only for this lane), `app/Domain/Eligibility/**`, `app/Domain/Narrative/**`, new migration (after coordinator review), new HTTP controllers scoped to review/signing, new tests under `tests/**/Review/**` | `app/Domain/Report/**` (F6, GLM's exclusive territory), `app/Filament/**` (F7/Codex), any existing migration, `routes/*.php` registration without coordinator go-ahead, `composer.json`/`package.json`/lockfiles, `AGENTS.md`, `tasks/parallel-work.md`, `tasks/f2-f9-acceptance.md` |
| Claude (coordinator) | Cross-tool verification, canonical docs, merge/promotion gatekeeping | `AGENTS.md`, `tasks/parallel-work.md`, `tasks/f2-f9-acceptance.md`, cross-branch reconciliation | — |

Every lane branches from current `main`, works in its own branch, and does
not merge into `main` without the coordinator's independent verification —
push the branch to `origin` and wait for review, per the Coordination role
rules above.
