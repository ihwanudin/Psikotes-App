# IST FA/WU item-content extraction — verification report (2026-09-21)

For: Lead's required visual review of every FA/WU crop against the contact
sheets, before FA/WU can be marked `status: "final"`.

**Contact sheets** (send these paths to Lead):
- `tasks/handoffs/f0/ist-fa-contact-sheet.png` — full FA page (117–136),
  every detected item box outlined in red and labeled with its item number,
  both legends outlined in blue and labeled a–e.
- `tasks/handoffs/f0/ist-wu-contact-sheet.png` — same for WU (137–156) and
  its one shared legend.

## Source

PDF: `Alat Tes IST (1).pdf`, FA on page 14 (1-indexed), WU on page 16 — not
committed. `database/seeders/data/ist_items.json` (text subtests, from
PR #66, already merged to `main`) had FA/WU merged into it by
`extract_ist_fa_wu.py`; the 136 text items are untouched.

## Why this needed real engineering, not just more of the same

Both pages are almost pure image — `page.extract_text()` returns only
header/footer text, confirmed before building anything. `pypdf.page.images`
returns only 6 large images per page (not 20+), because the source PDF
stores each items page as several horizontal raster bands, and the band
cuts don't align with item boundaries — individual item boxes are visibly
split across two consecutive band images (same class of surprise as RMIB's
3-column layout, different shape). Fixed by pulling each image's exact
placement from the content stream and recompositing into one seamless page
image (Pillow) — confirmed pixel-perfect contiguous, no gaps or overlaps.

From there, per-item cropping needed connected-component analysis
(`scipy.ndimage`), tried and rejected three approaches before landing on one
that reliably produces correct crops (each step's failure and why, so this
isn't just a list of things that happened to work):

1. **Uniform grid division** (divide width by 4/5 columns) — rejected: the
   resulting "content bounding box" per column landed exactly on the
   arbitrary grid line, a strong sign of clipping real content rather than
   a coincidence.
2. **Gap-based clustering of all components by x-position** — rejected for
   WU's legend: scan noise and small design-dot marks (~25×25px,
   consistently sized — too big to be pure noise, too small to be a real
   figure) confused the gap-finding, once merging two real cubes into one
   "cluster" and once giving a lone dot its own cluster.
3. **Size-based selection** (the `expected_count` largest components, with
   a required order-of-magnitude gap to the next-largest) — this is what's
   shipped. FA's item border boxes and WU's cube bodies are both
   unambiguous by size once you look at the actual distribution: FA's 20
   border boxes cluster within ~7% of each other in area, then drop to
   ~73% of the smallest kept size for the next-biggest non-border shape;
   WU's 20 cube bodies vary more (different rotations), but the 21st-ranked
   component is ~9% the size of the 20th. A single relaxed threshold (0.85)
   covers both without accepting a false positive on either page.

A second, separate problem: WU's item-number labels are their own
unmerged component (individual digit strokes too small and separated to
survive dilation into the cube's own blob), and a first fixed-margin
attempt cut two different labels off in two different ways before landing
on an iterative widen-until-the-label-stops-touching-the-search-window's-
edge approach (`_extend_box_with_nearby_content`).

Also found and fixed: the automated truncation check itself was initially
too strict — it failed one real, complete crop over a single isolated
JPEG-noise pixel touching one corner (not a truncated figure at all).
Fixed to require a run of several consecutive dark pixels along an edge,
which a real cut always produces and a lone speck never does.

## FA legend split: 117–128 / 129–136, verified on the reconstructed page

**Evidence, not assumption**: the two legends are visually and structurally
different — legend 1 is semicircle/circle/notch/oval/kite, legend 2 is
rectangle/triangle/square/triangle/parallelogram. Only found by
reconstructing the whole page composite and reading the legend switch
directly off it; individual embedded-image crops alone were ambiguous (see
the earlier plan message to Lead). `legend_id` is recorded explicitly per
item in the shipped data — `FA-L1` for 117–128, `FA-L2` for 129–136 — with a
dedicated test (`test_fa_legend_id_matches_the_verified_117_128_129_136_split`)
pinning that exact split so a future accidental change would fail loudly.

## Cheap automated check (Lead's requirement, in addition to — not instead of — visual review)

`_assert_no_truncated_content`: no run of ≥4 consecutive dark pixels may
touch any crop's outer edge. Runs on every one of the 40 item crops + 15
legend-option crops (10 for FA's two legends, 5 for WU's one) before it's
written to disk — a crop that fails this never reaches the output at all,
the extractor raises instead. All 55 image assets currently in the output
passed.

## Resolution

Lossless PNG, cropped directly from the reconstructed page composite at its
native resolution (the compositing scale factor, 4.1655 px/pt, was measured
from the source images' own actual pixel density — `1787px / 429.0pt` and
`513px / 123.2pt` both give ~4.165 — not chosen arbitrarily, so stitching
introduces no real up/downscaling). No resizing anywhere in this path.

## Asset layout

`database/seeders/data/assets/ist/fa/{117..136}.png`,
`.../fa/legend-{1,2}-{a..e}.png`, `.../wu/{137..156}.png`,
`.../wu/legend-{a..e}.png` — referenced by relative path from
`ist_items.json`, same proposal as RMIB/PAPI's asset convention. F2/GLM
decide final storage/serving (Lead already confirmed this in the original
plan review).

## Status marker

`FA` and `WU` both `status: "draft"` with a `draft_reason` pointing at this
review requirement — unconditional in the code, same pattern as ME. Top-level
`ist_items.json` `status` stays `"draft"` (already true from ME; FA/WU
joining doesn't change that, but would keep it `"draft"` on their own even
if ME were resolved).

## Tests

- `python -m unittest tools.extract.tests.test_f0_ist_fa_wu_items -v` —
  10/10 pass (item counts, legend_id split, asset existence + valid PNG
  format, no scoring-field leakage, contact sheets exist).
- `python -m unittest tools.extract.tests.test_f0_ist_items -v` — 11/11
  pass (updated for FA/WU now being present as drafts; no text-subtest
  content touched by this PR).
- `python -m unittest discover -s tools/extract/tests -t . -v` — 60/60
  pass, no regression. **CI is currently down repo-wide on a GitHub billing
  issue (Lead's notice, unrelated to this code) — pasting this local output
  in the PR per Lead's instruction; merge held until CI recovers.**

## Not touched

Text subtests (SE/WA/AN/GE/RA/ZR/ME) in `ist_items.json` - byte-identical
to what PR #66 merged, only FA/WU keys added. `ist.json`,
`InstrumentSeeder.php`, `resources/js/**`, `routes/api.php`, F2's session/
item-delivery controllers, `ReportSigning*.php`, `tests/Frontend/**`,
`extract_kraepelin.py`, `kraepelin*.json`.
