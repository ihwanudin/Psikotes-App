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


boundary = load(
    "checkout_windows_journal_stability_boundary",
    "checkout-windows-journal-stability-boundary.py",
)
request_codec = boundary._REQUEST
storage = boundary._STORAGE
attestor_tests = load(
    "checkout_windows_acl_attestor_harness_for_journal_stability",
    "test_checkout_windows_acl_attestor.py",
)


def path_digest(path):
    return hashlib.sha256(
        b"oncam.checkout.canonical-path.v1\0" + path.encode("ascii")
    ).hexdigest()


def request_value(run, journal):
    run_path = boundary._canonical_path_for_test(run)
    return {
        "currentState": {"generation": 0, "rawDigest": "0" * 64},
        "expectedFileIdentity": boundary._identity_for_test(journal),
        "forbiddenPathDigests": sorted([
            path_digest(run_path + "/runtime.ini"),
            path_digest(run_path + "/supervisor-config.json"),
            path_digest(run_path + "/tls/server.key"),
        ]),
        "journalKind": "one-shot-ledger",
        "namespace": "composition-admission",
        "operation": "compare-and-swap",
        "policyDigest": boundary._ACL.ACL_POLICY_DIGEST,
        "proposedState": {
            "generation": 1,
            "previousDigest": "0" * 64,
            "rawDigest": "4" * 64,
        },
        "providerAuthorityDigest": "5" * 64,
        "providerEvidenceDigest": "6" * 64,
        "providerGeneration": 7,
        "rebootState": "certain",
        "runIdentity": boundary._identity_for_test(run),
        "securityDescriptorDigest": "0" * 64,
        "version": 1,
    }


def current_user_sid():
    from ctypes import wintypes
    advapi = ctypes.WinDLL("advapi32.dll", use_last_error=True)
    kernel = ctypes.WinDLL("kernel32.dll", use_last_error=True)
    kernel.GetCurrentProcess.restype = wintypes.HANDLE
    kernel.CloseHandle.argtypes = (wintypes.HANDLE,)
    kernel.LocalFree.argtypes = (ctypes.c_void_p,)
    kernel.LocalFree.restype = ctypes.c_void_p
    advapi.OpenProcessToken.argtypes = (
        wintypes.HANDLE, wintypes.DWORD, ctypes.POINTER(wintypes.HANDLE),
    )
    advapi.GetTokenInformation.argtypes = (
        wintypes.HANDLE, ctypes.c_int, ctypes.c_void_p, wintypes.DWORD,
        ctypes.POINTER(wintypes.DWORD),
    )
    advapi.ConvertSidToStringSidW.argtypes = (
        ctypes.c_void_p, ctypes.POINTER(wintypes.LPWSTR),
    )
    token = wintypes.HANDLE()
    if not advapi.OpenProcessToken(kernel.GetCurrentProcess(), 8, ctypes.byref(token)):
        raise OSError("token")
    try:
        required = wintypes.DWORD()
        advapi.GetTokenInformation(token, 1, None, 0, ctypes.byref(required))
        if ctypes.get_last_error() != 122 or not 1 <= required.value <= 65536:
            raise OSError("token")
        buffer = ctypes.create_string_buffer(required.value)
        if not advapi.GetTokenInformation(
                token, 1, buffer, required.value, ctypes.byref(required)):
            raise OSError("token")
        sid = ctypes.c_void_p.from_buffer(buffer).value
        text = wintypes.LPWSTR()
        if not advapi.ConvertSidToStringSidW(sid, ctypes.byref(text)):
            raise OSError("sid")
        try:
            return text.value
        finally:
            kernel.LocalFree(text)
    finally:
        kernel.CloseHandle(token)


