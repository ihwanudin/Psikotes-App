"""Pure structural inspection of pinned cryptography dependency wheel archives.

This module does not download, install, import, or execute packages and does
not prove host/system-DLL runtime closure, acquisition, signature, trust,
freshness, replay safety, or admission.  Composition must pin the exported
callable and bind these supplied bytes to authenticated acquisition evidence.
"""

import base64
import csv
import email.parser
import email.policy
import hashlib
import io
import json
from pathlib import Path
import stat
import struct
from types import MappingProxyType
import zipfile


__all__ = ("CryptographyRuntimeClosureRefused", "inspect_runtime_closure")
LOCK_FILENAME = "checkout-cryptography-runtime-closure-v1.json"
_SCHEMA = "oncam.checkout.cryptography-runtime-closure.v1"
_PROJECTS = ("cffi", "pycparser")
_MAX_ARCHIVE_BYTES = 2 * 1024 * 1024
_MAX_ENTRIES = 512
_MAX_ENTRY_BYTES = 4 * 1024 * 1024
_MAX_TOTAL_BYTES = 16 * 1024 * 1024
_MAX_PATH_BYTES = 1024
_MAX_DEPTH = 32
_MAX_COMPONENT_BYTES = 255
_FORBIDDEN = frozenset('<>:"\\|?*')
_DEVICES = frozenset(
    {"CON", "PRN", "AUX", "NUL", "CONIN$", "CONOUT$"}
    | {f"COM{i}" for i in range(1, 10)}
    | {f"LPT{i}" for i in range(1, 10)}
)


class CryptographyRuntimeClosureRefused(RuntimeError):
    pass


