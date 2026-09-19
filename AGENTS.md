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
- **Two coordinator sessions currently split by worker, 2026-09-19 (human
  decision):** the "Lead" session is the global hub (Codex, DeepSeek, and
  everything else); a second, GLM-dedicated coordinator session owns all
  communication with GLM specifically — GLM-bound instructions/verification
  route through that session only, not through Lead. Either session's audit
  of `origin` is authoritative regardless of which Claude account is
  currently signed in on that device (see "Continuity across accounts"
  below) — a session is a role tied to this file and to `origin`, not to a
  login.

## Continuity across accounts

Claude Code chat history does **not** transfer between different Anthropic
accounts (only same-account re-login does). If the human switches to a
different account/device for a coordinator role: nothing here breaks, since
git (this file + every branch on `origin`) is the actual persistent state,
not any chat transcript. To resume a coordinator role from a new account:
open Claude Code in this same repository/worktree path, and as the very
first instruction tell it to read this entire file before doing anything
else — that already matches the standing "Required reading before work"
rule above. Re-state which lane it's taking over (global hub vs the
GLM-dedicated channel, per the split noted above) since that split lives
only in chat convention today, not in a machine-checkable place.

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

**2026-09-19 addendum:** when a feature branch has drifted far behind `main`
(squash-merged predecessor work no longer sharing history), prefer creating
a fresh branch off current `main` and cherry-picking the feature-specific
commits over `git merge`ing `main` into the old branch — a merge attempt
here produced conflicts in files that hadn't actually diverged in content
(`add/add` conflicts caused by the squash-merge history break, not real
disagreement), across 100+ unrelated files pulled in from independent lanes.
Cherry-picking the 5 F5-specific commits onto `deepseek/f5-report-signing`
(from `main` `bdc779e`) applied with zero conflicts. Also: two informal
PAPI/RMIB duration-estimate notes (destined for
`tasks/handoffs/authority-pack/{papi,rmib}.md`) were deliberately dropped
from the F5 branch before pushing — Codex's `codex/instrument-authority-pack`
lane already has a real, formally-audited `rmib.md` sitting unmerged, and a
placeholder from this lane would collide with it. Add those two duration
notes as their own small PR *after* Codex's instrument-authority-pack
branch merges to `main`, not before.

Claude Code stays coordinator-only in this project (human decision,
2026-09-16) — it does not write application code here. All feature work
below is delegated to Codex, GLM, and DeepSeek. Every lane branches from
current `main`.

