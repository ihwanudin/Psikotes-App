"""Extract IST FA/WU (image subtests) item content: per-item figure crops,
answer-option legend crops, and a contact sheet for the coordinator's
required visual review - see tasks/handoffs/f0/ist-fa-wu-verification.md.

Both subtests' items pages are almost pure image (no extractable text layer
for item numbers/figures - confirmed via `page.extract_text()` returning
only header/footer text). `pypdf.page.images` returns only a handful of
large images per page, because the source PDF stores each items page as
several horizontal raster bands (not one image per item), and the band cuts
don't align with item boundaries - individual item boxes are visibly split
across two consecutive band images. Fixed the same way as the RMIB/PAPI
sessions handled their own layout surprises: pulled each image's exact
placement (position + size) from the page's content stream, confirmed the
bands are pixel-perfect contiguous at a consistent px/pt scale, and
recomposited them into one seamless page image with Pillow (`pypdf` for
extraction + placement data, Pillow for compositing, `scipy.ndimage` for
connected-component analysis - no PyMuPDF anywhere in this shipped path).

Per-item cropping, from the reconstructed composite:
  - FA: every item is drawn inside a rectangular border box - the box's
    outline is the largest, most regular connected component in that row,
    so it's detected directly (`_detect_boxes`) rather than guessed from a
    uniform grid division (tried first; produced content bounding boxes that
    landed exactly on the naive quarter-grid boundary, a strong signal of a
    clipped crop rather than a coincidence - see the PR's report). FA's two
    answer-option legends have no border box (free-floating shapes with a
    letter label below) - detected with `_detect_free_shapes` instead,
    bounded to the page region above where the item boxes start.
  - WU has no border box on the items either (just a cube figure with an
    item-number label below it, irregular bounding shapes from the
    isometric drawing) - items are clustered into 5 evenly-spaced columns
    per row (`_detect_free_shapes` again, since it's the same "no border,
    cluster by column" problem as FA's legends).

Every crop is checked for a truncated figure before it's trusted: no
connected dark-pixel region may touch the crop's outer edge (an inexpensive
first-pass catch for a box that got cut, ahead of the coordinator's own
visual review of the contact sheet - required, not a substitute for it).
Crops are saved as lossless PNG at the source's native resolution - never
upscaled or downscaled; any resizing for a smaller screen is presentation,
left to F2/GLM, not this data.

FA's answer-option legend changes partway through the 20 items - confirmed,
not assumed, by reading the reconstructed page composite directly: legend 1
(semicircle/circle/notch/oval/kite) covers items 117-128, legend 2
(rectangle/triangle/square/triangle/parallelogram) covers 129-136. Each
item's `legend_id` records which.

Output stays `status: "draft"` unconditionally in this module - flipped to
"final" only by the coordinator after reviewing the contact sheet
crop-by-crop against the source PDF, per their explicit requirement.
"""

import json
from pathlib import Path

import numpy as np
from PIL import Image, ImageDraw
from pypdf import PdfReader
from scipy import ndimage

from .common import OUTPUT

VERSION_SUFFIX = "IST-FA-WU-2026.09"
DARK_THRESHOLD = 150
DILATE_ITERATIONS = 2
CROP_MARGIN = 6
ASSET_ROOT = Path("assets") / "ist"

FA_PAGE_INDEX = 13  # 0-based
WU_PAGE_INDEX = 15
FA_START, FA_COUNT = 117, 20
WU_START, WU_COUNT = 137, 20
FA_ITEMS_PER_ROW = 4
WU_ITEMS_PER_ROW = 5
FA_LEGEND_1_ITEMS = range(117, 129)  # 117-128
FA_LEGEND_2_ITEMS = range(129, 137)  # 129-136


# ---------------------------------------------------------------------------
# Page composite reconstruction (placement-coordinate stitching)
# ---------------------------------------------------------------------------

def _page_placements(page):
    placements = []

    def visitor_op(op, args, cm, tm):
        if op == b"Do":
            placements.append((str(args[0]).lstrip("/"), cm[4], cm[5], cm[0], cm[3]))

    page.extract_text(visitor_operand_before=visitor_op)
    return placements


