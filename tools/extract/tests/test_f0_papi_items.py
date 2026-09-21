import json
import unittest
from pathlib import Path

DATA = Path(__file__).parents[3] / "database" / "seeders" / "data"


class PapiItemsGateTest(unittest.TestCase):
    """Structural invariants for `papi_items.json`. The byte-hash pin (following
    the `extract_aspect_sources.py` / `test_f0.py` pattern, as used for RMIB in
    PR #55) is added in a follow-up commit after the coordinator has reviewed
    the extracted content - see PR description."""

    def load(self):
        return json.loads((DATA / "papi_items.json").read_text(encoding="utf-8"))

    def test_status_is_final(self):
        data = self.load()
        self.assertEqual(data["status"], "final")

    def test_covers_all_90_items_exactly_once(self):
        data = self.load()
        items = data["items"]
        self.assertEqual(len(items), 90)
        self.assertEqual({item["item"] for item in items}, set(range(1, 91)))

    def test_item_count_matches_papi_json_mapping(self):
        papi = json.loads((DATA / "papi.json").read_text(encoding="utf-8"))
        data = self.load()
        self.assertEqual(len(data["items"]), len(papi["mapping"]))
        self.assertEqual(
            {item["item"] for item in data["items"]},
            {entry["item"] for entry in papi["mapping"]},
        )

    def test_no_scoring_or_dimension_fields_leak_into_item_content(self):
        data = self.load()
        forbidden_keys = {"a", "b", "type", "dimension", "band", "score"}
        for item in data["items"]:
            self.assertEqual(set(item), {"item", "statement_a", "statement_b"})
            self.assertTrue(forbidden_keys.isdisjoint(item))

    def test_statements_are_nonempty_plain_text(self):
        data = self.load()
        for item in data["items"]:
            for field in ("statement_a", "statement_b"):
                value = item[field]
                self.assertIsInstance(value, str)
                self.assertTrue(value.strip())
                self.assertEqual(value, value.strip())
                self.assertNotIn("  ", value)  # no double-space artifacts
                self.assertNotIn("…", value)  # no leftover leader-dot glyphs
                self.assertNotIn("←", value)  # no leftover "circle this" arrow

    def test_instructions_present_and_structured(self):
        data = self.load()
        instructions = data["instructions"]
        self.assertGreater(len(instructions["intro"]), 100)
        self.assertGreater(len(instructions["closing"]), 50)
        for key in ("statement_a", "statement_b"):
            self.assertTrue(instructions["example"][key].strip())
            self.assertTrue(instructions["answer_sheet_demo"][key].strip())
        self.assertTrue(instructions["answer_sheet_demo"]["label"].strip())
        for value in (instructions["intro"], instructions["closing"]):
            self.assertNotIn("  ", value)
            self.assertNotIn("…", value)
            self.assertNotIn("←", value)

    def test_no_participant_data_or_secrets(self):
        payload = (DATA / "papi_items.json").read_text(encoding="utf-8")
        for marker in ("@", "tanggal_lahir", "no_tes", "password", "token"):
            self.assertNotIn(marker, payload.lower())


if __name__ == "__main__":
    unittest.main()
