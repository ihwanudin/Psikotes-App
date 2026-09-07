import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import unittest


MODULE_PATH = Path(__file__).with_name("checkout-tool-runtime-closure-artifact.py")
SPEC = importlib.util.spec_from_file_location("checkout_tool_runtime_closure", MODULE_PATH)
artifact = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(artifact)


TOOL_ROLES = (
    "php", "python", "node", "powershell", "playwright-cli", "browser",
)
RESOURCE_KINDS = (
    "dll", "shared-library", "provider-module", "config", "package-root",
    "loader-policy", "resource",
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


def item(role, index, path=None):
    return {
        "digest": format(index, "064x"),
        "fileId": str(1000 + index),
        "path": path or f"C:/oncam/tools/{role}/{role}.exe",
        "role": role,
        "version": f"1.0.{index}",
        "volumeSerial": "77",
    }


def resource(kind, index):
    return {
        "digest": format(100 + index, "064x"),
        "fileId": str(2000 + index),
        "kind": kind,
        "path": f"C:/oncam/runtime/{index:02d}-{kind}.bin",
        "version": f"resource-{index}",
        "volumeSerial": "77",
    }


def fixture():
    return {
        "artifactId": "tool-runtime-win64-20260907.1",
        "closureComplete": True,
        "expiresAt": "2026-09-14T02:03:04Z",
        "generation": 4,
        "hostIdentityDigest": "a" * 64,
        "issuedAt": "2026-09-07T02:03:04Z",
        "issuerId": "tool-runtime-custodian-01",
        "replayId": "tool-runtime-win64-20260907.1-attempt-01",
        "resources": [
            resource(kind, index + 1) for index, kind in enumerate(RESOURCE_KINDS)
        ],
        "role": "tool-runtime-closure",
        "tools": [item(role, index + 1) for index, role in enumerate(TOOL_ROLES)],
        "version": 1,
    }


class ToolRuntimeClosureArtifactTests(unittest.TestCase):
    def assert_refused(self, value):
        raw = canonical(value) if type(value) is dict else value
        with self.assertRaisesRegex(
            artifact.ToolRuntimeClosureArtifactRefused,
            "^tool_runtime_closure_artifact$",
        ):
            artifact.decode(raw)

    def test_known_vector_is_canonical_immutable_and_structural_only(self):
        document = fixture()
        raw = canonical(document)
        result = artifact.decode(raw)

        self.assertEqual(artifact.canonical_artifact(document), raw)
        self.assertIs(type(result), type({}.keys().mapping))
        self.assertEqual(tuple(result), (
            "structuralOnly", "role", "artifactId", "issuerId", "generation",
            "issuedAt", "expiresAt", "replayId", "hostIdentityDigest",
            "closureComplete", "toolCount", "resourceCount", "tools",
            "resources", "artifactDigest",
        ))
        self.assertIs(result["structuralOnly"], True)
        self.assertIs(result["closureComplete"], True)
        self.assertEqual(tuple(entry[0] for entry in result["tools"]), TOOL_ROLES)
        self.assertEqual(tuple(entry[0] for entry in result["resources"]), RESOURCE_KINDS)
        self.assertEqual(result["artifactDigest"], hashlib.sha256(raw).hexdigest())
        with self.assertRaises(TypeError):
            result["accepted"] = True
        self.assertIs(type(result["tools"]), tuple)
        self.assertIs(type(result["tools"][0]), tuple)

    def test_json_schema_encoding_and_caps_are_exact(self):
        document = fixture()
        raw = canonical(document)
        for key in tuple(document):
            changed = copy.deepcopy(document)
            del changed[key]
            with self.subTest(missing=key):
                self.assert_refused(changed)
        changed = fixture()
        changed["trusted"] = True
        self.assert_refused(changed)
        for candidate in (
            raw[:-1], raw + b"\n", b"\xef\xbb\xbf" + raw,
            json.dumps(document).encode("ascii"),
            b'{"version":1,"version":1}\n', b'{"generation":NaN}\n',
            "not-bytes", bytearray(raw),
            b"{" + b" " * artifact.MAX_ARTIFACT_BYTES + b"}\n",
        ):
            with self.subTest(candidate=repr(candidate)[:50]):
                self.assert_refused(candidate)

    def test_identity_and_lifetime_fields_are_strict_structural_data(self):
        for key, bad in (
            ("version", True), ("version", 2), ("role", "release-source"),
            ("artifactId", "Artifact"), ("issuerId", "../issuer"),
            ("replayId", "retry id"), ("generation", True), ("generation", 0),
            ("hostIdentityDigest", "A" * 64),
            ("issuedAt", "2026-09-07T02:03:04+00:00"),
            ("expiresAt", "2026-09-07T02:03:04Z"),
            ("expiresAt", "2026-09-14T02:03:05Z"),
        ):
            changed = fixture()
            changed[key] = bad
            with self.subTest(key=key, bad=bad):
                self.assert_refused(changed)

    def test_all_six_tool_roles_are_exact_ordered_and_unique(self):
        valid = fixture()
        self.assertEqual(tuple(tool["role"] for tool in valid["tools"]), TOOL_ROLES)
        for tools in (
            valid["tools"][:-1], list(reversed(valid["tools"])),
            valid["tools"] + [copy.deepcopy(valid["tools"][0])],
        ):
            changed = fixture()
            changed["tools"] = tools
            self.assert_refused(changed)
        changed = fixture()
        changed["tools"][0]["role"] = "php-cli"
        self.assert_refused(changed)

    def test_transitive_resource_categories_and_complete_claim_are_exact(self):
        for value in (False, 1, "true", None):
            changed = fixture()
            changed["closureComplete"] = value
            self.assert_refused(changed)
        changed = fixture()
        changed["resources"] = []
        self.assert_refused(changed)
        changed = fixture()
        changed["resources"][0]["kind"] = "executable"
        self.assert_refused(changed)
        changed = fixture()
        changed["resources"][0]["extra"] = True
        self.assert_refused(changed)

    def test_paths_are_absolute_canonical_collision_safe_and_vendor_free(self):
        bad_paths = (
            "C:\\runtime\\x.dll", "c:/runtime/x.dll", "C:/runtime/../x.dll",
            "C:/runtime/./x.dll", "C:/runtime//x.dll", "C:/runtime/CON.dll",
            "C:/runtime/CONIN$.dll", "C:/runtime/trailing.",
            "C:/runtime/trailing ", "C:/runtime/<bad>.dll", "C:/résumé.dll",
            "relative/tool.exe", "//server/share/tool.exe",
        )
        for bad in bad_paths:
            changed = fixture()
            changed["resources"][0]["path"] = bad
            changed["resources"].sort(key=lambda entry: entry["path"].lower())
            with self.subTest(path=repr(bad)):
                self.assert_refused(changed)

        changed = fixture()
        changed["resources"][0]["path"] = changed["tools"][0]["path"].upper()
        changed["resources"].sort(key=lambda entry: entry["path"].lower())
        self.assert_refused(changed)

    def test_object_identity_digest_version_and_joint_bounds_are_exact(self):
        cases = (
            ("volumeSerial", "00"), ("volumeSerial", 77),
            ("fileId", "0"), ("fileId", True),
            ("digest", "A" * 64), ("digest", "0" * 63),
            ("version", ""), ("version", True),
        )
        for key, bad in cases:
            changed = fixture()
            changed["resources"][0][key] = bad
            with self.subTest(key=key, bad=bad):
                self.assert_refused(changed)

        changed = fixture()
        changed["resources"][0]["volumeSerial"] = changed["tools"][0]["volumeSerial"]
        changed["resources"][0]["fileId"] = changed["tools"][0]["fileId"]
        self.assert_refused(changed)

        changed = fixture()
        template = changed["resources"][0]
        changed["resources"] = []
        for index in range(artifact.MAX_ENTRIES - len(TOOL_ROLES) + 1):
            entry = copy.deepcopy(template)
            entry["path"] = f"C:/runtime/{index:04d}.dll"
            entry["fileId"] = str(5000 + index)
            changed["resources"].append(entry)
        self.assert_refused(changed)

    def test_dependency_and_authority_rebinding_fail_closed(self):
        raw = canonical(fixture())
        original_refusal = artifact.ToolRuntimeClosureArtifactRefused
        replacements = (
            (artifact, "TOOL_ROLES", tuple(list(artifact.TOOL_ROLES))),
            (artifact, "RESOURCE_KINDS", tuple(list(artifact.RESOURCE_KINDS))),
            (artifact, "MAX_ENTRIES", artifact.MAX_ENTRIES + 1),
            (artifact, "MappingProxyType", dict),
            (artifact, "json", object()), (artifact, "hashlib", object()),
            (artifact, "re", object()), (artifact, "datetime", object()),
            (artifact, "ToolRuntimeClosureArtifactRefused", Exception),
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

        dependencies = (
            (artifact.json, "dumps"), (artifact.json, "loads"),
            (artifact.hashlib, "sha256"), (artifact.re, "fullmatch"),
            (artifact.datetime, "datetime"), (artifact.datetime, "timedelta"),
        )
        for owner, name in dependencies:
            original = getattr(owner, name)
            try:
                setattr(owner, name, lambda *_args, **_kwargs: {})
                with self.subTest(dependency=f"{owner.__name__}.{name}"):
                    with self.assertRaises(original_refusal):
                        artifact.decode(raw)
            finally:
                setattr(owner, name, original)

    def test_baseexception_identity_is_preserved_over_postcheck_drift(self):
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

    def test_public_surface_and_non_authority_scope_are_exact(self):
        self.assertEqual(artifact.__all__, (
            "ToolRuntimeClosureArtifactRefused", "canonical_artifact", "decode",
        ))
        text = (artifact.__doc__ or "").lower()
        for phrase in (
            "structural", "supplied", "does not inspect", "not proof",
            "pin the imported",
        ):
            self.assertIn(phrase, text)
        for forbidden in (
            "inspect_filesystem", "launch", "verify_signature", "accept",
            "admit", "consume_replay", "load_high_water", "run_tool",
        ):
            self.assertFalse(hasattr(artifact, forbidden))


if __name__ == "__main__":
    unittest.main()
