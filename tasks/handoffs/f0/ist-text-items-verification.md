# IST item-content extraction (text subtests) — verification report (2026-09-21)

For: Lead review of `database/seeders/data/ist_items.json`, produced by
`tools/extract/extract_ist_items.py` on branch `f0/extract-ist-items`.

**Scope of this PR: text subtests only** — SE, WA, AN, GE, RA, ZR, ME (136
items + ME's word list). FA/WU (images, 40 more items) are a separate
follow-up PR, per your instruction to split if images take substantially
longer — they did (see the FA/WU section below for what's already
understood but not yet built).

## Sources

- PDF: `Alat Tes IST (1).pdf` (20 pages), from the project owner's handoff —
  not committed.
- `database/seeders/data/ist.json` (existing, unmodified) — used only for the
  answer-key cross-check, never written to.

## Structure confirmed

20 pages: 9 subtests × 2 pages each (instructions+example, then items) in
order SE, WA, AN, GE, RA, ZR, FA, WU, ME; page 19 = ME's memorization word
list (printed 4 times); page 20 = closing/attribution only, no content.

| subtest | items | global # | items page | answer type |
|---|---|---|---|---|
| SE | 20 | 1–20 | 2 | multiple choice (a–e) |
| WA | 20 | 21–40 | 4 | multiple choice (a–e), no stem |
| AN | 20 | 41–60 | 6 | multiple choice (a–e) |
| GE | 16 | 61–76 | 8 | fill-in (word) |
| RA | 20 | 77–96 | 10 | fill-in (numeric) |
| ZR | 20 | 97–116 | 12 | fill-in (numeric) |
| ME | 20 | 157–176 | 18 | multiple choice (a–e) — **draft**, see below |

## Two-method cross-check: SE, WA, GE, RA, ZR — zero mismatches after 2 real fixes; AN and ME are documented exceptions

Required by your instruction, extended from PAPI's approach after finding
real (not hypothetical) differing defects in `pdftotext` and `pypdf` on this
same document:

- **`pdftotext -layout -enc UTF-8`** (note: `-enc UTF-8` explicit — without
  it, this specific PDF's en-dash renders as a replacement character;
  confirmed by diffing with/without the flag before building anything on
  top of it).
- **`pypdf`** `extraction_mode="layout"` — the same technique that worked for
  RMIB; also the only one of the two that handles WA's 3-column layout
  directly.
- **WA's second method**: `pdftotext` *without* `-layout` (plain mode) —
  `-layout` garbles WA's 3 columns the same way it did for RMIB; plain mode
  interleaves columns in an odd order but keeps each item's own text intact,
  which is all a number-matched cross-check needs.

**Two whitespace-only mismatches tolerated** (both on SE, both the same
known defect class as RMIB's "Artis profesional" — a phantom internal space
from `pypdf`, not a real wording difference): item 11 ("kecelakaan" vs
"k ecelakaan") and item 18 ("letaknya" vs "letak nya"). `pdftotext` (clean on
both) is what's committed; tolerance only fires when the two strings are
identical after removing all whitespace, and every tolerated case is printed
by the extractor for the record, never silent.

### AN and ME: two-method diff disabled, documented, with a same-strength single-source substitute

Both hit a defect in `pdftotext` too severe to treat as noise:

- **AN**: `pdftotext -layout` systematically separates every item's `e)`
  option from its `a`–`d` row, scattering it onto *unrelated* items' lines —
  not an occasional duplicate, all 20 items. Tried `pdfminer.six` (the
  PAPI/RMIB fallback) as an alternative second source: its content is
  correct but the reading order is scrambled across item boundaries in a way
  that would need bespoke reconstruction logic no more independently
  trustworthy than just reading `pypdf`'s already-clean output directly.
- **ME**: `pdftotext`'s "e) binatang" (present correctly for items 157–161)
  drops out of its real position entirely from item 162 onward — not
  duplicated elsewhere, genuinely missing from that row.

For both, `pypdf` is clean for all 20 items (I read it directly, not just
trusted it) and is what's committed. In place of the disabled diff, both
still get the same option-count and key-membership checks every subtest
gets — the originally-agreed minimum bar for IST before the two-method
extension. Full reasoning is in the module's docstring and `SUBTESTS`'
comment, not just this report.

## Answer-key membership check (SE/WA/AN/ME — all pass)

For every multiple-choice item, `ist.json`'s answer-key letter (converted
from its per-subtest local numbering to the global item number — `ist.json`
numbers WA 1–20, not 21–40; found and fixed a real bug here, see below) is
confirmed present among that item's extracted option letters. 80/80 items
(SE+WA+AN+ME) pass.

