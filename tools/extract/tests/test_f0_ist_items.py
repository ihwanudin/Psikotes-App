import json
import unittest
from pathlib import Path

DATA = Path(__file__).parents[3] / "database" / "seeders" / "data"

# This module covers only the text-based subtests. FA/WU (images) are
# covered by test_f0_ist_fa_wu_items.py - both are present in the same
# ist_items.json (FA/WU merged in by extract_ist_fa_wu.py), so tests here
# that iterate "every subtest" are scoped to TEXT_SUBTEST_COUNTS on purpose,
# not the full file.
TEXT_SUBTEST_COUNTS = {"SE": 20, "WA": 20, "AN": 20, "GE": 16, "RA": 20, "ZR": 20, "ME": 20}
LETTERED_SUBTESTS = {"SE", "WA", "AN", "ME"}
FILL_IN_SUBTESTS = {"GE": "fill_in_word", "RA": "fill_in_numeric", "ZR": "fill_in_numeric"}


class IstItemsGateTest(unittest.TestCase):
    """Structural invariants for the text-based subtests in `ist_items.json`.

    No byte-hash pin in this module: `ist_items.json` now also carries FA/WU
    (merged in by extract_ist_fa_wu.py), which are still `status: "draft"`
    pending Lead's visual review of the crop contact sheets - the hash gate
    for the *whole* file (following the extract_aspect_sources.py/test_f0.py
    pattern used for RMIB/PAPI/this file's own text-only predecessor) is
    added once that review clears and FA/WU flip to "final", not before."""

    def load(self):
        return json.loads((DATA / "ist_items.json").read_text(encoding="utf-8"))

    def load_ist(self):
        return json.loads((DATA / "ist.json").read_text(encoding="utf-8"))

    def test_top_level_status_is_draft_because_me_and_fa_wu_are_draft(self):
        data = self.load()
        self.assertEqual(data["status"], "draft")

    def test_text_subtests_present_and_final(self):
        data = self.load()
        for code in TEXT_SUBTEST_COUNTS:
            self.assertIn(code, data["subtests"])
            if code != "ME":
                self.assertEqual(data["subtests"][code]["status"], "final", code)

    def test_item_counts_per_subtest(self):
        data = self.load()
        for code, expected_count in TEXT_SUBTEST_COUNTS.items():
            items = data["subtests"][code]["items"]
            self.assertEqual(len(items), expected_count, code)

    def test_item_numbers_are_globally_contiguous_and_unique(self):
        data = self.load()
        order = ["SE", "WA", "AN", "GE", "RA", "ZR"]  # ME (157-176) checked separately below
        all_numbers = []
        for code in order:
            all_numbers.extend(item["item"] for item in data["subtests"][code]["items"])
        self.assertEqual(len(all_numbers), len(set(all_numbers)))
        self.assertEqual(all_numbers, sorted(all_numbers))
        self.assertEqual(min(all_numbers), 1)
        self.assertEqual(max(all_numbers), 116)  # ZR ends at 116; FA/WU/ME (117-176) not covered here
        me_numbers = [item["item"] for item in data["subtests"]["ME"]["items"]]
        self.assertEqual(me_numbers, list(range(157, 177)))

    def test_lettered_items_have_exactly_five_options_and_no_scoring_fields(self):
        data = self.load()
        for code in LETTERED_SUBTESTS:
            for item in data["subtests"][code]["items"]:
                self.assertEqual(set(item["options"]), set("abcde"), (code, item["item"]))
                for value in item["options"].values():
                    self.assertIsInstance(value, str)
                    self.assertTrue(value.strip())
                allowed_keys = {"item", "options"} | ({"text"} if code != "WA" else set())
                self.assertEqual(set(item), allowed_keys, (code, item["item"]))

    def test_fill_in_subtests_have_no_options_and_correct_answer_type(self):
        data = self.load()
        for code, expected_type in FILL_IN_SUBTESTS.items():
            entry = data["subtests"][code]
            self.assertEqual(entry["answer_type"], expected_type)
            for item in entry["items"]:
                self.assertEqual(set(item), {"item", "text"})
                self.assertTrue(item["text"].strip())

    def test_answer_key_letter_present_among_extracted_options(self):
        data = self.load()
        ist = self.load_ist()
        starts = {"SE": 1, "WA": 21, "AN": 41, "ME": 157}
        for code, start in starts.items():
            options_by_item = {item["item"]: item["options"] for item in data["subtests"][code]["items"]}
            for entry in ist["keys"]:
                if entry["subtest"] != code:
                    continue
                global_item = entry["item"] + start - 1
                self.assertIn(entry["key"], options_by_item[global_item], (code, global_item))

    def test_no_leftover_extraction_artifacts(self):
        data = self.load()
        payload = json.dumps(data, ensure_ascii=False)
        self.assertNotIn("�", payload)  # replacement character
        self.assertNotIn("←", payload)  # stray answer-sheet arrow glyph
        for code in TEXT_SUBTEST_COUNTS:
            entry = data["subtests"][code]
            texts = [item.get("text", "") for item in entry["items"]]
            texts += [v for item in entry["items"] for v in item.get("options", {}).values()]
            for text in texts:
                self.assertNotIn("  ", text, (code, text))
                self.assertEqual(text, text.strip(), (code, text))

    def test_instructions_present_for_every_subtest(self):
        data = self.load()
        for code in TEXT_SUBTEST_COUNTS:
            text = data["subtests"][code]["instructions"]["text"]
            self.assertGreater(len(text), 50, code)
            self.assertNotIn("  ", text, code)

    def test_me_is_draft_with_both_word_list_variants_recorded(self):
        data = self.load()
        me = data["subtests"]["ME"]
        self.assertEqual(me["status"], "draft")
        self.assertIn("draft_reason", me)
        variants = me["word_list_variants"]
        self.assertEqual(len(variants), 2)
        self.assertEqual(sum(v["printed_count"] for v in variants), 4)
        burung = {tuple(v["categories"]["BURUNG"]) for v in variants}
        kesenian = {tuple(v["categories"]["KESENIAN"]) for v in variants}
        self.assertEqual(len(burung), 2)
        self.assertEqual(len(kesenian), 2)
        for v in variants:
            self.assertEqual(set(v["categories"]), {"BUNGA", "PERKAKAS", "BURUNG", "KESENIAN", "BINATANG"})
            for words in v["categories"].values():
                self.assertEqual(len(words), 5)

    def test_no_participant_data_or_secrets(self):
        payload = (DATA / "ist_items.json").read_text(encoding="utf-8")
        for marker in ("@", "tanggal_lahir", "no_tes", "password", "token"):
            self.assertNotIn(marker, payload.lower())


if __name__ == "__main__":
    unittest.main()
