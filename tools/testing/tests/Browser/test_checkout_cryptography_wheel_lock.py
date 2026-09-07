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
MODULE_PATH = HERE / "checkout-cryptography-wheel-lock.py"
LOCK_PATH = HERE / "checkout-cryptography-wheel-lock-v1.json"
SPEC = importlib.util.spec_from_file_location("checkout_crypto_wheel_lock", MODULE_PATH)
lock = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(lock)


def canonical(value):
    return (json.dumps(value, sort_keys=True, separators=(",", ":"),
                       ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")


def fake_pe(imports, marker=b"OpenSSL 4.0.2 25 Aug 2026\0"):
    names = [name.encode("ascii") + b"\0" for name in imports]
    descriptors_size = 20 * (len(names) + 1)
    payload = bytearray(descriptors_size)
    cursor = descriptors_size
    for index, name in enumerate(names):
        struct.pack_into("<IIIII", payload, index * 20, 0, 0, 0,
                         0x1000 + cursor, 0)
        payload.extend(name)
        cursor += len(name)
    payload.extend(marker)
    raw = bytearray(512 + len(payload))
    raw[:2] = b"MZ"
    struct.pack_into("<I", raw, 0x3C, 0x80)
    raw[0x80:0x84] = b"PE\0\0"
    struct.pack_into("<HHIIIHH", raw, 0x84, 0x8664, 1, 0, 0, 0, 240, 0x2022)
    optional = 0x98
    struct.pack_into("<H", raw, optional, 0x20B)
    struct.pack_into("<II", raw, optional + 112 + 8, 0x1000, descriptors_size)
    section = optional + 240
    raw[section:section + 8] = b".rdata\0\0"
    struct.pack_into("<IIII", raw, section + 8, len(payload), 0x1000,
                     len(payload), 512)
    raw[512:] = payload
    return bytes(raw)


def record_rows(files, record_path):
    rows = []
    for path in sorted(files):
        digest = base64.urlsafe_b64encode(hashlib.sha256(files[path]).digest()) \
            .rstrip(b"=").decode("ascii")
        rows.append((path, "sha256=" + digest, str(len(files[path]))))
    rows.append((record_path, "", ""))
    output = io.StringIO(newline="")
    csv.writer(output, lineterminator="\n").writerows(rows)
    return output.getvalue().encode("utf-8")


def synthetic_archive(extra=None, external_attr=None):
    dist = "cryptography-50.0.1.dist-info"
    record_path = f"{dist}/RECORD"
    imports = ("KERNEL32.dll", "python3.dll", "VCRUNTIME140.dll")
    files = {
        f"{dist}/METADATA": (
            b"Metadata-Version: 2.4\nName: cryptography\nVersion: 50.0.1\n"
            b"Requires-Python: >=3.9, !=3.9.0, !=3.9.1\n"
            b"License-Expression: Apache-2.0 OR BSD-3-Clause\n"
            b"License-File: LICENSE\nLicense-File: LICENSE.APACHE\n"
            b"License-File: LICENSE.BSD\n\n"
        ),
        f"{dist}/WHEEL": (
            b"Wheel-Version: 1.0\nGenerator: maturin (1.14.1)\n"
            b"Root-Is-Purelib: false\nTag: cp311-abi3-win_amd64\n"
        ),
        f"{dist}/licenses/LICENSE": b"dual license\n",
        f"{dist}/licenses/LICENSE.APACHE": b"apache\n",
        f"{dist}/licenses/LICENSE.BSD": b"bsd\n",
        "cryptography/hazmat/bindings/_rust.pyd": fake_pe(imports),
        "cryptography/py.typed": b"",
    }
    if extra:
        files.update(extra)
    files[record_path] = record_rows(files, record_path)
    stream = io.BytesIO()
    with zipfile.ZipFile(stream, "w", zipfile.ZIP_DEFLATED) as archive:
        for path, content in files.items():
            info = zipfile.ZipInfo(path)
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = (stat.S_IFREG | 0o644) << 16
            if external_attr and path in external_attr:
                info.external_attr = external_attr[path]
            archive.writestr(info, content)
    raw = stream.getvalue()
    inventory = []
    for path in sorted(files):
        content = files[path]
        inventory.append({"path": path, "recordHash": hashlib.sha256(content).hexdigest(),
                          "size": len(content)})
    inventory_bytes = canonical(inventory)
    resources = [{"path": entry["path"], "sha256": entry["recordHash"],
                  "size": entry["size"]} for entry in inventory
                 if entry["path"].endswith(".pyi")
                 or entry["path"] == "cryptography/py.typed"]
    document = {
        "abiTag": "abi3", "archiveEntryCount": len(files),
        "archiveUncompressedSize": sum(len(content) for content in files.values()),
        "bundledOpenSsl": {"containerPath": "cryptography/hazmat/bindings/_rust.pyd",
                           "linkage": "static", "version": "4.0.2",
                           "versionMarker": "OpenSSL 4.0.2 25 Aug 2026"},
        "closureScope": "wheel-archive-only",
        "externalDllImports": list(imports),
        "filename": "cryptography-50.0.1-cp311-abi3-win_amd64.whl",
        "internalDlls": [],
        "licenses": [{"path": path, "sha256": hashlib.sha256(files[path]).hexdigest(),
                      "size": len(files[path])} for path in (
            f"{dist}/licenses/LICENSE", f"{dist}/licenses/LICENSE.APACHE",
            f"{dist}/licenses/LICENSE.BSD")],
        "metadata": {"licenseExpression": "Apache-2.0 OR BSD-3-Clause",
                     "metadataVersion": "2.4", "name": "cryptography",
                     "path": f"{dist}/METADATA",
                     "requiresDist": [],
                     "requiresPython": ">=3.9, !=3.9.0, !=3.9.1",
                     "sha256": hashlib.sha256(files[f"{dist}/METADATA"]).hexdigest(),
                     "size": len(files[f"{dist}/METADATA"]), "version": "50.0.1"},
        "nativeExtensions": [{"machine": "amd64",
            "path": "cryptography/hazmat/bindings/_rust.pyd",
            "sha256": hashlib.sha256(files["cryptography/hazmat/bindings/_rust.pyd"]).hexdigest(),
            "size": len(files["cryptography/hazmat/bindings/_rust.pyd"])}],
        "platformTag": "win_amd64", "project": "cryptography",
        "pythonCompatibility": {"architecture": "amd64", "implementation": "cpython",
                                "interpreterTag": "cp314", "minimumPythonTag": "cp311"},
        "pythonTag": "cp311",
        "record": {"entryCount": len(files),
            "inventoryDigest": hashlib.sha256(
                b"oncam.checkout.cryptography-wheel-record.v1\0" + inventory_bytes
            ).hexdigest(), "path": record_path,
            "sha256": hashlib.sha256(files[record_path]).hexdigest(),
            "size": len(files[record_path])},
        "resourceInventory": {
            "entryCount": len(resources),
            "inventoryDigest": hashlib.sha256(
                b"oncam.checkout.cryptography-wheel-resources.v1\0"
                + canonical(resources)
            ).hexdigest(),
        },
        "schema": "oncam.checkout.cryptography-wheel-lock.v1",
        "version": "50.0.1", "wheelSha256": hashlib.sha256(raw).hexdigest(),
        "wheelSize": len(raw),
        "wheelMetadata": {"generator": "maturin (1.14.1)",
                          "path": f"{dist}/WHEEL", "rootIsPurelib": False,
                          "sha256": hashlib.sha256(files[f"{dist}/WHEEL"]).hexdigest(),
                          "size": len(files[f"{dist}/WHEEL"]),
                          "tag": "cp311-abi3-win_amd64", "wheelVersion": "1.0"},
    }
    return raw, document


class CryptographyWheelLockTests(unittest.TestCase):
    def assert_refused(self, operation):
        with self.assertRaisesRegex(lock.CryptographyWheelLockRefused,
                                    "^cryptography_wheel_lock$"):
            operation()

    def test_official_lock_is_canonical_exact_and_public_metadata_only(self):
        raw = LOCK_PATH.read_bytes()
        value = json.loads(raw)
        self.assertEqual(raw, canonical(value))
        self.assertEqual(value["filename"],
                         "cryptography-50.0.1-cp311-abi3-win_amd64.whl")
        self.assertEqual(value["wheelSha256"],
                         "aed8db4f6d71c51efb89530e12d9464e7bf2923d46c3205dc794a2a93f8c0648")
        self.assertEqual(value["wheelSize"], 3842826)
        self.assertEqual(value["archiveEntryCount"], 120)
        self.assertEqual(value["record"]["inventoryDigest"],
                         "3fa3e2666b67554681099f2685edc459a0bfbf0c158d2b1532c0ad37f0fa88f1")
        self.assertEqual(value["resourceInventory"]["entryCount"], 33)
        self.assertIn(
            "cffi>=2.0.0 ; platform_python_implementation != 'PyPy'",
            value["metadata"]["requiresDist"],
        )
        self.assertEqual(value["nativeExtensions"][0]["machine"], "amd64")
        self.assertEqual(value["bundledOpenSsl"]["version"], "4.0.2")
        self.assertEqual(value["closureScope"], "wheel-archive-only")
        self.assertNotIn("path", value["pythonCompatibility"])
        for forbidden in ("hostPath", "fileId", "volumeSerial", "accepted", "trusted"):
            self.assertNotIn(forbidden, value)

    def test_synthetic_archive_matches_complete_record_and_returns_immutable_result(self):
        raw, document = synthetic_archive()
        result = lock._inspect_archive_structural_fixture_only(raw, document)
        self.assertIs(type(result), MappingProxyType)
        self.assertIs(result["structuralOnly"], True)
        self.assertEqual(result["recordCount"], document["archiveEntryCount"])
        self.assertEqual(result["externalDllImports"], tuple(document["externalDllImports"]))
        with self.assertRaises(TypeError): result["accepted"] = True
        for key in ("accepted", "installed", "trusted", "hostClosureComplete"):
            self.assertNotIn(key, result)

    def test_record_hash_size_completeness_and_metadata_are_checked(self):
        raw, document = synthetic_archive()
        for mutation in (
            lambda d: d["record"].__setitem__("entryCount", 1),
            lambda d: d["record"].__setitem__("inventoryDigest", "0" * 64),
            lambda d: d["metadata"].__setitem__("version", "50.0.0"),
            lambda d: d["wheelMetadata"].__setitem__("tag", "cp314-cp314-win_amd64"),
            lambda d: d["nativeExtensions"][0].__setitem__("size", 1),
        ):
            changed = json.loads(canonical(document)); mutation(changed)
            self.assert_refused(
                lambda changed=changed: lock._inspect_archive_structural_fixture_only(raw, changed)
            )

    def test_zip_paths_duplicates_case_collisions_and_symlinks_refuse(self):
        cases = (
            {"../escape.py": b"x"},
            {"Cryptography/PY.TYPED": b"x"},
            {"C:/absolute.py": b"x"},
            {"cryptography\\bad.py": b"x"},
        )
        for extra in cases:
            raw, document = synthetic_archive(extra)
            self.assert_refused(
                lambda raw=raw, document=document:
                    lock._inspect_archive_structural_fixture_only(raw, document)
            )
        link = "cryptography/link.py"
        raw, document = synthetic_archive(
            {link: b"target"}, {link: (stat.S_IFLNK | 0o777) << 16}
        )
        self.assert_refused(
            lambda: lock._inspect_archive_structural_fixture_only(raw, document)
        )

    def test_pe_machine_imports_and_static_openssl_marker_are_exact(self):
        raw, document = synthetic_archive()
        for mutation in (
            lambda d: d.__setitem__("externalDllImports", ["KERNEL32.dll"]),
            lambda d: d["nativeExtensions"][0].__setitem__("machine", "x86"),
            lambda d: d["bundledOpenSsl"].__setitem__("version", "3.5.0"),
            lambda d: d["bundledOpenSsl"].__setitem__("linkage", "dynamic"),
        ):
            changed = json.loads(canonical(document)); mutation(changed)
            self.assert_refused(
                lambda changed=changed: lock._inspect_archive_structural_fixture_only(raw, changed)
            )

    def test_public_inspector_uses_only_fixed_lock_and_rejects_nonofficial_bytes(self):
        raw, document = synthetic_archive()
        self.assert_refused(lambda: lock.inspect_wheel(raw))
        self.assert_refused(lambda: lock.inspect_wheel(b"not a zip"))
        self.assert_refused(lambda: lock.inspect_wheel(bytearray(raw)))
        self.assertEqual(lock.__all__,
                         ("CryptographyWheelLockRefused", "inspect_wheel"))

    def test_public_inspector_has_no_mutable_lock_document_closure_authority(self):
        fixed_document = next(
            cell.cell_contents
            for cell in lock.inspect_wheel.__closure__
            if callable(cell.cell_contents)
            and getattr(cell.cell_contents, "__name__", "") == "fixed_document"
        )
        mutable = (dict, list, set, bytearray)
        self.assertFalse(any(
            isinstance(cell.cell_contents, mutable)
            for cell in fixed_document.__closure__
        ))
        raw, _document = synthetic_archive()
        self.assert_refused(lambda: lock.inspect_wheel(raw))

    def test_fixed_document_callable_code_drift_refuses(self):
        fixed_document = next(
            cell.cell_contents
            for cell in lock.inspect_wheel.__closure__
            if callable(cell.cell_contents)
            and getattr(cell.cell_contents, "__name__", "") == "fixed_document"
        )
        original = fixed_document.__code__
        try:
            fixed_document.__code__ = original.replace()
            raw, _document = synthetic_archive()
            self.assert_refused(lambda: lock.inspect_wheel(raw))
        finally:
            fixed_document.__code__ = original

    def test_dependency_and_lock_authority_mutation_fail_closed(self):
        raw, _document = synthetic_archive()
        refusal = lock.CryptographyWheelLockRefused
        for owner, name, replacement in (
            (lock, "LOCK_FILENAME", "other.json"),
            (lock, "CryptographyWheelLockRefused", Exception),
            (lock, "json", object()), (lock, "hashlib", object()),
            (lock, "zipfile", object()), (lock, "__all__", tuple(lock.__all__)),
        ):
            original = getattr(owner, name)
            try:
                setattr(owner, name, replacement)
                with self.assertRaises(refusal): lock.inspect_wheel(raw)
            finally: setattr(owner, name, original)

    def test_baseexception_identity_and_no_acquisition_or_install_surface(self):
        invoke = next(cell.cell_contents for cell in lock.inspect_wheel.__closure__
                      if callable(cell.cell_contents)
                      and getattr(cell.cell_contents, "__name__", "") == "invoke")
        for primary in (KeyboardInterrupt("k"), SystemExit("s")):
            with self.assertRaises(type(primary)) as raised:
                invoke(lambda error=primary: (_ for _ in ()).throw(error))
            self.assertIs(raised.exception, primary)
        for name in ("acquire", "download", "install", "load_key", "verify_signature"):
            self.assertFalse(hasattr(lock, name))
        text = (lock.__doc__ or "").lower()
        for phrase in ("structural", "does not", "host", "install"):
            self.assertIn(phrase, text)


if __name__ == "__main__":
    unittest.main()
