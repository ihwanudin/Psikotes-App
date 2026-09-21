import json
import unittest
from pathlib import Path

import numpy as np
from PIL import Image

from tools.extract.extract_ist_fa_wu import (
    BAND_SEAM_MIN_RUN,
    MAX_ITEM_ASPECT_RATIO,
    _assert_no_band_seam,
    _assert_single_figure,
)

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

    def test_no_shipped_crop_has_a_band_seam_or_is_too_elongated(self):
        """End-to-end regression gate for both defects the coordinator found
        in their crop-by-crop review (see
        tasks/handoffs/f0/ist-fa-wu-verification.md): re-runs the two new
        automated checks against every currently-shipped PNG, not just a
        synthetic case - so a future regeneration that reintroduces either
        defect fails here, not just in a unit test of the check function in
        isolation."""
        data = self.load()
        for code in ("FA", "WU"):
            entry = data["subtests"][code]
            for item in entry["items"]:
                with Image.open(DATA / item["image"]) as img:
                    _assert_no_band_seam(img, f"{code} item {item['item']}")
                    w, h = img.size
                ratio = max(w, h) / max(1, min(w, h))
                self.assertLessEqual(
                    ratio, MAX_ITEM_ASPECT_RATIO,
                    f"{code} item {item['item']}: {w}x{h} ratio={ratio:.2f} exceeds {MAX_ITEM_ASPECT_RATIO} "
                    f"- looks like it absorbed a neighboring item's figure",
                )
            legend_options = (
                [opt for legend in entry["option_legends"] for opt in legend["options"].values()]
                if code == "FA" else list(entry["option_legend"]["options"].values())
            )
            for rel_path in legend_options:
                with Image.open(DATA / rel_path) as img:
                    _assert_no_band_seam(img, f"{code} legend option {rel_path}")

    def test_wu_138_and_143_are_distinct_crops_not_the_old_merged_defect(self):
        """Regression pin for the specific defect the coordinator found: WU
        138's crop used to be 307x833 (ratio 2.71) because it had absorbed
        the entirety of item 143's cube. Both are now separate, normally
        proportioned crops - this fails if that merge ever comes back."""
        data = self.load()
        items_by_no = {item["item"]: item["image"] for item in data["subtests"]["WU"]["items"]}
        for item_no in (138, 143):
            with Image.open(DATA / items_by_no[item_no]) as img:
                w, h = img.size
            ratio = max(w, h) / max(1, min(w, h))
            self.assertLessEqual(ratio, MAX_ITEM_ASPECT_RATIO, f"WU {item_no}: {w}x{h}")


class BandSeamAndSingleFigureCheckTest(unittest.TestCase):
    """Unit tests for the two check functions themselves, on synthetic
    images - independent of the real PDF/output, so these pin the exact
    threshold behavior (what should and shouldn't raise) without depending
    on any particular extraction run."""

    def _solid_image(self, w=300, h=400, margin=20):
        arr = np.full((h, w), 255, dtype=np.uint8)
        arr[margin:h - margin, margin:w - margin] = 0  # solid dark rectangle
        return Image.fromarray(arr, mode="L").convert("RGB")

    def test_band_seam_check_raises_on_a_wide_blank_row_through_a_shape(self):
        img = self._solid_image()
        arr = np.array(img.convert("L"))
        arr[200, 20:280] = 255  # a full BAND_SEAM_MIN_RUN+-wide blank row through the dark rectangle
        seamed = Image.fromarray(arr, mode="L").convert("RGB")
        with self.assertRaises(ValueError):
            _assert_no_band_seam(seamed, "synthetic")

    def test_band_seam_check_tolerates_a_short_gap_like_wu_line_art(self):
        img = self._solid_image()
        arr = np.array(img.convert("L"))
        self.assertLess(12, BAND_SEAM_MIN_RUN)  # the gap below must stay under the real threshold
        arr[200, 100:112] = 255  # a 12px gap, well under BAND_SEAM_MIN_RUN=60
        gapped = Image.fromarray(arr, mode="L").convert("RGB")
        _assert_no_band_seam(gapped, "synthetic")  # must not raise

    def test_single_figure_check_raises_on_an_elongated_merged_box(self):
        img = self._solid_image(w=400, h=1000, margin=20)
        with self.assertRaises(ValueError):
            _assert_single_figure(img, (0, 0, 400, 1000), "synthetic")  # ratio 2.5 > 2.0

    def test_single_figure_check_tolerates_a_normally_proportioned_box(self):
        img = self._solid_image(w=400, h=500, margin=20)
        _assert_single_figure(img, (0, 0, 400, 500), "synthetic")  # ratio 1.25, must not raise

    def test_single_figure_check_raises_on_two_widely_separated_groups(self):
        w, h = 300, 500
        arr = np.full((h, w), 255, dtype=np.uint8)
        arr[20:100, 100:200] = 0  # top figure
        arr[400:480, 100:200] = 0  # bottom figure, far below - simulates two merged items
        img = Image.fromarray(arr, mode="L").convert("RGB")
        with self.assertRaises(ValueError):
            _assert_single_figure(img, (0, 0, w, h), "synthetic")


if __name__ == "__main__":
    unittest.main()
