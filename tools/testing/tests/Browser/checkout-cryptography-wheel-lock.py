"""Pure structural inspection of the pinned cryptography wheel archive.

This codec does not install or import the wheel and does not establish host,
runtime, signature, trust, freshness, or admission authority.  A future
composition root must pin this module's exported callables and independently
bind the inspected bytes to authenticated acquisition evidence.
"""

import base64
import csv
import email.parser
import email.policy
import hashlib
import io
import json
from pathlib import Path
import re
import stat
import struct
from types import MappingProxyType
import zipfile


__all__ = ("CryptographyWheelLockRefused", "inspect_wheel")

LOCK_FILENAME = "checkout-cryptography-wheel-lock-v1.json"
_SCHEMA = "oncam.checkout.cryptography-wheel-lock.v1"
_FILENAME = "cryptography-50.0.1-cp311-abi3-win_amd64.whl"
_VERSION = "50.0.1"
_DIST = "cryptography-50.0.1.dist-info"
_METADATA_PATH = f"{_DIST}/METADATA"
_WHEEL_PATH = f"{_DIST}/WHEEL"
_RECORD_PATH = f"{_DIST}/RECORD"
_NATIVE_PATH = "cryptography/hazmat/bindings/_rust.pyd"
_OPENSSL_VERSION = "4.0.2"
_OPENSSL_MARKER = "OpenSSL 4.0.2 25 Aug 2026"
_MAX_WHEEL_BYTES = 8 * 1024 * 1024
_MAX_ENTRIES = 512
_MAX_ENTRY_BYTES = 16 * 1024 * 1024
_MAX_TOTAL_BYTES = 32 * 1024 * 1024
_MAX_PATH_BYTES = 1024
_MAX_DEPTH = 32
_MAX_COMPONENT_BYTES = 255
_HEX = re.compile(r"[0-9a-f]{64}\Z")
_WINDOWS_FORBIDDEN = frozenset('<>:"\\|?*')
_WINDOWS_DEVICES = frozenset(
    {"CON", "PRN", "AUX", "NUL", "CONIN$", "CONOUT$"}
    | {f"COM{i}" for i in range(1, 10)}
    | {f"LPT{i}" for i in range(1, 10)}
)


class CryptographyWheelLockRefused(RuntimeError):
    pass


def _canonical(value):
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


def _exact_data(value, depth=0):
    if depth > 12:
        raise ValueError("lock")
    if value is None or type(value) in (bool, int, str):
        return
    if type(value) is list:
        if len(value) > 1024:
            raise ValueError("lock")
        for item in value:
            _exact_data(item, depth + 1)
        return
    if type(value) is dict:
        if len(value) > 128:
            raise ValueError("lock")
        for key, item in value.items():
            if type(key) is not str:
                raise ValueError("lock")
            _exact_data(item, depth + 1)
        return
    raise ValueError("lock")


def _safe_path(path):
    if type(path) is not str:
        raise ValueError("path")
    try:
        encoded = path.encode("ascii")
    except UnicodeEncodeError as error:
        raise ValueError("path") from error
    if not encoded or len(encoded) > _MAX_PATH_BYTES:
        raise ValueError("path")
    if path.startswith("/") or "\\" in path or ":" in path:
        raise ValueError("path")
    parts = path.split("/")
    if len(parts) > _MAX_DEPTH or any(part in ("", ".", "..") for part in parts):
        raise ValueError("path")
    for part in parts:
        if len(part.encode("ascii")) > _MAX_COMPONENT_BYTES:
            raise ValueError("path")
        if any(ord(char) < 32 or ord(char) > 126 for char in part):
            raise ValueError("path")
        if any(char in _WINDOWS_FORBIDDEN for char in part):
            raise ValueError("path")
        trimmed = part.rstrip(" .")
        if trimmed != part or trimmed.split(".", 1)[0].upper() in _WINDOWS_DEVICES:
            raise ValueError("path")


def _headers(raw):
    message = email.parser.BytesParser(policy=email.policy.compat32).parsebytes(raw)
    return message


def _digest_record_entries(entries):
    payload = _canonical(entries)
    return hashlib.sha256(
        b"oncam.checkout.cryptography-wheel-record.v1\0" + payload
    ).hexdigest()


def _digest_resources(entries):
    payload = _canonical(entries)
    return hashlib.sha256(
        b"oncam.checkout.cryptography-wheel-resources.v1\0" + payload
    ).hexdigest()


def _rva_offset(raw, sections, rva, length):
    if type(rva) is not int or type(length) is not int or length < 0:
        raise ValueError("pe")
    for virtual_address, virtual_size, raw_offset, raw_size in sections:
        extent = max(virtual_size, raw_size)
        if virtual_address <= rva and rva - virtual_address <= extent:
            offset = raw_offset + (rva - virtual_address)
            if offset < 0 or offset + length > len(raw) or offset + length > raw_offset + raw_size:
                raise ValueError("pe")
            return offset
    raise ValueError("pe")


