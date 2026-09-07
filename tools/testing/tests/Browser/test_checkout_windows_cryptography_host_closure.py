import copy
import csv
import hashlib
import importlib.util
import io
import unittest
from types import MappingProxyType


MODULE_PATH = __file__.replace(
    "test_checkout_windows_cryptography_host_closure.py",
    "checkout-windows-cryptography-host-closure.py",
)
SPEC = importlib.util.spec_from_file_location("checkout_windows_crypto_host", MODULE_PATH)
host = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(host)


def digest(raw):
    return hashlib.sha256(raw).hexdigest()


def record(rows):
    output = io.StringIO(newline="")
    csv.writer(output, lineterminator="\n").writerows(
        [[path, "sha256=" + __import__("base64").urlsafe_b64encode(
            hashlib.sha256(raw).digest()).decode().rstrip("="), str(len(raw))]
         for path, raw in rows]
    )
    return output.getvalue().encode("ascii")


def fixture():
    files = {
        "python.exe": b"python",
        "python314.dll": b"python-dll",
        "python3.dll": b"stable-python-dll",
        "VCRUNTIME140.dll": b"vcruntime",
        "ucrtbase.dll": b"ucrt",
        "loader-policy.json": b'{"dllSearch":"isolated-root-only","version":1}\n',
        "cryptography/hazmat/bindings/_rust.pyd": b"rust",
        "_cffi_backend.cp314-win_amd64.pyd": b"cffi",
        "pycparser/__init__.py": b"parser",
    }
    package_rows = {
        "cryptography": [("cryptography/hazmat/bindings/_rust.pyd", files["cryptography/hazmat/bindings/_rust.pyd"])],
        "cffi": [("_cffi_backend.cp314-win_amd64.pyd", files["_cffi_backend.cp314-win_amd64.pyd"])],
        "pycparser": [("pycparser/__init__.py", files["pycparser/__init__.py"])],
    }
    versions = {"cryptography": "50.0.1", "cffi": "2.1.1", "pycparser": "3.0"}
    wheel_names = {
        "cryptography": "cryptography-50.0.1-cp311-abi3-win_amd64.whl",
        "cffi": "cffi-2.1.1-cp314-cp314-win_amd64.whl",
        "pycparser": "pycparser-3.0-py3-none-any.whl",
    }
    entry_counts = {"cryptography": 120, "cffi": 31, "pycparser": 13}
    for name in ("cryptography", "cffi", "pycparser"):
        for number in range(entry_counts[name] - 1 - len(package_rows[name])):
            path = f"{name}/closure-{number:03d}.dat"
            raw = f"{name}-{number}".encode("ascii")
            files[path] = raw
            package_rows[name].append((path, raw))
        package_rows[name].sort(key=lambda item: (item[0].casefold(), item[0]))
    packages = []
    for index, name in enumerate(("cryptography", "cffi", "pycparser"), 20):
        record_path = f"{name}-{versions[name]}.dist-info/RECORD"
        files[record_path] = record(package_rows[name]) + f"{record_path},,\n".encode("ascii")
        entries = []
        for path, raw in package_rows[name]:
            entries.append({"path": path, "sha256": digest(raw), "size": len(raw)})
        packages.append({
            "name": name, "version": versions[name], "wheelFilename": wheel_names[name],
            "recordPath": record_path, "recordSha256": digest(files[record_path]),
            "files": entries,
        })
    identities = {
        path: ("77", str(index + 100), False, f"C:/isolated/{path}")
        for index, path in enumerate(files)
    }
    manifest = {
        "version": 1,
        "root": {"path": "C:/isolated", "volumeSerial": "77", "fileId": "1"},
        "python": {
            "path": "python.exe", "version": "3.14.0", "implementation": "cpython",
            "architecture": "amd64", "sha256": digest(files["python.exe"]),
            "imports": ["python314.dll", "VCRUNTIME140.dll", "ucrtbase.dll"],
        },
        "loaderPolicy": {
            "path": "loader-policy.json", "sha256": digest(files["loader-policy.json"]),
            "mode": "isolated-root-only",
        },
        "runtimeFiles": [
            {"path": path, "sha256": digest(files[path])}
            for path in ("python314.dll", "python3.dll", "VCRUNTIME140.dll", "ucrtbase.dll")
        ],
        "packages": packages,
        "systemDllAllowlist": ["KERNEL32.dll", "USER32.dll"],
    }
    manifest["systemDllAllowlist"] = list(host.ACCEPTED_SYSTEM_DLLS)
    imports = {
        "python.exe": {"normal": tuple(manifest["python"]["imports"]), "delay": ()},
        "cryptography/hazmat/bindings/_rust.pyd": {
            "normal": host.ACCEPTED_CRYPTOGRAPHY_IMPORTS, "delay": ()},
        "_cffi_backend.cp314-win_amd64.pyd": {
            "normal": host.ACCEPTED_CFFI_IMPORTS, "delay": ()},
    }
    return files, identities, imports, manifest


