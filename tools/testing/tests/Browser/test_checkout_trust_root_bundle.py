import copy
import hashlib
import importlib.util
import json
from pathlib import Path
from types import MappingProxyType
import unittest


MODULE_PATH = Path(__file__).with_name("checkout-trust-root-bundle.py")
SPEC = importlib.util.spec_from_file_location("checkout_trust_root_bundle", MODULE_PATH)
bundle = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(bundle)


MAX_BUNDLE_BYTES = 64 * 1024
ARTIFACT_ROLES = (
    "asset-review",
    "composition-admission",
    "preparation-authorization",
    "release-source",
    "runtime-configuration-policy",
    "tool-runtime-closure",
    "vendor-build",
)


def canonical(value):
    return (json.dumps(value, sort_keys=True, separators=(",", ":"),
                       ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")


def key(role, suffix, material):
    return {
        "issuerId": f"{suffix}-issuer",
        "keyGeneration": 1,
        "keyId": f"{suffix}-key",
        "publicKey": material * 64,
        "role": role,
    }


def custodian(suffix, material):
    return {
        "custodianId": f"{suffix}-custodian",
        "keyGeneration": 1,
        "keyId": f"{suffix}-key",
        "publicKey": material * 64,
    }


def fixture(rotation=None):
    artifact_keys = [
        key("asset-review", "asset-review-a", "1"),
        key("asset-review", "asset-review-b", "2"),
        key("composition-admission", "composition", "3"),
        key("preparation-authorization", "preparation", "4"),
        key("release-source", "release", "5"),
        key("runtime-configuration-policy", "runtime-policy", "6"),
        key("tool-runtime-closure", "tool-runtime", "7"),
        key("vendor-build", "vendor", "8"),
    ]
    roots = [
        custodian("offline-root-a", "9"),
        custodian("offline-root-b", "a"),
        custodian("offline-root-c", "b"),
    ]
    revocation = [
        custodian("revocation-a", "c"),
        custodian("revocation-b", "d"),
        custodian("revocation-c", "e"),
    ]
    return {
        "artifactId": "trust-bundle-07",
        "artifactIssuerKeys": artifact_keys,
        "expiresAt": "2027-09-07T04:05:06Z",
        "issuedAt": "2026-09-07T04:05:06Z",
        "issuerId": "offline-root-ceremony",
        "offlineRootCustodians": roots,
        "replayId": "trust-bundle-replay-07",
        "revocationCustodians": revocation,
        "role": "trust-root-bundle",
        "rotation": rotation,
        "trustGeneration": 1 if rotation is None else 7,
        "version": 1,
    }


def rotation_fixture():
    return {
        "newRootSignatureEnvelopes": [
            {"envelopeDigest": "1" * 64, "keyId": "offline-root-a-key"},
            {"envelopeDigest": "2" * 64, "keyId": "offline-root-b-key"},
        ],
        "newTrustGeneration": 7,
        "oldRootSignatureEnvelopes": [
            {"envelopeDigest": "3" * 64, "keyId": "prior-root-a-key"},
            {"envelopeDigest": "4" * 64, "keyId": "prior-root-c-key"},
        ],
        "priorBundleDigest": "f" * 64,
        "priorTrustGeneration": 6,
    }


class TrustRootBundleTests(unittest.TestCase):
    def decode(self, document=None):
        value = fixture() if document is None else document
        raw = canonical(value) if type(value) is dict else value
        return bundle.decode(raw)

    def assert_refused(self, document):
        with self.assertRaisesRegex(bundle.TrustRootBundleRefused,
                                    "^trust_root_bundle$"):
            self.decode(document)

    def test_known_vector_is_exact_deeply_immutable_and_structural_only(self):
        document = fixture()
        raw = canonical(document)
        result = self.decode(document)
        self.assertIs(type(result), type(MappingProxyType({})))
        self.assertIs(result["structuralOnly"], True)
        self.assertEqual(result["role"], "trust-root-bundle")
        self.assertEqual(result["trustGeneration"], 1)
        self.assertEqual(len(result["artifactIssuerKeys"]), 8)
        self.assertEqual(len(result["offlineRootCustodians"]), 3)
        self.assertEqual(len(result["revocationCustodians"]), 3)
        self.assertIsNone(result["rotation"])
        self.assertEqual(result["artifactDigest"], hashlib.sha256(raw).hexdigest())
        self.assertIs(type(result["artifactIssuerKeys"]), tuple)
        self.assertIs(type(result["artifactIssuerKeys"][0]), tuple)
        with self.assertRaises(TypeError):
            result["trusted"] = True
        for forbidden in ("accepted", "authenticated", "current", "fresh",
                          "quorumAccepted", "replayAccepted", "signatureVerified"):
            self.assertNotIn(forbidden, result)

    def test_schema_is_exact_closed_and_strict(self):
        document = fixture()
        for field in tuple(document):
            changed = copy.deepcopy(document)
            del changed[field]
            with self.subTest(missing=field):
                self.assert_refused(changed)
        changed = copy.deepcopy(document)
        changed["trusted"] = True
        self.assert_refused(changed)
        for field in ("version", "trustGeneration"):
            for bad in (True, 1.0, "1", None, 0, 1 << 63):
                changed = copy.deepcopy(document)
                changed[field] = bad
                with self.subTest(field=field, bad=bad):
                    self.assert_refused(changed)

    def test_canonical_ascii_sorted_json_one_lf_duplicate_nonfinite_and_size(self):
        document = fixture()
        raw = canonical(document)
        self.assertEqual(bundle.canonical_bundle(document), raw)
        invalid = (
            raw[:-1], raw + b"\n", json.dumps(document).encode("ascii"),
            b"\xef\xbb\xbf" + raw, "not-bytes", bytearray(raw),
            b'{"role":"trust-root-bundle","role":"trust-root-bundle"}\n',
            b'{"trustGeneration":NaN}\n',
            b"{" + b" " * MAX_BUNDLE_BYTES + b"}\n",
        )
        for candidate in invalid:
            with self.subTest(candidate=repr(candidate)[:50]):
                self.assert_refused(candidate)

    def test_artifact_identity_role_replay_and_timestamps_are_exact(self):
        for field, values in {
            "role": ("release-source", True),
            "issuerId": ("", "../issuer", "A", True),
            "artifactId": ("", "../artifact", "A", True),
            "replayId": ("", "../replay", "A", True),
            "issuedAt": ("2026-09-07T04:05:06+00:00", "2026-02-29T00:00:00Z"),
            "expiresAt": ("2026-09-07T04:05:06Z", "2026-09-07T04:05:05Z"),
        }.items():
            for bad in values:
                changed = copy.deepcopy(fixture())
                changed[field] = bad
                with self.subTest(field=field, bad=bad):
                    self.assert_refused(changed)

    def test_artifact_roles_and_asset_review_two_of_two_are_exact_ordered(self):
        result = self.decode()
        self.assertEqual(tuple(item[0] for item in result["artifactIssuerKeys"]),
                         ("asset-review", "asset-review") + ARTIFACT_ROLES[1:])
        cases = []
        missing = fixture(); missing["artifactIssuerKeys"].pop(0); cases.append(missing)
        extra = fixture(); extra["artifactIssuerKeys"].append(key("vendor-build", "extra", "f")); cases.append(extra)
        swapped = fixture(); swapped["artifactIssuerKeys"][0:2] = reversed(swapped["artifactIssuerKeys"][0:2]); cases.append(swapped)
        wrong = fixture(); wrong["artifactIssuerKeys"][2]["role"] = "asset-review"; cases.append(wrong)
        for changed in cases:
            self.assert_refused(changed)

    def test_key_records_are_closed_and_ed25519_material_is_exact(self):
        for container, index in (("artifactIssuerKeys", 0),
                                 ("offlineRootCustodians", 0),
                                 ("revocationCustodians", 0)):
            for field, bad in (("publicKey", "a" * 63),
                               ("publicKey", "A" * 64),
                               ("keyGeneration", True),
                               ("keyGeneration", 0),
                               ("keyId", "../key")):
                changed = copy.deepcopy(fixture())
                changed[container][index][field] = bad
                with self.subTest(container=container, field=field, bad=bad):
                    self.assert_refused(changed)
            changed = copy.deepcopy(fixture())
            changed[container][index]["privateKey"] = "secret"
            self.assert_refused(changed)

    def test_all_key_ids_and_public_material_are_globally_distinct(self):
        document = fixture()
        cases = []
        duplicate_id = copy.deepcopy(document)
        duplicate_id["revocationCustodians"][0]["keyId"] = document["artifactIssuerKeys"][0]["keyId"]
        cases.append(duplicate_id)
        duplicate_material = copy.deepcopy(document)
        duplicate_material["offlineRootCustodians"][0]["publicKey"] = document["artifactIssuerKeys"][0]["publicKey"]
        cases.append(duplicate_material)
        duplicate_issuer = copy.deepcopy(document)
        duplicate_issuer["revocationCustodians"][0]["custodianId"] = document["artifactIssuerKeys"][0]["issuerId"]
        cases.append(duplicate_issuer)
        for changed in cases:
            self.assert_refused(changed)

    def test_offline_roots_and_revocation_custodians_are_exact_three_and_ordered(self):
        for field in ("offlineRootCustodians", "revocationCustodians"):
            for mutation in ("missing", "extra", "swapped"):
                changed = copy.deepcopy(fixture())
                if mutation == "missing":
                    changed[field].pop()
                elif mutation == "extra":
                    changed[field].append(custodian("extra", "f"))
                else:
                    changed[field][0], changed[field][1] = changed[field][1], changed[field][0]
                with self.subTest(field=field, mutation=mutation):
                    self.assert_refused(changed)

    def test_rotation_binds_prior_new_generation_and_exact_two_old_two_new_refs(self):
        document = fixture(rotation_fixture())
        result = self.decode(document)
        self.assertEqual(result["rotation"][0:2], (6, 7))
        for mutate in (
            lambda r: r.update(priorTrustGeneration=7),
            lambda r: r.update(newTrustGeneration=8),
            lambda r: r["oldRootSignatureEnvelopes"].pop(),
            lambda r: r["newRootSignatureEnvelopes"].append(
                {"envelopeDigest": "5" * 64, "keyId": "offline-root-c-key"}),
            lambda r: r["newRootSignatureEnvelopes"][0].update(keyId="prior-root-a-key"),
            lambda r: r["oldRootSignatureEnvelopes"][1].update(keyId="prior-root-a-key"),
        ):
            changed = fixture(rotation_fixture())
            mutate(changed["rotation"])
            self.assert_refused(changed)

    def test_rotation_schema_refs_and_digest_are_closed_strict_and_ordered(self):
        changed = fixture(rotation_fixture())
        changed["rotation"]["verified"] = True
        self.assert_refused(changed)
        for group in ("oldRootSignatureEnvelopes", "newRootSignatureEnvelopes"):
            changed = fixture(rotation_fixture())
            changed["rotation"][group][0]["signature"] = "0" * 128
            self.assert_refused(changed)
            changed = fixture(rotation_fixture())
            changed["rotation"][group].reverse()
            self.assert_refused(changed)

    def test_rotation_envelope_digests_are_distinct_within_and_across_sets(self):
        cases = []

        duplicate_old = fixture(rotation_fixture())
        duplicate_old["rotation"]["oldRootSignatureEnvelopes"][1][
            "envelopeDigest"
        ] = duplicate_old["rotation"]["oldRootSignatureEnvelopes"][0][
            "envelopeDigest"
        ]
        cases.append(duplicate_old)

        duplicate_new = fixture(rotation_fixture())
        duplicate_new["rotation"]["newRootSignatureEnvelopes"][1][
            "envelopeDigest"
        ] = duplicate_new["rotation"]["newRootSignatureEnvelopes"][0][
            "envelopeDigest"
        ]
        cases.append(duplicate_new)

        reused_across_sets = fixture(rotation_fixture())
        reused_across_sets["rotation"]["newRootSignatureEnvelopes"][0][
            "envelopeDigest"
        ] = reused_across_sets["rotation"]["oldRootSignatureEnvelopes"][0][
            "envelopeDigest"
        ]
        cases.append(reused_across_sets)

        for index, changed in enumerate(cases):
            with self.subTest(case=index):
                self.assert_refused(changed)

    def test_rotation_is_structural_only_and_bootstrap_null_is_allowed(self):
        self.assertIsNone(self.decode()["rotation"])
        result = self.decode(fixture(rotation_fixture()))
        for forbidden in ("oldThresholdVerified", "newThresholdVerified",
                          "rotationAccepted", "signatureVerified"):
            self.assertNotIn(forbidden, result)

        missing_rotation = fixture()
        missing_rotation["trustGeneration"] = 2
        self.assert_refused(missing_rotation)
        unexpected_rotation = fixture(rotation_fixture())
        unexpected_rotation["trustGeneration"] = 1
        unexpected_rotation["rotation"]["newTrustGeneration"] = 1
        self.assert_refused(unexpected_rotation)

    def test_output_and_public_surface_do_not_offer_crypto_or_admission(self):
        self.assertEqual(bundle.__all__, (
            "TrustRootBundleRefused", "canonical_bundle", "decode",
        ))
        for forbidden in ("accept", "admit", "bootstrap", "consume", "is_current",
                          "rotate", "verify", "verify_signature"):
            self.assertFalse(hasattr(bundle, forbidden))

    def test_dependency_and_module_authority_mutation_fail_closed(self):
        raw = canonical(fixture())
        original_refusal = bundle.TrustRootBundleRefused
        replacements = (
            (bundle, "MAX_BUNDLE_BYTES", MAX_BUNDLE_BYTES + 1),
            (bundle, "ARTIFACT_ROLES", tuple(list(bundle.ARTIFACT_ROLES))),
            (bundle, "ROLE", "release-source"),
            (bundle, "MappingProxyType", dict),
            (bundle, "json", object()),
            (bundle, "hashlib", object()),
            (bundle, "re", object()),
            (bundle, "datetime", object()),
            (bundle, "TrustRootBundleRefused", Exception),
            (bundle, "__all__", tuple(list(bundle.__all__))),
        )
        for owner, name, replacement in replacements:
            original = getattr(owner, name)
            try:
                setattr(owner, name, replacement)
                with self.subTest(authority=name):
                    with self.assertRaises(original_refusal):
                        bundle.decode(raw)
            finally:
                setattr(owner, name, original)

        for owner, name, replacement in (
            (bundle.json, "dumps", lambda *_a, **_k: "{}"),
            (bundle.json, "loads", lambda *_a, **_k: {}),
            (bundle.hashlib, "sha256", lambda *_a, **_k: None),
            (bundle.re, "fullmatch", lambda *_a, **_k: None),
        ):
            original = getattr(owner, name)
            try:
                setattr(owner, name, replacement)
                with self.subTest(dependency=f"{owner.__name__}.{name}"):
                    with self.assertRaises(original_refusal):
                        bundle.decode(raw)
            finally:
                setattr(owner, name, original)

    def test_replaced_dependency_cannot_execute_base_exception_payload(self):
        raw = canonical(fixture())
        original = bundle.json.loads
        for error in (KeyboardInterrupt(), SystemExit(9)):
            try:
                def explode(*_args, thrown=error, **_kwargs):
                    raise thrown
                bundle.json.loads = explode
                with self.subTest(error=type(error).__name__):
                    with self.assertRaises(bundle.TrustRootBundleRefused):
                        bundle.decode(raw)
            finally:
                bundle.json.loads = original


if __name__ == "__main__":
    unittest.main()
