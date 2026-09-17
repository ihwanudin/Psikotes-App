"""Pure structural codec for the ADR-021 final composition admission artifact.

This module validates supplied canonical bytes and derives digests only.  The
literal one-shot claim is not consumed here.  The codec does not verify trust,
signatures, current freshness, protected high-water state, revocation, replay,
TLS capability custody, admission execution, or runtime behavior.  Private TLS
capability/key data is never part of this serialized boundary.  A composition
caller must pin the exported callables and authenticate the exact bytes.
"""

from __future__ import annotations

import hashlib
import json
import re
from types import MappingProxyType


MAX_ARTIFACT_BYTES = 64 * 1024
ROLE = "composition-admission"
ID_PATTERN = r"[a-z0-9](?:[a-z0-9._-]{0,127})"
BINDING_KEYS = (
    "assetReviewArtifactDigest",
    "finalConfigBinding",
    "finalManifestDigest",
    "preparationAclEvidenceDigest",
    "preparationAuthorizationArtifactDigest",
    "publicTlsCertificateEvidenceDigest",
    "releaseSourceArtifactDigest",
    "runtimeConfigurationPolicyArtifactDigest",
    "toolRuntimeClosureArtifactDigest",
    "vendorArtifactDigest",
)
REVOCATION_ROLES = (
    "asset-review",
    "composition-admission",
    # Final-lifecycle authorities outside the I1 artifact-envelope role set.
    "preparation-acl",
    "preparation-authorization",
    "release-source",
    "revocation-snapshot",
    "runtime-configuration-policy",
    "tls-material",
    "tool-runtime-closure",
    "trust-root-bundle",
    "vendor-build",
)


class CompositionAdmissionArtifactRefused(Exception):
    """Fixed refusal without binding, path, lease, or revocation details."""


__all__ = (
    "CompositionAdmissionArtifactRefused",
    "canonical_artifact",
    "decode",
)


