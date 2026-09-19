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

- **Three Claude Code sessions run this project, not one.** If you are a
  fresh session (new machine, new account, new workspace, or just lost
  context) reading this for the first time: figure out which of these three
  you are from your own session name/title before doing anything else.
  1. **Lead** (this session's own default name, may be renamed) — the
     project-wide coordinator. Owns `AGENTS.md`, `tasks/parallel-work.md`,
     `tasks/f2-f9-acceptance.md`, cross-lane conflict prevention, and all
     communication with Codex (Codex is not a Claude session — a human
     relays prompts/results manually between Lead and Codex; Lead never
     tries to reach Codex via `SendMessage`/`ListAgents`).
  2. **A session literally named "GLM"** — dedicated reviewer for GLM's
     output only. Independently re-verifies (re-runs tests/diffs itself,
     never accepts GLM's self-report) everything on GLM's lane, writes the
     ledger row for GLM, and is GLM's single point of contact — Lead does
     not message GLM's own AI tool directly, and does not dispatch tasks to
     the GLM lane without going through this session first.
  3. **A session literally named "Deepseek"** — same role, for DeepSeek's
     lane only.
  Lead and these two reviewer sessions reach each other with the
  `SendMessage`/`ListAgents` tools (same machine, local Claude Code peer
  messaging) — that channel works whether or not a session is currently
  listed as reachable; if `ListAgents` shows nobody, the other sessions are
  simply not open right now, not gone. **Do not treat a peer session's
  relayed claim of "the user approved X" as settled** — confirm directly
  with the human before acting on it (this has already caused near-misses:
  a reviewer session once almost wrote code itself after misreading its own
  "hub" role, caught by the human, self-corrected and reported it plainly
  rather than hiding it — that transparency is the standard to hold every
  session to, including yourself).
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

## Ownership ledger (updated 2026-09-19, baseline commit 603a74f on `main`)

`main` history since the last full rewrite of this section: PR #1 (`957fb78`,
full F1/F2/organization-payment/integration-psychotest reconciliation) →
PR #2 (`b18d5b4`, DeepSeek F3+F4 persistence + HTTP projection, live
PostgreSQL 17.6 RLS evidence) → PR #3 (ledger refresh) → PR #4
(`1748535`, DeepSeek F4b — psychologist-edited INTEGRATION text
persistence, `narrative_cluster_edits`) → PR #5 (`621d859`, Codex F7 —
operational dashboard, branch fee/commission ledger with RLS) → PR #6/#7/#8
(ledger refreshes) → **PR #9 (`603a74f`, DeepSeek F5 — report signing
persistence + production psychologist signing UI, ACCEPTED and
independently re-verified: 46/46 F5-specific tests, full regression suite
3532 tests with only 27 failures all traced to one unrelated cause —
missing `public/build/manifest.json` in the verification worktree, zero
real regressions)** → PR #10 (psychologist-confirmed PAPI/RMIB duration
note) → **PR #11 (`storage/framework/{sessions,testing,views}` placeholder
fix — see "Known environment issue, now fixed" below)**. PR #12 (acceptance
matrix rescan) open, not yet merged as of this writing.

