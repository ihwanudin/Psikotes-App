import copy
import hashlib
import importlib.util
import json
from pathlib import Path
from types import MappingProxyType
import unittest


MODULE_PATH = Path(__file__).with_name("checkout-asset-review-artifact.py")
SPEC = importlib.util.spec_from_file_location("checkout_asset_review_artifact", MODULE_PATH)
artifact = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(artifact)


ROLE = "asset-review"
MAX_ARTIFACT_BYTES = 16 * 1024
ASSET_PATHS = (
    "public/css/checkout-summary-v1.css",
    "public/brand/oncam-logo-full-color.png",
    "public/js/checkout-confirmation-v1.js",
    "public/js/checkout-payment-v1.js",
    "resources/views/checkout/summary.blade.php",
    "tools/testing/tests/Browser/serve-checkout-session.php",
)
RESULT_FIELDS = (
    "structuralOnly",
    "role",
    "artifactId",
    "issuerId",
    "generation",
    "issuedAt",
    "expiresAt",
    "replayId",
    "releaseSourceArtifactDigest",
    "assetCount",
    "assetEntries",
    "artifactDigest",
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
        "artifactId": "asset-review-20260907.1",
        "assets": [
            {"digest": f"{index:x}" * 64, "path": path}
            for index, path in enumerate(ASSET_PATHS, start=1)
        ],
        "expiresAt": "2026-09-14T02:03:04Z",
        "generation": 9,
        "issuedAt": "2026-09-07T02:03:04Z",
        "issuerId": "asset-review-board-01",
        "releaseSourceArtifactDigest": "a" * 64,
        "replayId": "asset-review-20260907.1-attempt-01",
        "role": ROLE,
        "version": 1,
    }


