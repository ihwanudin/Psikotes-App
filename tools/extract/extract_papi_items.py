"""Extract PAPI item content (instructions + example + the 90 statement pairs)
for the test-taking UI.

PAPI has no second structured source like RMIB's xlsx, so per the plan agreed
with the coordinator, the 90 statement pairs are extracted by TWO independent
methods and diffed word-for-word:
  - `pdftotext -layout` (poppler, external CLI, C++)
  - `pdfminer.six` (pure-Python, a different codebase/algorithm from both
    poppler and `pypdf`)

The two tools decode the same glyphs differently: `pdftotext` normalizes the
source's typographic curly quotes (U+201C/U+201D) to straight ASCII quotes and
the ellipsis leader-dot glyph (U+2026, repeated) to plain periods, while
`pdfminer.six` preserves the source's actual encoded characters. Both are
normalized identically before diffing (leader dots/ellipses stripped - they are
a print layout aid connecting text to the answer-sheet arrow, not content;
quote style unified) so only *real* content differences fail the cross-check.
The committed text uses `pdfminer.six`'s output as canonical, since it is the
more faithful decoding of the source's actual characters.

90 x 2 statements must match papi.json's `mapping` count (90) exactly. No
scoring/dimension information is written to the item file - `statement_a`/
`statement_b` are positional (first/second printed statement) only, because
`papi.json`'s own `a`/`b` fields already mean the scored dimension codes.
"""

import json
import re
import subprocess
from pathlib import Path

from pdfminer.high_level import extract_text as pdfminer_extract_text

from .common import OUTPUT

VERSION = "F0-ITEMS-PAPI-2026.09"
ITEM_COUNT = 90

# A "leader" is the row of dots/ellipses connecting a statement to its
# answer-sheet arrow - always a long run (dozens of characters) in this
# booklet, never just one or two. Only strip runs of 2+ leader characters so a
# real sentence-ending period (single ".") in the instructions prose is kept.
# pdfminer.six also preserves a trailing "<-" print glyph (U+2190) on two of
# the instruction demo lines, showing which arrow to circle on paper - pure
# print layout, stripped along with the leader dots it follows.
_LEADER = re.compile(r"\s*[.…]{2,}[.…\s]*←?\s*$")
_ITEM_START = re.compile(r"^(\d{1,2})\.\s*(.+)$")
_QUOTE_NORMALIZE = str.maketrans({
    "“": '"', "”": '"', "‘": "'", "’": "'",
})


def build(pdf_path, papi_json_path):
    pdftotext_items, pdftotext_front = _read_pdftotext(pdf_path)
    pdfminer_items, pdfminer_front = _read_pdfminer(pdf_path)

    _cross_check_items(pdftotext_items, pdfminer_items)

    papi_reference = json.loads(Path(papi_json_path).read_text(encoding="utf-8"))
    expected_count = len(papi_reference["mapping"])
    if len(pdfminer_items) != expected_count:
        raise ValueError(
            f"Extracted {len(pdfminer_items)} PAPI items, "
            f"but papi.json mapping has {expected_count}"
        )

    items = [
        {"item": item, "statement_a": pdfminer_items[item][0], "statement_b": pdfminer_items[item][1]}
        for item in sorted(pdfminer_items)
    ]

    instructions = _build_instructions(pdftotext_front, pdfminer_front)

    return {
        "version": VERSION,
        "status": "final",
        "instructions": instructions,
        "items": items,
    }


def extract(pdf_path, papi_json_path, output=OUTPUT):
    data = build(pdf_path, papi_json_path)
    output.mkdir(parents=True, exist_ok=True)
    (output / "papi_items.json").write_bytes(_serialize(data))
    return data


# ---------------------------------------------------------------------------
# Method 1: pdftotext -layout (poppler)
# ---------------------------------------------------------------------------

def _read_pdftotext(pdf_path):
    result = subprocess.run(
        ["pdftotext", "-layout", str(pdf_path), "-"],
        capture_output=True, check=True,
    )
    text = result.stdout.decode("utf-8")
    return _parse_items(text), text


# ---------------------------------------------------------------------------
# Method 2: pdfminer.six (pure Python)
# ---------------------------------------------------------------------------

def _read_pdfminer(pdf_path):
    text = pdfminer_extract_text(str(pdf_path))
    return _parse_items(text), text


# ---------------------------------------------------------------------------
# Shared parsing (each method's raw text parsed independently by this same
# function - the *extraction* is independent, not the parsing logic; the
# parsing only recognizes the printed "N. statement" / "    statement" shape,
# it does not correct or infer content)
# ---------------------------------------------------------------------------

def _parse_items(text):
    items = {}
    pending_no = None
    pending_a = None

    for raw_line in text.splitlines():
        line = _strip_leader(raw_line)
        if not line:
            continue

        match = _ITEM_START.match(line)
        if match:
            if pending_no is not None:
                raise ValueError(f"Item {pending_no} has no second statement before item {match.group(1)} starts")
            pending_no = int(match.group(1))
            pending_a = match.group(2)
            continue

        if pending_no is not None:
            if pending_no in items:
                raise ValueError(f"Duplicate item {pending_no}")
            items[pending_no] = (pending_a, line)
            pending_no = None
            pending_a = None

    if pending_no is not None:
        raise ValueError(f"Item {pending_no} has a first statement but no second statement")

    return items


