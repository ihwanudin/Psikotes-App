import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import unittest


MODULE_PATH = Path(__file__).with_name("checkout-revocation-snapshot.py")
SPEC = importlib.util.spec_from_file_location("checkout_revocation_snapshot", MODULE_PATH)
snapshot = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(snapshot)


MAX_SNAPSHOT_BYTES = 256 * 1024
MAX_REVOKED_ENTRIES = 4096
AUTHORITY_ROLES = (
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
    "authorityRole",
    "issuerId",
    "issuerKeyGeneration",
    "revocationTrustGeneration",
    "generation",
    "issuedAt",
    "nextUpdate",
    "notBefore",
    "revokedArtifactIds",
    "revokedArtifactDigests",
    "revokedKeyGenerations",
    "snapshotDigest",
)


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


def fixture():
    return {
        "authorityRole": "release-source",
        "generation": 8,
        "issuedAt": "2026-09-07T03:04:05Z",
        "issuerId": "release-custodian-01",
        "issuerKeyGeneration": 3,
        "nextUpdate": "2026-09-08T03:04:05Z",
        "notBefore": "2026-09-07T00:00:00Z",
        "revocationTrustGeneration": 5,
        "revokedArtifactDigests": ["1" * 64, "a" * 64],
        "revokedArtifactIds": ["artifact-01", "artifact-02"],
        "revokedKeyGenerations": [1, 2],
        "version": 1,
    }


EXPECTED_NAMESPACE = {
    "expected_role": "release-source",
    "expected_issuer_id": "release-custodian-01",
    "expected_issuer_key_generation": 3,
    "expected_revocation_trust_generation": 5,
}


