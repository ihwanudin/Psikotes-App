"""Extract IST item content (instructions + all 136 text-subtest items across
SE, WA, AN, GE, RA, ZR, ME) for the test-taking UI. FA/WU (image subtests,
40 more items) are a separate module/PR - see the note below.

Structure (20-page PDF): each subtest occupies 2 pages - an instructions
("PETUNJUK DAN CONTOH") page, then an items page - in fixed order SE, WA, AN,
GE, RA, ZR, FA, WU, ME. Page 19 holds the ME memorization word list (printed
4 times - see the ME section below); page 20 is closing/attribution only.

Two independent text-extraction methods are diffed word-for-word for every
text-based subtest, per the coordinator's decision after finding that
`pdftotext` and `pypdf` have real, *different* extraction defects on this
document (not hypothetical - see the module's git history / PR description
for the specific lines each tool corrupted):
  - `pdftotext -layout -enc UTF-8`: clean on most pages, but on AN
    systematically separates every item's "e)" option from its a-d row
    (scattered onto unrelated items' lines - not just an occasional
    duplicate), and on ME drops "e) binatang" from its real position
    entirely from item 162 onward.
  - `pypdf` `extraction_mode="layout"`: clean on most pages (and the only one
    of the two that handles WA's 3-column layout directly, and the only one
    clean on AN/ME), but on SE splits a couple of words with a phantom
    internal space ("k          ecelakaan").
  - For WA specifically, `pdftotext -layout` garbles the 3-column layout
    (same failure class RMIB hit), so the second method there is plain
    `pdftotext` *without* `-layout` - its content-stream reading order
    interleaves columns awkwardly but each item's own text stays intact,
    which is all the cross-check needs (items are matched by number, not
    position).
  - AN and ME are documented exceptions where the two-method diff is
    disabled (`SUBTESTS[code]["cross_check"] = False`) because pdftotext's
    defect there is too severe to use as a genuine second opinion, and
    pdfminer.six (tried as an alternative) has its own, different problem on
    AN's page (correct text, scrambled reading order). Both rely on pypdf
    alone (directly verified) plus the option-count/key-membership checks
    every subtest gets - see SUBTESTS' own comment for the full reasoning.

For every multiple-choice item, the `ist.json` answer-key letter must be
present among that item's extracted option letters (a-e) - required by the
coordinator in place of a second full source (IST has none, unlike RMIB's
xlsx). GE/RA/ZR are fill-in subtests (no options; matches `ist.json`'s
`match_strategy`) - recorded as their answer type, no invented options.

FA and WU (image subtests) are handled by `extract_ist_fa_wu.py`, in a
separate PR, because per-item crops need the coordinator's own visual review
(a contact sheet checked crop-by-crop against the source) before they can be
marked final - unlike text, a bad crop can look structurally fine to any
automated check while still showing a truncated or wrong figure to a
participant. This module does not emit placeholder entries for them at all
(rather than a `status: "draft"` stub) - `extract()`'s `existing_subtests`
parameter merges FA/WU's own output back in once that PR lands, so re-running
this text extractor afterwards doesn't wipe them out.

ME's memorization word list has two non-identical printed variants (see the
verification report) - `build()` unconditionally emits ME as `status: "draft"`
with both variants recorded (deduplicated from the 4 printed copies, with a
print count each), never invented or "corrected" text, per CLAUDE.md/AGENTS.md
fail-closed conventions. Re-running the PDF extraction can never finalize ME
by itself, no matter what the PDF says - that would mean this module could
silently overturn a human decision the next time someone re-extracts.

The psychologist confirmed which variant is correct on 2026-09-22 (P7,
tasks/handoffs/decisions/owner-decisions-2026-09-21.md item 21, PR #81
`4050eb9b`): Version A - BURUNG includes TEKUKUR, KESENIAN includes QUINTET
(the variant printed once out of four copies, not the majority one - it also
matches the worked example already printed on ME's own instructions page,
which names "Quintet" specifically). `finalize_me()` (below) is the one and
only code path that can move ME from draft to final: it takes an
ALREADY-extracted `ist_items.json` (no PDF access needed - the words were
already read off the page and verified, this step only SELECTS between two
already-recorded candidates, it never re-reads or re-derives anything),
asserts the confirmed word list is byte-for-byte one of the variants already
on record (never invents a word list from ME_CONFIRMED_WORD_LIST alone), and
is invoked explicitly (`--finalize-me`), never automatically as part of a
routine PDF re-extraction.
"""

