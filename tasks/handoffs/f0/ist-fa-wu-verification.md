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

## Revision (2026-09-21): two real defects found in the coordinator's crop-by-crop review

The coordinator reviewed every crop in `c619380`'s contact sheets and found
two real defects. Both are fixed at the root (reconstruction/detection
logic), not patched per-item, and both now have an automated regression
test in addition to the coordinator's required visual re-review.

### Defect 1: a composite seam cut through FA 121-124

`_composite_page` pasted each raster band at a position and size that were
each independently rounded from that band's own x/y/width/height - so two
adjacent bands could each round consistently with *themselves* but not with
each other, since nothing tied one band's bottom edge to the next band's
top edge being the *same* number of canvas pixels. On the real FA page this
left canvas row 1026 completely unpainted (still white) between bands R69
and R70, which happened to slice straight through the item boxes in row
121-124 - confirmed by measuring dark-pixel counts row by row: count
dropped from 410-418 on the neighboring rows to exactly 0 at row 1026, for
the full width of all four boxes in that row. Row 117-120 crosses a
different band boundary (R68/R69 at canvas row 513) that happened to round
consistently in the old code, so it showed no symptom there - the bug
wasn't specific to one row, just luck about which particular boundary
rounded which way.

**Fix**: every band edge is now snapped to a canvas pixel through one
shared function of its *absolute* PDF coordinate (`px()`/`py()` in
`_composite_page`), and a band's target width/height is derived as the
difference between two such snapped edges, never from `round(w * scale)` in
isolation. Two bands that share a boundary (one's bottom edge is the next
one's top edge, literally the same PDF y-value) now always snap to the same
canvas pixel, by construction - not by coincidence.

**New test**: `_assert_no_band_seam` (in `extract_ist_fa_wu.py`) runs on
every item and legend-option crop before it's written - it raises if a
row is blank at a column while the rows immediately above and below it are
both dark at that same column, for a contiguous run of at least
`BAND_SEAM_MIN_RUN=60` px. That threshold sits well above the widest
natural gap measured in WU's line-art texture (8-12px, confirmed not to
trip it) and well below the FA defect (a 247px-wide gap). Pinned with both
a synthetic unit test (`BandSeamAndSingleFigureCheckTest`, in
`test_f0_ist_fa_wu_items.py`) and an end-to-end test that re-runs the check
against every currently-shipped PNG.

### Defect 2: WU 138's crop absorbed all of item 143's cube

`_extend_box_with_nearby_content` (used to pull each cube's number label
into its box) widens its search window below the box for as long as it
keeps finding *any* dark content near the window's far edge, up to a
400px safety cap. For most items this correctly stops once it's captured
the label and hit real whitespace (79-197px of real clearance to the next
row in every other column). Item 138 sits only 29px above item 143's own
box - the narrowest gap on the page - and isolated scan-noise specks in
that gap (confirmed: scattered single dark pixels down to grayscale value
10, not a connected shape) were enough to keep the window "finding
content" on each iteration, so it kept growing until it reached item 143's
own solid cube and merged straight into it: the shipped `wu/138.png` was
307x833px (aspect ratio 2.71) and visibly contained two cubes with two
number labels.

**Fix**: `_extend_box_with_nearby_content` now accepts a `hard_limit_y1`
that the search window can never cross. `_build_wu` computes it per item as
the next row's own raw-detected box top (same column) minus a 4px margin -
information already available from `_detect_boxes`' row grouping, not a
guess. This makes the specific failure structurally impossible: the window
can no longer reach into a neighboring item's own territory regardless of
what noise sits in the gap between them. Re-running extraction with this
fix: `wu/138.png` is now 295x456 (ratio 1.55), and `wu/143.png` is
unaffected (300x415, ratio 1.38) since it never depended on 138's box.

**New test**: `_assert_single_figure` runs on every item crop (not legend
options, which are legitimately irregular shapes) and raises on either of
two signals - (1) aspect ratio beyond `MAX_ITEM_ASPECT_RATIO=2.0` (every
real crop across both subtests measures 1.13-1.55; the old 138 defect was
2.71), or (2) more than one connected-component group inside the crop
separated by a gap wider than 12% of the crop's own height (a defense in
depth for a merge that didn't happen to distort the aspect ratio as
severely). Pinned three ways: a synthetic unit test for each signal
independently, an end-to-end test re-running the check against every
shipped item crop, and a regression test that replays the *exact* old
`wu/138.png` bytes (read back from commit `c619380`) through the aspect
ratio check to confirm it would have failed loudly before this fix.

### Side effect: other crops shifted by ~1px

Because the seam fix changes how every band boundary rounds (not just the
one that produced a visible defect), item boxes whose detection sits near
*any* band boundary on either page shifted by roughly a pixel, so their
crops were regenerated too even though they had no visible defect before:
FA 117-124 and 129-132 (near FA's band boundaries), WU 147-156 (near a WU
band boundary). None of these fail either new check or the existing
truncation check. **Both contact sheets are regenerated in full** and need
the coordinator's crop-by-crop review from scratch, per their request,
since a reconstruction-level fix can shift crops beyond the two rows they
originally flagged.

### Tests after this revision

`python -m unittest discover -s tools/extract/tests -t . -v` - 67/67 pass
(60 from before this revision + 7 new: 2 end-to-end regression checks in
`IstFaWuItemsGateTest`, 5 synthetic unit tests in the new
`BandSeamAndSingleFigureCheckTest`).
