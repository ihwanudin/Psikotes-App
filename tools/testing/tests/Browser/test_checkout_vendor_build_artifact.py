import copy
import hashlib
import importlib.util
import json
from pathlib import Path
from types import MappingProxyType
import unittest


MODULE_PATH = Path(__file__).with_name("checkout-vendor-build-artifact.py")
SPEC = importlib.util.spec_from_file_location("checkout_vendor_build_artifact", MODULE_PATH)
artifact = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(artifact)


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


def package(name, index):
    return {
        "name": name,
        "packageDigest": format(index, "064x"),
        "runtime": {
            "phpConstraint": ">=8.2",
            "requiredExtensions": ["ctype", "json"],
        },
        "version": f"1.0.{index}",
    }


def vendor_file(path, index):
    return {
        "digest": format(100 + index, "064x"),
        "kind": "file",
        "path": path,
        "reparse": False,
    }


def fixture():
    return {
        "artifactId": "vendor-build-20260907.1",
        "buildProvenance": {
            "buildId": "vendor-build-run-01",
            "builderDigest": "1" * 64,
            "policyDigest": "2" * 64,
            "toolchainDigest": "3" * 64,
        },
        "composerLockDigest": "4" * 64,
        "expiresAt": "2026-09-14T02:03:04Z",
        "generation": 3,
        "issuedAt": "2026-09-07T02:03:04Z",
        "issuerId": "vendor-build-custodian-01",
        "packages": [
            package("acme/alpha", 1),
            package("acme/beta", 2),
        ],
        "releaseSourceArtifactDigest": "5" * 64,
        "replayId": "vendor-build-20260907.1-attempt-01",
        "role": "vendor-build",
        "vendorFiles": [
            vendor_file("vendor/acme/alpha/src/Alpha.php", 1),
            vendor_file("vendor/acme/beta/src/Beta.php", 2),
            vendor_file("vendor/autoload.php", 3),
            vendor_file("vendor/composer/installed.php", 4),
        ],
        "version": 1,
    }


