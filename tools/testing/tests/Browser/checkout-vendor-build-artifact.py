"""Pure structural codec for the ADR-021 vendor-build artifact.

This module validates supplied canonical bytes and derives an artifact digest.
It does not read a filesystem or Composer state, execute a build, authenticate
an issuer, verify reproducibility/signatures/trust, evaluate freshness,
revocation, or replay, or grant admission/runtime authority.  Supplied build
provenance does not prove who built the inventory.  Composition must pin the
exported callables and authenticate and bind the exact bytes separately.
"""

from __future__ import annotations

import datetime
import hashlib
import json
import re
from types import MappingProxyType


MAX_ARTIFACT_BYTES = 8 * 1024 * 1024
MAX_PACKAGES = 2048
MAX_VENDOR_FILES = 32768
MAX_EXTENSIONS = 64
MAX_PATH_BYTES = 1024
MAX_PATH_DEPTH = 64
MAX_COMPONENT_BYTES = 255
MAX_TEXT_BYTES = 128
ROLE = "vendor-build"
ID_PATTERN = r"[a-z0-9](?:[a-z0-9._-]{0,63})"
REPLAY_ID_PATTERN = r"[a-z0-9](?:[a-z0-9._-]{0,127})"
PACKAGE_PATTERN = (
    r"[a-z0-9](?:[a-z0-9._-]{0,62}[a-z0-9])?/"
    r"[a-z0-9](?:[a-z0-9._-]{0,62}[a-z0-9])?"
)


class VendorBuildArtifactRefused(Exception):
    """Fixed refusal without package, path, provenance, or parser details."""


__all__ = (
    "VendorBuildArtifactRefused",
    "canonical_artifact",
    "decode",
)


