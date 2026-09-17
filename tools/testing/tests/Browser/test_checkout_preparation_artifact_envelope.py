import copy
import hashlib
import importlib.util
import json
from pathlib import Path
from types import MappingProxyType
import unittest


MODULE_PATH = Path(__file__).with_name("checkout-preparation-artifact-envelope.py")
SPEC = importlib.util.spec_from_file_location(
    "checkout_preparation_artifact_envelope", MODULE_PATH
)
envelope = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(envelope)


# I1 structural-v1 freezes only this envelope boundary. Role-specific artifact
# schemas, TTL, trust, quorum, revocation, and replay acceptance remain outside.
MAX_ENVELOPE_BYTES = 4096
MAX_ARTIFACT_BYTES = 8 * 1024 * 1024
ID_PATTERN = r"[a-z0-9](?:[a-z0-9._-]{0,63})"
ALLOWED_ROLES = (
    "asset-review",
    "composition-admission",
    "preparation-authorization",
    "release-source",
    "revocation-snapshot",
    "runtime-configuration-policy",
    "tool-runtime-closure",
    "trust-root-bundle",
    "vendor-build",
)
RESULT_FIELDS = (
    "structuralOnly",
    "role",
    "issuerId",
    "keyId",
    "keyGeneration",
    "trustGeneration",
    "artifactDigest",
    "envelopeDigest",
    "signingMessageDigest",
)

DOMAINS = {
    role: f"oncam.checkout.{role}.v1".encode("ascii") for role in ALLOWED_ROLES
}

OPAQUE_ARTIFACT = b"\x00\xffrole-specific-artifact\n"
KNOWN_ARTIFACT_DIGEST = "0a8e68c8f98cca86374c335836ccf41ff15a3e2734d725a5b55a1cb07c9766f3"
KNOWN_ENVELOPE_DIGEST = "ba91b31af2815501a3b660270f2667087560d0ad1068a24f6563dc23c9df874d"
KNOWN_SIGNING_MESSAGE_DIGEST = (
    "915e7b3dff2168b6c25007a94dc59f5bd1c5807d9c3d0f4c105260f86af3e646"
)
_UNSET = object()


def canonical(value):
    return (
        json.dumps(
            value,
            sort_keys=True,
            separators=(",", ":"),
            ensure_ascii=True,
            allow_nan=False,
        )
        + "\n"
    ).encode("ascii")


def fixture(role="release-source", artifact_raw=OPAQUE_ARTIFACT):
    return {
        "algorithm": "ed25519",
        "artifactDigest": hashlib.sha256(artifact_raw).hexdigest(),
        "issuerId": "release-custodian-01",
        "keyGeneration": 3,
        "keyId": "release-source-01",
        "role": role,
        "signature": "7" * 128,
        "trustGeneration": 5,
        "version": 1,
    }