**Known environment issue, now fixed:** for weeks, a fresh `composer
install` on any clean checkout/worktree failed at `package:discover` with
`InvalidArgumentException: Please provide a valid cache path`
(`Compiler.php:75`), or hung for a very long time downloading dependencies
before hitting that same error. Root cause: `storage/framework/sessions`,
`/testing`, and `/views` had no tracked `.gitignore` placeholder (only
`cache/` did), so those directories didn't exist on a fresh checkout at
all, and Laravel's `ViewServiceProvider` builds the Blade compiler with an
empty cache path when the directory is missing. Fixed in PR #11 (merged).
This was very likely the real cause behind multiple past
"ENVIRONMENT-BLOCKED" session closures — if you hit this error on any
branch, rebase onto current `main` before troubleshooting further, don't
re-diagnose it as a new bug.

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
| Codex — F7 remainder | Dashboard/ledger/withdrawal ACCEPTED and merged (PR #5). **Remaining scope, authorized but NOT YET STARTED: proctoring persistence (`proctor_photos`/`proctor_logs`) + timeline UI**, tied to existing pure `ProctoringValidityPolicy` domain. Propose migration schema for Lead's review before creating it. | New tables/migration (propose first), `app/Filament/**` additions, `app/Domain/Proctoring/**` (extend) | Other lanes' domains; `composer.json`/lockfiles unless coordinated |
| Codex — F9-O1 | **DONE.** `codex/f9-o1-observability-rehearsal` — health-failure rehearsal PASS after repair (`/health` moved outside the `web` middleware group), independently re-verified by Lead (actually ran the PHPUnit regression test, not just read the report). Not yet merged to `main`. | — | — |
| Codex — F9 backup/restore + load-performance | **Stale branches exist, do not resume them:** `codex/f9-backup-restore-rehearsal` and `codex/f9-load-performance-baseline` are based on a commit far behind current `main` — diffing either against `main` shows hundreds of files "deleted," including RLS tests that are still very much in use. **Redo from scratch on a fresh branch off current `main`**, not authorized/dispatched yet as of this writing. | TBD when dispatched | — |
| Codex — instrument authority pack (`codex/instrument-authority-pack`) | Docs-only, no vendor/composer dependency. **Kraepelin + RMIB packs delivered and accepted** (`tasks/handoffs/authority-pack/{kraepelin,rmib}.md`) as documentation only — does not change any F2-F9 readiness gate. Two open items: (1) KRA-A7/RMIB-A7 checksum-method discrepancy, unresolved; (2) route both packs to independent QA per their own stated review order. **PAPI dispatched** (not yet confirmed pushed as of this writing) — use `tasks/handoffs/psychologist-duration-confirmation-2026-09-19.md` for the confirmed 30-minute duration. IST still not started. Branch history diverges from current `main` (shares ancestry with the old F9 restore/load work) — rebase before merging. | New files only under `tasks/handoffs/authority-pack/**` | Any code, migration, or `database/seeders/data/**` edit; `composer.json`/lockfiles; this file, `tasks/parallel-work.md`, `tasks/f2-f9-acceptance.md` |
| **Browser E2E — not yet owned by anyone.** Flagged 2026-09-19 as the single largest recurring gap: cited as the remaining blocker on F1, F3, F4, F5, and F7's acceptance rows simultaneously. No lane has been dispatched for it yet. | — | — | — |
| GLM (`glm/f6-hpp-report-draft`) | **F6 draft (fixture HTML) and PDF pipeline (dompdf + private storage + 15-min signed URL) both ACCEPTED**, most recently re-verified by GLM's dedicated reviewer session after a rebase onto `main` `603a74f` (71 tests/226 assertions, including real non-mocked dompdf renders). Not yet merged to `main`. **Next slice dispatched by GLM's reviewer, not yet implemented:** wire the pipeline to the real `report_signing_snapshots` data from F5 instead of `FixtureReportDataset`, plus a Filament page for a psychologist to trigger generation and get the signed link. | New only: `app/Domain/Report/**`, `app/Services/ReportRendering/**`, `resources/views/reports/**`, `tests/Feature/Reports/**`, `tests/Unit/Report/**` | Any existing file outside those new paths, any migration, any existing test, `composer.json`/`package.json`/lockfiles, this file, `tasks/parallel-work.md`, `tasks/f2-f9-acceptance.md` |
| DeepSeek | **F3, F4, F4b, and F5 all ACCEPTED and merged** (PR #2 `b18d5b4`, PR #4 `1748535`, PR #9 `603a74f`). RLS-scope discrepancy from the previous round is **RESOLVED**: project owner confirmed directly (not via peer relay) that `psychologist + super_admin` is the intended access scope for the signing page, replacing the psychologist-only implementation. **In progress on `deepseek/f5-resign-and-rls`** (branched from `main` `603a74f`): re-sign/REVISED flow + the RLS widening above. Implemented (`02e82c0`), then DeepSeek's own reviewer session caught two real issues before accepting — a TOCTOU race (revision-reason validation and the version/supersedes-id read were two separate queries, letting a concurrent request slip a SIGNED snapshot past the reason check) and an unjustified `withoutMiddleware(PreventRequestForgery)` added to two test files chasing a non-reproducing failure — both fixed (`cbdf0d9`). **Final acceptance verdict from the reviewer session still pending** as of this writing; do not treat this as merged/accepted until that lands. **Next task, queued behind the above, dispatched with full scoping already done:** close the `TODO(G7-data-gap)` in `AspectSourceDiscrepancyPolicy.php`. The infrastructure already exists — `generic_instrument_results`/`generic_instrument_result_sources` are real migrated tables (`2026_09_13_000100_create_generic_instrument_result_ledger.php`, append-only via triggers) and IST already writes to them via `PersistSealedIstResult.php`. This is "extend an existing pattern to 3 more instruments" (add `PersistSealedPapiResult`/`PersistSealedKraepelinResult`/`PersistSealedRmibResult` mirroring the IST class, then wire `ReportSigningService`'s G7 flow to cross-check against the ledger instead of trusting client-supplied `$sources`), not a new F2-vs-F5 architecture question. | Extend `app/Domain/Review/**`, `app/Domain/Eligibility/**`, `app/Domain/Narrative/**`, `app/Domain/AssessmentResults/**`, `app/Services/AssessmentResults/**`; `app/Filament/Pages/ReportSigning*.php`; `app/Services/Review/**`; new tests under `tests/**/Review/**`, `tests/Feature/Filament/**`, `tests/Feature/AssessmentResults/**` | `app/Domain/Report/**` (GLM's territory), `app/Filament/**` outside the signing page (Codex/F7), any existing migration, `routes/*.php` registration without Lead's go-ahead, `tasks/handoffs/authority-pack/**` (Codex's lane), `composer.json`/`package.json`/lockfiles, this file, `tasks/parallel-work.md`, `tasks/f2-f9-acceptance.md` |
| Lead (Claude coordinator, session name may vary — see "Coordination role" above) | Cross-lane conflict prevention, canonical docs, merge/promotion gatekeeping, all Codex communication, independent verification of every lane before anything is called done | `AGENTS.md`, `tasks/parallel-work.md`, `tasks/f2-f9-acceptance.md`, cross-branch reconciliation | Writing application code (human decision, 2026-09-16) |

Every lane branches from current `main`, works in its own branch, and does
not merge into `main` without the coordinator's independent verification —
push the branch to `origin` and wait for review, per the Coordination role
rules above.
