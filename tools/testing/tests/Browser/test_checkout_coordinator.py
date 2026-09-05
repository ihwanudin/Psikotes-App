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
    asset_review_files = (
        "public/css/checkout-summary-v1.css",
        "public/brand/oncam-logo-full-color.png",
        "public/js/checkout-confirmation-v1.js",
        "public/js/checkout-payment-v1.js",
        "resources/views/checkout/summary.blade.php",
        "tools/testing/tests/Browser/serve-checkout-session.php",
    )

    def module(self):
        return load_module("checkout_coordinator", "checkout-coordinator.py")

    def leased_run(self, module, config, coordinator):
        lease = module.lease_module.CheckoutCoordinatorLease.acquire(
            coordinator_directory=coordinator,
            run_directory=config["directory"],
        )
        return module._assemble_fresh(config, coordinator, lease), lease

    def claimed_history(self, module, config, coordinator):
        run, lease = self.leased_run(module, config, coordinator)
        run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
        run.claim(1)
        anchor = run.journal_anchor()
        lease.release("lifecycle_finished")  # Simulates the former owner process exiting.
        return run, anchor

    @staticmethod
    def config(run: Path):
        names = {
            "ini": "runtime.ini",
            "browser_config": "browser-config.json",
            "cert": "cert.pem",
            "key": "key.pem",
        }
        paths = {
            name: str((run / names.get(name, name)).absolute())
            for name in ("php", "python", "node", "powershell", "cli", "browser",
                         "ini", "browser_config", "cert", "key")
        }
        return {
            "directory": str(run.absolute()),
            "manifest": "a" * 64,
            **paths,
            "tool_hashes": {name: format(index, "064x") for index, name in enumerate(paths, 1)},
            "asset_delivery_review": {
                name: format(index, "064x")
                for index, name in enumerate(CheckoutCoordinatorTests.asset_review_files, 20)
            },
        }

    def test_adapter_delegates_publish_and_returns_an_isolated_raw_anchor(self):
        module = self.module()

        class Run:
            run = Path("c:/candidate")
            session = self.session

            def _required_lifecycle_lease(self):
                return object()

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

            def _required_lifecycle_lease(self):
                return object()

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

            run, lease = self.leased_run(module, config, coordinator)
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
            lease.release("lifecycle_finished")

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
                "config_directory": lambda run, store: run.c.__setitem__("directory", str(other)),
                "config_review": lambda run, store: run.c["asset_delivery_review"].__setitem__(
                    self.asset_review_files[0], "f" * 64
                ),
                "config_extra": lambda run, store: run.c.__setitem__("unexpected", False),
                "config_hash_extra": lambda run, store: run.c["tool_hashes"].__setitem__(
                    "unexpected", "f" * 64
                ),
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

            def _required_lifecycle_lease(self):
                return object()

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

            run, lease = self.leased_run(module, config, coordinator)
            self.assertEqual(
                run.anchor_publisher.store.config_binding,
                run._config_binding(run.session),
            )
            run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            with patch.object(module.supervisor_module.subprocess, "Popen") as popen:
                run.claim(1)
                popen.assert_not_called()
            self.assertEqual(run.anchor_publisher.load(), run.journal_anchor())
            lease.release("lifecycle_finished")

    def test_public_fresh_assembly_is_inspection_only_without_lease_capability(self):
        module = self.module()
        with TemporaryDirectory(prefix="oncam-coordinator-test-") as root:
            root = Path(root)
            coordinator, run_directory = root / "coordinator", root / "candidate"
            coordinator.mkdir()
            run_directory.mkdir()
            run = module.assemble_fresh(
                config=self.config(run_directory), coordinator_directory=coordinator,
            )
            with self.assertRaisesRegex(module.CoordinatorRefused, "^coordinator_binding$"):
                run.anchor_publisher({"generation": 1, "digest": "c" * 64})
            with self.assertRaisesRegex(module.supervisor_module.Refused, "^lifecycle_lease$"):
                run.claim(1)
            self.assertFalse((run_directory / "supervisor.json").exists())
            self.assertFalse(any(coordinator.glob("checkout-anchor-*.json")))

    def test_bare_recovery_assembly_refuses_before_anchor_io_or_popen(self):
        module = self.module()
        with TemporaryDirectory(prefix="oncam-coordinator-test-") as root:
            root = Path(root)
            coordinator, run_directory = root / "coordinator", root / "candidate"
            coordinator.mkdir()
            run_directory.mkdir()
            config = self.config(run_directory)
            with patch.object(module.supervisor_module.WindowsRun, "recover_ownership") as recover, \
                    patch.object(module.anchor_store_module.CheckoutAnchorStore, "load") as load, \
                    patch.object(module.supervisor_module.subprocess, "Popen") as popen:
                with self.assertRaisesRegex(module.CoordinatorRefused, "^coordinator_lease$"):
                    module.assemble_recovery(
                        config=config,
                        coordinator_directory=coordinator,
                        session=self.session,
                    )
                recover.assert_not_called()
                load.assert_not_called()
                popen.assert_not_called()

    def test_supervise_fresh_assembles_bound_run_then_delegates_exact_options(self):
        module = self.module()
        with TemporaryDirectory(prefix="oncam-coordinator-test-") as root:
            root = Path(root)
            coordinator, run_directory = root / "coordinator", root / "candidate"
            coordinator.mkdir()
            run_directory.mkdir()
            observed = {}

            def supervise(run, *, mode, requests, budget):
                run.anchor_publisher._validate_binding()
                observed.update(
                    run=run,
                    mode=mode,
                    requests=requests,
                    budget=budget,
                )
                return {"state": "synthetic"}

            with patch.object(module.supervisor_module, "supervise", side_effect=supervise) as lifecycle, \
                    patch.object(module.os, "getenv", side_effect=AssertionError("no environment")), \
                    patch.object(module.supervisor_module.subprocess, "Popen",
                                 side_effect=AssertionError("no process")) as popen:
                result = module.supervise_fresh(
                    config=self.config(run_directory),
                    coordinator_directory=coordinator,
                    mode="full",
                    requests=2,
                    budget=321,
                )

            self.assertEqual(result, {"state": "synthetic"})
            self.assertEqual(lifecycle.call_count, 1)
            self.assertFalse(observed["run"].claimed)
            self.assertEqual(observed["run"].lifecycle_phase, "new")
            self.assertEqual(
                (observed["mode"], observed["requests"], observed["budget"]),
                ("full", 2, 321),
            )
            popen.assert_not_called()

    def test_facade_holds_run_keyed_lease_before_assembly_and_releases_after_safe_return(self):
        module = self.module()
        with TemporaryDirectory(prefix="oncam-coordinator-test-") as root:
            root = Path(root)
            coordinator, run_directory = root / "coordinator", root / "candidate"
            coordinator.mkdir()
            run_directory.mkdir()
            config = self.config(run_directory)
            original_builder = module._run_from_config

            def observed_builder(value):
                with self.assertRaisesRegex(module.lease_module.LeaseRefused, "^lease_held$"):
                    module.lease_module.CheckoutCoordinatorLease.acquire(
                        coordinator_directory=coordinator, run_directory=run_directory,
                    )
                return original_builder(value)

            with patch.object(module, "_run_from_config", side_effect=observed_builder), \
                    patch.object(module.supervisor_module, "supervise", return_value={"state": "synthetic"}):
                self.assertEqual(
                    module.supervise_fresh(
                        config=config, coordinator_directory=coordinator,
                        mode="smoke", requests=1, budget=1,
                    ),
                    {"state": "synthetic"},
                )
            replacement = module.lease_module.CheckoutCoordinatorLease.acquire(
                coordinator_directory=coordinator, run_directory=run_directory,
            )
            replacement.release("lifecycle_finished")

            interruption = KeyboardInterrupt("synthetic_primary")

            def unsafe_raise(run, **_options):
                run.claimed = True
                run.lifecycle_phase = "normal"
                raise interruption

            with patch.object(module.supervisor_module, "supervise", side_effect=unsafe_raise), \
                    self.assertRaises(KeyboardInterrupt) as caught:
                module.supervise_fresh(
                    config=config, coordinator_directory=coordinator,
                    mode="smoke", requests=1, budget=1,
                )
            self.assertIs(caught.exception, interruption)
            replacement = module.lease_module.CheckoutCoordinatorLease.acquire(
                coordinator_directory=coordinator, run_directory=run_directory,
            )
            replacement.release("lifecycle_finished")

    def test_nonterminal_return_refuses_but_always_releases_and_primary_wins(self):
        module = self.module()
        with TemporaryDirectory(prefix="oncam-coordinator-test-") as root:
            root = Path(root)
            coordinator, run_directory = root / "coordinator", root / "candidate"
            coordinator.mkdir()
            run_directory.mkdir()
            config = self.config(run_directory)

            def unsafe_return(run, **_options):
                run.claimed = True
                run.lifecycle_phase = "normal"
                return {"state": "unsafe"}

            with patch.object(module.supervisor_module, "supervise", side_effect=unsafe_return), \
                    self.assertRaisesRegex(module.CoordinatorRefused, "^coordinator_lease_terminal$"):
                module.supervise_fresh(
                    config=config, coordinator_directory=coordinator,
                    mode="smoke", requests=1, budget=1,
                )
            replacement = module.lease_module.CheckoutCoordinatorLease.acquire(
                coordinator_directory=coordinator, run_directory=run_directory,
            )
            replacement.release("lifecycle_finished")

    def test_terminal_inspection_failure_cannot_strand_the_lease(self):
        module = self.module()
        with TemporaryDirectory(prefix="oncam-coordinator-test-") as root:
            root = Path(root)
            coordinator, run_directory = root / "coordinator", root / "candidate"
            coordinator.mkdir()
            run_directory.mkdir()
            lease = module.lease_module.CheckoutCoordinatorLease.acquire(
                coordinator_directory=coordinator, run_directory=run_directory,
            )

            class CorruptRun:
                @property
                def claimed(self):
                    raise RuntimeError("PRIVATE")

            with self.assertRaisesRegex(module.CoordinatorRefused, "^coordinator_lease_terminal$"):
                module._release_lease(lease, CorruptRun(), None)
            replacement = module.lease_module.CheckoutCoordinatorLease.acquire(
                coordinator_directory=coordinator, run_directory=run_directory,
            )
            replacement.release("lifecycle_finished")

    def test_facade_uses_one_config_snapshot_for_lease_and_assembly(self):
        module = self.module()
        with TemporaryDirectory(prefix="oncam-coordinator-test-") as root:
            root = Path(root)
            coordinator, first, second = root / "coordinator", root / "first", root / "second"
            coordinator.mkdir()
            first.mkdir()
            second.mkdir()
            config = self.config(first)
            real_acquire = module._acquire_lease
            observed = {}

            def acquire_then_mutate(snapshot, coordinator_directory):
                lease = real_acquire(snapshot, coordinator_directory)
                config["directory"] = str(second.absolute())
                return lease

            def supervise(run, **_options):
                observed["run"] = run.run
                return {"state": "synthetic"}

            with patch.object(module, "_acquire_lease", side_effect=acquire_then_mutate), \
                    patch.object(module.supervisor_module, "supervise", side_effect=supervise):
                module.supervise_fresh(
                    config=config, coordinator_directory=coordinator,
                    mode="smoke", requests=1, budget=1,
                )
            self.assertEqual(observed["run"], first)

    def test_facade_resolves_coordinator_pathlike_once(self):
        module = self.module()
        with TemporaryDirectory(prefix="oncam-coordinator-test-") as root:
            root = Path(root)
            first, second, run_directory = root / "first", root / "second", root / "candidate"
            first.mkdir()
            second.mkdir()
            run_directory.mkdir()

            class TogglingPath:
                def __init__(self):
                    self.calls = 0

                def __fspath__(self):
                    self.calls += 1
                    return str(first if self.calls == 1 else second)

            pathlike = TogglingPath()
            with patch.object(module.supervisor_module, "supervise", return_value={"state": "synthetic"}):
                module.supervise_fresh(
                    config=self.config(run_directory), coordinator_directory=pathlike,
                    mode="smoke", requests=1, budget=1,
                )
            self.assertEqual(pathlike.calls, 1)
            self.assertTrue((run_directory / ".checkout-coordinator.lease").is_file())
            self.assertFalse(any(second.iterdir()))

    def test_recover_existing_assembles_recovery_then_delegates_exact_anchor(self):
        module = self.module()
        with TemporaryDirectory(prefix="oncam-coordinator-test-") as root:
            root = Path(root)
            coordinator, run_directory = root / "coordinator", root / "candidate"
            coordinator.mkdir()
            run_directory.mkdir()
            config = self.config(run_directory)
            original, expected_anchor = self.claimed_history(module, config, coordinator)
            observed = {}

            def recover(run, *, session, anchor, budget):
                run.anchor_publisher._validate_binding()
                observed.update(
                    run=run,
                    session=session,
                    anchor=anchor,
                    budget=budget,
                )
                return {"state": "synthetic_recovery"}

            with patch.object(module.supervisor_module, "recover", side_effect=recover) as lifecycle, \
                    patch.object(module.os, "getenv", side_effect=AssertionError("no environment")), \
                    patch.object(module.supervisor_module.subprocess, "Popen",
                                 side_effect=AssertionError("no process")) as popen:
                result = module.recover_existing(
                    config=config,
                    coordinator_directory=coordinator,
                    session=original.session,
                    budget=14,
                )

            self.assertEqual(result, {"state": "synthetic_recovery"})
            self.assertEqual(lifecycle.call_count, 1)
            self.assertEqual(observed["run"].lifecycle_phase, "recovery_ready")
            self.assertEqual(observed["session"], original.session)
            self.assertEqual(observed["anchor"], expected_anchor)
            self.assertIsNot(observed["anchor"], expected_anchor)
            self.assertEqual(observed["budget"], 14)
            popen.assert_not_called()

    def test_lifecycle_facade_stops_on_assembly_failure_before_delegation(self):
        module = self.module()
        with TemporaryDirectory(prefix="oncam-coordinator-test-") as root:
            root = Path(root)
            coordinator, run_directory = root / "coordinator", root / "candidate"
            coordinator.mkdir()
            run_directory.mkdir()
            invalid = {**self.config(run_directory), "unexpected": "PRIVATE_VALUE"}

            with patch.object(module.supervisor_module, "supervise") as supervise, \
                    patch.object(module.supervisor_module, "recover") as recover, \
                    patch.object(module.supervisor_module.subprocess, "Popen",
                                 side_effect=AssertionError("no process")):
                with self.assertRaisesRegex(module.CoordinatorRefused, "^coordinator_config$"):
                    module.supervise_fresh(
                        config=invalid,
                        coordinator_directory=coordinator,
                        mode="smoke",
                        requests=1,
                        budget=1,
                    )
                with self.assertRaisesRegex(module.CoordinatorRefused, "^coordinator_anchor$"):
                    module.recover_existing(
                        config=self.config(run_directory),
                        coordinator_directory=coordinator,
                        session=self.session,
                        budget=1,
                    )

            supervise.assert_not_called()
            recover.assert_not_called()

    def test_lifecycle_facade_preserves_supervisor_and_base_exceptions(self):
        module = self.module()
        with TemporaryDirectory(prefix="oncam-coordinator-test-") as root:
            root = Path(root)
            coordinator, run_directory = root / "coordinator", root / "candidate"
            coordinator.mkdir()
            run_directory.mkdir()
            config = self.config(run_directory)
            original, _ = self.claimed_history(module, config, coordinator)

            calls = (
                ("fresh", "supervise", lambda: module.supervise_fresh(
                    config=config, coordinator_directory=coordinator,
                    mode="smoke", requests=1, budget=1,
                )),
                ("recovery", "recover", lambda: module.recover_existing(
                    config=config, coordinator_directory=coordinator,
                    session=original.session, budget=1,
                )),
            )
            errors = (
                module.supervisor_module.Refused("invalid_options"),
                KeyboardInterrupt("synthetic_interrupt"),
                SystemExit("synthetic_exit"),
            )
            for operation, target, invoke in calls:
                for error in errors:
                    with self.subTest(operation=operation, error=type(error).__name__), \
                            patch.object(module.supervisor_module, target, side_effect=error), \
                            patch.object(module.supervisor_module.subprocess, "Popen",
                                         side_effect=AssertionError("no process")) as popen, \
                            self.assertRaises(type(error)) as caught:
                        invoke()
                    self.assertIs(caught.exception, error)
                    popen.assert_not_called()

    def test_recovery_wrong_session_or_config_fails_without_popen(self):
        module = self.module()
        with TemporaryDirectory(prefix="oncam-coordinator-test-") as root:
            root = Path(root)
            coordinator, run_directory = root / "coordinator", root / "candidate"
            coordinator.mkdir()
            run_directory.mkdir()
            config = self.config(run_directory)
            original, _ = self.claimed_history(module, config, coordinator)

            changed = {**config, "manifest": "f" * 64}
            cases = (("checkout-" + "f" * 32, config), (original.session, changed))
            for session, candidate_config in cases:
                with self.subTest(session=session, changed=candidate_config is changed), \
                        patch.object(module.supervisor_module.subprocess, "Popen") as popen, \
                        self.assertRaisesRegex(module.CoordinatorRefused, "^coordinator_anchor$"):
                    module.recover_existing(
                        config=candidate_config,
                        coordinator_directory=coordinator,
                        session=session,
                        budget=1,
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

            locked, locked_lease = self.leased_run(
                module, self.config(first_run), coordinator,
            )
            locked.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            locked.anchor_publisher.store.lock_path.write_text("LOCK\n", encoding="ascii")
            with patch.object(module.supervisor_module.subprocess, "Popen") as popen, \
                    self.assertRaisesRegex(module.supervisor_module.Refused, "^anchor_publish$"):
                locked.claim(1)
            popen.assert_not_called()
            locked_lease.release("lifecycle_finished")

            original, _ = self.claimed_history(module, self.config(second_run), coordinator)
            original.anchor_publisher.store.path.write_text('{"truncated":', encoding="ascii")
            with patch.object(module.supervisor_module.subprocess, "Popen") as popen, \
                    self.assertRaisesRegex(module.CoordinatorRefused, "^coordinator_anchor$"):
                module.recover_existing(
                    config=self.config(second_run),
                    coordinator_directory=coordinator,
                    session=original.session,
                    budget=1,
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

    def test_assembly_maps_malformed_nested_candidate_config_to_fixed_coordinator_error(self):
        module = self.module()
        with TemporaryDirectory(prefix="oncam-coordinator-test-") as root:
            root = Path(root)
            coordinator, run_directory = root / "coordinator", root / "candidate"
            coordinator.mkdir()
            run_directory.mkdir()
            valid = self.config(run_directory)
            invalid = []

            missing_hash = {**valid, "tool_hashes": dict(valid["tool_hashes"])}
            missing_hash["tool_hashes"].pop("php")
            invalid.append(missing_hash)
            invalid.append({**valid, "tool_hashes": {**valid["tool_hashes"], "extra": "f" * 64}})

            missing_review = {
                **valid,
                "asset_delivery_review": dict(valid["asset_delivery_review"]),
            }
            missing_review["asset_delivery_review"].pop(self.asset_review_files[0])
            invalid.append(missing_review)
            invalid.append({
                **valid,
                "asset_delivery_review": {
                    **valid["asset_delivery_review"],
                    self.asset_review_files[0]: True,
                },
            })
            invalid.append({**valid, "unexpected": "PRIVATE_VALUE"})

            for candidate in invalid:
                with self.subTest(keys=tuple(candidate)), \
                        patch.object(module.supervisor_module.subprocess, "Popen",
                                     side_effect=AssertionError("no process")), \
                        self.assertRaisesRegex(module.CoordinatorRefused, "^coordinator_config$"):
                    module.assemble_fresh(
                        config=candidate,
                        coordinator_directory=coordinator,
                    )

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
