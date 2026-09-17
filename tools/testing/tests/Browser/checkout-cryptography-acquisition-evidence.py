"""Pure structural codec for supplied ADR-021 acquisition evidence.

It does not inspect a filesystem, import or install a package, contact a
network, compute file hashes, or prove acquisition, trust, completeness,
freshness, replay safety, admission, or runtime efficacy.  Composition must pin
the exported callables and authenticate and bind the exact canonical bytes.
"""

from __future__ import annotations

import datetime
import hashlib
import json
import re
from types import MappingProxyType


MAX_EVIDENCE_BYTES = 8 * 1024 * 1024
MAX_ENTRIES = 8192
MAX_PATH_BYTES = 4096
MAX_PATH_DEPTH = 64
MAX_COMPONENT_BYTES = 255
MAX_VERSION_BYTES = 128
ROLE = "cryptography-acquisition-evidence"
RUNTIME_CATEGORIES = (
    "bundled-openssl", "config", "dll", "loader-policy",
    "native-extension", "package-root", "provider-module", "resource",
    "shared-library",
)
REQUIRED_CATEGORIES = frozenset({
    "bundled-openssl", "dll", "loader-policy", "native-extension",
})
ID_PATTERN = r"[a-z0-9](?:[a-z0-9._-]{0,127})"


class CryptographyAcquisitionEvidenceRefused(Exception):
    """Fixed refusal without path, package, host, or parser details."""


__all__ = (
    "CryptographyAcquisitionEvidenceRefused", "canonical_evidence", "decode",
)


