import copy
import hashlib
import importlib.util
import json
from pathlib import Path
from types import MappingProxyType
import unittest


ROOT = Path(__file__).parent
SPEC = importlib.util.spec_from_file_location(
    "checkout_verifier_evidence", ROOT / "checkout-verifier-evidence.py"
)
codec = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(codec)


def canonical(value):
    return (json.dumps(value, sort_keys=True, separators=(",", ":"),
                       ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")


def request_fixture(role="release-source", trust_generation=6):
    return {
        "artifact": {"bytesDigest": "1" * 64, "role": role},
        "cryptographyRuntime": {"acquisitionEvidenceDigest": "2" * 64,
                                "closureDigest": "3" * 64, "generation": 4},
        "detachedEnvelope": {"bytesDigest": "4" * 64},
        "highWater": {"currentGeneration": 7, "currentStateDigest": "5" * 64,
                      "proposedGeneration": 8, "proposedStateDigest": "6" * 64},
        "requestId": "verifier-request-08",
        "revocationSnapshot": {"bytesDigest": "7" * 64, "generation": 8,
            "namespace": {"authorityRole": role, "issuerId": "release-issuer-01",
                          "issuerKeyGeneration": 3,
                          "revocationTrustGeneration": 5}},
        "role": role, "runId": "checkout-run-08",
        "trustRootBundle": {"bytesDigest": "8" * 64,
                            "trustGeneration": trust_generation},
        "trustedTime": {"inputSetDigest": "9" * 64},
        "verifier": {"sourceDigest": "a" * 64}, "version": 1,
    }


BINDING_MAP = {
    "artifactDigest": ("artifact", "bytesDigest"),
    "cryptographyAcquisitionEvidenceDigest": ("cryptographyRuntime", "acquisitionEvidenceDigest"),
    "cryptographyClosureDigest": ("cryptographyRuntime", "closureDigest"),
    "cryptographyGeneration": ("cryptographyRuntime", "generation"),
    "currentHighWaterGeneration": ("highWater", "currentGeneration"),
    "currentHighWaterStateDigest": ("highWater", "currentStateDigest"),
    "envelopeDigest": ("detachedEnvelope", "bytesDigest"),
    "proposedHighWaterGeneration": ("highWater", "proposedGeneration"),
    "proposedHighWaterStateDigest": ("highWater", "proposedStateDigest"),
    "revocationSnapshotDigest": ("revocationSnapshot", "bytesDigest"),
    "snapshotGeneration": ("revocationSnapshot", "generation"),
    "trustBundleDigest": ("trustRootBundle", "bytesDigest"),
    "trustGeneration": ("trustRootBundle", "trustGeneration"),
    "trustedTimeInputSetDigest": ("trustedTime", "inputSetDigest"),
    "verifierSourceDigest": ("verifier", "sourceDigest"),
}


def signer(number):
    digit = format(number, "x")[-1]
    return {"keyId": f"signing-key-{number:02d}",
            "principalId": f"signer-principal-{number:02d}",
            "publicKeyDigest": digit * 64}


def matched(entry, number):
    return {**entry, "envelopeDigest": format(number + 8, "x")[-1] * 64,
            "signatureEvidenceDigest": format(number + 4, "x")[-1] * 64}


def quorum(kind, eligible_count, matched_count, generation, offset=1):
    eligible = [signer(offset + index) for index in range(eligible_count)]
    return {"eligibleSigners": eligible, "kind": kind,
            "matchedSigners": [matched(eligible[index], offset + index)
                               for index in range(matched_count)],
            "threshold": matched_count, "trustGeneration": generation}


def evidence_fixture(request=None, status="verified-structural-evidence",
                     trust_mode="bootstrap"):
    request = request_fixture() if request is None else request
    bindings = {key: request[parent][field]
                for key, (parent, field) in BINDING_MAP.items()}
    role = request["role"]
    generation = request["trustRootBundle"]["trustGeneration"]
    excluded = None
    prior = None
    if role == "asset-review":
        mode = "asset-review"
        excluded = "release-source-key-01"
        quorums = [quorum("asset-review", 2, 2, generation)]
    elif role == "revocation-snapshot":
        mode = "revocation"
        quorums = [quorum("revocation", 3, 2, generation)]
    elif role == "trust-root-bundle" and trust_mode == "rotation":
        mode = "rotation"
        prior = generation - 1
        quorums = [quorum("old-root", 3, 2, prior, 1),
                   quorum("new-root", 3, 2, generation, 7)]
    elif role == "trust-root-bundle":
        mode = "bootstrap"
        quorums = [quorum("current-root", 3, 2, generation)]
    else:
        mode = "ordinary"
        quorums = [quorum(role, 1, 1, generation)]
    verified = status == "verified-structural-evidence"
    return {
        "bindings": bindings, "domainMessageDigest": "b" * 64,
        "excludedReleaseSourceKeyId": excluded,
        "issuerId": request["revocationSnapshot"]["namespace"]["issuerId"],
        "issuerKeyGeneration": request["revocationSnapshot"]["namespace"]["issuerKeyGeneration"],
        "priorTrustGeneration": prior, "quorumMode": mode,
        "quorums": quorums if verified else [],
        "refusalCode": None if verified else "signature-refused",
        "revocationTrustGeneration": request["revocationSnapshot"]["namespace"]["revocationTrustGeneration"],
        "role": role, "signatureAlgorithm": "ed25519",
        "signaturePrimitiveResult": verified, "status": status,
        "verifierRequestDigest": hashlib.sha256(canonical(request)).hexdigest(),
        "version": 1,
    }


class VerifierEvidenceTests(unittest.TestCase):
    def decode(self, evidence=None, request=None):
        request = request_fixture() if request is None else request
        evidence = evidence_fixture(request) if evidence is None else evidence
        raw = canonical(evidence) if type(evidence) is dict else evidence
        return codec.decode(canonical(request), raw)

    def assert_refused(self, evidence, request=None):
        with self.assertRaisesRegex(codec.VerifierEvidenceRefused,
                                    "^verifier_evidence$"):
            self.decode(evidence, request)

    def test_ordinary_known_vector_is_immutable_narrow_and_structural_only(self):
        request = request_fixture(); value = evidence_fixture(request); raw = canonical(value)
        result = self.decode(value, request)
        self.assertEqual(codec.canonical_evidence(value), raw)
        self.assertIs(type(result), MappingProxyType)
        self.assertIs(result["evidenceStructuralOnly"], True)
        self.assertEqual(result["quorumSummary"], (("release-source", 1, 1),))
        self.assertEqual(result["evidenceDigest"], hashlib.sha256(raw).hexdigest())
        with self.assertRaises(TypeError): result["trusted"] = True
        for field in ("trusted", "admitted", "authenticated", "fresh"):
            self.assertNotIn(field, result)

    def test_refused_status_has_fixed_code_and_no_quorum_success_shape(self):
        for code in codec.REFUSAL_CODES:
            value = evidence_fixture(status="refused"); value["refusalCode"] = code
            result = self.decode(value)
            self.assertEqual(result["status"], "refused")
            self.assertEqual(result["quorumSummary"], ())
        for bad in (None, "secret-error", True):
            value = evidence_fixture(status="refused"); value["refusalCode"] = bad
            self.assert_refused(value)

    def test_schema_canonical_encoding_and_size_are_exact(self):
        value = evidence_fixture(); raw = canonical(value)
        for key in tuple(value):
            changed = copy.deepcopy(value); del changed[key]; self.assert_refused(changed)
        for invalid in (raw[:-1], raw + b"\n", json.dumps(value).encode("ascii"),
                        b"\xef\xbb\xbf" + raw, b'{"version":1,"version":1}\n',
                        b'{"version":NaN}\n', "bad", bytearray(raw),
                        b"{" + b" " * codec.MAX_EVIDENCE_BYTES + b"}\n"):
            with self.assertRaises(codec.VerifierEvidenceRefused):
                codec.decode(canonical(request_fixture()), invalid)

    def test_every_request_binding_digest_role_and_namespace_must_match(self):
        request = request_fixture(); value = evidence_fixture(request)
        for key in BINDING_MAP:
            changed = copy.deepcopy(value); current = changed["bindings"][key]
            changed["bindings"][key] = current + 1 if type(current) is int else "d" * 64
            with self.subTest(key=key): self.assert_refused(changed, request)
        for key, bad in (("verifierRequestDigest", "d" * 64), ("role", "vendor-build"),
                         ("issuerId", "other-issuer"), ("issuerKeyGeneration", 4),
                         ("revocationTrustGeneration", 6)):
            changed = copy.deepcopy(value); changed[key] = bad
            self.assert_refused(changed, request)

    def test_ordinary_role_requires_one_exact_eligible_and_matched_signer(self):
        value = evidence_fixture()
        for mutation in (lambda q: q["eligibleSigners"].append(signer(2)),
                         lambda q: q["matchedSigners"].clear(),
                         lambda q: q.__setitem__("threshold", 2),
                         lambda q: q.__setitem__("kind", "vendor-build")):
            changed = copy.deepcopy(value); mutation(changed["quorums"][0])
            self.assert_refused(changed)

    def test_asset_review_requires_two_distinct_signers_and_release_exclusion(self):
        request = request_fixture("asset-review"); value = evidence_fixture(request)
        self.assertEqual(self.decode(value, request)["quorumSummary"],
                         (("asset-review", 2, 2),))
        for field in ("keyId", "publicKeyDigest", "principalId"):
            changed = copy.deepcopy(value)
            changed["quorums"][0]["eligibleSigners"][1][field] = \
                changed["quorums"][0]["eligibleSigners"][0][field]
            self.assert_refused(changed, request)
        changed = copy.deepcopy(value)
        changed["excludedReleaseSourceKeyId"] = changed["quorums"][0]["eligibleSigners"][0]["keyId"]
        self.assert_refused(changed, request)

    def test_revocation_requires_three_eligible_and_two_matched_subset(self):
        request = request_fixture("revocation-snapshot"); value = evidence_fixture(request)
        self.assertEqual(self.decode(value, request)["quorumSummary"],
                         (("revocation", 2, 3),))
        for mutation in (lambda q: q["eligibleSigners"].pop(),
                         lambda q: q["matchedSigners"].pop(),
                         lambda q: q["matchedSigners"].append(matched(signer(9), 9))):
            changed = copy.deepcopy(value); mutation(changed["quorums"][0])
            self.assert_refused(changed, request)
        changed = copy.deepcopy(value)
        first = changed["quorums"][0]["matchedSigners"][0]
        second = changed["quorums"][0]["matchedSigners"][1]
        for key in ("keyId", "principalId", "publicKeyDigest"):
            second[key] = first[key]
        self.assert_refused(changed, request)

    def test_revocation_custodians_are_distinct_from_evidence_issuer(self):
        request = request_fixture("revocation-snapshot")
        issuer = request["revocationSnapshot"]["namespace"]["issuerId"]
        changed = evidence_fixture(request)
        changed["quorums"][0]["eligibleSigners"][2]["principalId"] = issuer
        self.assert_refused(changed, request)
        changed = evidence_fixture(request)
        changed["quorums"][0]["eligibleSigners"][0]["principalId"] = issuer
        changed["quorums"][0]["matchedSigners"][0]["principalId"] = issuer
        self.assert_refused(changed, request)

    def test_trust_bootstrap_requires_current_root_two_of_three(self):
        request = request_fixture("trust-root-bundle"); value = evidence_fixture(request)
        result = self.decode(value, request)
        self.assertEqual(result["quorumMode"], "bootstrap")
        self.assertEqual(result["quorumSummary"], (("current-root", 2, 3),))
        changed = copy.deepcopy(value); changed["quorums"][0]["trustGeneration"] -= 1
        self.assert_refused(changed, request)

    def test_trust_rotation_requires_separate_ordered_old_and_new_quorums(self):
        request = request_fixture("trust-root-bundle", 7)
        value = evidence_fixture(request, trust_mode="rotation")
        self.assertEqual(self.decode(value, request)["quorumSummary"],
                         (("old-root", 2, 3), ("new-root", 2, 3)))
        def copy_envelope(v):
            v["quorums"][1]["matchedSigners"][0]["envelopeDigest"] = \
                v["quorums"][0]["matchedSigners"][0]["envelopeDigest"]
        def copy_signature(v):
            v["quorums"][1]["matchedSigners"][0]["signatureEvidenceDigest"] = \
                v["quorums"][0]["matchedSigners"][0]["signatureEvidenceDigest"]
        for mutation in (lambda v: v["quorums"].reverse(),
                         lambda v: v["quorums"].pop(),
                         lambda v: v.__setitem__("priorTrustGeneration", 7),
                         copy_envelope, copy_signature):
            changed = copy.deepcopy(value); mutation(changed)
            self.assert_refused(changed, request)

    def test_quorum_membership_counts_schema_and_raw_material_are_closed(self):
        changed = evidence_fixture()
        changed["quorums"][0]["matchedSigners"][0]["keyId"] = "unknown-key"
        self.assert_refused(changed)
        for section in ("matchedSigners", "eligibleSigners"):
            changed = evidence_fixture(); changed["quorums"][0][section][0]["extra"] = True
            self.assert_refused(changed)
        changed = evidence_fixture(); changed["quorums"][0]["threshold"] = True
        self.assert_refused(changed)
        for key in ("privateKey", "publicKey", "signature", "artifactBytes",
                    "verifierCallback", "credential", "passphrase"):
            changed = evidence_fixture(); changed[key] = "forbidden"; self.assert_refused(changed)

    def test_signature_semantics_are_closed(self):
        for key, bad in (("signatureAlgorithm", "rsa"), ("signatureAlgorithm", True),
                         ("domainMessageDigest", "A" * 64),
                         ("signaturePrimitiveResult", False)):
            changed = evidence_fixture(); changed[key] = bad; self.assert_refused(changed)

    def test_dependency_and_sibling_authority_drift_fail_closed(self):
        raw_request = canonical(request_fixture()); raw = canonical(evidence_fixture())
        refusal = codec.VerifierEvidenceRefused
        for owner, name, replacement in ((codec, "ROLES", tuple(list(codec.ROLES))),
            (codec, "MappingProxyType", dict), (codec, "json", object()),
            (codec, "hashlib", object()), (codec, "VerifierEvidenceRefused", Exception),
            (codec._REQUEST, "decode", lambda _raw: {})):
            original = getattr(owner, name)
            try:
                setattr(owner, name, replacement)
                with self.assertRaises(refusal): codec.decode(raw_request, raw)
            finally: setattr(owner, name, original)

    def test_dependency_replacement_and_callable_metadata_drift_fail_closed(self):
        raw_request = canonical(request_fixture())
        raw = canonical(evidence_fixture())
        value = evidence_fixture()
        refusal = codec.VerifierEvidenceRefused
        for owner, name in ((codec.json, "dumps"), (codec.json, "loads"),
                            (codec.hashlib, "sha256")):
            original = getattr(owner, name)
            calls = []
            def replacement(*_args, **_kwargs):
                calls.append(True)
                return {}
            try:
                setattr(owner, name, replacement)
                with self.assertRaises(refusal):
                    codec.canonical_evidence(value)
                self.assertEqual(calls, [])
            finally:
                setattr(owner, name, original)

        function = codec.json.dumps
        original_code = function.__code__
        try:
            function.__code__ = original_code.replace()
            with self.assertRaises(refusal):
                codec.canonical_evidence(value)
        finally:
            function.__code__ = original_code

        original_defaults = function.__defaults__
        try:
            function.__defaults__ = (None,)
            with self.assertRaises(refusal):
                codec.canonical_evidence(value)
        finally:
            function.__defaults__ = original_defaults

        original_cls = function.__kwdefaults__["cls"]
        injected = []
        class InjectedEncoder(json.JSONEncoder):
            def __init__(self, *_args, **_kwargs):
                injected.append(True)
                super().__init__()
        try:
            function.__kwdefaults__["cls"] = InjectedEncoder
            with self.assertRaises(refusal):
                codec.canonical_evidence(value)
            self.assertEqual(injected, [])
        finally:
            function.__kwdefaults__["cls"] = original_cls

    def test_baseexception_identity_and_public_non_authority_surface(self):
        invoke = next(cell.cell_contents for cell in codec.decode.__closure__
                      if callable(cell.cell_contents)
                      and getattr(cell.cell_contents, "__name__", "") == "invoke")
        for primary in (KeyboardInterrupt("k"), SystemExit("s")):
            with self.assertRaises(type(primary)) as raised:
                invoke(lambda error=primary: (_ for _ in ()).throw(error))
            self.assertIs(raised.exception, primary)
        original_cls = codec.json.dumps.__kwdefaults__["cls"]
        for primary in (KeyboardInterrupt("drift-k"), SystemExit("drift-s")):
            def drift_then_stop(error=primary):
                codec.json.dumps.__kwdefaults__["cls"] = object
                raise error
            try:
                with self.assertRaises(type(primary)) as raised:
                    invoke(drift_then_stop)
                self.assertIs(raised.exception, primary)
            finally:
                codec.json.dumps.__kwdefaults__["cls"] = original_cls
        with self.assertRaisesRegex(codec.VerifierEvidenceRefused,
                                    "^verifier_evidence$"):
            invoke(lambda: (_ for _ in ()).throw(ValueError("sensitive")))
        self.assertEqual(codec.__all__,
            ("VerifierEvidenceRefused", "canonical_evidence", "decode"))
        for forbidden in ("admit", "verify", "verify_signature", "load_key", "run_crypto"):
            self.assertFalse(hasattr(codec, forbidden))
        text = (codec.__doc__ or "").lower()
        for phrase in ("structural", "does not", "cryptography", "admission"):
            self.assertIn(phrase, text)


if __name__ == "__main__":
    unittest.main()
