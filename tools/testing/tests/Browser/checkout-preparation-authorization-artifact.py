"""Pure structural codec for the ADR-021 preparation authorization artifact.

The result describes supplied one-shot intent only.  This module does not
authenticate signatures or trust, establish freshness/high-water state,
consume replay authority, touch a destination, apply an ACL, or grant final
composition/runtime admission.  Composition must pin the exported callables.
"""

from __future__ import annotations

import datetime
import hashlib
import json
import re
from types import MappingProxyType


MAX_ARTIFACT_BYTES = 128 * 1024
MAX_LIFETIME_SECONDS = 10 * 60
MAX_PATH_CHARS = 4096
MAX_PATH_DEPTH = 64
MAX_COMPONENT_BYTES = 255
MAX_IDENTITY = (1 << 128) - 1
ROLE = "preparation-authorization"
STATIC_ROLES = (
    "asset-review",
    "release-source",
    "runtime-configuration-policy",
    "tool-runtime-closure",
    "vendor-build",
)


class PreparationAuthorizationArtifactRefused(Exception):
    """Fixed refusal without artifact, path, or dependency details."""


__all__ = (
    "PreparationAuthorizationArtifactRefused",
    "canonical_artifact",
    "decode",
)


def _make_codec():
    refusal = PreparationAuthorizationArtifactRefused
    mapping_proxy_type = MappingProxyType
    maximum_artifact = MAX_ARTIFACT_BYTES
    maximum_lifetime = MAX_LIFETIME_SECONDS
    maximum_path = MAX_PATH_CHARS
    maximum_depth = MAX_PATH_DEPTH
    maximum_component = MAX_COMPONENT_BYTES
    maximum_identity = MAX_IDENTITY
    role = ROLE
    static_roles = STATIC_ROLES
    public_surface = __all__
    identifier_pattern = r"[a-z0-9](?:[a-z0-9._-]{0,127})"
    timestamp_pattern = (
        r"[0-9]{4}-[0-9]{2}-[0-9]{2}T"
        r"[0-9]{2}:[0-9]{2}:[0-9]{2}Z"
    )
    decimal_pattern = r"0|[1-9][0-9]{0,38}"
    dos_device_pattern = (
        r"(?:con|prn|aux|nul|conin\$|conout\$|com[1-9]|lpt[1-9])"
    )
    top_keys = frozenset({
        "artifactId", "destination", "expiresAt", "generation", "issuedAt",
        "issuerId", "replayId", "revocationSnapshots", "role",
        "staticArtifacts", "version",
    })
    static_keys = frozenset({
        "artifactDigest", "artifactId", "generation", "role"
    })
    snapshot_keys = frozenset({
        "authorityRole", "issuerId", "issuerKeyGeneration", "issuedAt",
        "nextUpdate", "revocationTrustGeneration", "snapshotDigest",
        "snapshotGeneration",
    })
    destination_keys = frozenset({
        "parentIdentity", "parentPath", "preparationPolicyDigest",
        "requestedGeneration", "runPath", "securityDescriptorDigest",
    })
    identity_keys = frozenset({"fileId", "volumeSerial"})
    domain = b"oncam.checkout.preparation-authorization-artifact.v1\0"

    json_module = json
    hashlib_module = hashlib
    re_module = re
    datetime_module = datetime
    json_dumps = json.dumps
    json_loads = json.loads
    sha256 = hashlib.sha256
    re_fullmatch = re.fullmatch
    datetime_class = datetime.datetime
    datetime_strptime_descriptor = datetime_class.__dict__["strptime"]
    datetime_strptime = datetime_class.strptime
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

    def mapping_matches(value, snapshot):
        if snapshot is None:
            return value is None
        if type(value) is not dict or len(value) != len(snapshot):
            return False
        for key, expected in snapshot:
            if key not in value:
                return False
            try:
                if freeze_metadata(value[key]) != expected:
                    return False
            except Exception:
                return False
        return True

    def callable_state(function):
        defaults = getattr(function, "__defaults__", None)
        kwdefaults = getattr(function, "__kwdefaults__", None)
        return (
            function, type(function), getattr(function, "__code__", None),
            defaults, freeze_metadata(defaults), kwdefaults,
            mapping_snapshot(kwdefaults), getattr(function, "__closure__", None),
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
        ("MAX_LIFETIME_SECONDS", maximum_lifetime),
        ("MAX_PATH_CHARS", maximum_path),
        ("MAX_PATH_DEPTH", maximum_depth),
        ("MAX_COMPONENT_BYTES", maximum_component),
        ("MAX_IDENTITY", maximum_identity),
        ("ROLE", role), ("STATIC_ROLES", static_roles),
        ("PreparationAuthorizationArtifactRefused", refusal),
        ("MappingProxyType", mapping_proxy_type), ("__all__", public_surface),
        ("json", json_module), ("hashlib", hashlib_module),
        ("re", re_module), ("datetime", datetime_module),
    )

    def guard():
        try:
            for name, expected in global_pins:
                if module_globals.get(name) is not expected:
                    raise ValueError("authority")
            if datetime_module.datetime is not datetime_class \
                    or datetime_class.__dict__.get("strptime") \
                    is not datetime_strptime_descriptor:
                raise ValueError("dependency")
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
            raise refusal("preparation_authorization_artifact") from None

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
        return (json_dumps(value, sort_keys=True, separators=(",", ":"),
                           ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")

    def exact_dict(value, keys):
        return type(value) is dict and all(type(key) is str for key in value) \
            and set(value) == keys

    def identifier(value):
        return type(value) is str \
            and re_fullmatch(identifier_pattern, value) is not None

    def positive_int63(value):
        return type(value) is int and 1 <= value < (1 << 63)

    def digest(value):
        return type(value) is str and len(value) == 64 \
            and re_fullmatch(r"[a-f0-9]+", value) is not None

    def parse_timestamp(value):
        if type(value) is not str \
                or re_fullmatch(timestamp_pattern, value) is None:
            raise ValueError("timestamp")
        parsed = datetime_strptime(value, "%Y-%m-%dT%H:%M:%SZ")
        if parsed.strftime("%Y-%m-%dT%H:%M:%SZ") != value:
            raise ValueError("timestamp")
        return parsed

    def decimal_identity(value):
        return type(value) is str \
            and re_fullmatch(decimal_pattern, value) is not None \
            and int(value) <= maximum_identity

    def safe_path(value):
        if type(value) is not str or not 4 <= len(value) <= maximum_path \
                or re_fullmatch(r"[a-z]:/[^\\]*", value) is None \
                or value.endswith("/") or ":" in value[2:] \
                or any(ord(character) < 32 or ord(character) > 126
                       for character in value):
            return False
        parts = value[3:].split("/")
        if len(parts) > maximum_depth:
            return False
        for part in parts:
            if not part or part in {".", ".."} or part.endswith((".", " ")) \
                    or len(part.encode("ascii")) > maximum_component \
                    or any(character in '<>"|?*' for character in part):
                return False
            alias = part.split(".", 1)[0].rstrip(" .").casefold()
            if re_fullmatch(dos_device_pattern, alias) is not None:
                return False
        return True

    def validate_destination(value):
        if not exact_dict(value, destination_keys) \
                or not safe_path(value["parentPath"]) \
                or not safe_path(value["runPath"]) \
                or not exact_dict(value["parentIdentity"], identity_keys) \
                or not decimal_identity(value["parentIdentity"]["volumeSerial"]) \
                or not decimal_identity(value["parentIdentity"]["fileId"]) \
                or not digest(value["securityDescriptorDigest"]) \
                or not digest(value["preparationPolicyDigest"]) \
                or not positive_int63(value["requestedGeneration"]):
            raise ValueError("destination")
        parent = value["parentPath"]
        run = value["runPath"]
        if not run.startswith(parent + "/") \
                or "/" in run[len(parent) + 1:]:
            raise ValueError("destination")
        return (
            parent,
            value["parentIdentity"]["volumeSerial"],
            value["parentIdentity"]["fileId"],
            run,
            value["securityDescriptorDigest"],
            value["preparationPolicyDigest"],
            value["requestedGeneration"],
        )

    def validate(value):
        if not exact_dict(value, top_keys) \
                or type(value["version"]) is not int or value["version"] != 1 \
                or type(value["role"]) is not str or value["role"] != role \
                or not identifier(value["artifactId"]) \
                or not identifier(value["issuerId"]) \
                or not identifier(value["replayId"]) \
                or not positive_int63(value["generation"]):
            raise ValueError("artifact")
        issued = parse_timestamp(value["issuedAt"])
        expires = parse_timestamp(value["expiresAt"])
        lifetime = (expires - issued).total_seconds()
        if type(lifetime) is not float \
                or not 0 < lifetime <= maximum_lifetime:
            raise ValueError("lifetime")

        static = value["staticArtifacts"]
        if type(static) is not list or len(static) != len(static_roles):
            raise ValueError("static")
        frozen_static = []
        artifact_digests = []
        for item, expected_role in zip(static, static_roles):
            if not exact_dict(item, static_keys) \
                    or type(item["role"]) is not str \
                    or item["role"] != expected_role \
                    or not identifier(item["artifactId"]) \
                    or not positive_int63(item["generation"]) \
                    or not digest(item["artifactDigest"]):
                raise ValueError("static")
            artifact_digests.append(item["artifactDigest"])
            frozen_static.append((expected_role, item["artifactId"],
                                  item["generation"], item["artifactDigest"]))
        if len(set(artifact_digests)) != len(artifact_digests):
            raise ValueError("static")

        snapshots = value["revocationSnapshots"]
        if type(snapshots) is not list or len(snapshots) != len(static_roles):
            raise ValueError("snapshots")
        frozen_snapshots = []
        snapshot_digests = []
        for item, expected_role in zip(snapshots, static_roles):
            if not exact_dict(item, snapshot_keys) \
                    or type(item["authorityRole"]) is not str \
                    or item["authorityRole"] != expected_role \
                    or not identifier(item["issuerId"]) \
                    or not positive_int63(item["issuerKeyGeneration"]) \
                    or not positive_int63(item["revocationTrustGeneration"]) \
                    or not positive_int63(item["snapshotGeneration"]) \
                    or not digest(item["snapshotDigest"]):
                raise ValueError("snapshot")
            snapshot_issued = parse_timestamp(item["issuedAt"])
            snapshot_next = parse_timestamp(item["nextUpdate"])
            snapshot_window = (snapshot_next - snapshot_issued).total_seconds()
            if type(snapshot_window) is not float \
                    or not 0 < snapshot_window <= 24 * 60 * 60:
                raise ValueError("snapshot")
            snapshot_digests.append(item["snapshotDigest"])
            frozen_snapshots.append((
                expected_role, item["issuerId"], item["issuerKeyGeneration"],
                item["revocationTrustGeneration"], item["snapshotGeneration"],
                item["snapshotDigest"], item["issuedAt"], item["nextUpdate"],
            ))
        if len(set(snapshot_digests)) != len(snapshot_digests):
            raise ValueError("snapshots")
        destination = validate_destination(value["destination"])
        return tuple(frozen_static), tuple(frozen_snapshots), destination

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
        value = json_loads(raw.decode("ascii"), object_pairs_hook=strict_object,
                           parse_constant=lambda _value: (_ for _ in ()).throw(
                               ValueError("constant")
                           ))
        static, snapshots, destination = validate(value)
        if json_bytes(value) != raw:
            raise ValueError("canonical")
        digest_value = sha256(raw).hexdigest()
        return mapping_proxy_type({
            "structuralOnly": True,
            "role": role,
            "issuerId": value["issuerId"],
            "artifactId": value["artifactId"],
            "generation": value["generation"],
            "issuedAt": value["issuedAt"],
            "expiresAt": value["expiresAt"],
            "replayId": value["replayId"],
            "staticArtifacts": static,
            "revocationSnapshots": snapshots,
            "destination": destination,
            "artifactDigest": digest_value,
            "intentDigest": sha256(domain + raw).hexdigest(),
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
            raise refusal("preparation_authorization_artifact") from None

    def canonical_artifact(value):
        return invoke(canonical_impl, value)

    def decode(raw):
        return invoke(decode_impl, raw)

    return canonical_artifact, decode


canonical_artifact, decode = _make_codec()
del _make_codec
