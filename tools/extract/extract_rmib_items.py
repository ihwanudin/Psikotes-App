"""Extract RMIB item content (instructions + the 108 job pairs) for the test-taking UI.

Two independent sources are cross-checked, per plan agreed with the coordinator:
  - xlsx `Input Jawaban` sheet (authoritative structure: Kelompok/Posisi/Pekerjaan L/P/Kategori)
  - the RMIB PDF booklet (participant-facing text; the 9 job groups are printed 3-per-row
    in side-by-side columns, so `pypdf`'s `extraction_mode="layout"` is used - it
    reconstructs a character-grid using actual glyph metrics, which keeps each column's
    text in its own run of the line and avoids the phantom spaces/joins that plain
    text-object concatenation introduces at word/column boundaries)

Every one of the 108 (group, position) job pairs must match between the two sources,
and every xlsx Kategori must match the rotation formula already encoded in `rmib.json`
(`category = ((position + group - 2) % 12) + 1`). Any mismatch stops extraction rather
than guessing - see AGENTS.md / CLAUDE.md fail-closed conventions.

The group-letter-to-numeric-group mapping (A..I -> 1..9) is *derived* from matching
each letter's 12 (position, category) pairs against rmib.json's rotation, not assumed
to be alphabetical, per explicit instruction from the coordinator.
"""

import json
import re
from pathlib import Path

import openpyxl
from pypdf import PdfReader

from .common import OUTPUT

VERSION = "F0-ITEMS-RMIB-2026.09"
GROUP_LETTERS = frozenset("ABCDEFGHI")

# Page-1 job grid: printed as 3 row-bands, each band = 1 header/label row (naming the
# 3 groups shown that band) followed by 12 data rows (positions 1-12). Fixed structural
# layout of a static, already-printed booklet - same convention as the hardcoded
# lookup-sheet row ranges in the other tools/extract/extract_*.py modules; asserted
# below rather than trusted blindly.
_BANDS = ((0, range(1, 13)), (13, range(14, 26)), (26, range(27, 39)))
_COLUMN_GAP = re.compile(r"\s{2,}")

# Page-2 instructions: the participant-facing "PETUNJUK" paragraph sits in the page's
# right-hand block; a few lines share their row with unrelated left-hand page furniture
# (the "(1)/(2)/(3)" write-in blanks before PETUNJUK, demographic field labels above
# it) separated by a much larger gap than any in-paragraph justification spacing.
# Measured on this PDF: the widest in-content/justification gap is 23 chars (a
# "Label     :" pair); the narrowest real left/right block gap is 137 chars - so any
# threshold comfortably between those (40) safely tells them apart.
_BLOCK_GAP = re.compile(r"\s{40,}")
_DEMOGRAPHIC_LABELS = frozenset({
    "Nama", "Umur", "Jenis kelamin", "Kelas/jurusan", "Sekolah", "Tanggal tes",
})


def build(xlsx_path, pdf_path, rmib_json_path):
    xlsx_entries = _read_xlsx(xlsx_path)
    pdf_entries, instructions = _read_pdf(pdf_path)
    rmib_reference = json.loads(Path(rmib_json_path).read_text(encoding="utf-8"))

    _cross_check_jobs(xlsx_entries, pdf_entries)
    letter_to_group = _derive_letter_to_group(xlsx_entries, rmib_reference)

    positions = []
    for (letter, position), entry in sorted(xlsx_entries.items(), key=lambda kv: (kv[0][0], kv[0][1])):
        positions.append({
            "group": letter_to_group[letter],
            "group_letter": letter,
            "position": position,
            "job_male": entry["job_l"],
            "job_female": entry["job_p"],
        })

    if len(positions) != 108:
        raise ValueError(f"Expected 108 RMIB positions, got {len(positions)}")

    return {
        "version": VERSION,
        "status": "final",
        "instructions": instructions,
        "positions": positions,
    }


def extract(xlsx_path, pdf_path, rmib_json_path, output=OUTPUT):
    data = build(xlsx_path, pdf_path, rmib_json_path)
    output.mkdir(parents=True, exist_ok=True)
    (output / "rmib_items.json").write_bytes(_serialize(data))
    return data


# ---------------------------------------------------------------------------
# xlsx (authoritative structure)
# ---------------------------------------------------------------------------

