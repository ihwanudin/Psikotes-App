import hashlib
import json
import unittest
from pathlib import Path

DATA = Path(__file__).parents[3] / "database" / "seeders" / "data"

# FA/WU are a separate PR (image crops need visual review) - see
# extract_ist_items.py's module docstring. Everything here covers the
# text-based subtests this PR actually produces.
TEXT_SUBTEST_COUNTS = {"SE": 20, "WA": 20, "AN": 20, "GE": 16, "RA": 20, "ZR": 20, "ME": 20}
LETTERED_SUBTESTS = {"SE", "WA", "AN", "ME"}
FILL_IN_SUBTESTS = {"GE": "fill_in_word", "RA": "fill_in_numeric", "ZR": "fill_in_numeric"}


class IstItemsGateTest(unittest.TestCase):
    """Structural invariants and a fail-closed byte-hash gate for
    `ist_items.json`, following the `extract_aspect_sources.py` / `test_f0.py`
    pattern (as used for RMIB and PAPI). The hash was pinned after Lead's
    independent review (own PDF text extraction, all 516 stem/option strings
    matched after normalization, the 4 RA fraction items re-verified by
    recomputing their answers against ist.json's keys, reported against
    commit f8ac705). Re-pinned 2026-09-22 when ME moved draft -> final via
    `extract_ist_items.py --finalize-me` (psychologist-confirmed P7, "Versi
    A": TEKUKUR/Burung, QUINTET/Kesenian - see
    tasks/handoffs/decisions/owner-decisions-2026-09-21.md item 21). Only
    ME's status/word_list fields and the top-level status changed; every
    other subtest and ME's own items/instructions are byte-identical to the
    prior pin. This pin covers the TEXT-ONLY shape - it will need
    re-pinning once FA/WU (a separate PR) are merged in, since that changes
    the file's bytes. FA/WU are covered by a separate test module once that
    PR lands, not here."""

    def load(self):
        return json.loads((DATA / "ist_items.json").read_text(encoding="utf-8"))

    def load_ist(self):
        return json.loads((DATA / "ist.json").read_text(encoding="utf-8"))

    def test_bytes_are_deterministic(self):
        payload = (DATA / "ist_items.json").read_bytes()
        self.assertEqual(len(payload), 36105)
        self.assertEqual(
            hashlib.sha256(payload).hexdigest(),
            "f4b3015fc1e5dd8c24c622cc5e10c3e8a4c03fc99b183b235be9fc8e2b6b6304",
        )
        self.assertEqual(payload, json.dumps(self.load(), ensure_ascii=False, indent=2).encode("utf-8"))

    def test_top_level_status_is_final_since_me_is_now_final(self):
        data = self.load()
        self.assertEqual(data["status"], "final")

    def test_fa_wu_are_absent_not_stubbed(self):
        data = self.load()
        self.assertNotIn("FA", data["subtests"])
        self.assertNotIn("WU", data["subtests"])

    def test_present_subtests_match_expected_set(self):
        data = self.load()
        self.assertEqual(set(data["subtests"]), set(TEXT_SUBTEST_COUNTS))

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

    def test_me_is_final_with_confirmed_word_list(self):
        data = self.load()
        me = data["subtests"]["ME"]
        self.assertEqual(me["status"], "final")
        self.assertNotIn("draft_reason", me)
        self.assertNotIn("word_list_variants", me)
        word_list = me["word_list"]
        self.assertEqual(set(word_list), {"BUNGA", "PERKAKAS", "BURUNG", "KESENIAN", "BINATANG"})
        for words in word_list.values():
            self.assertEqual(len(words), 5)
        # P7 (2026-09-22): "Versi A" - psychologist-confirmed, see
        # tasks/handoffs/decisions/owner-decisions-2026-09-21.md item 21.
        self.assertIn("TEKUKUR", word_list["BURUNG"])
        self.assertIn("QUINTET", word_list["KESENIAN"])

    def test_no_participant_data_or_secrets(self):
        payload = (DATA / "ist_items.json").read_text(encoding="utf-8")
        for marker in ("@", "tanggal_lahir", "no_tes", "password", "token"):
            self.assertNotIn(marker, payload.lower())


if __name__ == "__main__":
    unittest.main()