| Owner / lane | Scope | Exclusive paths | Forbidden |
|---|---|---|---|
| Codex — F7 remainder (`codex/f7-admin-branch-dashboards` or a fresh branch from `main`) | Dashboard, fee ledger, commission service, and withdrawal resources are ACCEPTED and merged (PR #5). **Remaining F7 scope only: proctoring persistence (`proctor_photos`/`proctor_logs`) and its timeline UI** (photos+logs per participant/attempt, signed-URL access, tied to the existing pure `ProctoringValidityPolicy` domain). | New tables/migration for proctoring persistence (propose schema for coordinator review before creating the migration, per the standing migration rule below), `app/Filament/**` additions, `app/Domain/Proctoring/**` (extend) | `app/Domain/Eligibility/**`, `app/Domain/Narrative/**`, `app/Domain/Report/**`, `app/Domain/Review/**` (other lanes' territory); `composer.json`/lockfiles unless coordinated |
| Codex — F9-O1 (`codex/f9-o1-observability-rehearsal`, pushed to `origin`, based on `main` `9209db8`) | Disposable queue/outbox visibility + health-failure rehearsal only. Explicitly does NOT set SLOs, alert thresholds, telemetry backend, or destinations. **Pushed and coordinator-verified 2026-09-18** (`6d222cc`, `bb4be3a`): harness/contract PASS (54 assertions), but the runtime rehearsal correctly reports **REPAIR-REQUIRED** — after stopping PostgreSQL, `/health` returns a generic HTML 500 instead of the required exact `503 {"status":"degraded"}`. Root cause confirmed by the coordinator: `/health` is registered in `routes/web.php` inside the default `web` middleware group, so `StartSession` (session driver defaults to `database`) throws before the request ever reaches `HealthCheckController`; the existing `tests/Feature/HealthCheckTest.php` doesn't catch this because it mocks `DB` directly and calls `getJson()`, which forces a JSON accept header that masks the same failure a real health-check caller (no `Accept: application/json`) would hit. Codex correctly declined to fix this itself (out of lane scope) and did not fabricate a partial pass. **Newly authorized 2026-09-18, narrow scope only:** exclude `/health` from the session/CSRF-bearing `web` middleware group in `routes/web.php` (one route registration), add a regression test in `tests/Feature/HealthCheckTest.php` that exercises the production-like session driver and a request without an `Accept: application/json` header, then rerun the existing rehearsal harness from a clean commit to confirm PostgreSQL-down, Redis-down, and the queue/outbox snapshot all pass end to end. | New files listed above, plus (newly granted, this fix only) `routes/web.php` (the `/health` line only) and `tests/Feature/HealthCheckTest.php` | Everything else outside those paths; no live/production resources, `.env`, outbound calls, notification, or scheduler activation; no other route/middleware change beyond the `/health` exclusion |
| GLM (`glm/f6-hpp-report-draft`) | **F6 draft + PDF pipeline both ACCEPTED** (draft: commit `d829295`/re-verified 2026-09-17, 60 tests/196 assertions, DASS separation and 1-5 scale confirmed by reading rendered output directly; PDF pipeline: commit `e755daf`/re-verified 2026-09-19, 71 tests/226 assertions, two real-`dompdf` integration renders — not mocked — plus an explicit test that the private-storage object key leaks none of participant name/test number/report number). Rebased onto current `main` (`603a74f`) and pushed 2026-09-19, `5474e8d` — still fixture-only (`FixtureReportDataset`), **not merged to `main`**. Coordinator-added prerequisites, outside GLM's paths: `barryvdh/laravel-dompdf` dependency and a private `reports` filesystem disk (commit `051c398`, mirrors the existing `identity`/`payment-proofs` disk pattern). **Newly dispatched 2026-09-19, two-part next slice:** (1) replace `FixtureReportDataset` with a real adapter reading `report_signing_snapshots` (see DeepSeek's row above for the schema/service — `ReportSigningService::latest()`, `snapshot_json` = `{prerequisite_input, provenance}`, RLS-gated, read via `RlsContextRunner`) joined with `assessment_cases`/`participants` for identity and the isolated DASS schema (`database/migrations/2026_08_25_000300_create_isolated_dass_schema.php`) for the screening category only — note the signing snapshot itself carries **no DASS and no participant identity**, GLM must locate those independently and report back if the join path is ambiguous rather than guessing; (2) a new Filament page for a psychologist to trigger PDF generation and receive the signed link, calling GLM's own `PdfReportRenderer`/`ReportDocumentPublisher` in-process (same pattern as `ReportSigningService` being called in-process from `ReportSigning.php`, not a self-issued HTTP request). | New only: `app/Domain/Report/**`, `app/Services/ReportRendering/**`, `resources/views/reports/**`, `tests/Feature/Reports/**`, `tests/Unit/Report/**`, plus (newly granted, this slice only) **one new file** `app/Filament/Pages/ReportGeneration.php` (or an equivalently-named new page — do not touch `ReportSigning.php`, `PsychologistReviewFixture.php`, or any existing Filament page/resource) and its view under `resources/views/filament/pages/**` | Any existing file outside those new paths, any migration, any existing test, `composer.json`/`package.json`/lockfiles, `config/**`, `routes/**`, `app/Http/Controllers/**`, any Filament file other than the one new page named above, `AGENTS.md`, `tasks/parallel-work.md`, `tasks/f2-f9-acceptance.md` |
| DeepSeek (`deepseek/f5-report-signing`, branched fresh from `main` `bdc779e` to avoid the stale-history conflicts a merge produced — see cross-coordinator note below) | **F3, F4, and F4b all ACCEPTED and merged** (PR [#2](https://github.com/ihwanudin/Psikotes-App/pull/2) `b18d5b4`, PR [#4](https://github.com/ihwanudin/Psikotes-App/pull/4) `1748535`). **F5 — review/signing persistence + production signing UI — ACCEPTED 2026-09-19, PR pending.** Backend: `report_signing_snapshots` migration, `ReportSigningController` (thin HTTP wrapper) + `ReportSigningService` (the actual 9-step derive-from-stored-data chain — extracted into a service specifically so the Filament UI could call it in-process instead of self-issuing HTTP requests), consuming the existing pure `app/Domain/Review/**` objects. Independently re-verified by the coordinator, not just self-reported: validity/label/final_level are derived server-side from `eligibility_decision_versions.canonical_input_json`, never trusted from client input (this was a real bug in an earlier draft, caught and fixed before acceptance); `level_sistem`/`level_final` stored side by side; DASS never enters the snapshot (T-07); PHPStan level 7 clean, Pint clean, 46 tests, full regression suite run twice with zero new failures (only the same ~28 pre-existing/unrelated failures both times — missing Vite build manifest, two known-flaky concurrency tests). UI: `app/Filament/Pages/ReportSigning.php` (new production page, distinct from Codex's fixture-only `PsychologistReviewFixture.php`) — this exceeds the originally authorized "HTTP projection only, no browser UI yet" scope; the coordinator and project owner explicitly decided to expand scope mid-flight rather than open the PR HTTP-only. **Authorization discrepancy to verify, not yet resolved:** this row previously specified RLS restricted to "psychologist + super_admin only, not branch_admin/staff." The actual implementation gates on `AdminAbility::ReviewReports`, which `Admin::canPerform()` maps to **psychologist only** (super_admin is not included) — this matches CLAUDE.md's literal text ("Lembar Kerja Internal & data DASS: akses HANYA psikolog+peserta") more closely than this row's original wording did, but the discrepancy itself was never explicitly reconciled by anyone — flagging so it isn't silently assumed either way. **Known backlog, documented not fixed:** `TODO(G7-data-gap)` in `AspectSourceDiscrepancyPolicy.php` — per-source instrument levels for G7 are still client-supplied (structurally validated only); a real aggregator needs `generic_instrument_result_sources` data for PAPI/Kraepelin/RMIB, which isn't persisted yet (only IST is) — investigated, this is bigger than F5 and likely F2 scoring-pipeline scope, not dispatched. First-signing-only in this increment (no re-sign/REVISED flow yet — read-only once a SIGNED snapshot exists). | Extend `app/Domain/Review/**`, `app/Domain/Eligibility/**`, `app/Domain/Narrative/**`; `app/Filament/Pages/ReportSigning*.php` (new only, not `PsychologistReviewFixture.php`); `app/Services/Review/**`; new tests under `tests/**/Review/**`, `tests/Feature/Filament/**` | `app/Domain/Report/**` (F6, GLM's exclusive territory), `app/Filament/**` outside the new signing page (F7/Codex), any existing migration, `routes/*.php` registration without coordinator go-ahead, `tasks/handoffs/authority-pack/**` (Codex's exclusive lane — do not add/edit instrument authority-pack files from this lane, even informally), `composer.json`/`package.json`/lockfiles, `AGENTS.md`, `tasks/parallel-work.md`, `tasks/f2-f9-acceptance.md` |
| Codex — instrument authority pack (`codex/instrument-authority-pack`, pushed to `origin`) | **Docs-only, no vendor/composer dependency.** Closing the provenance-and-rights decision pack for all four scored instruments (IST, Kraepelin, PAPI, RMIB): links each instrument's seeded data (`database/seeders/data/*.json`) back to its source material, records a checksum, and surfaces the human decisions LSI/psychologist must sign off on (licensing/rights, named-psychologist approval, Kraepelin seeded-vs-fixed numbers). **Kraepelin and RMIB packs delivered** (`tasks/handoffs/authority-pack/kraepelin.md`, `rmib.md`) and **Tech-Lead-reviewed and accepted by the coordinator 2026-09-18** as documentation only — accepting them does not change any F2-F9 readiness gate above. Per the packs' own stated review order, route them to **independent QA next**. Two open items from that review, still unresolved: (1) a checksum mismatch between this pack's recomputed hash and an earlier audit's hash for the same instrument-data blob (KRA-A7/RMIB-A7) — a procedural question (which hashing method is authoritative), not a policy one, worth investigating before either checksum is trusted as provenance; (2) the licensing/rights, sign-off, and seeded-vs-fixed decisions the packs surface are business/psychometric calls for LSI, not for any tool — no coding follow-up is blocked on them. **PAPI pack next, then IST** — neither exists on this branch yet (confirmed via `git ls-tree`). Note for whoever resumes this lane: the branch's own history diverges from current `main` (it shares ancestry with the F9 restore/load-rehearsal work, based near the old `a170bbd` baseline rather than `621d859`) — rebase before this is ever merged, though pure new-file docs make conflicts unlikely. | New files only under `tasks/handoffs/authority-pack/**` | Any code, migration, or `database/seeders/data/**` edit (instrument norma/kunci data changes only via `tools/extract/` + review per `CLAUDE.md`); `composer.json`/lockfiles; `AGENTS.md`, `tasks/parallel-work.md`, `tasks/f2-f9-acceptance.md` |
| Claude (coordinator) | Cross-tool verification, canonical docs, merge/promotion gatekeeping | `AGENTS.md`, `tasks/parallel-work.md`, `tasks/f2-f9-acceptance.md`, cross-branch reconciliation | — |

Every lane branches from current `main`, works in its own branch, and does
not merge into `main` without the coordinator's independent verification —
push the branch to `origin` and wait for review, per the Coordination role
rules above.