class PreparationArtifactEnvelopeTests(unittest.TestCase):
    def assert_refused(self, document, artifact_raw=OPAQUE_ARTIFACT, expected_role=_UNSET):
        if expected_role is _UNSET:
            expected_role = document.get("role", "release-source") if type(document) is dict else "release-source"
        raw = canonical(document) if type(document) is dict else document
        with self.assertRaisesRegex(
            envelope.PreparationArtifactEnvelopeRefused,
            "^preparation_artifact_envelope$",
        ):
            envelope.decode(raw, artifact_raw, expected_role=expected_role)

    def test_known_vector_is_narrow_immutable_and_structural_only(self):
        document = fixture()
        raw = canonical(document)

        result = envelope.decode(raw, OPAQUE_ARTIFACT, expected_role="release-source")

        self.assertEqual(document["artifactDigest"], KNOWN_ARTIFACT_DIGEST)
        self.assertEqual(hashlib.sha256(raw).hexdigest(), KNOWN_ENVELOPE_DIGEST)
        self.assertIs(type(result), MappingProxyType)
        self.assertEqual(tuple(result.keys()), RESULT_FIELDS)
        self.assertIs(result["structuralOnly"], True)
        self.assertEqual(result["role"], "release-source")
        self.assertEqual(result["issuerId"], "release-custodian-01")
        self.assertEqual(result["keyId"], "release-source-01")
        self.assertEqual(result["keyGeneration"], 3)
        self.assertEqual(result["trustGeneration"], 5)
        self.assertEqual(result["artifactDigest"], KNOWN_ARTIFACT_DIGEST)
        self.assertEqual(result["envelopeDigest"], KNOWN_ENVELOPE_DIGEST)
        self.assertEqual(result["signingMessageDigest"], KNOWN_SIGNING_MESSAGE_DIGEST)
        self.assertEqual(envelope.__all__, (
            "PreparationArtifactEnvelopeRefused",
            "canonical_envelope",
            "decode",
        ))
        self.assertEqual(envelope.ROLES, ALLOWED_ROLES)
        self.assertIsInstance(envelope.ROLES, tuple)
        self.assertEqual(envelope.ID_PATTERN, ID_PATTERN)
        with self.assertRaises(TypeError):
            result["role"] = "vendor-build"
        for forbidden in (
            "admitted",
            "artifactRaw",
            "authenticated",
            "envelopeRaw",
            "expiresAt",
            "message",
            "payload",
            "privateKey",
            "publicKey",
            "quorumAccepted",
            "replayAccepted",
            "signature",
            "signatureVerified",
            "trusted",
        ):
            self.assertNotIn(forbidden, RESULT_FIELDS)
            self.assertNotIn(forbidden, result)
            self.assertFalse(hasattr(result, forbidden))

    def test_envelope_json_is_exact_canonical_ascii_with_one_lf_and_bounded(self):
        document = fixture()
        raw = canonical(document)
        variants = (
            raw[:-1],
            raw + b"\n",
            b"\xef\xbb\xbf" + raw,
            json.dumps(document).encode("ascii"),
            raw.replace(b'"algorithm":"ed25519",', b' "algorithm": "ed25519",'),
            b'{"role":"release-source","role":"release-source"}\n',
            b'{"version":NaN}\n',
            b"[]\n",
            b"null\n",
            b"1\n",
            b"{" + b" " * MAX_ENVELOPE_BYTES + b"}\n",
            "not-bytes",
            bytearray(raw),
        )
        for candidate in variants:
            with self.subTest(candidate=repr(candidate)[:48]):
                self.assert_refused(candidate)

    def test_envelope_schema_is_closed_and_types_are_strict(self):
        document = fixture()
        for key in tuple(document):
            changed = copy.deepcopy(document)
            del changed[key]
            with self.subTest(missing=key):
                self.assert_refused(changed)
        changed = copy.deepcopy(document)
        changed["issuedAt"] = "2026-09-07T02:03:04Z"
        self.assert_refused(changed)
        for key in ("version", "keyGeneration", "trustGeneration"):
            for bad in (True, 1.0, "1", None):
                changed = copy.deepcopy(document)
                changed[key] = bad
                with self.subTest(key=key, bad=bad):
                    self.assert_refused(changed)

    def test_role_is_selected_by_trusted_expected_role_and_domain_is_not_serialized(self):
        for role in ALLOWED_ROLES:
            document = fixture(role)
            result = envelope.decode(canonical(document), OPAQUE_ARTIFACT, expected_role=role)
            expected_message = DOMAINS[role] + b"\0" + OPAQUE_ARTIFACT
            self.assertIs(type(result), MappingProxyType)
            self.assertEqual(
                result["signingMessageDigest"],
                hashlib.sha256(expected_message).hexdigest(),
            )

        document = fixture()
        for wrong in ("vendor-build", "Release-Source", "tls-authority", True, None):
            with self.subTest(expected_role=wrong):
                self.assert_refused(document, expected_role=wrong)
        changed = copy.deepcopy(document)
        changed["role"] = "vendor-build"
        self.assert_refused(changed, expected_role="release-source")
        changed = copy.deepcopy(document)
        changed["domain"] = "oncam.checkout.release-source.v1"
        self.assert_refused(changed)

    def test_ids_generations_digest_and_signature_are_strictly_bounded(self):
        document = fixture()
        for identifier in ("a", "a" + "-" * 63):
            changed = copy.deepcopy(document)
            changed["issuerId"] = identifier
            changed["keyId"] = identifier
            envelope.decode(canonical(changed), OPAQUE_ARTIFACT, expected_role="release-source")
        mutations = (
            ("issuerId", ""),
            ("issuerId", "a" * 65),
            ("issuerId", "../issuer"),
            ("issuerId", "Issuer-01"),
            ("issuerId", "issuer id"),
            ("keyId", ""),
            ("keyId", "a" * 65),
            ("keyId", "key/id"),
            ("keyGeneration", 0),
            ("trustGeneration", 0),
            ("keyGeneration", 2**63),
            ("trustGeneration", 2**63),
            ("artifactDigest", "A" * 64),
            ("artifactDigest", "0" * 63),
            ("signature", "A" * 128),
            ("signature", "0" * 126),
            ("signature", "gg" * 64),
            ("algorithm", "Ed25519"),
            ("algorithm", "rsa"),
            ("version", 2),
        )
        for key, bad in mutations:
            changed = copy.deepcopy(document)
            changed[key] = bad
            with self.subTest(key=key, bad=bad):
                self.assert_refused(changed)

    def test_artifact_is_opaque_bounded_bytes_and_digest_must_match(self):
        self.assertEqual(envelope.MAX_ARTIFACT_BYTES, MAX_ARTIFACT_BYTES)
        self.assertEqual(envelope.MAX_ENVELOPE_BYTES, MAX_ENVELOPE_BYTES)
        malformed_json_artifact = b"\x00\xff{not-json\r\n\x80"
        document = fixture(artifact_raw=malformed_json_artifact)
        result = envelope.decode(
            canonical(document), malformed_json_artifact, expected_role="release-source"
        )
        self.assertIs(type(result), MappingProxyType)
        self.assertEqual(
            result["artifactDigest"],
            hashlib.sha256(malformed_json_artifact).hexdigest(),
        )

        for invalid in (b"", "not-bytes", bytearray(OPAQUE_ARTIFACT)):
            self.assert_refused(document, invalid)
        self.assert_refused(fixture(), OPAQUE_ARTIFACT + b"tampered")

        exact_cap = b"x" * MAX_ARTIFACT_BYTES
        capped_document = fixture(artifact_raw=exact_cap)
        envelope.decode(canonical(capped_document), exact_cap, expected_role="release-source")
        self.assert_refused(capped_document, exact_cap + b"x")

    def test_signature_is_shape_only_and_cannot_promote_trust(self):
        document = fixture()
        changed = copy.deepcopy(document)
        changed["signature"] = "8" * 128
        original = envelope.decode(canonical(document), OPAQUE_ARTIFACT, expected_role="release-source")
        result = envelope.decode(canonical(changed), OPAQUE_ARTIFACT, expected_role="release-source")
        self.assertIs(type(original), MappingProxyType)
        self.assertIs(type(result), MappingProxyType)
        self.assertNotEqual(result["envelopeDigest"], original["envelopeDigest"])
        self.assertEqual(
            result["signingMessageDigest"], original["signingMessageDigest"]
        )
        self.assertIs(result["structuralOnly"], True)
        for name in ("authenticated", "quorumAccepted", "signatureVerified", "trusted"):
            self.assertFalse(hasattr(result, name))

    def test_dependency_replacement_refuses_before_replacement_is_called(self):
        raw = canonical(fixture())
        dependencies = (
            (envelope.json, "loads", {}),
            (envelope.json, "dumps", "{}"),
            (envelope.hashlib, "sha256", None),
            (envelope.re, "fullmatch", None),
        )
        for module, name, replacement_result in dependencies:
            original = getattr(module, name)
            called = []

            def replacement(*_args, value=replacement_result, **_kwargs):
                called.append(True)
                return value

            try:
                setattr(module, name, replacement)
                with self.subTest(dependency=f"{module.__name__}.{name}"):
                    self.assert_refused(raw)
                    self.assertEqual(called, [])
            finally:
                setattr(module, name, original)

    def test_hostile_callable_replacement_is_rejected_without_metadata_or_call_access(self):
        raw = canonical(fixture())
        accesses = []

        class HostileCallable:
            def __call__(self, *_args, **_kwargs):
                accesses.append("call")
                return {}

            def __getattribute__(self, name):
                if name not in {"__class__", "__dict__"}:
                    accesses.append(f"get:{name}")
                return object.__getattribute__(self, name)

        original = envelope.json.loads
        try:
            envelope.json.loads = HostileCallable()
            with self.assertRaises(envelope.PreparationArtifactEnvelopeRefused):
                envelope.decode(raw, OPAQUE_ARTIFACT, expected_role="release-source")
            self.assertEqual(accesses, [])
        finally:
            envelope.json.loads = original

    def test_in_place_callable_kwdefaults_mutation_refuses_before_injected_decoder(self):
        raw = canonical(fixture())
        function = envelope.json.loads
        kwdefaults = function.__kwdefaults__
        self.assertIsInstance(kwdefaults, dict)
        original_items = tuple(kwdefaults.items())
        injected_calls = []

        def injected_decoder(*_args, **_kwargs):
            injected_calls.append(True)
            raise RuntimeError("must not execute")

        try:
            kwdefaults["cls"] = injected_decoder
            with self.assertRaises(envelope.PreparationArtifactEnvelopeRefused):
                envelope.decode(raw, OPAQUE_ARTIFACT, expected_role="release-source")
            self.assertEqual(injected_calls, [])
        finally:
            kwdefaults.clear()
            kwdefaults.update(original_items)

    def test_module_and_structural_authority_rebinding_refuses_before_use(self):
        raw = canonical(fixture())
        original_refusal = envelope.PreparationArtifactEnvelopeRefused
        cases = (
            ("ROLES", tuple(list(envelope.ROLES))),
            ("ROLES", envelope.ROLES + ("tls-authority",)),
            ("MAX_ENVELOPE_BYTES", MAX_ENVELOPE_BYTES + 1),
            ("MAX_ARTIFACT_BYTES", MAX_ARTIFACT_BYTES + 1),
            ("ID_PATTERN", r".*"),
            ("PreparationArtifactEnvelopeRefused", Exception),
            ("json", object()),
            ("hashlib", object()),
            ("re", object()),
            ("__all__", tuple(list(envelope.__all__))),
        )
        for name, replacement in cases:
            original = getattr(envelope, name)
            self.assertIsNot(replacement, original)
            try:
                setattr(envelope, name, replacement)
                with self.subTest(authority=name):
                    with self.assertRaises(original_refusal):
                        envelope.decode(raw, OPAQUE_ARTIFACT, expected_role="release-source")
            finally:
                setattr(envelope, name, original)

        self.assertIs(envelope.PreparationArtifactEnvelopeRefused, original_refusal)

    def test_callable_metadata_drift_refuses_before_execution(self):
        raw = canonical(fixture())
        original_refusal = envelope.PreparationArtifactEnvelopeRefused
        for function in (envelope.json.loads, envelope.json.dumps, envelope.re.fullmatch):
            original_code = function.__code__
            try:
                function.__code__ = original_code.replace()
                with self.subTest(metadata=function.__name__):
                    with self.assertRaises(original_refusal):
                        envelope.decode(raw, OPAQUE_ARTIFACT, expected_role="release-source")
            finally:
                function.__code__ = original_code

    def test_result_is_builtin_immutable_mapping_without_claim_extension_surface(self):
        result = envelope.decode(
            canonical(fixture()),
            OPAQUE_ARTIFACT,
            expected_role="release-source",
        )

        self.assertIs(type(result), MappingProxyType)
        self.assertEqual(tuple(result.keys()), RESULT_FIELDS)
        with self.assertRaises(TypeError):
            result["authenticated"] = True
        for name in ("authenticated", "trusted"):
            self.assertNotIn(name, result)
            self.assertFalse(hasattr(result, name))
        for name in ("__getattr__", "__getattribute__"):
            with self.subTest(type_attribute=name):
                with self.assertRaises((TypeError, AttributeError)):
                    setattr(type(result), name, lambda *_args: True)
        self.assertEqual(result["role"], "release-source")
        self.assertNotIn("authenticated", result)
        self.assertNotIn("trusted", result)

    def test_hostile_algorithm_subclass_is_not_accepted_as_literal_algorithm(self):
        class HostileAlgorithm(str):
            def __ne__(self, _other):
                return False

        changed = fixture()
        changed["algorithm"] = HostileAlgorithm("rsa")
        with self.assertRaises(envelope.PreparationArtifactEnvelopeRefused):
            envelope.canonical_envelope(changed)

    def test_hostile_dict_key_subclass_refuses_without_magic_access(self):
        accesses = []

        class HostileKey(str):
            def __hash__(self):
                accesses.append("hash")
                return super().__hash__()

            def __eq__(self, other):
                accesses.append("eq")
                return super().__eq__(other)

        hostile_key = HostileKey("algorithm")
        hostile = fixture()
        value = hostile.pop("algorithm")
        hostile[hostile_key] = value
        accesses.clear()
        with self.assertRaises(envelope.PreparationArtifactEnvelopeRefused):
            envelope.canonical_envelope(hostile)
        self.assertEqual(accesses, [])

    def test_private_guard_preserves_baseexception_and_detects_post_operation_drift(self):
        invoke = next(
            cell.cell_contents
            for cell in envelope.decode.__closure__
            if callable(cell.cell_contents)
            and getattr(cell.cell_contents, "__name__", "") == "invoke"
        )
        for primary in (KeyboardInterrupt("k"), SystemExit("s")):
            def stop(error=primary):
                raise error

            with self.assertRaises(type(primary)) as raised:
                invoke(stop)
            self.assertIs(raised.exception, primary)

        original_roles = envelope.ROLES
        replacement_roles = tuple(list(original_roles))
        for primary in (KeyboardInterrupt("drift-k"), SystemExit("drift-s")):
            def drift_then_stop(error=primary):
                envelope.ROLES = replacement_roles
                raise error

            try:
                with self.assertRaises(type(primary)) as raised:
                    invoke(drift_then_stop)
                self.assertIs(raised.exception, primary)
            finally:
                envelope.ROLES = original_roles

        def drift_after_precheck():
            envelope.ROLES = replacement_roles
            return None

        try:
            with self.assertRaises(envelope.PreparationArtifactEnvelopeRefused):
                invoke(drift_after_precheck)
        finally:
            envelope.ROLES = original_roles

        original_loads = envelope.json.loads
        replacement_calls = []

        def replacement_loads(*_args, **_kwargs):
            replacement_calls.append(True)
            return {}

        def drift_dependency_after_precheck():
            envelope.json.loads = replacement_loads
            return None

        try:
            with self.assertRaises(envelope.PreparationArtifactEnvelopeRefused):
                invoke(drift_dependency_after_precheck)
            self.assertEqual(replacement_calls, [])
        finally:
            envelope.json.loads = original_loads

    def test_output_is_deterministic_and_no_signing_verification_or_quorum_api_exists(self):
        document = fixture()
        reordered = dict(reversed(tuple(document.items())))
        # A composition caller must itself pin the exported callables. Rebinding
        # decode/canonical_envelope is outside this structural codec's authority.
        self.assertEqual(envelope.canonical_envelope(document), canonical(document))
        self.assertEqual(envelope.canonical_envelope(reordered), canonical(document))
        for forbidden in (
            "sign",
            "sign_artifact",
            "verify",
            "verify_signature",
            "load_public_key",
            "load_private_key",
            "threshold",
            "quorum",
            "admit",
        ):
            self.assertFalse(hasattr(envelope, forbidden))


if __name__ == "__main__":
    unittest.main()
