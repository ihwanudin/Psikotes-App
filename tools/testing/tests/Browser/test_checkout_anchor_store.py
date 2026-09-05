"""Pure filesystem tests for the coordinator-owned checkout anchor store."""

import importlib.util
import json
import os
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest
from unittest.mock import patch


spec = importlib.util.spec_from_file_location(
    "checkout_anchor_store",
    Path(__file__).with_name("checkout-anchor-store.py"),
)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class CheckoutAnchorStoreTests(unittest.TestCase):
    session = "checkout-" + "a" * 32
    config_binding = "b" * 64

    def store(self, coordinator, run, **overrides):
        return module.CheckoutAnchorStore(
            coordinator_directory=coordinator,
            run_directory=run,
            session=overrides.get("session", self.session),
            config_binding=overrides.get("config_binding", self.config_binding),
        )

    def test_publishes_monotonic_anchors_and_reopens_exact_provenance(self):
        with TemporaryDirectory(prefix="oncam-anchor-test-") as root:
            root = Path(root)
            coordinator, run = root / "coordinator", root / "candidate"
            coordinator.mkdir()
            run.mkdir()
            store = self.store(coordinator, run)

            first = {"generation": 1, "digest": "c" * 64}
            second = {"generation": 2, "digest": "d" * 64}
            store.publish(first)
            first["generation"] = 999
            store.publish(second)

            reopened = self.store(coordinator, run)
            self.assertEqual(reopened.load(), {
                "version": 1,
                "run": module.canonical_path(run),
                "session": self.session,
                "configBinding": self.config_binding,
                "anchor": second,
            })

    def test_exact_replay_is_idempotent_but_stale_conflicting_and_skipped_generations_refuse(self):
        with TemporaryDirectory(prefix="oncam-anchor-test-") as root:
            root = Path(root)
            coordinator, run = root / "coordinator", root / "candidate"
            coordinator.mkdir()
            run.mkdir()
            store = self.store(coordinator, run)
            first = {"generation": 1, "digest": "c" * 64}
            store.publish(first)
            original = store.path.read_bytes()

            store.publish(dict(first))
            self.assertEqual(store.path.read_bytes(), original)
            cases = (
                ({"generation": 1, "digest": "d" * 64}, "anchor_conflict"),
                ({"generation": 3, "digest": "e" * 64}, "anchor_order"),
            )
            for anchor, reason in cases:
                with self.subTest(reason=reason), self.assertRaisesRegex(module.AnchorStoreRefused, f"^{reason}$"):
                    store.publish(anchor)

            store.publish({"generation": 2, "digest": "e" * 64})
            with self.assertRaisesRegex(module.AnchorStoreRefused, "^anchor_stale$"):
                store.publish(first)

    def test_first_generation_must_be_one_and_anchor_shape_is_strict(self):
        with TemporaryDirectory(prefix="oncam-anchor-test-") as root:
            root = Path(root)
            coordinator, run = root / "coordinator", root / "candidate"
            coordinator.mkdir()
            run.mkdir()
            store = self.store(coordinator, run)

            invalid = (
                None,
                {},
                {"generation": 2, "digest": "c" * 64},
                {"generation": True, "digest": "c" * 64},
                {"generation": 1, "digest": "C" * 64},
                {"generation": 1, "digest": "c" * 64, "extra": False},
            )
            for anchor in invalid:
                with self.subTest(anchor=anchor), self.assertRaises(module.AnchorStoreRefused):
                    store.publish(anchor)

    def test_malformed_truncated_oversize_or_wrong_provenance_fails_closed(self):
        with TemporaryDirectory(prefix="oncam-anchor-test-") as root:
            root = Path(root)
            coordinator, run = root / "coordinator", root / "candidate"
            coordinator.mkdir()
            run.mkdir()
            store = self.store(coordinator, run)
            store.publish({"generation": 1, "digest": "c" * 64})
            valid = json.loads(store.path.read_text(encoding="ascii"))

            corruptions = (
                b'{"version":1',
                b"x" * (module.MAX_DOCUMENT_BYTES + 1),
                json.dumps({**valid, "extra": False}).encode(),
                json.dumps({**valid, "session": "checkout-" + "f" * 32}).encode(),
                json.dumps({**valid, "configBinding": "f" * 64}).encode(),
                json.dumps({**valid, "run": module.canonical_path(coordinator)}).encode(),
                json.dumps({**valid, "anchor": {"generation": True, "digest": "c" * 64}}).encode(),
                json.dumps({**valid, "version": True}).encode(),
                b'{"version":1,"version":1}',
            )
            for index, content in enumerate(corruptions):
                with self.subTest(index=index):
                    store.path.write_bytes(content)
                    with self.assertRaises(module.AnchorStoreRefused):
                        self.store(coordinator, run).load()
                    store.path.write_text(json.dumps(valid, sort_keys=True, separators=(",", ":")), encoding="ascii")

    def test_failed_replace_preserves_previous_anchor_and_removes_its_temp(self):
        with TemporaryDirectory(prefix="oncam-anchor-test-") as root:
            root = Path(root)
            coordinator, run = root / "coordinator", root / "candidate"
            coordinator.mkdir()
            run.mkdir()
            store = self.store(coordinator, run)
            first = {"generation": 1, "digest": "c" * 64}
            store.publish(first)

            with patch.object(module.os, "replace", side_effect=OSError("PRIVATE")):
                with self.assertRaisesRegex(module.AnchorStoreRefused, "^anchor_write$"):
                    store.publish({"generation": 2, "digest": "d" * 64})

            self.assertEqual(self.store(coordinator, run).load()["anchor"], first)
            self.assertEqual(list(coordinator.glob(".*.tmp")), [])

    def test_orphaned_temp_never_replaces_the_last_durable_anchor(self):
        with TemporaryDirectory(prefix="oncam-anchor-test-") as root:
            root = Path(root)
            coordinator, run = root / "coordinator", root / "candidate"
            coordinator.mkdir()
            run.mkdir()
            store = self.store(coordinator, run)
            first = {"generation": 1, "digest": "c" * 64}
            store.publish(first)
            (coordinator / ".checkout-anchor-interrupted.tmp").write_text("PRIVATE", encoding="ascii")

            self.assertEqual(self.store(coordinator, run).load()["anchor"], first)

    def test_exclusive_writer_lock_fails_closed_instead_of_racing_generation(self):
        with TemporaryDirectory(prefix="oncam-anchor-test-") as root:
            root = Path(root)
            coordinator, run = root / "coordinator", root / "candidate"
            coordinator.mkdir()
            run.mkdir()
            store = self.store(coordinator, run)
            store.lock_path.write_text("LOCK\n", encoding="ascii")

            with self.assertRaisesRegex(module.AnchorStoreRefused, "^anchor_locked$"):
                store.publish({"generation": 1, "digest": "c" * 64})
            self.assertFalse(store.path.exists())

    def test_candidate_scoped_coordinator_and_reparse_paths_are_refused(self):
        with TemporaryDirectory(prefix="oncam-anchor-test-") as root:
            root = Path(root)
            run = root / "candidate"
            run.mkdir()
            inside = run / "coordinator"
            inside.mkdir()
            with self.assertRaisesRegex(module.AnchorStoreRefused, "^anchor_scope$"):
                self.store(inside, run)

            target, link = root / "target", root / "link"
            target.mkdir()
            try:
                link.symlink_to(target, target_is_directory=True)
            except OSError:
                return
            with self.assertRaisesRegex(module.AnchorStoreRefused, "^anchor_path$"):
                self.store(link, run)

    def test_broken_anchor_symlink_is_not_treated_as_an_empty_store(self):
        with TemporaryDirectory(prefix="oncam-anchor-test-") as root:
            root = Path(root)
            coordinator, run = root / "coordinator", root / "candidate"
            coordinator.mkdir()
            run.mkdir()
            store = self.store(coordinator, run)
            try:
                store.path.symlink_to(coordinator / "missing-anchor")
            except OSError:
                return

            with self.assertRaisesRegex(module.AnchorStoreRefused, "^anchor_path$"):
                store.load()
            with self.assertRaisesRegex(module.AnchorStoreRefused, "^anchor_path$"):
                store.publish({"generation": 1, "digest": "c" * 64})

    def test_constructor_and_missing_store_validate_without_exposing_values(self):
        with TemporaryDirectory(prefix="oncam-anchor-test-") as root:
            root = Path(root)
            coordinator, run = root / "coordinator", root / "candidate"
            coordinator.mkdir()
            run.mkdir()
            invalid = (
                {"session": "bad"},
                {"config_binding": "PRIVATE"},
            )
            for values in invalid:
                with self.subTest(values=values), self.assertRaises(module.AnchorStoreRefused) as caught:
                    self.store(coordinator, run, **values)
                self.assertNotIn("PRIVATE", str(caught.exception))

            with self.assertRaisesRegex(module.AnchorStoreRefused, "^anchor_missing$"):
                self.store(coordinator, run).load()

    def test_constructor_scope_reresolve_failure_is_fixed_and_private(self):
        with TemporaryDirectory(prefix="oncam-anchor-test-") as root:
            root = Path(root)
            coordinator, run = root / "coordinator", root / "candidate"
            coordinator.mkdir()
            run.mkdir()
            real_resolve = Path.resolve
            calls = 0

            def resolve(path, *args, **kwargs):
                nonlocal calls
                calls += 1
                if calls >= 3:
                    raise OSError("PRIVATE_PATH")
                return real_resolve(path, *args, **kwargs)

            with patch.object(Path, "resolve", resolve), \
                    self.assertRaisesRegex(module.AnchorStoreRefused, "^anchor_path$") as caught:
                self.store(coordinator, run)
            self.assertNotIn("PRIVATE", str(caught.exception))

    def test_opened_anchor_identity_mismatch_fails_closed(self):
        with TemporaryDirectory(prefix="oncam-anchor-test-") as root:
            root = Path(root)
            coordinator, run = root / "coordinator", root / "candidate"
            coordinator.mkdir()
            run.mkdir()
            store = self.store(coordinator, run)
            store.publish({"generation": 1, "digest": "c" * 64})

            with patch.object(module.os.path, "samestat", return_value=False), \
                    self.assertRaisesRegex(module.AnchorStoreRefused, "^anchor_path$"):
                store.load()

    def test_coordinator_identity_change_before_load_fails_closed_privately(self):
        with TemporaryDirectory(prefix="oncam-anchor-test-") as root:
            root = Path(root)
            coordinator, run = root / "coordinator", root / "candidate"
            coordinator.mkdir()
            run.mkdir()
            store = self.store(coordinator, run)
            store.publish({"generation": 1, "digest": "c" * 64})

            with patch.object(module, "_identity", return_value=(999, 999)), \
                    self.assertRaisesRegex(module.AnchorStoreRefused, "^anchor_path$") as caught:
                store.load()
            self.assertNotIn(str(coordinator), str(caught.exception))

    def test_swapped_writer_lock_is_retained_and_publication_fails_closed(self):
        with TemporaryDirectory(prefix="oncam-anchor-test-") as root:
            root = Path(root)
            coordinator, run = root / "coordinator", root / "candidate"
            coordinator.mkdir()
            run.mkdir()
            store = self.store(coordinator, run)
            with patch.object(module.os.path, "samestat", side_effect=[True, False]), \
                    self.assertRaisesRegex(module.AnchorStoreRefused, "^anchor_lock$"):
                store.publish({"generation": 1, "digest": "c" * 64})
            self.assertEqual(store.lock_path.read_text(encoding="ascii"), "LOCK\n")


if __name__ == "__main__":
    unittest.main()