class VendorBuildArtifactTests(unittest.TestCase):
    def assert_refused(self, value):
        raw = canonical(value) if type(value) is dict else value
        with self.assertRaisesRegex(
            artifact.VendorBuildArtifactRefused,
            "^vendor_build_artifact$",
        ):
            artifact.decode(raw)

    def test_known_vector_is_canonical_deeply_immutable_and_structural_only(self):
        document = fixture()
        raw = canonical(document)
        result = artifact.decode(raw)

        self.assertEqual(artifact.canonical_artifact(document), raw)
        self.assertIs(type(result), MappingProxyType)
        self.assertEqual(tuple(result), (
            "structuralOnly", "role", "artifactId", "issuerId", "generation",
            "issuedAt", "expiresAt", "replayId", "releaseSourceArtifactDigest",
            "composerLockDigest", "buildProvenance", "packageCount", "fileCount",
            "packages", "vendorFiles", "artifactDigest",
        ))
        self.assertIs(result["structuralOnly"], True)
        self.assertIs(type(result["packages"]), tuple)
        self.assertIs(type(result["packages"][0]), tuple)
        self.assertIs(type(result["packages"][0][4]), tuple)
        self.assertEqual(result["artifactDigest"], hashlib.sha256(raw).hexdigest())
        with self.assertRaises(TypeError):
            result["accepted"] = True
        with self.assertRaises(TypeError):
            result["packages"][0][4][0] = "curl"

    def test_json_boundary_schema_and_size_are_exact(self):
        document = fixture()
        raw = canonical(document)
        for key in tuple(document):
            changed = copy.deepcopy(document)
            del changed[key]
            with self.subTest(missing=key):
                self.assert_refused(changed)
        for extra in ("assetReviewDigest", "compositionAdmissionDigest", "tlsDigest",
                      "privateKey", "password"):
            changed = fixture()
            changed[extra] = "0" * 64
            self.assert_refused(changed)
        for candidate in (
            raw[:-1], raw + b"\n", b"\xef\xbb\xbf" + raw,
            json.dumps(document).encode("ascii"),
            b'{"version":1,"version":1}\n', b'{"generation":NaN}\n',
            b"[]\n", "not-bytes", bytearray(raw),
            b"{" + b" " * artifact.MAX_ARTIFACT_BYTES + b"}\n",
        ):
            with self.subTest(candidate=repr(candidate)[:48]):
                self.assert_refused(candidate)

    def test_artifact_identity_lifecycle_and_digest_bindings_are_strict(self):
        for key, bad in (
            ("version", True), ("version", 2), ("role", "release-source"),
            ("artifactId", "Artifact"), ("issuerId", "../issuer"),
            ("generation", 0), ("generation", True), ("replayId", "retry id"),
            ("releaseSourceArtifactDigest", "A" * 64),
            ("composerLockDigest", "4" * 63),
            ("issuedAt", "2026-09-07T02:03:04+00:00"),
            ("expiresAt", "2026-09-07T02:03:04Z"),
            ("expiresAt", "2026-09-14T02:03:05Z"),
        ):
            changed = fixture()
            changed[key] = bad
            with self.subTest(key=key, bad=bad):
                self.assert_refused(changed)

    def test_build_provenance_is_exact_supplied_structural_data(self):
        value = fixture()
        for key in tuple(value["buildProvenance"]):
            changed = copy.deepcopy(value)
            del changed["buildProvenance"][key]
            self.assert_refused(changed)
        changed = fixture()
        changed["buildProvenance"]["authenticated"] = True
        self.assert_refused(changed)
        for key, bad in (
            ("buildId", "../build"), ("builderDigest", "A" * 64),
            ("policyDigest", True), ("toolchainDigest", "0" * 63),
        ):
            changed = fixture()
            changed["buildProvenance"][key] = bad
            self.assert_refused(changed)

    def test_package_inventory_is_nonempty_sorted_unique_and_bounded(self):
        for packages in ([], list(reversed(fixture()["packages"])),
                         fixture()["packages"] + [copy.deepcopy(fixture()["packages"][0])]):
            changed = fixture()
            changed["packages"] = packages
            self.assert_refused(changed)
        changed = fixture()
        changed["packages"] = [package(f"v/p{i:04d}", i + 1)
                               for i in range(artifact.MAX_PACKAGES + 1)]
        self.assert_refused(changed)

    def test_package_schema_runtime_metadata_and_digest_are_exact(self):
        value = fixture()
        for key in tuple(value["packages"][0]):
            changed = copy.deepcopy(value)
            del changed["packages"][0][key]
            self.assert_refused(changed)
        for key, bad in (
            ("name", "Acme/alpha"), ("name", "alpha"),
            ("version", ""), ("packageDigest", "A" * 64),
        ):
            changed = fixture()
            changed["packages"][0][key] = bad
            self.assert_refused(changed)
        for extensions in (["json", "ctype"], ["json", "json"], [True],
                           ["x"] * (artifact.MAX_EXTENSIONS + 1)):
            changed = fixture()
            changed["packages"][0]["runtime"]["requiredExtensions"] = extensions
            self.assert_refused(changed)
        changed = fixture()
        changed["packages"][0]["runtime"]["extra"] = True
        self.assert_refused(changed)

    def test_vendor_files_are_nonempty_sorted_unique_and_package_complete(self):
        for files in ([], list(reversed(fixture()["vendorFiles"])),
                      fixture()["vendorFiles"] + [copy.deepcopy(fixture()["vendorFiles"][0])]):
            changed = fixture()
            changed["vendorFiles"] = files
            self.assert_refused(changed)
        changed = fixture()
        changed["vendorFiles"] = [entry for entry in changed["vendorFiles"]
                                  if not entry["path"].startswith("vendor/acme/alpha/")]
        self.assert_refused(changed)

    def test_vendor_paths_and_regular_nonreparse_file_contract_are_exact(self):
        bad_paths = (
            "app/Code.php", "VENDOR/acme/alpha/X.php", "/vendor/X.php",
            "vendor/../escape.php", "vendor/acme/./X.php", "vendor/acme//X.php",
            "vendor/acme\\X.php", "vendor/acme/CON.php", "vendor/acme/CONOUT$.dll",
            "vendor/acme/trailing.", "vendor/acme/trailing ",
            "vendor/acme/<bad>.php", "vendor/acmé/X.php",
        )
        for bad in bad_paths:
            changed = fixture()
            changed["vendorFiles"][0]["path"] = bad
            changed["vendorFiles"].sort(key=lambda entry: entry["path"].lower())
            with self.subTest(path=repr(bad)):
                self.assert_refused(changed)
        for key, bad in (
            ("kind", "symlink"), ("reparse", True), ("reparse", 0),
            ("digest", "A" * 64),
        ):
            changed = fixture()
            changed["vendorFiles"][0][key] = bad
            self.assert_refused(changed)

    def test_content_or_binding_changes_change_artifact_digest(self):
        original = artifact.decode(canonical(fixture()))
        for mutate in (
            lambda value: value.update(composerLockDigest="6" * 64),
            lambda value: value["packages"][0].update(packageDigest="7" * 64),
            lambda value: value["vendorFiles"][0].update(digest="8" * 64),
            lambda value: value["buildProvenance"].update(policyDigest="9" * 64),
        ):
            changed = fixture()
            mutate(changed)
            self.assertNotEqual(
                original["artifactDigest"],
                artifact.decode(canonical(changed))["artifactDigest"],
            )

    def test_dependency_and_module_authority_mutation_fail_closed(self):
        raw = canonical(fixture())
        original_refusal = artifact.VendorBuildArtifactRefused
        replacements = (
            (artifact, "MAX_VENDOR_FILES", artifact.MAX_VENDOR_FILES + 1),
            (artifact, "ROLE", "release-source"),
            (artifact, "MappingProxyType", dict),
            (artifact, "json", object()), (artifact, "hashlib", object()),
            (artifact, "re", object()), (artifact, "datetime", object()),
            (artifact, "VendorBuildArtifactRefused", Exception),
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
            (artifact.json, "loads"), (artifact.json, "dumps"),
            (artifact.hashlib, "sha256"), (artifact.re, "fullmatch"),
        ):
            original = getattr(owner, name)
            try:
                setattr(owner, name, lambda *_args, **_kwargs: {})
                with self.assertRaises(original_refusal):
                    artifact.decode(raw)
            finally:
                setattr(owner, name, original)

    def test_baseexception_identity_and_non_authority_surface(self):
        invoke = next(
            cell.cell_contents for cell in artifact.decode.__closure__
            if callable(cell.cell_contents)
            and getattr(cell.cell_contents, "__name__", "") == "invoke"
        )
        original_role = artifact.ROLE
        for primary in (KeyboardInterrupt("k"), SystemExit("s")):
            def drift_then_stop(error=primary):
                artifact.ROLE = "release-source"
                raise error
            try:
                with self.assertRaises(type(primary)) as raised:
                    invoke(drift_then_stop)
                self.assertIs(raised.exception, primary)
            finally:
                artifact.ROLE = original_role

        self.assertEqual(artifact.__all__, (
            "VendorBuildArtifactRefused", "canonical_artifact", "decode",
        ))
        text = (artifact.__doc__ or "").lower()
        for phrase in ("structural", "supplied", "does not read", "not prove"):
            self.assertIn(phrase, text)
        for forbidden in (
            "run_composer", "inspect_filesystem", "verify", "sign", "admit",
            "consume_replay", "authenticate_builder",
        ):
            self.assertFalse(hasattr(artifact, forbidden))


if __name__ == "__main__":
    unittest.main()
