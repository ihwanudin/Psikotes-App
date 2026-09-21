# F2 item-delivery Stage 2 — GET /sessions/{id}/items + real Kraepelin reader (2026-09-21)

Scope: the follow-up PR deferred by Stage 1
(`tasks/handoffs/f2/item-delivery-stage1-start-gate.md`) — the read endpoint
itself, plus the first real per-instrument reader (Kraepelin), registered
per Lead's instruction once both prerequisites (#61 start gate, #59
Kraepelin grid data) were on `main`.

## What shipped

- `GetAssessmentSessionItems` / `GetAssessmentSessionItemsResult` /
  `GetAssessmentSessionItemsController` — same sealed-controller,
  readable-exactly-when-writable shape as `GetAssessmentSessionAnswers`
  (`tasks/handoffs/f2/session-answers-readback.md`): `in_progress` and
  before `ends_at` only, `SESSION_NOT_STARTED`/`SESSION_CLOSED`/
  `DEADLINE_EXCEEDED`, never writes. `SessionDefinition` reconstructed from
  the session's own immutable stored snapshot, same as `replay()`.
- **One deliberate difference from `replay()` and from answers-readback**:
  the item-content authority is consulted **fresh on every read**, not
  cached or assumed still available because start succeeded. A reader that
  stops being available after a session started still fails the read
  closed (`503 ASSESSMENT_ITEM_CONTENT_UNAVAILABLE`) rather than serving
  something stale or guessed.
- `tests/Architecture/AssessmentSessionHttpBoundaryTest.php` extended to a
  fifth controller (route/RLS-exclusion, no forbidden dependency, no DB
  facade/`runAsService` reference in source).
- `KraepelinItemContentReader` — the first real
  `AssessmentItemContentAuthority` implementation, registered in
  `AppServiceProvider`'s readers map under `'kraepelin'`. `ist`/`papi`/
  `rmib` still have no reader and still reject exactly as Stage 1 shipped
  them; Stage 2 only ever **adds** entries, never changes the fail-closed
  default (same invariant Stage 1 established, now proven by an actual
  addition instead of only by the empty map).
- `InstrumentSeeder::SOURCES` gained `'kraepelin_grid' => 'kraepelin_grid.json'`
  — a **separate** `instrument_versions` row from `'kraepelin' =>
  'kraepelin.json'` (that one holds scoring norms: `hanker_formula`,
  `score_bands`; the new one holds only the raw item numbers). CLAUDE.md
  keeps norms/lookup data and instrument item data on separate paths into
  the app; this preserves that split at the seeder level, not just in
  application code.
- `API_CONTRACT.md` — new `GET /sessions/:id/items` bullet, plus the
  administration-order contract decision below, communicated to Lead for
  GLM's `grid-column.ts`.

## The order contract: server sends administration order, not sheet order

`kraepelin_grid.json`'s `grid` is stored in **sheet order** (row 0 = top of
the printed sheet — verified against the scanned sheet image, see
`tasks/handoffs/f2/verifikasi-kraepelin-2026-09-21.md`). Administration
itself works bottom-to-top (`CLAUDE.md`: "jumlahkan dari bawah ke atas").
Two places could do that bottom-to-top flip: server, once, before the
response is built, or client, once, per render.

**Decision: server does it, exactly once, in `KraepelinItemContentReader`.**
`GET /sessions/{id}/items` for Kraepelin returns 50 subtests (`col_01`..
`col_50`), each with exactly 28 `{position, value}` items **already in
administration order** — `position=1` is the bottom-most number on the
printed sheet, `position=28` is the top-most. A client renders `items` in
list order with no reordering step of its own.

