"""Pure structural codec for ADR-021 tool/runtime closure artifacts.

The codec validates supplied canonical bytes and derives an artifact digest.  It
does not inspect a filesystem, process, executable, loader, package, or browser,
and ``closureComplete`` remains supplied structural data; it is not proof that
the transitive runtime closure is actually complete.  It does not verify a
signature, trust, freshness, revocation, replay, admission, or runtime behavior.
A composition boundary must pin the imported callables and authenticate and
bind the exact bytes separately.
"""

from __future__ import annotations

import datetime
import hashlib
import json
import re
from types import MappingProxyType


MAX_ARTIFACT_BYTES = 8 * 1024 * 1024
MAX_ENTRIES = 8192
MAX_PATH_BYTES = 4096
MAX_PATH_DEPTH = 64
MAX_COMPONENT_BYTES = 255
MAX_VERSION_BYTES = 128
ROLE = "tool-runtime-closure"
TOOL_ROLES = (
    "php",
    "python",
    "node",
    "powershell",
    "playwright-cli",
    "browser",
)
RESOURCE_KINDS = (
    "dll",
    "shared-library",
    "provider-module",
    "config",
    "package-root",
    "loader-policy",
    "resource",
)
ID_PATTERN = r"[a-z0-9](?:[a-z0-9._-]{0,127})"


class ToolRuntimeClosureArtifactRefused(Exception):
    """Fixed refusal without host, tool, resource, path, or parser details."""


__all__ = (
    "ToolRuntimeClosureArtifactRefused",
    "canonical_artifact",
    "decode",
)


