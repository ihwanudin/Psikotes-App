# PAPI item-content extraction — verification report (2026-09-21)

For: Lead review of `database/seeders/data/papi_items.json`, produced by
`tools/extract/extract_papi_items.py` on branch `f0/extract-papi-items`.

## Sources

- PDF: `PAPI KOSTICK REVISI (1).pdf` (11 pages), from the project owner's
  handoff — not committed.
- `database/seeders/data/papi.json` (existing, unmodified) — used only to
  cross-check the item count (90) against its `mapping` array, never written to.

PAPI has no second structured source like RMIB's xlsx, so per the plan, the
required cross-check is two genuinely independent PDF text-extraction methods
diffed word-for-word.

## Counts

- 90 items extracted, matching `papi.json`'s `mapping` length (90) exactly.
- Items are printed 10 per page, pages 3–11 (page 1 = cover, page 2 =
  instructions/example). Item → page = `3 + (item - 1) // 10`.
- Instructions: `intro` (before the worked example), `example` (the
  illustrative statement pair), `answer_sheet_demo` (a second demonstration —
  the same pair, but showing *how* to mark it on the paper answer sheet, under
  its own "Di LEMBAR JAWABAN" label), `closing` (after both examples). Kept as
  four separate fields rather than flattened into one blob, since the source
  itself presents them as distinct sections.

## Two-method cross-check (required in place of RMIB's xlsx; zero mismatches in the final version)

- **Method 1**: `pdftotext -layout` (poppler, external CLI, C++).
- **Method 2**: `pdfminer.six` (pure Python, a different codebase/algorithm
  from both poppler and `pypdf`).
- All 90 × 2 statements compared word-for-word between the two methods, after
  normalizing only whitespace and quote-character style (see below) — never
  wording. Any remaining difference aborts extraction.
- Also independently confirmed: every sentence used in `instructions` appears,
  verbatim after the same normalization, in the other method's extraction —
  so the instructions text isn't sourced from only one tool unverified.

### Why the normalization, and why `pdfminer.six` is the canonical text

The two tools decode two things differently, discovered by literally diffing
their raw output before any cleanup:
- The source's typographic curly quotes (`"` `"`, U+201C/U+201D) — `pdftotext`
  silently flattens these to straight ASCII quotes; `pdfminer.six` preserves
  the actual encoded character.
- The leader-dot row connecting each statement to its answer-sheet arrow —
  `pdftotext` renders it as repeated ASCII periods; `pdfminer.six` renders the
  same glyph as repeated ellipsis characters (U+2026).

Neither is "wrong" as extracted, but they're not the same bytes, so the
cross-check normalizes quote style and collapses whitespace *only for the
comparison*. The **committed text uses `pdfminer.six`'s output**, since it's
the more faithful decoding of the source's real characters (verified this is
consistent: every curly-quote pair in the 90 items pairs up correctly, 13
occurrences across the document — spot-checked, not just item 1).

## Extraction bugs found and fixed along the way (disclosed per the RMIB precedent)

- First leader-dot-stripping regex was too aggressive: it also ate a real,
  single sentence-ending period in the instructions prose (`"...
  terlewatkan."` → `"... terlewatkan"`). Fixed to require a run of 2+
  leader characters, since the booklet's leaders are always dozens of
  characters long — a real sentence never ends in more than one period here.
- `pypdf`'s `extraction_mode="layout"` (the technique that worked well for
  RMIB) fails completely on this PDF — "Rotated text discovered. Output will
  be incomplete." on every page, empty output. `pypdf`'s default (non-layout)
  mode does extract text, but has the same mid-word phantom-space defect
  (e.g. an "Artis profesional"-style corruption) `pypdf`'s coordinate
  extraction had for RMIB before that branch switched to layout mode. Rather
  than fight the same defect twice, method 2 uses `pdfminer.six` instead —
  still `pypdf`-free, still a genuinely different implementation from
  `pdftotext`, and it doesn't exhibit either defect on this document.