import json
import re
import subprocess
from pathlib import Path

from pypdf import PdfReader

from .common import OUTPUT

VERSION = "F0-ITEMS-IST-2026.09"
OPTION_LETTERS = "abcde"

# P7 (2026-09-22): the psychologist-confirmed variant. Used only to SELECT
# among extract_ist_items.py's own already-extracted word_list_variants
# (finalize_me() asserts this is actually one of them) - never written into
# ist_items.json unless that assertion passes.
ME_CONFIRMED_WORD_LIST = {
    "BUNGA": ["SOKA", "LARAT", "FLAMBOYAN", "YASMIN", "DAHLIA"],
    "PERKAKAS": ["WAJAN", "JARUM", "KIKIR", "CANGKUL", "PALU"],
    "BURUNG": ["ITIK", "ELANG", "WALET", "TEKUKUR", "NURI"],
    "KESENIAN": ["QUINTET", "ARCA", "OPERA", "UKIRAN", "GAMELAN"],
    "BINATANG": ["RUSA", "MUSANG", "BERUANG", "HARIMAU", "ZEBRA"],
}

# (first item number, item count, 0-based PDF page index of the items page).
# The instructions page for each subtest is always items_page_index - 1.
# "primary" picks which of the two extraction methods is canonical (i.e. what
# gets committed) for that subtest - both are still cross-checked word-for-
# word either way, UNLESS "cross_check" is explicitly False (AN and ME only -
# see below). Default primary is pdftotext; overridden per-subtest where that
# subtest's own page has a *worse* defect in pdftotext than in pypdf (found
# empirically, not assumed - see module docstring and the PR's report):
# WA needs pypdf regardless since it's the only one of the two that handles
# the 3-column layout at all.
#
# AN and ME are deliberate, documented exceptions to the two-method
# requirement, each for its own reason:
# - AN: pdftotext -layout doesn't just occasionally duplicate a stray option
#   (like the phantom-line defect handled elsewhere) - it systematically
#   separates the "e)" option from its item's a-d row for ALL 20 AN items,
#   scattering it onto unrelated items' lines. pdfminer.six (the PAPI/RMIB
#   fallback) was tried as an alternative second source and has the opposite
#   problem on this specific page: every option's text is correct, but the
#   reading order is scrambled across item boundaries in a way that can't be
#   safely reconstructed without bespoke per-page logic whose own correctness
#   would be no more trustworthy than pypdf's already-clean output.
# - ME: pdftotext's "e) binatang" (identical for all 20 items - see the ME
#   word-list note below, unrelated to this) is present correctly for items
#   157-161, then drops out of its real position entirely from item 162
#   onward (not just duplicated elsewhere - genuinely absent from that row).
#   pypdf is clean for all 20 items, directly verified.
# Rather than force a diff through a tool that's known-broken for these
# pages, or fabricate a "second opinion" via reconstruction logic that isn't
# actually independent, both rely on pypdf (visually verified against the raw
# PDF in the PR's report) plus the same option-count and key-membership
# checks every subtest gets - the originally-agreed minimum bar for IST -
# rather than a fragile forced two-method diff.
SUBTESTS = {
    "SE": {"start": 1, "count": 20, "page": 1, "kind": "lettered_with_stem", "primary": "pdftotext"},
    "WA": {"start": 21, "count": 20, "page": 3, "kind": "lettered_no_stem", "primary": "pypdf"},
    "AN": {"start": 41, "count": 20, "page": 5, "kind": "lettered_with_stem", "primary": "pypdf", "cross_check": False},
    "GE": {"start": 61, "count": 16, "page": 7, "kind": "fill_in_word", "primary": "pdftotext"},
    "RA": {"start": 77, "count": 20, "page": 9, "kind": "fill_in_numeric", "primary": "pdftotext"},
    "ZR": {"start": 97, "count": 20, "page": 11, "kind": "fill_in_numeric", "primary": "pdftotext"},
    "FA": {"start": 117, "count": 20, "page": 13, "kind": "image_choice", "primary": "pdftotext"},
    "WU": {"start": 137, "count": 20, "page": 15, "kind": "image_choice", "primary": "pdftotext"},
    "ME": {"start": 157, "count": 20, "page": 17, "kind": "lettered_with_stem", "primary": "pypdf", "cross_check": False},
}
ME_WORD_LIST_PAGE = 18  # 0-based index (page 19)

