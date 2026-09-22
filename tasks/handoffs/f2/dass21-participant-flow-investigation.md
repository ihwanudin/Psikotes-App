# DASS-21 participant test-taking flow — investigation (GLM, 2026-09-22)

Requested by Lead: investigate before any code, per Lead's five questions.
Everything below is read from the actual source on `origin/main` (paths/
lines cited), not guessed or taken on Lead's word. No code was written for
this task — this is the plan Lead asked for before implementation starts.

## TL;DR

DASS-21 is fully wired everywhere **except** the one place that actually
matters for this task: a participant cannot take it. Consent-type
plumbing, entitlement provisioning, scoring, screening policy, the
two-tier confidentiality split for reporting, and an isolated DB schema
all already exist and are already tested. What's missing is the
session-taking layer: no way to start a DASS attempt, no way to answer its
21 items, no way to submit them, and **no RLS policy on the three DASS
tables themselves** (only `consent_records` has one). `API_CONTRACT.md`
documents that DASS is deliberately excluded from the generic engine and
uses "penyimpanan dan alur terisolasi" (isolated storage and flow), but
does not specify what that isolated flow's endpoints actually look like —
it was never designed, only reserved.

## 1. Is there an already-designed-but-unbuilt DASS API, or nothing at all?

Nothing at all beyond the boundary statement. Read directly:

- `API_CONTRACT.md:89` — `POST /api/sessions/:test_type/start` "hanya
  menerima `ist|papi|rmib|kraepelin`; `dass21` ditolak karena memakai
  penyimpanan dan alur terisolasi." No endpoint list, request/response
  shape, or route prefix for that isolated flow follows this sentence
  anywhere in the document.
- `API_CONTRACT.md:122` — "Generic session/answer/event tidak boleh
  menyimpan atau memproses respons DASS-21." A hard constraint on the
  generic engine, not a spec for DASS's own engine.
- `tasks/handoffs/f2-assessment-session-contract.md:16-22` (frozen,
  Accepted status) repeats the same boundary and names the isolated
  tables (`dass.assessments`, `dass.responses`, `dass.results` — see §2)
  but again stops at "DASS-21 remains on [its own track]" (line 281) and
  "DASS implementation remains a [separate item]" (line 371) without
  ever sketching its routes or actions.
- `app/Http/Requests/StartGenericAssessmentSessionRequest.php:49-51`'s
  own comment cites `API_CONTRACT.md` for the same claim, closing the
  loop: every reference to "DASS has its own isolated flow" traces back
  to the same one boundary sentence, not to a design.

**Conclusion**: this needs a real design, not a lookup. Nobody has
specified DASS's session-start/answer/submit contract yet.

## 2. DASS-21 item data shape

`database/seeders/data/dass21.json` (F0-extracted via
`tools/extract/extract_dass.py`), top-level keys: `items`, `cutoffs`,
`multiplier`, `narratives`, `follow_up`.

- `items`: exactly 21 entries, each `{item: int (1-21), scale: "D"|"A"|"S",
  text_id: string}`. No answer-option text in the file — the 0-3 Likert
  scale is fixed/universal across all 21 items (not per-item data), so the
  frontend renders one shared 4-point answer scale, not per-item options
  like IST's multiple-choice items.
- `cutoffs`, `multiplier`, `narratives`, `follow_up`: consumed by
  `Dass21Scorer`/`Dass21ScreeningPolicy` (see §5) to turn 21 raw 0-3
  answers into subscale scores, categories, and follow-up guidance. Not
  the participant's concern — never sent to the client.

`Dass21Scorer::score()` (`app/Services/Scoring/Dass21Scorer.php:70-100`)
is the authoritative answer shape: exactly 21 responses, each
`{item: int, score: int 0-3}`, no duplicates, every item 1-21 covered —
**all 21 at once**, not incremental. There is no partial-scoring path in
this class at all.

## 3. Autosave/resume, or submit-all-at-once?

Genuinely open — evidence points both ways, this is a real decision for
Lead/psychologist, not something I should silently pick.

**For submit-once (no autosave complexity)**:
- `Dass21Scorer` only ever scores a complete set of 21 (§2) — there is no
  concept of "partial score" anywhere downstream.
- `Dass21ScreeningPolicy::evaluate()`
  (`app/Services/Scoring/Dass21ScreeningPolicy.php:36,134,141-143`) needs
  a `completionDurationSeconds` and flags `completion_under_minimum` — it
  wants a clean start→submit interval, not a resumed-after-a-gap session.
