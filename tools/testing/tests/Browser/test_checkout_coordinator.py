"""Pure tests for checkout supervisor/anchor-store assembly; never starts runtime processes."""

from __future__ import annotations

import importlib.util
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest
from unittest.mock import patch


def load_module(name: str, filename: str):
    spec = importlib.util.spec_from_file_location(name, Path(__file__).with_name(filename))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class CheckoutCoordinatorTests(unittest.TestCase):
    session = "checkout-" + "a" * 32

    def module(self):
        return load_module("checkout_coordinator", "checkout-coordinator.py")

    @staticmethod
    def config(run: Path):
        paths = {
            name: str((run / name).absolute())
            for name in ("php", "python", "node", "powershell", "cli", "browser",
                         "ini", "browser_config", "cert", "key")
        }
        return {
            "directory": str(run.absolute()),
            "manifest": "a" * 64,
            **paths,
            "tool_hashes": {name: format(index, "064x") for index, name in enumerate(paths, 1)},
        }

    def test_adapter_delegates_publish_and_returns_an_isolated_raw_anchor(self):
        module = self.module()

        class Run:
            run = Path("c:/candidate")
            session = self.session

            def _config_binding(self, session):
                self.binding_sessions = getattr(self, "binding_sessions", []) + [session]
                return "b" * 64

        class Store:
            directory = Path("c:/coordinator")
            directory_identity = (1, 2)
            run = "c:/candidate"
            session = self.session
            config_binding = "b" * 64
            path = directory / "anchor.json"
            lock_path = directory / "anchor.lock"

            def __init__(self):
                self.published = []
                self.anchor = {"generation": 1, "digest": "c" * 64}

            def publish(self, anchor):
                self.published.append(dict(anchor))
                self.anchor = dict(anchor)
                return dict(anchor)

            def load(self):
                return {
                    "version": 1,
                    "run": self.run,
                    "session": self.session,
                    "configBinding": self.config_binding,
                    "anchor": dict(self.anchor),
                }

        store = Store()
        run = Run()
        adapter = module.SupervisorAnchorPublisher(
            store,
            run=run,
            expected_run="c:/candidate",
            expected_session=self.session,
            expected_config_binding="b" * 64,
        )
        run.anchor_publisher = adapter
        second = {"generation": 2, "digest": "d" * 64}
        self.assertEqual(adapter(second), second)
        loaded = adapter.load()
        self.assertEqual(loaded, second)
        loaded["generation"] = 999
        self.assertEqual(adapter.load(), second)
        self.assertEqual(store.published, [second])
        self.assertEqual(run.binding_sessions, [self.session] * 7)

    def test_adapter_rejects_malformed_or_wrong_provenance_wrapper_with_fixed_code(self):
        module = self.module()

        class Store:
            directory = Path("c:/coordinator")
            directory_identity = (1, 2)
            run = "c:/candidate"
            session = self.session
            config_binding = "b" * 64
            path = directory / "anchor.json"
            lock_path = directory / "anchor.lock"

            def publish(self, anchor):
                return anchor

            def load(self):
                return self.document

        class Run:
            run = Path("c:/candidate")
            session = self.session

            def _config_binding(self, session):
                return "b" * 64

        def adapter(store):
            run = Run()
            publisher = module.SupervisorAnchorPublisher(
                store,
                run=run,
                expected_run="c:/candidate",
                expected_session=self.session,
                expected_config_binding="b" * 64,
            )
            run.anchor_publisher = publisher
            return publisher

        valid = {
            "version": 1,
            "run": Store.run,
            "session": Store.session,
            "configBinding": Store.config_binding,
            "anchor": {"generation": 1, "digest": "c" * 64},
        }
        corruptions = (
            None,
            {**valid, "extra": False},
            {**valid, "version": True},
            {**valid, "run": "c:/other"},
            {**valid, "session": "checkout-" + "f" * 32},
            {**valid, "configBinding": "f" * 64},
            {**valid, "anchor": {"generation": True, "digest": "c" * 64}},
            {**valid, "anchor": {"generation": 1, "digest": "PRIVATE"}},
        )
        for document in corruptions:
            with self.subTest(document=document):
                store = Store()
                store.document = document
                with self.assertRaisesRegex(module.CoordinatorRefused, "^coordinator_anchor$") as caught:
                    adapter(store).load()
                self.assertNotIn("PRIVATE", str(caught.exception))
        class FailingStore(Store):
            def load(self):
                raise OSError("PRIVATE_PATH")

        store = FailingStore()
        with self.assertRaisesRegex(module.CoordinatorRefused, "^coordinator_anchor$") as caught:
            adapter(store).load()
        self.assertNotIn("PRIVATE", str(caught.exception))

    def test_adapter_requires_the_exact_store_interface(self):
        module = self.module()
        for store in (None, object(), type("OnlyPublish", (), {"publish": lambda *_: None})()):
            with self.subTest(store=store), \
                    self.assertRaisesRegex(module.CoordinatorRefused, "^coordinator_store$"):
                module.SupervisorAnchorPublisher(
                    store,
                    run=object(),
                    expected_run="c:/candidate",
                    expected_session=self.session,
                    expected_config_binding="b" * 64,
                )

    def test_assembly_deep_copies_caller_config_before_binding(self):
        module = self.module()
        with TemporaryDirectory(prefix="oncam-coordinator-test-") as root:
            root = Path(root)
            coordinator, run_directory = root / "coordinator", root / "candidate"
            coordinator.mkdir()
            run_directory.mkdir()
            config = self.config(run_directory)
            expected_manifest = config["manifest"]
            expected_php_hash = config["tool_hashes"]["php"]

            run = module.assemble_fresh(config=config, coordinator_directory=coordinator)
            config["manifest"] = "f" * 64
            config["tool_hashes"]["php"] = "e" * 64
            config["directory"] = "PRIVATE_MUTATION"

            self.assertEqual(run.c["manifest"], expected_manifest)
            self.assertEqual(run.c["tool_hashes"]["php"], expected_php_hash)
            self.assertEqual(run.run, run_directory)
            self.assertEqual(
                run.anchor_publisher({"generation": 1, "digest": "c" * 64}),
                {"generation": 1, "digest": "c" * 64},
            )

    def test_adapter_refuses_sequential_operational_identity_mutation_before_anchor_io(self):
        module = self.module()
        with TemporaryDirectory(prefix="oncam-coordinator-test-") as root:
            root = Path(root)
            coordinator, run_directory, other = root / "coordinator", root / "candidate", root / "other"
            coordinator.mkdir()
            run_directory.mkdir()
            other.mkdir()

            mutations = {
                "config_top": lambda run, store: run.c.__setitem__("manifest", "f" * 64),
                "config_nested": lambda run, store: run.c["tool_hashes"].__setitem__("php", "f" * 64),
                "run_directory": lambda run, store: setattr(run, "run", other),
                "run_session": lambda run, store: setattr(run, "session", "checkout-" + "f" * 32),
                "store_run": lambda run, store: setattr(store, "run", "c:/mutated"),
                "store_session": lambda run, store: setattr(store, "session", "checkout-" + "f" * 32),
                "store_binding": lambda run, store: setattr(store, "config_binding", "f" * 64),
                "store_directory": lambda run, store: setattr(store, "directory", other),
                "store_directory_identity": lambda run, store: setattr(store, "directory_identity", (9, 9)),
                "store_path": lambda run, store: setattr(store, "path", other / "anchor.json"),
                "store_lock_path": lambda run, store: setattr(store, "lock_path", other / "anchor.lock"),
                "store_publish_method": lambda run, store: setattr(store, "publish", lambda anchor: anchor),
                "store_load_method": lambda run, store: setattr(store, "load", lambda: {}),
            }
            for name, mutate in mutations.items():
                with self.subTest(name=name):
                    run = module.assemble_fresh(
                        config=self.config(run_directory), coordinator_directory=coordinator,
                    )
                    publisher = run.anchor_publisher
                    store = publisher.store
                    mutate(run, store)
                    with patch.object(module.supervisor_module.subprocess, "Popen") as popen:
                        with self.assertRaisesRegex(module.CoordinatorRefused, "^coordinator_binding$"):
                            publisher({"generation": 1, "digest": "c" * 64})
                        with self.assertRaisesRegex(module.CoordinatorRefused, "^coordinator_binding$"):
                            publisher.load()
                    popen.assert_not_called()
                    self.assertEqual(run.journal_generation, 0)
                    self.assertFalse(any(coordinator.glob("checkout-anchor-*.json")))

            run = module.assemble_fresh(
                config=self.config(run_directory), coordinator_directory=coordinator,
            )
            publisher = run.anchor_publisher
            run.anchor_publisher = object()
            with patch.object(module.supervisor_module.subprocess, "Popen") as popen, \
                    self.assertRaisesRegex(module.CoordinatorRefused, "^coordinator_binding$"):
                publisher({"generation": 1, "digest": "c" * 64})
            popen.assert_not_called()
            self.assertFalse(any(coordinator.glob("checkout-anchor-*.json")))

            run = module.assemble_fresh(
                config=self.config(run_directory), coordinator_directory=coordinator,
            )
            publisher = run.anchor_publisher
            publisher.store = object()
            with patch.object(module.supervisor_module.subprocess, "Popen") as popen, \
                    self.assertRaisesRegex(module.CoordinatorRefused, "^coordinator_binding$"):
                publisher.load()
            popen.assert_not_called()
            self.assertFalse(any(coordinator.glob("checkout-anchor-*.json")))

    def test_adapter_postcheck_detects_in_process_drift_from_captured_callable(self):
        module = self.module()

        class Run:
            run = Path("c:/candidate")
            session = self.session

            def _config_binding(self, session):
                return "b" * 64

        class Store:
            directory = Path("c:/coordinator")
            directory_identity = (1, 2)
            run = "c:/candidate"
            session = self.session
            config_binding = "b" * 64
            path = directory / "anchor.json"
            lock_path = directory / "anchor.lock"

            def publish(self, anchor):
                self.path = self.directory / "mutated.json"
                return dict(anchor)

            def load(self):
                self.lock_path = self.directory / "mutated.lock"
                return {
                    "version": 1,
                    "run": self.run,
                    "session": self.session,
                    "configBinding": self.config_binding,
                    "anchor": {"generation": 1, "digest": "c" * 64},
                }

        for operation in ("publish", "load"):
            with self.subTest(operation=operation):
                run, store = Run(), Store()
                publisher = module.SupervisorAnchorPublisher(
                    store,
                    run=run,
                    expected_run="c:/candidate",
                    expected_session=self.session,
                    expected_config_binding="b" * 64,
                )
                run.anchor_publisher = publisher
                with self.assertRaisesRegex(module.CoordinatorRefused, "^coordinator_binding$"):
                    if operation == "publish":
                        publisher({"generation": 1, "digest": "c" * 64})
                    else:
                        publisher.load()

    def test_fresh_assembly_binds_internal_config_parity_and_claims_without_popen(self):
        module = self.module()
        with TemporaryDirectory(prefix="oncam-coordinator-test-") as root:
            root = Path(root)
            coordinator, run_directory = root / "coordinator", root / "candidate"
            coordinator.mkdir()
            run_directory.mkdir()
            config = self.config(run_directory)

            run = module.assemble_fresh(config=config, coordinator_directory=coordinator)
            self.assertEqual(
                run.anchor_publisher.store.config_binding,
                run._config_binding(run.session),
            )
            run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            with patch.object(module.supervisor_module.subprocess, "Popen") as popen:
                run.claim(1)
                popen.assert_not_called()
            self.assertEqual(run.anchor_publisher.load(), run.journal_anchor())

    def test_recovery_assembly_returns_loaded_raw_anchor_without_recover_or_popen(self):
        module = self.module()
        with TemporaryDirectory(prefix="oncam-coordinator-test-") as root:
            root = Path(root)
            coordinator, run_directory = root / "coordinator", root / "candidate"
            coordinator.mkdir()
            run_directory.mkdir()
            config = self.config(run_directory)
            original = module.assemble_fresh(config=config, coordinator_directory=coordinator)
            original.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            original.claim(1)

            with patch.object(module.supervisor_module.WindowsRun, "recover_ownership") as recover, \
                    patch.object(module.supervisor_module.subprocess, "Popen") as popen:
                candidate, anchor = module.assemble_recovery(
                    config=config,
                    coordinator_directory=coordinator,
                    session=original.session,
                )
                recover.assert_not_called()
                popen.assert_not_called()
            self.assertEqual(anchor, original.journal_anchor())
            self.assertEqual(candidate.anchor_publisher.load(), anchor)
            self.assertEqual(candidate.session, original.session)
            self.assertEqual(candidate.lifecycle_phase, "recovery_ready")
            with patch.object(module.supervisor_module.subprocess, "Popen") as popen, \
                    self.assertRaisesRegex(module.supervisor_module.Refused, "^lifecycle_phase$"):
                candidate.claim(1)
            popen.assert_not_called()
            self.assertEqual(candidate.anchor_publisher.load(), anchor)

    def test_recovery_wrong_session_or_config_fails_without_popen(self):
        module = self.module()
        with TemporaryDirectory(prefix="oncam-coordinator-test-") as root:
            root = Path(root)
            coordinator, run_directory = root / "coordinator", root / "candidate"
            coordinator.mkdir()
            run_directory.mkdir()
            config = self.config(run_directory)
            original = module.assemble_fresh(config=config, coordinator_directory=coordinator)
            original.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            original.claim(1)

            changed = {**config, "manifest": "f" * 64}
            cases = (("checkout-" + "f" * 32, config), (original.session, changed))
            for session, candidate_config in cases:
                with self.subTest(session=session, changed=candidate_config is changed), \
                        patch.object(module.supervisor_module.subprocess, "Popen") as popen, \
                        self.assertRaisesRegex(module.CoordinatorRefused, "^coordinator_anchor$"):
                    module.assemble_recovery(
                        config=candidate_config,
                        coordinator_directory=coordinator,
                        session=session,
                    )
                popen.assert_not_called()

    def test_locked_publish_and_corrupt_recovery_store_fail_with_fixed_codes_without_popen(self):
        module = self.module()
        with TemporaryDirectory(prefix="oncam-coordinator-test-") as root:
            root = Path(root)
            coordinator, first_run, second_run = root / "coordinator", root / "first", root / "second"
            coordinator.mkdir()
            first_run.mkdir()
            second_run.mkdir()

            locked = module.assemble_fresh(
                config=self.config(first_run), coordinator_directory=coordinator,
            )
            locked.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            locked.anchor_publisher.store.lock_path.write_text("LOCK\n", encoding="ascii")
            with patch.object(module.supervisor_module.subprocess, "Popen") as popen, \
                    self.assertRaisesRegex(module.supervisor_module.Refused, "^anchor_publish$"):
                locked.claim(1)
            popen.assert_not_called()

            original = module.assemble_fresh(
                config=self.config(second_run), coordinator_directory=coordinator,
            )
            original.owner = {"pid": 8, "started": "101", "nonce": "d" * 64}
            original.claim(1)
            original.anchor_publisher.store.path.write_text('{"truncated":', encoding="ascii")
            with patch.object(module.supervisor_module.subprocess, "Popen") as popen, \
                    self.assertRaisesRegex(module.CoordinatorRefused, "^coordinator_anchor$"):
                module.assemble_recovery(
                    config=self.config(second_run),
                    coordinator_directory=coordinator,
                    session=original.session,
                )
            popen.assert_not_called()

    def test_assembly_rejects_implicit_inputs_and_never_creates_directories(self):
        module = self.module()
        with TemporaryDirectory(prefix="oncam-coordinator-test-") as root:
            root = Path(root)
            run_directory = root / "candidate"
            run_directory.mkdir()
            missing = root / "missing"
            cases = (
                ({"config": None, "coordinator_directory": root}, module.CoordinatorRefused,
                 "coordinator_config"),
                ({"config": self.config(run_directory), "coordinator_directory": None},
                 module.CoordinatorRefused, "coordinator_directory"),
                ({"config": self.config(run_directory), "coordinator_directory": missing},
                 module.anchor_store_module.AnchorStoreRefused, "anchor_path"),
                ({"config": self.config(run_directory), "coordinator_directory": run_directory},
                 module.anchor_store_module.AnchorStoreRefused, "anchor_scope"),
            )
            for kwargs, exception, reason in cases:
                with self.subTest(reason=reason), self.assertRaisesRegex(exception, f"^{reason}$"):
                    module.assemble_fresh(**kwargs)
            self.assertFalse(missing.exists())

    def test_import_and_assembly_do_not_read_environment_or_start_runtime(self):
        module = self.module()
        with TemporaryDirectory(prefix="oncam-coordinator-test-") as root:
            root = Path(root)
            coordinator, run_directory = root / "coordinator", root / "candidate"
            coordinator.mkdir()
            run_directory.mkdir()
            with patch.object(module.os, "getenv", side_effect=AssertionError("no environment")), \
                    patch.object(module.supervisor_module.subprocess, "Popen", side_effect=AssertionError("no process")):
                run = module.assemble_fresh(
                    config=self.config(run_directory),
                    coordinator_directory=coordinator,
                )
            self.assertFalse(run.claimed)
            self.assertFalse((run_directory / "supervisor.json").exists())


if __name__ == "__main__":
    unittest.main()
