"""Pure tests for checkout supervisor/anchor-store assembly; never starts runtime processes."""

from __future__ import annotations

import importlib.util
import json
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest
from unittest.mock import patch


def load_module(name: str, filename: str):
    spec = importlib.util.spec_from_file_location(name, Path(__file__).with_name(filename))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class SyntheticAclAttestor:
    """Pure canonical evidence adapter; it does not claim operating-system ACL proof."""

    def __init__(self, module):
        self.module = module
        self.cache = {}
        self.requests = []
        self.discards = []

    def attest(self, request):
        snapshot = json.loads(json.dumps(request))
        self.requests.append(snapshot)
        evidence = {
            "version": 1,
            "requestDigest": self.module.supervisor_module.acl_attestation_module.request_digest(
                snapshot
            ),
            "policyDigest": self.module.supervisor_module.ACL_POLICY_DIGEST,
            "leaseDigest": self.module.supervisor_module.acl_attestation_module.lease_digest(
                snapshot["leaseBinding"], snapshot["leaseIdentity"],
            ),
            "targets": [],
        }
        for target in snapshot["targets"]:
            identity = snapshot["leaseIdentity"].get(target["role"], {
                "volumeSerial": "31", "fileId": "32",
            })
            evidence["targets"].append({
                **target,
                **identity,
                "ownerSid": "S-1-5-21-1",
                "daclDigest": "d" * 64,
                "reparse": False,
                "policySatisfied": True,
            })
        raw = self.module.supervisor_module.acl_attestation_module.canonical_evidence(
            evidence, snapshot,
        )
        self.cache[evidence["requestDigest"]] = raw
        return raw

    def load(self, digest):
        return self.cache.pop(digest, None)

    def discard(self, digest):
        self.discards.append(digest)
        self.cache.pop(digest, None)


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

    @staticmethod
    def attestor(module):
        return SyntheticAclAttestor(module)

    def leased_run(self, module, config, coordinator):
        lease = module.lease_module.CheckoutCoordinatorLease.acquire(
            coordinator_directory=coordinator,
            run_directory=config["directory"],
        )
        return module._assemble_fresh(
            config, coordinator, lease, self.attestor(module),
        ), lease

    def claimed_history(self, module, config, coordinator):
        run, lease = self.leased_run(module, config, coordinator)
        run.begin_acl_execution("fresh")
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
            "acl_policy_digest": (
                "a63c221764f73a54e87513fc91cded6b3fa16825138f6b24b6118132829f4eeb"
            ),
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
                    run, lease = self.leased_run(
                        module, self.config(run_directory), coordinator,
                    )
                    try:
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
                    finally:
                        lease.release("lifecycle_finished")

            run, lease = self.leased_run(module, self.config(run_directory), coordinator)
            try:
                publisher = run.anchor_publisher
                run.anchor_publisher = object()
                with patch.object(module.supervisor_module.subprocess, "Popen") as popen, \
                        self.assertRaisesRegex(module.CoordinatorRefused, "^coordinator_binding$"):
                    publisher({"generation": 1, "digest": "c" * 64})
                popen.assert_not_called()
                self.assertFalse(any(coordinator.glob("checkout-anchor-*.json")))
            finally:
                lease.release("lifecycle_finished")

            run, lease = self.leased_run(module, self.config(run_directory), coordinator)
            try:
                publisher = run.anchor_publisher
                publisher.store = object()
                with patch.object(module.supervisor_module.subprocess, "Popen") as popen, \
                        self.assertRaisesRegex(module.CoordinatorRefused, "^coordinator_binding$"):
                    publisher.load()
                popen.assert_not_called()
                self.assertFalse(any(coordinator.glob("checkout-anchor-*.json")))
            finally:
                lease.release("lifecycle_finished")

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
                run.begin_acl_execution("fresh")
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
            self.assertIsNone(run.anchor_publisher)
            with self.assertRaisesRegex(module.supervisor_module.Refused, "^acl_admission$"):
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
                with self.assertRaisesRegex(module.supervisor_module.Refused, "^acl_admission$"):
                    run.begin_acl_execution("fresh")
                run.anchor_publisher._validate_binding()
                observed.update(
                    run=run,
                    mode=mode,
                    requests=requests,
                    budget=budget,
                )
                return {"state": "synthetic"}

            with patch.object(module.supervisor_module, "_supervise_active",
                              side_effect=supervise) as lifecycle, \
                    patch.object(module.os, "getenv", side_effect=AssertionError("no environment")), \
                    patch.object(module.supervisor_module.subprocess, "Popen",
                                 side_effect=AssertionError("no process")) as popen:
                result = module.supervise_fresh(
                    config=self.config(run_directory),
                    coordinator_directory=coordinator,
                    acl_attestor=self.attestor(module),
                    mode="full",
                    requests=2,
                    budget=321,
                )

            self.assertEqual(result, {"state": "synthetic"})
            self.assertEqual(lifecycle.call_count, 1)
            self.assertFalse(observed["run"].claimed)
            self.assertEqual(observed["run"].lifecycle_phase, "new")
            self.assertEqual(observed["run"]._WindowsRun__acl_state, "exhausted")
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
                        acl_attestor=self.attestor(module),
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
                    acl_attestor=self.attestor(module),
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
                    acl_attestor=self.attestor(module),
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
                    acl_attestor=self.attestor(module),
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
                    acl_attestor=self.attestor(module),
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
                with self.assertRaisesRegex(module.supervisor_module.Refused, "^acl_admission$"):
                    run.begin_acl_execution("recovery")
                run.anchor_publisher._validate_binding()
                observed.update(
                    run=run,
                    session=session,
                    anchor=anchor,
                    budget=budget,
                )
                return {"state": "synthetic_recovery"}

            with patch.object(module.supervisor_module, "_recover_active",
                              side_effect=recover) as lifecycle, \
                    patch.object(module.os, "getenv", side_effect=AssertionError("no environment")), \
                    patch.object(module.supervisor_module.subprocess, "Popen",
                                 side_effect=AssertionError("no process")) as popen:
                result = module.recover_existing(
                    config=config,
                    coordinator_directory=coordinator,
                    acl_attestor=self.attestor(module),
                    session=original.session,
                    budget=14,
                )

            self.assertEqual(result, {"state": "synthetic_recovery"})
            self.assertEqual(lifecycle.call_count, 1)
            self.assertEqual(observed["run"].lifecycle_phase, "recovery_ready")
            self.assertEqual(observed["run"]._WindowsRun__acl_state, "exhausted")
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
                        acl_attestor=self.attestor(module),
                        mode="smoke",
                        requests=1,
                        budget=1,
                    )
                with self.assertRaisesRegex(module.CoordinatorRefused, "^coordinator_acl$"):
                    module.recover_existing(
                        config=self.config(run_directory),
                        coordinator_directory=coordinator,
                        acl_attestor=self.attestor(module),
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
                    acl_attestor=self.attestor(module),
                    mode="smoke", requests=1, budget=1,
                )),
                ("recovery", "recover", lambda: module.recover_existing(
                    config=config, coordinator_directory=coordinator,
                    acl_attestor=self.attestor(module),
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
                    expected = (module.CoordinatorRefused
                                if isinstance(error, module.supervisor_module.Refused)
                                else type(error))
                    with self.subTest(operation=operation, error=type(error).__name__), \
                            patch.object(module.supervisor_module, target, side_effect=error), \
                            patch.object(module.supervisor_module.subprocess, "Popen",
                                         side_effect=AssertionError("no process")) as popen, \
                            self.assertRaises(expected) as caught:
                        invoke()
                    if isinstance(error, module.supervisor_module.Refused):
                        self.assertEqual(str(caught.exception), "coordinator_acl")
                    else:
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
                        self.assertRaisesRegex(module.CoordinatorRefused, "^coordinator_acl$"):
                    module.recover_existing(
                        config=candidate_config,
                        coordinator_directory=coordinator,
                        acl_attestor=self.attestor(module),
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
            try:
                locked.begin_acl_execution("fresh")
                with patch.object(module.supervisor_module.subprocess, "Popen") as popen, \
                        self.assertRaisesRegex(module.supervisor_module.Refused, "^anchor_publish$"):
                    locked.claim(1)
                popen.assert_not_called()
            finally:
                locked_lease.release("lifecycle_finished")

            original, _ = self.claimed_history(module, self.config(second_run), coordinator)
            original.anchor_publisher.store.path.write_text('{"truncated":', encoding="ascii")
            with patch.object(module.supervisor_module.subprocess, "Popen") as popen, \
                    self.assertRaisesRegex(module.CoordinatorRefused, "^coordinator_acl$"):
                module.recover_existing(
                    config=self.config(second_run),
                    coordinator_directory=coordinator,
                    acl_attestor=self.attestor(module),
                    session=original.session,
                    budget=1,
                )
            popen.assert_not_called()

    def test_facades_require_attestor_and_acl_failure_precedes_publisher_or_delegate(self):
        module = self.module()

        class FailingAttestor(SyntheticAclAttestor):
            def attest(self, request):
                raise self.error

        with TemporaryDirectory(prefix="oncam-coordinator-test-") as root:
            root = Path(root)
            coordinator, run_directory = root / "coordinator", root / "candidate"
            coordinator.mkdir()
            run_directory.mkdir()
            config = self.config(run_directory)
            with self.assertRaisesRegex(module.CoordinatorRefused, "^coordinator_acl$"):
                module.supervise_fresh(
                    config=config, coordinator_directory=coordinator,
                    mode="smoke", requests=1, budget=1,
                )
            with self.assertRaisesRegex(module.CoordinatorRefused, "^coordinator_acl$"):
                module.supervise_fresh(
                    config=config, coordinator_directory=coordinator,
                    acl_attestor=None, mode="smoke", requests=1, budget=1,
                )
            with patch.object(module.anchor_store_module, "CheckoutAnchorStore") as store, \
                    patch.object(module.supervisor_module, "supervise") as supervise, \
                    self.assertRaisesRegex(module.CoordinatorRefused, "^coordinator_acl$"):
                module.supervise_fresh(
                    config=config, coordinator_directory=coordinator,
                    acl_attestor=object(), mode="smoke", requests=1, budget=1,
                )
            store.assert_not_called()
            supervise.assert_not_called()

            for error in (RuntimeError("PRIVATE_ACL"), KeyboardInterrupt("ACL_INTERRUPT"),
                          SystemExit(17)):
                attestor = FailingAttestor(module)
                attestor.error = error
                expected = module.CoordinatorRefused if isinstance(error, RuntimeError) else type(error)
                with self.subTest(kind=type(error).__name__), \
                        patch.object(module.anchor_store_module, "CheckoutAnchorStore") as store, \
                        patch.object(module.supervisor_module, "supervise") as supervise, \
                        self.assertRaises(expected) as caught:
                    module.supervise_fresh(
                        config=config, coordinator_directory=coordinator,
                        acl_attestor=attestor, mode="smoke", requests=1, budget=1,
                    )
                if isinstance(error, RuntimeError):
                    self.assertEqual(str(caught.exception), "coordinator_acl")
                    self.assertNotIn("PRIVATE", str(caught.exception))
                else:
                    self.assertIs(caught.exception, error)
                store.assert_not_called()
                supervise.assert_not_called()
                replacement = module.lease_module.CheckoutCoordinatorLease.acquire(
                    coordinator_directory=coordinator, run_directory=run_directory,
                )
                replacement.release("lifecycle_finished")

    def test_wrapper_base_exception_exhausts_admission_and_facade_releases_lease(self):
        module = self.module()
        error = KeyboardInterrupt("SYNTHETIC_WRAPPER_PRIMARY")
        observed = {}
        with TemporaryDirectory(prefix="oncam-coordinator-test-") as root:
            root = Path(root)
            coordinator, run_directory = root / "coordinator", root / "candidate"
            coordinator.mkdir()
            run_directory.mkdir()

            def interrupt(run, **_options):
                observed["run"] = run
                raise error

            with patch.object(module.supervisor_module, "_supervise_active",
                              side_effect=interrupt), \
                    self.assertRaises(KeyboardInterrupt) as caught:
                module.supervise_fresh(
                    config=self.config(run_directory), coordinator_directory=coordinator,
                    acl_attestor=self.attestor(module), mode="smoke", requests=1, budget=1,
                )
            self.assertIs(caught.exception, error)
            self.assertEqual(observed["run"]._WindowsRun__acl_state, "exhausted")
            replacement = module.lease_module.CheckoutCoordinatorLease.acquire(
                coordinator_directory=coordinator, run_directory=run_directory,
            )
            replacement.release("lifecycle_finished")

    def test_supervisor_wrapper_refusals_are_fixed_at_coordinator_boundary(self):
        module = self.module()
        with TemporaryDirectory(prefix="oncam-coordinator-test-") as root:
            root = Path(root)
            coordinator, run_directory = root / "coordinator", root / "candidate"
            coordinator.mkdir()
            run_directory.mkdir()

            def invoke():
                return module.supervise_fresh(
                    config=self.config(run_directory), coordinator_directory=coordinator,
                    acl_attestor=self.attestor(module), mode="smoke", requests=1, budget=1,
                )

            cases = ("begin", "body", "finish")
            for boundary in cases:
                refusal = module.supervisor_module.Refused("PRIVATE_" + boundary)
                patches = []
                if boundary == "begin":
                    patches.append(patch.object(
                        module.supervisor_module.WindowsRun, "begin_acl_execution",
                        side_effect=refusal,
                    ))
                    patches.append(patch.object(module.supervisor_module, "_supervise_active"))
                elif boundary == "body":
                    patches.append(patch.object(
                        module.supervisor_module, "_supervise_active", side_effect=refusal,
                    ))
                else:
                    patches.append(patch.object(
                        module.supervisor_module, "_supervise_active",
                        return_value={"state": "synthetic"},
                    ))
                    patches.append(patch.object(
                        module.supervisor_module.WindowsRun, "finish_acl_execution",
                        side_effect=refusal,
                    ))
                with self.subTest(boundary=boundary), patches[0] as first:
                    second_context = patches[1] if len(patches) == 2 else None
                    if second_context is None:
                        with self.assertRaisesRegex(
                            module.CoordinatorRefused, "^coordinator_acl$",
                        ) as caught:
                            invoke()
                    else:
                        with second_context as second, self.assertRaisesRegex(
                            module.CoordinatorRefused, "^coordinator_acl$",
                        ) as caught:
                            invoke()
                        if boundary == "begin":
                            second.assert_not_called()
                    self.assertNotIn("PRIVATE", str(caught.exception))
                    self.assertTrue(first.called)
                replacement = module.lease_module.CheckoutCoordinatorLease.acquire(
                    coordinator_directory=coordinator, run_directory=run_directory,
                )
                replacement.release("lifecycle_finished")

    def test_fresh_and_recovery_admission_order_is_exact(self):
        module = self.module()
        with TemporaryDirectory(prefix="oncam-coordinator-test-") as root:
            root = Path(root)
            coordinator, run_directory = root / "coordinator", root / "candidate"
            coordinator.mkdir()
            run_directory.mkdir()
            config = self.config(run_directory)
            original, _anchor = self.claimed_history(module, config, coordinator)

            def exercise(phase):
                events = []
                real_acquire = module._acquire_lease
                real_builder = module._run_from_config

                def acquire(candidate, coordinator_path):
                    events.append("lease")
                    return real_acquire(candidate, coordinator_path)

                def build(candidate):
                    events.append("construct")
                    run = real_builder(candidate)
                    methods = {
                        "bind_lifecycle_lease": "bind_lease",
                        "bind_acl_attestor": "bind_attestor",
                        "bind_anchor_publisher": "bind_publisher",
                    }
                    for name, event in methods.items():
                        original_method = getattr(run, name)

                        def wrapper(value, original_method=original_method, event=event):
                            events.append(event)
                            return original_method(value)

                        setattr(run, name, wrapper)
                    original_prepare = run.prepare_acl_admission

                    def prepare(boundary, requested_phase):
                        events.append("prepare_" + boundary)
                        return original_prepare(boundary, requested_phase)

                    run.prepare_acl_admission = prepare
                    if phase == "recovery":
                        original_load = run.load_recovery_anchor

                        def load_anchor():
                            events.append("load_anchor")
                            return original_load()

                        run.load_recovery_anchor = load_anchor
                    return run

                def delegate(_run, **_options):
                    events.append("delegate")
                    return {"state": "synthetic"}

                attestor = self.attestor(module)
                with patch.object(module, "_acquire_lease", side_effect=acquire), \
                        patch.object(module, "_run_from_config", side_effect=build), \
                        patch.object(module.supervisor_module, "supervise" if phase == "fresh" else "recover",
                                     side_effect=delegate):
                    if phase == "fresh":
                        module.supervise_fresh(
                            config=config, coordinator_directory=coordinator,
                            acl_attestor=attestor, mode="smoke", requests=1, budget=1,
                        )
                    else:
                        module.recover_existing(
                            config=config, coordinator_directory=coordinator,
                            acl_attestor=attestor, session=original.session, budget=1,
                        )
                expected = [
                    "lease", "construct", "bind_lease", "bind_attestor",
                    "prepare_anchor", "bind_publisher",
                ]
                if phase == "recovery":
                    expected.append("load_anchor")
                expected.extend(("prepare_execution", "delegate"))
                self.assertEqual(events, expected)
                self.assertEqual(
                    [request["boundary"] for request in attestor.requests],
                    ["anchor", "execution"],
                )

            exercise("fresh")
            exercise("recovery")

    def test_recovery_underlying_publisher_load_occurs_only_inside_supervisor_gate(self):
        module = self.module()
        with TemporaryDirectory(prefix="oncam-coordinator-test-") as root:
            root = Path(root)
            coordinator, run_directory = root / "coordinator", root / "candidate"
            coordinator.mkdir()
            run_directory.mkdir()
            config = self.config(run_directory)
            original, _anchor = self.claimed_history(module, config, coordinator)
            gate_load = module.supervisor_module.WindowsRun.load_recovery_anchor
            publisher_load = module.SupervisorAnchorPublisher.load
            state = {"inside": False, "gates": 0, "loads": 0}

            def gated(run):
                self.assertFalse(state["inside"])
                state["inside"] = True
                state["gates"] += 1
                try:
                    return gate_load(run)
                finally:
                    state["inside"] = False

            def loaded(publisher):
                self.assertTrue(state["inside"])
                state["loads"] += 1
                return publisher_load(publisher)

            with patch.object(module.supervisor_module.WindowsRun, "load_recovery_anchor", gated), \
                    patch.object(module.SupervisorAnchorPublisher, "load", loaded), \
                    patch.object(module.supervisor_module, "_recover_active",
                                 return_value={"state": "synthetic"}):
                result = module.recover_existing(
                    config=config, coordinator_directory=coordinator,
                    acl_attestor=self.attestor(module), session=original.session, budget=1,
                )
            self.assertEqual(result, {"state": "synthetic"})
            self.assertEqual(state, {"inside": False, "gates": 1, "loads": 1})

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
            )
            for kwargs, exception, reason in cases:
                with self.subTest(reason=reason), self.assertRaisesRegex(exception, f"^{reason}$"):
                    module.assemble_fresh(**kwargs)
            self.assertFalse(module.assemble_fresh(
                config=self.config(run_directory), coordinator_directory=missing,
            ).claimed)
            self.assertFalse(module.assemble_fresh(
                config=self.config(run_directory), coordinator_directory=run_directory,
            ).claimed)
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