- 21 short Likert items is a 3-5 minute task; the generic instruments'
  autosave/resume/revision-conflict machinery exists because THEY run
  30-90+ minutes with real risk of a dropped connection mid-subtest — a
  much smaller risk surface for DASS.

**For per-item storage anyway (not necessarily autosave, but relevant)**:
- `dass.responses` (`database/migrations/2026_08_25_000300_create_isolated_dass_schema.php:38-50`)
  is already a **row-per-item** table: `UNIQUE(assessment_id,
  item_number)`, plus a nullable `response_time_ms` per item — the schema
  was built assuming per-item writes (whether all sent together at submit
  or one at a time), and even anticipated capturing individual item
  response times, which a single bulk-JSON submit would just synthesize
  as 21 simultaneous timestamps.
- `dass.assessments.status` includes `'in_progress'`
  (migration line 27, 103) as a distinct persisted state from
  `'not_started'`/`'completed'` — implying the design already expects a
  session can sit "started but not yet submitted," which is at minimum a
  resume-on-reload requirement even if not a live autosave-per-answer one.

**My read**: the schema's own shape (row-per-item, an `in_progress`
state) suggests resume-on-reload is intended even if live per-keystroke
autosave isn't — e.g. submit could still be "send all 21 at the end,"
with the server writing 21 rows in one transaction, while a *separate*
lightweight "what did I already answer" read lets a reloaded page
rehydrate. That's a smaller build than the generic instruments'
mutation-id/revision-conflict autosave engine. This is the option I'd
lean toward, but it's Lead's/psychologist's call, not mine to lock in
silently.

## 4. Lobby / entitlement appearance

Already fully wired on the entitlement side — this is not a gap:

- `docs/decisions/0027-dass21-standalone-and-bundled-package.md:17-18` —
  DASS-21 is both a standalone free package AND bundled into every main
  psychotest package, server-decided composition, not participant choice.
- Entitlement provisioning already treats `'dass21'` as a first-class
  test type alongside the four generic instruments in every relevant
  action: `ProvisionSelectionParticipant.php:224`,
  `RegisterParticipant.php:336,341,347-348`,
  `ConfirmIntegratedCheckout.php:212`, `SettleZeroPriceCheckout.php:179`.
- `resources/js/pages/participant/lobby.tsx:35` already has
  `dass21: 'DASS-21'` in its test-name lookup, so it renders correctly in
  the entitlement list today, no special-casing needed.
