import copy
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


request = load("checkout_tls_material_verification_request",
               "checkout-tls-material-verification-request.py")
fixtures = load("checkout_tls_material_envelope_v2_request_fixtures",
                "test_checkout_tls_material_envelope_v2.py")
v2 = load("checkout_tls_material_envelope_v2_for_request_test",
          "checkout-tls-material-envelope-v2.py")


def canonical(value):
    return (json.dumps(value, sort_keys=True, separators=(",", ":"),
                       ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")


def request_fixture(v2_result):
    return {
        "version": 1,
        "schema": "checkout-tls-material-verification-request",
        "requestId": "tls-verification-request-20260908.1",
        "role": "tls-material",
        "replayId": v2_result["replayId"],
        "generation": v2_result["generation"],
        "runIdentityDigest": v2_result["runIdentityDigest"],
        "leaseIdentityDigest": v2_result["leaseIdentityDigest"],
        "tlsEvidenceDigest": v2_result["tlsEvidenceDigest"],
        "tlsPackageDigest": v2_result["tlsPackageDigest"],
        "payloadDigest": v2_result["artifactDigest"],
        "envelopeDigest": v2_result["envelopeDigest"],
        "trustBundleDigest": "b" * 64,
        "trustGeneration": v2_result["trustGeneration"],
        "revocationSnapshotDigest": "c" * 64,
        "revocationGeneration": 11,
        "trustedTimeChallengeDigest": "d" * 64,
    }


class TlsMaterialVerificationRequestTests(unittest.TestCase):
    def setUp(self):
        self.evidence_raw = canonical(fixtures.evidence_fixture())
        self.package_raw = canonical(fixtures.package_fixture(self.evidence_raw))
        self.payload_raw = canonical(fixtures.payload_fixture(
            self.evidence_raw, self.package_raw
        ))
        self.envelope_raw = canonical(fixtures.envelope_fixture(self.payload_raw))
        self.v2_result = v2.decode(
            self.evidence_raw, self.package_raw, self.payload_raw, self.envelope_raw
        )
        self.document = request_fixture(self.v2_result)

    def decode(self, document=None, **sources):
        value = self.document if document is None else document
        raw = canonical(value) if type(value) is dict else value
        return request.decode(
            sources.get("evidence_raw", self.evidence_raw),
            sources.get("package_raw", self.package_raw),
            sources.get("payload_raw", self.payload_raw),
            sources.get("envelope_raw", self.envelope_raw),
            raw,
        )

    def assert_refused(self, document=None, **sources):
        with self.assertRaisesRegex(
            request.TlsMaterialVerificationRequestRefused,
            "^tls_material_verification_request$",
        ):
            self.decode(document, **sources)

    def test_request_is_canonical_immutable_narrow_and_structural_only(self):
        raw = canonical(self.document)
        result = self.decode()
        self.assertEqual(request.canonical_request(
            self.evidence_raw, self.package_raw, self.payload_raw,
            self.envelope_raw, self.document,
        ), raw)
        self.assertIs(type(result), MappingProxyType)
        self.assertIs(result["structuralOnly"], True)
        self.assertEqual(result["role"], "tls-material")
        self.assertEqual(result["payloadDigest"], self.v2_result["artifactDigest"])
        self.assertEqual(result["envelopeDigest"], self.v2_result["envelopeDigest"])
        self.assertEqual(result["tlsEvidenceDigest"], self.v2_result["tlsEvidenceDigest"])
        self.assertEqual(result["tlsPackageDigest"], self.v2_result["tlsPackageDigest"])
        with self.assertRaises(TypeError):
            result["verified"] = True
        for forbidden in ("authenticated", "fresh", "policySatisfied",
                          "replayConsumed", "signatureVerified", "trusted"):
            self.assertNotIn(forbidden, result)

    def test_schema_is_closed_canonical_ascii_and_bounded(self):
        for key in tuple(self.document):
            changed = copy.deepcopy(self.document)
            del changed[key]
            with self.subTest(missing=key):
                self.assert_refused(changed)
        changed = copy.deepcopy(self.document)
        changed["extra"] = True
        self.assert_refused(changed)
        raw = canonical(self.document)
        for bad in (
            raw[:-1], raw + b"\n", b"\xef\xbb\xbf" + raw,
            json.dumps(self.document).encode("ascii"),
            b'{"version":1,"version":1}\n', b'{"generation":NaN}\n',
            b"[]\n", b"null\n", "not-bytes", bytearray(raw),
            b"{" + b" " * (16 * 1024) + b"}\n",
        ):
            with self.subTest(bad=repr(bad)[:48]):
                self.assert_refused(bad)

    def test_exact_v2_payload_envelope_evidence_and_package_bindings_are_required(self):
        for key in (
            "tlsEvidenceDigest", "tlsPackageDigest", "payloadDigest",
            "envelopeDigest", "runIdentityDigest", "leaseIdentityDigest",
        ):
            changed = copy.deepcopy(self.document)
            changed[key] = "f" * 64
            with self.subTest(key=key):
                self.assert_refused(changed)
        for key, bad in (("generation", 8), ("generation", 0),
                         ("generation", True)):
            changed = copy.deepcopy(self.document)
            changed[key] = bad
            self.assert_refused(changed)

        changed_envelope = bytearray(self.envelope_raw)
        changed_envelope[-3] = ord("0") if changed_envelope[-3] != ord("0") else ord("1")
        self.assert_refused(envelope_raw=bytes(changed_envelope))

    def test_trust_revocation_and_trusted_time_inputs_are_strict_bindings_only(self):
        for key, bad in (
            ("trustBundleDigest", "A" * 64),
            ("trustBundleDigest", "b" * 63),
            ("trustGeneration", self.v2_result["trustGeneration"] + 1),
            ("trustGeneration", True),
            ("revocationSnapshotDigest", "c" * 63),
            ("revocationSnapshotDigest", "C" * 64),
            ("revocationGeneration", 0),
            ("revocationGeneration", True),
            ("trustedTimeChallengeDigest", "d" * 63),
            ("trustedTimeChallengeDigest", "D" * 64),
        ):
            changed = copy.deepcopy(self.document)
            changed[key] = bad
            with self.subTest(key=key, bad=bad):
                self.assert_refused(changed)
        result = self.decode()
        for claim in ("current", "fresh", "notRevoked", "trusted"):
            self.assertNotIn(claim, result)

    def test_role_version_request_and_one_shot_replay_identity_are_exact(self):
        for key, bad in (
            ("version", 2), ("version", True), ("schema", "verifier-request"),
            ("role", "release-source"), ("role", "tls-authority"),
            ("requestId", "../request"), ("requestId", "Request-01"),
            ("replayId", "tls-material-other-attempt"),
            ("replayId", "../replay"),
        ):
            changed = copy.deepcopy(self.document)
            changed[key] = bad
            with self.subTest(key=key, bad=bad):
                self.assert_refused(changed)

    def test_supplied_verification_authority_and_secret_fields_are_rejected(self):
        for extra in (
            "authenticated", "certificateBytes", "credential", "fresh",
            "path", "policySatisfied", "privateKey", "privateKeyBytes",
            "replayConsumed", "secret", "signatureVerified", "trusted",
            "verified",
        ):
            changed = copy.deepcopy(self.document)
            changed[extra] = True
            with self.subTest(extra=extra):
                self.assert_refused(changed)

    def test_replay_and_source_drift_fail_without_consumption_api(self):
        changed_payload = copy.deepcopy(fixtures.payload_fixture(
            self.evidence_raw, self.package_raw
        ))
        changed_payload["replayId"] = "tls-material-drift-attempt"
        self.assert_refused(payload_raw=canonical(changed_payload))
        self.assertFalse(hasattr(request, "consume"))
        self.assertFalse(hasattr(request, "mark_replayed"))

    def test_dependency_and_module_rebinding_fail_closed(self):
        refusal = request.TlsMaterialVerificationRequestRefused
        for name, replacement in (
            ("ROLE", "release-source"), ("SCHEMA", "other"),
            ("MAX_REQUEST_BYTES", 1),
            ("TlsMaterialVerificationRequestRefused", Exception),
            ("MappingProxyType", dict), ("json", object()),
            ("hashlib", object()), ("_V2", object()),
            ("__all__", tuple(list(request.__all__))),
        ):
            original = getattr(request, name)
            try:
                setattr(request, name, replacement)
                with self.assertRaises(refusal):
                    self.decode()
            finally:
                setattr(request, name, original)

    def test_baseexception_identity_and_public_surface_are_non_authorizing(self):
        invoke = next(cell.cell_contents for cell in request.decode.__closure__
                      if callable(cell.cell_contents)
                      and getattr(cell.cell_contents, "__name__", "") == "invoke")
        original = request.ROLE
        for primary in (KeyboardInterrupt("k"), SystemExit("s")):
            def drift_then_stop(error=primary):
                request.ROLE = "drift"
                raise error
            try:
                with self.assertRaises(type(primary)) as raised:
                    invoke(drift_then_stop)
                self.assertIs(raised.exception, primary)
            finally:
                request.ROLE = original
        self.assertEqual(request.__all__, (
            "TlsMaterialVerificationRequestRefused", "canonical_request", "decode",
        ))
        for forbidden in ("authenticate", "consume", "verify", "verify_signature"):
            self.assertFalse(hasattr(request, forbidden))
        text = (request.__doc__ or "").lower()
        for phrase in ("structural", "does not", "replay", "filesystem"):
            self.assertIn(phrase, text)


if __name__ == "__main__":
    unittest.main()