def _make_codec():
    refusal = ToolRuntimeClosureArtifactRefused
    mapping_proxy_type = MappingProxyType
    maximum_artifact = MAX_ARTIFACT_BYTES
    maximum_entries = MAX_ENTRIES
    maximum_path = MAX_PATH_BYTES
    maximum_depth = MAX_PATH_DEPTH
    maximum_component = MAX_COMPONENT_BYTES
    maximum_version = MAX_VERSION_BYTES
    role = ROLE
    tool_roles = TOOL_ROLES
    resource_kinds = RESOURCE_KINDS
    identifier_pattern = ID_PATTERN
    public_surface = __all__
    top_keys = frozenset({
        "artifactId",
        "closureComplete",
        "expiresAt",
        "generation",
        "hostIdentityDigest",
        "issuedAt",
        "issuerId",
        "replayId",
        "resources",
        "role",
        "tools",
        "version",
    })
    tool_keys = frozenset({
        "digest", "fileId", "path", "role", "version", "volumeSerial",
    })
    resource_keys = frozenset({
        "digest", "fileId", "kind", "path", "version", "volumeSerial",
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
        ("MAX_ENTRIES", maximum_entries),
        ("MAX_PATH_BYTES", maximum_path),
        ("MAX_PATH_DEPTH", maximum_depth),
        ("MAX_COMPONENT_BYTES", maximum_component),
        ("MAX_VERSION_BYTES", maximum_version),
        ("ROLE", role),
        ("TOOL_ROLES", tool_roles),
        ("RESOURCE_KINDS", resource_kinds),
        ("ID_PATTERN", identifier_pattern),
        ("ToolRuntimeClosureArtifactRefused", refusal),
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
            raise refusal("tool_runtime_closure_artifact") from None

    def exact_keys(value, expected):
        return type(value) is dict \
            and all(type(key) is str for key in value) \
            and set(value) == expected

    def strict_object(pairs):
        guard()
        value = {}
        for key, item in pairs:
            if key in value:
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

    def identifier(value):
        return type(value) is str \
            and re_fullmatch(identifier_pattern, value) is not None

    def positive_int63(value):
        return type(value) is int and 1 <= value < (1 << 63)

    def decimal_uint128(value):
        if type(value) is not str or re_fullmatch(r"(?:0|[1-9][0-9]*)", value) is None:
            return False
        number = int(value)
        return 1 <= number < (1 << 128)

    def lowercase_sha256(value):
        return type(value) is str and len(value) == 64 \
            and re_fullmatch(r"[a-f0-9]{64}", value) is not None

    def version_text(value):
        return type(value) is str and 1 <= len(value.encode("ascii")) <= maximum_version \
            and all(32 <= ord(character) <= 126 for character in value)

    def timestamp(value):
        if type(value) is not str \
                or re_fullmatch(
                    r"[0-9]{4}-[0-9]{2}-[0-9]{2}T"
                    r"[0-9]{2}:[0-9]{2}:[0-9]{2}Z",
                    value,
                ) is None:
            raise ValueError("timestamp")
        return datetime_type(
            int(value[0:4]),
            int(value[5:7]),
            int(value[8:10]),
            int(value[11:13]),
            int(value[14:16]),
            int(value[17:19]),
            tzinfo=timezone_utc,
        )

    def canonical_path(value):
        if type(value) is not str or len(value) < 4 \
                or len(value.encode("utf-8")) > maximum_path \
                or not "A" <= value[0] <= "Z" or value[1:3] != ":/" \
                or value.endswith("/") or "\\" in value \
                or ":" in value[2:] \
                or any(ord(character) < 32 or ord(character) > 126
                       for character in value):
            return False
        parts = value[3:].split("/")
        if not parts or len(parts) > maximum_depth:
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

    def freeze_entry(entry, discriminator, allowed):
        expected = tool_keys if discriminator == "role" else resource_keys
        if not exact_keys(entry, expected) \
                or type(entry[discriminator]) is not str \
                or entry[discriminator] not in allowed \
                or not canonical_path(entry["path"]) \
                or not decimal_uint128(entry["volumeSerial"]) \
                or not decimal_uint128(entry["fileId"]) \
                or not lowercase_sha256(entry["digest"]) \
                or not version_text(entry["version"]):
            raise ValueError("entry")
        return (
            entry[discriminator],
            entry["path"],
            entry["volumeSerial"],
            entry["fileId"],
            entry["digest"],
            entry["version"],
        )

    def validate(value):
        if not exact_keys(value, top_keys) \
                or type(value["version"]) is not int or value["version"] != 1 \
                or type(value["role"]) is not str or value["role"] != role \
                or not identifier(value["artifactId"]) \
                or not identifier(value["issuerId"]) \
                or not identifier(value["replayId"]) \
                or not positive_int63(value["generation"]) \
                or not lowercase_sha256(value["hostIdentityDigest"]) \
                or value["closureComplete"] is not True:
            raise ValueError("artifact")

        issued_at = timestamp(value["issuedAt"])
        expires_at = timestamp(value["expiresAt"])
        if not timedelta_type(0) < expires_at - issued_at \
                <= timedelta_type(days=7):
            raise ValueError("lifetime")

        tools = value["tools"]
        resources = value["resources"]
        if type(tools) is not list or len(tools) != len(tool_roles) \
                or type(resources) is not list or not resources \
                or len(tools) + len(resources) > maximum_entries:
            raise ValueError("closure")

        frozen_tools = []
        frozen_resources = []
        paths = set()
        identities = set()
        for index, entry in enumerate(tools):
            frozen = freeze_entry(entry, "role", tool_roles)
            if frozen[0] != tool_roles[index]:
                raise ValueError("tools")
            path_key = frozen[1].lower()
            identity = (frozen[2], frozen[3])
            if path_key in paths or identity in identities:
                raise ValueError("collision")
            paths.add(path_key)
            identities.add(identity)
            frozen_tools.append(frozen)

        previous = None
        for entry in resources:
            frozen = freeze_entry(entry, "kind", resource_kinds)
            path_key = frozen[1].lower()
            identity = (frozen[2], frozen[3])
            if previous is not None and path_key <= previous:
                raise ValueError("resource_order")
            if path_key in paths or identity in identities:
                raise ValueError("collision")
            previous = path_key
            paths.add(path_key)
            identities.add(identity)
            frozen_resources.append(frozen)
        return tuple(frozen_tools), tuple(frozen_resources)

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
        frozen_tools, frozen_resources = validate(value)
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
            "hostIdentityDigest": value["hostIdentityDigest"],
            "closureComplete": True,
            "toolCount": len(frozen_tools),
            "resourceCount": len(frozen_resources),
            "tools": frozen_tools,
            "resources": frozen_resources,
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
            raise refusal("tool_runtime_closure_artifact") from None

    def canonical_artifact(value):
        return invoke(canonical_impl, value)

    def decode(raw):
        return invoke(decode_impl, raw)

    return canonical_artifact, decode


canonical_artifact, decode = _make_codec()
del _make_codec
