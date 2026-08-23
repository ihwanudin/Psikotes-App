import json
import re
from datetime import date, datetime
from pathlib import Path


OUTPUT = Path(__file__).parents[2] / "database" / "seeders" / "data"


def write(name, data):
    OUTPUT.mkdir(parents=True, exist_ok=True)
    (OUTPUT / name).write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")


def bounds(value):
    nums = [int(x) for x in re.findall(r"\d+", str(value))]
    return [nums[0], nums[-1]]


def cell_text(value, number_format=None):
    """Return stable text without turning integer-valued Excel cells into `x.0`."""
    compact_format = str(number_format or "").lower().replace(" ", "")
    if isinstance(value, (date, datetime)) and compact_format == "m,d":
        # Excel auto-converted the intended RA key `3, 5` into a date serial.
        return f"{value.month}, {value.day}"
    if isinstance(value, float) and value.is_integer():
        return str(int(value))
    return "" if value is None else str(value).strip()