_ITEM_LINE = re.compile(r"^(\d{1,3})[.)]\s*(.*)$")  # ZR uses "97)"; every other subtest uses "97."
_LETTERED = re.compile(
    r"^(?P<stem>.*?)a\)\s*(?P<a>.*?)\s*b\)\s*(?P<b>.*?)\s*c\)\s*(?P<c>.*?)\s*"
    r"d\)\s*(?P<d>.*?)\s*e\)\s*(?P<e>.*)$"
)
_FOOTER_MARKERS = ("BERHENTI", "JANGAN DIBALIK", "JANGAN MENCORETKAN", "APAPUN DALAM BUKU")
_TRAILING_E_ON_HEADER = re.compile(r"\s*e\)\s*.+$")
# See the filter's own comment at its use site (_iter_item_blocks). Matches
# either a whole phantom line, or a phantom segment tacked onto the end of a
# real line (e.g. an item's own stem line) - always preceded by a huge gap
# (>=30 spaces) that never occurs within this document's real content.
_PHANTOM_E_COLUMN = re.compile(r"\s{30,}e\)\s*.+$")


def build(pdf_path, ist_json_path):
    reader = PdfReader(pdf_path)
    if len(reader.pages) != 20:
        raise ValueError(f"Expected a 20-page IST PDF, got {len(reader.pages)} pages")

    ist_reference = json.loads(Path(ist_json_path).read_text(encoding="utf-8"))
    pdftotext_full = _pdftotext(pdf_path)

    subtests = {}
    tolerated_mismatches = []
    for code, spec in SUBTESTS.items():
        if spec["kind"] == "image_choice":
            # FA/WU (images) are deliberately not produced by this module -
            # see extract_ist_fa_wu.py (separate PR: per-item crops need
            # Lead's own visual review before they can be marked final, so
            # they're kept out of this text-only file rather than shipped as
            # a placeholder key). Callers that already have FA/WU data merge
            # it in afterwards - see extract()'s `existing_subtests` param.
            continue

        page = reader.pages[spec["page"]]
        pypdf_text = page.extract_text(extraction_mode="layout")

        if code == "WA":
            # pdftotext -layout garbles the 3-column page; pypdf layout mode
            # handles it natively and becomes method A, with plain (no
            # -layout) pdftotext as the independent method B instead.
            method_a_text = pypdf_text
            method_b_text = _pdftotext_plain_page(pdf_path, spec["page"] + 1)
        elif spec["primary"] == "pypdf":
            method_a_text = pypdf_text
            method_b_text = _section_text(pdftotext_full, code)
        else:
            method_a_text = _section_text(pdftotext_full, code)
            method_b_text = pypdf_text

        do_cross_check = spec.get("cross_check", True)

        if code == "WA":
            items_a = _parse_wa_columns(method_a_text, spec["start"], spec["count"])
            items_b = _parse_lettered(method_b_text, spec["start"], spec["count"], has_stem=False)
        elif spec["kind"] in ("lettered_with_stem", "lettered_no_stem"):
            has_stem = spec["kind"] == "lettered_with_stem"
            items_a = _parse_lettered(method_a_text, spec["start"], spec["count"], has_stem)
            items_b = _parse_lettered(method_b_text, spec["start"], spec["count"], has_stem) if do_cross_check else items_a
        elif spec["kind"] == "fill_in_word":
            items_a = _parse_ge(method_a_text, spec["start"], spec["count"])
            items_b = _parse_ge(method_b_text, spec["start"], spec["count"]) if do_cross_check else items_a
        elif spec["kind"] == "fill_in_numeric":
            items_a = _parse_fill_in(method_a_text, spec["start"], spec["count"])
            items_b = _parse_fill_in(method_b_text, spec["start"], spec["count"]) if do_cross_check else items_a
        else:
            raise ValueError(f"Unhandled subtest kind: {spec['kind']}")

        if do_cross_check:
            for item_no, field, value_a, value_b in _cross_check_items(code, items_a, items_b):
                tolerated_mismatches.append((code, item_no, field, value_a, value_b))

        if code == "WA" or spec["kind"] in ("lettered_with_stem", "lettered_no_stem"):
            _cross_check_keys(code, items_a, ist_reference, spec["start"])

        instructions = _parse_instructions(reader.pages[spec["page"] - 1].extract_text(extraction_mode="layout"))

        entry = {"status": "final", "instructions": instructions}
        if spec["kind"] == "fill_in_word":
            entry["answer_type"] = "fill_in_word"
        elif spec["kind"] == "fill_in_numeric":
            entry["answer_type"] = "fill_in_numeric"
        entry["items"] = [items_a[n] for n in sorted(items_a)]
        subtests[code] = entry

    me_word_list = _parse_me_word_list(reader.pages[ME_WORD_LIST_PAGE].extract_text(extraction_mode="layout"))
    subtests["ME"]["status"] = "draft"
    subtests["ME"]["draft_reason"] = (
        "The memorization word list is printed 4 times on PDF page 19 and is "
        "not identical across copies (BURUNG/KESENIAN entries differ). The "
        "psychologist has not confirmed which variant is correct. Both are "
        "recorded verbatim; this subtest cannot be marked final until resolved."
    )
    subtests["ME"]["word_list_variants"] = me_word_list

    return subtests, tolerated_mismatches