def _make_codec():
    refusal = VendorBuildArtifactRefused
    mapping_proxy_type = MappingProxyType
    maximum_artifact = MAX_ARTIFACT_BYTES
    maximum_packages = MAX_PACKAGES
    maximum_files = MAX_VENDOR_FILES
    maximum_extensions = MAX_EXTENSIONS
    maximum_path = MAX_PATH_BYTES
    maximum_depth = MAX_PATH_DEPTH
    maximum_component = MAX_COMPONENT_BYTES
    maximum_text = MAX_TEXT_BYTES
    role = ROLE
    identifier_pattern = ID_PATTERN
    replay_pattern = REPLAY_ID_PATTERN
    package_pattern = PACKAGE_PATTERN
    public_surface = __all__
    top_keys = frozenset({
        "artifactId",
        "buildProvenance",
        "composerLockDigest",
        "expiresAt",
        "generation",
        "issuedAt",
        "issuerId",
        "packages",
        "releaseSourceArtifactDigest",
        "replayId",
        "role",
        "vendorFiles",
        "version",
    })
    provenance_keys = frozenset({
        "buildId", "builderDigest", "policyDigest", "toolchainDigest",
    })
    package_keys = frozenset({"name", "packageDigest", "runtime", "version"})
    runtime_keys = frozenset({"phpConstraint", "requiredExtensions"})
    file_keys = frozenset({"digest", "kind", "path", "reparse"})
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

    def mapping_snapshot(value):
        if value is None:
            return None
        if type(value) is not dict or any(type(key) is not str for key in value):
            raise ValueError("metadata")
        return tuple((key, freeze_metadata(item)) for key, item in value.items())

    def mapping_matches(value, expected):
        if expected is None:
            return value is None
        if type(value) is not dict or len(value) != len(expected):
            return False
        for key, item in expected:
            if key not in value:
                return False
            try:
                if freeze_metadata(value[key]) != item:
                    return False
            except Exception:
                return False
        return True

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
            mapping_snapshot(kwdefaults),
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
        ("MAX_ARTIFACT_BYTES", maximum_artifact),
        ("MAX_PACKAGES", maximum_packages),
        ("MAX_VENDOR_FILES", maximum_files),
        ("MAX_EXTENSIONS", maximum_extensions),
        ("MAX_PATH_BYTES", maximum_path),
        ("MAX_PATH_DEPTH", maximum_depth),
        ("MAX_COMPONENT_BYTES", maximum_component),
        ("MAX_TEXT_BYTES", maximum_text),
        ("ROLE", role),
        ("ID_PATTERN", identifier_pattern),
        ("REPLAY_ID_PATTERN", replay_pattern),
        ("PACKAGE_PATTERN", package_pattern),
        ("VendorBuildArtifactRefused", refusal),
        ("MappingProxyType", mapping_proxy_type),
        ("__all__", public_surface),
        ("datetime", datetime_module),
        ("json", json_module),
        ("hashlib", hashlib_module),
        ("re", re_module),
    )

    def guard():
        try:
            for name, expected in global_pins:
                if module_globals.get(name) is not expected:
                    raise ValueError("authority")
            if datetime_module.datetime is not datetime_type \
                    or datetime_module.timedelta is not timedelta_type \
                    or datetime_module.timezone.utc is not timezone_utc:
                raise ValueError("datetime")
            for module, name, expected in dependencies:
                current = getattr(module, name, None)
                if current is not expected[0] or type(current) is not expected[1]:
                    raise ValueError("dependency")
                defaults = getattr(current, "__defaults__", None)
                kwdefaults = getattr(current, "__kwdefaults__", None)
                if getattr(current, "__code__", None) is not expected[2] \
                        or defaults is not expected[3] \
                        or freeze_metadata(defaults) != expected[4] \
                        or kwdefaults is not expected[5] \
                        or not mapping_matches(kwdefaults, expected[6]) \
                        or getattr(current, "__closure__", None) is not expected[7] \
                        or getattr(current, "__globals__", None) is not expected[8]:
                    raise ValueError("dependency")
        except refusal:
            raise
        except Exception:
            raise refusal("vendor_build_artifact") from None

    def exact_dict(value, keys):
        return type(value) is dict \
            and all(type(key) is str for key in value) \
            and set(value) == keys

    def strict_object(pairs):
        guard()
        value = {}
        for key, item in pairs:
            if type(key) is not str or key in value:
                raise ValueError("duplicate")
            value[key] = item
        guard()
        return value

    def json_bytes(value):
        return (
            json_dumps(
                value,
                sort_keys=True,
                separators=(",", ":"),
                ensure_ascii=True,
                allow_nan=False,
            )
            + "\n"
        ).encode("ascii")

    def identifier(value, pattern):
        return type(value) is str and re_fullmatch(pattern, value) is not None

    def positive_int63(value):
        return type(value) is int and 1 <= value < (1 << 63)

    def lowercase_sha256(value):
        return type(value) is str and len(value) == 64 \
            and re_fullmatch(r"[a-f0-9]{64}", value) is not None

    def bounded_text(value):
        if type(value) is not str:
            return False
        try:
            raw = value.encode("ascii")
        except UnicodeEncodeError:
            return False
        return 1 <= len(raw) <= maximum_text \
            and all(32 <= byte <= 126 for byte in raw)

    def timestamp(value):
        if type(value) is not str \
                or re_fullmatch(
                    r"[0-9]{4}-[0-9]{2}-[0-9]{2}T"
                    r"[0-9]{2}:[0-9]{2}:[0-9]{2}Z",
                    value,
                ) is None:
            raise ValueError("timestamp")
        return datetime_type(
            int(value[0:4]), int(value[5:7]), int(value[8:10]),
            int(value[11:13]), int(value[14:16]), int(value[17:19]),
            tzinfo=timezone_utc,
        )

    def vendor_path(value):
        if type(value) is not str or not value.startswith("vendor/") \
                or len(value.encode("utf-8")) > maximum_path \
                or value.endswith("/") or "\\" in value or ":" in value \
                or any(ord(character) < 32 or ord(character) > 126
                       for character in value):
            return False
        parts = value.split("/")
        if len(parts) < 2 or len(parts) > maximum_depth:
            return False
        for part in parts:
            if not part or part in (".", "..") \
                    or len(part.encode("ascii")) > maximum_component \
                    or part.endswith((".", " ")) \
                    or any(character in '<>"|?*' for character in part):
                return False
            base = part.split(".", 1)[0].rstrip(" .").lower()
            if base in reserved_names:
                return False
        return True

    def freeze_provenance(value):
        if not exact_dict(value, provenance_keys) \
                or not identifier(value["buildId"], replay_pattern) \
                or not lowercase_sha256(value["builderDigest"]) \
                or not lowercase_sha256(value["policyDigest"]) \
                or not lowercase_sha256(value["toolchainDigest"]):
            raise ValueError("provenance")
        return (
            value["buildId"], value["builderDigest"],
            value["toolchainDigest"], value["policyDigest"],
        )

    def freeze_runtime(value):
        if not exact_dict(value, runtime_keys) \
                or not bounded_text(value["phpConstraint"]):
            raise ValueError("runtime")
        extensions = value["requiredExtensions"]
        if type(extensions) is not list or len(extensions) > maximum_extensions:
            raise ValueError("extensions")
        frozen = []
        previous = None
        for extension in extensions:
            if not identifier(extension, identifier_pattern) \
                    or (previous is not None and extension <= previous):
                raise ValueError("extensions")
            frozen.append(extension)
            previous = extension
        return value["phpConstraint"], tuple(frozen)

    def freeze_packages(value):
        if type(value) is not list or not 1 <= len(value) <= maximum_packages:
            raise ValueError("packages")
        result = []
        previous = None
        for entry in value:
            if not exact_dict(entry, package_keys) \
                    or not identifier(entry["name"], package_pattern) \
                    or (previous is not None and entry["name"] <= previous) \
                    or not bounded_text(entry["version"]) \
                    or not lowercase_sha256(entry["packageDigest"]):
                raise ValueError("package")
            constraint, extensions = freeze_runtime(entry["runtime"])
            result.append((
                entry["name"], entry["version"], entry["packageDigest"],
                constraint, extensions,
            ))
            previous = entry["name"]
        return tuple(result)

    def freeze_files(value, package_names):
        if type(value) is not list or not 1 <= len(value) <= maximum_files:
            raise ValueError("files")
        result = []
        previous = None
        package_set = frozenset(package_names)
        seen_package_files = set()
        for entry in value:
            if not exact_dict(entry, file_keys) \
                    or entry["kind"] != "file" \
                    or type(entry["kind"]) is not str \
                    or entry["reparse"] is not False \
                    or not vendor_path(entry["path"]) \
                    or not lowercase_sha256(entry["digest"]):
                raise ValueError("file")
            path_key = entry["path"].lower()
            if previous is not None and path_key <= previous:
                raise ValueError("files")
            previous = path_key
            parts = entry["path"].split("/")
            package_name = "/".join(parts[1:3]) if len(parts) >= 4 else None
            if package_name in package_set:
                seen_package_files.add(package_name)
            elif entry["path"] != "vendor/autoload.php" \
                    and not entry["path"].startswith("vendor/composer/"):
                raise ValueError("orphan")
            result.append((entry["path"], entry["digest"]))
        if seen_package_files != package_set:
            raise ValueError("package_files")
        return tuple(result)

    def validate(value):
        if not exact_dict(value, top_keys) \
                or type(value["version"]) is not int or value["version"] != 1 \
                or type(value["role"]) is not str or value["role"] != role \
                or not identifier(value["artifactId"], identifier_pattern) \
                or not identifier(value["issuerId"], identifier_pattern) \
                or not identifier(value["replayId"], replay_pattern) \
                or not positive_int63(value["generation"]) \
                or not lowercase_sha256(value["releaseSourceArtifactDigest"]) \
                or not lowercase_sha256(value["composerLockDigest"]):
            raise ValueError("artifact")
        issued_at = timestamp(value["issuedAt"])
        expires_at = timestamp(value["expiresAt"])
        if not timedelta_type(0) < expires_at - issued_at \
                <= timedelta_type(days=7):
            raise ValueError("lifetime")
        provenance = freeze_provenance(value["buildProvenance"])
        packages = freeze_packages(value["packages"])
        files = freeze_files(value["vendorFiles"], tuple(item[0] for item in packages))
        return provenance, packages, files

    def canonical_impl(value):
        validate(value)
        raw = json_bytes(value)
        if len(raw) > maximum_artifact:
            raise ValueError("size")
        return raw

    def decode_impl(raw):
        if type(raw) is not bytes or not 1 <= len(raw) <= maximum_artifact \
                or not raw.endswith(b"\n") or raw.endswith(b"\n\n"):
            raise ValueError("raw")
        value = json_loads(
            raw.decode("ascii"),
            object_pairs_hook=strict_object,
            parse_constant=lambda _value: (_ for _ in ()).throw(
                ValueError("constant")
            ),
        )
        provenance, packages, files = validate(value)
        if json_bytes(value) != raw:
            raise ValueError("canonical")
        return mapping_proxy_type({
            "structuralOnly": True,
            "role": role,
            "artifactId": value["artifactId"],
            "issuerId": value["issuerId"],
            "generation": value["generation"],
            "issuedAt": value["issuedAt"],
            "expiresAt": value["expiresAt"],
            "replayId": value["replayId"],
            "releaseSourceArtifactDigest": value["releaseSourceArtifactDigest"],
            "composerLockDigest": value["composerLockDigest"],
            "buildProvenance": provenance,
            "packageCount": len(packages),
            "fileCount": len(files),
            "packages": packages,
            "vendorFiles": files,
            "artifactDigest": sha256(raw).hexdigest(),
        })

    def invoke(operation, *args):
        try:
            guard()
            result = operation(*args)
            guard()
            return result
        except BaseException as primary:
            try:
                guard()
            except BaseException:
                pass
            if isinstance(primary, (KeyboardInterrupt, SystemExit)):
                raise
            raise refusal("vendor_build_artifact") from None

    def canonical_artifact(value):
        return invoke(canonical_impl, value)

    def decode(raw):
        return invoke(decode_impl, raw)

    return canonical_artifact, decode


canonical_artifact, decode = _make_codec()
del _make_codec