def _composite_page(page, scale=4.1655):
    placements = _page_placements(page)
    images_by_name = {img.name.split(".")[0]: img.image for img in page.images}

    min_x = min(p[1] for p in placements)
    max_x = max(p[1] + p[3] for p in placements)
    min_y = min(p[2] for p in placements)
    max_y = max(p[2] + p[4] for p in placements)

    canvas_w = int(round((max_x - min_x) * scale)) + 4
    canvas_h = int(round((max_y - min_y) * scale)) + 4
    canvas = Image.new("RGB", (canvas_w, canvas_h), "white")

    for name, x, y, w, h in placements:
        if name not in images_by_name:
            raise ValueError(f"Placement {name!r} has no matching embedded image")
        img = images_by_name[name]
        px = int(round((x - min_x) * scale))
        py = int(round((max_y - (y + h)) * scale))  # PDF y is bottom-up; canvas is top-down
        target_w = int(round(w * scale))
        target_h = int(round(h * scale))
        canvas.paste(img.resize((target_w, target_h)), (px, py))

    return canvas


# ---------------------------------------------------------------------------
# Connected-component region detection
# ---------------------------------------------------------------------------

def _dark_mask(image):
    gray = np.array(image.convert("L"))
    return gray < DARK_THRESHOLD


def _components(mask, dilate_iterations=DILATE_ITERATIONS):
    """Dilates first so nearby strokes of one figure (or a figure and its
    label) merge into a single component - scanned line art often has small
    gaps between strokes. Returns (x0, y0, x1, y1) boxes, largest area first."""
    dilated = ndimage.binary_dilation(mask, structure=np.ones((5, 5)), iterations=dilate_iterations)
    labeled, count = ndimage.label(dilated)
    boxes = []
    for index, region in enumerate(ndimage.find_objects(labeled), start=1):
        if region is None:
            continue
        ys, xs = region
        boxes.append((xs.start, ys.start, xs.stop, ys.stop))
    boxes.sort(key=lambda b: (b[2] - b[0]) * (b[3] - b[1]), reverse=True)
    return boxes


def _detect_boxes(image, y_range, expected_count, expected_per_row, dilate_iterations=DILATE_ITERATIONS):
    """FA's item border boxes, or WU's item cubes: the `expected_count`
    largest components within `y_range`, forming `expected_per_row`-wide
    rows - found across the *whole* region rather than pre-splitting it into
    guessed row bands first (tried for WU: a naive `remaining_height /
    row_count` division doesn't land exactly on the real row boundaries, and
    a cube sitting across that guessed boundary got clipped). Fails loudly
    if the page layout doesn't match this shape.

    Selection is "top N by area, with a clean size gap to the rest" (not a
    tolerance around the median - tried first for WU and rejected several
    real cubes whose bounding-box area, while still clearly the largest 20
    on the page, varied more than a tight percentage band allowed, since
    they're drawn in perspective at different rotations). The real figures
    are unambiguous by size alone: on both pages there's an order-of-
    magnitude drop between the Nth and (N+1)th largest component - if that
    gap isn't there, something about the page doesn't match expectations and
    this raises rather than guessing which are real."""
    mask = _dark_mask(image)
    y0, y1 = y_range
    mask_slice = np.zeros_like(mask)
    mask_slice[y0:y1] = mask[y0:y1]
    boxes = _components(mask_slice, dilate_iterations=dilate_iterations)
    if len(boxes) < expected_count:
        raise ValueError(f"Only {len(boxes)} component(s) in y={y_range}, expected >= {expected_count}")

    def area(b):
        return (b[2] - b[0]) * (b[3] - b[1])

    candidates = boxes[:expected_count]
    smallest_kept = area(candidates[-1])
    largest_next = area(boxes[expected_count]) if len(boxes) > expected_count else 0
    # Measured on both real pages: FA's gap gives a ratio of ~0.73 (border
    # boxes are all within ~7% of each other, but the next-largest
    # non-border blob is still fairly big), WU's gives ~0.09 (cube bodies
    # vary more, but the next-largest speck is tiny). 0.85 comfortably
    # covers both while still catching a genuinely ambiguous page.
    if largest_next > smallest_kept * 0.85:
        raise ValueError(
            f"No clear size gap between the {expected_count} largest components and the rest in "
            f"y={y_range} (smallest kept area={smallest_kept}, next area={largest_next}) - "
            f"ambiguous which components are the real figures"
        )

    rows = expected_count // expected_per_row
    candidates.sort(key=lambda b: b[1])  # top to bottom
    ordered = []
    for row in range(rows):
        row_boxes = candidates[row * expected_per_row:(row + 1) * expected_per_row]
        row_boxes.sort(key=lambda b: b[0])  # left to right
        ordered.extend(row_boxes)
    return ordered


