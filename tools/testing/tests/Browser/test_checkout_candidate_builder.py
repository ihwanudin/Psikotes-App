import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import stat
import tempfile
import unittest
from unittest.mock import patch
import ast
import uuid


HERE = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location(
    "checkout_candidate_builder_tested", HERE / "checkout-candidate-builder.py"
)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)
m = MODULE


def digest(value):
    return hashlib.sha256(value).hexdigest()


class CandidateBuilderTests(unittest.TestCase):
    def setUp(self):
        self.temp_root = Path(tempfile.gettempdir()).resolve()
        self.owned_candidates = {}

    def new_destination(self):
        path = self.temp_root / ("oncam-checkout-" + uuid.uuid4().hex)
        self.assertFalse(path.exists())
        return path

    def build(self, args):
        try:
            return m.build_candidate(**args)
        finally:
            path = Path(args["destination"])
            if path.exists() and path.parent.resolve() == self.temp_root \
                    and re.fullmatch(r"oncam-checkout-[a-f0-9]{32}", path.name):
                info = path.lstat()
                if stat.S_ISDIR(info.st_mode) and not path.is_symlink():
                    self.owned_candidates.setdefault(path, (info.st_dev, info.st_ino))

    def tearDown(self):
        for path, identity in self.owned_candidates.items():
            if path.parent.resolve() != self.temp_root \
                    or re.fullmatch(r"oncam-checkout-[a-f0-9]{32}", path.name) is None:
                continue
            try:
                info = path.lstat()
            except FileNotFoundError:
                continue
            if stat.S_ISDIR(info.st_mode) and not path.is_symlink() \
                    and (info.st_dev, info.st_ino) == identity and path.resolve() == path.absolute():
                shutil.rmtree(path)

    def fixture(self, root):
        root = Path(root)
        source = root / "reviewed-source"
        source.mkdir()
        manifest = {}
        for index, relative in enumerate(sorted(m.REQUIRED_SOURCE), 1):
            value = f"reviewed-source-{index}\n".encode()
            path = source / Path(*relative.split("/"))
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(value)
            manifest[relative] = digest(value)

        tools = {}
        for index, name in enumerate(m.EXTERNAL_TOOLS, 1):
            path = root / f"tool-{name}.bin"
            if name == "cli":
                path = root / Path(*m.CLI_SUFFIX.split("/"))
            elif name == "browser":
                path = root / Path(*m.BROWSER_SUFFIX.split("/"))
            value = f"reviewed-tool-{index}".encode()
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(value)
            tools[name] = {"path": str(path.absolute()), "sha256": digest(value)}

        runtime_ini = b"display_errors=Off\nlog_errors=Off\n"
        cert = b"-----BEGIN CERTIFICATE-----\nsynthetic\n-----END CERTIFICATE-----\n"
        key = b"-----BEGIN PRIVATE KEY-----\nsynthetic\n-----END PRIVATE KEY-----\n"
        runtime = {
            "ini": {"bytes": runtime_ini, "sha256": digest(runtime_ini)},
            "cert": {"bytes": cert, "sha256": digest(cert)},
            "key": {"bytes": key, "sha256": digest(key)},
        }
        review = {name: manifest[name] for name in m.ASSET_REVIEW_FILES}
        destination = self.new_destination()
        kwargs = {
            "destination": destination,
            "candidate_parent": self.temp_root,
            "source_root": source,
            "expected_manifest": manifest,
            "source_revision": "b" * 40,
            "tools": tools,
            "runtime_files": runtime,
            "asset_delivery_review": review,
        }
        return kwargs

    def test_builds_exact_hash_pinned_candidate_and_writes_config_last(self):
        with tempfile.TemporaryDirectory(prefix="candidate-builder-test-") as directory:
            args = self.fixture(directory)
            result = self.build(args)
            destination = Path(args["destination"])
            config = json.loads((destination / "supervisor-config.json").read_text("utf-8"))
            manifest_bytes = (destination / "source-manifest.json").read_bytes()

            self.assertEqual(result, config)
            self.assertIsNot(result, config)
            self.assertEqual(config["manifest"], digest(manifest_bytes))
            self.assertEqual(json.loads(manifest_bytes), args["expected_manifest"])
            self.assertEqual(config["asset_delivery_review"], args["asset_delivery_review"])
            self.assertEqual(set(config), m.CONFIG_KEYS)
            self.assertEqual(set(config["tool_hashes"]), set(m.ALL_TOOL_KEYS))
            self.assertEqual((destination / "source-revision.txt").read_text("ascii"), "b" * 40 + "\n")
            self.assertFalse(any((destination / name).exists() for name in (
                "browser.sqlite", "baseline.json", "fixtures.json", "storage", "supervisor.json"
            )))
            self.assertFalse(hasattr(m, "main"))

    def test_output_is_deterministic_for_identical_explicit_inputs(self):
        with tempfile.TemporaryDirectory(prefix="candidate-builder-test-") as directory:
            args = self.fixture(directory)
            first = self.build(args)
            args["destination"] = self.new_destination()
            second = self.build(args)
            first_root, second_root = Path(first["directory"]), Path(second["directory"])
            for name in ("source-manifest.json", "runtime.ini", "browser-config.json", "cert.pem", "key.pem"):
                self.assertEqual((first_root / name).read_bytes(), (second_root / name).read_bytes())
            normalized_first = json.loads((first_root / "supervisor-config.json").read_text("utf-8"))
            normalized_second = json.loads((second_root / "supervisor-config.json").read_text("utf-8"))
            for config in (normalized_first, normalized_second):
                config.pop("directory")
                for name in m.RUN_LOCAL_FILES:
                    config[name] = Path(config[name]).name
            self.assertEqual(normalized_first, normalized_second)

    def test_refuses_existing_destination_without_repair_or_overwrite(self):
        with tempfile.TemporaryDirectory(prefix="candidate-builder-test-") as directory:
            args = self.fixture(directory)
            destination = Path(args["destination"])
            destination.mkdir()
            marker = destination / "owned-by-caller"
            marker.write_text("keep", encoding="ascii")
            with self.assertRaisesRegex(m.CandidateRefused, "^destination_freshness$"):
                self.build(args)
            self.assertEqual(marker.read_text("ascii"), "keep")
            self.assertEqual(list(destination.iterdir()), [marker])

    def test_refuses_invalid_destination_scope_before_creation(self):
        with tempfile.TemporaryDirectory(prefix="candidate-builder-test-") as directory:
            base = self.fixture(directory)
            cases = (
                self.temp_root / "wrong-name",
                Path(directory) / ("oncam-checkout-" + "d" * 32),
                str(Path(base["destination"]).absolute()) + "\x00suffix",
            )
            for destination in cases:
                with self.subTest(destination=destination):
                    args = dict(base)
                    args["destination"] = destination
                    with self.assertRaisesRegex(m.CandidateRefused, "^destination_scope$"):
                        self.build(args)

    def test_candidate_parent_must_be_the_canonical_os_temp_root(self):
        with tempfile.TemporaryDirectory(prefix="candidate-builder-test-") as directory:
            args = self.fixture(directory)
            other_parent = Path(directory) / "other-parent"
            other_parent.mkdir()
            args["candidate_parent"] = other_parent
            args["destination"] = other_parent / ("oncam-checkout-" + uuid.uuid4().hex)
            with self.assertRaisesRegex(m.CandidateRefused, "^destination_scope$"):
                self.build(args)
            self.assertFalse(Path(args["destination"]).exists())

    def test_parent_target_and_component_identity_drift_leave_candidate_incomplete(self):
        for boundary in ("parent", "target", "component"):
            with self.subTest(boundary=boundary), \
                    tempfile.TemporaryDirectory(prefix="candidate-builder-test-") as directory:
                args = self.fixture(directory)
                target = Path(args["destination"])
                original = m._directory_identity
                observed = set()

                def drift(path, code):
                    path = Path(path)
                    active = target.exists()
                    matches = (
                        boundary == "parent" and path == self.temp_root
                        or boundary == "target" and path == target
                        or boundary == "component" and path == target / "source"
                    )
                    if active and matches:
                        identity = original(path, code)
                        if path not in observed:
                            observed.add(path)
                            return identity
                        return (identity[0], identity[1] + 1, *identity[2:])
                    return original(path, code)

                with patch.object(m, "_directory_identity", side_effect=drift):
                    with self.assertRaisesRegex(m.CandidateRefused, "^destination_identity$"):
                        self.build(args)
                self.assertFalse((target / "supervisor-config.json").exists())
                if boundary == "component":
                    self.assertTrue((target / m.INCOMPLETE_MARKER).is_file())

    def test_final_rename_identity_drift_keeps_incomplete_marker(self):
        with tempfile.TemporaryDirectory(prefix="candidate-builder-test-") as directory:
            args = self.fixture(directory)
            target = Path(args["destination"])
            final = target / "supervisor-config.json"
            original = m._file_identity

            def drift(path, code):
                if Path(path) == final:
                    identity = original(path, code)
                    return (identity[0], identity[1] + 1, *identity[2:])
                return original(path, code)

            with patch.object(m, "_file_identity", side_effect=drift):
                with self.assertRaisesRegex(m.CandidateRefused, "^destination_identity$"):
                    self.build(args)
            self.assertTrue(final.is_file())
            self.assertTrue((target / m.INCOMPLETE_MARKER).is_file())

    def test_refuses_manifest_path_abuse_and_casefold_collisions(self):
        with tempfile.TemporaryDirectory(prefix="candidate-builder-test-") as directory:
            base = self.fixture(directory)
            invalid = ("../escape.php", "/absolute.php", "app\\Bad.php", "app/NUL\x00.php", ".env")
            for index, relative in enumerate(invalid):
                with self.subTest(relative=relative):
                    args = dict(base)
                    args["destination"] = self.new_destination()
                    args["expected_manifest"] = {**base["expected_manifest"], relative: "0" * 64}
                    with self.assertRaisesRegex(m.CandidateRefused, "^manifest_shape$"):
                        self.build(args)
                    self.assertFalse(Path(args["destination"]).exists())

            args = dict(base)
            args["destination"] = self.new_destination()
            args["expected_manifest"] = {
                **base["expected_manifest"],
                "app/Case.php": "0" * 64,
                "app/case.php": "1" * 64,
            }
            with self.assertRaisesRegex(m.CandidateRefused, "^manifest_shape$"):
                self.build(args)

    def test_refuses_omitted_or_changed_reviewed_source_before_destination(self):
        with tempfile.TemporaryDirectory(prefix="candidate-builder-test-") as directory:
            base = self.fixture(directory)
            relative = "app/Http/Controllers/CheckoutSessionController.php"
            cases = []
            omitted = dict(base["expected_manifest"])
            omitted.pop(relative)
            cases.append(omitted)
            changed = dict(base["expected_manifest"])
            changed[relative] = "f" * 64
            cases.append(changed)
            extra = dict(base["expected_manifest"])
            extra["app/Unexpected.php"] = "f" * 64
            cases.append(extra)
            for index, manifest in enumerate(cases):
                with self.subTest(index=index):
                    args = dict(base)
                    args["destination"] = self.new_destination()
                    args["expected_manifest"] = manifest
                    with self.assertRaises(m.CandidateRefused):
                        self.build(args)
                    self.assertFalse(Path(args["destination"]).exists())

    def test_refuses_symlinked_source_without_creating_destination(self):
        with tempfile.TemporaryDirectory(prefix="candidate-builder-test-") as directory:
            args = self.fixture(directory)
            relative = "composer.json"
            source = Path(args["source_root"]) / relative
            target = Path(directory) / "target.json"
            target.write_bytes(source.read_bytes())
            source.unlink()
            try:
                source.symlink_to(target)
            except OSError:
                self.skipTest("symlink creation unavailable")
            with self.assertRaisesRegex(m.CandidateRefused, "^source_identity$"):
                self.build(args)
            self.assertFalse(Path(args["destination"]).exists())

    def test_refuses_tool_runtime_and_tls_hash_or_shape_mismatch(self):
        with tempfile.TemporaryDirectory(prefix="candidate-builder-test-") as directory:
            base = self.fixture(directory)
            mutations = (
                lambda args: args["tools"]["php"].__setitem__("sha256", "f" * 64),
                lambda args: args["runtime_files"]["ini"].__setitem__("sha256", "f" * 64),
                lambda args: args["runtime_files"]["cert"].__setitem__("bytes", b"not-a-certificate"),
                lambda args: args["runtime_files"]["key"].__setitem__("bytes", b"key\x00data"),
            )
            for index, mutate in enumerate(mutations):
                with self.subTest(index=index):
                    args = {
                        **base,
                        "tools": json.loads(json.dumps(base["tools"])),
                        "runtime_files": {
                            key: dict(value) for key, value in base["runtime_files"].items()
                        },
                        "destination": self.new_destination(),
                    }
                    mutate(args)
                    with self.assertRaises(m.CandidateRefused):
                        self.build(args)
                    self.assertFalse(Path(args["destination"]).exists())

    def test_refuses_cli_or_browser_path_without_exact_approved_suffix(self):
        with tempfile.TemporaryDirectory(prefix="candidate-builder-test-") as directory:
            base = self.fixture(directory)
            for name in ("cli", "browser"):
                with self.subTest(name=name):
                    args = {
                        **base,
                        "destination": self.new_destination(),
                        "tools": json.loads(json.dumps(base["tools"])),
                    }
                    path = Path(directory) / f"arbitrary-{name}.bin"
                    value = f"arbitrary-{name}".encode()
                    path.write_bytes(value)
                    args["tools"][name] = {
                        "path": str(path.absolute()),
                        "sha256": digest(value),
                    }
                    with self.assertRaisesRegex(m.CandidateRefused, "^tool_path$"):
                        self.build(args)
                    self.assertFalse(Path(args["destination"]).exists())

    def test_refuses_suffix_embedded_in_a_larger_first_component(self):
        with tempfile.TemporaryDirectory(prefix="candidate-builder-test-") as directory:
            base = self.fixture(directory)
            for name, suffix in (("cli", m.CLI_SUFFIX), ("browser", m.BROWSER_SUFFIX)):
                with self.subTest(name=name):
                    args = {
                        **base,
                        "destination": self.new_destination(),
                        "tools": json.loads(json.dumps(base["tools"])),
                    }
                    first, *rest = suffix.split("/")
                    path = Path(directory) / f"evil{first}" / Path(*rest)
                    path.parent.mkdir(parents=True, exist_ok=True)
                    value = f"evil-{name}".encode()
                    path.write_bytes(value)
                    args["tools"][name] = {
                        "path": str(path.absolute()),
                        "sha256": digest(value),
                    }
                    with self.assertRaisesRegex(m.CandidateRefused, "^tool_path$"):
                        self.build(args)
                    self.assertFalse(Path(args["destination"]).exists())

    def test_tool_suffix_and_browser_arg_constants_match_supervisor_source_without_import(self):
        supervisor = ast.parse((HERE / "checkout-supervisor.py").read_text("utf-8"))
        assignments = {
            target.id: ast.literal_eval(node.value)
            for node in supervisor.body
            if isinstance(node, ast.Assign)
            for target in node.targets
            if isinstance(target, ast.Name)
            and target.id in {"CLI_SUFFIX", "BROWSER_SUFFIX", "BROWSER_LAUNCH_ARGS"}
        }
        self.assertEqual(assignments, {
            "CLI_SUFFIX": m.CLI_SUFFIX,
            "BROWSER_SUFFIX": m.BROWSER_SUFFIX,
            "BROWSER_LAUNCH_ARGS": m.BROWSER_LAUNCH_ARGS,
        })
        helper = next(
            node for node in supervisor.body
            if isinstance(node, ast.FunctionDef) and node.name == "_approved_tool_path"
        )
        isolated = {}
        exec(compile(ast.fix_missing_locations(ast.Module(body=[helper], type_ignores=[])),
                     "<supervisor-helper>", "exec"), isolated)
        cases = (
            ("C:\\approved\\" + m.CLI_SUFFIX.replace("/", "\\"), m.CLI_SUFFIX, True),
            ("/approved/" + m.BROWSER_SUFFIX, m.BROWSER_SUFFIX, True),
            ("C:\\evil" + m.CLI_SUFFIX.replace("/", "\\"), m.CLI_SUFFIX, False),
            ("/evil" + m.BROWSER_SUFFIX, m.BROWSER_SUFFIX, False),
        )
        for path, suffix, expected in cases:
            with self.subTest(path=path):
                self.assertEqual(m._approved_tool_path(path, suffix), expected)
                self.assertEqual(isolated["_approved_tool_path"](path, suffix), expected)

    def test_partial_copy_failure_is_never_deleted_or_finalized(self):
        with tempfile.TemporaryDirectory(prefix="candidate-builder-test-") as directory:
            args = self.fixture(directory)
            original = m._copy_verified
            calls = 0

            def fail_after_one(*values, **keywords):
                nonlocal calls
                calls += 1
                if calls == 2:
                    raise OSError("synthetic write failure")
                return original(*values, **keywords)

            with patch.object(m, "_copy_verified", side_effect=fail_after_one):
                with self.assertRaisesRegex(m.CandidateRefused, "^candidate_write$"):
                    self.build(args)
            destination = Path(args["destination"])
            self.assertTrue(destination.is_dir())
            self.assertFalse((destination / "supervisor-config.json").exists())
            self.assertTrue(any(destination.rglob("*")))
            with self.assertRaisesRegex(m.CandidateRefused, "^destination_freshness$"):
                self.build(args)

    def test_result_and_inputs_cannot_mutate_persisted_configuration(self):
        with tempfile.TemporaryDirectory(prefix="candidate-builder-test-") as directory:
            args = self.fixture(directory)
            result = self.build(args)
            destination = Path(args["destination"])
            expected = json.loads((destination / "supervisor-config.json").read_text("utf-8"))
            result["manifest"] = "0" * 64
            result["tool_hashes"]["php"] = "0" * 64
            args["tools"]["php"]["sha256"] = "0" * 64
            args["asset_delivery_review"].clear()
            self.assertEqual(
                json.loads((destination / "supervisor-config.json").read_text("utf-8")), expected
            )

    def test_module_has_no_runner_environment_or_subprocess_boundary(self):
        source = (HERE / "checkout-candidate-builder.py").read_text("utf-8")
        tree = ast.parse(source)
        imports = {
            alias.name
            for node in ast.walk(tree)
            if isinstance(node, (ast.Import, ast.ImportFrom))
            for alias in node.names
        }
        self.assertNotIn("subprocess", imports)
        self.assertNotIn("os.environ", source)
        self.assertNotIn("getenv", source)
        self.assertNotIn("__main__", source)


if __name__ == "__main__":
    unittest.main()
