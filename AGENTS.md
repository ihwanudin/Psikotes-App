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

## Ownership ledger (as of 2026-09-16, baseline commit 463783c)

This checkpoint reconciles `codex/organization-payment-spec` onto
`codex/f2-wave1-integration`. Full PHP suite: 3,379 tests, 3,370 passing, 0
errors, 0 failures, 9 known skips. Disposable PostgreSQL: 554 tests, 5,863
assertions, green. No `app/` production code changed by the reconciliation
itself. Known open item: `tasks/handoffs/sqlite-test-isolation-known-issue-2026-09-16.md`
(SQLite-only test-order guard, not a production issue).

| Owner | Scope | Exclusive paths | Forbidden |
|---|---|---|---|
| Codex | Remaining Grup C reconciliation: `f2-r0/r2/r2c/s3/s4`, `f5-fixture-*`, `f9-*`, `test-harness-reliability(-qa)`, `security-binary-content-gate`, `test-fixture-ledger-compat`, `release-gate-verification` | Existing `app/`, `database/migrations/`, `tests/` outside GLM's paths below | GLM's exclusive paths below; `composer.json`/`composer.lock`/`package.json`/lockfiles unless explicitly coordinated with the coordinator |
| GLM | F6 — Result documents (participant HPP report + internal report rendering). Status per `tasks/parallel-work.md`: unstarted ("Open"), depends on a stable F5 review/signature contract that is **not yet final** — build against **fixture/mock data only**, do not wire to live F5 output yet. | New only: `app/Domain/Report/**`, `app/Services/ReportRendering/**`, `resources/views/reports/**`, `tests/Feature/Reports/**`, `tests/Unit/Report/**` | Any existing file outside those new paths, any migration, any existing test, `composer.json`/`package.json`/lockfiles, `AGENTS.md`, `tasks/parallel-work.md`, `tasks/f2-f9-acceptance.md` |
| Claude (coordinator) | Cross-tool verification, canonical docs, merge/promotion gatekeeping | `AGENTS.md`, `tasks/parallel-work.md`, `tasks/f2-f9-acceptance.md`, cross-branch reconciliation | — |

GLM works on its own branch (`glm/f6-hpp-report-draft`, branched from this
checkpoint) and does not merge into the reconciliation branch or `main`
without the coordinator's verification, per the rules above.
