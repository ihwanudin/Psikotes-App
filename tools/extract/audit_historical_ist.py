import argparse
import re
from pathlib import Path

import openpyxl
from .common import cell_text


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("source_root", type=Path)
    args = parser.parse_args()
    path = args.source_root / "Penilaian IST.xlsx"
    formulas = openpyxl.load_workbook(path, data_only=False)
    cached = openpyxl.load_workbook(path, data_only=True)
    source = cached["Respon"]
    process_formula = formulas["Proses"]
    process_cached = cached["Proses"]
    blocks = [("SE",2,21,179),("WA",22,41,180),("AN",42,61,181),("GE",62,77,182),
              ("RA",78,97,184),("ZR",98,117,185),("FA",118,137,186),("WU",138,157,187),("ME",158,177,183)]
    checked = 0
    mismatches = 0
    for process_row in range(5, 249):
        if checked == 5: break
        calculated = {}
        valid = True
        for code, lo, hi, result_col in blocks:
            total = 0
            for col in range(lo, hi + 1):
                formula = process_formula.cell(5, col).value
                formula = formula.text if hasattr(formula, "text") else str(formula)
                match = re.search(r"Respon!([A-Z]+)2", formula)
                if not match: valid = False; break
                response = source[f"{match.group(1)}{process_row-3}"].value
                if code == "GE":
                    dictionary = {a:int(s) for a,s in re.findall(r'="([^"]+)",(1|2)',formula)}
                    total += dictionary.get(str(response).strip().lower(), 0)
                elif code in ("RA", "ZR"):
                    key_cell = process_formula.cell(3, col)
                    key = cell_text(key_cell.value, key_cell.number_format)
                    answer = cell_text(response)
                    total += bool(answer) and all(char in key for char in answer)
                elif code in ("FA", "WU"):
                    key_cell = process_formula.cell(3, col)
                    key = cell_text(key_cell.value, key_cell.number_format)
                    total += cell_text(response) == key
                else:
                    key_cell = process_formula.cell(3, col)
                    key = cell_text(key_cell.value, key_cell.number_format)
                    answer = cell_text(response).split(")",1)[0]
                    total += answer == key
            if not valid: break
            calculated[code] = int(total)
            expected = process_cached.cell(process_row,result_col).value
            if expected != total:
                if expected not in (None, 0) and total:
                    mismatches += 1
                    if mismatches <= 5: print(f"mismatch-{mismatches}: {code} expected={expected} calculated={total}")
                valid = False
        if valid and calculated["GE"] > 0:
            checked += 1
            completeness = "COMPLETE" if all(calculated.values()) else "PARTIAL"
            print(f"sample-{checked}: MATCH_{completeness} {calculated}")
    if checked < 4:
        raise SystemExit(f"Only {checked}/4 complete historical IST samples matched")
    print(f"historical audit: {checked} complete samples matched; cached RA anomalies={mismatches}")


if __name__ == "__main__": main()
