import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import unittest


MODULE_PATH = Path(__file__).with_name("checkout-release-source-artifact.py")
SPEC = importlib.util.spec_from_file_location("checkout_release_source_artifact", MODULE_PATH)
artifact = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(artifact)


def canonical(value):
    return (json.dumps(value, sort_keys=True, separators=(",", ":"),
                       ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")


def fixture():
    return {
        "artifactId": "release-20260907.1",
        "expiresAt": "2026-09-14T02:03:04Z",
        "generation": 17,
        "issuedAt": "2026-09-07T02:03:04Z",
        "issuerId": "release-custodian-01",
        "replayId": "release-20260907.1-attempt-01",
        "reviewedCommit": "1" * 40,
        "reviewedTree": "2" * 40,
        "role": "release-source",
        "sourceFiles": [
            {"digest": "3" * 64, "kind": "file", "path": "app/Checkout.php",
             "reparse": False},
            {"digest": "4" * 64, "kind": "file", "path": "composer.lock",
             "reparse": False},
        ],
        "sourceRevision": "1" * 40,
        "version": 1,
    }


class ReleaseSourceArtifactTests(unittest.TestCase):
    def assert_refused(self, value):
        raw = canonical(value) if type(value) is dict else value
        with self.assertRaisesRegex(
            artifact.ReleaseSourceArtifactRefused,
            "^release_source_artifact$",
        ):
            artifact.decode(raw)

    def test_known_vector_is_canonical_immutable_and_structural_only(self):
        value = fixture()
        raw = canonical(value)
        result = artifact.decode(raw)

        self.assertEqual(artifact.canonical_artifact(value), raw)
        self.assertIs(type(result), type({}.keys().mapping))
        self.assertEqual(tuple(result), (
            "structuralOnly", "role", "artifactId", "issuerId", "generation",
            "issuedAt", "expiresAt", "replayId", "reviewedCommit", "reviewedTree",
            "sourceRevision", "sourceFileCount", "sourceFiles", "artifactDigest",
        ))
        self.assertIs(result["structuralOnly"], True)
        self.assertEqual(result["sourceFiles"], (
            ("app/Checkout.php", "3" * 64),
            ("composer.lock", "4" * 64),
        ))
        self.assertEqual(
            result["artifactDigest"],
            "c7258629abf89fa80f2bb1938b347d696f42e61e688c7ea005f1b5c8c75b7281",
        )
        with self.assertRaises(TypeError):
            result["role"] = "vendor-build"

    def test_json_boundary_is_exact_ascii_one_lf_and_bounded(self):
        value = fixture()
        raw = canonical(value)
        for candidate in (
            raw[:-1], raw + b"\n", b"\xef\xbb\xbf" + raw,
            json.dumps(value).encode("ascii"), b'{"version":1,"version":1}\n',
            b'{"version":NaN}\n', b"[]\n", b"null\n", "not-bytes", bytearray(raw),
            b"{" + b" " * artifact.MAX_ARTIFACT_BYTES + b"}\n",
        ):
            with self.subTest(candidate=repr(candidate)[:40]):
                self.assert_refused(candidate)

    def test_schema_and_scalar_types_are_exact(self):
        value = fixture()
        for key in tuple(value):
            changed = copy.deepcopy(value)
            del changed[key]
            with self.subTest(missing=key):
                self.assert_refused(changed)
        changed = copy.deepcopy(value)
        changed["vendorFiles"] = []
        self.assert_refused(changed)
        for key in ("version", "generation"):
            for bad in (True, 1.0, "1", None, 0, 2**63):
                changed = copy.deepcopy(value)
                changed[key] = bad
                with self.subTest(key=key, bad=bad):
                    self.assert_refused(changed)

    def test_lifecycle_fields_are_signed_data_but_only_duration_is_checked(self):
        value = fixture()
        # Historical timestamps remain structurally valid: this codec has no trusted clock.
        artifact.decode(canonical(value))
        invalid = (
            ("issuedAt", "2026-02-30T00:00:00Z"),
            ("issuedAt", "2026-09-07T02:03:04+00:00"),
            ("expiresAt", "2026-09-07T02:03:04Z"),
            ("expiresAt", "2026-09-14T02:03:05Z"),
            ("expiresAt", "2026-09-06T02:03:04Z"),
            ("replayId", "../retry"),
            ("artifactId", "Artifact"),
            ("issuerId", "issuer id"),
        )
        for key, bad in invalid:
            changed = copy.deepcopy(value)
            changed[key] = bad
            with self.subTest(key=key, bad=bad):
                self.assert_refused(changed)
        changed = copy.deepcopy(value)
        changed["now"] = "2026-09-07T02:03:04Z"
        self.assert_refused(changed)

    def test_reviewed_commit_tree_and_source_revision_are_exact(self):
        value = fixture()
        for key in ("reviewedCommit", "reviewedTree", "sourceRevision"):
            for bad in ("A" * 40, "0" * 39, True, None):
                changed = copy.deepcopy(value)
                changed[key] = bad
                with self.subTest(key=key, bad=bad):
                    self.assert_refused(changed)
        changed = copy.deepcopy(value)
        changed["sourceRevision"] = "9" * 40
        self.assert_refused(changed)

    def test_inventory_is_sorted_nonempty_bounded_and_includes_composer_lock(self):
        value = fixture()
        for files in ([], list(reversed(value["sourceFiles"])), value["sourceFiles"][:1]):
            changed = copy.deepcopy(value)
            changed["sourceFiles"] = files
            self.assert_refused(changed)
        changed = copy.deepcopy(value)
        changed["sourceFiles"] = [copy.deepcopy(value["sourceFiles"][1])] * (
            artifact.MAX_SOURCE_FILES + 1
        )
        self.assert_refused(changed)

    def test_source_entry_is_exact_regular_nonreparse_file(self):
        value = fixture()
        for key, bad in (("kind", "symlink"), ("reparse", True),
                         ("reparse", 0), ("digest", "A" * 64)):
            changed = copy.deepcopy(value)
            changed["sourceFiles"][0][key] = bad
            with self.subTest(key=key, bad=bad):
                self.assert_refused(changed)
        changed = copy.deepcopy(value)
        changed["sourceFiles"][0]["target"] = "elsewhere"
        self.assert_refused(changed)

    def test_windows_safe_relative_path_and_collision_rules_are_exact(self):
        bad_paths = (
            "/absolute", "c:/absolute", "../escape", "a/../b", "a/./b",
            "a\\b", "a:b", "a\x00b", "a/CON.txt", "a/CONIN$",
            "a/conin$.txt", "a/CONIN$ .txt", "a/CONOUT$", "a/conout$.log",
            "a/CONOUT$ .log", "a/trailing.",
            "a/trailing ", 'a/<bad>', "é.txt", "vendor/pkg.php", "VENDOR/pkg.php",
            "/".join(["a"] * 65), "a" * 256,
        )
        for bad in bad_paths:
            changed = fixture()
            changed["sourceFiles"][0]["path"] = bad
            changed["sourceFiles"].sort(key=lambda item: item["path"].lower())
            with self.subTest(path=repr(bad)):
                self.assert_refused(changed)

        for collision in ("APP/checkout.php", "app/Checkout.php"):
            changed = fixture()
            duplicate = copy.deepcopy(changed["sourceFiles"][0])
            duplicate["path"] = collision
            changed["sourceFiles"].append(duplicate)
            changed["sourceFiles"].sort(key=lambda item: item["path"].lower())
            self.assert_refused(changed)

    def test_content_digest_and_inventory_changes_change_artifact_digest(self):
        original = artifact.decode(canonical(fixture()))
        changed = fixture()
        changed["sourceFiles"][0]["digest"] = "8" * 64
        revised = artifact.decode(canonical(changed))
        self.assertNotEqual(original["artifactDigest"], revised["artifactDigest"])
        self.assertNotEqual(original["sourceFiles"], revised["sourceFiles"])

    def test_dependency_rebinding_refuses_before_replacement_execution(self):
        raw = canonical(fixture())
        for module, name in (
            (artifact.json, "loads"), (artifact.json, "dumps"),
            (artifact.hashlib, "sha256"), (artifact.re, "fullmatch"),
        ):
            original = getattr(module, name)
            called = []

            def replacement(*_args, **_kwargs):
                called.append(True)
                return {}

            try:
                setattr(module, name, replacement)
                with self.assertRaises(artifact.ReleaseSourceArtifactRefused):
                    artifact.decode(raw)
                self.assertEqual(called, [])
            finally:
                setattr(module, name, original)

    def test_regex_casefold_authority_rebinding_fails_closed(self):
        changed = fixture()
        changed["sourceFiles"][0]["path"] = "a/CON.txt"
        changed["sourceFiles"].sort(key=lambda item: item["path"].lower())
        raw = canonical(changed)
        original = artifact.re.IGNORECASE
        try:
            artifact.re.IGNORECASE = 0
            with self.assertRaisesRegex(
                artifact.ReleaseSourceArtifactRefused,
                "^release_source_artifact$",
            ):
                artifact.decode(raw)
        finally:
            artifact.re.IGNORECASE = original

    def test_baseexceptions_keep_identity_and_postcheck_does_not_mask(self):
        invoke = next(
            cell.cell_contents for cell in artifact.decode.__closure__
            if callable(cell.cell_contents) and getattr(cell.cell_contents, "__name__", "") == "invoke"
        )
        original_roles = artifact.ROLE
        for primary in (KeyboardInterrupt("k"), SystemExit("s")):
            def drift_then_stop(error=primary):
                artifact.ROLE = "vendor-build"
                raise error

            try:
                with self.assertRaises(type(primary)) as raised:
                    invoke(drift_then_stop)
                self.assertIs(raised.exception, primary)
            finally:
                artifact.ROLE = original_roles

    def test_public_surface_and_composition_nonclaim_are_exact(self):
        self.assertEqual(artifact.__all__, (
            "ReleaseSourceArtifactRefused", "canonical_artifact", "decode",
        ))
        text = (artifact.__doc__ or "").lower()
        for phrase in ("structural", "no trusted clock", "role-specific envelope",
                       "pin the imported"):
            self.assertIn(phrase, text)
        for forbidden in ("verify", "sign", "admit", "load_key", "inspect_git",
                          "read_filesystem", "consume_replay"):
            self.assertFalse(hasattr(artifact, forbidden))


if __name__ == "__main__":
    unittest.main()