def _strip_leader(line):
    return _LEADER.sub("", line.strip())


def _cross_check_items(pdftotext_items, pdfminer_items):
    if set(pdftotext_items) != set(pdfminer_items):
        raise ValueError(
            f"Item numbers differ between extraction methods. "
            f"pdftotext-only: {sorted(set(pdftotext_items) - set(pdfminer_items))}, "
            f"pdfminer-only: {sorted(set(pdfminer_items) - set(pdftotext_items))}"
        )

    mismatches = []
    for item in sorted(pdftotext_items):
        a1, b1 = pdftotext_items[item]
        a2, b2 = pdfminer_items[item]
        if _normalize_for_compare(a1) != _normalize_for_compare(a2):
            mismatches.append((item, "a", a1, a2))
        if _normalize_for_compare(b1) != _normalize_for_compare(b2):
            mismatches.append((item, "b", b1, b2))

    if mismatches:
        details = "\n".join(
            f"  item={item} statement_{side}: pdftotext={t1!r} pdfminer={t2!r}"
            for item, side, t1, t2 in mismatches
        )
        raise ValueError(f"pdftotext/pdfminer PAPI text mismatch for {len(mismatches)} statement(s):\n{details}")


def _normalize_for_compare(text):
    """Quote-style and whitespace only - never touches actual wording. Used
    solely to detect mismatches between the two extraction methods; never
    used to build committed content (it would flatten the source's real
    curly quotes to ASCII)."""
    return re.sub(r"\s+", " ", text.translate(_QUOTE_NORMALIZE)).strip()


def _collapse_whitespace(text):
    """Join wrapped lines into one paragraph without altering characters."""
    return re.sub(r"\s+", " ", text).strip()


# ---------------------------------------------------------------------------
# Instructions + example (from the front matter, before item 1)
# ---------------------------------------------------------------------------

def _build_instructions(pdftotext_front, pdfminer_front):
    # The source has four distinct instructional sub-sections (intro prose,
    # an illustrative statement pair, a demonstration of how to mark the
    # answer sheet for that pair, then closing prose) - kept separate rather
    # than flattened into one blob, matching how the booklet actually
    # presents them. Content and reading order both come from pdfminer.six
    # (the more faithful decoding - see module docstring); pdftotext (lossy
    # on quotes/leaders, but a fully independent implementation) is used only
    # to independently confirm every line's wording, after normalization.
    lines = [_collapse_whitespace(_strip_leader(l)) for l in pdfminer_front.splitlines()]
    lines = [l for l in lines if l]

    intro_start = lines.index("Di dalam buku terdapat 90 pasang pernyataan. Pilihlah satu pernyataan dari pasangan pernyataan itu")
    misalnya_index = lines.index("Misalnya :")
    example_a_index = misalnya_index + 1
    lembar_jawaban_index = lines.index("Di LEMBAR JAWABAN", example_a_index)
    demo_a_index = lembar_jawaban_index + 1
    closing_start = lines.index("Pastikanlah bahwa anda memilih jawaban anda pada kolom yang tepat di lembar jawaban anda.")
    closing_end = lines.index("Bekerjalah dengan cepat, tetapi jangan sampai ada nomor pernyataan yang terlewatkan.")

    intro = " ".join(lines[intro_start:misalnya_index])
    example = {"statement_a": lines[example_a_index], "statement_b": lines[example_a_index + 1]}
    answer_sheet_demo = {
        "label": lines[lembar_jawaban_index],
        "statement_a": lines[demo_a_index],
        "statement_b": lines[demo_a_index + 1],
    }
    closing = " ".join(lines[closing_start:closing_end + 1])

    all_lines = (
        [intro]
        + [example["statement_a"], example["statement_b"]]
        + [answer_sheet_demo["label"], answer_sheet_demo["statement_a"], answer_sheet_demo["statement_b"]]
        + [closing]
    )
    pdftotext_normalized = _normalize_for_compare(pdftotext_front)
    for line in all_lines:
        normalized = _normalize_for_compare(line)
        if normalized not in pdftotext_normalized:
            raise ValueError(f"Instruction line not confirmed in the pdftotext extraction: {line!r}")

    return {
        "intro": _collapse_whitespace(intro),
        "example": example,
        "answer_sheet_demo": answer_sheet_demo,
        "closing": _collapse_whitespace(closing),
    }


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _serialize(data):
    return json.dumps(data, ensure_ascii=False, indent=2).encode("utf-8")


def main():
    import argparse

    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("pdf_path", type=Path, help="Path to the PAPI Kostick PDF booklet")
    parser.add_argument(
        "--papi-json", type=Path, default=OUTPUT / "papi.json",
        help="Path to the existing papi.json scoring file (default: database/seeders/data/papi.json)",
    )
    args = parser.parse_args()
    data = extract(args.pdf_path, args.papi_json)
    print(f"Wrote papi_items.json: {len(data['items'])} items, status={data['status']}")


if __name__ == "__main__":
    main()
