import copy
import hashlib
import importlib.util
import json
from pathlib import Path
from types import MappingProxyType
import unittest


HERE = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location(
    "checkout_tls_material_package", HERE / "checkout-tls-material-package.py"
)
package = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(package)


EVIDENCE_DOMAIN = b"oncam.checkout.tls-material-evidence.v1\0"
PACKAGE_DOMAIN = b"oncam.checkout.tls-material-package.v1\0"


def canonical(value):
    return (json.dumps(value, sort_keys=True, separators=(",", ":"),
                       ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")


def evidence_fixture():
    value = {
        "version": 1,
        "role": "tls-material",
        "issuerId": "tls-evidence-custodian-01",
        "artifactId": "tls-material-20260908.1",
        "generation": 7,
        "issuedAt": "2026-09-08T02:03:04Z",
        "expiresAt": "2026-09-09T01:58:04Z",
        "replayId": "tls-material-20260908.1-attempt-01",
        "requestDigest": "1" * 64,
        "preparationBindingDigest": "2" * 64,
        "runtimeEvidenceDigest": "3" * 64,
        "runtimeConfigurationPolicyDigest": "4" * 64,
        "runIdentityDigest": "5" * 64,
        "leaseIdentityDigest": "6" * 64,
        "certificateSha256": "7" * 64,
        "spkiSha256": "8" * 64,
        "serialHex": "1a2b3c",
        "publicKeyAlgorithm": "rsa-3072",
        "signatureAlgorithm": "sha256WithRSAEncryption",
        "subjectDigest": "9" * 64,
        "sanPolicy": "psikotes-two-dns-v1",
        "notBefore": "2026-09-08T01:58:04Z",
        "notAfter": "2026-09-09T01:58:04Z",
        "certificatePolicyDigest": "a" * 64,
    }
    value["evidenceDigest"] = hashlib.sha256(
        EVIDENCE_DOMAIN + canonical(value)
    ).hexdigest()
    return value


def package_fixture(evidence_raw):
    evidence = json.loads(evidence_raw)
    value = {
        "version": 1,
        "schema": "checkout-tls-material-package",
        "packageId": "tls-package-20260908.1",
        "generation": evidence["generation"],
        "tlsArtifactId": evidence["artifactId"],
        "tlsReplayId": evidence["replayId"],
        "tlsArtifactDigest": hashlib.sha256(evidence_raw).hexdigest(),
        "tlsEvidenceDigest": evidence["evidenceDigest"],
        "runIdentityDigest": evidence["runIdentityDigest"],
        "leaseIdentityDigest": evidence["leaseIdentityDigest"],
        "evidenceDestination": {
            "content": "canonical-public-evidence",
            "disposition": "create-new",
            "relativePath": "tls/tls-material-evidence.json",
        },
        "profileDestination": {
            "disposable": True,
            "disposition": "create-new",
            "mode": "persistent-context",
            "relativePath": "browser-profile",
        },
    }
    value["packageDigest"] = hashlib.sha256(
        PACKAGE_DOMAIN + canonical(value)
    ).hexdigest()
    return value


class TlsMaterialPackageTests(unittest.TestCase):
    def setUp(self):
        self.evidence = canonical(evidence_fixture())

    def assert_refused(self, candidate, evidence_raw=None):
        raw = canonical(candidate) if type(candidate) is dict else candidate
        with self.assertRaisesRegex(
            package.TlsMaterialPackageRefused, "^tls_material_package$"
        ):
            package.decode(evidence_raw or self.evidence, raw)

    def test_package_is_canonical_immutable_narrow_and_structural_only(self):
        value = package_fixture(self.evidence)
        raw = canonical(value)
        result = package.decode(self.evidence, raw)

        self.assertEqual(package.canonical_package(self.evidence, value), raw)
        self.assertIs(type(result), MappingProxyType)
        self.assertIs(result["structuralOnly"], True)
        self.assertEqual(result["tlsArtifactDigest"], hashlib.sha256(self.evidence).hexdigest())
        self.assertEqual(result["tlsEvidenceDigest"], evidence_fixture()["evidenceDigest"])
        self.assertEqual(result["evidenceRelativePath"], "tls/tls-material-evidence.json")
        self.assertEqual(result["profileRelativePath"], "browser-profile")
        self.assertIs(result["profileDisposable"], True)
        with self.assertRaises(TypeError):
            result["packaged"] = True
        for forbidden in ("authenticated", "launched", "privateKey", "trusted", "written"):
            self.assertNotIn(forbidden, result)

    def test_schema_and_canonical_bytes_are_exact_closed_and_bounded(self):
        value = package_fixture(self.evidence)
        for key in tuple(value):
            changed = copy.deepcopy(value)
            del changed[key]
            self.assert_refused(changed)
        raw = canonical(value)
        for bad in (
            raw[:-1], raw + b"\n", b"\xef\xbb\xbf" + raw,
            json.dumps(value).encode("ascii"), b'{"version":1,"version":1}\n',
            b'{"generation":NaN}\n', b"[]\n", b"null\n", "not-bytes",
            bytearray(raw), b"{" + b" " * (16 * 1024) + b"}\n",
        ):
            self.assert_refused(bad)

    def test_all_tls_identity_generation_and_replay_bindings_are_exact(self):
        value = package_fixture(self.evidence)
        mutations = (
            ("generation", 8), ("generation", 0), ("generation", True),
            ("tlsArtifactId", "tls-material-other"),
            ("tlsReplayId", "tls-material-other-attempt"),
            ("tlsArtifactDigest", "f" * 64),
            ("tlsEvidenceDigest", "f" * 64),
            ("runIdentityDigest", "f" * 64),
            ("leaseIdentityDigest", "f" * 64),
        )
        for key, bad in mutations:
            changed = copy.deepcopy(value)
            changed[key] = bad
            with self.subTest(key=key, bad=bad):
                self.assert_refused(changed)
        changed_evidence = bytearray(self.evidence)
        changed_evidence[-3] = ord("0") if changed_evidence[-3] != ord("0") else ord("1")
        self.assert_refused(value, bytes(changed_evidence))

    def test_destinations_are_fixed_relative_create_new_and_disposable(self):
        value = package_fixture(self.evidence)
        mutations = (
            ("evidenceDestination", "relativePath", "../tls/evidence.json"),
            ("evidenceDestination", "relativePath", "C:/tls/evidence.json"),
            ("evidenceDestination", "relativePath", "/tls/evidence.json"),
            ("evidenceDestination", "relativePath", "tls\\evidence.json"),
            ("evidenceDestination", "relativePath", "tls/private-key.pem"),
            ("evidenceDestination", "disposition", "replace"),
            ("evidenceDestination", "content", "certificate-and-key"),
            ("profileDestination", "relativePath", "../browser-profile"),
            ("profileDestination", "relativePath", "browser-profile/child"),
            ("profileDestination", "disposition", "reuse"),
            ("profileDestination", "disposable", False),
            ("profileDestination", "disposable", 1),
            ("profileDestination", "mode", "ephemeral-context"),
        )
        for section, key, bad in mutations:
            changed = copy.deepcopy(value)
            changed[section][key] = bad
            with self.subTest(section=section, key=key, bad=bad):
                self.assert_refused(changed)

    def test_secret_private_key_and_authority_claim_fields_are_rejected(self):
        value = package_fixture(self.evidence)
        for extra in (
            "authenticated", "certificateBytes", "credential", "launched",
            "password", "privateKeyBytes", "privateKeyHash", "privateKeyPath",
            "secret", "signatureVerified", "trusted", "written",
        ):
            changed = copy.deepcopy(value)
            changed[extra] = True
            with self.subTest(extra=extra):
                self.assert_refused(changed)
        for section in ("evidenceDestination", "profileDestination"):
            changed = copy.deepcopy(value)
            changed[section]["absolutePath"] = "C:/private"
            self.assert_refused(changed)

    def test_package_digest_is_domain_separated_exact_and_sensitive(self):
        value = package_fixture(self.evidence)
        predecessor = {key: item for key, item in value.items() if key != "packageDigest"}
        self.assertEqual(
            value["packageDigest"],
            hashlib.sha256(PACKAGE_DOMAIN + canonical(predecessor)).hexdigest(),
        )
        changed = copy.deepcopy(value)
        changed["packageId"] = "tls-package-20260908.2"
        self.assert_refused(changed)
        changed["packageDigest"] = hashlib.sha256(
            PACKAGE_DOMAIN + canonical({key: item for key, item in changed.items()
                                        if key != "packageDigest"})
        ).hexdigest()
        self.assertNotEqual(
            package.decode(self.evidence, canonical(value))["packageDigest"],
            package.decode(self.evidence, canonical(changed))["packageDigest"],
        )

    def test_nested_schemas_types_and_string_subclasses_are_rejected(self):
        value = package_fixture(self.evidence)
        for section in ("evidenceDestination", "profileDestination"):
            for key in tuple(value[section]):
                changed = copy.deepcopy(value)
                del changed[section][key]
                self.assert_refused(changed)
            changed = copy.deepcopy(value)
            changed[section]["extra"] = True
            self.assert_refused(changed)

        class StringSubclass(str):
            pass

        for key in (
            "schema", "packageId", "tlsArtifactId", "tlsReplayId",
            "tlsArtifactDigest", "tlsEvidenceDigest", "runIdentityDigest",
            "leaseIdentityDigest", "packageDigest",
        ):
            changed = copy.deepcopy(value)
            changed[key] = StringSubclass(changed[key])
            with self.assertRaises(package.TlsMaterialPackageRefused):
                package.canonical_package(self.evidence, changed)

    def test_invalid_tls_evidence_and_dependency_rebinding_fail_closed(self):
        value = package_fixture(self.evidence)
        invalid_evidence = self.evidence.replace(b'"role":"tls-material"', b'"role":"tls-xaterial"')
        self.assert_refused(value, invalid_evidence)
        refusal = package.TlsMaterialPackageRefused
        for owner, name, replacement in (
            (package, "SCHEMA", "other"),
            (package, "MAX_PACKAGE_BYTES", 1),
            (package, "TlsMaterialPackageRefused", Exception),
            (package, "MappingProxyType", dict),
            (package, "json", object()),
            (package, "hashlib", object()),
            (package, "_TLS", object()),
            (package, "__all__", tuple(list(package.__all__))),
        ):
            original = getattr(owner, name)
            try:
                setattr(owner, name, replacement)
                with self.assertRaises(refusal):
                    package.decode(self.evidence, canonical(value))
            finally:
                setattr(owner, name, original)

    def test_baseexception_identity_and_public_non_authorizing_surface(self):
        invoke = next(cell.cell_contents for cell in package.decode.__closure__
                      if callable(cell.cell_contents)
                      and getattr(cell.cell_contents, "__name__", "") == "invoke")
        original = package.SCHEMA
        for primary in (KeyboardInterrupt("k"), SystemExit("s")):
            def drift_then_stop(error=primary):
                package.SCHEMA = "drift"
                raise error
            try:
                with self.assertRaises(type(primary)) as raised:
                    invoke(drift_then_stop)
                self.assertIs(raised.exception, primary)
            finally:
                package.SCHEMA = original
        self.assertEqual(package.__all__, (
            "TlsMaterialPackageRefused", "canonical_package", "decode",
        ))
        for forbidden in (
            "authenticate", "consume", "launch", "materialize", "sign",
            "verify_signature", "write",
        ):
            self.assertFalse(hasattr(package, forbidden))
        text = (package.__doc__ or "").lower()
        for phrase in ("structural", "does not", "replay", "filesystem"):
            self.assertIn(phrase, text)


if __name__ == "__main__":
    unittest.main()
