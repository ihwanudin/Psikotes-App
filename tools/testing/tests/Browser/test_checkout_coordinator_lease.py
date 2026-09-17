"""Pure filesystem tests for the candidate-global coordinator lifecycle lease."""

from __future__ import annotations

import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch


HERE = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location(
    "checkout_coordinator_lease_tested", HERE / "checkout-coordinator-lease.py"
)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("lease module unavailable")
module = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(module)


class CheckoutCoordinatorLeaseTests(unittest.TestCase):
    session = "checkout-" + "a" * 32
    binding = "b" * 64

    def fixture(self):
        root = tempfile.TemporaryDirectory(prefix="oncam-lease-test-")
        base = Path(root.name)
        coordinator = base / "coordinator"
        run = base / "candidate"
        coordinator.mkdir()
        run.mkdir()
        return root, coordinator, run

    def acquire(self, coordinator, run, **overrides):
        return module.CheckoutCoordinatorLease.acquire(
            coordinator_directory=coordinator,
            run_directory=run,
        )

    def test_same_run_contends_across_sessions_and_config_bindings_then_reopens(self):
        root, coordinator, run = self.fixture()
        with root:
            first = self.acquire(coordinator, run)
            for session, binding in (
                ("checkout-" + "c" * 32, self.binding),
                (self.session, "d" * 64),
            ):
                with self.subTest(session=session), \
                        self.assertRaisesRegex(module.LeaseRefused, "^lease_held$"):
                    self.acquire(coordinator, run, session=session, config_binding=binding)
            path = first.path
            first.release("lifecycle_finished")
            self.assertTrue(path.is_file())
            second = self.acquire(
                coordinator, run, session="checkout-" + "c" * 32,
                config_binding="d" * 64,
            )
            self.assertEqual(second.path, path)
            second.release("lifecycle_finished")

    def test_same_run_contends_across_different_coordinator_directories(self):
        root, coordinator, run = self.fixture()
        with root:
            other = Path(root.name) / "other-coordinator"
            other.mkdir()
            first = self.acquire(coordinator, run)
            with self.assertRaisesRegex(module.LeaseRefused, "^lease_held$"):
                self.acquire(other, run)
            first.release("lifecycle_finished")

    def test_stable_document_is_run_only_strict_bounded_and_handle_noninheritable(self):
        root, coordinator, run = self.fixture()
        with root:
            lease = self.acquire(coordinator, run)
            document = lease.validate()
            self.assertEqual(set(document), {"version", "run", "leaseBinding"})
            self.assertLessEqual(lease.path.stat().st_size, module.MAX_DOCUMENT_BYTES)
            self.assertFalse(os.get_inheritable(lease.descriptor))
            lease.release("lifecycle_finished")
            raw = lease.path.read_text("ascii")
            self.assertNotIn(self.session, raw)
            self.assertNotIn(self.binding, raw)
            self.assertEqual(json.loads(raw), document)

    def test_partial_first_initialization_is_retained_and_never_auto_repaired(self):
        root, coordinator, run = self.fixture()
        with root:
            with patch.object(module.os, "write", side_effect=OSError("PRIVATE")), \
                    self.assertRaisesRegex(module.LeaseRefused, "^lease_write$") as caught:
                self.acquire(coordinator, run)
            self.assertNotIn("PRIVATE", str(caught.exception))
            paths = list(run.glob(".checkout-coordinator.lease"))
            self.assertEqual(len(paths), 1)
            with self.assertRaisesRegex(module.LeaseRefused, "^lease_invalid$"):
                self.acquire(coordinator, run)
            self.assertEqual(list(run.glob(".checkout-coordinator.lease")), paths)

    def test_path_swap_and_symlink_fail_closed_without_unlink(self):
        root, coordinator, run = self.fixture()
        with root:
            lease = self.acquire(coordinator, run)
            with patch.object(module.os.path, "samestat", return_value=False), \
                    self.assertRaisesRegex(module.LeaseRefused, "^lease_invalid$"):
                lease.validate()
            lease.release("lifecycle_finished")

        root, coordinator, run = self.fixture()
        with root:
            path = module.lease_path(coordinator, run)
            original = coordinator / "original.lease"
            original.write_text("stable", encoding="ascii")
            try:
                path.symlink_to(original)
            except OSError:
                self.skipTest("file symlink creation unavailable")
            with self.assertRaisesRegex(module.LeaseRefused, "^lease_invalid$"):
                self.acquire(coordinator, run)
            self.assertTrue(path.is_symlink())

    def test_directory_replacement_and_reparse_fail_closed(self):
        for changed_path in ("coordinator", "run"):
            root, coordinator, run = self.fixture()
            with self.subTest(changed_path=changed_path), root:
                lease = self.acquire(coordinator, run)
                real_path_stat = module._path_stat
                target = coordinator if changed_path == "coordinator" else run

                def changed_directory(path):
                    value = real_path_stat(path)
                    if Path(path) == target:
                        return type("Changed", (), {
                            "st_mode": value.st_mode,
                            "st_dev": value.st_dev,
                            "st_ino": value.st_ino + 1,
                        })()
                    return value

                with patch.object(module, "_path_stat", side_effect=changed_directory), \
                        self.assertRaisesRegex(module.LeaseRefused, "^lease_invalid$"):
                    lease.validate()
                lease.release("lifecycle_finished")

        root, coordinator, run = self.fixture()
        with root:
            replacement = coordinator.parent / "replacement"
            coordinator.rename(replacement)
            try:
                coordinator.symlink_to(replacement, target_is_directory=True)
            except OSError:
                replacement.rename(coordinator)
                self.skipTest("directory symlink creation unavailable")
            with self.assertRaisesRegex(module.LeaseRefused, "^lease_invalid$"):
                self.acquire(coordinator, run)

    def test_release_requires_exact_terminal_and_never_unlinks(self):
        root, coordinator, run = self.fixture()
        with root:
            lease = self.acquire(coordinator, run)
            for state in (None, "new", "invalidated", True):
                with self.subTest(state=state), \
                        self.assertRaisesRegex(module.LeaseRefused, "^lease_terminal$"):
                    lease.release(state)
            with patch.object(module.os, "unlink", side_effect=AssertionError("no unlink")) as unlink:
                lease.release("lifecycle_finished")
            unlink.assert_not_called()
            self.assertTrue(lease.path.is_file())
            with self.assertRaisesRegex(module.LeaseRefused, "^lease_released$"):
                lease.validate()

    def test_unlock_and_close_failures_are_fixed_and_baseexceptions_preserved(self):
        root, coordinator, run = self.fixture()
        with root:
            lease = self.acquire(coordinator, run)
            with patch.object(module, "_unlock_descriptor", side_effect=OSError("PRIVATE")), \
                    self.assertRaisesRegex(module.LeaseRefused, "^lease_release$") as caught:
                lease.release("lifecycle_finished")
            self.assertNotIn("PRIVATE", str(caught.exception))
            replacement = self.acquire(coordinator, run)
            replacement.release("lifecycle_finished")

        for interruption in (KeyboardInterrupt("interrupt"), SystemExit("exit")):
            root, coordinator, run = self.fixture()
            with root:
                lease = self.acquire(coordinator, run)
                real_unlock = module._unlock_descriptor

                def unlock_then_interrupt(descriptor):
                    real_unlock(descriptor)
                    raise interruption

                with self.subTest(interruption=type(interruption).__name__), \
                        patch.object(module, "_unlock_descriptor", side_effect=unlock_then_interrupt), \
                        self.assertRaises(type(interruption)) as caught:
                    lease.release("lifecycle_finished")
                self.assertIs(caught.exception, interruption)
                replacement = self.acquire(coordinator, run)
                replacement.release("lifecycle_finished")

    def test_release_validation_failure_still_closes_and_unlocks(self):
        root, coordinator, run = self.fixture()
        with root:
            lease = self.acquire(coordinator, run)
            with patch.object(lease, "validate", side_effect=module.LeaseRefused("lease_invalid")), \
                    self.assertRaisesRegex(module.LeaseRefused, "^lease_release$"):
                lease.release("lifecycle_finished")
            replacement = self.acquire(coordinator, run)
            replacement.release("lifecycle_finished")

            lease = self.acquire(coordinator, run)
            lease.descriptor = -1
            with self.assertRaisesRegex(module.LeaseRefused, "^lease_release$"):
                lease.release("lifecycle_finished")
            replacement = self.acquire(coordinator, run)
            replacement.release("lifecycle_finished")

    def test_input_scope_and_existing_malformed_or_reparse_file_fail_closed(self):
        root, coordinator, run = self.fixture()
        with root:
            inside = run / "inside"
            inside.mkdir()
            with self.assertRaisesRegex(module.LeaseRefused, "^lease_scope$"):
                self.acquire(inside, run)

            expected = module.lease_path(coordinator, run)
            expected.write_bytes(b"{}")
            with self.assertRaisesRegex(module.LeaseRefused, "^lease_invalid$"):
                self.acquire(coordinator, run)


if __name__ == "__main__":
    unittest.main()
