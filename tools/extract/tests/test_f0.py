import json
import unittest
from pathlib import Path

from tools.extract.validate import kraepelin_factors, kraepelin_score


DATA = Path(__file__).parents[3] / "database" / "seeders" / "data"


class F0GateTest(unittest.TestCase):
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

    def test_rmib_invariants(self):
        data = self.load("rmib.json")
        self.assertEqual(sum(range(1, 13)) * 9, 702)
        self.assertTrue(all(sum(range(1, 13)) == 78 for _ in range(9)))
        self.assertEqual(len(data["rotation"]), 108)

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

    def test_kraepelin_golden_scores(self):
        bands = self.load("kraepelin.json")["score_bands"]
        first = [("Panker",15.86),("Tianker",7),("Hanker",-0.622),("Janker",7)]
        self.assertEqual([kraepelin_score(bands,f,"S1/S2 (IPA)",v) for f,v in first], [7,6,4,6])
        second = [("Panker",13.12),("Tianker",5),("Janker",6),("Hanker",5.032)]
        scores = [kraepelin_score(bands,f,"SMA/SMK",v) for f,v in second]
        self.assertEqual([(score + 1)//2 for score in scores], [4,4,4,5])

    def test_reporting_data(self):
        data = self.load("reporting.json")
        self.assertEqual(data["standard_version"], "GA-2026.08")
        self.assertEqual(data["critical"], ["A1","B2","C4","C5"])
        self.assertEqual(len(data["fields"]), 6)
        self.assertEqual(len(data["narratives"]), 90)


if __name__ == "__main__":
    unittest.main()