def extract(pdf_path, ist_json_path, output=OUTPUT, existing_subtests=None):
    """`existing_subtests` merges in subtests this module doesn't produce
    itself (FA/WU - see extract_ist_fa_wu.py) so re-running the text
    extraction after the images PR lands doesn't wipe them out. Any subtest
    this module *does* produce always overwrites the corresponding key in
    `existing_subtests` (this module is the sole source of truth for those)."""
    subtests, tolerated_mismatches = build(pdf_path, ist_json_path)
    if tolerated_mismatches:
        print(f"NOTE: {len(tolerated_mismatches)} whitespace-only mismatch(es) tolerated "
              f"(known pdftotext/pypdf phantom-space defect - see module docstring):")
        for code, item_no, field, value_a, value_b in tolerated_mismatches:
            print(f"  {code} item={item_no} {field}: A={value_a!r} B={value_b!r}")

    merged_subtests = dict(existing_subtests or {})
    merged_subtests.update(subtests)
    top_status = "draft" if any(s["status"] == "draft" for s in merged_subtests.values()) else "final"
    data = {"version": VERSION, "status": top_status, "subtests": merged_subtests}

    output.mkdir(parents=True, exist_ok=True)
    (output / "ist_items.json").write_bytes(_serialize(data))
    return data


def finalize_me(ist_items_json_path, output=OUTPUT):
    """The one and only code path that can move ME from `status: "draft"` to
    `"final"` - see the module docstring for why this is deliberately
    separate from `extract()`/`build()` (which can never do this themselves,
    no matter what a re-extracted PDF says) and from `ME_CONFIRMED_WORD_LIST`'s
    own provenance (P7, 2026-09-22).

    Reads an ALREADY-extracted `ist_items.json` - no PDF access, no
    re-extraction. Fails closed at every step: raises if ME is missing,
    already final (calling this twice must be a deliberate no-op-detecting
    error, not a silent re-write), or - the one check that actually matters -
    if `ME_CONFIRMED_WORD_LIST` is not byte-for-byte identical to one of the
    `word_list_variants` this module itself already recorded from the PDF.
    That last check is what keeps this a SELECTION, not an invention: the
    confirmed word list can only ever be a variant that was actually printed
    on the source document and already extracted, never hand-typed content
    substituting for extraction.
    """
    data = json.loads(Path(ist_items_json_path).read_text(encoding="utf-8"))
    me = data["subtests"]["ME"]
    if me["status"] != "draft":
        raise ValueError(f"ME is not in draft status (found {me['status']!r}) - nothing to finalize.")

    variants = me["word_list_variants"]
    if not any(v["categories"] == ME_CONFIRMED_WORD_LIST for v in variants):
        raise ValueError(
            "ME_CONFIRMED_WORD_LIST does not exactly match any already-extracted "
            "word_list_variants entry - refusing to finalize with an invented word list."
        )

    data["subtests"]["ME"] = {
        "status": "final",
        "instructions": me["instructions"],
        "items": me["items"],
        "word_list": ME_CONFIRMED_WORD_LIST,
    }
    data["status"] = "draft" if any(s["status"] == "draft" for s in data["subtests"].values()) else "final"

    output.mkdir(parents=True, exist_ok=True)
    (output / "ist_items.json").write_bytes(_serialize(data))
    return data


