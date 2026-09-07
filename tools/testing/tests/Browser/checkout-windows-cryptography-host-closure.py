"""Observe an explicit isolated Windows cryptography host closure.

This module performs no installation, import, execution, acquisition, or
admission.  Its immutable result only says that a sealed descriptor adapter
reported bytes, identities, PE metadata, and an inventory matching the
supplied manifest at sampled boundaries.  The supplied manifest is not the
accepted wheel authority: composition must separately authenticate and bind
the public wheel/runtime/acquisition/tool-descriptor results.  Native adapter
efficacy, delay-import enumeration, dynamic loading, API-set resolution, and
actual system-DLL identity remain runtime responsibilities.
"""

from __future__ import annotations

import base64
import csv
import hashlib
import io
import json
import re
import types
import weakref
from types import MappingProxyType


MAX_FILE_BYTES = 16 * 1024 * 1024
MAX_FILES = 8192
MAX_PATH_BYTES = 4096
MAX_CAPABILITIES = 64
ACCEPTED_CRYPTOGRAPHY_IMPORTS = (
    "python3.dll", "api-ms-win-core-synch-l1-2-0.dll", "bcryptprimitives.dll",
    "USER32.dll", "CRYPT32.dll", "WS2_32.dll", "ADVAPI32.dll", "KERNEL32.dll",
    "ntdll.dll", "VCRUNTIME140.dll", "api-ms-win-crt-string-l1-1-0.dll",
    "api-ms-win-crt-runtime-l1-1-0.dll", "api-ms-win-crt-utility-l1-1-0.dll",
    "api-ms-win-crt-heap-l1-1-0.dll", "api-ms-win-crt-time-l1-1-0.dll",
    "api-ms-win-crt-stdio-l1-1-0.dll", "api-ms-win-crt-convert-l1-1-0.dll",
    "api-ms-win-crt-filesystem-l1-1-0.dll", "api-ms-win-crt-environment-l1-1-0.dll",
)
ACCEPTED_CFFI_IMPORTS = (
    "python314.dll", "USER32.dll", "KERNEL32.dll", "VCRUNTIME140.dll",
    "api-ms-win-crt-heap-l1-1-0.dll", "api-ms-win-crt-stdio-l1-1-0.dll",
    "api-ms-win-crt-convert-l1-1-0.dll", "api-ms-win-crt-string-l1-1-0.dll",
    "api-ms-win-crt-runtime-l1-1-0.dll", "api-ms-win-crt-math-l1-1-0.dll",
)
ACCEPTED_SYSTEM_DLLS = tuple(sorted({
    *ACCEPTED_CRYPTOGRAPHY_IMPORTS, *ACCEPTED_CFFI_IMPORTS,
} - {"python3.dll", "python314.dll", "VCRUNTIME140.dll"}, key=str.casefold))
_PACKAGES = (
    ("cryptography", "50.0.1", "cryptography-50.0.1-cp311-abi3-win_amd64.whl", 120),
    ("cffi", "2.1.1", "cffi-2.1.1-cp314-cp314-win_amd64.whl", 31),
    ("pycparser", "3.0", "pycparser-3.0-py3-none-any.whl", 13),
)
_ADAPTER_METHODS = (
    "root_identity", "inventory", "open", "identity", "read", "pe_imports", "close",
)
_RESULT_KEYS = (
    "hostClosureObservationOnly", "rootIdentityDigest", "inventoryDigest",
    "manifestDigest", "fileCount", "packageCount", "observationDigest",
)
_RESERVED = frozenset({
    "con", "prn", "aux", "nul", "conin$", "conout$",
    *(f"com{index}" for index in range(1, 10)),
    *(f"lpt{index}" for index in range(1, 10)),
})


class HostClosureRefused(Exception):
    """Fixed refusal without path, identity, host, or parser details."""


__all__ = ("HostClosureRefused", "seal_adapter", "observe")