- **Not DASS-specific**: the lobby has no "Start" button/click action for
  *any* instrument yet — `lobby.tsx:295-298` is a static note ("Tombol
  mulai akan aktif setelah modul pengerjaan tes tersedia"). Wiring an
  actual start click is a separate, cross-instrument task this
  investigation doesn't need to solve; DASS just needs to be ready to
  slot into whatever that becomes, the same as IST/PAPI/RMIB/Kraepelin.

## 5. Confidentiality boundary — what already enforces it, what's still open

CLAUDE.md's rule ("DASS-21 TIDAK PERNAH masuk ekspresi zona/label...
Skor subskala DASS TIDAK dicetak di HPP") is already structurally
enforced on the **reporting** side, which is reassuring evidence this
project takes it seriously, but the **live test-taking API** (what I'd be
building) has no code to audit yet, so it inherits nothing automatically
— it has to be built correctly from scratch.

**Already built (reporting side)**:
- `app/Domain/Report/DassScreeningSummary.php` — the ONLY DASS shape
  allowed into the HPP: `{general_category, narrative, follow_up}`.
  Structurally cannot carry subscale scores (`fromArray()` rejects any
  input that isn't exactly these three keys).
- `app/Domain/Report/DassInternalDetail.php` — the psychologist-only
  sheet: raw/doubled/category per subscale (D/A/S) plus flags. Comment:
  "Never projected into the participant HPP."
- `Dass21ScreeningPolicy::evaluate()` itself returns `general`,
  `follow_up`, `validity_flags`, `active_flag_codes`, `provenance` — the
  raw per-item `item_scores`/subscale breakdown from `Dass21Scorer` is a
  SEPARATE structure this policy consumes but does not forward untouched;
  whoever builds `DassInternalDetail`/`DassScreeningSummary` from it has
  to deliberately choose which fields go where (already done correctly
  today per the classes above, but worth stating: nothing here
  automatically firewalls a careless new caller that reaches for
  `Dass21Scorer`'s raw output directly).
- `consent_records` RLS (`database/migrations/2026_09_05_000500_restrict_dass_consent_read_policy.php`)
  already restricts `consent_type = 'dass'` rows to
  service/psychologist/self-participant only — `branch_admin`/`staff`
  explicitly excluded, proven by
  `tests/Postgres/DassConsentBranchPrivacyTest.php`.

**Not yet built — a real gap, found during this investigation, worth
flagging even though Lead framed this task as "not a security risk"**:
`dass.assessments`, `dass.responses`, `dass.results` have **no RLS
policy at all**. `grep`-verified: only
`2026_08_25_000300_create_isolated_dass_schema.php` touches those three
tables in the whole `database/migrations/` tree — no follow-up policy
migration exists for them the way one exists for `consent_records`. Any
new session-taking action that writes into `dass.responses` or reads
`dass.results` needs its own RLS policy migration (participant sees only
their own row, `branch_admin`/`staff` excluded same as DASS consent,
`super_admin` almost certainly excluded too by analogy to the consent
policy's restrictive layer) before it can be considered safe to ship —
this is new work this task would need to include, not something to
inherit "for free" the way the generic instruments' RLS already exists.

**What a new DASS session-taking API must NOT do** (derived from the
above, my own design constraint, not yet built so nothing to point at
that already enforces it): the submit/complete response returned to the
*participant themselves* should carry at most what
`DassScreeningSummary` carries (general category + narrative + follow-up)
— never raw subscale scores, never `item_scores`. Whether even the
general category should reach the participant live (vs. only ever
appearing later in the signed HPP) is itself a question for Lead/
psychologist, not something I'd decide unilaterally.

## Proposed shape (sketch only — needs Lead/psychologist sign-off before any code)

Mirroring the generic instruments' pure-domain/thin-controller split
(`app/Actions/AssessmentSessions/*`) but against the isolated `dass.*`
tables, NOT reusing `AllocateAndStartAssessmentSession`,
`AutosaveAssessmentAnswers`, `SubmitAssessmentSession`, or their RLS
group — those are explicitly generic-four-instrument-only per
`API_CONTRACT.md:122`.

- **Routes**: a `dass21`-specific prefix, e.g. `POST /api/dass21/start`,
  `GET /api/dass21/:id`, `POST /api/dass21/:id/answers` (shape TBD per
  §3's open decision), `POST /api/dass21/:id/submit` — deliberately NOT
  under `/sessions/:test_type/...`, since that family is reserved for the
  generic four per the FormRequest's own comment (§1).
- **Start**: verify entitlement via the existing
  `AssessmentAccessPrerequisites` gate (`app/Services/ParticipantAuth/AssessmentAccessPrerequisites.php:45`
  already requires BOTH `'psychotest'` and `'dass'` consent types for
  `testType === 'dass21'` — this already exists, just needs to be called
  from the new start action), allocate a `dass.assessments` row
  (`status: 'not_started'` → `'in_progress'`, `started_at` from server
  clock, `expires_at` per the 2-year retention policy CLAUDE.md and
  ADR-0027 already establish), link `consent_record_id`.
- **Answers/submit**: per §3's open decision — either one bulk write of
  21 rows into `dass.responses` at submit time, or a lighter per-item
  save endpoint; either way `Dass21Scorer::score()` then
  `Dass21ScreeningPolicy::evaluate()` run server-side only, writing
  `dass.results`, and the participant-facing response is
  `DassScreeningSummary`-shaped only (§5).
- **New RLS migration** for `dass.assessments`/`dass.responses`/`dass.results`
  (§5's gap) — required, not optional, before this can go anywhere near
  production data.
- **Frontend**: a new `resources/js/components/participant/dass21/`
  directory, NOT reusing `session-runner/*` (that package's types/hooks
  are generic-four-instrument-shaped — `AssessmentSessionState.testType`
  is currently `'ist' | 'papi' | 'rmib' | 'kraepelin'` and would need to
  either grow a fifth incompatible variant or, more likely, DASS gets its
  own small session-state hook mirroring the shape but not sharing the
  type). A single-screen or short-paginated 21-item Likert form, no
  timed-segments (nothing in `dass21.json`/`Dass21Scorer` suggests a
  server-tracked time limit).

## Explicit non-goals of this document

No code, no migration, no route was written for this. This is the
investigation + design sketch Lead asked for before implementation
starts, per this project's plan-first norm for unfamiliar/large-scope
work. The three flagged decisions (§3 autosave-vs-submit-once, §5's new
RLS migration scope, whether the general category reaches the
participant live) need Lead's and/or the psychologist's sign-off before
any of the above gets built.
