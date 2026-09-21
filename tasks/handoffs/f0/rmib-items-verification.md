# RMIB item-content extraction — verification report (2026-09-21)

For: Lead review of `database/seeders/data/rmib_items.json`, produced by
`tools/extract/extract_rmib_items.py` on branch `f0/extract-rmib-items`.

## Sources

- xlsx: `RMIB_Master_Formula_Skoring.xlsx` (sheet `Input Jawaban`), at
  `D:\LSI\Psikotes\PSIKOTEST LSI\PSIKOTEST\` — not committed.
- PDF: `soal _ Ljk RMIB (1).pdf` (2 pages), from the project owner's handoff — not
  committed.
- `database/seeders/data/rmib.json` (existing, unmodified) — used only as the
  reference for the rotation-formula cross-check, never written to.

## Counts

- 108 (group, position) pairs extracted from both xlsx and PDF page 1 — matches exactly.
- 9 groups × 12 positions each, group letters A–I, numeric groups 1–9.
- Instructions: 1 paragraph (`text`, PDF page 2) + 1 short prompt
  (`write_preferred_jobs_prompt`, PDF page 2, printed above the main paragraph next
  to the "(1)/(2)/(3) preferred jobs" write-in blanks).

## Cross-checks (all pass; extractor raises and aborts on any mismatch)

1. **xlsx vs PDF, all 108 positions, both L/P columns**: exact string match after
   whitespace normalization. Zero mismatches in the final version.
2. **xlsx Kategori vs `rmib.json` rotation formula**: the group-letter → numeric-group
   mapping (A→1, B→2, … I→9) was *derived*, not assumed — for each letter, its 12
   (position, category) pairs from the xlsx were matched against every candidate
   numeric group 1–9 in `rmib.json`'s `rotation`, requiring exactly one match. All 9
   letters matched uniquely and alphabetically, confirming (not assuming) A=1…I=9.
3. **No scoring/answer-key leakage**: `rmib_items.json` positions carry only
   `group`, `group_letter`, `position`, `job_male`, `job_female` — no `category`,
   rank, or score fields (those stay in the existing `rmib.json`).

## Extraction method note (why `pypdf` layout mode, not raw text-object coordinates)

First attempt used `pypdf`'s per-text-object `visitor_text` callback with manual
x/y-coordinate column bucketing (needed because the PDF prints 9 job groups
3-per-row in side-by-side columns). That surfaced two real defects worth recording:

- A whitespace-only text fragment between two kerning-split character runs
  ("Art" + "i" + "s" + " " + "profesional") was being silently dropped by an
  over-eager `if text.strip():` filter, corrupting one cell to "Artisprofesional".
- Once fixed, a **second-order** issue appeared: some justified-text lines on the
  instructions page encode extra space characters *inside* a fragment string at
  points that don't correspond to a real visual gap (confirmed by rendering the
  page to an image — "terhadap" and "tersedia" are printed with no gap; the PDF's
  text layer alone said otherwise).

Switching to `pypdf.extract_text(extraction_mode="layout")` — which reconstructs
a character-grid from actual glyph metrics instead of concatenating raw
text-showing strings — resolved both issues at once and is simpler than the
manual coordinate approach, so the shipped extractor uses it throughout. This
stayed within `pypdf` (no PyMuPDF in the shipped tool or `requirements.txt`).

**One temporary exception, disclosed for transparency:** while chasing the second
defect above, I temporarily `pip install`ed PyMuPDF in my own local environment
*only* to render two small page crops as an independent visual check (not
`pypdf`-derived) — never added to `tools/extract/requirements.txt`, never imported
by the shipped extractor, not committed. Flagging this since Lead's plan approval
specifically asked to keep PyMuPDF out of the shipped tool; the one-off personal
verification use felt within that spirit but is the kind of thing you should get to
decide, not me.

## Random sample for spot-checking against the PDF (seed 20260921, n=10)

All from PDF **page 1** (the job grid; page 2 is instructions-only).

| group | letter | position | job (Laki-laki) | job (Perempuan) |
|---|---|---|---|---|
| 1 | A | 5  | Manager penjualan | Penjual hasil-hasil mode |
| 1 | A | 6  | Seniman | Seniwati |
| 1 | A | 10 | Manager bank | Sekretaris pribadi |
| 2 | B | 9  | Sekretaris perusahaan | Juru ketik |
| 3 | C | 1  | Auditor | Auditor |
| 3 | C | 7  | Kepala sekolah | Kepala yayasan sosial |
| 4 | D | 9  | Apoteker | Apoteker |
| 5 | E | 1  | Petugas wawancara | Petugas wawancara |
| 8 | H | 8  | Juru bayar | Juru bayar |
| 9 | I | 10 | Perancang motif tekstil | Perancang motif tekstil |

Layout reference for locating these visually: page 1 is laid out as 3 row-bands
top→bottom, each band showing 3 groups side-by-side left→right (band 1: A, D, G;
band 2: B, E, H; band 3: C, F, I), each group column showing its 12 positions
top→bottom.

## Instructions text (full, for review)

> "Dibawah ini anda akan menemui daftar-daftar berbagai macam pekerjaan yang
> tersusun dalam berbagai kelompok. Setiap kelompok terdiri dari 12 macam
> pekerjaan. Setiap pekerjaan merupakan keahlian khusus yang memerlukan latihan
> atau pendidikan keahlian sendiri. Mungkin hanya beberapa diantaranya yang anda
> sukai. Disini anda diminta untuk memilih pekerjaan mana yang ingin anda lakukan
> atau pekerjaan mana yang anda sukai, terlepas dari besarnya upah gaji yang akan
> anda terima. Juga terlepas apakah anda berhasil atau tidak dalam mengerjakan
> pekerjaan tersebut. Tugas anda adalah mencantumkan nomor atau angka pada tiap
> pekerjaan dalam kelompok-kelompok yang tersedia. Berikanlah nomor (angka) 1
> untuk pekerjaan yang paling anda sukai diantara ke 12 pekerjaan yang tersedia
> pada setiap kelompok, dan dilanjutkan dengan pemberian nomor 2, 3, dan
> seterusnya berurutan berdasarkan besarnya kadar kesukaan/minat anda terhadap
> pekerjaan itu, dan nomor/angka 12 anda cantumkan untuk pekerjaan yang paling
> tidak disukai dari daftar pekerjaan yang tersedia pada kelompok-kelompok
> tersebut. Bekerjalah secepatnya dan tulislah nomor-nomor (angka-angka) sesuai
> dengan kesan dan keinginan anda yang pertama muncul. Jika anda Perempuan
> gunakanlah daftar pekerjaan yang tersusun di bagian kanan pada setiap kelompok.
> Jika anda Laki-laki, gunakanlah daftar pekerjaan yang tersusun di bagian kiri
> pada setiap kelompok. Selamat bekerja !"

`write_preferred_jobs_prompt`: "Tulislah dibawah ini tiga (3) macam pekerjaan yang
paling ingin anda lakukan atau paling anda sukai (tidak harus pekerjaan yang
tercantum di dalam daftar yang ada):"

## Status marker

`rmib_items.json` top-level `"status": "final"` — both cross-checks passed with
zero mismatches, so nothing forced a `"draft"` state (unlike IST's ME word list,
which is expected to land `"draft"` in the IST PR).

## Hash gate

Not yet pinned. Per your review note: the byte-hash pin
(`extract_aspect_sources.py`/`test_f0.py` pattern) will be added in a follow-up
commit on this branch once you've reviewed this content — `test_f0_rmib_items.py`
currently covers structural invariants only (108 unique pairs, letter/group
consistency, no scoring-field leakage, nonempty text) and says so in its class
docstring.

## Tests

- `python -m unittest tools.extract.tests.test_f0_rmib_items -v` — 8/8 pass.
- `python -m unittest tools.extract.tests.test_f0 -v` — 20/20 pass (no regression
  in the existing F0 suite).

## Not touched

`InstrumentSeeder.php`, `resources/js/**`, `routes/api.php`, session controllers,
`ReportSigning*.php`, `tests/Frontend/**`, `extract_kraepelin.py`, `kraepelin*.json`.