# ---------------------------------------------------------------------------
# Text extraction (two methods)
# ---------------------------------------------------------------------------

def _pdftotext(pdf_path):
    result = subprocess.run(
        ["pdftotext", "-layout", "-enc", "UTF-8", str(pdf_path), "-"],
        capture_output=True, check=True,
    )
    return result.stdout.decode("utf-8")


def _pdftotext_plain_page(pdf_path, page_number_1indexed):
    result = subprocess.run(
        ["pdftotext", "-f", str(page_number_1indexed), "-l", str(page_number_1indexed),
         "-enc", "UTF-8", str(pdf_path), "-"],
        capture_output=True, check=True,
    )
    return result.stdout.decode("utf-8")


def _section_text(full_text, subtest_code):
    marker = re.compile(rf"^\s*{subtest_code}\.\s*\(Soal-soal", re.M)
    match = marker.search(full_text)
    if not match:
        raise ValueError(f"Could not locate {subtest_code}'s item section header")
    # Bounded at this subtest's own first footer marker, not "the next
    # subtest's items header" - the latter let the following subtest's
    # *instructions* page (with its own worked-example answer-sheet marking
    # like "02) a b c d e") leak into this section, which could silently
    # masquerade as a real item and overwrite it (found via SE/WA - see PR).
    footer = re.compile("|".join(re.escape(m) for m in _FOOTER_MARKERS))
    footer_match = footer.search(full_text, match.end())
    end = footer_match.end() if footer_match else len(full_text)
    return full_text[match.start():end]


# ---------------------------------------------------------------------------
# Parsers
# ---------------------------------------------------------------------------

# WA's pypdf-layout page prints 3 side-by-side columns, one option letter per
# line (5 lines per item), 3 items' worth of that same letter concatenated on
# each physical line - e.g. "21. a) lingkungan   28.  a) putih   35. a) gambar".
# Measured on this page: internal within-column gaps top out at 8 chars; the
# narrowest real cross-column gap is 12 - so a threshold of 10 safely tells
# them apart (same measure-first approach as RMIB's block splitter).
_WA_COLUMN_GAP = re.compile(r" {10,}")
_WA_OPTION_LINE = re.compile(r"^([a-e])\)\s*(.*)$")