def _pe_imports(raw):
    if len(raw) < 0x40 or raw[:2] != b"MZ":
        raise ValueError("pe")
    pe_offset = struct.unpack_from("<I", raw, 0x3C)[0]
    if pe_offset + 24 > len(raw) or raw[pe_offset:pe_offset + 4] != b"PE\0\0":
        raise ValueError("pe")
    machine, section_count = struct.unpack_from("<HH", raw, pe_offset + 4)
    optional_size = struct.unpack_from("<H", raw, pe_offset + 20)[0]
    optional = pe_offset + 24
    if machine != 0x8664 or not 1 <= section_count <= 96:
        raise ValueError("pe")
    if optional_size < 128 or optional + optional_size > len(raw):
        raise ValueError("pe")
    if struct.unpack_from("<H", raw, optional)[0] != 0x20B:
        raise ValueError("pe")
    import_rva, import_size = struct.unpack_from("<II", raw, optional + 120)
    if import_rva == 0 or not 20 <= import_size <= 8192:
        raise ValueError("pe")
    section_table = optional + optional_size
    if section_table + section_count * 40 > len(raw):
        raise ValueError("pe")
    sections = []
    for index in range(section_count):
        offset = section_table + index * 40
        virtual_size, virtual_address, raw_size, raw_offset = struct.unpack_from(
            "<IIII", raw, offset + 8
        )
        if raw_offset + raw_size > len(raw):
            raise ValueError("pe")
        sections.append((virtual_address, virtual_size, raw_offset, raw_size))
    descriptor = _rva_offset(raw, sections, import_rva, import_size)
    imports = []
    for index in range(min(import_size // 20, 128)):
        entry = descriptor + index * 20
        values = struct.unpack_from("<IIIII", raw, entry)
        if values == (0, 0, 0, 0, 0):
            if not imports:
                raise ValueError("pe")
            return tuple(imports)
        name_offset = _rva_offset(raw, sections, values[3], 1)
        end = raw.find(b"\0", name_offset, min(name_offset + 261, len(raw)))
        if end < 0:
            raise ValueError("pe")
        name = raw[name_offset:end].decode("ascii")
        if not name or len(name) > 260:
            raise ValueError("pe")
        imports.append(name)
    raise ValueError("pe")


def _entry(path, content):
    return {"path": path, "sha256": hashlib.sha256(content).hexdigest(), "size": len(content)}


def _inspect(raw, expected):
    if type(raw) is not bytes or not 1 <= len(raw) <= _MAX_WHEEL_BYTES:
        raise ValueError("wheel")
    _exact_data(expected)
    with zipfile.ZipFile(io.BytesIO(raw), "r") as archive:
        infos = archive.infolist()
        if not 1 <= len(infos) <= _MAX_ENTRIES:
            raise ValueError("wheel")
        names = []
        folded = set()
        total = 0
        files = {}
        for info in infos:
            _safe_path(info.filename)
            key = info.filename.casefold()
            if key in folded:
                raise ValueError("wheel")
            folded.add(key)
            names.append(info.filename)
            if info.is_dir() or info.flag_bits & 1:
                raise ValueError("wheel")
            mode = (info.external_attr >> 16) & 0xFFFF
            kind = stat.S_IFMT(mode)
            if kind not in (0, stat.S_IFREG) or stat.S_ISLNK(mode):
                raise ValueError("wheel")
            if not 0 <= info.file_size <= _MAX_ENTRY_BYTES:
                raise ValueError("wheel")
            total += info.file_size
            if total > _MAX_TOTAL_BYTES:
                raise ValueError("wheel")
            content = archive.read(info)
            if len(content) != info.file_size:
                raise ValueError("wheel")
            files[info.filename] = content
    if set(files) != set(names) or _RECORD_PATH not in files:
        raise ValueError("wheel")

    reader = csv.reader(io.StringIO(files[_RECORD_PATH].decode("utf-8"), newline=""))
    record = {}
    for row in reader:
        if len(row) != 3 or row[0] in record:
            raise ValueError("record")
        _safe_path(row[0])
        record[row[0]] = (row[1], row[2])
    if set(record) != set(files) or record[_RECORD_PATH] != ("", ""):
        raise ValueError("record")
    inventory = []
    for path in sorted(files):
        content = files[path]
        digest = hashlib.sha256(content).digest()
        hex_digest = digest.hex()
        if path != _RECORD_PATH:
            encoded = base64.urlsafe_b64encode(digest).rstrip(b"=").decode("ascii")
            if record[path] != ("sha256=" + encoded, str(len(content))):
                raise ValueError("record")
        inventory.append({"path": path, "recordHash": hex_digest, "size": len(content)})

    metadata = _headers(files[_METADATA_PATH])
    wheel_metadata = _headers(files[_WHEEL_PATH])
    licenses = [
        _entry(path, files[path])
        for path in (
            f"{_DIST}/licenses/LICENSE",
            f"{_DIST}/licenses/LICENSE.APACHE",
            f"{_DIST}/licenses/LICENSE.BSD",
        )
    ]
    native_paths = sorted(path for path in files if path.lower().endswith(".pyd"))
    internal_dlls = sorted(path for path in files if path.lower().endswith(".dll"))
    if native_paths != [_NATIVE_PATH]:
        raise ValueError("native")
    native = files[_NATIVE_PATH]
    imports = _pe_imports(native)
    marker = _OPENSSL_MARKER.encode("ascii")
    if marker not in native:
        raise ValueError("openssl")
    resource_entries = [
        _entry(path, files[path])
        for path in sorted(files)
        if path.endswith(".pyi") or path == "cryptography/py.typed"
    ]
    actual = {
        "abiTag": "abi3",
        "archiveEntryCount": len(files),
        "archiveUncompressedSize": total,
        "bundledOpenSsl": {
            "containerPath": _NATIVE_PATH,
            "linkage": "static",
            "version": _OPENSSL_VERSION,
            "versionMarker": _OPENSSL_MARKER,
        },
        "closureScope": "wheel-archive-only",
        "externalDllImports": list(imports),
        "filename": _FILENAME,
        "internalDlls": internal_dlls,
        "licenses": licenses,
        "metadata": {
            "licenseExpression": metadata.get("License-Expression"),
            "metadataVersion": metadata.get("Metadata-Version"),
            "name": metadata.get("Name"),
            "path": _METADATA_PATH,
            "requiresDist": metadata.get_all("Requires-Dist", []),
            "requiresPython": metadata.get("Requires-Python"),
            "sha256": hashlib.sha256(files[_METADATA_PATH]).hexdigest(),
            "size": len(files[_METADATA_PATH]),
            "version": metadata.get("Version"),
        },
        "nativeExtensions": [dict(_entry(_NATIVE_PATH, native), machine="amd64")],
        "platformTag": "win_amd64",
        "project": "cryptography",
        "pythonCompatibility": {
            "architecture": "amd64",
            "implementation": "cpython",
            "interpreterTag": "cp314",
            "minimumPythonTag": "cp311",
        },
        "pythonTag": "cp311",
        "record": {
            "entryCount": len(files),
            "inventoryDigest": _digest_record_entries(inventory),
            "path": _RECORD_PATH,
            "sha256": hashlib.sha256(files[_RECORD_PATH]).hexdigest(),
            "size": len(files[_RECORD_PATH]),
        },
        "resourceInventory": {
            "entryCount": len(resource_entries),
            "inventoryDigest": _digest_resources(resource_entries),
        },
        "schema": _SCHEMA,
        "version": _VERSION,
        "wheelMetadata": {
            "generator": wheel_metadata.get("Generator"),
            "path": _WHEEL_PATH,
            "rootIsPurelib": wheel_metadata.get("Root-Is-Purelib") == "true",
            "sha256": hashlib.sha256(files[_WHEEL_PATH]).hexdigest(),
            "size": len(files[_WHEEL_PATH]),
            "tag": wheel_metadata.get("Tag"),
            "wheelVersion": wheel_metadata.get("Wheel-Version"),
        },
        "wheelSha256": hashlib.sha256(raw).hexdigest(),
        "wheelSize": len(raw),
    }
    if _canonical(expected) != _canonical(actual):
        raise ValueError("lock")
    return MappingProxyType(
        {
            "structuralOnly": True,
            "filename": _FILENAME,
            "version": _VERSION,
            "wheelSha256": actual["wheelSha256"],
            "recordCount": len(files),
            "recordInventoryDigest": actual["record"]["inventoryDigest"],
            "externalDllImports": tuple(imports),
            "closureScope": "wheel-archive-only",
        }
    )


def _make_public_api():
    refusal_type = CryptographyWheelLockRefused
    module_globals = globals()
    inspect_archive = _inspect
    mapping_proxy_type = MappingProxyType
    helper_names = (
        "_canonical", "_exact_data", "_safe_path", "_headers",
        "_digest_record_entries", "_digest_resources", "_rva_offset",
        "_pe_imports", "_entry", "_inspect",
    )
    constant_names = tuple(
        name for name in module_globals
        if name.isupper() or name.startswith("_MAX_")
    )
    authority = tuple(
        (name, module_globals[name])
        for name in (
            "CryptographyWheelLockRefused", "MappingProxyType", "Path",
            "base64", "csv", "email", "hashlib", "io", "json", "re",
            "stat", "struct", "zipfile", "__all__", *helper_names,
            *constant_names,
        )
    )
    dependencies = (
        (json, "dumps", json.dumps),
        (json, "loads", json.loads),
        (hashlib, "sha256", hashlib.sha256),
        (zipfile, "ZipFile", zipfile.ZipFile),
        (csv, "reader", csv.reader),
        (email.parser, "BytesParser", email.parser.BytesParser),
        (base64, "urlsafe_b64encode", base64.urlsafe_b64encode),
        (struct, "unpack_from", struct.unpack_from),
    )

    def freeze_metadata(value, depth=0):
        if depth > 8:
            raise ValueError("authority")
        if value is None or type(value) in (bool, int, str, bytes):
            return (type(value).__name__, value)
        if type(value) is tuple:
            return ("tuple", tuple(freeze_metadata(item, depth + 1) for item in value))
        if type(value) is dict:
            return (
                "dict",
                tuple(
                    (freeze_metadata(key, depth + 1), freeze_metadata(item, depth + 1))
                    for key, item in value.items()
                ),
            )
        raise ValueError("authority")

    def callable_state(function):
        defaults = getattr(function, "__defaults__", None)
        kwdefaults = getattr(function, "__kwdefaults__", None)
        return (
            function,
            type(function),
            getattr(function, "__code__", None),
            defaults,
            freeze_metadata(defaults),
            kwdefaults,
            freeze_metadata(kwdefaults),
            getattr(function, "__closure__", None),
            getattr(function, "__globals__", None),
        )

    callable_pins = tuple(
        (owner, name, callable_state(function))
        for owner, name, function in dependencies
    ) + tuple(
        (module_globals, name, callable_state(module_globals[name]))
        for name in helper_names
    )
    lock_path = Path(__file__).with_name(LOCK_FILENAME)
    try:
        lock_raw = lock_path.read_bytes()
        lock_document = json.loads(lock_raw)
        _exact_data(lock_document)
    except (KeyboardInterrupt, SystemExit):
        raise
    except BaseException:
        raise refusal_type("cryptography_wheel_lock") from None
    if lock_raw != _canonical(lock_document):
        raise refusal_type("cryptography_wheel_lock")
    lock_bytes = bytes(lock_raw)
    json_loads = json.loads

    def fixed_document():
        document = json_loads(lock_bytes)
        _exact_data(document)
        if _canonical(document) != lock_bytes:
            raise ValueError("lock")
        return document

    fixed_document_state = callable_state(fixed_document)

    def guard():
        try:
            for name, expected in authority:
                if module_globals.get(name) is not expected:
                    raise ValueError("authority")
            for owner, name, expected in callable_pins:
                current = owner.get(name) if type(owner) is dict else getattr(owner, name, None)
                if current is not expected[0] or type(current) is not expected[1]:
                    raise ValueError("dependency")
                defaults = getattr(current, "__defaults__", None)
                kwdefaults = getattr(current, "__kwdefaults__", None)
                if (
                    getattr(current, "__code__", None) is not expected[2]
                    or defaults is not expected[3]
                    or freeze_metadata(defaults) != expected[4]
                    or kwdefaults is not expected[5]
                    or freeze_metadata(kwdefaults) != expected[6]
                    or getattr(current, "__closure__", None) is not expected[7]
                    or getattr(current, "__globals__", None) is not expected[8]
                ):
                    raise ValueError("dependency")
            current = fixed_document
            expected = fixed_document_state
            if current is not expected[0] or type(current) is not expected[1]:
                raise ValueError("dependency")
            defaults = getattr(current, "__defaults__", None)
            kwdefaults = getattr(current, "__kwdefaults__", None)
            if (
                getattr(current, "__code__", None) is not expected[2]
                or defaults is not expected[3]
                or freeze_metadata(defaults) != expected[4]
                or kwdefaults is not expected[5]
                or freeze_metadata(kwdefaults) != expected[6]
                or getattr(current, "__closure__", None) is not expected[7]
                or getattr(current, "__globals__", None) is not expected[8]
            ):
                raise ValueError("dependency")
        except refusal_type:
            raise
        except Exception:
            raise refusal_type("cryptography_wheel_lock") from None

    def invoke(operation):
        try:
            guard()
            result = operation()
            guard()
            return result
        except (KeyboardInterrupt, SystemExit):
            raise
        except BaseException as error:
            if type(error) is refusal_type and str(error) == "cryptography_wheel_lock":
                raise
            raise refusal_type("cryptography_wheel_lock") from None

    def public(raw):
        return invoke(lambda: inspect_archive(raw, fixed_document()))

    def fixture(raw, document):
        return invoke(lambda: inspect_archive(raw, document))

    public.__name__ = "inspect_wheel"
    fixture.__name__ = "_inspect_archive_structural_fixture_only"
    return public, fixture


inspect_wheel, _inspect_archive_structural_fixture_only = _make_public_api()