Why server-side and not client-side: CLAUDE.md forbids timer/scoring logic
in the frontend, and while a bottom-to-top display flip is not scoring
arithmetic, it is exactly the same *class* of correctness-critical,
easy-to-get-backwards transformation — Lead's own framing of the risk
("satu pembalikan yang salah tempat berarti setiap jawaban peserta
dicocokkan dengan pasangan angka yang salah") is not hypothetical: a column
of nine-or-fewer possible values gives no signal at all that a reversed
column is wrong, unlike say a truncated list or an out-of-range value.
Putting the flip in exactly one place, proven by one test
(`KraepelinItemContentReaderTest`), removes the failure mode of two
independent implementations (this reader's and GLM's `grid-column.ts`)
silently drifting out of lockstep — a double-flip or a missing flip are
otherwise indistinguishable from correct until a real participant's score
comes out wrong.

**Action needed on the GLM side** (communicated to Lead, who relays it):
`grid-column.ts` currently performs its own `grid[row][col]` reversal
under the assumption the server sends sheet order. Once this contract
ships, the server response is already in administration order, so that
client-side reversal must be removed — not doubled up on top of the
server's.

## Item shape: raw numbers only, no computed sums

Each Kraepelin item is `{position: int, value: int 1-9}` — the single digit
printed on the sheet at that position. No sums, no expected answers, no
per-item correctness data. Delivery only supplies the stimulus; grading a
submitted digit-answer batch against these numbers is a separate,
not-yet-built scoring concern (`KraepelinFactorCalculator` already exists
for *already-graded* column data per its own docblock — grading raw
per-slot digit answers against the grid is still open, out of scope here).

## Why `instrument_versions`, not reading the JSON file directly

Same pattern as every other scorer in this codebase reading instrument
data (`ScoreSealedIstAnswerSet`, `LoadGenericInstrumentResultSourcesForAspect`,
etc.): `KraepelinItemContentReader` queries
`instrument_versions WHERE code = 'kraepelin_grid' AND is_active = true`,
verifies `checksum` against `source_text` byte-for-byte (never trusts
`payload`, which PostgreSQL's jsonb normalization can silently reserialize
away from the hashed bytes — same reasoning documented in
`ScoreSealedIstAnswerSet`), and fails closed
(`AssessmentItemContentUnavailable`) on any missing/unverifiable/malformed
row. This is also how CLAUDE.md's "SEMUA dibaca dari Tabel Lookup" rule
applies to item content, not just scoring weights: the grid data path is
identical to the norms data path, just a different `code`.

## Test coverage

- `tests/Feature/AssessmentSessions/AssessmentSessionItemsReadbackTest.php`
  — 11 SQLite tests: happy path proving exact source-order preservation
  through the whole controller/action pipeline, `SESSION_NOT_STARTED`,
  all four `SESSION_CLOSED` statuses, the exact-deadline boundary with a
  no-side-effects proof (session row byte-identical before/after a
  rejected past-deadline read), byte-identical 404 (nonexistent vs.
  foreign, headers minus `Date`), DASS-21 exclusion, response whitelist,
  and the real fail-closed empty-registry-for-ist/papi/rmib path returning
  `503` (bound directly, not through a fake).
- `tests/Postgres/AssessmentSessionItemsReadbackControllerTest.php` — 3
  tests, 12 assertions: happy path and foreign-session-404 through the real
  controller under `psikotes_runtime`/`NOBYPASSRLS`, plus the real
  fail-closed registry proof under PostgreSQL (mirrors the mandatory
  PostgreSQL evidence Lead required for the `/start` item-content gate in
  PR #61). No torn-read/concurrency test: unlike answers, item content
  isn't read from a table another action writes to concurrently, so there
  is no interleaving to prove safe.
- `tests/Feature/AssessmentSessions/KraepelinItemContentReaderTest.php` — 4
  tests against the **real, unmodified** seeded `kraepelin_grid.json` (via
  a real `InstrumentSeeder` run, not a synthetic fixture): the
  administration-order reversal proven on both edge columns (`col_01` and
  `col_50`) against the raw grid's actual values, every value checked as a
  1-9 digit, rejection for a non-Kraepelin instrument, and fail-closed
  behaviour for both an inactive row and a tampered/checksum-mismatched
  row.

## Test suite status

- SQLite: `tests/Feature/AssessmentSessions`, `tests/Unit/AssessmentSessions`,
  `tests/Architecture` — green (see CI/PR for exact counts at merge time).
- PostgreSQL (`tools/testing/run-org-postgres.ps1 -Filter
  AssessmentSessionItemsReadbackControllerTest`): 3 tests, 12 assertions,
  green, first run.
- Full `phpstan` gate: 0 errors. `pint --test`: clean.

## Noted, not fixed (out of scope for this PR)

`GET /sessions/:id/answers` (PR #52, already merged) has no bullet in
`API_CONTRACT.md`'s Peserta section — only the DASS-21-exclusion and
`ASSESSMENT_ITEM_CONTENT_UNAVAILABLE` additions ever landed there for the
readback endpoints. Flagging for a separate small doc-only fix rather than
folding it into this PR's diff.

## Deferred, still open

Real readers for `ist`/`papi`/`rmib` — item data extraction for those three
("Soal" session) was not final at time of writing this PR; each stays
fail-closed until its own reader lands, same invariant as Stage 1.
Grading submitted Kraepelin digit-answers against this grid (scoring, not
delivery) is a separate not-yet-built concern.
