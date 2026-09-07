import copy
import hashlib
import importlib.util
import json
from pathlib import Path
from types import MappingProxyType
import unittest


MODULE_PATH = Path(__file__).with_name("checkout-trust-bootstrap-evidence.py")
SPEC = importlib.util.spec_from_file_location("checkout_trust_bootstrap_evidence", MODULE_PATH)
codec = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(codec)

MAX_EVIDENCE_BYTES = 32 * 1024
MAX_AUTHORITY_IDS = 32
CHANNELS = (
    "offline-paper-record",
    "offline-read-only-media",
    "offline-secure-display",
)
RESULT_FIELDS = (
    "evidenceStructuralOnly", "bundleDigest", "bundleGeneration",
    "verifierDigest", "verifierGeneration", "acquisitionEvidenceDigest",
    "acquisitionEvidenceGeneration", "expectedFingerprint",
    "ceremonyStartedAt", "ceremonyEndedAt", "observationCount",
    "evidenceDigest",
)


def canonical(value):
    return (json.dumps(value, sort_keys=True, separators=(",", ":"),
                       ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")


def fixture():
    return {
        "acquisitionEvidenceDigest": "1" * 64,
        "acquisitionEvidenceGeneration": 3,
        "bundleDigest": "2" * 64,
        "bundleGeneration": 8,
        "custodianIds": ["root-custodian-01", "root-custodian-02"],
        "expectedFingerprint": "3" * 64,
        "fingerprintAlgorithm": "sha256",
        "observations": [
            {
                "channelId": "offline-paper-record",
                "fingerprint": "3" * 64,
                "observedAt": "2026-09-07T08:00:00Z",
                "operatorId": "bootstrap-operator-01",
                "sourceCopyDigest": "4" * 64,
                "sourceCopyId": "bootstrap-copy-01",
                "sourceCopyProvenanceDigest": "7" * 64,
            },
            {
                "channelId": "offline-read-only-media",
                "fingerprint": "3" * 64,
                "observedAt": "2026-09-07T08:30:00Z",
                "operatorId": "bootstrap-operator-02",
                "sourceCopyDigest": "5" * 64,
                "sourceCopyId": "bootstrap-copy-02",
                "sourceCopyProvenanceDigest": "8" * 64,
            },
        ],
        "roleIssuerIds": ["asset-issuer-01", "release-issuer-01"],
        "verifierDigest": "6" * 64,
        "verifierGeneration": 4,
        "version": 1,
    }


class TrustBootstrapEvidenceTests(unittest.TestCase):
    def decode(self, value):
        return codec.decode(canonical(value) if type(value) is dict else value)

    def assert_refused(self, value):
        with self.assertRaisesRegex(codec.TrustBootstrapEvidenceRefused,
                                    "^trust_bootstrap_evidence$"):
            self.decode(value)

    def test_known_vector_is_canonical_immutable_and_structural_only(self):
        value = fixture(); raw = canonical(value); result = self.decode(value)
        self.assertEqual(codec.canonical_evidence(value), raw)
        self.assertEqual(codec.__all__, (
            "TrustBootstrapEvidenceRefused", "canonical_evidence", "decode",
        ))
        self.assertIs(type(result), MappingProxyType)
        self.assertEqual(tuple(result), RESULT_FIELDS)
        self.assertIs(result["evidenceStructuralOnly"], True)
        self.assertEqual(result["observationCount"], 2)
        self.assertEqual(
            result["evidenceDigest"],
            "ad24fea23bb6524a63e32eebfb4433c6490cd770196c50d47f7bcaba81ab4b6d",
        )
        with self.assertRaises(TypeError):
            result["trusted"] = True
        for forbidden in ("accepted", "authenticated", "bootstrapAccepted", "trusted"):
            self.assertNotIn(forbidden, result)

    def test_schema_encoding_and_size_are_exact_closed_and_canonical(self):
        value = fixture(); raw = canonical(value)
        for key in tuple(value):
            changed = copy.deepcopy(value); del changed[key]; self.assert_refused(changed)
        for key in ("contact", "credential", "email", "operatorName", "path",
                    "phone", "privateKey", "secret", "signature"):
            changed = copy.deepcopy(value); changed[key] = "forbidden"
            self.assert_refused(changed)
        for candidate in (
            raw[:-1], raw + b"\n", b"\xef\xbb\xbf" + raw,
            json.dumps(value).encode("ascii"), b'{"version":1,"version":1}\n',
            b'{"version":NaN}\n', b"[]\n", "not-bytes", bytearray(raw),
            b"{" + b" " * MAX_EVIDENCE_BYTES + b"}\n",
        ):
            self.assert_refused(candidate)

    def test_bundle_verifier_and_acquisition_bindings_are_exact(self):
        for key in ("bundleDigest", "verifierDigest", "acquisitionEvidenceDigest"):
            for bad in ("A" * 64, "0" * 63, True, None):
                changed = fixture(); changed[key] = bad; self.assert_refused(changed)
        for key in ("bundleGeneration", "verifierGeneration",
                    "acquisitionEvidenceGeneration"):
            for bad in (0, True, 1 << 63):
                changed = fixture(); changed[key] = bad; self.assert_refused(changed)

    def test_fingerprint_algorithm_shape_and_exact_equality_are_required(self):
        for key, bad in (
            ("fingerprintAlgorithm", "SHA256"),
            ("fingerprintAlgorithm", "sha512"),
            ("expectedFingerprint", "A" * 64),
            ("expectedFingerprint", "0" * 63),
        ):
            changed = fixture(); changed[key] = bad; self.assert_refused(changed)
        for index in range(2):
            changed = fixture(); changed["observations"][index]["fingerprint"] = "7" * 64
            self.assert_refused(changed)

    def test_exactly_two_distinct_operators_and_channels_are_required(self):
        value = fixture()
        for observations in (
            value["observations"][:1], value["observations"] * 2,
        ):
            changed = fixture(); changed["observations"] = observations
            self.assert_refused(changed)
        changed = fixture()
        changed["observations"][1]["operatorId"] = changed["observations"][0]["operatorId"]
        self.assert_refused(changed)
        changed = fixture()
        changed["observations"][1]["channelId"] = changed["observations"][0]["channelId"]
        self.assert_refused(changed)
        changed = fixture(); changed["observations"][0]["channelId"] = "online-email"
        self.assert_refused(changed)

    def test_observations_and_source_copy_digests_are_exact(self):
        value = fixture()
        for key in tuple(value["observations"][0]):
            changed = copy.deepcopy(value); del changed["observations"][0][key]
            self.assert_refused(changed)
        changed = fixture(); changed["observations"][0]["extra"] = True
        self.assert_refused(changed)
        changed = fixture()
        changed["observations"][1]["sourceCopyDigest"] = \
            changed["observations"][0]["sourceCopyDigest"]
        self.assertEqual(self.decode(changed)["observationCount"], 2)
        for key in ("sourceCopyId", "sourceCopyProvenanceDigest"):
            changed = fixture()
            changed["observations"][1][key] = changed["observations"][0][key]
            self.assert_refused(changed)
        changed = fixture()
        changed["observations"][0]["sourceCopyProvenanceDigest"] = "A" * 64
        self.assert_refused(changed)

    def test_ceremony_window_is_canonical_positive_and_at_most_thirty_minutes(self):
        for timestamp in (
            "2026-09-07T08:00:00+00:00", "2026-09-07t08:00:00Z",
            "2026-02-29T00:00:00Z", "2026-09-07T24:00:00Z",
        ):
            changed = fixture(); changed["observations"][0]["observedAt"] = timestamp
            self.assert_refused(changed)
        changed = fixture(); changed["observations"][1]["observedAt"] = "2026-09-07T08:00:00Z"
        self.assertEqual(self.decode(changed)["ceremonyStartedAt"], "2026-09-07T08:00:00Z")
        changed = fixture(); changed["observations"][1]["observedAt"] = "2026-09-07T08:30:01Z"
        self.assert_refused(changed)
        changed = fixture(); changed["observations"].reverse()
        self.assert_refused(changed)

    def test_operators_must_be_distinct_from_all_role_issuers_and_custodians(self):
        for field, value in (
            ("roleIssuerIds", "bootstrap-operator-01"),
            ("custodianIds", "bootstrap-operator-02"),
        ):
            changed = fixture(); changed[field].append(value); changed[field].sort()
            self.assert_refused(changed)
        for field in ("roleIssuerIds", "custodianIds"):
            changed = fixture(); changed[field] = []
            self.assert_refused(changed)
            changed = fixture(); changed[field] = ["duplicate", "duplicate"]
            self.assert_refused(changed)
            changed = fixture(); changed[field] = [f"id-{index:02d}" for index in range(MAX_AUTHORITY_IDS + 1)]
            self.assert_refused(changed)
        changed = fixture()
        changed["custodianIds"] = ["release-issuer-01", "root-custodian-02"]
        changed["custodianIds"].sort()
        self.assert_refused(changed)

    def test_opaque_ids_reject_case_ambiguity_and_personal_data_shapes(self):
        for location, bad in (
            (("observations", 0, "operatorId"), "Operator-01"),
            (("observations", 0, "operatorId"), "alice@example.com"),
            (("observations", 0, "operatorId"), "Alice Smith"),
            (("observations", 0, "sourceCopyId"), "Copy-01"),
            (("observations", 0, "sourceCopyId"), "copy@example.com"),
            (("roleIssuerIds", 0), "Issuer-01"),
            (("custodianIds", 0), "../custodian"),
        ):
            changed = fixture()
            target = changed
            for key in location[:-1]: target = target[key]
            target[location[-1]] = bad
            self.assert_refused(changed)

    def test_every_binding_mutation_changes_evidence_digest(self):
        original = self.decode(fixture())["evidenceDigest"]
        mutations = []
        changed = fixture(); changed["bundleGeneration"] += 1; mutations.append(changed)
        changed = fixture(); changed["observations"][1]["observedAt"] = "2026-09-07T08:29:59Z"; mutations.append(changed)
        changed = fixture(); changed["roleIssuerIds"][0] = "asset-issuer-02"; mutations.append(changed)
        for changed in mutations:
            self.assertNotEqual(original, self.decode(changed)["evidenceDigest"])

    def test_dependency_and_authority_tampering_refuses_before_replacement(self):
        raw = canonical(fixture()); refusal = codec.TrustBootstrapEvidenceRefused
        for name, replacement in (
            ("CHANNELS", tuple(list(codec.CHANNELS))),
            ("MAX_EVIDENCE_BYTES", MAX_EVIDENCE_BYTES + 1),
            ("MAX_AUTHORITY_IDS", MAX_AUTHORITY_IDS + 1),
            ("TrustBootstrapEvidenceRefused", Exception),
            ("MappingProxyType", dict), ("json", object()),
            ("hashlib", object()), ("re", object()),
            ("__all__", tuple(list(codec.__all__))),
        ):
            original = getattr(codec, name)
            try:
                setattr(codec, name, replacement)
                with self.assertRaises(refusal): codec.decode(raw)
            finally:
                setattr(codec, name, original)
        for owner, name in ((codec.json, "loads"), (codec.json, "dumps"),
                            (codec.hashlib, "sha256"), (codec.re, "fullmatch")):
            original = getattr(owner, name); calls = []
            def replacement(*_args, **_kwargs): calls.append(True); return {}
            try:
                setattr(owner, name, replacement)
                with self.assertRaises(refusal): codec.decode(raw)
                self.assertEqual(calls, [])
            finally:
                setattr(owner, name, original)

    def test_baseexception_identity_and_no_acceptance_surface(self):
        invoke = next(cell.cell_contents for cell in codec.decode.__closure__
                      if callable(cell.cell_contents)
                      and getattr(cell.cell_contents, "__name__", "") == "invoke")
        original = codec.CHANNELS; replacement = tuple(list(original))
        for primary in (KeyboardInterrupt("k"), SystemExit("s")):
            def drift_then_stop(error=primary):
                codec.CHANNELS = replacement
                raise error
            try:
                with self.assertRaises(type(primary)) as raised: invoke(drift_then_stop)
                self.assertIs(raised.exception, primary)
            finally:
                codec.CHANNELS = original
        for forbidden in ("accept", "admit", "authenticate", "bootstrap",
                          "sign", "trust", "verify", "verify_signature"):
            self.assertFalse(hasattr(codec, forbidden))
        text = (codec.__doc__ or "").lower()
        for phrase in ("structural", "does not", "supplied", "opaque"):
            self.assertIn(phrase, text)


if __name__ == "__main__":
    unittest.main()