def _parse_wa_columns(text, start, count):
    all_lines = [l for l in text.splitlines() if l.strip()]
    header_index = next(i for i, l in enumerate(all_lines) if l.strip().startswith("WA."))
    content_lines = []
    for line in all_lines[header_index + 1:]:
        if any(m in line for m in _FOOTER_MARKERS):
            break  # footer text (e.g. "tunggulah perintah berikutnya") has no marker of its own
        content_lines.append(line)

    num_columns = 3
    per_column_start = [start, start + 7, start + 14]  # 21, 28, 35
    current_item = list(per_column_start)
    line_index = [0] * num_columns
    buffers = [[] for _ in range(num_columns)]
    items = {}

    for line in content_lines:
        segments = [s.strip() for s in _WA_COLUMN_GAP.split(line)]
        segments = [s for s in segments if s]
        for col, segment in enumerate(segments):
            if current_item[col] >= start + count:
                continue  # this column has no more items (WA's 3rd column is short)
            # Each segment may still start with the item number ("21.") on
            # its very first line - strip it the same way a lettered item
            # start would be, then it's a plain "a) ..." option line.
            segment = re.sub(r"^\d{1,3}\.\s*", "", segment)
            match = _WA_OPTION_LINE.match(segment)
            if not match:
                raise ValueError(f"WA: expected an 'x) ...' option line, got {segment!r}")
            letter, value = match.group(1), match.group(2).strip()
            expected_letter = OPTION_LETTERS[line_index[col]]
            if letter != expected_letter:
                raise ValueError(
                    f"WA: column {col} expected option {expected_letter!r} but got {letter!r} "
                    f"(item {current_item[col]}, segment {segment!r})"
                )
            buffers[col].append(value)
            line_index[col] += 1
            if line_index[col] == 5:
                item_no = current_item[col]
                options = dict(zip(OPTION_LETTERS, buffers[col]))
                for letter, opt_value in options.items():
                    if not opt_value:
                        raise ValueError(f"WA item {item_no}: option {letter} is empty")
                items[item_no] = {"item": item_no, "options": options}
                current_item[col] += 1
                line_index[col] = 0
                buffers[col] = []

    expected = set(range(start, start + count))
    missing = expected - set(items)
    if missing:
        raise ValueError(f"WA: missing items {sorted(missing)} (found {sorted(items)})")
    return items


def _iter_item_blocks(text, start, count, strip_trailing_e_on_header=False):
    """Yield (item_number, joined_block_text) for each expected item number,
    accumulating wrapped continuation lines until the next item or a footer
    marker. Raises if an expected item number is missing.

    `strip_trailing_e_on_header` must only be set for subtests whose item
    lines carry a real question stem separate from the option row (SE, AN,
    GE, RA, ZR, ME) - there, an option marker on the header line is always
    the ME-page phantom-e defect (see the strip's own comment). It must stay
    False for WA, where the header line *is* the option row and a trailing
    "e)" there is real content, not a phantom."""
    lines = text.splitlines()
    expected = set(range(start, start + count))
    blocks = {}
    current_no = None
    current_lines = []

    def flush():
        if current_no is not None:
            blocks[current_no] = _collapse_whitespace(" ".join(current_lines))

    for raw_line in lines:
        # pdftotext -layout's known defect on the AN page: duplicates each
        # item's "e)" option onto a phantom trailing segment, preceded by a
        # huge gap (>=30 spaces - a real wrapped "e) ..." continuation line,
        # e.g. SE item 11, sits at the normal option indent, well under 10),
        # either on its own line or tacked onto another item's real line. The
        # real option is always also present in its correct item's row, so
        # dropping this duplicate loses nothing.
        raw_line = _PHANTOM_E_COLUMN.sub("", raw_line)
        line = raw_line.strip()
        if not line:
            continue
        if any(marker in line for marker in _FOOTER_MARKERS):
            # Closes the current item outright (not just "skip this line") -
            # anything after a footer marker belongs to the next page's own
            # content (e.g. the following subtest's instructions page), never
            # to the item just finished.
            flush()
            current_no = None
            current_lines = []
            continue
        match = _ITEM_LINE.match(line)
        item_no = int(match.group(1)) if match else None
        if item_no in expected:
            flush()
            current_no = item_no
            stem = match.group(2)
            if strip_trailing_e_on_header:
                # A subtest with a real question stem never legitimately
                # carries an inline option marker on that same header line
                # (options only appear on their own dedicated row) - on the
                # ME page, pdftotext tacks a trailing "e) binatang" onto the
                # stem line itself, at a gap far too small for
                # _PHANTOM_E_COLUMN's general threshold.
                stem = _TRAILING_E_ON_HEADER.sub("", stem)
            current_lines = [stem]
        elif current_no is not None:
            current_lines.append(line)
    flush()

    missing = expected - set(blocks)
    if missing:
        raise ValueError(f"Missing items {sorted(missing)} (found {sorted(blocks)})")
    return blocks