class AssetReviewArtifactTests(unittest.TestCase):
    def assert_refused(self, value):
        raw = canonical(value) if type(value) is dict else value
        with self.assertRaisesRegex(
            artifact.AssetReviewArtifactRefused,
            "^asset_review_artifact$",
        ):
            artifact.decode(raw)

    def test_known_vector_is_canonical_immutable_and_structural_only(self):
        value = fixture()
        raw = canonical(value)
        result = artifact.decode(raw)

        self.assertEqual(artifact.canonical_artifact(value), raw)
        self.assertIs(type(result), MappingProxyType)
        self.assertEqual(tuple(result), RESULT_FIELDS)
        self.assertIs(result["structuralOnly"], True)
        self.assertEqual(result["role"], ROLE)
        self.assertEqual(result["assetCount"], 6)
        self.assertEqual(
            result["assetEntries"],
            tuple((path, f"{index:x}" * 64) for index, path in enumerate(ASSET_PATHS, 1)),
        )
        self.assertEqual(
            result["artifactDigest"],
            "f481b50088946c79fd575218e8f148e202644872179040115c27e76bad895514",
        )
        with self.assertRaises(TypeError):
            result["trusted"] = True
        for forbidden in (
            "authenticated",
            "current",
            "reviewers",
            "reviewerKeys",
            "signatureVerified",
            "thresholdAccepted",
            "trusted",
        ):
            self.assertNotIn(forbidden, result)

    def test_json_boundary_is_exact_ascii_one_lf_and_bounded(self):
        value = fixture()
        raw = canonical(value)
        for candidate in (
            raw[:-1],
            raw + b"\n",
            b"\xef\xbb\xbf" + raw,
            json.dumps(value).encode("ascii"),
            raw.replace(b'"artifactId":', b' "artifactId": '),
            b'{"version":1,"version":1}\n',
            b'{"version":NaN}\n',
            b"[]\n",
            b"null\n",
            "not-bytes",
            bytearray(raw),
            b"{" + b" " * MAX_ARTIFACT_BYTES + b"}\n",
        ):
            with self.subTest(candidate=repr(candidate)[:48]):
                self.assert_refused(candidate)

    def test_top_level_schema_identity_and_scalar_types_are_exact(self):
        value = fixture()
        for key in tuple(value):
            changed = copy.deepcopy(value)
            del changed[key]
            with self.subTest(missing=key):
                self.assert_refused(changed)
        changed = copy.deepcopy(value)
        changed["reviewerSignatures"] = []
        self.assert_refused(changed)
        for key, bad in (
            ("version", 2),
            ("version", True),
            ("role", "release-source"),
            ("role", True),
            ("artifactId", "Artifact"),
            ("artifactId", "a" * 65),
            ("issuerId", "../issuer"),
            ("replayId", "retry id"),
            ("generation", 0),
            ("generation", True),
            ("generation", 1.0),
            ("generation", 1 << 63),
        ):
            changed = copy.deepcopy(value)
            changed[key] = bad
            with self.subTest(key=key, bad=bad):
                self.assert_refused(changed)

    def test_lifecycle_is_canonical_positive_and_at_most_seven_days(self):
        value = fixture()
        valid = copy.deepcopy(value)
        valid["issuedAt"] = "2024-02-29T00:00:00Z"
        valid["expiresAt"] = "2024-03-07T00:00:00Z"
        artifact.decode(canonical(valid))
        for issued, expires in (
            ("2026-09-07T02:03:04Z", "2026-09-07T02:03:04Z"),
            ("2026-09-07T02:03:04Z", "2026-09-14T02:03:05Z"),
            ("2026-09-07T02:03:04+00:00", "2026-09-08T02:03:04Z"),
            ("2026-09-07t02:03:04Z", "2026-09-08T02:03:04Z"),
            ("2026-02-29T00:00:00Z", "2026-03-01T00:00:00Z"),
            ("2026-09-07T24:00:00Z", "2026-09-08T02:03:04Z"),
        ):
            changed = copy.deepcopy(value)
            changed["issuedAt"] = issued
            changed["expiresAt"] = expires
            with self.subTest(issued=issued, expires=expires):
                self.assert_refused(changed)

    def test_release_source_digest_is_exact_lowercase_sha256(self):
        for bad in ("A" * 64, "a" * 63, "g" * 64, True, None):
            changed = copy.deepcopy(fixture())
            changed["releaseSourceArtifactDigest"] = bad
            with self.subTest(bad=bad):
                self.assert_refused(changed)

    def test_assets_are_exact_ordered_six_path_digest_entries(self):
        value = fixture()
        variants = (
            [],
            value["assets"][:-1],
            list(reversed(value["assets"])),
            value["assets"] + [copy.deepcopy(value["assets"][0])],
        )
        for assets in variants:
            changed = copy.deepcopy(value)
            changed["assets"] = assets
            with self.subTest(count=len(assets)):
                self.assert_refused(changed)
        changed = copy.deepcopy(value)
        changed["assets"] = tuple(changed["assets"])
        with self.assertRaises(artifact.AssetReviewArtifactRefused):
            artifact.canonical_artifact(changed)
        for index, expected_path in enumerate(ASSET_PATHS):
            changed = copy.deepcopy(value)
            changed["assets"][index]["path"] = expected_path.upper()
            self.assert_refused(changed)

    def test_asset_entry_schema_and_digest_types_are_exact(self):
        value = fixture()
        for key in ("path", "digest"):
            changed = copy.deepcopy(value)
            del changed["assets"][0][key]
            self.assert_refused(changed)
        changed = copy.deepcopy(value)
        changed["assets"][0]["size"] = 123
        self.assert_refused(changed)
        for bad in ("A" * 64, "1" * 63, "g" * 64, True, None):
            changed = copy.deepcopy(value)
            changed["assets"][0]["digest"] = bad
            with self.subTest(bad=bad):
                self.assert_refused(changed)

    def test_asset_digest_or_release_binding_change_changes_artifact_digest(self):
        original = artifact.decode(canonical(fixture()))
        changed_asset = fixture()
        changed_asset["assets"][0]["digest"] = "f" * 64
        changed_release = fixture()
        changed_release["releaseSourceArtifactDigest"] = "b" * 64
        self.assertNotEqual(
            original["artifactDigest"],
            artifact.decode(canonical(changed_asset))["artifactDigest"],
        )
        self.assertNotEqual(
            original["artifactDigest"],
            artifact.decode(canonical(changed_release))["artifactDigest"],
        )

    def test_dependency_and_module_authority_mutation_fail_closed(self):
        raw = canonical(fixture())
        original_refusal = artifact.AssetReviewArtifactRefused
        replacements = (
            (artifact, "ROLE", "release-source"),
            (artifact, "ASSET_PATHS", tuple(list(artifact.ASSET_PATHS))),
            (artifact, "MAX_ARTIFACT_BYTES", MAX_ARTIFACT_BYTES + 1),
            (artifact, "AssetReviewArtifactRefused", Exception),
            (artifact, "MappingProxyType", dict),
            (artifact, "json", object()),
            (artifact, "hashlib", object()),
            (artifact, "re", object()),
            (artifact, "__all__", tuple(list(artifact.__all__))),
        )
        for owner, name, replacement in replacements:
            original = getattr(owner, name)
            try:
                setattr(owner, name, replacement)
                with self.subTest(authority=name):
                    with self.assertRaises(original_refusal):
                        artifact.decode(raw)
            finally:
                setattr(owner, name, original)

        for owner, name in (
            (artifact.json, "loads"),
            (artifact.json, "dumps"),
            (artifact.hashlib, "sha256"),
            (artifact.re, "fullmatch"),
        ):
            original = getattr(owner, name)
            calls = []

            def replacement(*_args, **_kwargs):
                calls.append(True)
                return {}

            try:
                setattr(owner, name, replacement)
                with self.assertRaises(original_refusal):
                    artifact.decode(raw)
                self.assertEqual(calls, [])
            finally:
                setattr(owner, name, original)

    def test_baseexception_identity_survives_postcheck_authority_drift(self):
        invoke = next(
            cell.cell_contents
            for cell in artifact.decode.__closure__
            if callable(cell.cell_contents)
            and getattr(cell.cell_contents, "__name__", "") == "invoke"
        )
        original_paths = artifact.ASSET_PATHS
        replacement_paths = tuple(list(original_paths))
        for primary in (KeyboardInterrupt("k"), SystemExit("s")):
            def drift_then_stop(error=primary):
                artifact.ASSET_PATHS = replacement_paths
                raise error

            try:
                with self.assertRaises(type(primary)) as raised:
                    invoke(drift_then_stop)
                self.assertIs(raised.exception, primary)
            finally:
                artifact.ASSET_PATHS = original_paths

    def test_public_surface_states_composition_and_non_authority_boundaries(self):
        self.assertEqual(
            artifact.__all__,
            ("AssetReviewArtifactRefused", "canonical_artifact", "decode"),
        )
        text = (artifact.__doc__ or "").lower()
        for phrase in ("structural", "does not read", "composition", "2-of-2"):
            self.assertIn(phrase, text)
        for forbidden in (
            "admit",
            "inspect_assets",
            "open",
            "read_filesystem",
            "verify",
            "verify_reviewers",
            "verify_signature",
        ):
            self.assertFalse(hasattr(artifact, forbidden))


if __name__ == "__main__":
    unittest.main()
