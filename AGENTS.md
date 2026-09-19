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
  3. **A session literally named "Deepseek"** (may appear as "DS") — same
     role, for DeepSeek's lane only.
  4. **A session literally named "Codex"** (added 2026-09-20) — same role
     for the Codex lane, so Codex traffic stays out of Lead's general
     work. Codex is NOT a Claude session: that channel session relays
     prompts/results through the human and never via `SendMessage`. Its
     starting context pack is
     `tasks/handoffs/channels/codex-channel-context-2026-09-20.md`.
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

## Ownership ledger (updated 2026-09-19, baseline commit 650eea5 on `main`)

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
fix — see "Known environment issue, now fixed" below)** → PR #12 (acceptance
matrix rescan) → PR #13 (`650eea5`, full `AGENTS.md` handoff refresh for
account/workspace continuity).

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

**Known clean regression baseline (Lead, 2026-09-20, `main` `650eea5`):**
with frontend assets built, the full suite has **0 real failures** — the
old "~27-28 known failures" were 26 `ViteManifestNotFoundException` (assets
never built in the verification worktree) plus 2 `sandbox`-group tests that
only ran because `--exclude-group=none` overrode `phpunit.xml`'s exclusion
(`PersistSealedIstResultTest` two_runtime_processes needs the multi-process
Postgres harness; `LoadPersistedIstResultTest` postgres_runtime_role needs
Postgres). Both are deterministic, not flaky. Reproduce: fresh worktree from
`origin/main` → `.env` from `.env.example` with `APP_ENV=testing` (without
it, `package:discover` fails on the production config check) → `php
<composer.phar> install` → `php artisan key:generate` → `npm ci` → `npm run
build` (needs `vendor/` first — Wayfinder) → `APP_ENV=testing php -d
memory_limit=2048M vendor/bin/phpunit --testsuite=Unit,Feature,Integration`
(**no** `--exclude-group=none`; ~38 min). Expected: 0 failures, 0 errors,
~10 skips. Any Vite-manifest error means a broken environment, not a code
regression. Every lane acceptance run is compared against this.

**Open cross-lane blockers found 2026-09-20 (Lead-verified in code, awaiting
human decisions — do not fix unilaterally):**
1. *IST scoring vs PostgreSQL `jsonb`:* `InstrumentSeeder.php:40-43` hashes
   the raw seed-file text; `instrument_versions.payload` is `jsonb`
   (`2026_08_23_000000` line 19), which normalizes whitespace/key order;
   `ScoreSealedIstAnswerSet.php:129` re-hashes the DB-returned payload. On
   PostgreSQL the checksum can essentially never match, so IST scoring of
   seeded instruments fails `SEALED_IST_RESULT_INVALID`; SQLite tests pass
   only because SQLite keeps text verbatim. Blocks IST in production, the G7
   Sealed*Result design, and F6 labels/DASS text read from
   `instrument_versions`. Fix options (human decision "F"): canonical-JSON
   checksum / separate raw-text column / `json` instead of `jsonb`.
2. *F3/F4 endpoints lack authorization:* `EligibilityDecisionController`,
   `BilingualNarrativeController`, `NarrativeClusterEditController`,
   `ReviewInputController` have no `canPerform` check, use plain `Request`
   (no Form Request), and query via `runAsService` (RLS bypassed); routes
   (`routes/web.php` ~131-199) only require an admin panel login. Any admin
   incl. branch_admin/staff can create eligibility-decision versions or
   overwrite INTEGRATION text for any case, cross-branch. Fix queued for the
   DeepSeek lane on its own branch `fix/f3-f4-review-authz`, priority before
   anything else in that lane (pending direct user confirmation).
