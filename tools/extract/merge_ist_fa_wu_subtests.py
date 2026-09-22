"""Merge reviewed IST FA/WU subtests into the current ist_items.json.

This is the no-PDF reconciliation path for PR #73's already-reviewed FA/WU
payload. The source PDF used by extract_ist_fa_wu.py is intentionally not
committed to the repository, so this script reads the already-committed FA/WU
subtrees from the PR branch and applies the exact same merge operation that
extract_ist_fa_wu.py uses after extraction.
"""

import json
import subprocess
from pathlib import Path


DATA = Path(__file__).parents[2] / "database" / "seeders" / "data"
IST_ITEMS = DATA / "ist_items.json"
SOURCE_REF = "origin/f0/extract-ist-fa-wu:database/seeders/data/ist_items.json"


def _load_source_subtests() -> dict:
    payload = subprocess.check_output(["git", "show", SOURCE_REF])
    source = json.loads(payload)
    return {code: source["subtests"][code] for code in ("FA", "WU")}


def merge() -> dict:
    existing = json.loads(IST_ITEMS.read_bytes()) if IST_ITEMS.exists() else {"subtests": {}}
    fa_wu_subtests = _load_source_subtests()

    existing["subtests"].update(fa_wu_subtests)
    existing["status"] = "draft" if any(s["status"] == "draft" for s in existing["subtests"].values()) else "final"

    IST_ITEMS.write_bytes(json.dumps(existing, ensure_ascii=False, indent=2).encode("utf-8"))
    return existing


if __name__ == "__main__":
    merged = merge()
    print(
        "Merged IST FA/WU subtests into "
        f"{IST_ITEMS} ({len(merged['subtests'])} subtests, status={merged['status']})"
    )