def _make_api():
    refusal = HostClosureRefused
    mapping_proxy = MappingProxyType
    types_module = types
    function_type = types.FunctionType
    module_globals = globals()
    class CapabilityToken:
        __slots__ = ("__weakref__",)
        __hash__ = object.__hash__
        __eq__ = object.__eq__

    capability_type = CapabilityToken
    token_hash = CapabilityToken.__dict__["__hash__"]
    token_eq = CapabilityToken.__dict__["__eq__"]
    capabilities = weakref.WeakKeyDictionary()
    maximum_file = MAX_FILE_BYTES
    maximum_files = MAX_FILES
    maximum_path = MAX_PATH_BYTES
    maximum_capabilities = MAX_CAPABILITIES
    packages = _PACKAGES
    adapter_methods = _ADAPTER_METHODS
    result_keys = _RESULT_KEYS
    reserved = _RESERVED
    surface = __all__
    json_module, hashlib_module, csv_module, io_module, base64_module, re_module = (
        json, hashlib, csv, io, base64, re,
    )
    json_dumps, sha256, csv_reader = json.dumps, hashlib.sha256, csv.reader
    string_io = io.StringIO
    b64decode, b64encode, fullmatch = (
        base64.urlsafe_b64decode, base64.urlsafe_b64encode, re.fullmatch,
    )

    def freeze(value, depth=0):
        if depth > 8:
            raise ValueError("metadata")
        if value is None or type(value) in (bool, int, str, bytes):
            return (type(value).__name__, value)
        if type(value) is tuple:
            return ("tuple", tuple(freeze(item, depth + 1) for item in value))
        if type(value) is dict and all(type(key) is str for key in value):
            return ("dict", tuple((key, freeze(item, depth + 1))
                                  for key, item in value.items()))
        raise ValueError("metadata")

    def state(function):
        defaults = getattr(function, "__defaults__", None)
        kwdefaults = getattr(function, "__kwdefaults__", None)
        return (
            function, type(function), getattr(function, "__code__", None),
            defaults, freeze(defaults), kwdefaults, freeze(kwdefaults),
            getattr(function, "__closure__", None), getattr(function, "__globals__", None),
        )

    dependency_pins = (
        (json_module, "dumps", state(json_dumps)),
        (hashlib_module, "sha256", state(sha256)),
        (csv_module, "reader", state(csv_reader)),
        (io_module, "StringIO", state(string_io)),
        (base64_module, "urlsafe_b64decode", state(b64decode)),
        (base64_module, "urlsafe_b64encode", state(b64encode)),
        (re_module, "fullmatch", state(fullmatch)),
    )
    global_pins = (
        ("MAX_FILE_BYTES", maximum_file), ("MAX_FILES", maximum_files),
        ("MAX_PATH_BYTES", maximum_path), ("_PACKAGES", packages),
        ("MAX_CAPABILITIES", maximum_capabilities),
        ("ACCEPTED_CRYPTOGRAPHY_IMPORTS", ACCEPTED_CRYPTOGRAPHY_IMPORTS),
        ("ACCEPTED_CFFI_IMPORTS", ACCEPTED_CFFI_IMPORTS),
        ("ACCEPTED_SYSTEM_DLLS", ACCEPTED_SYSTEM_DLLS),
        ("_ADAPTER_METHODS", adapter_methods), ("_RESULT_KEYS", result_keys),
        ("_RESERVED", reserved), ("HostClosureRefused", refusal),
        ("MappingProxyType", mapping_proxy),
        ("__all__", surface), ("json", json_module), ("hashlib", hashlib_module),
        ("csv", csv_module), ("io", io_module), ("base64", base64_module), ("re", re_module),
        ("types", types),
        ("weakref", weakref),
    )

    def authority():
        for name, expected in global_pins:
            if module_globals.get(name) is not expected:
                raise ValueError("authority")
        if types_module.FunctionType is not function_type:
            raise ValueError("dependency")
        for owner, name, expected in dependency_pins:
            current = getattr(owner, name, None)
            if current is not expected[0] or type(current) is not expected[1]:
                raise ValueError("dependency")
            observed = state(current)
            if observed[2] is not expected[2] or observed[3] is not expected[3] \
                    or observed[4] != expected[4] or observed[5] is not expected[5] \
                    or observed[6] != expected[6] or observed[7] is not expected[7] \
                    or observed[8] is not expected[8]:
                raise ValueError("dependency")

    def method_state(function):
        if type(function) is not function_type:
            raise ValueError("adapter")
        observed = state(function)
        if observed[3] is not None or observed[5] is not None or observed[7] is not None:
            raise ValueError("adapter")
        return observed

    def adapter_guard(sealed):
        adapter, expected_cls, mro, methods = sealed
        cls = type(adapter)
        if cls is not expected_cls or type(cls) is not type or cls.__mro__ is not mro:
            raise ValueError("adapter")
        for name, function, expected in methods:
            if cls.__dict__.get(name) is not function:
                raise ValueError("adapter")
            observed = method_state(function)
            if observed[0] is not expected[0] or observed[1] is not expected[1] \
                    or observed[2] is not expected[2] or observed[3] is not expected[3] \
                    or observed[4] != expected[4] or observed[5] is not expected[5] \
                    or observed[6] != expected[6] or observed[7] is not expected[7] \
                    or observed[8] is not expected[8]:
                raise ValueError("adapter")
        return adapter

    def callback_for(sealed, name):
        for observed_name, function, _state in sealed[3]:
            if observed_name == name:
                return function
        raise ValueError("adapter")

    def call(sealed, name, *arguments):
        authority()
        adapter = adapter_guard(sealed)
        callback = callback_for(sealed, name)
        try:
            result = callback(adapter, *arguments)
        except BaseException as primary:
            try:
                authority()
                adapter_guard(sealed)
            except BaseException:
                pass
            raise primary
        authority()
        adapter_guard(sealed)
        return result

    def exact_dict(value, keys):
        return type(value) is dict and all(type(key) is str for key in value) and set(value) == set(keys)

    def digest_text(value):
        return type(value) is str and fullmatch(r"[a-f0-9]{64}", value) is not None

    def decimal(value):
        return type(value) is str and fullmatch(r"[1-9][0-9]{0,38}", value) is not None \
            and int(value) < (1 << 128)

    def safe_relative(value):
        if type(value) is not str or not value or len(value.encode("ascii", "strict")) > maximum_path \
                or value.startswith("/") or "\\" in value or ":" in value:
            return False
        parts = value.split("/")
        if len(parts) > 64:
            return False
        for part in parts:
            if not part or len(part.encode("ascii", "strict")) > 255 \
                    or part in (".", "..") or part[-1] in " ." \
                    or any(character in '<>"|?*' or not 32 <= ord(character) <= 126
                           for character in part):
                return False
            base = part.rstrip(" .").split(".", 1)[0].casefold()
            if base in reserved:
                return False
        return True

    def canonical_root(value):
        if type(value) is not str or len(value) < 4 or not "A" <= value[0] <= "Z" \
                or value[1:3] != ":/" or value.endswith("/") or "\\" in value:
            return False
        try:
            return safe_relative(value[3:])
        except (UnicodeError, ValueError):
            return False

    def canonical(value):
        return (json_dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=True,
                           allow_nan=False) + "\n").encode("ascii")

    def validate_manifest(value, root):
        if not exact_dict(value, ("version", "root", "python", "loaderPolicy", "runtimeFiles",
                                  "packages", "systemDllAllowlist")) \
                or type(value["version"]) is not int or value["version"] != 1:
            raise ValueError("manifest")
        root_value = value["root"]
        if not exact_dict(root_value, ("path", "volumeSerial", "fileId")) \
                or root_value["path"] != root or not canonical_root(root) \
                or not decimal(root_value["volumeSerial"]) or not decimal(root_value["fileId"]):
            raise ValueError("root")
        python = value["python"]
        if not exact_dict(python, ("path", "version", "implementation", "architecture", "sha256", "imports")) \
                or python["path"] != "python.exe" or python["version"] != "3.14.0" \
                or python["implementation"] != "cpython" or python["architecture"] != "amd64" \
                or not digest_text(python["sha256"]):
            raise ValueError("python")
        loader = value["loaderPolicy"]
        if not exact_dict(loader, ("path", "sha256", "mode")) \
                or loader["path"] != "loader-policy.json" or loader["mode"] != "isolated-root-only" \
                or not digest_text(loader["sha256"]):
            raise ValueError("loader")
        runtime = value["runtimeFiles"]
        if type(runtime) is not list or not runtime or len(runtime) > maximum_files:
            raise ValueError("runtime")
        runtime_paths = []
        for item in runtime:
            if not exact_dict(item, ("path", "sha256")) or not safe_relative(item["path"]) \
                    or "/" in item["path"] or not item["path"].lower().endswith(".dll") \
                    or not digest_text(item["sha256"]):
                raise ValueError("runtime")
            runtime_paths.append(item["path"])
        if runtime_paths != ["python314.dll", "python3.dll", "VCRUNTIME140.dll", "ucrtbase.dll"]:
            raise ValueError("runtime")
        allowlist = value["systemDllAllowlist"]
        if type(allowlist) is not list or not allowlist \
                or any(type(name) is not str or fullmatch(r"[A-Za-z0-9._-]+\.dll", name) is None for name in allowlist) \
                or tuple(allowlist) != ACCEPTED_SYSTEM_DLLS \
                or len({name.casefold() for name in allowlist}) != len(allowlist):
            raise ValueError("allowlist")
        imports = python["imports"]
        if type(imports) is not list or imports != runtime_paths[:1] + runtime_paths[2:]:
            raise ValueError("python imports")
        package_values = value["packages"]
        if type(package_values) is not list or len(package_values) != 3:
            raise ValueError("packages")
        expected_paths = [python["path"], loader["path"], *runtime_paths]
        parsed_packages = []
        for item, expected in zip(package_values, packages):
            if not exact_dict(item, ("name", "version", "wheelFilename", "recordPath", "recordSha256", "files")) \
                    or (item["name"], item["version"], item["wheelFilename"]) != expected[:3] \
                    or item["recordPath"] != f"{expected[0]}-{expected[1]}.dist-info/RECORD" \
                    or not digest_text(item["recordSha256"]) or type(item["files"]) is not list \
                    or len(item["files"]) != expected[3] - 1:
                raise ValueError("package")
            paths = []
            for entry in item["files"]:
                if not exact_dict(entry, ("path", "sha256", "size")) or not safe_relative(entry["path"]) \
                        or not digest_text(entry["sha256"]) or type(entry["size"]) is not int \
                        or not 0 <= entry["size"] <= maximum_file:
                    raise ValueError("file")
                paths.append(entry["path"])
                expected_paths.append(entry["path"])
            if paths != sorted(paths, key=lambda path: (path.casefold(), path)):
                raise ValueError("file order")
            expected_paths.append(item["recordPath"])
            parsed_packages.append(item)
        if len(expected_paths) > maximum_files or len({path.casefold() for path in expected_paths}) != len(expected_paths):
            raise ValueError("inventory")
        return root_value, python, loader, runtime, parsed_packages, allowlist, expected_paths

    def parse_record(raw, package):
        if type(raw) is not bytes or len(raw) > maximum_file:
            raise ValueError("record")
        text = raw.decode("ascii", "strict")
        rows = list(csv_reader(string_io(text, newline="")))
        expected = package["files"]
        if len(rows) != len(expected) + 1 or rows[-1] != [package["recordPath"], "", ""]:
            raise ValueError("record")
        for row, entry in zip(rows[:-1], expected):
            if len(row) != 3 or row[0] != entry["path"] or row[2] != str(entry["size"]) \
                    or not row[1].startswith("sha256="):
                raise ValueError("record")
            encoded = row[1][7:]
            padding = "=" * ((4 - len(encoded) % 4) % 4)
            observed = b64decode((encoded + padding).encode("ascii"))
            if fullmatch(r"[A-Za-z0-9_-]{43}", encoded) is None \
                    or len(observed) != 32 or observed.hex() != entry["sha256"] \
                    or b64encode(observed).decode("ascii").rstrip("=") != encoded:
                raise ValueError("record")

    def cleanup(sealed, handle):
        adapter = sealed[0]
        callback = callback_for(sealed, "close")
        primary = None
        try:
            callback(adapter, handle)
        except BaseException as error:
            primary = error
        try:
            authority()
            adapter_guard(sealed)
        except BaseException as error:
            if primary is None:
                primary = error
        if primary is not None:
            raise primary

    def open_handle(sealed, root, path):
        authority()
        adapter = adapter_guard(sealed)
        callback = callback_for(sealed, "open")
        handle = callback(adapter, root, path)
        try:
            authority()
            adapter_guard(sealed)
        except BaseException as primary:
            try:
                cleanup(sealed, handle)
            except BaseException:
                pass
            raise primary
        return handle

    def read_file(sealed, root, path):
        handle = open_handle(sealed, root, path)
        primary = None
        result = None
        try:
            before = call(sealed, "identity", handle)
            raw = call(sealed, "read", handle, maximum_file + 1)
            after = call(sealed, "identity", handle)
            imports = call(sealed, "pe_imports", handle)
            final = call(sealed, "identity", handle)
            if before != after or before != final or type(raw) is not bytes or len(raw) > maximum_file:
                raise ValueError("identity")
            result = (raw, before, imports)
        except BaseException as error:
            primary = error
        try:
            cleanup(sealed, handle)
        except BaseException as error:
            if primary is None:
                primary = error
        if primary is not None:
            raise primary
        return result

    def inspect(sealed, root, manifest):
        root_value, python, loader, runtime, parsed_packages, allowlist, expected_paths = validate_manifest(manifest, root)
        before_root = call(sealed, "root_identity", root)
        inventory = call(sealed, "inventory", root)
        canonical_inventory = tuple(sorted(expected_paths, key=lambda item: (item.casefold(), item)))
        if type(inventory) is not tuple or any(type(path) is not str for path in inventory) \
                or len(inventory) > maximum_files or any(not safe_relative(path) for path in inventory) \
                or len({path.casefold() for path in inventory}) != len(inventory) \
                or inventory != canonical_inventory:
            raise ValueError("inventory")
        root_parts = root[3:].split("/")
        expected_chain_paths = ("C:/",) + tuple(
            "C:/" + "/".join(root_parts[:index])
            for index in range(1, len(root_parts) + 1)
        )
        if type(before_root) is not tuple or len(before_root) != len(expected_chain_paths) \
                or any(type(item) is not tuple or len(item) != 4
                       or not canonical_root(item[0]) and item[0] != "C:/"
                       or not decimal(item[1]) or not decimal(item[2])
                       or type(item[3]) is not bool or item[3]
                       for item in before_root) \
                or before_root[-1] != (root, root_value["volumeSerial"], root_value["fileId"], False) \
                or tuple(item[0] for item in before_root) != expected_chain_paths \
                or len({item[0].casefold() for item in before_root}) != len(before_root):
            raise ValueError("root")
        if any(item[1] != root_value["volumeSerial"] for item in before_root) \
                or len({(item[1], item[2]) for item in before_root}) != len(before_root):
            raise ValueError("root")
        specifications = {python["path"]: python["sha256"], loader["path"]: loader["sha256"]}
        specifications.update({item["path"]: item["sha256"] for item in runtime})
        for package in parsed_packages:
            specifications[package["recordPath"]] = package["recordSha256"]
            specifications.update({entry["path"]: entry["sha256"] for entry in package["files"]})
        runtime_names = {item["path"].casefold() for item in runtime}
        allowed_imports = runtime_names | {name.casefold() for name in allowlist}
        identities = []
        observed_raw = {}
        for path in sorted(expected_paths, key=lambda item: (item.casefold(), item)):
            raw, identity, imports = read_file(sealed, root, path)
            observed_raw[path] = raw
            if sha256(raw).hexdigest() != specifications[path] or type(identity) is not tuple \
                    or len(identity) != 4 or not decimal(identity[0]) or not decimal(identity[1]) \
                    or type(identity[2]) is not bool or identity[2] \
                    or identity[0] != root_value["volumeSerial"] \
                    or identity[3] != f"{root}/{path}":
                raise ValueError("file")
            if not exact_dict(imports, ("normal", "delay")) \
                    or type(imports["normal"]) is not tuple or type(imports["delay"]) is not tuple \
                    or any(type(name) is not str for name in imports["normal"] + imports["delay"]) \
                    or imports["delay"]:
                raise ValueError("imports")
            imports = imports["normal"]
            if any(not 1 <= len(name) <= 260 or not name.isascii()
                   or fullmatch(r"[A-Za-z0-9._-]+\.dll", name) is None
                   for name in imports) \
                    or len({name.casefold() for name in imports}) != len(imports):
                raise ValueError("imports")
            is_pe = path == "python.exe" or path.lower().endswith((".pyd", ".dll"))
            if not is_pe and imports:
                raise ValueError("imports")
            if path == "python.exe" and list(imports) != python["imports"]:
                raise ValueError("imports")
            if path == "cryptography/hazmat/bindings/_rust.pyd" \
                    and imports != ACCEPTED_CRYPTOGRAPHY_IMPORTS:
                raise ValueError("imports")
            if path == "_cffi_backend.cp314-win_amd64.pyd" \
                    and imports != ACCEPTED_CFFI_IMPORTS:
                raise ValueError("imports")
            if is_pe and any(name.casefold() not in allowed_imports for name in imports):
                raise ValueError("imports")
            identities.append((path, *identity[:2], specifications[path]))
        if len({(item[1], item[2]) for item in identities}) != len(identities):
            raise ValueError("identity")
        for package in parsed_packages:
            parse_record(observed_raw[package["recordPath"]], package)
        after_inventory = call(sealed, "inventory", root)
        after_root = call(sealed, "root_identity", root)
        if after_root != before_root or after_inventory != inventory:
            raise ValueError("root")
        manifest_raw = canonical(manifest)
        inventory_raw = canonical(identities)
        values = {
            "hostClosureObservationOnly": True,
            "rootIdentityDigest": sha256(canonical(before_root)).hexdigest(),
            "inventoryDigest": sha256(inventory_raw).hexdigest(),
            "manifestDigest": sha256(manifest_raw).hexdigest(),
            "fileCount": len(expected_paths), "packageCount": len(parsed_packages),
        }
        values["observationDigest"] = sha256(
            b"oncam.checkout.windows-cryptography-host-closure.v1\0" + canonical(values)
        ).hexdigest()
        if tuple(values) != result_keys:
            raise ValueError("result")
        return mapping_proxy(values)

    def invoke(operation):
        primary = None
        result = None
        try:
            authority()
            result = operation()
        except BaseException as error:
            primary = error
        try:
            authority()
        except BaseException as error:
            if primary is None:
                primary = error
        if primary is not None:
            if not isinstance(primary, Exception):
                raise primary
            if type(primary) is refusal and str(primary) == "host_closure":
                raise primary
            raise refusal("host_closure") from None
        return result

    def seal(adapter):
        def operation():
            if len(capabilities) >= maximum_capabilities:
                raise ValueError("capability")
            cls = type(adapter)
            if type(cls) is not type:
                raise ValueError("adapter")
            methods = []
            for name in adapter_methods:
                function = cls.__dict__.get(name)
                if type(function) is not function_type:
                    raise ValueError("adapter")
                methods.append((name, function, method_state(function)))
            capability = capability_type()
            capabilities[capability] = (adapter, cls, cls.__mro__, tuple(methods))
            return capability
        return invoke(operation)

    def public_observe(capability, root, manifest):
        if type(capability) is not capability_type \
                or capability_type.__dict__.get("__hash__") is not token_hash \
                or capability_type.__dict__.get("__eq__") is not token_eq:
            raise refusal("host_closure")
        sealed = capabilities.pop(capability, None)
        if sealed is None:
            raise refusal("host_closure")
        return invoke(lambda: inspect(sealed, root, manifest))

    return seal, public_observe


seal_adapter, observe = _make_api()
