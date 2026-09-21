import json
import unittest
from pathlib import Path

DATA = Path(__file__).parents[3] / "database" / "seeders" / "data"


class RmibItemsGateTest(unittest.TestCase):
    """Structural invariants for `rmib_items.json`. The byte-hash pin (following the
    `extract_aspect_sources.py` / `test_f0.py` pattern) is added in a follow-up commit
    after the coordinator has reviewed the extracted content - see PR description."""

    def load(self):
        return json.loads((DATA / "rmib_items.json").read_text(encoding="utf-8"))

    def test_status_is_final(self):
        data = self.load()
        self.assertEqual(data["status"], "final")

    def test_covers_all_108_group_position_pairs_exactly_once(self):
        data = self.load()
        positions = data["positions"]
        self.assertEqual(len(positions), 108)

        seen = {(p["group"], p["position"]) for p in positions}
        self.assertEqual(len(seen), 108)
        self.assertEqual(seen, {(g, p) for g in range(1, 10) for p in range(1, 13)})

    def test_group_letter_matches_numeric_group_one_to_one(self):
        data = self.load()
        letter_by_group = {p["group"]: p["group_letter"] for p in data["positions"]}
        self.assertEqual(len(letter_by_group), 9)
        self.assertEqual(set(letter_by_group.values()), set("ABCDEFGHI"))
        # Every position sharing a numeric group must agree on the same letter.
        for p in data["positions"]:
            self.assertEqual(p["group_letter"], letter_by_group[p["group"]])

    def test_group_numbering_matches_rmib_json_rotation(self):
        rmib = json.loads((DATA / "rmib.json").read_text(encoding="utf-8"))
        category_by_code = {c["code"]: c["index"] for c in rmib["categories"]}
        rotation_lookup = {
            (r["group"], r["position"]): r["category"] for r in rmib["rotation"]
        }

        data = self.load()
        for p in data["positions"]:
            # rmib_items.json intentionally does not carry the scoring category
            # (item-content files must not leak scoring/answer-key info), so this
            # only re-derives the rotation formula's category count is covered -
            # the real category cross-check lives in extract_rmib_items.py's
            # _derive_letter_to_group, run at extraction time.
            self.assertIn((p["group"], p["position"]), rotation_lookup)
        self.assertEqual(len(category_by_code), 12)

    def test_no_scoring_or_answer_key_fields_leak_into_item_content(self):
        data = self.load()
        forbidden_keys = {"category", "rank", "rank_to_score", "rank_to_level", "score"}
        for p in data["positions"]:
            self.assertEqual(set(p), {"group", "group_letter", "position", "job_male", "job_female"})
            self.assertTrue(forbidden_keys.isdisjoint(p))

    def test_job_names_are_nonempty_plain_text(self):
        data = self.load()
        for p in data["positions"]:
            for field in ("job_male", "job_female"):
                value = p[field]
                self.assertIsInstance(value, str)
                self.assertTrue(value.strip())
                self.assertEqual(value, value.strip())
                self.assertNotIn("  ", value)  # no double-space artifacts

    def test_instructions_present_and_nonempty(self):
        data = self.load()
        instructions = data["instructions"]
        self.assertIn("text", instructions)
        self.assertIn("write_preferred_jobs_prompt", instructions)
        self.assertGreater(len(instructions["text"]), 200)
        self.assertGreater(len(instructions["write_preferred_jobs_prompt"]), 20)
        for value in instructions.values():
            self.assertNotIn("  ", value)
            self.assertEqual(value, value.strip())

    def test_no_participant_data_or_secrets(self):
        payload = (DATA / "rmib_items.json").read_text(encoding="utf-8")
        for marker in ("@", "tanggal_lahir", "no_tes", "password", "token"):
            self.assertNotIn(marker, payload.lower())


if __name__ == "__main__":
    unittest.main()