def protect_file(path):
    from ctypes import wintypes
    advapi = ctypes.WinDLL("advapi32.dll", use_last_error=True)
    kernel = ctypes.WinDLL("kernel32.dll", use_last_error=True)
    advapi.ConvertStringSecurityDescriptorToSecurityDescriptorW.argtypes = (
        wintypes.LPCWSTR, wintypes.DWORD, ctypes.POINTER(ctypes.c_void_p),
        ctypes.POINTER(wintypes.DWORD),
    )
    advapi.SetFileSecurityW.argtypes = (
        wintypes.LPCWSTR, wintypes.DWORD, ctypes.c_void_p,
    )
    kernel.LocalFree.argtypes = (ctypes.c_void_p,)
    kernel.LocalFree.restype = ctypes.c_void_p
    descriptor = ctypes.c_void_p()
    sid = current_user_sid()
    sddl = f"O:{sid}G:{sid}D:P(A;OICI;FA;;;{sid})"
    if not advapi.ConvertStringSecurityDescriptorToSecurityDescriptorW(
            sddl, 1, ctypes.byref(descriptor), None):
        raise OSError("descriptor")
    try:
        security_information = 1 | 2 | 4 | 0x80000000
        if not advapi.SetFileSecurityW(
                str(path), security_information, descriptor):
            raise OSError("security")
    finally:
        kernel.LocalFree(descriptor)