def _make_codec():
    refusal = CompositionAdmissionArtifactRefused
    mapping_proxy_type = MappingProxyType
    maximum_bytes = MAX_ARTIFACT_BYTES
    role = ROLE
    identifier_pattern = ID_PATTERN
    binding_keys = BINDING_KEYS
    revocation_roles = REVOCATION_ROLES
    public_surface = __all__
    top_keys = frozenset({
        "artifactId",
        "bindings",
        "destinationIdentity",
        "expiresAt",
        "generation",
        "issuedAt",
        "issuerId",
        "lease",
        "oneShot",
        "requestedGeneration",
        "replayId",
        "revocationSnapshots",
        "role",
        "runIdentity",
        "version",
    })
    identity_keys = frozenset({"fileId", "path", "volumeSerial"})
    lease_keys = frozenset({"fileId", "generation", "path", "volumeSerial"})
    snapshot_keys = frozenset({
        "authorityRole",
        "issuedAt",
        "issuerId",
        "issuerKeyGeneration",
        "nextUpdate",
        "revocationTrustGeneration",
        "snapshotDigest",
        "snapshotGeneration",
    })
    reserved_names = frozenset({
        "con", "prn", "aux", "nul", "conin$", "conout$",
        *(f"com{index}" for index in range(1, 10)),
        *(f"lpt{index}" for index in range(1, 10)),
    })

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
        ("MAX_ARTIFACT_BYTES", maximum_bytes),
        ("ROLE", role),
        ("ID_PATTERN", identifier_pattern),
        ("BINDING_KEYS", binding_keys),
        ("REVOCATION_ROLES", revocation_roles),
        ("CompositionAdmissionArtifactRefused", refusal),
        ("MappingProxyType", mapping_proxy_type),
        ("__all__", public_surface),
        ("json", json_module),
        ("hashlib", hashlib_module),
        ("re", re_module),
    )

    def guard():
        try:
            for name, expected in global_pins:
                if module_globals.get(name) is not expected:
                    raise ValueError("authority")
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
            raise refusal("composition_admission_artifact") from None

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
        if type(value) is not str \
                or re_fullmatch(r"[1-9][0-9]{0,38}", value) is None:
            return False
        return int(value) < (1 << 128)

    def lowercase_sha256(value):
        return type(value) is str \
            and re_fullmatch(r"[a-f0-9]{64}", value) is not None

    def timestamp(value):
        if type(value) is not str:
            return None
        match = re_fullmatch(
            r"([0-9]{4})-([0-9]{2})-([0-9]{2})T"
            r"([0-9]{2}):([0-9]{2}):([0-9]{2})Z",
            value,
        )
        if match is None:
            return None
        year, month, day, hour, minute, second = (
            int(part) for part in match.groups()
        )
        if not 1 <= year <= 9999 or not 1 <= month <= 12 \
                or hour > 23 or minute > 59 or second > 59:
            return None
        leap = year % 4 == 0 and (year % 100 != 0 or year % 400 == 0)
        month_days = (31, 29 if leap else 28, 31, 30, 31, 30,
                      31, 31, 30, 31, 30, 31)
        if not 1 <= day <= month_days[month - 1]:
            return None
        prior_year = year - 1
        days = 365 * prior_year + prior_year // 4 \
            - prior_year // 100 + prior_year // 400
        days += sum(month_days[:month - 1]) + day - 1
        return ((days * 24 + hour) * 60 + minute) * 60 + second

    def canonical_path(value):
        if type(value) is not str or not 4 <= len(value) <= 4096 \
                or not "A" <= value[0] <= "Z" or value[1:3] != ":/" \
                or value.endswith("/") or "\\" in value or ":" in value[2:] \
                or any(ord(character) < 32 or ord(character) > 126
                       for character in value):
            return False
        parts = value[3:].split("/")
        if not parts or len(parts) > 64:
            return False
        for part in parts:
            if not part or part in (".", "..") or len(part) > 255 \
                    or part.endswith((".", " ")) \
                    or any(character in '<>"|?*' for character in part):
                return False
            base = part.split(".", 1)[0].rstrip(" .").lower()
            if base in reserved_names:
                return False
        return True

    def freeze_identity(value, expected_keys):
        if not exact_keys(value, expected_keys) \
                or not canonical_path(value["path"]) \
                or not decimal_uint128(value["volumeSerial"]) \
                or not decimal_uint128(value["fileId"]):
            raise ValueError("identity")
        if "generation" in expected_keys \
                and not positive_int63(value["generation"]):
            raise ValueError("identity")
        return (
            value["path"],
            value["volumeSerial"],
            value["fileId"],
        )

    def validate(value):
        if not exact_keys(value, top_keys) \
                or type(value["version"]) is not int or value["version"] != 1 \
                or type(value["role"]) is not str or value["role"] != role \
                or not identifier(value["artifactId"]) \
                or not identifier(value["issuerId"]) \
                or not identifier(value["replayId"]) \
                or not positive_int63(value["generation"]) \
                or not positive_int63(value["requestedGeneration"]) \
                or value["oneShot"] is not True:
            raise ValueError("artifact")
        issued = timestamp(value["issuedAt"])
        expires = timestamp(value["expiresAt"])
        if issued is None or expires is None or not 0 < expires - issued <= 300:
            raise ValueError("lifetime")

        bindings = value["bindings"]
        if not exact_keys(bindings, frozenset(binding_keys)) \
                or any(not lowercase_sha256(bindings[key]) for key in binding_keys):
            raise ValueError("bindings")
        if len(set(bindings.values())) != len(binding_keys):
            raise ValueError("bindings")

        destination = freeze_identity(value["destinationIdentity"], identity_keys)
        run = freeze_identity(value["runIdentity"], identity_keys)
        lease = freeze_identity(value["lease"], lease_keys)
        if len({(item[1], item[2]) for item in (destination, run, lease)}) != 3:
            raise ValueError("identity_collision")
        if not run[0].startswith(destination[0] + "/") \
                or "/" in run[0][len(destination[0]) + 1:] \
                or lease[0] != run[0] + "/.checkout-coordinator.lease":
            raise ValueError("identity_relation")

        snapshots = value["revocationSnapshots"]
        if type(snapshots) is not list or len(snapshots) != len(revocation_roles):
            raise ValueError("snapshots")
        frozen_snapshots = []
        snapshot_digests = []
        for index, snapshot in enumerate(snapshots):
            if not exact_keys(snapshot, snapshot_keys) \
                    or type(snapshot["authorityRole"]) is not str \
                    or snapshot["authorityRole"] != revocation_roles[index] \
                    or not identifier(snapshot["issuerId"]) \
                    or not positive_int63(snapshot["issuerKeyGeneration"]) \
                    or not positive_int63(snapshot["revocationTrustGeneration"]) \
                    or not positive_int63(snapshot["snapshotGeneration"]) \
                    or not lowercase_sha256(snapshot["snapshotDigest"]):
                raise ValueError("snapshot")
            snapshot_issued = timestamp(snapshot["issuedAt"])
            next_update = timestamp(snapshot["nextUpdate"])
            if snapshot_issued is None or next_update is None \
                    or not 0 < next_update - snapshot_issued <= 24 * 60 * 60:
                raise ValueError("snapshot_time")
            frozen_snapshots.append((
                snapshot["authorityRole"],
                snapshot["issuerId"],
                snapshot["issuerKeyGeneration"],
                snapshot["revocationTrustGeneration"],
                snapshot["snapshotGeneration"],
                snapshot["issuedAt"],
                snapshot["nextUpdate"],
                snapshot["snapshotDigest"],
            ))
            snapshot_digests.append(snapshot["snapshotDigest"])
        if len(set(snapshot_digests)) != len(revocation_roles):
            raise ValueError("snapshots")
        return destination, run, lease, tuple(frozen_snapshots)

    def canonical_impl(value):
        validate(value)
        raw = json_bytes(value)
        if len(raw) > maximum_bytes:
            raise ValueError("size")
        return raw

    def decode_impl(raw):
        if type(raw) is not bytes or not 1 <= len(raw) <= maximum_bytes \
                or not raw.endswith(b"\n") or raw.endswith(b"\n\n"):
            raise ValueError("raw")
        value = json_loads(
            raw.decode("ascii"),
            object_pairs_hook=strict_object,
            parse_constant=lambda _value: (_ for _ in ()).throw(
                ValueError("constant")
            ),
        )
        _destination, _run, _lease, snapshots = validate(value)
        if json_bytes(value) != raw:
            raise ValueError("canonical")
        binding_document = {
            "bindings": value["bindings"],
            "destinationIdentity": value["destinationIdentity"],
            "lease": value["lease"],
            "requestedGeneration": value["requestedGeneration"],
            "runIdentity": value["runIdentity"],
        }
        binding_digest = sha256(
            b"oncam.checkout.composition-admission-bindings.v1\0"
            + json_bytes(binding_document)
        ).hexdigest()
        revocation_digest = sha256(
            b"oncam.checkout.composition-admission-revocations.v1\0"
            + json_bytes(value["revocationSnapshots"])
        ).hexdigest()
        return mapping_proxy_type({
            "structuralOnly": True,
            "role": role,
            "artifactId": value["artifactId"],
            "issuerId": value["issuerId"],
            "generation": value["generation"],
            "requestedGeneration": value["requestedGeneration"],
            "issuedAt": value["issuedAt"],
            "expiresAt": value["expiresAt"],
            "replayId": value["replayId"],
            "oneShot": True,
            "bindingDigest": binding_digest,
            "revocationSetDigest": revocation_digest,
            "revocationSnapshotCount": len(snapshots),
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
            raise refusal("composition_admission_artifact") from None

    def canonical_artifact(value):
        return invoke(canonical_impl, value)

    def decode(raw):
        return invoke(decode_impl, raw)

    return canonical_artifact, decode


canonical_artifact, decode = _make_codec()
del _make_codec
