import base64
import csv
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import stat
import struct
from types import MappingProxyType
import unittest
import zipfile


HERE = Path(__file__).parent
MODULE_PATH = HERE / "checkout-cryptography-runtime-closure.py"
LOCK_PATH = HERE / "checkout-cryptography-runtime-closure-v1.json"
SPEC = importlib.util.spec_from_file_location("crypto_runtime_closure", MODULE_PATH)
closure = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(closure)


def canonical(value):
    return (json.dumps(value, sort_keys=True, separators=(",", ":"),
                       ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")


def fake_pe(imports):
    names = [name.encode("ascii") + b"\0" for name in imports]
    descriptors = 20 * (len(names) + 1)
    payload = bytearray(descriptors)
    cursor = descriptors
    for index, name in enumerate(names):
        struct.pack_into("<IIIII", payload, index * 20, 0, 0, 0,
                         0x1000 + cursor, 0)
        payload.extend(name)
        cursor += len(name)
    raw = bytearray(512 + len(payload))
    raw[:2] = b"MZ"
    struct.pack_into("<I", raw, 0x3C, 0x80)
    raw[0x80:0x84] = b"PE\0\0"
    struct.pack_into("<HHIIIHH", raw, 0x84, 0x8664, 1, 0, 0, 0, 240, 0)
    struct.pack_into("<H", raw, 0x98, 0x20B)
    struct.pack_into("<II", raw, 0x98 + 120, 0x1000, descriptors)
    section = 0x98 + 240
    struct.pack_into("<IIII", raw, section + 8, len(payload), 0x1000,
                     len(payload), 512)
    raw[512:] = payload
    return bytes(raw)


def make_wheel(project, version, tag, pure, dependency, native=False):
    dist = f"{project}-{version}.dist-info"
    metadata = (
        f"Metadata-Version: 2.4\nName: {project}\nVersion: {version}\n"
        f"Requires-Python: >=3.10\nLicense-Expression: MIT-0\n"
        f"License-File: LICENSE\n"
        + (f"Requires-Dist: {dependency}\n" if dependency else "")
        + "\n"
    ).encode()
    wheel = (
        f"Wheel-Version: 1.0\nGenerator: test\nRoot-Is-Purelib: "
        f"{'true' if pure else 'false'}\nTag: {tag}\n"
    ).encode()
    files = {
        f"{dist}/METADATA": metadata,
        f"{dist}/WHEEL": wheel,
        f"{dist}/licenses/LICENSE": b"license\n",
        f"{project}/__init__.py": b"",
    }
    imports = []
    if native:
        imports = ["python314.dll", "KERNEL32.dll"]
        files["_cffi_backend.cp314-win_amd64.pyd"] = fake_pe(imports)
        files["cffi/include.h"] = b"header\n"
    record_path = f"{dist}/RECORD"
    out = io.StringIO(newline="")
    writer = csv.writer(out, lineterminator="\n")
    for path in sorted(files):
        digest = base64.urlsafe_b64encode(hashlib.sha256(files[path]).digest()) \
            .rstrip(b"=").decode("ascii")
        writer.writerow((path, "sha256=" + digest, str(len(files[path]))))
    writer.writerow((record_path, "", ""))
    files[record_path] = out.getvalue().encode()
    stream = io.BytesIO()
    with zipfile.ZipFile(stream, "w", zipfile.ZIP_DEFLATED) as archive:
        for path, content in files.items():
            info = zipfile.ZipInfo(path)
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = (stat.S_IFREG | 0o644) << 16
            archive.writestr(info, content)
    raw = stream.getvalue()
    inventory = [
        {"path": path, "recordHash": hashlib.sha256(files[path]).hexdigest(),
         "size": len(files[path])}
        for path in sorted(files)
    ]
    resources = [
        {"path": item["path"], "sha256": item["recordHash"], "size": item["size"]}
        for item in inventory if item["path"].endswith(".h")
    ]
    native_entries = []
    if native:
        path = "_cffi_backend.cp314-win_amd64.pyd"
        native_entries.append({"machine": "amd64", "path": path,
                               "sha256": hashlib.sha256(files[path]).hexdigest(),
                               "size": len(files[path])})
    document = {
        "archiveEntryCount": len(files),
        "externalDllImports": imports,
        "filename": f"{project}-{version}-{tag}.whl",
        "licenses": [{"path": f"{dist}/licenses/LICENSE",
                      "sha256": hashlib.sha256(files[f"{dist}/licenses/LICENSE"]).hexdigest(),
                      "size": len(files[f"{dist}/licenses/LICENSE"])}],
        "metadata": {"licenseExpression": "MIT-0", "metadataVersion": "2.4",
                     "path": f"{dist}/METADATA", "requiresDist": [dependency] if dependency else [],
                     "requiresPython": ">=3.10", "sha256": hashlib.sha256(metadata).hexdigest(),
                     "size": len(metadata)},
        "nativeExtensions": native_entries,
        "project": project,
        "record": {"entryCount": len(files), "path": record_path,
                   "inventoryDigest": hashlib.sha256(
                       b"oncam.checkout.cryptography-runtime-record.v1\0" + canonical(inventory)
                   ).hexdigest(), "sha256": hashlib.sha256(files[record_path]).hexdigest(),
                   "size": len(files[record_path])},
        "resources": {"entryCount": len(resources), "inventoryDigest": hashlib.sha256(
            b"oncam.checkout.cryptography-runtime-resources.v1\0" + canonical(resources)
        ).hexdigest()},
        "rootIsPurelib": pure, "tag": tag, "version": version,
        "wheelSha256": hashlib.sha256(raw).hexdigest(), "wheelSize": len(raw),
    }
    return raw, document


def fixture():
    cffi_raw, cffi = make_wheel("cffi", "2.1.1", "cp314-cp314-win_amd64", False,
                                'pycparser; implementation_name != "PyPy"', True)
    parser_raw, parser = make_wheel("pycparser", "3.0", "py3-none-any", True, None)
    document = {"closureScope": "python-wheel-archives-only",
                "cryptographyRequiresDist": [
                    "cffi>=2.0.0 ; platform_python_implementation != 'PyPy'"],
                "schema": "oncam.checkout.cryptography-runtime-closure.v1",
                "wheels": [cffi, parser]}
    return (cffi_raw, parser_raw), document


class RuntimeClosureTests(unittest.TestCase):
    def refused(self, operation):
        with self.assertRaisesRegex(closure.CryptographyRuntimeClosureRefused,
                                    "^cryptography_runtime_closure$"):
            operation()

    def test_fixed_lock_is_canonical_and_pins_official_wheels(self):
        raw = LOCK_PATH.read_bytes()
        value = json.loads(raw)
        self.assertEqual(raw, canonical(value))
        self.assertEqual([wheel["filename"] for wheel in value["wheels"]], [
            "cffi-2.1.1-cp314-cp314-win_amd64.whl",
            "pycparser-3.0-py3-none-any.whl",
        ])
        self.assertEqual([wheel["wheelSha256"] for wheel in value["wheels"]], [
            "3222ba5d678f80a030e6afbcc33dc1ae5cb45facabb61cee2c7016b8432fde48",
            "b727414169a36b7d524c1c3e31839a521725078d7b2ff038656844266160a992",
        ])

    def test_synthetic_complete_closure_returns_narrow_immutable_result(self):
        archives, document = fixture()
        result = closure._inspect_runtime_fixture_only(archives, document)
        self.assertIs(type(result), MappingProxyType)
        self.assertIs(result["structuralOnly"], True)
        self.assertEqual(result["wheelCount"], 2)
        self.assertEqual(result["projects"], ("cffi", "pycparser"))
        self.assertEqual(result["closureScope"], "python-wheel-archives-only")
        with self.assertRaises(TypeError):
            result["trusted"] = True
        for key in ("trusted", "installed", "hostClosureComplete", "admitted"):
            self.assertNotIn(key, result)

    def test_dependency_order_and_transitive_metadata_are_exact(self):
        archives, document = fixture()
        for mutate in (
            lambda d: d["wheels"].reverse(),
            lambda d: d["wheels"][0]["metadata"].__setitem__("requiresDist", []),
            lambda d: d["wheels"][1].__setitem__("version", "2.22"),
            lambda d: d["wheels"][0].__setitem__("filename", "other.whl"),
            lambda d: d.__setitem__("cryptographyRequiresDist", []),
        ):
            changed = json.loads(canonical(document)); mutate(changed)
            self.refused(lambda changed=changed:
                         closure._inspect_runtime_fixture_only(archives, changed))

    def test_record_zip_path_symlink_and_case_collision_fail_closed(self):
        archives, document = fixture()
        for mutation in (
            lambda d: d["wheels"][0]["record"].__setitem__("inventoryDigest", "0" * 64),
            lambda d: d["wheels"][0]["resources"].__setitem__("entryCount", 0),
            lambda d: d["wheels"][0]["nativeExtensions"][0].__setitem__("size", 1),
        ):
            changed = json.loads(canonical(document)); mutation(changed)
            self.refused(lambda changed=changed:
                         closure._inspect_runtime_fixture_only(archives, changed))
        bad_raw, bad_doc = make_wheel("cffi", "2.1.1", "cp314-cp314-win_amd64",
                                      False, 'pycparser; implementation_name != "PyPy"', True)
        # RECORD completeness catches any archive-only injected path.
        source = io.BytesIO(bad_raw); target = io.BytesIO()
        with zipfile.ZipFile(source) as zin, zipfile.ZipFile(target, "w") as zout:
            for info in zin.infolist():
                zout.writestr(info, zin.read(info))
            zout.writestr("../escape", b"x")
        self.refused(lambda: closure._inspect_runtime_fixture_only(
            (target.getvalue(), archives[1]), document))
        for path, attributes in (
            ("Cffi/__init__.py", (stat.S_IFREG | 0o644) << 16),
            ("cffi/link", (stat.S_IFLNK | 0o777) << 16),
        ):
            source = io.BytesIO(archives[0])
            target = io.BytesIO()
            with zipfile.ZipFile(source) as zin, zipfile.ZipFile(target, "w") as zout:
                for info in zin.infolist():
                    zout.writestr(info, zin.read(info))
                info = zipfile.ZipInfo(path)
                info.external_attr = attributes
                zout.writestr(info, b"x")
            self.refused(lambda target=target: closure._inspect_runtime_fixture_only(
                (target.getvalue(), archives[1]), document))

    def test_exact_pe_imports_and_pure_python_relation_are_enforced(self):
        archives, document = fixture()
        for mutation in (
            lambda d: d["wheels"][0].__setitem__("externalDllImports", []),
            lambda d: d["wheels"][0]["nativeExtensions"][0].__setitem__("machine", "x86"),
            lambda d: d["wheels"][1]["nativeExtensions"].append({}),
            lambda d: d["wheels"][1].__setitem__("rootIsPurelib", False),
        ):
            changed = json.loads(canonical(document)); mutation(changed)
            self.refused(lambda changed=changed:
                         closure._inspect_runtime_fixture_only(archives, changed))

    def test_public_uses_fixed_immutable_lock_and_dependency_drift_refuses(self):
        archives, _document = fixture()
        self.refused(lambda: closure.inspect_runtime_closure(archives))
        self.refused(lambda: closure.inspect_runtime_closure(list(archives)))
        self.assertEqual(closure.__all__,
                         ("CryptographyRuntimeClosureRefused", "inspect_runtime_closure"))
        original = closure._inspect
        try:
            closure._inspect = lambda *_args: MappingProxyType({"trusted": True})
            self.refused(lambda: closure.inspect_runtime_closure(archives))
        finally:
            closure._inspect = original
        original_loads = closure.json.loads
        try:
            closure.json.loads = lambda _raw: {"trusted": True}
            self.refused(lambda: closure.inspect_runtime_closure(archives))
        finally:
            closure.json.loads = original_loads

    def test_fixed_document_callable_and_mutable_closure_tamper_refuse(self):
        fixed = next(cell.cell_contents for cell in closure.inspect_runtime_closure.__closure__
                     if callable(cell.cell_contents)
                     and getattr(cell.cell_contents, "__name__", "") == "fixed_document")
        self.assertFalse(any(isinstance(cell.cell_contents, (dict, list, set, bytearray))
                             for cell in fixed.__closure__))
        old = fixed.__code__
        try:
            fixed.__code__ = old.replace()
            archives, _document = fixture()
            self.refused(lambda: closure.inspect_runtime_closure(archives))
        finally:
            fixed.__code__ = old

    def test_baseexception_identity_and_no_runtime_surface(self):
        invoke = next(cell.cell_contents for cell in closure.inspect_runtime_closure.__closure__
                      if callable(cell.cell_contents)
                      and getattr(cell.cell_contents, "__name__", "") == "invoke")
        for primary in (KeyboardInterrupt("k"), SystemExit("s")):
            with self.assertRaises(type(primary)) as raised:
                invoke(lambda error=primary: (_ for _ in ()).throw(error))
            self.assertIs(raised.exception, primary)
        for name in ("download", "install", "import_wheel", "verify_signature"):
            self.assertFalse(hasattr(closure, name))
        text = (closure.__doc__ or "").lower()
        for phrase in ("structural", "does not", "host", "install"):
            self.assertIn(phrase, text)


if __name__ == "__main__":
    unittest.main()
