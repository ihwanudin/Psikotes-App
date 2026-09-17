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

## Ownership ledger (updated 2026-09-17, baseline commit 957fb78 on `main`)

`main` now contains the full reconciliation: `codex/organization-payment-spec`
onto `codex/f2-wave1-integration`, then `codex/integration-psychotest-current`
(selection callback/keyring hardening, DASS fixture centralization,
direct-public/manual-payment fixture harmonization, and a root-cause fix for
the SQLite test-lifecycle isolation issue — see
`tasks/handoffs/sqlite-test-isolation-known-issue-2026-09-16.md`, now
RESOLVED), merged via PR #1 (merge commit `957fb78`) after CI was repaired
(environment bootstrap, ESLint/Prettier/Pint formatting, PHPStan level 7,
and one real config bug: an unset callback key ID read as `''` instead of
`null`). All 13 Grup C branches and all Grup B `fix/*` branches are
confirmed patch-equivalent or superseded; no further action needed on them.

**Cross-coordinator reconciliation note (2026-09-17):** this ledger was
rewritten by a coordinator session handling Codex F7/F9-O1 without
visibility into the unmerged `deepseek/f3-f4-eligibility-narrative` branch's
progress (never pushed to `main`, so not reachable from that session's
view). When merging PR #2 into the retargeted `main`, `git merge` silently
picked this file's `main` side wholesale with no conflict reported —
verified via `git merge-tree`, then manually re-inspected rather than
trusted. The DeepSeek row below has been hand-reconciled to carry forward
its accepted status and F4b scope; the rest of this file is unchanged from
the F7/F9-O1 session's version. Flagging per the ambiguity-reporting rule
above: two coordinator sessions editing this file independently risks
exactly this kind of silent data loss on merge — canonical-doc edits should
ideally be pushed to `main` (or otherwise made visible) as soon as they're
made, not held on a feature branch until a PR merges.

Claude Code stays coordinator-only in this project (human decision,
2026-09-16) — it does not write application code here. All feature work
below is delegated to Codex, GLM, and DeepSeek. **Every lane now branches
from `main`, not from the old reconciliation branch.**

| Owner / lane | Scope | Exclusive paths | Forbidden |
|---|---|---|---|
| Codex — F7 (`codex/f7-admin-branch-dashboards`) | Admin/branch dashboards, commission/fee-cabang ledger with monthly payout cycle (SPEC.md §4.4/§9), proctoring timeline view (photos+logs). Audit existing Filament resources first (`OrganizationBillResource`, `AssessmentParticipantResource`, `IntegrationClientResource`, `PaymentMethodResource`, `TestPackageResource`) before adding new ones. | New Filament resources/pages, `app/Filament/**`, `app/Domain/Commission/**` (or established equivalent) | `app/Domain/Eligibility/**`, `app/Domain/Narrative/**`, `app/Domain/Report/**` (other lanes' territory); `composer.json`/lockfiles unless coordinated |
| Codex — F9-O1 (`codex/f9-o1-observability-rehearsal`) | Disposable queue/outbox visibility + health-failure rehearsal only. Explicitly does NOT set SLOs, alert thresholds, telemetry backend, or destinations — those need a separate decision packet with human/architecture authority. | New only: `tools/testing/run-observability-rehearsal.ps1`, `tools/testing/tests/observability-rehearsal-contract.ps1`, a new collector under `tools/testing/observability/`, `tasks/handoffs/observability/f9-local-observability-rehearsal.md` | Everything outside those new paths; no live/production resources, `.env`, outbound calls, notification, or scheduler activation |
| GLM (`glm/f6-hpp-report-draft`) | F6 — Result documents (participant HPP report + internal report rendering). Status: unstarted, depends on a stable F5 review/signature contract that is **not yet final** — build against **fixture/mock data only**. | New only: `app/Domain/Report/**`, `app/Services/ReportRendering/**`, `resources/views/reports/**`, `tests/Feature/Reports/**`, `tests/Unit/Report/**` | Any existing file outside those new paths, any migration, any existing test, `composer.json`/`package.json`/lockfiles, `AGENTS.md`, `tasks/parallel-work.md`, `tasks/f2-f9-acceptance.md` |
| DeepSeek (`deepseek/f3-f4-eligibility-narrative`) | **F3+F4 original scope (persistence, HTTP/API projection) — ACCEPTED 2026-09-17**, independently re-verified including live PostgreSQL 17.6 RLS/trigger/SECURITY DEFINER runtime evidence (`tests/Postgres/EligibilityDecisionRlsTest.php`, `BilingualNarrativeRlsTest.php`). PR [#2](https://github.com/ihwanudin/Psikotes-App/pull/2), being retargeted from the now-merged `codex/reconcile-org-f2-2026-09-15` to `main`; see `tasks/parallel-work.md` for full evidence. **New scope (F4b, in progress) — INTEGRATION-text persistence gap.** No table persists a psychologist-edited version of narrative text distinct from the system-generated baseline in `bilingual_narrative_versions`, per `CLAUDE.md`'s "Teks INTEGRATION tersunting psikolog TIDAK boleh tertimpa saat regenerate" rule. Still Narrative-domain persistence, not report rendering — `app/Domain/Report/**` stays GLM's exclusive F6 territory. Design (`narrative_cluster_edits` schema, cross-case FK trigger guard) reviewed and approved; implementation (migration + controller + tests) authorized and in progress. | Extend only: `app/Domain/Eligibility/**`, `app/Domain/Narrative/**`, plus NEW files under `app/Http/Controllers/` scoped to these two domains, NEW test files under `tests/**/Eligibility/**` and `tests/**/Narrative/**` | Any existing migration, `routes/*.php`, `app/Domain/Review/**` (F5, Codex/coordinator territory, read-only for context), `app/Domain/Report/**` (F6, GLM's exclusive territory), any existing test outside the two domains, `composer.json`/`package.json`/lockfiles, `AGENTS.md`, `tasks/parallel-work.md`, `tasks/f2-f9-acceptance.md`. **New migrations and any route registration require the coordinator's explicit go-ahead before writing them.** |
| Claude (coordinator) | Cross-tool verification, canonical docs, merge/promotion gatekeeping | `AGENTS.md`, `tasks/parallel-work.md`, `tasks/f2-f9-acceptance.md`, cross-branch reconciliation | — |

Every lane branches from current `main`, works in its own branch, and does
not merge into `main` without the coordinator's independent verification —
push the branch to `origin` and wait for review, per the Coordination role
rules above. Two Codex lanes run concurrently (F7 and F9-O1); they do not
share files, so no ownership conflict is expected, but both must still push
for review before merging.
