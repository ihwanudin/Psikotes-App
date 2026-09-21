import hashlib
import json
import tempfile
import unittest
from pathlib import Path

import openpyxl

from tools.extract import extract_aspect_sources
from tools.extract.validate import kraepelin_factors, kraepelin_score


DATA = Path(__file__).parents[3] / "database" / "seeders" / "data"


class F0GateTest(unittest.TestCase):
    ASPECT_SOURCES = {
        "A1": ["IST_IQ"],
        "A2": ["IST_AN", "IST_RA", "IST_ZR", "PAPI_R"],
        "B1": ["IST_ME", "KRAEPELIN_PANKER", "KRAEPELIN_JANKER"],
        "B2": ["KRAEPELIN_PANKER", "KRAEPELIN_TIANKER"],
        "B3": ["IST_GE", "IST_SE", "IST_WA"],
        "B4": ["KRAEPELIN_JANKER", "PAPI_C", "PAPI_D"],
        "C1": ["PAPI_L", "PAPI_E"],
        "C2": ["PAPI_N", "PAPI_F", "PAPI_S"],
        "C3": ["PAPI_P", "PAPI_A", "PAPI_B", "PAPI_O"],
        "C4": ["PAPI_E", "PAPI_K", "KRAEPELIN_HANKER"],
        "C5": ["KRAEPELIN_HANKER", "PAPI_V"],
        "C6": ["PAPI_T", "PAPI_V", "PAPI_N"],
        "C7": ["PAPI_W", "PAPI_F", "PAPI_R", "KRAEPELIN_JANKER"],
        "D1": ["RMIB_Out"],
        "D2": ["RMIB_Me"],
        "D3": ["RMIB_Prac"],
        "D4": ["RMIB_Med"],
        "D5": ["RMIB_S.Se"],
    }

    def load(self, name):
        return json.loads((DATA / name).read_text(encoding="utf-8"))

    def test_ist_two_blocks_and_keys(self):
        data = self.load("ist.json")
        self.assertEqual(len(data["keys"]), 160)
        self.assertEqual(len(data["ge_dictionary"]), 16)
        self.assertEqual(set(data["norms"]["GE"]), {str(i) for i in range(33)})
        self.assertEqual(set(data["norms"]["SE"]), {str(i) for i in range(21)})
        self.assertEqual(data["norms"]["SE"]["16"], 131)
        self.assertTrue(all(item["answers"] for item in data["ge_dictionary"]))
        self.assertEqual(len(data["sw_score_bands"]), 10)
        self.assertEqual(len(data["iq_score_bands"]), 10)
        self.assertEqual(
            [(band["lo"], band["hi"], band["level"]) for band in data["iq_level_bands"]],
            [(127, None, 5), (115, 126, 4), (103, 114, 3), (91, 102, 2), (None, 90, 1)],
        )
        self.assertTrue(all(not item["key"].endswith(".0") for item in data["keys"]))
        ra1 = next(item for item in data["keys"] if item["subtest"] == "RA" and item["item"] == 1)
        self.assertEqual(ra1["key"], "3, 5")

    def test_ist_norms_are_monotonic(self):
        data = self.load("ist.json")
        for code, table in data["norms"].items():
            values = [value for _, value in sorted((int(k), v) for k, v in table.items())]
            self.assertEqual(values, sorted(values), code)

    def test_ist_iq_ranges_are_complete_and_monotonic(self):
        data = self.load("ist.json")
        expanded = {}
        for band in data["iq_ranges"]:
            for raw_total in range(band["lo"], band["hi"] + 1):
                self.assertNotIn(raw_total, expanded)
                expanded[raw_total] = band["iq"]
        self.assertEqual(set(expanded), set(range(28, 152)))
        values = [expanded[raw_total] for raw_total in sorted(expanded)]
        self.assertEqual(values, sorted(values))

    def test_ist_score_bands_cover_lookup_domain(self):
        data = self.load("ist.json")
        for field, domain in (("sw_score_bands", range(50, 151)), ("iq_score_bands", range(50, 151))):
            for value in domain:
                matches = [band for band in data[field]
                           if (band["lo"] is None or value >= band["lo"])
                           and (band["hi"] is None or value <= band["hi"])]
                self.assertEqual(len(matches), 1, (field, value))

    def test_papi_invariants(self):
        data = self.load("papi.json")
        self.assertEqual(len(data["mapping"]), 90)
        self.assertEqual(sum(x["type"] == "ROLE" for x in data["mapping"]), 45)
        self.assertEqual(sum(x["type"] == "NEED" for x in data["mapping"]), 45)
        self.assertEqual(len(data["white_zones"]), 20)
        self.assertEqual(len(data["bands"]), 20)
        counts = {}
        for item in data["mapping"]:
            counts[item["a"]] = counts.get(item["a"], 0) + 1
            counts[item["b"]] = counts.get(item["b"], 0) + 1
        self.assertTrue(all(value == 9 for value in counts.values()))
        for code, dimension in data["bands"].items():
            covered = []
            for zone in dimension["zones"]:
                covered.extend(range(zone["lo"], zone["hi"] + 1))
            self.assertEqual(sorted(covered), list(range(10)), code)
            self.assertEqual(len(covered), len(set(covered)), code)
        self.assertEqual(data["band_scores"], {
            "white": 8, "blue_low": 7, "blue_high": 7,
            "yellow_low": 3, "yellow_high": 5,
        })
        self.assertEqual(data["normalization"]["method"], "distance_from_white_zone")
        self.assertEqual(data["normalization"]["distance_to_level"], {"0": 5, "1": 4, "2": 3, "3": 2, "4": 1})
        self.assertEqual(data["normalization"]["excluded_from_hpp"], ["G", "I", "X", "Z"])

    def test_rmib_invariants(self):
        data = self.load("rmib.json")
        self.assertEqual(sum(range(1, 13)) * 9, 702)
        self.assertTrue(all(sum(range(1, 13)) == 78 for _ in range(9)))
        self.assertEqual(len(data["rotation"]), 108)
        self.assertEqual(data["tie_policy"], "competition_ranking")
        self.assertEqual(data["rank_to_level"], {str(rank): (score + 1) // 2 for rank, score in data["rank_to_score"].items()})

    def test_dass_invariants(self):
        data = self.load("dass21.json")
        self.assertEqual(len(data["items"]), 21)
        for scale in "DAS":
            self.assertEqual(sum(x["scale"] == scale for x in data["items"]), 7)
        self.assertEqual(len(data["narratives"]), 20)
        self.assertEqual(sum(x["type"] == "Subskala" for x in data["narratives"]), 15)
        self.assertEqual(sum(x["type"] == "Kategori umum" for x in data["narratives"]), 5)
        self.assertEqual(set(data["follow_up"]), {"monitoring", "referral"})
        self.assertTrue(all(x["text_id"] and x["text_jp"] for x in data["narratives"]))
        self.assertTrue(all(x["text_id"] and x["text_jp"] for x in data["follow_up"].values()))

    def test_kraepelin_golden_1(self):
        y = [18,19,19,18,15,19,17,17,13,16,16,13,14,15,16,14,15,15,15,15,13,16,12,18,18,16,16,15,15,17,15,18,16,16,16,15,15,14,15,18,17,16,13,15,16,18,17,17,14,17]
        self.assertEqual(kraepelin_factors(y, 7, 0), {"panker": 15.86, "tianker": 7, "hanker": -0.622, "janker": 7})

    def test_kraepelin_golden_2(self):
        y = [10,11,11,10,12,11,11,12,12,11,12,12,11,12,13,12,12,13,12,13,12,13,13,12,14,13,13,14,13,14,13,14,14,13,15,14,14,15,14,15,14,15,15,14,16,15,15,16,15,16]
        self.assertEqual(kraepelin_factors(y, 4, 1), {"panker": 13.12, "tianker": 5, "hanker": 5.032, "janker": 6})

    KRAEPELIN_GRID_HASH = "6df51224c36bea665b9f8b0f8792139489f3c3204476089134657e3ae6ff9ee0"

    def test_kraepelin_golden_scores(self):
        data = self.load("kraepelin.json")
        bands = data["score_bands"]
        self.assertEqual(data["panker_achievement"], "correct_plus_incorrect")
        self.assertEqual(data["factor_rounding"], {"stage": "before_band_lookup", "precision": 3, "mode": "half_up"})
        first = [("Panker",15.86),("Tianker",7),("Hanker",-0.622),("Janker",7)]
        self.assertEqual([kraepelin_score(bands,f,"S1/S2 (IPA)",v) for f,v in first], [7,6,4,6])
        second = [("Panker",13.12),("Tianker",5),("Janker",6),("Hanker",5.032)]
        scores = [kraepelin_score(bands,f,"SMA/SMK",v) for f,v in second]
        self.assertEqual([(score + 1)//2 for score in scores], [4,4,4,5])

    def test_kraepelin_digit_grid_structure(self):
        # Grid lives in its own file, not inside kraepelin.json — see
        # test_kraepelin_json_has_no_digit_grid_key below for why.
        dg = self.load("kraepelin_grid.json")
        grid = dg["grid"]

        self.assertEqual(len(grid), 28, "harus 28 baris")
        self.assertTrue(all(len(row) == 50 for row in grid), "setiap baris harus 50 kolom")
        for row in grid:
            for cell in row:
                self.assertIsInstance(cell, int)
                self.assertGreaterEqual(cell, 1)
                self.assertLessEqual(cell, 9)

        self.assertEqual(dg["numbers_per_column"], 28)
        self.assertEqual(dg["answer_slots_per_column"], 27)
        self.assertIn("version", dg)

        computed = hashlib.sha256(
            json.dumps(grid, separators=(",", ":")).encode()
        ).hexdigest()
        self.assertEqual(computed, self.KRAEPELIN_GRID_HASH)
        self.assertEqual(dg["sha256"], self.KRAEPELIN_GRID_HASH)

    def test_kraepelin_json_has_no_digit_grid_key(self):
        # SealPrecomputedKraepelinFactors::hasExactFields() requires
        # kraepelin.json to contain EXACTLY 5 fields (version,
        # hanker_formula, panker_achievement, factor_rounding,
        # score_bands) — a 6th field (digit_grid was briefly added here)
        # stops Kraepelin scoring closed. The grid belongs in its own
        # file (kraepelin_grid.json), never inline here.
        data = self.load("kraepelin.json")
        self.assertNotIn("digit_grid", data)
        self.assertEqual(
            set(data.keys()),
            {
                "version",
                "hanker_formula",
                "panker_achievement",
                "factor_rounding",
                "score_bands",
            },
        )

    def test_reporting_data(self):
        data = self.load("reporting.json")
        self.assertEqual(data["standard_version"], "GA-2026.08")
        self.assertEqual(len(data["base_standards"]), 18)
        self.assertTrue(all(data["base_standards"][code] == 3 for code in ["A1", "A2", "B1", "B2", "B3", "B4", "C1", "C2", "C3", "C4", "C5", "C6", "C7"]))
        self.assertTrue(all(data["base_standards"][code] is None for code in ["D1", "D2", "D3", "D4", "D5"]))
        kaigo = next(field for field in data["fields"] if field["code"] == "KAIGO")
        self.assertEqual(kaigo["required_interest"], "D4")
        self.assertEqual(kaigo["required_interest_standard"], 3)
        self.assertEqual(kaigo["raised_standards"], {"C2": 4, "C3": 4, "C4": 4})
        self.assertTrue(all(field["raised_to_4"] == list(field["raised_standards"]) for field in data["fields"]))
        self.assertEqual(data["critical"], ["A1","B2","C4","C5"])
        self.assertEqual(len(data["fields"]), 6)
        self.assertEqual(len(data["narratives"]), 90)

    def test_aspect_source_data_is_the_frozen_42_association_contract(self):
        data = self.load("aspect_sources.json")

        self.assertEqual(data, {
            "version": "ASPECT-SOURCES-2026.09",
            "aspects": self.ASPECT_SOURCES,
        })
        self.assertEqual(list(data["aspects"]), list(self.ASPECT_SOURCES))
        self.assertEqual(sum(len(sources) for sources in data["aspects"].values()), 42)
        self.assertEqual(len({source for sources in data["aspects"].values() for source in sources}), 33)
        self.assertNotIn("RMIB_Prs", str(data))
        self.assertNotIn("DASS", str(data))
        extract_aspect_sources.validate_source_identifiers(data, DATA)

    def test_aspect_source_bytes_are_deterministic(self):
        payload = (DATA / "aspect_sources.json").read_bytes()

        self.assertEqual(len(payload), 1148)
        self.assertEqual(
            hashlib.sha256(payload).hexdigest(),
            "cdd6c3b89e9ee792db14a9b6878e10c2ddd50143224582c0c99d2bfd03b4d9a9",
        )
        self.assertEqual(
            payload,
            json.dumps(self.load("aspect_sources.json"), ensure_ascii=False, indent=2).encode("utf-8"),
        )

    def test_aspect_source_extractor_reads_formula_counts_and_exact_rmib_categories(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.write_aspect_source_workbook(root)

            first = extract_aspect_sources.build(root)
            second = extract_aspect_sources.build(root)

        self.assertEqual(first, {
            "version": "ASPECT-SOURCES-2026.09",
            "aspects": self.ASPECT_SOURCES,
        })
        self.assertEqual(first, second)

    def test_aspect_source_extractor_writes_stable_utf8_lf_bytes_to_a_temp_output(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.write_aspect_source_workbook(root)
            output = root / "output"

            extract_aspect_sources.extract(root, output, DATA)
            first = (output / "aspect_sources.json").read_bytes()
            extract_aspect_sources.extract(root, output, DATA)
            second = (output / "aspect_sources.json").read_bytes()

        self.assertEqual(first, second)
        self.assertNotIn(b"\r\n", first)
        self.assertEqual(len(first), 1148)
        self.assertEqual(
            hashlib.sha256(first).hexdigest(),
            "cdd6c3b89e9ee792db14a9b6878e10c2ddd50143224582c0c99d2bfd03b4d9a9",
        )

    def test_aspect_source_extractor_rejects_a_formula_count_mismatch(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            path = self.write_aspect_source_workbook(root)
            workbook = openpyxl.load_workbook(path)
            workbook["13. Skala Formula"]["D3"] = 3
            workbook.save(path)

            with self.assertRaisesRegex(ValueError, "A2 declares 3 sources but contains 4"):
                extract_aspect_sources.build(root)

    def test_aspect_source_extractor_derives_same_count_source_changes_from_the_workbook(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            path = self.write_aspect_source_workbook(root)
            workbook = openpyxl.load_workbook(path)
            workbook["13. Skala Formula"]["E3"] = "IST AN + IST RA + IST ZR + PAPI L"
            workbook.save(path)

            data = extract_aspect_sources.build(root)

        self.assertEqual(data["aspects"]["A2"], ["IST_AN", "IST_RA", "IST_ZR", "PAPI_L"])
        self.assertNotEqual(data["aspects"]["A2"], self.ASPECT_SOURCES["A2"])

    def test_aspect_source_extractor_rejects_noncanonical_workbook_aspect_order(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            path = self.write_aspect_source_workbook(root)
            workbook = openpyxl.load_workbook(path)
            sheet = workbook["13. Skala Formula"]
            a1 = [sheet.cell(2, column).value for column in range(1, 6)]
            a2 = [sheet.cell(3, column).value for column in range(1, 6)]
            for column, value in enumerate(a2, start=1):
                sheet.cell(2, column).value = value
            for column, value in enumerate(a1, start=1):
                sheet.cell(3, column).value = value
            workbook.save(path)

            with self.assertRaisesRegex(ValueError, "canonical order"):
                extract_aspect_sources.build(root)

    def test_aspect_source_extractor_rejects_persuasive_as_d5(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            path = self.write_aspect_source_workbook(root)
            workbook = openpyxl.load_workbook(path)
            workbook["4. Konversi Skor"]["C5"] = "Prs"
            workbook.save(path)

            with self.assertRaisesRegex(ValueError, "D5 must use only RMIB_S.Se"):
                extract_aspect_sources.build(root)

    def test_aspect_source_extractor_rejects_dass_formula_components(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            path = self.write_aspect_source_workbook(root)
            workbook = openpyxl.load_workbook(path)
            workbook["13. Skala Formula"]["E3"] = "IST AN + IST RA + IST ZR + DASS Stress"
            workbook.save(path)

            with self.assertRaisesRegex(ValueError, "Unknown aspect-source formula component"):
                extract_aspect_sources.build(root)

    @classmethod
    def write_aspect_source_workbook(cls, root):
        path = root / "Update DASS" / "Bank Narasi Formula HPP Psikotes.xlsx"
        path.parent.mkdir(parents=True)
        workbook = openpyxl.Workbook()
        formula = workbook.active
        formula.title = "13. Skala Formula"
        formula.append([None, "KODE", "ASPEK", "JML SUMBER", "SUMBER TERURAI"])
        for code, sources in cls.ASPECT_SOURCES.items():
            rendered = []
            for source in sources:
                instrument, measure = source.split("_", 1)
                rendered.append(f"{instrument.title()} {measure}" if instrument == "KRAEPELIN" else f"{instrument} {measure}")
            if code.startswith("D"):
                rendered = ["RMIB"]
            formula.append([None, code, code, len(sources), " + ".join(rendered)])

        rmib = workbook.create_sheet("4. Konversi Skor")
        rmib.append([None, "INDEX", "KODE", "NAMA KATEGORI", "DIPAKAI DI HPP", "ASPEK HPP"])
        categories = [
            (1, "Out", "Outdoor", "Ya", "D1"),
            (2, "Me", "Mechanical", "Ya", "D2"),
            (5, "Prs", "Persuasive", "Tidak", "-"),
            (9, "S.Se", "Social Service", "Ya", "D5"),
            (11, "Prac", "Practical", "Ya", "D3"),
            (12, "Med", "Medical", "Ya", "D4"),
        ]
        for row in categories:
            rmib.append([None, *row])
        workbook.save(path)

        return path


if __name__ == "__main__":
    unittest.main()