## Bugs found and fixed along the way (transparency, same as RMIB/PAPI)

1. **`ist.json` local vs global item numbering.** `ist.json`'s `keys` array
   numbers every subtest 1–20 locally (e.g. WA's first key is `item: 1`, not
   `21`). The key-membership check initially compared local numbers against
   global ones directly and failed for every subtest except SE (where
   `start=1` coincidentally makes local and global numbers equal, hiding the
   bug there). Fixed by converting with `local + start - 1`.
2. **Section-boundary leak.** The per-subtest text section was originally
   bounded by "the next subtest's *items* header," which let the next
   subtest's *instructions* page (with its own worked-example answer-sheet
   marking, e.g. WA's "02) a b c d e") leak in. Combined with accepting `)`
   as an item-number terminator (needed for ZR's `97)` style, vs every other
   subtest's `97.`), this once let WA's demo line masquerade as a second,
   overwriting "item 2" inside SE's own item set — SE item 2's real content
   ("Lawannya 'hemat'...") was silently replaced before I caught it via the
   cross-check firing on unrelated content. Fixed by bounding each section
   at its own first footer marker instead.
3. **AN/ME phantom-`e)` handling**, three variants of the same underlying
   `pdftotext` defect, each needed its own fix because the gap size and
   position differed: a general filter for phantom lines/segments preceded
   by a huge gap (≥30 chars — measured, not guessed, same approach as RMIB's
   block splitter), plus a targeted fix for ME specifically (gap as small as
   2 chars, only ever on an item's own header line, which never legitimately
   carries an option marker in this document).
4. **WA's `pypdf` 3-column shape**: genuinely different from every other
   subtest (5 lines per item — one per option letter — with 3 items
   interleaved per physical line, not "1 item per block" like the rest), so
   it has its own dedicated column-tracking parser rather than being forced
   through the generic one.

Every one of these was caught by a loud failure (`ValueError`, a
cross-check mismatch, or a missing-item assertion) — nothing here was
silently wrong output; each was investigated and fixed at the root before
moving on, not patched around.

## Random sample for spot-checking against the PDF (seed 20260921, n=12, plus 4 more for WA/ME specifically since you flagged those as priority risk areas)

| subtest | item | page | content |
|---|---|---|---|
| SE | 10 | 2 | "Pada sepatu selalu terdapat ……" → a) kulit b) sol c) tali sepatu d) gesper e) lidah |
| SE | 12 | 2 | "Mata uang logam Rp 50,- tahun 1991, garis tengahnya ialah …… mm." → a) 17 b) 29 c) 25 d) 20 e) 15 |
| SE | 19 | 2 | "Jika kita mengetahui jumlah presentase nomor-nomor lotere yang tidak menang, maka kita dapat menghitung ….." → a) jumlah nomor yang menang b) pajak lotere c) kemungkinan menang d) jumlah pengikut e) tinggi keuntungan |
| SE | 20 | 2 | "Seorang anak yang berumur 10 tahun tingginya rata-rata …… cm" → a) 150 b) 130 c) 110 d) 105 e) 115 |
| AN | 41 | 6 | "Menemukan : menghilangkan = Mengingat : ?" → a) menghapal b) mengenai c) melupakan d) berpikir e) memimpikan |
| AN | 49 | 6 | "Saraf : penyalur = Pupil : ?" → a) penyinaran b) mata c) melihat d) cahaya e) pelindung |
| GE | 62 | 8 | "mata – telinga" |
| RA | 84 | 10 | "Jika 4 ½ m bahan sandang harganya Rp 90,- berapakah rupiahkah harganya 2 ½ m?" |
| RA | 85 | 10 | "7 orang dapat menyelesaikan sesuatu pekerjaan dalam 6 hari. Berapa orangkah yang diperlukan untuk menyelesaikan pekerjaan itu dalam setengah hari?" |
| RA | 90 | 10 | "Mesin A menenun 60 m kain, sedangkan mesin B menenun 40 m. berapa meterkah yang ditenun mesin A, jika mesin B menenun 60 m?" |
| RA | 92 | 10 | "Di dalam dua peti terdapat 43 piring. Di dalam peti yang satu terdapat 9 piring lebih banyak dari pada di dalam peti yang lain. Berapa buah piring terdapat di dalam peti yang lebih kecil?" |
| ZR | 98 | 12 | "15 16 18 19 21 22 24 ?" |
| WA | 21 | 4 | a) lingkungan b) panah c) elips d) busur e) lengkungan |
| WA | 40 | 4 | a) batu b) baja c) bulu d) karet e) kayu |
| ME | 157 | 18 | "Kata yang mempunyai huruf permulaan – A – adalah ……." → a) bunga b) perkakas c) burung d) kesenian e) binatang |
| ME | 176 | 18 | "Kata yang mempunyai huruf permulaan – U – adalah ……." → a) bunga b) perkakas c) burung d) kesenian e) binatang |