def _canonical(value):
    return (json.dumps(value, sort_keys=True, separators=(",", ":"),
                       ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")


def _exact(value, depth=0):
    if depth > 12:
        raise ValueError("document")
    if value is None or type(value) in (bool, int, str):
        return
    if type(value) is list:
        if len(value) > 1024:
            raise ValueError("document")
        for item in value:
            _exact(item, depth + 1)
        return
    if type(value) is dict:
        if len(value) > 128:
            raise ValueError("document")
        for key, item in value.items():
            if type(key) is not str:
                raise ValueError("document")
            _exact(item, depth + 1)
        return
    raise ValueError("document")


def _safe_path(path):
    if type(path) is not str:
        raise ValueError("path")
    try:
        raw = path.encode("ascii")
    except UnicodeEncodeError as error:
        raise ValueError("path") from error
    if not raw or len(raw) > _MAX_PATH_BYTES or path.startswith("/") \
            or "\\" in path or ":" in path:
        raise ValueError("path")
    parts = path.split("/")
    if len(parts) > _MAX_DEPTH or any(part in ("", ".", "..") for part in parts):
        raise ValueError("path")
    for part in parts:
        if len(part) > _MAX_COMPONENT_BYTES or any(
            ord(char) < 32 or ord(char) > 126 or char in _FORBIDDEN for char in part
        ):
            raise ValueError("path")
        trimmed = part.rstrip(" .")
        if trimmed != part or trimmed.split(".", 1)[0].upper() in _DEVICES:
            raise ValueError("path")


def _entry(path, raw):
    return {"path": path, "sha256": hashlib.sha256(raw).hexdigest(), "size": len(raw)}


def _digest(domain, entries):
    return hashlib.sha256(domain + _canonical(entries)).hexdigest()


def _rva_offset(raw, sections, rva, length):
    for address, virtual_size, offset, size in sections:
        if address <= rva < address + max(virtual_size, size):
            result = offset + rva - address
            if result + length <= len(raw) and result + length <= offset + size:
                return result
    raise ValueError("pe")


def _pe_imports(raw):
    if len(raw) < 0x40 or raw[:2] != b"MZ":
        raise ValueError("pe")
    pe = struct.unpack_from("<I", raw, 0x3C)[0]
    if pe + 24 > len(raw) or raw[pe:pe + 4] != b"PE\0\0":
        raise ValueError("pe")
    machine, count = struct.unpack_from("<HH", raw, pe + 4)
    optional_size = struct.unpack_from("<H", raw, pe + 20)[0]
    optional = pe + 24
    if machine != 0x8664 or not 1 <= count <= 96 or optional_size < 128 \
            or optional + optional_size > len(raw) \
            or struct.unpack_from("<H", raw, optional)[0] != 0x20B:
        raise ValueError("pe")
    import_rva, import_size = struct.unpack_from("<II", raw, optional + 120)
    table = optional + optional_size
    if not import_rva or not 20 <= import_size <= 8192 \
            or table + count * 40 > len(raw):
        raise ValueError("pe")
    sections = []
    for index in range(count):
        offset = table + index * 40
        virtual_size, address, size, raw_offset = struct.unpack_from(
            "<IIII", raw, offset + 8
        )
        if raw_offset + size > len(raw):
            raise ValueError("pe")
        sections.append((address, virtual_size, raw_offset, size))
    descriptor = _rva_offset(raw, sections, import_rva, import_size)
    result = []
    for index in range(min(import_size // 20, 128)):
        values = struct.unpack_from("<IIIII", raw, descriptor + index * 20)
        if values == (0, 0, 0, 0, 0):
            if not result:
                raise ValueError("pe")
            return result
        name_offset = _rva_offset(raw, sections, values[3], 1)
        end = raw.find(b"\0", name_offset, min(name_offset + 261, len(raw)))
        if end < 0:
            raise ValueError("pe")
        result.append(raw[name_offset:end].decode("ascii"))
    raise ValueError("pe")


def _inspect_one(raw, expected):
    if type(raw) is not bytes or not 1 <= len(raw) <= _MAX_ARCHIVE_BYTES:
        raise ValueError("archive")
    with zipfile.ZipFile(io.BytesIO(raw), "r") as archive:
        infos = archive.infolist()
        if not 1 <= len(infos) <= _MAX_ENTRIES:
            raise ValueError("archive")
        files = {}
        folded = set()
        total = 0
        for info in infos:
            _safe_path(info.filename)
            key = info.filename.casefold()
            if key in folded:
                raise ValueError("archive")
            folded.add(key)
            mode = (info.external_attr >> 16) & 0xFFFF
            kind = stat.S_IFMT(mode)
            if info.is_dir() or info.flag_bits & 1 or stat.S_ISLNK(mode) \
                    or kind not in (0, stat.S_IFREG):
                raise ValueError("archive")
            if not 0 <= info.file_size <= _MAX_ENTRY_BYTES:
                raise ValueError("archive")
            total += info.file_size
            if total > _MAX_TOTAL_BYTES:
                raise ValueError("archive")
            content = archive.read(info)
            if len(content) != info.file_size:
                raise ValueError("archive")
            files[info.filename] = content

    project = expected["project"]
    version = expected["version"]
    dist = f"{project}-{version}.dist-info"
    metadata_path = f"{dist}/METADATA"
    wheel_path = f"{dist}/WHEEL"
    record_path = f"{dist}/RECORD"
    if any(path not in files for path in (metadata_path, wheel_path, record_path)):
        raise ValueError("archive")
    reader = csv.reader(io.StringIO(files[record_path].decode("utf-8"), newline=""))
    records = {}
    for row in reader:
        if len(row) != 3 or row[0] in records:
            raise ValueError("record")
        _safe_path(row[0])
        records[row[0]] = (row[1], row[2])
    if set(records) != set(files) or records[record_path] != ("", ""):
        raise ValueError("record")
    inventory = []
    for path in sorted(files):
        content = files[path]
        digest = hashlib.sha256(content).digest()
        if path != record_path:
            encoded = base64.urlsafe_b64encode(digest).rstrip(b"=").decode("ascii")
            if records[path] != ("sha256=" + encoded, str(len(content))):
                raise ValueError("record")
        inventory.append({"path": path, "recordHash": digest.hex(), "size": len(content)})
    metadata = email.parser.BytesParser(policy=email.policy.compat32).parsebytes(
        files[metadata_path]
    )
    wheel = email.parser.BytesParser(policy=email.policy.compat32).parsebytes(files[wheel_path])
    licenses = [_entry(path, files[path]) for path in sorted(files)
                if path.startswith(f"{dist}/licenses/")]
    native_paths = sorted(path for path in files if path.lower().endswith(".pyd"))
    dll_paths = sorted(path for path in files if path.lower().endswith(".dll"))
    native = [dict(_entry(path, files[path]), machine="amd64") for path in native_paths]
    imports = []
    for path in native_paths:
        imports.extend(_pe_imports(files[path]))
    resources = [_entry(path, files[path]) for path in sorted(files)
                 if path.endswith((".h", ".pyi")) or path.endswith("/py.typed")]
    actual = {
        "archiveEntryCount": len(files),
        "externalDllImports": imports,
        "filename": f"{project}-{version}-{wheel.get('Tag')}.whl",
        "licenses": licenses,
        "metadata": {
            "licenseExpression": metadata.get("License-Expression"),
            "metadataVersion": metadata.get("Metadata-Version"),
            "path": metadata_path,
            "requiresDist": metadata.get_all("Requires-Dist", []),
            "requiresPython": metadata.get("Requires-Python"),
            "sha256": hashlib.sha256(files[metadata_path]).hexdigest(),
            "size": len(files[metadata_path]),
        },
        "nativeExtensions": native,
        "project": metadata.get("Name"),
        "record": {
            "entryCount": len(files), "path": record_path,
            "inventoryDigest": _digest(
                b"oncam.checkout.cryptography-runtime-record.v1\0", inventory
            ),
            "sha256": hashlib.sha256(files[record_path]).hexdigest(),
            "size": len(files[record_path]),
        },
        "resources": {
            "entryCount": len(resources),
            "inventoryDigest": _digest(
                b"oncam.checkout.cryptography-runtime-resources.v1\0", resources
            ),
        },
        "rootIsPurelib": wheel.get("Root-Is-Purelib") == "true",
        "tag": wheel.get("Tag"),
        "version": metadata.get("Version"),
        "wheelSha256": hashlib.sha256(raw).hexdigest(),
        "wheelSize": len(raw),
    }
    if dll_paths or _canonical(actual) != _canonical(expected):
        raise ValueError("lock")


def _inspect(archives, document):
    if type(archives) is not tuple or len(archives) != 2:
        raise ValueError("archives")
    _exact(document)
    if type(document) is not dict or set(document) != {
        "closureScope", "cryptographyRequiresDist", "schema", "wheels"
    } or document["schema"] != _SCHEMA \
            or document["closureScope"] != "python-wheel-archives-only" \
            or document["cryptographyRequiresDist"] != [
                "cffi>=2.0.0 ; platform_python_implementation != 'PyPy'"
            ] or type(document["wheels"]) is not list \
            or len(document["wheels"]) != 2 \
            or tuple(item.get("project") for item in document["wheels"]) != _PROJECTS:
        raise ValueError("document")
    for raw, expected in zip(archives, document["wheels"]):
        _inspect_one(raw, expected)
    if document["wheels"][0]["metadata"]["requiresDist"] != [
        'pycparser; implementation_name != "PyPy"'
    ] or document["wheels"][1]["metadata"]["requiresDist"] != []:
        raise ValueError("dependency")
    return MappingProxyType({
        "structuralOnly": True,
        "wheelCount": 2,
        "projects": _PROJECTS,
        "wheelDigests": tuple(item["wheelSha256"] for item in document["wheels"]),
        "closureScope": "python-wheel-archives-only",
    })


def _make_api():
    refusal = CryptographyRuntimeClosureRefused
    module_globals = globals()
    inspect = _inspect
    helper_names = (
        "_canonical", "_exact", "_safe_path", "_entry", "_digest",
        "_rva_offset", "_pe_imports", "_inspect_one", "_inspect",
    )
    pinned = tuple((name, module_globals[name]) for name in (
        "CryptographyRuntimeClosureRefused", "MappingProxyType", "LOCK_FILENAME",
        "__all__", "base64", "csv", "email", "hashlib", "io", "json", "Path",
        "stat", "struct", "zipfile", "_SCHEMA", "_PROJECTS", "_MAX_ARCHIVE_BYTES",
        "_MAX_ENTRIES", "_MAX_ENTRY_BYTES", "_MAX_TOTAL_BYTES", "_MAX_PATH_BYTES",
        "_MAX_DEPTH", "_MAX_COMPONENT_BYTES", "_FORBIDDEN", "_DEVICES", *helper_names,
    ))

    def state(function):
        return (
            function, type(function), getattr(function, "__code__", None),
            getattr(function, "__defaults__", None),
            getattr(function, "__kwdefaults__", None),
            getattr(function, "__closure__", None),
            getattr(function, "__globals__", None),
        )

    dependencies = (
        (module_globals, name, module_globals[name]) for name in helper_names
    )
    dependencies = tuple(dependencies) + (
        (json, "dumps", json.dumps),
        (json, "loads", json.loads),
        (hashlib, "sha256", hashlib.sha256),
        (zipfile, "ZipFile", zipfile.ZipFile),
        (csv, "reader", csv.reader),
        (email.parser, "BytesParser", email.parser.BytesParser),
        (base64, "urlsafe_b64encode", base64.urlsafe_b64encode),
        (struct, "unpack_from", struct.unpack_from),
    )
    function_pins = tuple(
        (owner, name, state(function)) for owner, name, function in dependencies
    )
    path = Path(__file__).with_name(LOCK_FILENAME)
    try:
        lock_bytes = bytes(path.read_bytes())
        parsed = json.loads(lock_bytes)
        _exact(parsed)
    except (KeyboardInterrupt, SystemExit):
        raise
    except BaseException:
        raise refusal("cryptography_runtime_closure") from None
    if _canonical(parsed) != lock_bytes:
        raise refusal("cryptography_runtime_closure")

    def fixed_document():
        value = json.loads(lock_bytes)
        _exact(value)
        if _canonical(value) != lock_bytes:
            raise ValueError("lock")
        return value

    fixed_state = state(fixed_document)

    def guard():
        try:
            for name, expected in pinned:
                if module_globals.get(name) is not expected:
                    raise ValueError("authority")
            for owner, name, expected in function_pins:
                current = owner.get(name) if type(owner) is dict else getattr(owner, name, None)
                if current is not expected[0] or type(current) is not expected[1]:
                    raise ValueError("authority")
                observed = state(current)
                if observed[2] is not expected[2] \
                        or observed[3] is not expected[3] \
                        or observed[4] is not expected[4] \
                        or observed[5] is not expected[5] \
                        or observed[6] is not expected[6]:
                    raise ValueError("authority")
            observed = state(fixed_document)
            if fixed_document is not fixed_state[0] \
                    or type(fixed_document) is not fixed_state[1] \
                    or observed[2] is not fixed_state[2] \
                    or observed[3] is not fixed_state[3] \
                    or observed[4] is not fixed_state[4] \
                    or observed[5] is not fixed_state[5] \
                    or observed[6] is not fixed_state[6]:
                raise ValueError("authority")
        except refusal:
            raise
        except Exception:
            raise refusal("cryptography_runtime_closure") from None

    def invoke(operation):
        try:
            guard()
            result = operation()
            guard()
            return result
        except (KeyboardInterrupt, SystemExit):
            raise
        except BaseException as error:
            if type(error) is refusal and str(error) == "cryptography_runtime_closure":
                raise
            raise refusal("cryptography_runtime_closure") from None

    def public(archives):
        return invoke(lambda: inspect(archives, fixed_document()))

    def fixture(archives, document):
        return invoke(lambda: inspect(archives, document))

    public.__name__ = "inspect_runtime_closure"
    fixture.__name__ = "_inspect_runtime_fixture_only"
    return public, fixture


inspect_runtime_closure, _inspect_runtime_fixture_only = _make_api()