- `pdfminer.six` also preserves a trailing "←" print glyph (U+2190, the "circle
  this" arrow) on the two instruction demo lines — stripped alongside the
  leader dots it follows, since it's pure print layout, not content.
- `pdfminer.six`'s raw lines have double-spaced (justified-text) internal
  whitespace on some lines; the exact-string section lookups in
  `_build_instructions` needed that collapsed before matching, not just
  trimmed at the ends.

None of these were guessed around — each was found by a script assertion
failing loudly (`ValueError`/`list.index` raising), inspected, and fixed at
the root (a general rule), not patched per-line.

## Random sample for spot-checking against the PDF (seed 20260921, n=10)

| item | page | statement A | statement B |
|---|---|---|---|
| 5  | 3 | Saya suka menggabungkan diri dengan kelompok-kelompok | Saya suka diperhatikan oleh kelompok-kelompok |
| 6  | 3 | Saya senang bersahabat intim dengan seseorang | Saya senang bersahabat dengan sekolompok orang |
| 10 | 3 | Saya suka mengikuti perintah-perintah yang diberikan kepada saya | Saya suka menyenangkan hati orang yang memimpin saya |
| 21 | 5 | Saya selalu mencoba sekuat tenaga | Saya senang bekerja dengan sangat cermat dan hati-hati |
| 25 | 5 | Saya senang bila diundang | Saya ingin melakukan sesuatu lebih baik dari orang lain |
| 31 | 6 | Saya bekerja "keras" | Saya banyak berpikir dan berencana |
| 42 | 7 | Orang lain beranggapan bahwa saya adalah seorang pemimpin yang baik | Saya berpikir jauh ke depan dan terinci |
| 43 | 7 | Seringkali saya memanfaatkan peluang | Saya senang memperhatikan hal-hal sampai sekecil-kecilnya |
| 45 | 7 | Saya menyukai permainan-permainan dan olahraga | Saya sangat menyenangkan |
| 49 | 7 | Saya suka mengerjakan apa yang diharapkan dari saya | Saya suka menarik perhatian |

(Item 31's "keras" is rendered here with straight quotes for this table's
plain Markdown; the committed JSON has the source's actual curly quotes, per
above.)

## Instructions (full, for review)

**Intro:** "Di dalam buku terdapat 90 pasang pernyataan. Pilihlah satu
pernyataan dari pasangan pernyataan itu yang anda rasakan paling mendekati
gambaran diri anda, atau yang paling menunjukkan perasaan anda. Kadang-kadang
anda merasa bahwa kedua pernyataan itu tidak sesuai benar dengan diri anda,
namun demikian anda diminta tetap memilih satu pernyataan yang paling
menunjukkan diri anda. Jawaban anda dibuat di LEMBAR JAWABAN dengan cara
MELINGKARI PANAH yang terdapat didekat nomor pernyataannya."

**Example:** statement_a "Saya seorang pekerja "keras"" / statement_b "Saya
bukan seorang pemurung"

**Answer-sheet demo** (label "Di LEMBAR JAWABAN"): statement_a "Bila anda
seorang pekerja "keras", maka lingkarilah semacam ini" / statement_b "Tapi
bila anda bukan seorang pemurung, maka lingkari semacam ini"

**Closing:** "Pastikanlah bahwa anda memilih jawaban anda pada kolom yang
tepat di lembar jawaban anda. Apabila anda meneruskannya ke halaman
berikutnya, pastikanlah bahwa nomor di lembar jawaban berada pada garis yang
sama dengan nomor buku soal. Bekerjalah dengan cepat, tetapi jangan sampai ada
nomor pernyataan yang terlewatkan."

## Status marker

`papi_items.json` top-level `"status": "final"` — the cross-check passed with
zero mismatches after the fixes above, so nothing forced a `"draft"` state.

## Hash gate

Not yet pinned, same as RMIB's process: `test_f0_papi_items.py` covers
structural invariants only (90 unique items, count matches `papi.json`
mapping, no scoring/dimension-field leakage, nonempty text, no leftover
leader/arrow glyphs). The byte-hash pin will follow in a commit after you've
reviewed this content.

## Tests

- `python -m unittest tools.extract.tests.test_f0_papi_items -v` — 7/7 pass.
- `python -m unittest discover -s tools/extract/tests -t . -v` — 36/36 pass
  (29 existing + these 7; no regression).

## Not touched

`papi.json`, `InstrumentSeeder.php`, `resources/js/**`, `routes/api.php`,
session controllers, `ReportSigning*.php`, `tests/Frontend/**`,
`extract_kraepelin.py`, `kraepelin*.json`.