def _parse_lettered(text, start, count, has_stem):
    blocks = _iter_item_blocks(text, start, count, strip_trailing_e_on_header=has_stem)
    items = {}
    for item_no, block in blocks.items():
        match = _LETTERED.match(block)
        if not match:
            raise ValueError(f"Item {item_no}: could not find 5 lettered options in {block!r}")
        stem = _collapse_whitespace(match.group("stem"))
        if has_stem and not stem:
            raise ValueError(f"Item {item_no}: expected a question stem but found none")
        if not has_stem and stem:
            raise ValueError(f"Item {item_no}: expected no stem but found {stem!r}")
        options = {letter: _collapse_whitespace(match.group(letter)) for letter in OPTION_LETTERS}
        for letter, value in options.items():
            if not value:
                raise ValueError(f"Item {item_no}: option {letter} is empty")
        entry = {"item": item_no, "options": options}
        if has_stem:
            entry = {"item": item_no, "text": stem, "options": options}
        items[item_no] = entry
    return items


def _parse_ge(text, start, count):
    blocks = _iter_item_blocks(text, start, count)
    return {n: {"item": n, "text": _collapse_whitespace(block)} for n, block in blocks.items()}


def _parse_fill_in(text, start, count):
    blocks = _iter_item_blocks(text, start, count)
    return {n: {"item": n, "text": _collapse_whitespace(block)} for n, block in blocks.items()}


def _parse_instructions(text):
    lines = [_collapse_whitespace(l) for l in text.splitlines()]
    lines = [l for l in lines if l]
    start = next(i for i, l in enumerate(lines) if l.startswith("PETUNJUK DAN CONTOH"))
    end = next(i for i, l in enumerate(lines) if "JANGAN DIBALIK" in l)
    body = lines[start + 1:end]
    return {"text": " ".join(body)}


def _parse_me_word_list(text):
    lines = [_collapse_whitespace(l) for l in text.splitlines()]
    lines = [l for l in lines if l]
    categories = ("BUNGA", "PERKAKAS", "BURUNG", "KESENIAN", "BINATANG")
    copies = []
    current = {}
    for line in lines:
        for category in categories:
            if line.startswith(category + " :") or line.startswith(category + ":"):
                words = line.split(":", 1)[1].strip()
                current[category] = [w.strip() for w in words.split(",")]
        if len(current) == len(categories):
            copies.append(current)
            current = {}
    if not copies:
        raise ValueError("Could not parse any ME word-list copy from page 19")

    # Page 19 prints the list 4 times; several copies are byte-identical to
    # each other. Deduplicated to the *distinct* variants (with how many of
    # the 4 printed copies matched each), since that's the substantive fact
    # for the psychologist's decision - not a judgment about which is
    # correct, just not repeating identical copies 4 times over.
    distinct = []
    for copy in copies:
        existing = next((v for v in distinct if v["categories"] == copy), None)
        if existing:
            existing["printed_count"] += 1
        else:
            distinct.append({"categories": copy, "printed_count": 1})
    return distinct


# ---------------------------------------------------------------------------
# Cross-checks
# ---------------------------------------------------------------------------

