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


def static_config_keys(module, tool_keys):
    def targets_config_keys(target):
        return any(isinstance(item, ast.Name) and item.id == "CONFIG_KEYS"
                   for item in ast.walk(target))

    writes = []
    for node in ast.walk(module):
        if isinstance(node, ast.Assign) and any(
                targets_config_keys(target) for target in node.targets):
            writes.append(node)
        elif isinstance(node, (ast.AnnAssign, ast.AugAssign, ast.NamedExpr)) \
                and targets_config_keys(node.target):
            writes.append(node)
        elif isinstance(node, ast.Delete) and any(
                targets_config_keys(target) for target in node.targets):
            writes.append(node)
        elif isinstance(node, ast.Call) and (
            isinstance(node.func, ast.Attribute)
            and targets_config_keys(node.func.value)
            or isinstance(node.func, ast.Name)
            and node.func.id in {"setattr", "delattr"}
            and node.args
            and targets_config_keys(node.args[0])
        ):
            writes.append(node)

    assert len(writes) == 1
    assignment = writes[0]
    assert isinstance(assignment, ast.Assign)
    assert len(assignment.targets) == 1
    assert isinstance(assignment.targets[0], ast.Name)
    assert assignment.targets[0].id == "CONFIG_KEYS"
    value = assignment.value
    assert isinstance(value, ast.Call)
    assert isinstance(value.func, ast.Name) and value.func.id == "frozenset"
    assert len(value.args) == 1 and not value.keywords
    assert isinstance(value.args[0], ast.Set)

    constants = []
    stars = 0
    for item in value.args[0].elts:
        if isinstance(item, ast.Constant) and type(item.value) is str:
            constants.append(item.value)
        elif isinstance(item, ast.Starred) and isinstance(item.value, ast.Name) \
                and item.value.id == "CONFIG_TOOL_KEYS":
            stars += 1
        else:
            raise AssertionError("CONFIG_KEYS contains a non-allowlisted expression")
    assert stars == 1
    assert len(constants) == len(set(constants))
    return frozenset((*constants, *tool_keys))