def _detect_free_shapes(image, y_range, x_range, expected_count, dilate_iterations=6):
    """FA's legends / WU's items+legend: no border box, so the figure itself
    is found directly as the `expected_count` largest, similarly-sized
    components (same size-filtering principle as `_detect_boxes`, minus the
    row grouping) - not by clustering *all* components by position. That was
    tried first and broke on WU's legend: scan noise/small design dots
    (consistently ~25x25px - too big to drop as speckle, too small to be a
    real figure) confused both a fixed-width column split and a
    largest-gap-based one, sometimes merging two real cubes into one
    "cluster" and sometimes giving a lone dot its own cluster. The real cube
    bodies are unambiguous once you look at component *size*: ~77,000-88,000
    px^2 each, an order of magnitude larger than every dot/label fragment -
    see the PR's report for the full component dump that showed this.

    Uses more dilation than `_detect_boxes`' default (some WU cubes are thin
    wireframe/checkerboard line art, not solid fills, and its strokes don't
    bridge into one component at the default setting - found via one legend
    cube whose outline fragmented into pieces too small to pass the size
    filter otherwise). Safe here because these regions (legend cubes, WU
    item cells) are hundreds of pixels apart - nowhere near FA's item boxes,
    which sit only ~10-14px apart and use the tighter default specifically
    so adjacent boxes never merge."""
    mask = _dark_mask(image)
    y0, y1 = y_range
    x0, x1 = x_range
    mask_slice = np.zeros_like(mask)
    mask_slice[y0:y1, x0:x1] = mask[y0:y1, x0:x1]
    boxes = _components(mask_slice, dilate_iterations=dilate_iterations)
    if len(boxes) < expected_count:
        raise ValueError(f"Only {len(boxes)} component(s) in x={x_range} y={y_range}, expected >= {expected_count}")

    top = boxes[:expected_count]  # _components already sorts by area, largest first
    areas = [(b[2] - b[0]) * (b[3] - b[1]) for b in top]
    smallest_kept, largest_next = areas[-1], (boxes[expected_count][2] - boxes[expected_count][0]) * (boxes[expected_count][3] - boxes[expected_count][1]) if len(boxes) > expected_count else 0
    if largest_next > smallest_kept * 0.85:  # see _detect_boxes' comment on this threshold
        raise ValueError(
            f"No clear size gap between the {expected_count} largest components and the rest in "
            f"x={x_range} y={y_range} (smallest kept area={smallest_kept}, next area={largest_next}) - "
            f"ambiguous which components are the real figures"
        )

    top.sort(key=lambda b: (b[0] + b[2]) / 2)  # left to right by center
    return top


def _extend_box_with_nearby_content(image, box, initial_below=90, sides=30, max_below=400, step=80):
    """WU's item number label sits just below its cube as its own,
    unmerged component (individual digit strokes, ~9x9px each - far too
    small to survive dilation into the cube's own component, or to pass any
    reasonable size filter as a "figure" in its own right). Rather than
    guess a margin generous enough to *probably* include it (tried, and a
    fixed 90px window still cut a label off partway through - see the PR's
    report), search the band directly below the box and keep widening it
    while the found content still touches the search window's own bottom
    edge (a sign there's more below that the window didn't reach), stopping
    once there's a real gap or `max_below` is hit."""
    x0, y0, x1, y1 = box
    w, h = image.size
    search_x0, search_x1 = max(0, x0 - sides), min(w, x1 + sides)
    mask = _dark_mask(image)

    below = initial_below
    while True:
        search_y1 = min(h, y1 + below)
        region = mask[y1:search_y1, search_x0:search_x1]
        ys, xs = np.where(region)
        if len(xs) == 0:
            return box  # no label found nearby - leave the box as detected
        touches_far_edge = ys.max() >= (search_y1 - y1) - 1
        if not touches_far_edge or below >= max_below or search_y1 >= h:
            return (
                min(x0, search_x0 + xs.min()), y0,
                max(x1, search_x0 + xs.max()), y1 + ys.max(),
            )
        below += step


