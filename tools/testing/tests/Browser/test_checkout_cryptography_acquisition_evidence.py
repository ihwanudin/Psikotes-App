import copy
import hashlib
import importlib.util
import json
from pathlib import Path
from types import MappingProxyType
import unittest


MODULE_PATH = Path(__file__).with_name("checkout-cryptography-acquisition-evidence.py")
SPEC = importlib.util.spec_from_file_location("checkout_crypto_acquisition", MODULE_PATH)
evidence = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(evidence)


def canonical(value):
    return (json.dumps(value, sort_keys=True, separators=(",", ":"),
                       ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")


def runtime(category, index, path):
    return {
        "category": category, "digest": format(100 + index, "064x"),
        "fileId": str(200 + index), "path": path, "reparsePoint": False,
        "version": "46.0.1", "volumeSerial": "77",
    }


def fixture():
    return {
        "artifactId": "cryptography-acquisition-win64-01",
        "closureComplete": True,
        "cryptographyVersion": "46.0.1",
        "expiresAt": "2026-09-14T03:04:05Z",
        "generation": 3,
        "issuedAt": "2026-09-07T03:04:05Z",
        "issuerId": "dependency-custodian-01",
        "python": {
            "abiTag": "abi3", "architecture": "amd64",
            "digest": "1" * 64, "fileId": "100", "implementation": "cpython",
            "interpreterTag": "cp314", "platformTag": "win_amd64",
            "path": "C:/oncam/verifier/python/python.exe", "reparsePoint": False,
            "version": "3.14.0", "volumeSerial": "77",
        },
        "record": [
            {"digest": "3" * 64, "path": "cryptography-46.0.1.dist-info/RECORD", "size": 200},
            {"digest": "2" * 64, "path": "cryptography/__init__.py", "size": 100},
        ],
        "replayId": "cryptography-acquisition-win64-01-attempt-01",
        "role": "cryptography-acquisition-evidence",
        "runtime": [
            runtime("bundled-openssl", 1, "C:/oncam/verifier/cryptography/01-openssl.dll"),
            runtime("dll", 2, "C:/oncam/verifier/cryptography/02-runtime.dll"),
            runtime("loader-policy", 3, "C:/oncam/verifier/cryptography/03-loader.json"),
            runtime("native-extension", 4, "C:/oncam/verifier/cryptography/04-rust.pyd"),
        ],
        "version": 1,
        "wheel": {
            "abiTag": "abi3", "digest": "4" * 64,
            "filename": "cryptography-46.0.1-cp311-abi3-win_amd64.whl",
            "licenseDigest": "5" * 64, "platformTag": "win_amd64",
            "pythonTag": "cp311", "version": "46.0.1",
        },
    }


class CryptographyAcquisitionEvidenceTests(unittest.TestCase):
    def assert_refused(self, value):
        raw = canonical(value) if type(value) is dict else value
        with self.assertRaisesRegex(
            evidence.CryptographyAcquisitionEvidenceRefused,
            "^cryptography_acquisition_evidence$",
        ):
            evidence.decode(raw)

    def test_known_vector_is_canonical_immutable_and_structural_only(self):
        value = fixture(); raw = canonical(value)
        result = evidence.decode(raw)
        self.assertEqual(evidence.canonical_evidence(value), raw)
        self.assertIs(type(result), MappingProxyType)
        self.assertIs(result["structuralOnly"], True)
        self.assertIs(result["closureComplete"], True)
        self.assertEqual(result["recordCount"], 2)
        self.assertEqual(result["runtimeCount"], 4)
        self.assertEqual(result["artifactDigest"], hashlib.sha256(raw).hexdigest())
        with self.assertRaises(TypeError): result["accepted"] = True
        for field in ("accepted", "authenticated", "installed", "trusted"):
            self.assertNotIn(field, result)

    def test_top_schema_encoding_and_bounds_are_closed(self):
        value = fixture(); raw = canonical(value)
        for key in tuple(value):
            changed = copy.deepcopy(value); del changed[key]; self.assert_refused(changed)
        changed = fixture(); changed["privateKey"] = "secret"; self.assert_refused(changed)
        for invalid in (
            raw[:-1], raw + b"\n", b"\xef\xbb\xbf" + raw,
            json.dumps(value).encode("ascii"), b'{"version":1,"version":1}\n',
            b'{"generation":NaN}\n', "not-bytes", bytearray(raw),
            b"{" + b" " * evidence.MAX_EVIDENCE_BYTES + b"}\n",
        ):
            self.assert_refused(invalid)

    def test_identity_lifetime_and_closure_claim_are_exact(self):
        for key, bad in (
            ("version", True), ("role", "tool-runtime-closure"),
            ("generation", 0), ("artifactId", "Artifact"),
            ("issuerId", "../issuer"), ("replayId", "replay id"),
            ("closureComplete", False), ("closureComplete", 1),
            ("expiresAt", "2026-09-07T03:04:05Z"),
            ("expiresAt", "2026-09-14T03:04:06Z"),
        ):
            changed = fixture(); changed[key] = bad; self.assert_refused(changed)

    def test_python_executable_is_exact_canonical_identity(self):
        for key, bad in (
            ("path", "c:/python.exe"), ("path", "C:\\python.exe"),
            ("path", "C:/tools/../python.exe"), ("volumeSerial", "0"),
            ("fileId", True), ("digest", "A" * 64), ("version", ""),
            ("reparsePoint", True), ("reparsePoint", 0),
        ):
            changed = fixture(); changed["python"][key] = bad; self.assert_refused(changed)

    def test_python_implementation_interpreter_abi_platform_and_architecture_are_bound(self):
        for key, bad in (
            ("implementation", "cp"), ("implementation", "pypy"),
            ("interpreterTag", "cp311"), ("interpreterTag", "cp314d"),
            ("abiTag", "cp314"), ("platformTag", "win32"),
            ("architecture", "x86"), ("architecture", True),
        ):
            changed = fixture(); changed["python"][key] = bad
            with self.subTest(key=key, bad=bad): self.assert_refused(changed)

    def test_wheel_filename_tags_hash_license_and_version_are_exact(self):
        for key, bad in (
            ("filename", "cryptography.whl"), ("pythonTag", "py3"),
            ("abiTag", "ABI3"), ("platformTag", "linux_x86_64"),
            ("digest", "a" * 63), ("licenseDigest", True),
        ):
            changed = fixture(); changed["wheel"][key] = bad; self.assert_refused(changed)
        changed = fixture(); changed["wheel"]["version"] = "46.0.2"; self.assert_refused(changed)
        changed = fixture(); changed["cryptographyVersion"] = "46.0.2"; self.assert_refused(changed)
        changed = fixture(); changed["python"]["version"] = "3.10.9"; self.assert_refused(changed)

    def test_version_fields_are_bounded_before_regex_and_filename_work(self):
        huge = "9" * (evidence.MAX_VERSION_BYTES + 1)
        for parent, field in (("python", "version"), (None, "cryptographyVersion")):
            changed = fixture()
            if parent is None: changed[field] = huge
            else: changed[parent][field] = huge
            with self.subTest(parent=parent):
                with self.assertRaisesRegex(
                    evidence.CryptographyAcquisitionEvidenceRefused,
                    "^cryptography_acquisition_evidence$",
                ):
                    evidence.canonical_evidence(changed)

    def test_record_inventory_is_complete_sorted_unique_bounded_and_file_only(self):
        valid = fixture()["record"]
        for records in ([], list(reversed(valid)), valid + [copy.deepcopy(valid[0])]):
            changed = fixture(); changed["record"] = records; self.assert_refused(changed)
        for key, bad in (("path", "../escape"), ("path", "vendor/x"),
                         ("digest", "A" * 64), ("size", True), ("size", -1)):
            changed = fixture(); changed["record"][0][key] = bad; self.assert_refused(changed)
        changed = fixture(); changed["record"][0]["symlink"] = False; self.assert_refused(changed)

    def test_runtime_requires_native_openssl_dll_loader_and_complete_sorted_closure(self):
        valid = fixture()["runtime"]
        for resources in ([], valid[:-1], list(reversed(valid))):
            changed = fixture(); changed["runtime"] = resources; self.assert_refused(changed)
        changed = fixture(); changed["runtime"][0]["category"] = "resource"; self.assert_refused(changed)
        changed = fixture(); changed["runtime"][0]["reparsePoint"] = True; self.assert_refused(changed)

    def test_paths_and_object_identities_are_globally_collision_safe(self):
        changed = fixture(); changed["runtime"][1]["path"] = changed["runtime"][0]["path"].upper(); self.assert_refused(changed)
        changed = fixture(); changed["runtime"][1]["fileId"] = changed["runtime"][0]["fileId"]; self.assert_refused(changed)
        for bad in ("C:/oncam/CON.dll", "C:/oncam/CONIN$.dll", "C:/oncam/x.",
                    "C:/oncam/<x>.dll", "C:/oncam/résumé.dll"):
            changed = fixture(); changed["runtime"][0]["path"] = bad; changed["runtime"].sort(key=lambda x: x["path"].lower()); self.assert_refused(changed)

    def test_ambient_discovery_secrets_and_downstream_bindings_are_rejected(self):
        for key in ("sitePackages", "ambientFallback", "pipIndex", "password",
                    "signatureVerified", "compositionAdmissionDigest"):
            changed = fixture(); changed[key] = True; self.assert_refused(changed)

    def test_dependency_and_authority_mutation_fail_closed(self):
        raw = canonical(fixture()); refusal = evidence.CryptographyAcquisitionEvidenceRefused
        for owner, name, replacement in (
            (evidence, "RUNTIME_CATEGORIES", tuple(list(evidence.RUNTIME_CATEGORIES))),
            (evidence, "REQUIRED_CATEGORIES", frozenset()),
            (evidence, "MappingProxyType", dict), (evidence, "json", object()),
            (evidence, "hashlib", object()), (evidence, "re", object()),
            (evidence, "CryptographyAcquisitionEvidenceRefused", Exception),
            (evidence, "__all__", tuple(list(evidence.__all__))),
        ):
            original = getattr(owner, name)
            try:
                setattr(owner, name, replacement)
                with self.assertRaises(refusal): evidence.decode(raw)
            finally: setattr(owner, name, original)

    def test_baseexception_identity_is_preserved(self):
        invoke = next(cell.cell_contents for cell in evidence.decode.__closure__
                      if callable(cell.cell_contents)
                      and getattr(cell.cell_contents, "__name__", "") == "invoke")
        for primary in (KeyboardInterrupt("k"), SystemExit("s")):
            with self.assertRaises(type(primary)) as raised:
                invoke(lambda error=primary: (_ for _ in ()).throw(error))
            self.assertIs(raised.exception, primary)

    def test_public_surface_has_no_acquisition_install_or_authority_api(self):
        self.assertEqual(evidence.__all__, (
            "CryptographyAcquisitionEvidenceRefused", "canonical_evidence", "decode",
        ))
        text = (evidence.__doc__ or "").lower()
        for phrase in ("structural", "supplied", "does not", "filesystem"):
            self.assertIn(phrase, text)
        for name in ("accept", "acquire", "install", "discover", "verify", "admit"):
            self.assertFalse(hasattr(evidence, name))


if __name__ == "__main__": unittest.main()
