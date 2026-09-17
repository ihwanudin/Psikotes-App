import copy
import hashlib
import importlib.util
import json
from pathlib import Path
from types import MappingProxyType
import unittest


MODULE_PATH = Path(__file__).with_name("checkout-verifier-request.py")
SPEC = importlib.util.spec_from_file_location("checkout_verifier_request", MODULE_PATH)
request = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(request)

I10_PATH = Path(__file__).with_name("checkout-composition-admission-artifact.py")
I10_SPEC = importlib.util.spec_from_file_location(
    "checkout_composition_admission_i15_oracle", I10_PATH
)
i10 = importlib.util.module_from_spec(I10_SPEC)
I10_SPEC.loader.exec_module(i10)
I12_PATH = Path(__file__).with_name("checkout-revocation-high-water-transition.py")
I12_SPEC = importlib.util.spec_from_file_location(
    "checkout_revocation_high_water_i15_oracle", I12_PATH
)
i12 = importlib.util.module_from_spec(I12_SPEC)
I12_SPEC.loader.exec_module(i12)

ZERO_DIGEST = "0" * 64


def canonical(value):
    return (json.dumps(value, sort_keys=True, separators=(",", ":"),
                       ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")


def fixture(role="release-source"):
    return {
        "artifact": {"bytesDigest": "1" * 64, "role": role},
        "cryptographyRuntime": {
            "acquisitionEvidenceDigest": "2" * 64,
            "closureDigest": "3" * 64,
            "generation": 4,
        },
        "detachedEnvelope": {"bytesDigest": "4" * 64},
        "highWater": {
            "currentGeneration": 7,
            "currentStateDigest": "5" * 64,
            "proposedGeneration": 8,
            "proposedStateDigest": "6" * 64,
        },
        "requestId": "verifier-request-08",
        "revocationSnapshot": {
            "bytesDigest": "7" * 64,
            "generation": 8,
            "namespace": {
                "authorityRole": role,
                "issuerId": "release-issuer-01",
                "issuerKeyGeneration": 3,
                "revocationTrustGeneration": 5,
            },
        },
        "role": role,
        "runId": "checkout-run-08",
        "trustRootBundle": {
            "bytesDigest": "8" * 64,
            "trustGeneration": 6,
        },
        "trustedTime": {"inputSetDigest": "9" * 64},
        "verifier": {"sourceDigest": "a" * 64},
        "version": 1,
    }


class VerifierRequestTests(unittest.TestCase):
    def decode(self, document=None):
        value = fixture() if document is None else document
        raw = canonical(value) if type(value) is dict else value
        return request.decode(raw)

    def assert_refused(self, document):
        with self.assertRaisesRegex(
            request.VerifierRequestRefused, "^verifier_request$"
        ):
            self.decode(document)

    def test_known_vector_is_exact_immutable_and_structural_only(self):
        document = fixture()
        raw = canonical(document)
        result = self.decode(document)
        self.assertIs(type(result), type(MappingProxyType({})))
        self.assertIs(result["structuralOnly"], True)
        self.assertEqual(result["role"], "release-source")
        self.assertEqual(result["requestId"], "verifier-request-08")
        self.assertEqual(result["artifactDigest"], "1" * 64)
        self.assertEqual(result["requestDigest"], hashlib.sha256(raw).hexdigest())
        self.assertIs(type(result["revocationNamespace"]), tuple)
        with self.assertRaises(TypeError):
            result["verified"] = True
        for field in ("accepted", "authenticated", "current", "fresh",
                      "highWaterAccepted", "signatureVerified", "trusted"):
            self.assertNotIn(field, result)

    def test_schema_is_closed_and_every_binding_is_required(self):
        document = fixture()
        for field in tuple(document):
            changed = copy.deepcopy(document); del changed[field]
            with self.subTest(missing=field): self.assert_refused(changed)
        for parent in (
            "artifact", "cryptographyRuntime", "detachedEnvelope", "highWater",
            "revocationSnapshot", "trustRootBundle", "trustedTime", "verifier",
        ):
            for field in tuple(document[parent]):
                changed = copy.deepcopy(document); del changed[parent][field]
                with self.subTest(parent=parent, missing=field): self.assert_refused(changed)

    def test_algorithm_fallback_callbacks_paths_and_secrets_are_rejected(self):
        for field in (
            "algorithm", "fallback", "privateKey", "signerCallback",
            "verifierCallback", "path", "environment", "credential",
        ):
            changed = copy.deepcopy(fixture()); changed[field] = "forbidden"
            with self.subTest(field=field): self.assert_refused(changed)
        changed = copy.deepcopy(fixture()); changed["verifier"]["path"] = "c:/tool.py"
        self.assert_refused(changed)
        changed = copy.deepcopy(fixture()); changed["detachedEnvelope"]["verify"] = True
        self.assert_refused(changed)

    def test_canonical_ascii_json_one_lf_duplicate_nonfinite_and_size(self):
        document = fixture(); raw = canonical(document)
        self.assertEqual(request.canonical_request(document), raw)
        for invalid in (
            raw[:-1], raw + b"\n", json.dumps(document).encode("ascii"),
            b"\xef\xbb\xbf" + raw, "not-bytes", bytearray(raw),
            b'{"role":"release-source","role":"release-source"}\n',
            b'{"version":NaN}\n', b"{" + b" " * (32 * 1024) + b"}\n",
        ):
            with self.subTest(invalid=repr(invalid)[:50]): self.assert_refused(invalid)

    def test_request_run_and_role_are_exact_bounded_identifiers(self):
        for field, bad in (
            ("requestId", ""), ("requestId", "../request"),
            ("runId", "run id"), ("role", "Release-Source"),
            ("role", True), ("version", True), ("version", 2),
        ):
            changed = copy.deepcopy(fixture()); changed[field] = bad
            with self.subTest(field=field, bad=bad): self.assert_refused(changed)
        for role in i10.REVOCATION_ROLES:
            self.decode(fixture(role))

    def test_role_vocabulary_matches_i10_and_i12_authorities_exactly(self):
        self.assertEqual(request.ROLES, i10.REVOCATION_ROLES)
        self.assertEqual(request.ROLES, i12.AUTHORITY_ROLES)

    def test_role_and_revocation_namespace_must_match_exactly(self):
        changed = copy.deepcopy(fixture())
        changed["artifact"]["role"] = "vendor-build"
        self.assert_refused(changed)
        changed = copy.deepcopy(fixture())
        changed["revocationSnapshot"]["namespace"]["authorityRole"] = "vendor-build"
        self.assert_refused(changed)

    def test_all_digests_are_exact_lowercase_sha256(self):
        locations = (
            ("artifact", "bytesDigest"),
            ("cryptographyRuntime", "acquisitionEvidenceDigest"),
            ("cryptographyRuntime", "closureDigest"),
            ("detachedEnvelope", "bytesDigest"),
            ("highWater", "currentStateDigest"),
            ("highWater", "proposedStateDigest"),
            ("revocationSnapshot", "bytesDigest"),
            ("trustRootBundle", "bytesDigest"),
            ("trustedTime", "inputSetDigest"),
            ("verifier", "sourceDigest"),
        )
        for parent, field in locations:
            for bad in ("A" * 64, "a" * 63, True):
                changed = copy.deepcopy(fixture()); changed[parent][field] = bad
                with self.subTest(parent=parent, field=field, bad=bad):
                    self.assert_refused(changed)

    def test_generations_are_strict_and_high_water_is_monotonic(self):
        for parent, field in (
            ("cryptographyRuntime", "generation"),
            ("revocationSnapshot", "generation"),
            ("trustRootBundle", "trustGeneration"),
            ("revocationSnapshot", "namespace"),
        ):
            if field == "namespace":
                continue
            for bad in (0, True, 1.0, 1 << 63):
                changed = copy.deepcopy(fixture()); changed[parent][field] = bad
                self.assert_refused(changed)
        for current, proposed, current_digest in (
            (8, 8, "5" * 64), (8, 7, "5" * 64),
            (0, 2, ZERO_DIGEST), (0, 1, "5" * 64),
            (1, 2, ZERO_DIGEST),
        ):
            changed = copy.deepcopy(fixture())
            changed["highWater"].update(
                currentGeneration=current, proposedGeneration=proposed,
                currentStateDigest=current_digest,
            )
            self.assert_refused(changed)
        bootstrap = copy.deepcopy(fixture())
        bootstrap["highWater"].update(
            currentGeneration=0, currentStateDigest=ZERO_DIGEST,
            proposedGeneration=1,
        )
        bootstrap["revocationSnapshot"]["generation"] = 1
        self.decode(bootstrap)

    def test_snapshot_and_proposed_high_water_generation_are_exactly_bound(self):
        for snapshot_generation in (7, 9):
            changed = copy.deepcopy(fixture())
            changed["revocationSnapshot"]["generation"] = snapshot_generation
            with self.subTest(advance=snapshot_generation):
                self.assert_refused(changed)

        for snapshot_generation in (0, 2):
            changed = copy.deepcopy(fixture())
            changed["highWater"].update(
                currentGeneration=0,
                currentStateDigest=ZERO_DIGEST,
                proposedGeneration=1,
            )
            changed["revocationSnapshot"]["generation"] = snapshot_generation
            with self.subTest(bootstrap=snapshot_generation):
                self.assert_refused(changed)

    def test_proposed_high_water_digest_is_nonzero_and_differs_from_current(self):
        changed = copy.deepcopy(fixture())
        changed["highWater"]["proposedStateDigest"] = ZERO_DIGEST
        self.assert_refused(changed)
        changed = copy.deepcopy(fixture())
        changed["highWater"]["proposedStateDigest"] = changed["highWater"][
            "currentStateDigest"
        ]
        self.assert_refused(changed)

        bootstrap = copy.deepcopy(fixture())
        bootstrap["highWater"].update(
            currentGeneration=0,
            currentStateDigest=ZERO_DIGEST,
            proposedGeneration=1,
            proposedStateDigest=ZERO_DIGEST,
        )
        bootstrap["revocationSnapshot"]["generation"] = 1
        self.assert_refused(bootstrap)

    def test_revocation_namespace_is_closed_and_generation_bound(self):
        namespace = fixture()["revocationSnapshot"]["namespace"]
        for field in tuple(namespace):
            changed = copy.deepcopy(fixture())
            del changed["revocationSnapshot"]["namespace"][field]
            self.assert_refused(changed)
        for field, bad in (
            ("issuerId", "../issuer"), ("issuerKeyGeneration", 0),
            ("issuerKeyGeneration", True), ("revocationTrustGeneration", 0),
        ):
            changed = copy.deepcopy(fixture())
            changed["revocationSnapshot"]["namespace"][field] = bad
            self.assert_refused(changed)

    def test_result_contains_only_narrow_builtin_bindings(self):
        result = self.decode()
        self.assertEqual(tuple(result), (
            "structuralOnly", "requestId", "runId", "role", "artifactDigest",
            "envelopeDigest", "trustBundleDigest", "trustGeneration",
            "revocationSnapshotDigest", "revocationGeneration",
            "revocationNamespace", "trustedTimeInputSetDigest",
            "currentHighWaterStateDigest", "currentHighWaterGeneration",
            "proposedHighWaterStateDigest", "proposedHighWaterGeneration",
            "verifierSourceDigest", "cryptographyAcquisitionEvidenceDigest",
            "cryptographyClosureDigest", "cryptographyGeneration", "requestDigest",
        ))
        for value in result.values():
            self.assertIn(type(value), (bool, int, str, tuple))

    def test_dependency_and_module_mutation_fail_closed(self):
        raw = canonical(fixture()); refusal = request.VerifierRequestRefused
        for owner, name, replacement in (
            (request, "MAX_REQUEST_BYTES", request.MAX_REQUEST_BYTES + 1),
            (request, "ROLES", tuple(list(request.ROLES))),
            (request, "MappingProxyType", dict), (request, "json", object()),
            (request, "hashlib", object()), (request, "re", object()),
            (request, "VerifierRequestRefused", Exception),
            (request, "__all__", tuple(list(request.__all__))),
        ):
            original = getattr(owner, name)
            try:
                setattr(owner, name, replacement)
                with self.assertRaises(refusal): request.decode(raw)
            finally:
                setattr(owner, name, original)

    def test_dependency_callable_replacement_and_metadata_drift_fail_closed(self):
        refusal = request.VerifierRequestRefused
        raw = canonical(fixture())
        for owner, name in (
            (request.json, "dumps"), (request.json, "loads"),
            (request.hashlib, "sha256"), (request.re, "fullmatch"),
        ):
            original = getattr(owner, name)
            calls = []
            def replacement(*_args, **_kwargs):
                calls.append(True)
                return None
            try:
                setattr(owner, name, replacement)
                with self.assertRaises(refusal):
                    request.decode(raw)
                self.assertEqual(calls, [])
            finally:
                setattr(owner, name, original)

        for function in (request.json.dumps, request.json.loads, request.re.fullmatch):
            original_code = function.__code__
            try:
                function.__code__ = (lambda: None).__code__
                with self.assertRaises(refusal): request.decode(raw)
            finally:
                function.__code__ = original_code
            original_defaults = function.__defaults__
            try:
                function.__defaults__ = (None,) if original_defaults is None \
                    else tuple(list(original_defaults))
                with self.assertRaises(refusal): request.decode(raw)
            finally:
                function.__defaults__ = original_defaults
            original_kwdefaults = function.__kwdefaults__
            if original_kwdefaults is not None:
                saved = dict(original_kwdefaults)
                try:
                    original_kwdefaults["__hostile__"] = True
                    with self.assertRaises(refusal): request.decode(raw)
                finally:
                    original_kwdefaults.clear(); original_kwdefaults.update(saved)

    def test_error_redaction_and_baseexception_identity_are_preserved(self):
        invoke = next(
            cell.cell_contents for cell in request.decode.__closure__
            if callable(cell.cell_contents)
            and getattr(cell.cell_contents, "__name__", "") == "invoke"
        )
        def fail():
            raise RuntimeError("secret verifier host detail")
        with self.assertRaisesRegex(
            request.VerifierRequestRefused, "^verifier_request$"
        ) as raised:
            invoke(fail)
        self.assertNotIn("secret", str(raised.exception))
        for primary in (KeyboardInterrupt("keyboard-secret"), SystemExit(17)):
            def stop(error=primary):
                raise error
            with self.assertRaises(type(primary)) as caught:
                invoke(stop)
            self.assertIs(caught.exception, primary)

    def test_public_surface_has_no_crypto_discovery_or_admission(self):
        self.assertEqual(request.__all__, (
            "VerifierRequestRefused", "canonical_request", "decode",
        ))
        for forbidden in ("accept", "admit", "discover", "load_key", "sign",
                          "verify", "verify_signature", "run"):
            self.assertFalse(hasattr(request, forbidden))


if __name__ == "__main__":
    unittest.main()