# ---------------------------------------------------------------------------
# Cropping + safety check
# ---------------------------------------------------------------------------

def _crop_with_margin(image, box, margin=CROP_MARGIN):
    x0, y0, x1, y1 = box
    w, h = image.size
    return image.crop((max(0, x0 - margin), max(0, y0 - margin), min(w, x1 + margin), min(h, y1 + margin)))


def _assert_no_truncated_content(crop, label, min_run=4):
    """Cheap first-pass check: no *connected* dark region may touch the
    crop's outer edge - a figure that got cut by a wrong boundary always
    leaves a run of several consecutive dark pixels along that edge. Checks
    for a run of `min_run` (not "any dark pixel at all" - found a real crop
    failing on a single isolated scan-noise pixel touching one corner, not
    an actual truncation; a real cut line is never just one pixel). Not a
    substitute for the coordinator's own visual review."""
    mask = _dark_mask(crop)

    def has_run(line):
        run = 0
        for value in line:
            run = run + 1 if value else 0
            if run >= min_run:
                return True
        return False

    edges = (mask[0, :], mask[-1, :], mask[:, 0], mask[:, -1])
    if any(has_run(edge) for edge in edges):
        raise ValueError(f"{label}: a dark region touches the crop edge - likely a truncated figure")


# ---------------------------------------------------------------------------
# FA
# ---------------------------------------------------------------------------

def _build_fa(page):
    composite = _composite_page(page)
    w, h = composite.size

    item_boxes = _detect_boxes(composite, y_range=(0, h), expected_count=FA_COUNT, expected_per_row=FA_ITEMS_PER_ROW)
    # Legend shapes sit above where the item-box rows start; item_boxes are
    # already sorted row-major top to bottom, so the first row's top y is the
    # boundary. Both legends are detected the same way, in their own known
    # bands (legend 1 above row 1 of items 117-128; legend 2 in the gap
    # between the 117-128 block and the 129-136 block).
    first_item_top = min(b[1] for b in item_boxes)
    legend_1_boxes = _detect_free_shapes(composite, y_range=(0, first_item_top), x_range=(0, w), expected_count=5)

    # Legend 2 sits in the vertical gap between the two item blocks - find it
    # by locating the y-band with no item-box content between the last box
    # of the first block and the first of the second.
    legend_1_item_boxes = item_boxes[:len(FA_LEGEND_1_ITEMS)]
    legend_2_item_boxes = item_boxes[len(FA_LEGEND_1_ITEMS):]
    gap_top = max(b[3] for b in legend_1_item_boxes)
    gap_bottom = min(b[1] for b in legend_2_item_boxes)
    if gap_bottom <= gap_top:
        raise ValueError("No vertical gap found between FA's two legend blocks")
    legend_2_boxes = _detect_free_shapes(composite, y_range=(gap_top, gap_bottom), x_range=(0, w), expected_count=5)

    letters = "abcde"
    legend_1_crops = {letters[i]: _crop_with_margin(composite, b) for i, b in enumerate(legend_1_boxes)}
    legend_2_crops = {letters[i]: _crop_with_margin(composite, b) for i, b in enumerate(legend_2_boxes)}
    for letter, crop in {**legend_1_crops, **legend_2_crops}.items():
        _assert_no_truncated_content(crop, f"FA legend option {letter}")

    items = []
    item_crops = {}
    for offset, box in enumerate(item_boxes):
        item_no = FA_START + offset
        crop = _crop_with_margin(composite, box)
        _assert_no_truncated_content(crop, f"FA item {item_no}")
        item_crops[item_no] = crop
        legend_id = "FA-L1" if item_no in FA_LEGEND_1_ITEMS else "FA-L2"
        path = ASSET_ROOT / "fa" / f"{item_no}.png"
        items.append({"item": item_no, "image": str(path).replace("\\", "/"), "legend_id": legend_id})

    option_legends = [
        {
            "legend_id": "FA-L1",
            "items": list(FA_LEGEND_1_ITEMS),
            "options": {letter: str(ASSET_ROOT / "fa" / f"legend-1-{letter}.png").replace("\\", "/") for letter in letters},
        },
        {
            "legend_id": "FA-L2",
            "items": list(FA_LEGEND_2_ITEMS),
            "options": {letter: str(ASSET_ROOT / "fa" / f"legend-2-{letter}.png").replace("\\", "/") for letter in letters},
        },
    ]

    assets = {}
    for item_no, crop in item_crops.items():
        assets[ASSET_ROOT / "fa" / f"{item_no}.png"] = crop
    for letter, crop in legend_1_crops.items():
        assets[ASSET_ROOT / "fa" / f"legend-1-{letter}.png"] = crop
    for letter, crop in legend_2_crops.items():
        assets[ASSET_ROOT / "fa" / f"legend-2-{letter}.png"] = crop

    entry = {
        "status": "draft",
        "draft_reason": "Per-item crops await the coordinator's visual review against the contact sheet (required before FA/WU can be marked final).",
        "answer_type": "image_choice",
        "option_legends": option_legends,
        "items": items,
    }
    return entry, assets, {"item_boxes": item_boxes, "legend_1_boxes": legend_1_boxes, "legend_2_boxes": legend_2_boxes, "composite": composite}