TEST_CERT = b"""-----BEGIN CERTIFICATE-----
MIIBJTCBy6ADAgECAgECMAoGCCqGSM49BAMCMBwxGjAYBgNVBAMMEXN5bnRoZXRp
Yy5pbnZhbGlkMB4XDTI2MDkwNDIwNTAwNFoXDTI2MDkwNjIwNTAwNFowHDEaMBgG
A1UEAwwRc3ludGhldGljLmludmFsaWQwWTATBgcqhkjOPQIBBggqhkjOPQMBBwNC
AARnQPQWUcOTSwjMotK+AW3SuHwqZfiDkGsNJZdhl3m4qtzkwOLp9N03Of5tWYUq
F+qJxQirT881d7r/bj5F+2UqMAoGCCqGSM49BAMCA0kAMEYCIQDkcZiEWyF/HDq9
TzRq9pwSTIMIu1BCoXatfk++V8uu5QIhANB/Yh0VS7kndP96B7CwJQKV8ZCVxjoW
+5TA8ZcxN4GN
-----END CERTIFICATE-----
"""
_PEM_BEGIN = b"-----BEGIN "
_PEM_END = b"-----END "
_PKCS8_LABEL = b"PRIVATE " + b"KEY"
_ENCRYPTED_LABEL = b"ENCRYPTED " + _PKCS8_LABEL
_RSA_LABEL = b"RSA " + _PKCS8_LABEL
TEST_KEY = _PEM_BEGIN + _PKCS8_LABEL + b"-----\n" + b"""MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQgEeL2CTBm1yWncpOX
IJTzL8jDClZlP7g7Kp1WFSCHIE2hRANCAARnQPQWUcOTSwjMotK+AW3SuHwqZfiD
kGsNJZdhl3m4qtzkwOLp9N03Of5tWYUqF+qJxQirT881d7r/bj5F+2Uq
""" + _PEM_END + _PKCS8_LABEL + b"-----\n"
OTHER_TEST_KEY = _PEM_BEGIN + _PKCS8_LABEL + b"-----\n" + b"""MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQgiBEdz17KENSFO7QD
4jukhcezLVKxS9H0JXrYp1+3WuyhRANCAATnOb3Mrxi/OtCxB2j94u48E6MwEsSt
uscAPPvd7gIwuGzATXQj95tIPLrsdcIMkwSJGyT5cWsx0o6QGmR9Y9cV
""" + _PEM_END + _PKCS8_LABEL + b"-----\n"
ENCRYPTED_TEST_KEY = _PEM_BEGIN + _ENCRYPTED_LABEL + b"-----\n" + b"""MIH0MF8GCSqGSIb3DQEFDTBSMDEGCSqGSIb3DQEFDDAkBBClAyXBMXWne/fLInys
x5aNAgIIADAMBggqhkiG9w0CCQUAMB0GCWCGSAFlAwQBKgQQAZufQVX/4AVMdI2g
8J8w6gSBkKQKCFYoYTgknHXeWYDb5VQy4vA8hGrmLr5AB8pEd+0GneYI4MAk6iOp
KxX5tQOeNRqNbYzbv3pA7jlhTlrJq9wrhIutP/TTBHDxiF6gNy03NZGjFCGod8kg
MFJ1RVMdzDAFmtbz330U3apyfZrELyG6R97heltggnBzFcJgJNJ7aazWye/4KSz0
dErnCPTxyA==
""" + _PEM_END + _ENCRYPTED_LABEL + b"-----\n"
LEGACY_ENCRYPTED_TEST_KEY = _PEM_BEGIN + _RSA_LABEL + b"-----\n" + b"""Proc-Type: 4,ENCRYPTED
DEK-Info: AES-256-CBC,9BD501556DF358E7269489B982E5FC7B

tot27kLEg8vggCEVU1kK7UKE8O0uPmat+t49hNcMWxpC1MAN+UYo3i9CH+1Gg19G
C5c3h8Qb5z1EtkgDATmdrwzqkZTagrBYCStydVYByLTqI5AoP7WJ5CKi8toef4hn
KFZGio3hRwFtfsqd1ajJuCzKGiWilNmSJg7HAnssc6fG/Dqi2cCwih1t4hyVuvGf
RmNsZutrqTUrVNLm4ZxbQEuZz5q+TdW1scE5y6U7PIosOtTgIPQx1Hf236u9Rfeo
Vl7+WyabthEYYsqWPibGUgVNF2EmSiFVG2ZkFNLihJoFsDjZo9WSeDfQz14iS37L
8UsPITEO7nuoWhH/63dRgYJeoiBM04iKZXa6KzlsW+DUeAqarEjmmDZEJwTKIiUW
6QB9wE1VVkckUZ9g9wyvO2UQGI9chP5T9RCCY/b7NlR37gpeZvEZ9Ow5akzqsiFc
8YtvASgiWI0+rmGJx2JZxfHrr+/QMNnIIjj460XYomFaDBhZYsqpw4EJOvDlELkW
8PSnsXRbR1ugdW1Xiz2KiY7zSLzUtrjJ0W205nCeEXNvmrWdi8YNa81s2uEJ7tdC
hXkdMvozcaeDwxb+i8R7UTO3FKpr/1aCXJ4cHhRldZnPkyoN2RJwCbR2FDl9n9Vq
HZ7D4t/c2hvRMTznz1IcWW5WaQqmf/qS6tizg9ihSEi5WGrVRphsxieUP58q/x+i
SPFnYWAIVO21hYhWPsltOxOB1gSR6cxlxGm5SYWUPLi4sjZvFdlkc+kTQ3Y7Q5x+
RR5Ezo8K5S5fxvWPS67pjB0+2y+0ySET0PCZ3p731NpanzoEJMFr+T9qgG53kXDB
""" + _PEM_END + _RSA_LABEL + b"-----\n"


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
            value = ((HERE / Path(relative).name).read_bytes()
                     if relative == m.ACL_POLICY_FILE
                     else f"reviewed-source-{index}\n".encode())
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
        cert = TEST_CERT
        key = TEST_KEY
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
            self.assertEqual(config["acl_policy_digest"], m.ACL_POLICY_DIGEST)
            self.assertEqual(
                args["expected_manifest"][m.ACL_POLICY_FILE], m.ACL_POLICY_DIGEST
            )
            self.assertEqual(json.loads(manifest_bytes), args["expected_manifest"])
            self.assertTrue({
                "tools/testing/tests/Browser/checkout-acl-attestation.py",
                "tools/testing/tests/Browser/checkout-coordinator-lease.py",
                "tools/testing/tests/Browser/test_checkout_acl_attestation.py",
                "tools/testing/tests/Browser/test_checkout_coordinator_lease.py",
            } <= set(args["expected_manifest"]))
            self.assertEqual(config["asset_delivery_review"], args["asset_delivery_review"])
            self.assertEqual(
                set(config["asset_delivery_review"]), set(m.ASSET_REVIEW_FILES)
            )
            self.assertEqual(set(config), m.CONFIG_KEYS)
            self.assertEqual(set(config["tool_hashes"]), set(m.ALL_TOOL_KEYS))
            for relative in (
                "tools/testing/tests/Browser/checkout-ordinary-privilege-authority.py",
                "tools/testing/tests/Browser/test_checkout_ordinary_privilege_authority.py",
            ):
                source_bytes = (
                    Path(args["source_root"]) / Path(*relative.split("/"))
                ).read_bytes()
                copied_bytes = (
                    destination / "source" / Path(*relative.split("/"))
                ).read_bytes()
                self.assertEqual(copied_bytes, source_bytes)
                self.assertEqual(args["expected_manifest"][relative], digest(source_bytes))
            self.assertEqual((destination / "source-revision.txt").read_text("ascii"), "b" * 40 + "\n")
            self.assertFalse(any((destination / name).exists() for name in (
                "browser.sqlite", "baseline.json", "fixtures.json", "storage", "supervisor.json"
            )))
            self.assertFalse(hasattr(m, "main"))

    def test_acl_policy_authority_is_compiled_and_not_caller_selectable(self):
        with tempfile.TemporaryDirectory(prefix="candidate-builder-test-") as directory:
            args = self.fixture(directory)
            policy = Path(args["source_root"]) / Path(*m.ACL_POLICY_FILE.split("/"))
            document = json.loads(policy.read_bytes())
            document["policyId"] = "caller-selected"
            changed = (json.dumps(document, sort_keys=True, separators=(",", ":")) + "\n").encode()
            policy.write_bytes(changed)
            args["expected_manifest"][m.ACL_POLICY_FILE] = digest(changed)
            with self.assertRaisesRegex(m.CandidateRefused, "^acl_policy$"):
                self.build(args)
            self.assertFalse(Path(args["destination"]).exists())

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

    def test_source_root_component_and_reparse_swap_before_publication_fail_closed(self):
        for boundary in ("root", "component", "reparse"):
            with self.subTest(boundary=boundary), \
                    tempfile.TemporaryDirectory(prefix="candidate-builder-test-") as directory:
                args = self.fixture(directory)
                source = Path(args["source_root"])
                target = Path(args["destination"])
                original_write = m._write_new
                mutated = False

                def swap_before_publication(path, value, guard):
                    nonlocal mutated
                    if Path(path).name == "supervisor-config.pending" and not mutated:
                        mutated = True
                        selected = source if boundary == "root" else source / "app"
                        backup = source.parent / f"{selected.name}-{boundary}-original"
                        selected.rename(backup)
                        if boundary == "reparse":
                            try:
                                selected.symlink_to(backup, target_is_directory=True)
                            except OSError:
                                backup.rename(selected)
                                self.skipTest("directory symlink creation unavailable")
                        else:
                            shutil.copytree(backup, selected)
                    return original_write(path, value, guard)

                with patch.object(m, "_write_new", side_effect=swap_before_publication):
                    with self.assertRaisesRegex(m.CandidateRefused, "^source_identity$"):
                        self.build(args)
                self.assertTrue(mutated)
                self.assertTrue((target / m.INCOMPLETE_MARKER).is_file())
                self.assertFalse((target / "supervisor-config.json").exists())

    def test_descriptor_cleanup_preserves_primary_and_attempts_every_close(self):
        class ReadInterrupted(BaseException):
            pass

        class IdentityInterrupted(BaseException):
            pass

        with tempfile.TemporaryDirectory(prefix="candidate-builder-test-") as directory:
            root = Path(directory)
            source_root = root / "source"
            destination = root / "candidate"
            source_root.mkdir()
            destination.mkdir()
            source = source_root / "input.php"
            target = destination / "output.php"
            value = b"reviewed"
            source.write_bytes(value)
            source_guard = m._SourceGuard(
                source_root, m._directory_identity(source_root, "source_identity")
            )
            destination_guard = m._DestinationGuard(
                root,
                m._directory_identity(root, "destination_identity"),
                destination,
                m._directory_identity(destination, "destination_identity"),
            )
            real_close = os.close
            closed = []

            def close_then_fail_first(descriptor):
                closed.append(descriptor)
                real_close(descriptor)
                if len(closed) == 1:
                    raise OSError("synthetic close failure")

            interrupted = ReadInterrupted()
            with patch.object(m.os, "read", side_effect=interrupted), \
                    patch.object(m.os, "close", side_effect=close_then_fail_first):
                with self.assertRaises(ReadInterrupted) as caught:
                    m._copy_verified(
                        source, target, digest(value), destination_guard, source_guard
                    )
            self.assertIs(caught.exception, interrupted)
            self.assertEqual(len(closed), 2)

            identity_interrupted = IdentityInterrupted()
            identity_closes = []

            def close_after_identity_failure(descriptor):
                identity_closes.append(descriptor)
                real_close(descriptor)
                raise OSError("synthetic close failure")

            with patch.object(m, "_revalidate_file", side_effect=identity_interrupted), \
                    patch.object(m.os, "close", side_effect=close_after_identity_failure):
                with self.assertRaises(IdentityInterrupted) as caught:
                    m._open_verified(source, digest(value), guard=source_guard)
            self.assertIs(caught.exception, identity_interrupted)
            self.assertEqual(len(identity_closes), 1)

    def test_standalone_descriptor_close_failures_are_fixed_refusals(self):
        with tempfile.TemporaryDirectory(prefix="candidate-builder-test-") as directory:
            root = Path(directory)
            source_root = root / "source"
            destination = root / "candidate"
            source_root.mkdir()
            destination.mkdir()
            source = source_root / "input.php"
            value = b"reviewed"
            source.write_bytes(value)
            source_guard = m._SourceGuard(
                source_root, m._directory_identity(source_root, "source_identity")
            )
            real_close = os.close

            def close_then_fail(descriptor):
                real_close(descriptor)
                raise OSError("synthetic close failure")

            with patch.object(m.os, "close", side_effect=close_then_fail):
                with self.assertRaisesRegex(m.CandidateRefused, "^source_identity$"):
                    m._hash_verified(source, digest(value), source_guard)

            target = destination / "output.php"
            destination_guard = m._DestinationGuard(
                root,
                m._directory_identity(root, "destination_identity"),
                destination,
                m._directory_identity(destination, "destination_identity"),
            )
            close_count = 0

            def fail_target_close(descriptor):
                nonlocal close_count
                close_count += 1
                real_close(descriptor)
                if close_count == 2:
                    raise OSError("synthetic target close failure")

            with patch.object(m.os, "close", side_effect=fail_target_close):
                with self.assertRaisesRegex(m.CandidateRefused, "^destination_identity$"):
                    m._copy_verified(
                        source, target, digest(value), destination_guard, source_guard
                    )
            self.assertEqual(close_count, 2)

    def test_close_only_non_exception_baseexceptions_propagate_after_all_closes(self):
        with tempfile.TemporaryDirectory(prefix="candidate-builder-test-") as directory:
            root = Path(directory)
            source_root = root / "source"
            destination = root / "candidate"
            source_root.mkdir()
            destination.mkdir()
            source = source_root / "input.php"
            value = b"reviewed"
            source.write_bytes(value)
            source_guard = m._SourceGuard(
                source_root, m._directory_identity(source_root, "source_identity")
            )
            destination_guard = m._DestinationGuard(
                root,
                m._directory_identity(root, "destination_identity"),
                destination,
                m._directory_identity(destination, "destination_identity"),
            )
            real_close = os.close

            for index, interruption in enumerate((KeyboardInterrupt(), SystemExit(9))):
                with self.subTest(interruption=type(interruption).__name__):
                    closed = []

                    def close_then_interrupt_source(descriptor):
                        closed.append(descriptor)
                        real_close(descriptor)
                        if len(closed) == 1:
                            raise interruption

                    with patch.object(m.os, "close", side_effect=close_then_interrupt_source):
                        with self.assertRaises(type(interruption)) as caught:
                            m._copy_verified(
                                source,
                                destination / f"output-{index}.php",
                                digest(value),
                                destination_guard,
                                source_guard,
                            )
                    self.assertIs(caught.exception, interruption)
                    self.assertEqual(len(closed), 2)

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

    def test_acl_preparation_sources_are_exact_required_manifest_members(self):
        accepted = frozenset({
            "tools/testing/tests/Browser/checkout-acl-source-binding.py",
            "tools/testing/tests/Browser/checkout-acl-source-tree.py",
            "tools/testing/tests/Browser/test_checkout_acl_source_binding.py",
            "tools/testing/tests/Browser/test_checkout_acl_source_tree.py",
            "tools/testing/tests/Browser/checkout-windows-acl-attestor.py",
            "tools/testing/tests/Browser/test_checkout_windows_acl_attestor.py",
            "tools/testing/tests/Browser/checkout-ordinary-access-request.py",
            "tools/testing/tests/Browser/test_checkout_ordinary_access_request.py",
            "tools/testing/tests/Browser/checkout-ordinary-privilege-authority.py",
            "tools/testing/tests/Browser/test_checkout_ordinary_privilege_authority.py",
        })
        self.assertTrue(accepted <= m.BROWSER_TOOLS)
        self.assertTrue(accepted <= m.REQUIRED_SOURCE)
        self.assertTrue(all(m._allowed_source(relative) for relative in accepted))
        self.assertFalse(m._allowed_source(
            "tools/testing/tests/Browser/checkout-ordinary-privilege-authority.py.bak"
        ))

        class Guard:
            def validate(self):
                return None

        manifest = {
            relative: "a" * 64 for relative in sorted(m.REQUIRED_SOURCE)
        }
        with patch.object(m, "_inventory", return_value=set(manifest)), \
                patch.object(m, "_hash_verified"):
            self.assertEqual(
                m._validated_manifest(manifest, Path("C:/reviewed-source"), Guard()),
                manifest,
            )

        for relative in sorted(accepted):
            with self.subTest(omitted=relative):
                omitted = dict(manifest)
                omitted.pop(relative)
                with patch.object(m, "_inventory", return_value=set(omitted)), \
                        patch.object(m, "_hash_verified"):
                    with self.assertRaisesRegex(
                            m.CandidateRefused, "^manifest_inventory$"):
                        m._validated_manifest(
                            omitted, Path("C:/reviewed-source"), Guard(),
                        )

        collision = {
            **manifest,
            "tools/testing/tests/Browser/Checkout-Ordinary-Privilege-Authority.py":
                "b" * 64,
        }
        with self.assertRaisesRegex(m.CandidateRefused, "^manifest_shape$"):
            m._validated_manifest(
                collision, Path("C:/reviewed-source"), Guard(),
            )

        extra = {
            **manifest,
            "tools/testing/tests/Browser/checkout-unreviewed.py": "b" * 64,
        }
        with self.assertRaisesRegex(m.CandidateRefused, "^manifest_shape$"):
            m._validated_manifest(extra, Path("C:/reviewed-source"), Guard())

        collision = {
            **manifest,
            "TOOLS/TESTING/TESTS/BROWSER/CHECKOUT-ACL-SOURCE-TREE.PY": "b" * 64,
        }
        with self.assertRaisesRegex(m.CandidateRefused, "^manifest_shape$"):
            m._validated_manifest(collision, Path("C:/reviewed-source"), Guard())

    def test_packaged_privilege_authority_has_exact_transitive_source_closure(self):
        authority = HERE / "checkout-ordinary-privilege-authority.py"
        tree = ast.parse(authority.read_text(encoding="utf-8"), authority.name)
        dependencies = tuple(
            node.args[1].value
            for node in ast.walk(tree)
            if isinstance(node, ast.Call)
            and isinstance(node.func, ast.Name)
            and node.func.id == "_load_fixed"
            and len(node.args) == 2
            and isinstance(node.args[1], ast.Constant)
            and type(node.args[1].value) is str
        )
        self.assertEqual(dependencies, (
            "checkout-ordinary-authority-manifest.py",
            "checkout-ordinary-broker-start-identity.py",
            "checkout-ordinary-access-request.py",
        ))

        required = frozenset(
            f"tools/testing/tests/Browser/{filename}"
            for filename in dependencies
        ) | frozenset(
            f"tools/testing/tests/Browser/test_{filename.replace('-', '_')}"
            for filename in dependencies
        )
        self.assertTrue(required <= m.BROWSER_TOOLS)
        self.assertTrue(required <= m.REQUIRED_SOURCE)
        self.assertTrue(all(m._allowed_source(relative) for relative in required))

        class Guard:
            def validate(self):
                return None

        manifest = {
            relative: "a" * 64 for relative in sorted(m.REQUIRED_SOURCE)
        }
        for relative in sorted(required):
            with self.subTest(omitted=relative):
                omitted = dict(manifest)
                omitted.pop(relative)
                with patch.object(m, "_inventory", return_value=set(omitted)), \
                        patch.object(m, "_hash_verified"):
                    with self.assertRaisesRegex(
                            m.CandidateRefused, "^manifest_inventory$"):
                        m._validated_manifest(
                            omitted, Path("C:/reviewed-source"), Guard(),
                        )

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

    def test_asset_delivery_review_is_exact_and_manifest_bound_before_target(self):
        def old_four(args):
            args["asset_delivery_review"] = {
                name: args["expected_manifest"][name]
                for name in (
                    "public/css/checkout-summary-v1.css",
                    "public/brand/oncam-logo-full-color.png",
                    "resources/views/checkout/summary.blade.php",
                    "tools/testing/tests/Browser/serve-checkout-session.php",
                )
            }

        def missing(args):
            args["asset_delivery_review"].pop(m.ASSET_REVIEW_FILES[0])

        def extra(args):
            args["asset_delivery_review"]["public/extra.js"] = "f" * 64

        def wrong_type(args):
            args["asset_delivery_review"][m.ASSET_REVIEW_FILES[0]] = True

        def invalid_hash(args):
            args["asset_delivery_review"][m.ASSET_REVIEW_FILES[0]] = "f" * 63

        def manifest_mismatch(args):
            args["asset_delivery_review"][m.ASSET_REVIEW_FILES[0]] = "f" * 64

        mutations = (old_four, missing, extra, wrong_type, invalid_hash, manifest_mismatch)
        with tempfile.TemporaryDirectory(prefix="candidate-builder-test-") as directory:
            base = self.fixture(directory)
            for mutate in mutations:
                with self.subTest(case=mutate.__name__):
                    args = {
                        **base,
                        "destination": self.new_destination(),
                        "asset_delivery_review": dict(base["asset_delivery_review"]),
                    }
                    mutate(args)
                    with self.assertRaisesRegex(m.CandidateRefused, "^asset_review$"):
                        self.build(args)
                    self.assertFalse(Path(args["destination"]).exists())

    def test_certificate_pair_semantics_fail_closed_after_guarded_write(self):
        malformed_cert = (
            b"-----BEGIN CERTIFICATE-----\nnot-base64\n-----END CERTIFICATE-----\n"
        )
        unsupported_key = (
            _PEM_BEGIN + _PKCS8_LABEL + b"-----\nnot-base64\n"
            + _PEM_END + _PKCS8_LABEL + b"-----\n"
        )
        cases = (
            ("mismatched", "key", OTHER_TEST_KEY),
            ("malformed", "cert", malformed_cert),
            ("encrypted", "key", ENCRYPTED_TEST_KEY),
            ("legacy-encrypted", "key", LEGACY_ENCRYPTED_TEST_KEY),
            ("unsupported", "key", unsupported_key),
        )
        with tempfile.TemporaryDirectory(prefix="candidate-builder-test-") as directory:
            for index, (label, field, value) in enumerate(cases):
                with self.subTest(label=label):
                    root = Path(directory) / str(index)
                    root.mkdir()
                    args = self.fixture(root)
                    args["runtime_files"][field] = {
                        "bytes": value,
                        "sha256": digest(value),
                    }
                    with self.assertRaisesRegex(m.CandidateRefused, "^certificate_semantics$"):
                        self.build(args)
                    target = Path(args["destination"])
                    self.assertTrue((target / m.INCOMPLETE_MARKER).is_file())
                    self.assertFalse((target / "supervisor-config.json").exists())

    def test_certificate_postcheck_hash_drift_overrides_ssl_failure_without_prompt(self):
        with tempfile.TemporaryDirectory(prefix="candidate-builder-test-") as directory:
            args = self.fixture(directory)
            seen = {}

            class DriftingContext:
                def load_cert_chain(self, *, certfile, keyfile, password):
                    seen["password"] = password
                    Path(certfile).write_bytes(TEST_CERT + b"tampered")
                    raise m.ssl.SSLError("PRIVATE_OPENSSL_TEXT")

            with patch.object(m.ssl, "SSLContext", return_value=DriftingContext()):
                with self.assertRaisesRegex(m.CandidateRefused, "^destination_identity$"):
                    self.build(args)
            self.assertTrue(callable(seen["password"]))
            with self.assertRaisesRegex(m.CandidateRefused, "^certificate_semantics$"):
                seen["password"]()
            source = (HERE / "checkout-candidate-builder.py").read_text("utf-8")
            self.assertNotIn("getpass", source)
            self.assertNotIn("input(", source)
            private_header = r"BEGIN [^\r\n]*PRIVATE " + "KEY"
            self.assertIsNone(re.search(private_header, source))
            self.assertIsNone(re.search(
                private_header,
                (HERE / "test_checkout_candidate_builder.py").read_text("utf-8"),
            ))
            target = Path(args["destination"])
            self.assertTrue((target / m.INCOMPLETE_MARKER).is_file())
            self.assertFalse((target / "supervisor-config.json").exists())

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

    def test_builder_constants_match_supervisor_source_without_import(self):
        supervisor = ast.parse((HERE / "checkout-supervisor.py").read_text("utf-8"))
        assignments = {
            target.id: ast.literal_eval(node.value)
            for node in supervisor.body
            if isinstance(node, ast.Assign)
            for target in node.targets
            if isinstance(target, ast.Name)
            and target.id in {
                "CLI_SUFFIX", "BROWSER_SUFFIX", "BROWSER_LAUNCH_ARGS", "CONFIG_TOOL_KEYS",
                "DELIVERED_ASSETS", "ASSET_REVIEW_FILES", "ACL_POLICY_FILE",
                "ACL_POLICY_DIGEST",
            }
        }
        self.assertEqual(assignments, {
            "CLI_SUFFIX": m.CLI_SUFFIX,
            "BROWSER_SUFFIX": m.BROWSER_SUFFIX,
            "BROWSER_LAUNCH_ARGS": m.BROWSER_LAUNCH_ARGS,
            "CONFIG_TOOL_KEYS": m.ALL_TOOL_KEYS,
            "DELIVERED_ASSETS": m.DELIVERED_ASSETS,
            "ASSET_REVIEW_FILES": m.ASSET_REVIEW_FILES,
            "ACL_POLICY_FILE": m.ACL_POLICY_FILE,
            "ACL_POLICY_DIGEST": m.ACL_POLICY_DIGEST,
        })
        self.assertEqual(static_config_keys(supervisor, m.ALL_TOOL_KEYS), m.CONFIG_KEYS)
        self.assertEqual(m.ASSET_REVIEW_FILES[:4], m.DELIVERED_ASSETS)
        supervisor_helper = next(
            node for node in supervisor.body
            if isinstance(node, ast.FunctionDef) and node.name == "_approved_tool_path"
        )
        builder = ast.parse((HERE / "checkout-candidate-builder.py").read_text("utf-8"))
        builder_helper = next(
            node for node in builder.body
            if isinstance(node, ast.FunctionDef) and node.name == "_approved_tool_path"
        )
        self.assertEqual(ast.dump(supervisor_helper), ast.dump(builder_helper))
        cases = (
            ("C:\\approved\\" + m.CLI_SUFFIX.replace("/", "\\"), m.CLI_SUFFIX, True),
            ("/approved/" + m.BROWSER_SUFFIX, m.BROWSER_SUFFIX, True),
            ("C:\\evil" + m.CLI_SUFFIX.replace("/", "\\"), m.CLI_SUFFIX, False),
            ("/evil" + m.BROWSER_SUFFIX, m.BROWSER_SUFFIX, False),
        )
        for path, suffix, expected in cases:
            with self.subTest(path=path):
                self.assertEqual(m._approved_tool_path(path, suffix), expected)

    def test_supervisor_config_key_static_reader_refuses_writes_and_expressions(self):
        valid = ast.parse(
            'CONFIG_KEYS = frozenset({"directory", *CONFIG_TOOL_KEYS, "manifest"})'
        )
        self.assertEqual(
            static_config_keys(valid, ("php", "node")),
            frozenset({"directory", "php", "node", "manifest"}),
        )
        invalid = (
            'CONFIG_KEYS = frozenset({"directory", *CONFIG_TOOL_KEYS})\nCONFIG_KEYS = frozenset()',
            'CONFIG_KEYS: frozenset = frozenset({"directory", *CONFIG_TOOL_KEYS})',
            'CONFIG_KEYS = frozenset({"directory", *CONFIG_TOOL_KEYS})\nCONFIG_KEYS |= {"extra"}',
            'CONFIG_KEYS = frozenset({"directory", *CONFIG_TOOL_KEYS})\nCONFIG_KEYS.add("extra")',
            'CONFIG_KEYS = dangerous({"directory", *CONFIG_TOOL_KEYS})',
            'CONFIG_KEYS = frozenset({"directory", *OTHER_KEYS})',
            'CONFIG_KEYS = frozenset({"directory", *CONFIG_TOOL_KEYS, *CONFIG_TOOL_KEYS})',
            'CONFIG_KEYS = frozenset({"directory", "directory", *CONFIG_TOOL_KEYS})',
        )
        for source in invalid:
            with self.subTest(source=source), self.assertRaises(AssertionError):
                static_config_keys(ast.parse(source), ("php", "node"))

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
            result["asset_delivery_review"].clear()
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