class RevocationSnapshotTests(unittest.TestCase):
    def decode(self, document=None, **overrides):
        namespace = dict(EXPECTED_NAMESPACE)
        namespace.update(overrides)
        value = fixture() if document is None else document
        raw = canonical(value) if type(value) is dict else value
        return snapshot.decode(raw, **namespace)

    def assert_refused(self, document=None, **overrides):
        with self.assertRaisesRegex(
            snapshot.RevocationSnapshotRefused,
            "^revocation_snapshot$",
        ):
            self.decode(document, **overrides)

    def test_known_vector_is_exact_immutable_and_structural_only(self):
        document = fixture()
        raw = canonical(document)
        result = self.decode(document)

        self.assertIs(type(result), type(snapshot.MappingProxyType({})))
        self.assertEqual(tuple(result), RESULT_FIELDS)
        self.assertIs(result["structuralOnly"], True)
        self.assertEqual(result["authorityRole"], "release-source")
        self.assertEqual(result["generation"], 8)
        self.assertEqual(result["revokedArtifactIds"], ("artifact-01", "artifact-02"))
        self.assertEqual(result["revokedArtifactDigests"], ("1" * 64, "a" * 64))
        self.assertEqual(result["revokedKeyGenerations"], (1, 2))
        self.assertEqual(result["snapshotDigest"], hashlib.sha256(raw).hexdigest())
        with self.assertRaises(TypeError):
            result["accepted"] = True
        for forbidden in (
            "authenticated",
            "current",
            "fresh",
            "quorumAccepted",
            "replayAccepted",
            "rollbackChecked",
            "signatureVerified",
            "stale",
            "trusted",
        ):
            self.assertNotIn(forbidden, result)

    def test_schema_is_closed_strict_and_duplicate_json_is_rejected(self):
        document = fixture()
        for key in tuple(document):
            changed = copy.deepcopy(document)
            del changed[key]
            with self.subTest(missing=key):
                self.assert_refused(changed)
        changed = copy.deepcopy(document)
        changed["accepted"] = True
        self.assert_refused(changed)
        for key in (
            "version",
            "generation",
            "issuerKeyGeneration",
            "revocationTrustGeneration",
        ):
            for bad in (True, 1.0, "1", None):
                changed = copy.deepcopy(document)
                changed[key] = bad
                with self.subTest(key=key, bad=bad):
                    self.assert_refused(changed)
        self.assert_refused(
            b'{"authorityRole":"release-source","authorityRole":"release-source"}\n'
        )
        self.assert_refused(b'{"generation":NaN}\n')

    def test_canonical_ascii_sorted_json_one_lf_and_size_bound(self):
        document = fixture()
        raw = canonical(document)
        self.assertEqual(snapshot.canonical_snapshot(document), raw)
        for invalid in (
            raw[:-1],
            raw + b"\n",
            b"\xef\xbb\xbf" + raw,
            json.dumps(document).encode("ascii"),
            raw.replace(b'"authorityRole":', b' "authorityRole": '),
            "not-bytes",
            bytearray(raw),
            b"{" + b" " * MAX_SNAPSHOT_BYTES + b"}\n",
        ):
            with self.subTest(candidate=repr(invalid)[:50]):
                self.assert_refused(invalid)

    def test_namespace_is_exact_and_selected_by_trusted_arguments(self):
        cases = (
            ("expected_role", "vendor-build"),
            ("expected_role", "Release-Source"),
            ("expected_role", True),
            ("expected_issuer_id", "other-issuer"),
            ("expected_issuer_id", True),
            ("expected_issuer_key_generation", 4),
            ("expected_issuer_key_generation", True),
            ("expected_revocation_trust_generation", 6),
            ("expected_revocation_trust_generation", True),
        )
        for name, value in cases:
            with self.subTest(argument=name, value=value):
                self.assert_refused(**{name: value})

        for role in AUTHORITY_ROLES:
            document = fixture()
            document["authorityRole"] = role
            self.decode(document, expected_role=role)

    def test_utc_timestamps_are_canonical_and_window_is_positive_at_most_24_hours(self):
        invalid_timestamps = (
            "2026-09-07T03:04:05+00:00",
            "2026-09-07t03:04:05Z",
            "2026-09-07T03:04:05.000Z",
            "2026-09-07T03:04:60Z",
            "2026-02-29T03:04:05Z",
            "2026-09-07T24:00:00Z",
            "２０２６-09-07T03:04:05Z",
        )
        for key in ("issuedAt", "nextUpdate", "notBefore"):
            for value in invalid_timestamps:
                changed = copy.deepcopy(fixture())
                changed[key] = value
                with self.subTest(key=key, value=value):
                    self.assert_refused(changed)

        for next_update in (
            "2026-09-07T03:04:05Z",
            "2026-09-07T03:04:04Z",
            "2026-09-08T03:04:06Z",
        ):
            changed = copy.deepcopy(fixture())
            changed["nextUpdate"] = next_update
            with self.subTest(nextUpdate=next_update):
                self.assert_refused(changed)

        changed = copy.deepcopy(fixture())
        changed["nextUpdate"] = "2026-09-08T03:04:04Z"
        self.decode(changed)

    def test_not_before_cutoff_cannot_be_after_issuance(self):
        boundary = copy.deepcopy(fixture())
        boundary["notBefore"] = boundary["issuedAt"]
        self.decode(boundary)

        earlier = copy.deepcopy(fixture())
        earlier["notBefore"] = "2026-09-07T03:04:04Z"
        self.decode(earlier)

        for impossible in (
            "2026-09-07T03:04:06Z",
            "2026-09-08T03:04:05Z",
        ):
            changed = copy.deepcopy(fixture())
            changed["notBefore"] = impossible
            with self.subTest(notBefore=impossible):
                self.assert_refused(changed)

    def test_revocation_lists_are_exact_sorted_unique_and_jointly_bounded(self):
        empty = fixture()
        empty["revokedArtifactIds"] = []
        empty["revokedArtifactDigests"] = []
        empty["revokedKeyGenerations"] = []
        result = self.decode(empty)
        self.assertEqual(result["revokedArtifactIds"], ())
        self.assertEqual(result["revokedArtifactDigests"], ())
        self.assertEqual(result["revokedKeyGenerations"], ())

        cases = (
            ("revokedArtifactIds", ["artifact-02", "artifact-01"]),
            ("revokedArtifactIds", ["artifact-01", "artifact-01"]),
            ("revokedArtifactIds", ["../artifact"]),
            ("revokedArtifactIds", [True]),
            ("revokedArtifactDigests", ["a" * 64, "1" * 64]),
            ("revokedArtifactDigests", ["a" * 64, "a" * 64]),
            ("revokedArtifactDigests", ["A" * 64]),
            ("revokedArtifactDigests", [True]),
            ("revokedKeyGenerations", [2, 1]),
            ("revokedKeyGenerations", [1, 1]),
            ("revokedKeyGenerations", [0]),
            ("revokedKeyGenerations", [True]),
        )
        for key, value in cases:
            changed = copy.deepcopy(fixture())
            changed[key] = value
            with self.subTest(key=key, value=value):
                self.assert_refused(changed)

        changed = copy.deepcopy(fixture())
        changed["revokedArtifactIds"] = [f"a{i:04d}" for i in range(MAX_REVOKED_ENTRIES)]
        changed["revokedArtifactDigests"] = ["f" * 64]
        changed["revokedKeyGenerations"] = []
        self.assert_refused(changed)

    def test_identifiers_and_positive_int63_values_are_bounded(self):
        for key in ("issuerId",):
            for bad in ("", "A", "../issuer", "a" * 65, True):
                changed = copy.deepcopy(fixture())
                changed[key] = bad
                with self.subTest(key=key, bad=bad):
                    self.assert_refused(changed)
        for key in ("generation", "issuerKeyGeneration", "revocationTrustGeneration"):
            for bad in (0, 1 << 63, -1):
                changed = copy.deepcopy(fixture())
                changed[key] = bad
                with self.subTest(key=key, bad=bad):
                    self.assert_refused(changed)

    def test_dependency_and_module_authority_mutation_fail_closed(self):
        raw = canonical(fixture())
        original_refusal = snapshot.RevocationSnapshotRefused
        replacements = (
            (snapshot, "AUTHORITY_ROLES", tuple(list(snapshot.AUTHORITY_ROLES))),
            (snapshot, "MAX_SNAPSHOT_BYTES", MAX_SNAPSHOT_BYTES + 1),
            (snapshot, "MAX_REVOKED_ENTRIES", MAX_REVOKED_ENTRIES + 1),
            (snapshot, "ID_PATTERN", r".*"),
            (snapshot, "MappingProxyType", dict),
            (snapshot, "json", object()),
            (snapshot, "hashlib", object()),
            (snapshot, "re", object()),
            (snapshot, "datetime", object()),
            (snapshot, "RevocationSnapshotRefused", Exception),
            (snapshot, "__all__", tuple(list(snapshot.__all__))),
        )
        for owner, name, replacement in replacements:
            original = getattr(owner, name)
            try:
                setattr(owner, name, replacement)
                with self.subTest(authority=name):
                    with self.assertRaises(original_refusal):
                        snapshot.decode(raw, **EXPECTED_NAMESPACE)
            finally:
                setattr(owner, name, original)

        dependencies = (
            (snapshot.json, "dumps", lambda *_args, **_kwargs: "{}"),
            (snapshot.json, "loads", lambda *_args, **_kwargs: {}),
            (snapshot.hashlib, "sha256", lambda *_args, **_kwargs: None),
            (snapshot.re, "fullmatch", lambda *_args, **_kwargs: None),
            (snapshot.datetime, "datetime", object()),
            (snapshot.datetime, "timedelta", object()),
        )
        for owner, name, replacement in dependencies:
            original = getattr(owner, name)
            try:
                setattr(owner, name, replacement)
                with self.subTest(dependency=f"{owner.__name__}.{name}"):
                    with self.assertRaises(original_refusal):
                        snapshot.decode(raw, **EXPECTED_NAMESPACE)
            finally:
                setattr(owner, name, original)

    def test_output_does_not_expose_or_decide_signature_state_or_admission(self):
        result = self.decode()
        self.assertEqual(snapshot.__all__, (
            "RevocationSnapshotRefused",
            "canonical_snapshot",
            "decode",
        ))
        for forbidden_api in (
            "accept",
            "admit",
            "consume",
            "is_current",
            "load_highest_generation",
            "save_highest_generation",
            "verify",
            "verify_signature",
        ):
            self.assertFalse(hasattr(snapshot, forbidden_api))
        self.assertIs(result["structuralOnly"], True)


if __name__ == "__main__":
    unittest.main()