class WindowsJournalStabilityBoundaryTests(unittest.TestCase):
    def setUp(self):
        if os.name != "nt":
            self.skipTest("native Windows boundary only")
        self.temporary = tempfile.TemporaryDirectory()
        self.run = Path(self.temporary.name) / "oncam-checkout-journal-stability"
        self.run.mkdir()
        self.journal = self.run / ".checkout-one-shot-ledger.journal"
        self.journal.touch()
        protect_file(self.journal)

    def tearDown(self):
        if hasattr(self, "temporary"):
            self.temporary.cleanup()

    def raw(self):
        value = request_value(self.run, self.journal)
        probe = request_codec.canonical_request(value)
        value["securityDescriptorDigest"] = boundary._descriptor_digest_for_test(probe)
        return request_codec.canonical_request(value)

    def test_exact_native_before_after_snapshot_returns_narrow_immutable_observation(self):
        raw = self.raw()
        result = boundary.observe_stability(raw, storage.apply_storage)
        self.assertEqual(tuple(result), boundary.RESULT_FIELDS)
        self.assertIs(result["stabilityObservationOnly"], True)
        self.assertEqual(result["requestDigest"], hashlib.sha256(raw).hexdigest())
        self.assertEqual(result["securityDescriptorDigest"],
                         json.loads(raw)["securityDescriptorDigest"])
        request = json.loads(raw)
        self.assertEqual(result["providerAuthorityDigest"],
                         request["providerAuthorityDigest"])
        self.assertEqual(result["providerEvidenceDigest"],
                         request["providerEvidenceDigest"])
        self.assertEqual(result["providerGeneration"],
                         request["providerGeneration"])
        digest_input = dict(result)
        digest = digest_input.pop("stabilityObservationDigest")
        self.assertEqual(digest, hashlib.sha256(
            b"oncam.checkout.windows-journal-stability.v1\0"
            + (json.dumps(digest_input, sort_keys=True, separators=(",", ":"),
                          ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")
        ).hexdigest())
        for forbidden in ("descriptorStable", "tokenStable", "status",
                          "evidenceStructuralOnly", "accepted", "trusted"):
            self.assertNotIn(forbidden, result)
        with self.assertRaises(TypeError):
            result["accepted"] = True

    def test_fake_or_rebound_storage_callable_refuses_before_mutation(self):
        raw = self.raw()
        before = self.journal.read_bytes()
        calls = []
        with self.assertRaises(boundary.WindowsJournalStabilityRefused):
            boundary.observe_stability(raw, lambda _raw: calls.append(True))
        self.assertEqual(calls, [])
        self.assertEqual(self.journal.read_bytes(), before)

    def test_request_policy_descriptor_and_provider_bindings_are_exact(self):
        raw = self.raw()
        base = json.loads(raw)
        for key in ("policyDigest", "securityDescriptorDigest"):
            with self.subTest(key=key):
                changed = copy.deepcopy(base)
                changed[key] = "a" * 64
                with self.assertRaises(boundary.WindowsJournalStabilityRefused):
                    boundary.observe_stability(
                        request_codec.canonical_request(changed),
                        storage.apply_storage,
                    )

    def test_snapshot_drift_comparator_rejects_identity_descriptor_and_token(self):
        sample = ("identity", "owner", "descriptor", "token")
        for index in range(len(sample)):
            changed = list(sample)
            changed[index] += "-drift"
            with self.subTest(index=index), self.assertRaises(
                    boundary.WindowsJournalStabilityRefused):
                boundary._compare_snapshots_for_test(sample, tuple(changed))

    def test_aligned_extra_empty_restricted_sid_buffer_refuses_before_storage(self):
        harness = attestor_tests.NativeDirectoryHarness(boundary._ACL)
        harness.token_restricted_required_override = (
            boundary._ACL.TOKEN_GROUPS.Groups.offset
            + ctypes.alignment(boundary._ACL.SID_AND_ATTRIBUTES)
        )
        before = self.journal.read_bytes()
        with boundary._ACL._open_current_process_token(harness.bundle()) as token:
            with self.assertRaises(boundary._ACL.WindowsAclRefused):
                boundary._token_profile(token)
        self.assertEqual(self.journal.read_bytes(), before)

    def test_reparse_journal_refuses_without_storage(self):
        target = self.run / "target.journal"
        target.touch()
        self.journal.unlink()
        link = self.journal
        try:
            link.symlink_to(target)
        except OSError:
            self.skipTest("symlink privilege unavailable")
        value = request_value(self.run, target)
        value["expectedFileIdentity"]["path"] = (
            boundary._canonical_path_for_test(self.run)
            + "/.checkout-one-shot-ledger.journal"
        )
        raw = request_codec.canonical_request(value)
        before = target.read_bytes()
        with self.assertRaises(boundary.WindowsJournalStabilityRefused):
            boundary.observe_stability(raw, storage.apply_storage)
        self.assertEqual(target.read_bytes(), before)

    def test_authority_mutation_refuses_before_storage(self):
        raw = self.raw()
        before = self.journal.read_bytes()
        refusal = boundary.WindowsJournalStabilityRefused
        calls = []

        def hostile(*_args, **_kwargs):
            calls.append(True)

        mutations = (
            (boundary, "_capture", hostile),
            (boundary._ACL, "SE_DACL_PROTECTED",
             boundary._ACL.SE_DACL_PROTECTED ^ 1),
            (storage, "_WriteFile", hostile),
            (request_codec, "decode", hostile),
        )
        for owner, name, replacement in mutations:
            with self.subTest(owner=owner.__name__, name=name):
                original = getattr(owner, name)
                try:
                    setattr(owner, name, replacement)
                    with self.assertRaises(refusal):
                        boundary.observe_stability(raw, storage.apply_storage)
                    self.assertEqual(self.journal.read_bytes(), before)
                finally:
                    setattr(owner, name, original)
        self.assertEqual(calls, [])

    def test_error_redaction_baseexception_and_no_i18_surface(self):
        with self.assertRaisesRegex(
            boundary.WindowsJournalStabilityRefused,
            "^windows_journal_stability$",
        ):
            boundary.observe_stability(b'{"secret":"hidden"}\n', storage.apply_storage)
        for name in ("apply", "canonical_evidence", "evidence", "attest",
                     "descriptorStable", "tokenStable"):
            self.assertFalse(hasattr(boundary, name))
        self.assertEqual(boundary.__all__, (
            "WindowsJournalStabilityRefused", "observe_stability",
        ))
        for primary in (KeyboardInterrupt("ki"), SystemExit("exit")):
            original = boundary._ACL.SE_DACL_PROTECTED

            def interrupt_with_drift(primary=primary):
                boundary._ACL.SE_DACL_PROTECTED = original ^ 1
                raise primary

            try:
                with self.assertRaises(type(primary)) as raised:
                    boundary._invoke_for_test(interrupt_with_drift)
                self.assertIs(raised.exception, primary)
            finally:
                boundary._ACL.SE_DACL_PROTECTED = original


if __name__ == "__main__":
    unittest.main()