def _read_xlsx(xlsx_path):
    workbook = openpyxl.load_workbook(xlsx_path, data_only=True, read_only=True)
    try:
        sheet = workbook["Input Jawaban"]
        rows = list(sheet.iter_rows(min_row=2, max_row=109, values_only=True))
    finally:
        workbook.close()

    entries = {}
    for row in rows:
        letter, position, job_l, job_p, category = row[0], row[1], row[2], row[3], row[4]
        if letter is None:
            continue
        letter = str(letter).strip()
        if letter not in GROUP_LETTERS:
            raise ValueError(f"Unexpected RMIB group letter in xlsx: {letter!r}")
        position = int(position)
        if not (1 <= position <= 12):
            raise ValueError(f"Unexpected RMIB position in xlsx: {position!r}")
        key = (letter, position)
        if key in entries:
            raise ValueError(f"Duplicate xlsx row for group {letter} position {position}")
        entries[key] = {
            "job_l": _normalize(job_l),
            "job_p": _normalize(job_p),
            "category": _normalize(category),
        }

    if len(entries) != 108:
        raise ValueError(f"Expected 108 rows in xlsx Input Jawaban, got {len(entries)}")
    for letter in GROUP_LETTERS:
        count = sum(1 for (l, _p) in entries if l == letter)
        if count != 12:
            raise ValueError(f"Group {letter} has {count} xlsx rows, expected 12")

    return entries


# ---------------------------------------------------------------------------
# PDF (participant-facing text; cross-check source)
# ---------------------------------------------------------------------------

def _read_pdf(pdf_path):
    reader = PdfReader(pdf_path)
    if len(reader.pages) != 2:
        raise ValueError(f"Expected a 2-page RMIB PDF, got {len(reader.pages)} pages")

    job_entries = _read_job_grid(reader.pages[0])
    instructions = _read_instructions(reader.pages[1])
    return job_entries, instructions


def _layout_lines(page):
    text = page.extract_text(extraction_mode="layout")
    return [line for line in text.splitlines() if line.strip()]


def _columns(line):
    return [part.strip() for part in _COLUMN_GAP.split(line) if part.strip()]


def _blocks(line):
    return [part.strip() for part in _BLOCK_GAP.split(line) if part.strip()]


def _read_job_grid(page):
    lines = _layout_lines(page)
    if len(lines) != 39:
        raise ValueError(
            f"Expected 39 text lines on RMIB PDF page 1 (1 header + 12 data, "
            f"repeated x3 bands), got {len(lines)} - PDF layout may have changed"
        )

    rows = [_columns(line) for line in lines]

    entries = {}
    for header_idx, data_range in _BANDS:
        header = rows[header_idx]
        letters = _band_group_letters(header, header_idx)
        for pair in range(3):
            letter = letters[pair]
            for position, row_idx in enumerate(data_range, start=1):
                columns = rows[row_idx]
                if len(columns) != 6:
                    raise ValueError(
                        f"Expected 6 columns on job-grid row {row_idx} "
                        f"(group {letter} position {position}), got {columns!r}"
                    )
                key = (letter, position)
                if key in entries:
                    raise ValueError(f"Duplicate PDF entry for group {letter} position {position}")
                entries[key] = {
                    "job_l": _normalize(columns[pair * 2]),
                    "job_p": _normalize(columns[pair * 2 + 1]),
                }

    if len(entries) != 108:
        raise ValueError(f"Expected 108 PDF job entries, got {len(entries)}")
    return entries


def _band_group_letters(header_columns, header_idx):
    """Band 0's header row also carries "Laki-laki"/"Perempuan" labels (9 columns:
    Laki-laki, <letter>, Perempuan, x3); band 13/26's label rows are bare (3 columns:
    <letter> x3)."""
    if header_idx == 0:
        if len(header_columns) != 9:
            raise ValueError(f"Expected 9 columns in the main header row, got {header_columns!r}")
        letters = [header_columns[3 * pair + 1] for pair in range(3)]
    else:
        if len(header_columns) != 3:
            raise ValueError(f"Expected 3 columns in a group-label row, got {header_columns!r}")
        letters = list(header_columns)

    if not all(letter in GROUP_LETTERS for letter in letters):
        raise ValueError(f"Expected 3 group letters A-I, got {letters!r}")
    return letters


