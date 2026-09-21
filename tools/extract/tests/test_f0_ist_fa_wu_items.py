import json
import unittest
from pathlib import Path

from PIL import Image

DATA = Path(__file__).parents[3] / "database" / "seeders" / "data"
FA_ITEM_RANGE = range(117, 137)
WU_ITEM_RANGE = range(137, 157)
FA_LEGEND_1_ITEMS = set(range(117, 129))
FA_LEGEND_2_ITEMS = set(range(129, 137))


class IstFaWuItemsGateTest(unittest.TestCase):
    """Structural invariants for FA/WU (images) in `ist_items.json`, produced
    by extract_ist_fa_wu.py. No byte-hash pin here (or in test_f0_ist_items.py
    for the file as a whole) - FA/WU stay `status: "draft"` until Lead has
    reviewed every crop against the contact sheets
    (tasks/handoffs/f0/ist-{fa,wu}-contact-sheet.png), per their explicit
    requirement. These tests are the cheap automated first pass (structure,
    asset existence, no truncation at the file level via PNG validity) - not
    a substitute for that visual review."""

    def load(self):
        return json.loads((DATA / "ist_items.json").read_text(encoding="utf-8"))

    def test_fa_wu_present_and_draft(self):
        data = self.load()
        for code in ("FA", "WU"):
            self.assertIn(code, data["subtests"])
            entry = data["subtests"][code]
            self.assertEqual(entry["status"], "draft")
            self.assertIn("draft_reason", entry)
            self.assertEqual(entry["answer_type"], "image_choice")

    def test_fa_item_count_and_numbering(self):
        data = self.load()
        items = data["subtests"]["FA"]["items"]
        self.assertEqual(len(items), 20)
        self.assertEqual({i["item"] for i in items}, set(FA_ITEM_RANGE))

    def test_wu_item_count_and_numbering(self):
        data = self.load()
        items = data["subtests"]["WU"]["items"]
        self.assertEqual(len(items), 20)
        self.assertEqual({i["item"] for i in items}, set(WU_ITEM_RANGE))

    def test_fa_legend_id_matches_the_verified_117_128_129_136_split(self):
        data = self.load()
        items = data["subtests"]["FA"]["items"]
        legend_by_item = {i["item"]: i["legend_id"] for i in items}
        for item in FA_LEGEND_1_ITEMS:
            self.assertEqual(legend_by_item[item], "FA-L1", item)
        for item in FA_LEGEND_2_ITEMS:
            self.assertEqual(legend_by_item[item], "FA-L2", item)

    def test_fa_option_legends_are_well_formed(self):
        data = self.load()
        legends = data["subtests"]["FA"]["option_legends"]
        self.assertEqual({l["legend_id"] for l in legends}, {"FA-L1", "FA-L2"})
        for legend in legends:
            self.assertEqual(set(legend["options"]), set("abcde"))
            expected_items = FA_LEGEND_1_ITEMS if legend["legend_id"] == "FA-L1" else FA_LEGEND_2_ITEMS
            self.assertEqual(set(legend["items"]), expected_items)

    def test_wu_option_legend_is_well_formed(self):
        data = self.load()
        legend = data["subtests"]["WU"]["option_legend"]
        self.assertEqual(set(legend["options"]), set("abcde"))

    def test_no_scoring_or_answer_key_fields_leak_into_item_content(self):
        data = self.load()
        forbidden_keys = {"key", "correct", "answer"}
        for item in data["subtests"]["FA"]["items"]:
            self.assertEqual(set(item), {"item", "image", "legend_id"})
            self.assertTrue(forbidden_keys.isdisjoint(item))
        for item in data["subtests"]["WU"]["items"]:
            self.assertEqual(set(item), {"item", "image"})
            self.assertTrue(forbidden_keys.isdisjoint(item))

    def test_every_referenced_image_asset_exists_and_is_a_valid_nonempty_png(self):
        data = self.load()
        paths = []
        for code in ("FA", "WU"):
            entry = data["subtests"][code]
            paths.extend(item["image"] for item in entry["items"])
        for legend in data["subtests"]["FA"]["option_legends"]:
            paths.extend(legend["options"].values())
        paths.extend(data["subtests"]["WU"]["option_legend"]["options"].values())

        self.assertEqual(len(paths), 20 + 20 + 10 + 5)  # items + FA legends (2x5) + WU legend (5)
        self.assertEqual(len(paths), len(set(paths)), "asset paths must be unique")

        for rel_path in paths:
            full_path = DATA / rel_path
            self.assertTrue(full_path.is_file(), rel_path)
            self.assertGreater(full_path.stat().st_size, 0, rel_path)
            with Image.open(full_path) as img:
                img.verify()
            self.assertEqual(full_path.suffix, ".png", rel_path)

    def test_contact_sheets_exist(self):
        contact_dir = DATA.parents[2] / "tasks" / "handoffs" / "f0"
        for name in ("ist-fa-contact-sheet.png", "ist-wu-contact-sheet.png"):
            path = contact_dir / name
            self.assertTrue(path.is_file(), path)
            self.assertGreater(path.stat().st_size, 0, path)

    def test_no_participant_data_or_secrets_in_asset_paths(self):
        data = self.load()
        payload = json.dumps(data["subtests"]["FA"]) + json.dumps(data["subtests"]["WU"])
        for marker in ("@", "tanggal_lahir", "no_tes", "password", "token"):
            self.assertNotIn(marker, payload.lower())


if __name__ == "__main__":
    unittest.main()