def _make_codec():
    refusal = CryptographyAcquisitionEvidenceRefused
    mapping_proxy_type = MappingProxyType
    maximum_evidence = MAX_EVIDENCE_BYTES
    maximum_entries = MAX_ENTRIES
    maximum_path = MAX_PATH_BYTES
    maximum_depth = MAX_PATH_DEPTH
    maximum_component = MAX_COMPONENT_BYTES
    maximum_version = MAX_VERSION_BYTES
    role = ROLE
    runtime_categories = RUNTIME_CATEGORIES
    required_categories = REQUIRED_CATEGORIES
    identifier_pattern = ID_PATTERN
    public_surface = __all__
    top_keys = frozenset({
        "artifactId", "closureComplete", "cryptographyVersion", "expiresAt",
        "generation", "issuedAt", "issuerId", "python", "record", "replayId",
        "role", "runtime", "version", "wheel",
    })
    python_keys = frozenset({
        "abiTag", "architecture", "digest", "fileId", "implementation",
        "interpreterTag", "path", "platformTag", "reparsePoint", "version",
        "volumeSerial",
    })
    wheel_keys = frozenset({
        "abiTag", "digest", "filename", "licenseDigest", "platformTag",
        "pythonTag", "version",
    })
    record_keys = frozenset({"digest", "path", "size"})
    runtime_keys = frozenset({
        "category", "digest", "fileId", "path", "reparsePoint", "version",
        "volumeSerial",
    })
    reserved_names = frozenset({
        "con", "prn", "aux", "nul", "conin$", "conout$",
        *(f"com{index}" for index in range(1, 10)),
        *(f"lpt{index}" for index in range(1, 10)),
    })

    datetime_module = datetime
    datetime_type = datetime.datetime
    timedelta_type = datetime.timedelta
    timezone_utc = datetime.timezone.utc
    json_module = json
    hashlib_module = hashlib
    re_module = re
    json_dumps = json.dumps
    json_loads = json.loads
    sha256 = hashlib.sha256
    re_fullmatch = re.fullmatch
    module_globals = globals()

    def freeze_metadata(value):
        if value is None:
            return ("none",)
        if type(value) is bool:
            return ("bool", value)
        if type(value) is int:
            return ("int", value)
        if type(value) is str:
            return ("str", value)
        if type(value) is tuple:
            return ("tuple", tuple(freeze_metadata(item) for item in value))
        raise ValueError("metadata")

    def callable_state(function):
        defaults = getattr(function, "__defaults__", None)
        kwdefaults = getattr(function, "__kwdefaults__", None)
        kwitems = None if kwdefaults is None else tuple(
            (key, freeze_metadata(value)) for key, value in kwdefaults.items()
        )
        return (
            function, type(function), getattr(function, "__code__", None),
            defaults, freeze_metadata(defaults), kwdefaults, kwitems,
            getattr(function, "__closure__", None),
            getattr(function, "__globals__", None),
        )

    dependencies = (
        (json_module, "dumps", callable_state(json_dumps)),
        (json_module, "loads", callable_state(json_loads)),
        (hashlib_module, "sha256", callable_state(sha256)),
        (re_module, "fullmatch", callable_state(re_fullmatch)),
    )
    global_pins = (
        ("MAX_EVIDENCE_BYTES", maximum_evidence), ("MAX_ENTRIES", maximum_entries),
        ("MAX_PATH_BYTES", maximum_path), ("MAX_PATH_DEPTH", maximum_depth),
        ("MAX_COMPONENT_BYTES", maximum_component),
        ("MAX_VERSION_BYTES", maximum_version), ("ROLE", role),
        ("RUNTIME_CATEGORIES", runtime_categories),
        ("REQUIRED_CATEGORIES", required_categories),
        ("ID_PATTERN", identifier_pattern),
        ("CryptographyAcquisitionEvidenceRefused", refusal),
        ("MappingProxyType", mapping_proxy_type), ("__all__", public_surface),
        ("datetime", datetime_module), ("json", json_module),
        ("hashlib", hashlib_module), ("re", re_module),
    )

    def guard():
        try:
            for name, expected in global_pins:
                if module_globals.get(name) is not expected:
                    raise ValueError("authority")
            if datetime_module.datetime is not datetime_type \
                    or datetime_module.timedelta is not timedelta_type \
                    or datetime_module.timezone.utc is not timezone_utc:
                raise ValueError("dependency")
            for module, name, expected in dependencies:
                current = getattr(module, name, None)
                if current is not expected[0] or type(current) is not expected[1]:
                    raise ValueError("dependency")
                defaults = getattr(current, "__defaults__", None)
                kwdefaults = getattr(current, "__kwdefaults__", None)
                kwitems = None if kwdefaults is None else tuple(
                    (key, freeze_metadata(value)) for key, value in kwdefaults.items()
                )
                if getattr(current, "__code__", None) is not expected[2] \
                        or defaults is not expected[3] \
                        or freeze_metadata(defaults) != expected[4] \
                        or kwdefaults is not expected[5] or kwitems != expected[6] \
                        or getattr(current, "__closure__", None) is not expected[7] \
                        or getattr(current, "__globals__", None) is not expected[8]:
                    raise ValueError("dependency")
        except refusal:
            raise
        except Exception:
            raise refusal("cryptography_acquisition_evidence") from None

    def exact_dict(value, keys):
        return type(value) is dict and all(type(key) is str for key in value) \
            and set(value) == keys

    def strict_object(pairs):
        guard()
        result = {}
        for key, value in pairs:
            if type(key) is not str or key in result:
                raise ValueError("duplicate")
            result[key] = value
        guard()
        return result

    def json_bytes(value):
        return (json_dumps(value, sort_keys=True, separators=(",", ":"),
                           ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")

    def identifier(value):
        return type(value) is str and re_fullmatch(identifier_pattern, value) is not None

    def positive_int63(value):
        return type(value) is int and 1 <= value < (1 << 63)

    def uint_size(value):
        return type(value) is int and 0 <= value < (1 << 63)

    def decimal_uint128(value):
        return type(value) is str and re_fullmatch(r"[1-9][0-9]{0,38}", value) is not None \
            and int(value) < (1 << 128)

    def digest(value):
        return type(value) is str and re_fullmatch(r"[a-f0-9]{64}", value) is not None

    def version_text(value):
        return type(value) is str and 1 <= len(value) <= maximum_version \
            and all(32 <= ord(character) <= 126 for character in value)

    def numeric_version(value):
        return type(value) is str \
            and 1 <= len(value) <= maximum_version \
            and value.isascii() \
            and re_fullmatch(r"[0-9]+(?:\.[0-9]+){2}", value) is not None

    def timestamp(value):
        if type(value) is not str or re_fullmatch(
            r"[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z", value,
        ) is None:
            raise ValueError("timestamp")
        return datetime_type(int(value[:4]), int(value[5:7]), int(value[8:10]),
                             int(value[11:13]), int(value[14:16]), int(value[17:19]),
                             tzinfo=timezone_utc)

    def canonical_path(value):
        if type(value) is not str or not 4 <= len(value) <= maximum_path \
                or not "A" <= value[0] <= "Z" or value[1:3] != ":/" \
                or value.endswith("/") or "\\" in value or ":" in value[2:] \
                or any(ord(character) < 32 or ord(character) > 126 for character in value):
            return False
        parts = value[3:].split("/")
        return validate_parts(parts)

    def relative_path(value):
        if type(value) is not str or not value or len(value) > maximum_path \
                or value.startswith("/") or "\\" in value or ":" in value \
                or any(ord(character) < 32 or ord(character) > 126 for character in value):
            return False
        return validate_parts(value.split("/"))

    def validate_parts(parts):
        if not parts or len(parts) > maximum_depth:
            return False
        for part in parts:
            if not part or part in (".", "..") or len(part) > maximum_component \
                    or part.endswith((".", " ")) \
                    or any(character in '<>"|?*' for character in part):
                return False
            if part.split(".", 1)[0].rstrip(" .").lower() in reserved_names:
                return False
        return True

    def validate(value):
        if not exact_dict(value, top_keys) or type(value["version"]) is not int \
                or value["version"] != 1 or type(value["role"]) is not str \
                or value["role"] != role or not identifier(value["artifactId"]) \
                or not identifier(value["issuerId"]) or not identifier(value["replayId"]) \
                or not positive_int63(value["generation"]) \
                or value["closureComplete"] is not True \
                or not numeric_version(value["cryptographyVersion"]):
            raise ValueError("evidence")
        issued = timestamp(value["issuedAt"]); expires = timestamp(value["expiresAt"])
        if not timedelta_type(0) < expires - issued <= timedelta_type(days=7):
            raise ValueError("lifetime")
        python = value["python"]
        if not exact_dict(python, python_keys) or not canonical_path(python["path"]) \
                or not decimal_uint128(python["volumeSerial"]) \
                or not decimal_uint128(python["fileId"]) or not digest(python["digest"]) \
                or not numeric_version(python["version"]) \
                or type(python["implementation"]) is not str \
                or python["implementation"] != "cpython" \
                or type(python["interpreterTag"]) is not str \
                or re_fullmatch(r"cp[0-9]{3}", python["interpreterTag"]) is None \
                or type(python["abiTag"]) is not str or python["abiTag"] != "abi3" \
                or type(python["platformTag"]) is not str \
                or python["platformTag"] != "win_amd64" \
                or type(python["architecture"]) is not str \
                or python["architecture"] != "amd64" \
                or python["reparsePoint"] is not False:
            raise ValueError("python")
        wheel = value["wheel"]
        crypto_version = value["cryptographyVersion"]
        if not exact_dict(wheel, wheel_keys) or type(wheel["version"]) is not str \
                or wheel["version"] != crypto_version \
                or re_fullmatch(r"cp[0-9]{3}", wheel["pythonTag"] \
                                if type(wheel["pythonTag"]) is str else "") is None \
                or type(wheel["abiTag"]) is not str or wheel["abiTag"] != "abi3" \
                or type(wheel["platformTag"]) is not str \
                or wheel["platformTag"] != "win_amd64" \
                or not digest(wheel["digest"]) or not digest(wheel["licenseDigest"]):
            raise ValueError("wheel")
        python_parts = tuple(int(part) for part in python["version"].split("."))
        tag_minor = int(wheel["pythonTag"][3:])
        expected_interpreter_tag = f"cp{python_parts[0]}{python_parts[1]:02d}"
        if python_parts[0] != 3 or python["interpreterTag"] != expected_interpreter_tag \
                or python["abiTag"] != wheel["abiTag"] \
                or python["platformTag"] != wheel["platformTag"] \
                or python_parts[1] < tag_minor:
            raise ValueError("wheel_python")
        expected_filename = "cryptography-{}-{}-{}-{}.whl".format(
            crypto_version, wheel["pythonTag"], wheel["abiTag"], wheel["platformTag"]
        )
        if type(wheel["filename"]) is not str or wheel["filename"] != expected_filename:
            raise ValueError("wheel")
        records = value["record"]
        runtime = value["runtime"]
        if type(records) is not list or not records or type(runtime) is not list \
                or not runtime or len(records) + len(runtime) > maximum_entries:
            raise ValueError("inventory")
        frozen_records = []
        paths = set()
        previous = None
        for item in records:
            if not exact_dict(item, record_keys) or not relative_path(item["path"]) \
                    or not digest(item["digest"]) or not uint_size(item["size"]):
                raise ValueError("record")
            key = item["path"].lower()
            if previous is not None and key <= previous:
                raise ValueError("record_order")
            previous = key; paths.add(key)
            frozen_records.append((item["path"], item["digest"], item["size"]))
        required_record = f"cryptography-{crypto_version}.dist-info/RECORD".lower()
        if required_record not in paths:
            raise ValueError("record")
        identities = {(python["volumeSerial"], python["fileId"])}
        absolute_paths = {python["path"].lower()}
        categories = set()
        frozen_runtime = []
        previous = None
        for item in runtime:
            if not exact_dict(item, runtime_keys) \
                    or type(item["category"]) is not str \
                    or item["category"] not in runtime_categories \
                    or not canonical_path(item["path"]) \
                    or not decimal_uint128(item["volumeSerial"]) \
                    or not decimal_uint128(item["fileId"]) or not digest(item["digest"]) \
                    or not version_text(item["version"]) or item["reparsePoint"] is not False:
                raise ValueError("runtime")
            key = item["path"].lower(); identity = (item["volumeSerial"], item["fileId"])
            if previous is not None and key <= previous:
                raise ValueError("runtime_order")
            if key in absolute_paths or identity in identities:
                raise ValueError("collision")
            previous = key; absolute_paths.add(key); identities.add(identity)
            categories.add(item["category"])
            frozen_runtime.append((item["category"], item["path"], item["volumeSerial"],
                                   item["fileId"], item["digest"], item["version"]))
        if not required_categories <= categories:
            raise ValueError("closure")
        return tuple(frozen_records), tuple(frozen_runtime)

    def canonical_impl(value):
        validate(value); raw = json_bytes(value)
        if len(raw) > maximum_evidence:
            raise ValueError("size")
        return raw

    def decode_impl(raw):
        if type(raw) is not bytes or not 1 <= len(raw) <= maximum_evidence \
                or not raw.endswith(b"\n") or raw.endswith(b"\n\n"):
            raise ValueError("raw")
        value = json_loads(raw.decode("ascii"), object_pairs_hook=strict_object,
                           parse_constant=lambda _value: (_ for _ in ()).throw(ValueError("constant")))
        records, runtime = validate(value)
        if json_bytes(value) != raw:
            raise ValueError("canonical")
        return mapping_proxy_type({
            "structuralOnly": True, "role": role, "artifactId": value["artifactId"],
            "issuerId": value["issuerId"], "generation": value["generation"],
            "issuedAt": value["issuedAt"], "expiresAt": value["expiresAt"],
            "replayId": value["replayId"], "cryptographyVersion": value["cryptographyVersion"],
            "closureComplete": True, "python": tuple(value["python"][key] for key in
                ("path", "volumeSerial", "fileId", "digest", "version",
                 "implementation", "interpreterTag", "abiTag", "platformTag",
                 "architecture")),
            "wheel": tuple(value["wheel"][key] for key in
                ("filename", "version", "pythonTag", "abiTag", "platformTag", "digest", "licenseDigest")),
            "recordCount": len(records), "runtimeCount": len(runtime),
            "record": records, "runtime": runtime, "artifactDigest": sha256(raw).hexdigest(),
        })

    def invoke(operation, *args):
        try:
            guard(); result = operation(*args); guard(); return result
        except BaseException as primary:
            try:
                guard()
            except BaseException:
                pass
            if isinstance(primary, (KeyboardInterrupt, SystemExit)):
                raise
            raise refusal("cryptography_acquisition_evidence") from None

    def canonical_evidence(value):
        return invoke(canonical_impl, value)

    def decode(raw):
        return invoke(decode_impl, raw)

    return canonical_evidence, decode


canonical_evidence, decode = _make_codec()
del _make_codec
