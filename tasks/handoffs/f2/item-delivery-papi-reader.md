# F2 item-delivery Stage 2 continuation — real PAPI reader (2026-09-21)

Scope: the second real `AssessmentItemContentAuthority` implementation
after Kraepelin (`tasks/handoffs/f2/item-delivery-items-endpoint.md`),
registered per Lead's instruction once `papi_items.json` (#62, 90 items,
`status: final`) was verified on `main`. `ist`/`rmib` still have no reader
and still reject exactly as before -- this PR only adds `papi`.

## What shipped

- `PapiItemContentReader` — reads `instrument_versions` (code=`papi_items`,
  a separate row from `code=papi`, which holds the `mapping` scoring-
  dimension data). Same checksum-verification pattern as
  `KraepelinItemContentReader`: `source_text` hashed and compared against
  the stored `checksum` before anything is trusted, fails closed
  (`AssessmentItemContentUnavailable`) on a missing/inactive/unverifiable
  row or an unexpected payload shape.
- **New for this reader, no Kraepelin precedent**: rejects a non-`final`
  `status` the same way it rejects a checksum mismatch.
  `papi_items.json` is instrument data that came through `tools/extract/`
  — a draft/unreviewed extraction must never reach a participant just
  because its checksum happens to verify.
- No gender/variant axis (unlike the RMIB work still pending) — one
  subtest (`code: 'ITEMS'`), all 90 `{item, statement_a, statement_b}` in
  exact source order, no reordering, no field beyond those three (no
  `mapping`/dimension codes — confirmed absent from `papi_items.json`
  itself, so there's nothing to filter out, structurally).
- `InstrumentSeeder::SOURCES` gained `'papi_items' => 'papi_items.json'`,
  following the `kraepelin_grid` precedent exactly. Two tests that
  enumerate the exact seeded `instrument_versions` set updated
  (`InstrumentSeederTest`, `InstrumentVersionHistorySecurityTest`).
- `AppServiceProvider`'s readers map gained `'papi' => new
  PapiItemContentReader`.
- **Added after initial review (GLM request, 2026-09-21)**:
  `AssessmentItemContent` gained an optional `instructions` field (`null`
  for readers that don't have any -- always present in the response shape,
  never an omitted key). PAPI's `intro`/`example`/`answer_sheet_demo`/
  `closing` administration text now travels through the same `/items`
  response as its 90 items, whitelisted to exactly those four fields.
  `GetAssessmentSessionItemsController` and `API_CONTRACT.md` updated to
  match. One server-side source of truth for this text instead of a
  hardcoded client-side copy.

## Test coverage

- `tests/Feature/AssessmentSessions/PapiItemContentReaderTest.php` — 6
  tests against the **real, unmodified** seeded `papi_items.json` (via a
  real `InstrumentSeeder` run): exact 90-item source-order preservation
  with per-item value comparison against the raw payload, rejection for a
  non-PAPI instrument, fail-closed for an inactive row, fail-closed for a
  checksum mismatch, fail-closed for a tampered `status: draft` (checksum
  recomputed to match the tampered payload, so only the status check is
  what rejects it — proves the status gate is real, not just incidentally
  covered by the checksum gate), fail-closed for malformed/incomplete
  instructions.
- `AssessmentSessionItemsReadbackTest.php` updated: the strict-whitelist
  test and the exact-JSON happy path both now account for the always-present
  `instructions` key.

## Test suite status

- SQLite: `tests/Feature/AssessmentSessions`, `tests/Unit/AssessmentSessions`,
  `tests/Architecture`, `tests/Feature/Seeders`, `tests/Feature/Eligibility`
  — 369 tests, 10426 assertions, green (re-run after the `instructions`
  addition; an earlier 389/10600 count from before that addition is
  superseded).
- PostgreSQL: `InstrumentVersionHistorySecurityTest` (7 tests, 77
  assertions) and `AssessmentSessionItemsReadbackControllerTest` (3 tests,
  12 assertions) both green after the `instructions` addition. Full
  organization suite: 646 tests, exactly the known 7-test baseline red, no
  8th failure.
- Full `phpstan` gate: 0 errors. `pint --test` (full repo): clean.

## CI note

GitHub Actions is down repo-wide at time of this PR due to a billing issue
on the account, unrelated to this change (confirmed by Lead, who has
notified the project owner). All jobs fail in ~2 seconds with "recent
account payments have failed". Local `pint`/`phpstan`/SQLite/PostgreSQL
results above stand in for CI until it recovers; merge is held until then
regardless.

## Deferred, still open

RMIB — blocked on a larger design (server-side gender-track selection
locked at session start and stored with the session, not re-read from
`participants.gender` on every `/items` read, per Lead's decision
2026-09-21) that needs its own migration. Tracked separately, not part of
this PR.
