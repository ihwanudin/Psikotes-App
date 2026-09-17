import copy
import hashlib
import importlib.util
import json
from pathlib import Path
from types import MappingProxyType
import unittest


HERE = Path(__file__).resolve().parent


def load(name, filename):
    spec = importlib.util.spec_from_file_location(name, HERE / filename)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


envelope = load("checkout_tls_material_envelope_v2", "checkout-tls-material-envelope-v2.py")
envelope_v1 = load("checkout_preparation_artifact_envelope_for_tls_v2_test",
                   "checkout-preparation-artifact-envelope.py")

EVIDENCE_DOMAIN = b"oncam.checkout.tls-material-evidence.v1\0"
PACKAGE_DOMAIN = b"oncam.checkout.tls-material-package.v1\0"
SIGNING_DOMAIN = b"oncam.checkout.tls-material.v1\0"


def canonical(value):
    return (json.dumps(value, sort_keys=True, separators=(",", ":"),
                       ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")


def evidence_fixture():
    value = {
        "version": 1, "role": "tls-material",
        "issuerId": "tls-evidence-custodian-01",
        "artifactId": "tls-material-20260908.1", "generation": 7,
        "issuedAt": "2026-09-08T02:03:04Z",
        "expiresAt": "2026-09-09T01:58:04Z",
        "replayId": "tls-material-20260908.1-attempt-01",
        "requestDigest": "1" * 64, "preparationBindingDigest": "2" * 64,
        "runtimeEvidenceDigest": "3" * 64,
        "runtimeConfigurationPolicyDigest": "4" * 64,
        "runIdentityDigest": "5" * 64, "leaseIdentityDigest": "6" * 64,
        "certificateSha256": "7" * 64, "spkiSha256": "8" * 64,
        "serialHex": "1a2b3c", "publicKeyAlgorithm": "rsa-3072",
        "signatureAlgorithm": "sha256WithRSAEncryption",
        "subjectDigest": "9" * 64, "sanPolicy": "psikotes-two-dns-v1",
        "notBefore": "2026-09-08T01:58:04Z",
        "notAfter": "2026-09-09T01:58:04Z",
        "certificatePolicyDigest": "a" * 64,
    }
    value["evidenceDigest"] = hashlib.sha256(
        EVIDENCE_DOMAIN + canonical(value)
    ).hexdigest()
    return value


def package_fixture(evidence_raw):
    evidence_value = json.loads(evidence_raw)
    value = {
        "version": 1, "schema": "checkout-tls-material-package",
        "packageId": "tls-package-20260908.1",
        "generation": evidence_value["generation"],
        "tlsArtifactId": evidence_value["artifactId"],
        "tlsReplayId": evidence_value["replayId"],
        "tlsArtifactDigest": hashlib.sha256(evidence_raw).hexdigest(),
        "tlsEvidenceDigest": evidence_value["evidenceDigest"],
        "runIdentityDigest": evidence_value["runIdentityDigest"],
        "leaseIdentityDigest": evidence_value["leaseIdentityDigest"],
        "evidenceDestination": {
            "content": "canonical-public-evidence", "disposition": "create-new",
            "relativePath": "tls/tls-material-evidence.json",
        },
        "profileDestination": {
            "disposable": True, "disposition": "create-new",
            "mode": "persistent-context", "relativePath": "browser-profile",
        },
    }
    value["packageDigest"] = hashlib.sha256(
        PACKAGE_DOMAIN + canonical(value)
    ).hexdigest()
    return value


def payload_fixture(evidence_raw, package_raw):
    evidence_value = json.loads(evidence_raw)
    package_value = json.loads(package_raw)
    return {
        "version": 2, "role": "tls-material",
        "artifactId": evidence_value["artifactId"],
        "generation": evidence_value["generation"],
        "replayId": evidence_value["replayId"],
        "runIdentityDigest": evidence_value["runIdentityDigest"],
        "leaseIdentityDigest": evidence_value["leaseIdentityDigest"],
        "tlsArtifactDigest": hashlib.sha256(evidence_raw).hexdigest(),
        "tlsEvidenceDigest": evidence_value["evidenceDigest"],
        "tlsPackageArtifactDigest": hashlib.sha256(package_raw).hexdigest(),
        "tlsPackageDigest": package_value["packageDigest"],
    }


def envelope_fixture(payload_raw):
    return {
        "version": 2, "algorithm": "ed25519", "role": "tls-material",
        "issuerId": "tls-evidence-signer-01", "keyId": "tls-signing-key-01",
        "keyGeneration": 3, "trustGeneration": 5,
        "artifactDigest": hashlib.sha256(payload_raw).hexdigest(),
        "signature": "7" * 128,
    }


class TlsMaterialEnvelopeV2Tests(unittest.TestCase):
    def setUp(self):
        self.evidence_raw = canonical(evidence_fixture())
        self.package_raw = canonical(package_fixture(self.evidence_raw))
        self.payload = payload_fixture(self.evidence_raw, self.package_raw)
        self.payload_raw = canonical(self.payload)
        self.document = envelope_fixture(self.payload_raw)

    def decode(self, payload=None, document=None):
        payload_raw = canonical(payload or self.payload)
        return envelope.decode(
            self.evidence_raw, self.package_raw, payload_raw,
            canonical(document or envelope_fixture(payload_raw)),
        )

    def assert_refused(self, payload=None, document=None, evidence_raw=None,
                       package_raw=None, payload_raw=None, envelope_raw=None):
        payload_value = self.payload if payload is None else payload
        payload_bytes = canonical(payload_value) if payload_raw is None else payload_raw
        document_value = self.document if document is None else document
        envelope_bytes = canonical(document_value) if envelope_raw is None else envelope_raw
        with self.assertRaisesRegex(
            envelope.TlsMaterialEnvelopeV2Refused, "^tls_material_envelope_v2$"
        ):
            envelope.decode(
                evidence_raw or self.evidence_raw,
                package_raw or self.package_raw,
                payload_bytes,
                envelope_bytes,
            )

    def test_known_boundary_is_canonical_immutable_narrow_and_structural_only(self):
        result = self.decode()
        self.assertEqual(
            envelope.canonical_payload(self.evidence_raw, self.package_raw, self.payload),
            self.payload_raw,
        )
        self.assertEqual(envelope.canonical_envelope(self.document), canonical(self.document))
        self.assertIs(type(result), MappingProxyType)
        self.assertIs(result["structuralOnly"], True)
        self.assertEqual(result["role"], "tls-material")
        self.assertEqual(result["tlsEvidenceDigest"], self.payload["tlsEvidenceDigest"])
        self.assertEqual(result["tlsPackageDigest"], self.payload["tlsPackageDigest"])
        self.assertEqual(
            result["signingMessageDigest"],
            hashlib.sha256(SIGNING_DOMAIN + self.payload_raw).hexdigest(),
        )
        with self.assertRaises(TypeError):
            result["trusted"] = True
        for forbidden in ("authenticated", "fresh", "replayAccepted",
                          "signature", "signatureVerified", "trusted"):
            self.assertNotIn(forbidden, result)

    def test_payload_and_envelope_are_closed_canonical_ascii_and_bounded(self):
        for value, canonicalizer in (
            (self.payload, lambda item: envelope.canonical_payload(
                self.evidence_raw, self.package_raw, item)),
            (self.document, envelope.canonical_envelope),
        ):
            for key in tuple(value):
                changed = copy.deepcopy(value)
                del changed[key]
                with self.assertRaises(envelope.TlsMaterialEnvelopeV2Refused):
                    canonicalizer(changed)
            changed = copy.deepcopy(value)
            changed["extra"] = True
            with self.assertRaises(envelope.TlsMaterialEnvelopeV2Refused):
                canonicalizer(changed)

        for target in ("payload", "envelope"):
            raw = self.payload_raw if target == "payload" else canonical(self.document)
            for bad in (raw[:-1], raw + b"\n", b"\xef\xbb\xbf" + raw,
                        b'{"version":2,"version":2}\n', b'{"version":NaN}\n',
                        b"[]\n", "not-bytes", bytearray(raw),
                        b"{" + b" " * (16 * 1024) + b"}\n"):
                with self.subTest(target=target, bad=repr(bad)[:40]):
                    if target == "payload":
                        self.assert_refused(payload_raw=bad)
                    else:
                        self.assert_refused(envelope_raw=bad)

    def test_payload_binds_exact_evidence_package_identity_generation_and_replay(self):
        for key, bad in (
            ("artifactId", "tls-material-other"), ("generation", 8),
            ("generation", True), ("replayId", "tls-material-other-attempt"),
            ("runIdentityDigest", "f" * 64), ("leaseIdentityDigest", "f" * 64),
            ("tlsArtifactDigest", "f" * 64), ("tlsEvidenceDigest", "f" * 64),
            ("tlsPackageArtifactDigest", "f" * 64),
            ("tlsPackageDigest", "f" * 64),
        ):
            changed = copy.deepcopy(self.payload)
            changed[key] = bad
            with self.subTest(key=key):
                self.assert_refused(payload=changed,
                                    document=envelope_fixture(canonical(changed)))

        changed_package = bytearray(self.package_raw)
        changed_package[-3] = ord("0") if changed_package[-3] != ord("0") else ord("1")
        self.assert_refused(package_raw=bytes(changed_package))

    def test_role_and_version_cannot_be_confused_or_promoted_from_v1(self):
        for target in ("payload", "envelope"):
            for key, bad in (("role", "release-source"), ("role", "tls-authority"),
                             ("version", 1), ("version", 3)):
                changed_payload = copy.deepcopy(self.payload)
                changed_document = copy.deepcopy(self.document)
                changed = changed_payload if target == "payload" else changed_document
                changed[key] = bad
                with self.subTest(target=target, key=key, bad=bad):
                    self.assert_refused(payload=changed_payload,
                                        document=changed_document)

        v1_document = {
            "version": 1, "algorithm": "ed25519", "role": "tls-material",
            "issuerId": "tls-evidence-signer-01", "keyId": "tls-signing-key-01",
            "keyGeneration": 3, "trustGeneration": 5,
            "artifactDigest": hashlib.sha256(self.payload_raw).hexdigest(),
            "signature": "7" * 128,
        }
        with self.assertRaises(envelope_v1.PreparationArtifactEnvelopeRefused):
            envelope_v1.canonical_envelope(v1_document)
        with self.assertRaises(envelope_v1.PreparationArtifactEnvelopeRefused):
            envelope_v1.decode(canonical(v1_document), self.payload_raw,
                               expected_role="tls-material")

    def test_detached_ed25519_fields_are_shape_only_not_trust(self):
        changed = copy.deepcopy(self.document)
        changed["signature"] = "8" * 128
        first = self.decode()
        second = self.decode(document=changed)
        self.assertNotEqual(first["envelopeDigest"], second["envelopeDigest"])
        self.assertEqual(first["signingMessageDigest"], second["signingMessageDigest"])
        for key, bad in (
            ("algorithm", "Ed25519"), ("algorithm", "rsa"),
            ("signature", "A" * 128), ("signature", "7" * 126),
            ("artifactDigest", "f" * 64), ("keyGeneration", 0),
            ("trustGeneration", True), ("issuerId", "../issuer"),
        ):
            invalid = copy.deepcopy(self.document)
            invalid[key] = bad
            self.assert_refused(document=invalid)

    def test_secret_path_private_key_and_authority_claims_are_rejected(self):
        forbidden = (
            "authenticated", "certificateBytes", "expiresAt", "fresh",
            "path", "privateKey", "privateKeyBytes", "privateKeyPath",
            "publicKey", "replayAccepted", "secret", "signatureVerified",
            "trusted",
        )
        for target in ("payload", "envelope"):
            for extra in forbidden:
                changed_payload = copy.deepcopy(self.payload)
                changed_document = copy.deepcopy(self.document)
                changed = changed_payload if target == "payload" else changed_document
                changed[extra] = True
                with self.subTest(target=target, extra=extra):
                    self.assert_refused(payload=changed_payload,
                                        document=changed_document)

    def test_artifact_digest_and_exact_domain_are_deterministic_and_sensitive(self):
        result = self.decode()
        self.assertEqual(result["artifactDigest"], hashlib.sha256(self.payload_raw).hexdigest())
        self.assertEqual(
            result["signingMessageDigest"],
            hashlib.sha256(SIGNING_DOMAIN + self.payload_raw).hexdigest(),
        )
        promoted_domain = b"oncam.checkout.tls-material.v2\0"
        self.assertNotEqual(
            result["signingMessageDigest"],
            hashlib.sha256(promoted_domain + self.payload_raw).hexdigest(),
        )

    def test_dependency_and_module_rebinding_fail_closed(self):
        refusal = envelope.TlsMaterialEnvelopeV2Refused
        for name, replacement in (
            ("ROLE", "release-source"), ("PAYLOAD_VERSION", 1),
            ("SIGNING_DOMAIN", b"other\0"), ("MAX_PAYLOAD_BYTES", 1),
            ("TlsMaterialEnvelopeV2Refused", Exception),
            ("MappingProxyType", dict), ("json", object()),
            ("hashlib", object()), ("_EVIDENCE", object()),
            ("_PACKAGE", object()), ("__all__", tuple(list(envelope.__all__))),
        ):
            original = getattr(envelope, name)
            try:
                setattr(envelope, name, replacement)
                with self.assertRaises(refusal):
                    self.decode()
            finally:
                setattr(envelope, name, original)

    def test_baseexception_identity_and_non_authorizing_public_surface(self):
        invoke = next(cell.cell_contents for cell in envelope.decode.__closure__
                      if callable(cell.cell_contents)
                      and getattr(cell.cell_contents, "__name__", "") == "invoke")
        original = envelope.ROLE
        for primary in (KeyboardInterrupt("k"), SystemExit("s")):
            def drift_then_stop(error=primary):
                envelope.ROLE = "drift"
                raise error
            try:
                with self.assertRaises(type(primary)) as raised:
                    invoke(drift_then_stop)
                self.assertIs(raised.exception, primary)
            finally:
                envelope.ROLE = original
        self.assertEqual(envelope.__all__, (
            "TlsMaterialEnvelopeV2Refused", "canonical_payload",
            "canonical_envelope", "decode",
        ))
        for forbidden in ("authenticate", "consume", "sign", "verify",
                          "verify_signature", "write"):
            self.assertFalse(hasattr(envelope, forbidden))
        text = (envelope.__doc__ or "").lower()
        for phrase in ("structural", "does not", "replay", "filesystem"):
            self.assertIn(phrase, text)


if __name__ == "__main__":
    unittest.main()