# ---------------------------------------------------------------------------
# WU
# ---------------------------------------------------------------------------

def _build_wu(page):
    composite = _composite_page(page)
    w, h = composite.size

    # Legend (5 reference cubes + their a-e labels) sits in the top band;
    # items are 4 rows of 5 below it (no border box anywhere on this page -
    # legend via size-filtering same as FA's legends; items via the same
    # size+row-grouping algorithm as FA's border boxes, since pre-dividing
    # into guessed row bands clipped a cube sitting across a guessed
    # boundary - see _detect_boxes' docstring).
    legend_boxes = _detect_free_shapes(composite, y_range=(0, int(h * 0.22)), x_range=(0, w), expected_count=5)
    legend_boxes = [_extend_box_with_nearby_content(composite, b) for b in legend_boxes]
    items_top = max(b[3] for b in legend_boxes)

    item_boxes = _detect_boxes(
        composite, y_range=(items_top, h), expected_count=WU_COUNT,
        expected_per_row=WU_ITEMS_PER_ROW, dilate_iterations=6,
    )
    item_boxes = [_extend_box_with_nearby_content(composite, b) for b in item_boxes]

    letters = "abcde"
    legend_crops = {letters[i]: _crop_with_margin(composite, b) for i, b in enumerate(legend_boxes)}
    for letter, crop in legend_crops.items():
        _assert_no_truncated_content(crop, f"WU legend option {letter}")

    items = []
    item_crops = {}
    for offset, box in enumerate(item_boxes):
        item_no = WU_START + offset
        crop = _crop_with_margin(composite, box)
        _assert_no_truncated_content(crop, f"WU item {item_no}")
        item_crops[item_no] = crop
        path = ASSET_ROOT / "wu" / f"{item_no}.png"
        items.append({"item": item_no, "image": str(path).replace("\\", "/")})

    option_legend = {
        "options": {letter: str(ASSET_ROOT / "wu" / f"legend-{letter}.png").replace("\\", "/") for letter in letters},
    }

    assets = {}
    for item_no, crop in item_crops.items():
        assets[ASSET_ROOT / "wu" / f"{item_no}.png"] = crop
    for letter, crop in legend_crops.items():
        assets[ASSET_ROOT / "wu" / f"legend-{letter}.png"] = crop

    entry = {
        "status": "draft",
        "draft_reason": "Per-item crops await the coordinator's visual review against the contact sheet (required before FA/WU can be marked final).",
        "answer_type": "image_choice",
        "option_legend": option_legend,
        "items": items,
    }
    return entry, assets, {"item_boxes": item_boxes, "legend_boxes": legend_boxes, "composite": composite}


# ---------------------------------------------------------------------------
# Contact sheet
# ---------------------------------------------------------------------------