3. *Lesson for every new Filament page:* a `mount()` parameter must be in the
   route (`{param}`), and acceptance needs a feature test over real HTTP
   (`actingAs()->get()`), not only `Livewire::test()` — the F5 signing page
   was marked ACCEPTED while returning 500 for every logged-in role.

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
| Codex — F7 remainder | Dashboard/ledger/withdrawal ACCEPTED and merged (PR #5). **Remaining scope, authorized but NOT YET STARTED: proctoring persistence (`proctor_photos`/`proctor_logs`) + timeline UI**, tied to existing pure `ProctoringValidityPolicy` domain. Propose migration schema for Lead's review before creating it. **Dispatched 2026-09-20 as PROPOSAL ONLY** on `codex/f7-proctoring-proposal`: one document `tasks/handoffs/f7/proctoring-persistence-proposal.md` (schema + RLS per role, private photo storage/signed URL/retention, event-ingest endpoint mapped to T-25..T-28, timeline UI with real-HTTP test plan, link into `ProctoringValidityPolicy` — validity V1/V2/V3 stays the psychologist's). Detection, not prevention. No migration/code until Lead review. | New tables/migration (propose first), `app/Filament/**` additions, `app/Domain/Proctoring/**` (extend) | Other lanes' domains; `composer.json`/lockfiles unless coordinated |
| Codex — F9-O1 | **DONE.** `codex/f9-o1-observability-rehearsal` — health-failure rehearsal PASS after repair (`/health` moved outside the `web` middleware group), independently re-verified by Lead (actually ran the PHPUnit regression test, not just read the report). **2026-09-20: rebuilt by Lead as `lead/f9-o1-rebased` from `main` `650eea5` (5 commits cherry-picked cleanly; Codex's merge commit `31d255a` dropped — its only diff was `AGENTS.md`) → PR #16.** 6 files, all within the 2026-09-18 authorization (`/health` line in `routes/web.php` + `HealthCheckTest.php` + new harness/evidence files). Do not touch the old `codex/` branch. | — | — |
| Codex — F9 backup/restore + load-performance | **Stale branches exist, do not resume them:** `codex/f9-backup-restore-rehearsal` and `codex/f9-load-performance-baseline` are based on a commit far behind current `main` — diffing either against `main` shows hundreds of files "deleted," including RLS tests that are still very much in use. **Redo from scratch on a fresh branch off current `main`**, not authorized/dispatched yet as of this writing. | TBD when dispatched | — |
| Codex — instrument authority pack (`codex/authority-pack-v2`, replacing `codex/instrument-authority-pack`) | Docs-only, no vendor/composer dependency. **Correction 2026-09-20 (found by Codex, verified by Lead):** `papi.md` and `ist.md` have been on `main` since `7dbea06` (2026-09-14), both BLOCKED 0/4; `kraepelin.md`/`rmib.md` exist only on the old branch (`2110519`), which is ~100 commits behind — earlier ledger text saying "PAPI dispatched / IST not started" was stale. **Dispatched 2026-09-20 as reconciliation, not new authoring:** fresh branch from `main`, cherry-pick `2110519`; update existing `papi.md` duration (30 min) and `rmib.md` (15 min) from `tasks/handoffs/psychologist-duration-confirmation-2026-09-19.md`, recomputing readiness honestly (other rows stay BLOCKED without new evidence); refresh `ist.md` facts against current `main` (ME learn/recall split and "Verbal (3)" stay BLOCKED — psychologist decision); bump all four packs' candidate baseline with a history note. Also investigate KRA-A7/RMIB-A7: exact hash method each audit used (raw bytes / LF vs CRLF / canonical JSON) — this is direct input to the `jsonb` checksum decision above; report only. | New files only under `tasks/handoffs/authority-pack/**` | Any code, migration, or `database/seeders/data/**` edit; `composer.json`/lockfiles; this file, `tasks/parallel-work.md`, `tasks/f2-f9-acceptance.md` |
| Browser E2E (Lead helper, user decision 2026-09-19: do pending parallel work here unless already far along in Codex) | **E2E-1 DONE (investigation only), PR #15** (`lead/e2e-1-inventory`, `a8c3279`): `tasks/handoffs/e2e/e2e-1-inventory.md` maps every open Browser-E2E acceptance row to "testable now" vs "blocked + cause". Headline finding, re-verified by Lead in code: `ReportSigning.php` route has no `{case}` while `mount()` requires it → HTTP 500 for every logged-in role; F5 row reopened in `tasks/f2-f9-acceptance.md`; fix requested from the DeepSeek lane before its re-sign PR merges. **E2E-2 NOT started — waiting on two human decisions:** (1) harness = pinned `@playwright/test` devDependency (touches `package.json`/lockfile, Lead-owned) vs versioned `npx @playwright/cli`; (2) who writes the E2E test code — a Lead helper would need the "Claude Code stays coordinator-only" rule below explicitly lifted for this lane. Proposed E2E-2 scope: T-23 admin access matrix + ReportSigning reachability at 320/390/768/1280 on disposable SQLite. | New files only: `tasks/handoffs/e2e/**`, `tasks/evidence/e2e/**` (E2E-2 test paths TBD with the decisions above) | Application code, migrations, existing tests, `package.json`/`composer.json`/lockfiles until decision (1) |
| GLM (`glm/f6-hpp-report-draft`) | **F6 draft (fixture HTML) and PDF pipeline (dompdf + private storage + 15-min signed URL) both ACCEPTED**, most recently re-verified by GLM's dedicated reviewer session after a rebase onto `main` `603a74f` (71 tests/226 assertions, including real non-mocked dompdf renders). Not yet merged to `main`. **Next slice dispatched by GLM's reviewer, not yet implemented:** wire the pipeline to the real `report_signing_snapshots` data from F5 instead of `FixtureReportDataset`, plus a Filament page for a psychologist to trigger generation and get the signed link. **Merge hazard (found by Lead 2026-09-19):** commit `2b8a6b1` on this branch edits `AGENTS.md` with an older ledger snapshot (baseline `621d859`), and `33a8542` is also a docs/ledger commit. `AGENTS.md` is Lead-owned and `main` already carries the newer, reconciled content. When this lane is PR'd, build the PR branch fresh from current `main` and cherry-pick only the F6 code commits (`49e1a7b`, `5df303a`, `09c5fea`, `5474e8d`, plus whatever the next slice adds) — do **not** merge the branch as-is, and drop both docs commits. GLM's reviewer session: do not add further `AGENTS.md` edits on the lane branch; send ledger changes to Lead instead. **Update 2026-09-20:** per a direct user instruction (reported by the GLM session), the GLM Claude session now implements F6 slices itself instead of relaying to external GLM. Slice implemented, **pending Lead's independent verification:** `d11d83f` (`SignedReportDataset` reading the latest SIGNED snapshot + new `ReportGeneration` Filament page) and `b2f6e05` (route `/admin/report-generation/{case}` + real-HTTP tests + a new `tests/Postgres/SignedReportDatasetRlsTest.php`, a new file outside the lane's test paths, accepted by Lead as new-file-only). Adapter reads only the text actually signed and fails closed (`NARRATIVE_CLUSTER_MISSING`); `IQ_CATEGORY` fails closed on PostgreSQL until the `jsonb` decision. Docs-only proposal `tasks/handoffs/f6/report-supplemental-data-proposal.md` (`b3d703a`, `cfff50a`, `009b6d3`) for 5 unpersisted HPP inputs — only report number (`report_documents`) and SIPP (`admins.sipp_number`) need schema; **no migration until the user decides**. PR cherry-pick list is now `49e1a7b`, `5df303a`, `09c5fea`, `5474e8d`, `d11d83f`, `b2f6e05` (+ the proposal docs if wanted). Branch tip `009b6d3`; the lane has agreed to no force-push/amend after push. | New only: `app/Domain/Report/**`, `app/Services/ReportRendering/**`, `resources/views/reports/**`, `tests/Feature/Reports/**`, `tests/Unit/Report/**` | Any existing file outside those new paths, any migration, any existing test, `composer.json`/`package.json`/lockfiles, this file, `tasks/parallel-work.md`, `tasks/f2-f9-acceptance.md` |
| DeepSeek | **F3, F4, F4b, and F5 all ACCEPTED and merged** (PR #2 `b18d5b4`, PR #4 `1748535`, PR #9 `603a74f`). RLS-scope discrepancy from the previous round is **RESOLVED**: project owner confirmed directly (not via peer relay) that `psychologist + super_admin` is the intended access scope for the signing page, replacing the psychologist-only implementation. **In progress on `deepseek/f5-resign-and-rls`** (branched from `main` `603a74f`): re-sign/REVISED flow + the RLS widening above. Implemented (`02e82c0`), then DeepSeek's own reviewer session caught two real issues before accepting — a TOCTOU race (revision-reason validation and the version/supersedes-id read were two separate queries, letting a concurrent request slip a SIGNED snapshot past the reason check) and an unjustified `withoutMiddleware(PreventRequestForgery)` added to two test files chasing a non-reproducing failure — both fixed (`cbdf0d9`). A third regression the reviewer's full-suite run caught (`AdminAuthorizationTest` still asserting super_admin *cannot* `ReviewReports` after the RLS widening) was fixed in `dfb81fd`; the branch also carries a handoff note (`tasks/handoffs/coordinator-session-handoff-2026-09-19.md`, `16e4a6d`/`9314584`). **Final acceptance verdict still pending:** the only remaining item is one full regression-suite run on the branch head confirming the failure count is back to the known baseline (~27-28: Vite manifest missing + the two flaky `PersistSealedIstResultTest`/`LoadPersistedIstResultTest` concurrency tests) and not one higher. Do not treat this as merged/accepted until that lands and Lead has re-run it independently. **Update 2026-09-20:** Lead's independent full run on `9314584` matched baseline (3544 tests; 28 failures + 1 error, all Vite-manifest or the two known-flaky IST tests; F5-specific suites all green). **But a new blocker was found by E2E-1 (PR #15):** `ReportSigning.php` has no `{case}` in its route while `mount(string $case, ...)` requires it → HTTP 500 for every logged-in role, no entry point to a case. Required on this branch **before** its PR merges: case-carrying route + case-list entry page scoped to the reviewer's RLS (not `runAsService` for listing), concealed 404 (not 403) for non-reviewers, and feature tests over real HTTP (`actingAs()->get()`, not `Livewire::test`). `ViewDass` stays psychologist-only — only `ReviewReports` widens. Drop the coordinator handoff file from the PR. G7 Phase A (below) waits until this merges. **Progress 2026-09-20:** route fix pushed (`35e16c4` drops the handoff file, `a934eda` = `report-signing/{case}` + new `ReportSigningQueue` page + concealed 404 via `mountCanAuthorizeAccess` + real-HTTP tests); verified green by the DeepSeek reviewer session (DeepSeek's own self-reported test counts did not match and are not used). Two queue bugs to fix before merge: the queue filtered on `assessment_participants` (exists only for INTEGRATED cases, so DIRECT_PUBLIC cases never appeared) and matched on participant+organization instead of case. Agreed fix: a case is listed when it has both `eligibility_decision_versions` and `bilingual_narrative_versions` rows (exactly the inputs `mount()` reads), joined on `assessment_case_id`; status badge from the latest snapshot **version** (snapshot `state` is always `SIGNED`; revisions are version > 1 + `supersedes_id`). Listing via `runAsService` is justified (RLS on those tables allows only `service`) but the claimed user approval ("Option B") is being confirmed directly with the user. **G7 Phase A design `36c508b` (branch `deepseek/g7-sealed-results`) REJECTED** by Lead + DeepSeek reviewer: it was written on the stale `a170bbd` checkout, builds on a `sealed_results` table that does not exist on `main`, never uses `generic_instrument_result_sources`, and copies the broken `jsonb` checksum pattern. Branch kept as archive. Redo Phase A from scratch on a worktree from `origin/main` after the `jsonb` decision, citing file:line on `main` for every table/class, no new tables without a migration proposal. Lane order: `fix/f3-f4-review-authz` → ReportSigning queue fixes + merge → `jsonb` decision → G7 Phase A redo → Lead review + user approval → Phase B. **Next task, queued behind the above — dispatch as investigation-first, NOT as a small wiring job (scope corrected 2026-09-19 after Lead re-checked `main`):** close the `TODO(G7-data-gap)` in `AspectSourceDiscrepancyPolicy.php` (and its twin in `ReportSigningService.php`). What already exists: `generic_instrument_results`/`generic_instrument_result_sources` (migrated tables, `2026_09_13_000100_create_generic_instrument_result_ledger.php`, append-only via triggers), and the IST path only — `app/Domain/AssessmentResults/SealedIstResult.php` (~260 lines of per-instrument invariant validation) + `app/Services/AssessmentResults/PersistSealedIstResult.php` (~290 lines) + its test (~555 lines). What does **not** exist: `SealedPapiResult`/`SealedKraepelinResult`/`SealedRmibResult` domain objects, as well as their `PersistSealed*Result` services. Real size is therefore ~3 new domain classes + 3 persist services + G7 wiring (cross-check `ReportSigningService`'s G7 flow against the ledger instead of trusting client-supplied `$sources`) + tests — roughly 3x the IST equivalent (~3000+ lines), not "extend an existing pattern." Step 1 of the dispatch is investigation only: read each instrument's already-accepted F2 scoring output shape and propose the three `Sealed*Result` shapes for review before writing them. Still not a new F2-vs-F5 architecture question; report back if one of the instruments' output shape doesn't fit the IST ledger layout rather than loosening the ledger. | Extend `app/Domain/Review/**`, `app/Domain/Eligibility/**`, `app/Domain/Narrative/**`, `app/Domain/AssessmentResults/**`, `app/Services/AssessmentResults/**`; `app/Filament/Pages/ReportSigning*.php`; `app/Services/Review/**`; new tests under `tests/**/Review/**`, `tests/Feature/Filament/**`, `tests/Feature/AssessmentResults/**` | `app/Domain/Report/**` (GLM's territory), `app/Filament/**` outside the signing page (Codex/F7), any existing migration, `routes/*.php` registration without Lead's go-ahead, `tasks/handoffs/authority-pack/**` (Codex's lane), `composer.json`/`package.json`/lockfiles, this file, `tasks/parallel-work.md`, `tasks/f2-f9-acceptance.md` |
| Lead (Claude coordinator, session name may vary — see "Coordination role" above) | Cross-lane conflict prevention, canonical docs, merge/promotion gatekeeping, all Codex communication, independent verification of every lane before anything is called done | `AGENTS.md`, `tasks/parallel-work.md`, `tasks/f2-f9-acceptance.md`, cross-branch reconciliation | Writing application code (human decision, 2026-09-16) |

Every lane branches from current `main`, works in its own branch, and does
not merge into `main` without the coordinator's independent verification —
push the branch to `origin` and wait for review, per the Coordination role
rules above.