def _cross_check_items(subtest_code, items_a, items_b):
    """Returns the list of *tolerated* whitespace-only mismatches (still
    reported, never silent) for the caller to log. Raises on anything that
    isn't explainable purely by whitespace - i.e. any real wording diff."""
    if set(items_a) != set(items_b):
        raise ValueError(
            f"{subtest_code}: item numbers differ between extraction methods. "
            f"A-only: {sorted(set(items_a) - set(items_b))}, "
            f"B-only: {sorted(set(items_b) - set(items_a))}"
        )
    hard_mismatches = []
    tolerated = []
    for item_no in sorted(items_a):
        a, b = items_a[item_no], items_b[item_no]
        for field in a:
            if field == "item":
                continue
            va, vb = a[field], b[field]
            pairs = va.items() if isinstance(va, dict) else [(None, va)]
            for letter, value_a in pairs:
                value_b = vb[letter] if letter else vb
                label = f"{field}.{letter}" if letter else field
                if _normalize_for_compare(value_a) == _normalize_for_compare(value_b):
                    continue
                if _strip_all_whitespace(value_a) == _strip_all_whitespace(value_b):
                    # Same letters, differing only in a spurious internal
                    # space - the known pypdf/pdftotext phantom-space defect
                    # (see module docstring). Tolerated because it's provably
                    # whitespace-only, not a wording difference; A (this
                    # module's designated primary/canonical source) wins.
                    tolerated.append((item_no, label, value_a, value_b))
                else:
                    hard_mismatches.append((item_no, label, value_a, value_b))
    if hard_mismatches:
        details = "\n".join(f"  item={n} {f}: A={a!r} B={b!r}" for n, f, a, b in hard_mismatches)
        raise ValueError(f"{subtest_code}: text mismatch between extraction methods for {len(hard_mismatches)} field(s):\n{details}")
    return tolerated


def _strip_all_whitespace(text):
    return re.sub(r"\s+", "", str(text).translate(_QUOTE_NORMALIZE))


def _cross_check_keys(subtest_code, items, ist_reference, start):
    # ist.json's "keys" are numbered per-subtest (1..20), not by the global
    # item number (e.g. WA's global items are 21-40) - convert before lookup.
    keys_by_item = {
        entry["item"] + start - 1: entry["key"]
        for entry in ist_reference["keys"]
        if entry["subtest"] == subtest_code
    }
    if set(keys_by_item) != set(items):
        raise ValueError(
            f"{subtest_code}: item numbers in ist.json keys don't match extracted items. "
            f"ist.json-only: {sorted(set(keys_by_item) - set(items))}, "
            f"extracted-only: {sorted(set(items) - set(keys_by_item))}"
        )
    missing_key = []
    for item_no, key in keys_by_item.items():
        options = items[item_no]["options"]
        if key not in options:
            missing_key.append((item_no, key, sorted(options)))
    if missing_key:
        details = "\n".join(f"  item={n} key={k!r} not in options {opts}" for n, k, opts in missing_key)
        raise ValueError(f"{subtest_code}: ist.json answer key missing from extracted options:\n{details}")


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

_QUOTE_NORMALIZE = str.maketrans({"“": '"', "”": '"', "‘": "'", "’": "'"})


def _collapse_whitespace(text):
    return re.sub(r"\s+", " ", text).strip()


def _normalize_for_compare(text):
    return re.sub(r"\s+", " ", str(text).translate(_QUOTE_NORMALIZE)).strip()


def _serialize(data):
    return json.dumps(data, ensure_ascii=False, indent=2).encode("utf-8")


def main():
    import argparse

    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("pdf_path", type=Path, nargs="?", help="Path to the IST PDF booklet")
    parser.add_argument(
        "--ist-json", type=Path, default=OUTPUT / "ist.json",
        help="Path to the existing ist.json scoring file (default: database/seeders/data/ist.json)",
    )
    parser.add_argument(
        "--finalize-me", type=Path, metavar="IST_ITEMS_JSON",
        help="Select the psychologist-confirmed ME word list (P7) from an ALREADY-extracted "
             "ist_items.json and mark ME final - no PDF needed, mutually exclusive with pdf_path.",
    )
    args = parser.parse_args()

    if args.finalize_me is not None:
        if args.pdf_path is not None:
            parser.error("--finalize-me does not take a pdf_path - it operates on an already-extracted ist_items.json.")
        data = finalize_me(args.finalize_me)
        print(f"Finalized ME in ist_items.json: status={data['status']}")
        for code, entry in data["subtests"].items():
            print(f"  {code}: status={entry['status']} items={len(entry.get('items', []))}")
        return

    if args.pdf_path is None:
        parser.error("pdf_path is required unless --finalize-me is given.")

    data = extract(args.pdf_path, args.ist_json)
    print(f"Wrote ist_items.json: status={data['status']}")
    for code, entry in data["subtests"].items():
        print(f"  {code}: status={entry['status']} items={len(entry.get('items', []))}")


if __name__ == "__main__":
    main()
