import copy
import ctypes
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest


ROOT = Path(__file__).parent


def load(name, filename):
    spec = importlib.util.spec_from_file_location(name, ROOT / filename)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


provider = load(
    "checkout_windows_protected_journal_provider",
    "checkout-windows-protected-journal-provider.py",
)
request_codec = load(
    "checkout_protected_journal_request_native_test",
    "checkout-protected-journal-request.py",
)
ZERO_DIGEST = "0" * 64


def canonical(value):
    return (json.dumps(value, sort_keys=True, separators=(",", ":"),
                       ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")


def canonical_path(path):
    value = str(path.resolve()).replace("\\", "/")
    return value[0].upper() + value[1:]


def identity(path):
    return provider._identity_for_test(
        canonical_path(path), directory=Path(path).is_dir()
    )


def path_digest(path):
    return hashlib.sha256(
        b"oncam.checkout.canonical-path.v1\0" + path.encode("ascii")
    ).hexdigest()


def request_value(run, journal, *, kind="one-shot-ledger", current=0,
                  proposed=None, provider_generation=4):
    run_path = canonical_path(run)
    current_digest = ZERO_DIGEST if current == 0 else format(current + 20, "064x")
    next_generation = current + 1 if proposed is None else proposed
    leaf_namespace = "composition-admission" if kind == "one-shot-ledger" \
        else "release-source.issuer-01.key-3.trust-5"
    return {
        "currentState": {"generation": current, "rawDigest": current_digest},
        "expectedFileIdentity": identity(journal),
        "forbiddenPathDigests": sorted([
            path_digest(run_path + "/runtime.ini"),
            path_digest(run_path + "/supervisor-config.json"),
            path_digest(run_path + "/tls/server.key"),
        ]),
        "journalKind": kind,
        "namespace": leaf_namespace,
        "operation": "compare-and-swap",
        "policyDigest": "2" * 64,
        "proposedState": {
            "generation": next_generation,
            "previousDigest": current_digest,
            "rawDigest": format(next_generation + 40, "064x"),
        },
        "providerAuthorityDigest": "4" * 64,
        "providerEvidenceDigest": "5" * 64,
        "providerGeneration": provider_generation,
        "rebootState": "certain",
        "runIdentity": identity(run),
        "securityDescriptorDigest": "6" * 64,
        "version": 1,
    }


class WindowsProtectedJournalProviderTests(unittest.TestCase):
    def setUp(self):
        if os.name != "nt":
            self.skipTest("native Windows evidence only")
        self.temporary = tempfile.TemporaryDirectory()
        self.run = Path(self.temporary.name) / "oncam-checkout-native-journal"
        self.run.mkdir()

    def tearDown(self):
        if hasattr(self, "temporary"):
            self.temporary.cleanup()

    def journal(self, kind="one-shot-ledger"):
        leaf = ".checkout-one-shot-ledger.journal" if kind == "one-shot-ledger" \
            else ".checkout-revocation-high-water.journal"
        path = self.run / leaf
        path.touch(exist_ok=False)
        return path

    def apply(self, value):
        request_raw = request_codec.canonical_request(value)
        return provider.apply_storage(request_raw)

    def test_bootstrap_and_exact_one_shot_compare_and_swap_use_native_flush_protocol(self):
        journal = self.journal()
        first = request_value(self.run, journal)
        result = self.apply(first)
        self.assertIs(result["nativeStorageOnly"], True)
        self.assertEqual(tuple(result), (
            "nativeStorageOnly", "afterGeneration", "afterStateDigest",
            "beforeGeneration", "beforeStateDigest", "journalIdentityDigest",
            "journalKind", "namespace", "requestDigest",
            "finalHandleClosedBeforeReturn", "storageEvidenceDigest",
        ))
        self.assertIs(result["finalHandleClosedBeforeReturn"], True)
        self.assertEqual((result["beforeGeneration"], result["afterGeneration"]), (0, 1))
        for forbidden in ("descriptorStable", "tokenStable", "status",
                          "evidenceStructuralOnly", "persisted", "trusted"):
            self.assertNotIn(forbidden, result)
        with self.assertRaises(TypeError):
            result["persisted"] = True
        self.assertGreater(journal.stat().st_size, 0)

        second = request_value(self.run, journal, current=1)
        second["currentState"]["rawDigest"] = first["proposedState"]["rawDigest"]
        second["proposedState"]["previousDigest"] = first["proposedState"]["rawDigest"]
        result = self.apply(second)
        self.assertEqual((result["beforeGeneration"], result["afterGeneration"]), (1, 2))

    def test_revocation_high_water_supports_strict_forward_jump(self):
        journal = self.journal("revocation-high-water")
        first = request_value(self.run, journal, kind="revocation-high-water")
        self.assertEqual(self.apply(first)["afterGeneration"], 1)
        later = request_value(
            self.run, journal, kind="revocation-high-water", current=1, proposed=4
        )
        later["currentState"]["rawDigest"] = first["proposedState"]["rawDigest"]
        later["proposedState"]["previousDigest"] = first["proposedState"]["rawDigest"]
        self.assertEqual(self.apply(later)["afterGeneration"], 4)

    def test_stale_replay_and_conflicting_writer_refuse_without_mutation(self):
        journal = self.journal()
        first = request_value(self.run, journal)
        self.apply(first)
        before = journal.read_bytes()
        with self.assertRaises(provider.WindowsProtectedJournalProviderRefused):
            self.apply(first)
        self.assertEqual(journal.read_bytes(), before)

        current = request_value(self.run, journal, current=1)
        current["currentState"]["rawDigest"] = first["proposedState"]["rawDigest"]
        current["proposedState"]["previousDigest"] = first["proposedState"]["rawDigest"]
        handle = provider._open_exclusive_for_test(canonical_path(journal))
        try:
            with self.assertRaises(provider.WindowsProtectedJournalProviderRefused):
                self.apply(current)
        finally:
            provider._close_for_test(handle)
        self.assertEqual(journal.read_bytes(), before)

    def test_partial_corrupt_and_ambiguous_records_refuse_without_auto_repair(self):
        header = canonical({
            "format": "oncam.checkout.protected-journal.v1",
            "journalKind": "one-shot-ledger",
            "namespace": "composition-admission",
        })
        intent = canonical({
            "generation": 1,
            "previousDigest": ZERO_DIGEST,
            "rawDigest": format(41, "064x"),
            "requestDigest": "9" * 64,
            "type": "intent",
        })
        mutations = (
            b'{"type":"intent"',
            b'{"format":"wrong"}\n',
            header,
            header + intent,
            header
            + canonical({"type": "commit", "generation": 1,
                         "intentDigest": "7" * 64}),
        )
        for index, data in enumerate(mutations):
            with self.subTest(index=index):
                journal = self.run / f"bad-{index}.journal"
                journal.write_bytes(data)
                value = request_value(self.run, journal)
                # The structural request requires the canonical journal leaf.
                canonical_journal = self.run / ".checkout-one-shot-ledger.journal"
                journal.rename(canonical_journal)
                value["expectedFileIdentity"] = identity(canonical_journal)
                original = canonical_journal.read_bytes()
                try:
                    with self.assertRaises(
                        provider.WindowsProtectedJournalProviderRefused
                    ):
                        self.apply(value)
                    self.assertEqual(canonical_journal.read_bytes(), original)
                finally:
                    canonical_journal.unlink(missing_ok=True)

        journal = self.journal()
        first = request_value(self.run, journal)
        self.apply(first)
        committed = journal.read_bytes()
        duplicate_commit = committed + committed.splitlines(keepends=True)[-1]
        journal.write_bytes(duplicate_commit)
        current = request_value(self.run, journal, current=1)
        current["currentState"]["rawDigest"] = first["proposedState"]["rawDigest"]
        current["proposedState"]["previousDigest"] = first["proposedState"]["rawDigest"]
        with self.assertRaises(provider.WindowsProtectedJournalProviderRefused):
            self.apply(current)
        self.assertEqual(journal.read_bytes(), duplicate_commit)

    def test_missing_existing_state_refuses_without_recreating_or_repairing(self):
        journal = self.journal()
        value = request_value(self.run, journal, current=1)
        expected_identity = copy.deepcopy(value["expectedFileIdentity"])
        journal.unlink()
        value["expectedFileIdentity"] = expected_identity
        request_raw = request_codec.canonical_request(value)
        with self.assertRaises(provider.WindowsProtectedJournalProviderRefused):
            provider.apply_storage(request_raw)
        self.assertFalse(journal.exists())

    def test_handle_is_exclusive_noninheritable_and_identity_is_revalidated(self):
        journal = self.journal()
        handle = provider._open_exclusive_for_test(canonical_path(journal))
        try:
            self.assertFalse(provider._handle_inheritable_for_test(handle))
            with self.assertRaisesRegex(
                provider.WindowsProtectedJournalProviderRefused,
                "^windows_protected_journal_provider$",
            ):
                provider._open_exclusive_for_test(canonical_path(journal))
        finally:
            provider._close_for_test(handle)

        value = request_value(self.run, journal)
        value["expectedFileIdentity"]["fileId"] = str(
            int(value["expectedFileIdentity"]["fileId"]) + 1
        )
        before = journal.read_bytes()
        with self.assertRaises(provider.WindowsProtectedJournalProviderRefused):
            provider.apply_storage(request_codec.canonical_request(value))
        self.assertEqual(journal.read_bytes(), before)

    def test_native_writes_use_write_through_and_flush_each_protocol_phase(self):
        journal = self.journal()
        original_create = provider._CreateFileW
        original_flush = provider._FlushFileBuffers
        create_flags = []
        flushes = []

        def tracked_create(*args):
            create_flags.append(args[5])
            return original_create(*args)

        def tracked_flush(handle):
            flushes.append(True)
            return original_flush(handle)

        try:
            provider._CreateFileW = tracked_create
            provider._FlushFileBuffers = tracked_flush
            provider._apply_storage_unbound(request_codec.canonical_request(
                request_value(self.run, journal)
            ))
        finally:
            provider._CreateFileW = original_create
            provider._FlushFileBuffers = original_flush
        self.assertGreaterEqual(len(create_flags), 3)
        self.assertTrue(any(
            flags & provider._FILE_FLAG_WRITE_THROUGH for flags in create_flags
        ))
        self.assertEqual(len(flushes), 3)

    def test_errors_are_fixed_redacted_and_scope_has_no_false_authority(self):
        with self.assertRaisesRegex(
            provider.WindowsProtectedJournalProviderRefused,
            "^windows_protected_journal_provider$",
        ) as raised:
            provider.apply_storage(b'{"secret":"do-not-leak"}\n')
        self.assertNotIn("secret", str(raised.exception))
        self.assertEqual(provider.__all__, (
            "WindowsProtectedJournalProviderRefused", "apply_storage",
        ))
        text = (provider.__doc__ or "").lower()
        for phrase in ("temporary", "does not", "dpapi", "reboot", "acl"):
            self.assertIn(phrase, text)
        for name in ("apply", "canonical_evidence", "evidence", "repair",
                     "rollback_protected", "trust", "admit"):
            self.assertFalse(hasattr(provider, name))

        journal = self.journal()
        outside = request_value(self.run, journal)
        outside_run = "C:/not-the-os-temp/oncam-checkout-a"
        outside["runIdentity"] = {
            "fileId": "101", "path": outside_run, "volumeSerial": "77",
        }
        outside["expectedFileIdentity"] = {
            "fileId": "102",
            "path": outside_run + "/.checkout-one-shot-ledger.journal",
            "volumeSerial": "77",
        }
        outside["forbiddenPathDigests"] = sorted([
            path_digest(outside_run + "/runtime.ini"),
            path_digest(outside_run + "/supervisor-config.json"),
            path_digest(outside_run + "/tls/server.key"),
        ])
        opens = []
        original_open = provider._open_handle
        try:
            provider._open_handle = lambda *_a, **_k: opens.append(True)
            with self.assertRaises(provider.WindowsProtectedJournalProviderRefused):
                provider.apply_storage(request_codec.canonical_request(outside))
            self.assertEqual(opens, [])
        finally:
            provider._open_handle = original_open

    def test_public_authority_drift_refuses_before_any_write(self):
        refusal = provider.WindowsProtectedJournalProviderRefused

        class HostileCodec:
            calls = 0

            def decode(self, _raw):
                self.calls += 1

        def hostile(*_args, **_kwargs):
            hostile.calls += 1
            return True

        hostile.calls = 0
        mutations = (
            ("_refuse", hostile),
            ("_REQUEST", HostileCodec()),
            ("_WriteFile", hostile),
            ("_FlushFileBuffers", hostile),
            ("MAX_JOURNAL_BYTES", provider.MAX_JOURNAL_BYTES + 1),
            ("WindowsProtectedJournalProviderRefused", RuntimeError),
            ("__all__", tuple(list(provider.__all__))),
            ("_apply_impl", hostile),
        )
        for index, (name, replacement) in enumerate(mutations):
            with self.subTest(name=name):
                journal = self.run / ".checkout-one-shot-ledger.journal"
                journal.touch(exist_ok=False)
                raw = request_codec.canonical_request(
                    request_value(self.run, journal)
                )
                original = getattr(provider, name)
                try:
                    setattr(provider, name, replacement)
                    with self.assertRaisesRegex(
                        refusal, "^windows_protected_journal_provider$"
                    ):
                        provider.apply_storage(raw)
                    self.assertEqual(journal.read_bytes(), b"")
                finally:
                    setattr(provider, name, original)
                    journal.unlink()
        self.assertEqual(hostile.calls, 0)

    def test_cleanup_preserves_primary_baseexception_and_attempts_every_close(self):
        original_close = provider._close
        attempted = []

        def failing_close(handle):
            attempted.append(handle)
            raise RuntimeError("close detail")

        try:
            provider._close = failing_close
            for primary in (KeyboardInterrupt("ki"), SystemExit("exit")):
                attempted.clear()

                def operation(primary=primary):
                    raise primary

                with self.assertRaises(type(primary)) as raised:
                    provider._invoke_cleanup_for_test(operation, (11, 12))
                self.assertIs(raised.exception, primary)
                self.assertEqual(attempted, [11, 12])

            attempted.clear()
            with self.assertRaisesRegex(RuntimeError, "^close detail$"):
                provider._invoke_cleanup_for_test(lambda: "ok", (21, 22))
            self.assertEqual(attempted, [21, 22])
        finally:
            provider._close = original_close


if __name__ == "__main__":
    unittest.main()