## ME — draft, both word-list variants recorded (page 19)

Confirmed again on this fresh extraction, matching the original finding
exactly: the word list is printed 4 times, deduplicated to **2 distinct
variants** (not carrying 4 near-identical copies): copy 1 (printed once) has
BURUNG=...,TEKUKUR,... and KESENIAN=QUINTET,...; copies 2–4 (printed 3
times, identical to each other) have TERUKUR and QUATET. Recorded as
`word_list_variants: [{categories, printed_count}, ...]`, `printed_count`
1 and 3 respectively, summing to 4. Neither picked as "the" answer — still
waiting on the psychologist.

**One new observation, for the record, not a decision**: the ME subtest's
own *worked example* on the instructions page (page 17) explicitly uses
"Quintet" — "Quintet adalah termasuk dalam jenis kesenian, sehingga jawaban
yang benar adalah d)." This is circumstantial support for variant 1
(TEKUKUR/QUINTET), since the instructional material treats "Quintet" as the
real word being tested. Flagging this because it's genuinely new information
found during this extraction (the instructions page wasn't examined this
closely before), not because I'm treating it as resolving the question —
that's still the psychologist's call, and the majority-count reasoning
(3-of-4 copies say TERUKUR/QUATET) still cuts the other way, which is
exactly why this needs a human decision rather than either heuristic
winning by default.

ME's `status: "draft"` is unconditional in the code (not computed from any
input) — there is no path in `extract_ist_items.py` that can mark it final.

## FA/WU (images) — not in this PR

Confirmed via composite reconstruction (page placement data from the content
stream, stitched with Pillow — no PyMuPDF in the shipped path):
- **FA** (117–136): two different 5-shape answer legends, not one — legend 1
  (semicircle/circle/notch/oval/kite) covers items 117–128, legend 2
  (rectangle/triangle/square/triangle/parallelogram) covers 129–136. This
  was genuinely ambiguous from individual embedded-image crops alone; only
  resolved by reconstructing the full page and reading the legend switch
  directly off it.
- **WU** (137–156): one shared reference-cube legend (a–e) for all 20 items.

Per your requirement, FA/WU needs per-item crops (participant-facing pages
show one item at a time) plus a contact sheet for your own crop-by-crop
visual review, an automated edge-safe-crop check, and lossless
original-resolution PNGs — real additional engineering (cropping precisely
at each item's label position, reconstructed from text-label coordinates in
the same content-stream space as the images) beyond what this PR needed.
Rather than hold the 136 already-verified text items on that work, this PR
ships them now; FA/WU follows as `f0/extract-ist-fa-wu`.

## Status marker

Top-level `status: "draft"` (because ME is draft) — unconditional per the
agreed rule (draft if any subtest is draft). SE/WA/AN/GE/RA/ZR are each
individually `"status": "final"`.

## Hash gate

**Pinned**, after Lead's independent review (own PDF text extraction, all
516 stem/option strings matched after normalization, the 4 RA fraction
items re-verified by recomputing their answers against `ist.json`'s keys,
reported against commit `f8ac705`): `test_bytes_are_deterministic` in
`test_f0_ist_items.py` asserts `ist_items.json` is exactly 37509 bytes with
sha256 `7ba88a3c29a1bd91b85357967d0710ea6f6d8347e03b2f64a96379067af58061`
(`extract_aspect_sources.py`/`test_f0.py` pattern). This pin covers the
text-only shape — it will need re-pinning once FA/WU (a separate PR) are
merged in, since that changes the file's bytes.

## Tests

- `python -m unittest tools.extract.tests.test_f0_ist_items -v` — 13/13 pass.
- `python -m unittest discover -s tools/extract/tests -t . -v` — 52/52 pass
  (all prior RMIB/PAPI/base tests + these 13, no regression).

## Not touched

`ist.json`, `InstrumentSeeder.php`, `resources/js/**`, `routes/api.php`,
session controllers (including F2's new
`AssessmentItemContentAuthority`/`AssessmentItemContent` work, seen on
merge — read for context only), `ReportSigning*.php`, `tests/Frontend/**`,
`extract_kraepelin.py`, `kraepelin*.json`.