def _read_instructions(page):
    lines = _layout_lines(page)
    columns = [_blocks(line) for line in lines]

    petunjuk_idx = next((i for i, cols in enumerate(columns) if cols == ["PETUNJUK"]), None)
    if petunjuk_idx is None:
        raise ValueError("Could not locate 'PETUNJUK' heading on RMIB PDF page 2")

    prompt_parts = [
        cols[0] for cols in columns[:petunjuk_idx]
        if cols and _normalize(cols[0]).rstrip(":").strip() not in _DEMOGRAPHIC_LABELS
    ]
    paragraph_parts = [cols[-1] for cols in columns[petunjuk_idx + 1:] if cols]

    text = _normalize(" ".join(paragraph_parts))
    write_prompt = _normalize(" ".join(prompt_parts))

    if not text or not write_prompt:
        raise ValueError("Failed to reconstruct RMIB instructions text from PDF page 2")

    return {"text": text, "write_preferred_jobs_prompt": write_prompt}


# ---------------------------------------------------------------------------
# Cross-checks
# ---------------------------------------------------------------------------

def _cross_check_jobs(xlsx_entries, pdf_entries):
    if set(xlsx_entries) != set(pdf_entries):
        missing_in_pdf = sorted(set(xlsx_entries) - set(pdf_entries))
        missing_in_xlsx = sorted(set(pdf_entries) - set(xlsx_entries))
        raise ValueError(
            f"xlsx/PDF RMIB position sets differ. Missing in PDF: {missing_in_pdf}. "
            f"Missing in xlsx: {missing_in_xlsx}"
        )

    mismatches = []
    for key in sorted(xlsx_entries):
        xlsx_entry, pdf_entry = xlsx_entries[key], pdf_entries[key]
        for field in ("job_l", "job_p"):
            if xlsx_entry[field] != pdf_entry[field]:
                mismatches.append((key, field, xlsx_entry[field], pdf_entry[field]))

    if mismatches:
        details = "\n".join(
            f"  group={k[0]} position={k[1]} {field}: xlsx={xv!r} pdf={pv!r}"
            for k, field, xv, pv in mismatches
        )
        raise ValueError(f"xlsx/PDF RMIB job text mismatch for {len(mismatches)} field(s):\n{details}")


def _derive_letter_to_group(xlsx_entries, rmib_reference):
    category_index = {c["code"]: c["index"] for c in rmib_reference["categories"]}
    rotation_lookup = {
        (r["group"], r["position"]): r["category"] for r in rmib_reference["rotation"]
    }
    numeric_groups = sorted({g for g, _p in rotation_lookup})

    letter_to_group = {}
    for letter in GROUP_LETTERS:
        xlsx_pairs = {
            position: category_index[entries["category"]]
            for (l, position), entries in xlsx_entries.items()
            if l == letter
        }
        candidates = [
            g for g in numeric_groups
            if all(rotation_lookup.get((g, position)) == category for position, category in xlsx_pairs.items())
        ]
        if len(candidates) != 1:
            raise ValueError(
                f"Group letter {letter} matches {len(candidates)} numeric group(s) in "
                f"rmib.json rotation (expected exactly 1): {candidates}"
            )
        letter_to_group[letter] = candidates[0]

    if sorted(letter_to_group.values()) != numeric_groups:
        raise ValueError("Derived letter-to-group mapping does not cover every numeric group exactly once")

    return letter_to_group


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _normalize(value):
    return re.sub(r"\s+", " ", str(value or "")).strip()


def _serialize(data):
    return json.dumps(data, ensure_ascii=False, indent=2).encode("utf-8")


def main():
    import argparse

    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("xlsx_path", type=Path, help="Path to RMIB_Master_Formula_Skoring.xlsx")
    parser.add_argument("pdf_path", type=Path, help="Path to the RMIB PDF booklet")
    parser.add_argument(
        "--rmib-json", type=Path, default=OUTPUT / "rmib.json",
        help="Path to the existing rmib.json scoring file (default: database/seeders/data/rmib.json)",
    )
    args = parser.parse_args()
    data = extract(args.xlsx_path, args.pdf_path, args.rmib_json)
    print(f"Wrote rmib_items.json: {len(data['positions'])} positions, status={data['status']}")


if __name__ == "__main__":
    main()