def _contact_sheet(composite, item_boxes, legend_boxes_list, start_item, title):
    """Full composite with every detected box outlined in red and labeled,
    so the coordinator can check all crops against the source in one image
    rather than opening 20+ separate files."""
    sheet = composite.convert("RGB").copy()
    draw = ImageDraw.Draw(sheet)
    for offset, box in enumerate(item_boxes):
        item_no = start_item + offset
        x0, y0, x1, y1 = box
        draw.rectangle([x0, y0, x1, y1], outline=(255, 0, 0), width=3)
        draw.text((x0 + 4, max(0, y0 - 18)), str(item_no), fill=(255, 0, 0))
    for legend_boxes in legend_boxes_list:
        for letter, box in zip("abcde", legend_boxes):
            x0, y0, x1, y1 = box
            draw.rectangle([x0, y0, x1, y1], outline=(0, 0, 255), width=3)
            draw.text((x0 + 4, max(0, y0 - 18)), letter, fill=(0, 0, 255))
    draw.text((10, 10), title, fill=(0, 128, 0))
    return sheet


# ---------------------------------------------------------------------------
# Top level
# ---------------------------------------------------------------------------

def build(pdf_path):
    reader = PdfReader(pdf_path)
    fa_entry, fa_assets, fa_debug = _build_fa(reader.pages[FA_PAGE_INDEX])
    wu_entry, wu_assets, wu_debug = _build_wu(reader.pages[WU_PAGE_INDEX])
    subtests = {"FA": fa_entry, "WU": wu_entry}
    assets = {**fa_assets, **wu_assets}
    debug = {"FA": fa_debug, "WU": wu_debug}
    return subtests, assets, debug


def extract(pdf_path, output=OUTPUT, contact_sheet_dir=None):
    subtests, assets, debug = build(pdf_path)

    output.mkdir(parents=True, exist_ok=True)
    for path, image in assets.items():
        full_path = output / path
        full_path.parent.mkdir(parents=True, exist_ok=True)
        image.save(full_path)

    contact_sheets = {}
    if contact_sheet_dir is not None:
        contact_sheet_dir.mkdir(parents=True, exist_ok=True)
        fa_sheet = _contact_sheet(
            debug["FA"]["composite"], debug["FA"]["item_boxes"],
            [debug["FA"]["legend_1_boxes"], debug["FA"]["legend_2_boxes"]],
            FA_START, "FA contact sheet - items 117-136 + both legends",
        )
        fa_path = contact_sheet_dir / "ist-fa-contact-sheet.png"
        fa_sheet.save(fa_path)
        contact_sheets["FA"] = fa_path

        wu_sheet = _contact_sheet(
            debug["WU"]["composite"], debug["WU"]["item_boxes"],
            [debug["WU"]["legend_boxes"]],
            WU_START, "WU contact sheet - items 137-156 + legend",
        )
        wu_path = contact_sheet_dir / "ist-wu-contact-sheet.png"
        wu_sheet.save(wu_path)
        contact_sheets["WU"] = wu_path

    return subtests, contact_sheets


def main():
    import argparse

    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("pdf_path", type=Path, help="Path to the IST PDF booklet")
    parser.add_argument(
        "--ist-items-json", type=Path, default=OUTPUT / "ist_items.json",
        help="Existing ist_items.json (text subtests) to merge FA/WU into (default: database/seeders/data/ist_items.json)",
    )
    parser.add_argument(
        "--contact-sheet-dir", type=Path, default=Path("tasks/handoffs/f0"),
        help="Directory to write the FA/WU contact sheets into (default: tasks/handoffs/f0)",
    )
    args = parser.parse_args()

    subtests, contact_sheets = extract(args.pdf_path, contact_sheet_dir=args.contact_sheet_dir)

    existing = json.loads(args.ist_items_json.read_bytes()) if args.ist_items_json.exists() else {"subtests": {}}
    existing["subtests"].update(subtests)
    existing["status"] = "draft" if any(s["status"] == "draft" for s in existing["subtests"].values()) else "final"
    # write_bytes, not write_text: this repo's JSON convention is LF-only
    # (see common.py's write() and every other extract_*.py's _serialize())
    # - write_text() on Windows silently translates \n to \r\n, which would
    # make this the only non-LF seed file and break every other tool's
    # byte-hash pin expectations for it.
    args.ist_items_json.write_bytes(
        json.dumps(existing, ensure_ascii=False, indent=2).encode("utf-8"),
    )

    print(f"Wrote FA ({len(subtests['FA']['items'])} items) and WU ({len(subtests['WU']['items'])} items), both status=draft")
    for code, path in contact_sheets.items():
        print(f"  {code} contact sheet: {path}")


if __name__ == "__main__":
    main()