class Handle:
    def __init__(self, path):
        self.path = path
        self.closed = False


class FakeAdapter:
    def __init__(self, files, identities, imports):
        self.files = files
        self.identities = identities
        self.imports = imports
        self.handles = []
        self.fail_read = None
        self.fail_close = None
        self.after_read = None
        self.after_open = None
        self.before_read = None
        self.close_calls = []
        self.root_chain = (("C:/", "77", "2", False),
                           ("C:/isolated", "77", "1", False))

    def root_identity(self, root):
        return self.root_chain

    def inventory(self, root):
        return tuple(sorted(self.files, key=lambda path: (path.casefold(), path)))

    def open(self, root, path):
        handle = Handle(path)
        self.handles.append(handle)
        if self.after_open is not None:
            self.after_open(handle)
        return handle

    def identity(self, handle):
        return self.identities[handle.path]

    def read(self, handle, maximum):
        if self.before_read is not None:
            self.before_read(handle)
        if self.fail_read is not None:
            raise self.fail_read
        value = self.files[handle.path]
        if len(value) > maximum:
            raise ValueError("large")
        if self.after_read is not None:
            self.after_read(handle)
        return value

    def pe_imports(self, handle):
        return self.imports.get(handle.path, {"normal": (), "delay": ()})

    def close(self, handle):
        self.close_calls.append(handle)
        handle.closed = True
        if self.fail_close is not None:
            raise self.fail_close


