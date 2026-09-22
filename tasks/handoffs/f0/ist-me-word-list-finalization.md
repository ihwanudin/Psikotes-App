# IST ME word list — draft → final (2026-09-22)

For: Lead review of `database/seeders/data/ist_items.json` on branch
`f2/me-final-extraction`. Follow-up to
`tasks/handoffs/f0/ist-text-items-verification.md` (PR #66,
`3003a471`), which left ME `status: "draft"` pending the psychologist's
choice between the two word-list variants found on page 19 of the PDF.

## What changed

Psychologist confirmed P7 (`tasks/handoffs/decisions/owner-decisions-2026-09-21.md`
item 21, PR #81, `4050eb9b`): **"Versi A"** — variant 1, the minority copy
(printed once of four) — is correct: BURUNG includes **TEKUKUR**, KESENIAN
includes **QUINTET**. This is the variant the ME instructions page's own
worked example already pointed to ("Quintet adalah termasuk dalam jenis
kesenian..." — flagged as circumstantial support in the original
verification report, not treated as resolving the question at the time).

ME moves `draft` → `final`. Because FA/WU are still absent (separate PR,
not yet merged) and every other text subtest was already `final`, the
top-level `status` also moves `draft` → `final`.

## How the data was written — selection, not re-extraction

No PDF re-read was needed or done: both variants were already extracted
and verified in the prior PR, sitting as `word_list_variants` in the
tracked `ist_items.json`. This step is a pure **selection** among
already-recorded candidates, done through `tools/extract/extract_ist_items.py`
per CLAUDE.md's instrument-data rule (never hand-typed into JSON), not a
fresh extraction:

- New function `finalize_me(ist_items_json_path, output)`, invoked via
  `extract_ist_items.py --finalize-me <path>` (new CLI flag, mutually
  exclusive with the PDF-extraction `pdf_path` argument, which is now
  optional).
- A module-level constant `ME_CONFIRMED_WORD_LIST` holds exactly the
  psychologist-confirmed variant.
- **Fail-closed guard**: `finalize_me()` asserts `ME_CONFIRMED_WORD_LIST`
  exactly equals one of the entries already present in `word_list_variants`
  before writing anything. If it didn't match — e.g. a future typo in the
  constant — the function raises instead of silently inventing content.
  This is the same "loud failure over silent wrong output" discipline used
  throughout the original extraction (see the prior report's "bugs found"
  section).
- ME's own `items` and `instructions` (unrelated to which word-list variant
  is chosen) are carried through unchanged.

## Byte-level diff confirms nothing else moved

Structural diff between the pre- and post-finalize files: every subtest
except ME is byte-identical; ME's `items`/`instructions` are unchanged;
only `ME.status`, `ME.draft_reason` + `ME.word_list_variants` →
`ME.word_list`, and the top-level `status` changed.

## Hash gate — re-pinned

`test_bytes_are_deterministic` in `test_f0_ist_items.py`:
- length: 37509 → **36105** bytes
- sha256: `7ba88a3c...` → **`f4b3015fc1e5dd8c24c622cc5e10c3e8a4c03fc99b183b235be9fc8e2b6b6304`**

Also updated in the same test file:
- `test_top_level_status_is_draft_because_me_is_draft` →
  `test_top_level_status_is_final_since_me_is_now_final` (asserts `"final"`).
- `test_me_is_draft_with_both_word_list_variants_recorded` →
  `test_me_is_final_with_confirmed_word_list` (asserts `status == "final"`,
  no `draft_reason`/`word_list_variants` left over, all 5 categories × 5
  words present, TEKUKUR in BURUNG and QUINTET in KESENIAN specifically).

All other tests in the file were unaffected — the answer-key
cross-check doesn't depend on which variant is chosen (TEKUKUR/TERUKUR and
QUINTET/QUATET share the same starting letter, so the T/Q answer-key
membership checks pass either way).

## Tests

- `python -m unittest tools.extract.tests.test_f0_ist_items -v` — 13/13 pass.
- `python -m unittest discover -s tools/extract/tests -t . -v` — 52/52 pass
  (all prior RMIB/PAPI/IST/base tests + these, no regression).

## Not touched

`ist.json`, `InstrumentSeeder.php`, `resources/js/**`, `routes/api.php`,
session controllers, `extract_kraepelin.py`, `kraepelin*.json`. No PHP code
currently reads `ist_items.json` (IST item-delivery is still deferred per
`tasks/handoffs/f2/item-delivery-segment-awareness-deferred.md`), so this
change has no runtime effect yet — it only finalizes the data-governance
record for when that reader is built.