class HostClosureTest(unittest.TestCase):
    def setUp(self):
        self.files, self.identities, self.imports, self.manifest = fixture()
        self.adapter = FakeAdapter(self.files, self.identities, self.imports)
        self.capability = host.seal_adapter(self.adapter)

    def observe(self, manifest=None):
        return host.observe(
            host.seal_adapter(self.adapter), "C:/isolated",
            self.manifest if manifest is None else manifest,
        )

    @staticmethod
    def function_state(function):
        return (function, type(function), function.__code__, function.__defaults__,
                function.__kwdefaults__, function.__closure__, function.__globals__)

    def refused(self, callback):
        with self.assertRaisesRegex(host.HostClosureRefused, "^host_closure$"):
            callback()

    def test_exact_locked_versions_and_supplied_inventory_are_observed_immutably(self):
        result = self.observe()
        self.assertIs(type(result), MappingProxyType)
        self.assertEqual(set(result), {
            "hostClosureObservationOnly", "rootIdentityDigest", "inventoryDigest",
            "manifestDigest", "fileCount", "packageCount", "observationDigest",
        })
        self.assertIs(result["hostClosureObservationOnly"], True)
        self.assertEqual(result["packageCount"], 3)
        self.assertEqual(result["fileCount"], len(self.files))
        with self.assertRaises(TypeError):
            result["admitted"] = True
        self.assertNotIn("authenticated", result)
        self.assertNotIn("imported", result)

    def test_versions_tags_python_and_closed_schema_are_exact(self):
        mutations = []
        for path, value in (
            (("python", "version"), "3.13.9"),
            (("python", "architecture"), "x86"),
            (("python", "implementation"), "pypy"),
            (("packages", 0, "version"), "50.0.0"),
            (("packages", 1, "wheelFilename"), "cffi.whl"),
            (("packages", 2, "name"), "parser"),
        ):
            changed = copy.deepcopy(self.manifest)
            target = changed
            for key in path[:-1]:
                target = target[key]
            target[path[-1]] = value
            mutations.append(changed)
        changed = copy.deepcopy(self.manifest); changed["extra"] = True; mutations.append(changed)
        for changed in mutations:
            with self.subTest(changed=changed):
                self.refused(lambda changed=changed: self.observe(changed))

    def test_inventory_rejects_missing_extra_forbidden_and_casefold_collision(self):
        for path in ("extra.py", "bootstrap.pth", "sitecustomize.py", "usercustomize.py"):
            files, identities, imports, manifest = fixture()
            files[path] = b"x"; identities[path] = ("77", "999", False, f"C:/isolated/{path}")
            adapter = FakeAdapter(files, identities, imports)
            self.refused(lambda: host.observe(host.seal_adapter(adapter), "C:/isolated", manifest))
        files, identities, imports, manifest = fixture()
        files["Python.exe"] = files["python.exe"]
        identities["Python.exe"] = ("77", "999", False, "C:/isolated/Python.exe")
        self.refused(lambda: host.observe(host.seal_adapter(
            FakeAdapter(files, identities, imports)), "C:/isolated", manifest))
        files, identities, imports, manifest = fixture(); del files["python314.dll"]
        self.refused(lambda: host.observe(host.seal_adapter(
            FakeAdapter(files, identities, imports)), "C:/isolated", manifest))

    def test_paths_root_and_descriptor_identity_are_fail_closed(self):
        for path in ("../escape", "/absolute", "a\\b", "a/./b", "CON.txt",
                     "file:stream", "file. "):
            changed = copy.deepcopy(self.manifest)
            changed["runtimeFiles"][0]["path"] = path
            self.refused(lambda changed=changed: self.observe(changed))
        self.identities["python.exe"] = ("77", "100", True, "C:/isolated/python.exe")
        self.refused(self.observe)
        self.assertTrue(self.adapter.handles[-1].closed)
        for bad_root in ("//server/share", "\\\\?\\C:\\isolated", "C:isolated",
                         "c:/isolated", "C:/isolated. "):
            changed = copy.deepcopy(self.manifest); changed["root"]["path"] = bad_root
            self.refused(lambda changed=changed, bad_root=bad_root:
                         host.observe(self.capability, bad_root, changed))

    def test_hash_record_and_size_tampering_refuse(self):
        changed = copy.deepcopy(self.manifest)
        changed["python"]["sha256"] = "0" * 64
        self.refused(lambda: self.observe(changed))
        changed = copy.deepcopy(self.manifest)
        changed["packages"][0]["files"][0]["size"] += 1
        self.refused(lambda: self.observe(changed))
        files, identities, imports, manifest = fixture()
        files[manifest["packages"][0]["recordPath"]] += b"bad"
        self.refused(lambda: host.observe(host.seal_adapter(
            FakeAdapter(files, identities, imports)), "C:/isolated", manifest))
        files, identities, imports, manifest = fixture()
        record_path = manifest["packages"][0]["recordPath"]
        files[record_path] = files[record_path].rsplit(b"\n", 2)[0] + b"\n"
        manifest["packages"][0]["recordSha256"] = digest(files[record_path])
        self.refused(lambda: host.observe(host.seal_adapter(
            FakeAdapter(files, identities, imports)), "C:/isolated", manifest))

    def test_pre_and_post_identity_detect_swap(self):
        original = self.identities["python.exe"]
        self.adapter.after_read = lambda handle: self.identities.__setitem__(
            handle.path, (original[0], "999", False, original[3]))
        self.refused(self.observe)
        self.assertTrue(self.adapter.handles[-1].closed)

    def test_root_ancestor_reparse_or_identity_drift_refuses(self):
        self.adapter.root_chain = (("C:/", "77", "2", True),
                                   ("C:/isolated", "77", "1", False))
        self.refused(self.observe)
        files, identities, imports, manifest = fixture()
        adapter = FakeAdapter(files, identities, imports)
        def drift(handle):
            adapter.root_chain = (("C:/", "77", "999", False),
                                  ("C:/isolated", "77", "1", False))
        adapter.after_read = drift
        self.refused(lambda: host.observe(host.seal_adapter(adapter), "C:/isolated", manifest))

    def test_inventory_is_resampled_after_reads(self):
        def add_extra(_handle):
            if "evil.dll" not in self.adapter.files:
                self.adapter.files["evil.dll"] = b"evil"
                self.adapter.identities["evil.dll"] = (
                    "77", "9999", False, "C:/isolated/evil.dll",
                )
        self.adapter.after_read = add_extra
        self.refused(self.observe)

    def test_duplicate_file_object_identity_refuses(self):
        first, second = "python.exe", "python314.dll"
        self.identities[second] = (
            self.identities[first][0], self.identities[first][1], False,
            self.identities[second][3],
        )
        self.refused(self.observe)

    def test_pe_imports_close_only_to_runtime_or_system_allowlist(self):
        self.imports["cryptography/hazmat/bindings/_rust.pyd"] = {
            "normal": ("evil.dll",), "delay": (),
        }
        self.refused(self.observe)
        self.imports["cryptography/hazmat/bindings/_rust.pyd"] = {
            "normal": host.ACCEPTED_CRYPTOGRAPHY_IMPORTS, "delay": ("evil.dll",),
        }
        self.refused(self.observe)
        self.imports["cryptography/hazmat/bindings/_rust.pyd"] = {
            "normal": host.ACCEPTED_CRYPTOGRAPHY_IMPORTS, "delay": (),
        }
        self.assertTrue(self.observe()["hostClosureObservationOnly"])
        changed = copy.deepcopy(self.manifest)
        changed["loaderPolicy"]["mode"] = "path-search"
        self.refused(lambda: self.observe(changed))

    def test_adapter_is_sealed_and_replacement_does_not_execute(self):
        original = FakeAdapter.read
        calls = []
        capability = self.capability
        try:
            FakeAdapter.read = lambda *_: calls.append(True) or b""
            self.refused(lambda: host.observe(capability, "C:/isolated", self.manifest))
            self.assertEqual(calls, [])
        finally:
            FakeAdapter.read = original

    def test_capability_state_cannot_bless_replacement_and_is_one_shot(self):
        capability = self.capability
        original = FakeAdapter.read
        calls = []
        replacement = lambda *_: calls.append(True) or b""
        try:
            FakeAdapter.read = replacement
            # This reproduces the old capability's caller-owned dictionaries.
            if hasattr(capability, "_methods"):
                capability._methods["read"] = self.function_state(replacement)
                capability._descriptors["read"] = (FakeAdapter, replacement)
            self.refused(lambda: host.observe(
                capability, "C:/isolated", self.manifest,
            ))
            self.assertEqual(calls, [])
        finally:
            FakeAdapter.read = original
        capability = host.seal_adapter(self.adapter)
        self.assertTrue(host.observe(capability, "C:/isolated", self.manifest)[
            "hostClosureObservationOnly"
        ])
        self.refused(lambda: host.observe(capability, "C:/isolated", self.manifest))
        with self.assertRaises((AttributeError, TypeError)):
            object.__setattr__(capability, "_methods", {})

    def test_in_place_json_defaults_mutation_refuses_before_execution(self):
        defaults = host.json.dumps.__kwdefaults__
        original = dict(defaults)
        calls = []
        class Decoder:
            def __init__(self, *_args, **_kwargs):
                calls.append(True)
        try:
            defaults["cls"] = Decoder
            self.refused(self.observe)
            self.assertEqual(calls, [])
        finally:
            defaults.clear(); defaults.update(original)

    def test_post_open_authority_drift_closes_handle_once(self):
        original = FakeAdapter.identity
        try:
            self.adapter.after_open = lambda _handle: setattr(
                FakeAdapter, "identity", lambda *_: ("77", "1", False, "wrong")
            )
            self.refused(self.observe)
            self.assertEqual(len(self.adapter.close_calls), 1)
            self.assertTrue(self.adapter.handles[0].closed)
        finally:
            FakeAdapter.identity = original

    def test_primary_baseexception_survives_authority_drift_and_cleanup(self):
        original = FakeAdapter.identity
        primary = SystemExit("primary")
        def mutate_and_raise(_handle):
            FakeAdapter.identity = lambda *_: ("77", "1", False, "wrong")
            raise primary
        try:
            self.adapter.before_read = mutate_and_raise
            self.adapter.fail_close = RuntimeError("close")
            with self.assertRaises(SystemExit) as caught:
                self.observe()
            self.assertIs(caught.exception, primary)
            self.assertEqual(len(self.adapter.close_calls), 1)
        finally:
            FakeAdapter.identity = original

    def test_cleanup_once_and_primary_baseexception_wins(self):
        primary = KeyboardInterrupt("primary")
        self.adapter.fail_read = primary
        self.adapter.fail_close = RuntimeError("close")
        with self.assertRaises(KeyboardInterrupt) as caught:
            self.observe()
        self.assertIs(caught.exception, primary)
        self.assertEqual(sum(handle.closed for handle in self.adapter.handles), 1)
        files, identities, imports, manifest = fixture()
        adapter = FakeAdapter(files, identities, imports)
        adapter.fail_close = RuntimeError("close detail")
        self.refused(lambda: host.observe(host.seal_adapter(adapter), "C:/isolated", manifest))
        class Fatal(BaseException):
            pass
        files, identities, imports, manifest = fixture()
        adapter = FakeAdapter(files, identities, imports)
        primary = Fatal("primary")
        adapter.fail_read = primary
        adapter.fail_close = SystemExit("close")
        with self.assertRaises(Fatal) as caught:
            host.observe(host.seal_adapter(adapter), "C:/isolated", manifest)
        self.assertIs(caught.exception, primary)
        self.assertEqual(len(adapter.close_calls), 1)

    def test_bounds_types_and_no_execution_acquisition_surface(self):
        changed = copy.deepcopy(self.manifest); changed["version"] = True
        self.refused(lambda: self.observe(changed))
        changed = copy.deepcopy(self.manifest)
        changed["systemDllAllowlist"] = changed["systemDllAllowlist"][:-1]
        self.refused(lambda: self.observe(changed))
        self.files["python.exe"] = b"x" * (host.MAX_FILE_BYTES + 1)
        self.refused(self.observe)
        for path in ("a" * 256, "bad\x7f.dat", "café.dat"):
            changed = copy.deepcopy(self.manifest)
            changed["packages"][2]["files"][0]["path"] = path
            self.refused(lambda changed=changed: self.observe(changed))
        self.assertEqual(host.__all__, ("HostClosureRefused", "seal_adapter", "observe"))
        for name in ("install", "import_runtime", "execute", "admit", "acquire"):
            self.assertFalse(hasattr(host, name))

    def test_pe_import_names_are_unique_ascii_basenames(self):
        path = "cryptography/hazmat/bindings/_rust.pyd"
        for imports in (
            ("KERNEL32.dll", "kernel32.DLL"),
            ("../KERNEL32.dll",), ("C:\\KERNEL32.dll",), ("café.dll",),
        ):
            self.imports[path] = {"normal": imports, "delay": ()}
            self.refused(self.observe)


if __name__ == "__main__":
    unittest.main()
