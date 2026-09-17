"""Pure structural codec for ADR-021 offline revocation snapshots.

The result describes supplied canonical bytes only.  This module does not
verify signatures or quorum, consult or update monotonic state, decide whether
a snapshot is current/stale/conflicting, consume replay state, or grant any
preparation/runtime admission.

A composition caller must pin the imported ``canonical_snapshot`` and
``decode`` callable identities.  It must separately authenticate the exact
snapshot bytes and enforce live time, revocation, replay, and strict-higher
generation policy.  This codec cannot defend a caller after an exported module
binding has been replaced.

The signed cutoff is internally consistent only when
``notBefore <= issuedAt < nextUpdate``.  That relationship does not establish
applicability or freshness against a live clock.
"""

from __future__ import annotations

import datetime
import hashlib
import json
import re
from types import MappingProxyType


MAX_SNAPSHOT_BYTES = 256 * 1024
MAX_REVOKED_ENTRIES = 4096
ID_PATTERN = r"[a-z0-9](?:[a-z0-9._-]{0,63})"
AUTHORITY_ROLES = (
    "asset-review",
    "composition-admission",
    "preparation-authorization",
    "release-source",
    "revocation-snapshot",
    "runtime-configuration-policy",
    "tool-runtime-closure",
    "trust-root-bundle",
    "vendor-build",
)


class RevocationSnapshotRefused(Exception):
    """Fixed refusal without namespace, timestamp, or revocation details."""


__all__ = (
    "RevocationSnapshotRefused",
    "canonical_snapshot",
    "decode",
)


def _make_codec():
    refusal = RevocationSnapshotRefused
    mapping_proxy_type = MappingProxyType
    authority_roles = AUTHORITY_ROLES
    maximum_snapshot = MAX_SNAPSHOT_BYTES
    maximum_entries = MAX_REVOKED_ENTRIES
    identifier_pattern = ID_PATTERN
    public_surface = __all__
    snapshot_keys = frozenset({
        "authorityRole",
        "generation",
        "issuedAt",
        "issuerId",
        "issuerKeyGeneration",
        "nextUpdate",
        "notBefore",
        "revocationTrustGeneration",
        "revokedArtifactDigests",
        "revokedArtifactIds",
        "revokedKeyGenerations",
        "version",
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
        ("MAX_SNAPSHOT_BYTES", maximum_snapshot),
        ("MAX_REVOKED_ENTRIES", maximum_entries),
        ("ID_PATTERN", identifier_pattern),
        ("AUTHORITY_ROLES", authority_roles),
        ("RevocationSnapshotRefused", refusal),
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
            raise refusal("revocation_snapshot") from None

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

    def positive_int63(value):
        return type(value) is int and 1 <= value < (1 << 63)

    def identifier(value):
        return type(value) is str \
            and re_fullmatch(identifier_pattern, value) is not None

    def lowercase_hex(value):
        return type(value) is str and len(value) == 64 \
            and re_fullmatch(r"[a-f0-9]+", value) is not None

    def timestamp(value):
        if type(value) is not str \
                or re_fullmatch(r"[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z", value) is None:
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

    def sorted_unique(values, validator):
        if type(values) is not list or len(values) > maximum_entries:
            raise ValueError("entries")
        previous = None
        output = []
        for index, value in enumerate(values):
            if not validator(value) or (index and value <= previous):
                raise ValueError("entries")
            output.append(value)
            previous = value
        return tuple(output)

    def validate(value):
        if type(value) is not dict \
                or any(type(key) is not str for key in value) \
                or set(value) != snapshot_keys \
                or type(value["version"]) is not int or value["version"] != 1 \
                or type(value["authorityRole"]) is not str \
                or value["authorityRole"] not in authority_roles \
                or not identifier(value["issuerId"]) \
                or not positive_int63(value["issuerKeyGeneration"]) \
                or not positive_int63(value["revocationTrustGeneration"]) \
                or not positive_int63(value["generation"]):
            raise ValueError("snapshot")

        issued_at = timestamp(value["issuedAt"])
        next_update = timestamp(value["nextUpdate"])
        not_before = timestamp(value["notBefore"])
        validity = next_update - issued_at
        if not not_before <= issued_at < next_update \
                or validity > timedelta_type(hours=24):
            raise ValueError("validity")

        artifact_ids = sorted_unique(value["revokedArtifactIds"], identifier)
        artifact_digests = sorted_unique(
            value["revokedArtifactDigests"], lowercase_hex
        )
        key_generations = sorted_unique(
            value["revokedKeyGenerations"], positive_int63
        )
        if len(artifact_ids) + len(artifact_digests) + len(key_generations) \
                > maximum_entries:
            raise ValueError("entries")
        return artifact_ids, artifact_digests, key_generations

    def canonical_impl(value):
        validate(value)
        raw = json_bytes(value)
        if len(raw) > maximum_snapshot:
            raise ValueError("size")
        return raw

    def decode_impl(
        raw,
        expected_role,
        expected_issuer_id,
        expected_issuer_key_generation,
        expected_revocation_trust_generation,
    ):
        if type(raw) is not bytes or not 1 <= len(raw) <= maximum_snapshot \
                or not raw.endswith(b"\n") or raw.endswith(b"\n\n"):
            raise ValueError("raw")
        if type(expected_role) is not str or expected_role not in authority_roles \
                or not identifier(expected_issuer_id) \
                or not positive_int63(expected_issuer_key_generation) \
                or not positive_int63(expected_revocation_trust_generation):
            raise ValueError("namespace")

        value = json_loads(
            raw.decode("ascii"),
            object_pairs_hook=strict_object,
            parse_constant=lambda _value: (_ for _ in ()).throw(
                ValueError("constant")
            ),
        )
        artifact_ids, artifact_digests, key_generations = validate(value)
        if json_bytes(value) != raw \
                or value["authorityRole"] != expected_role \
                or value["issuerId"] != expected_issuer_id \
                or value["issuerKeyGeneration"] != expected_issuer_key_generation \
                or value["revocationTrustGeneration"] \
                != expected_revocation_trust_generation:
            raise ValueError("canonical")

        return mapping_proxy_type({
            "structuralOnly": True,
            "authorityRole": expected_role,
            "issuerId": expected_issuer_id,
            "issuerKeyGeneration": expected_issuer_key_generation,
            "revocationTrustGeneration": expected_revocation_trust_generation,
            "generation": value["generation"],
            "issuedAt": value["issuedAt"],
            "nextUpdate": value["nextUpdate"],
            "notBefore": value["notBefore"],
            "revokedArtifactIds": artifact_ids,
            "revokedArtifactDigests": artifact_digests,
            "revokedKeyGenerations": key_generations,
            "snapshotDigest": sha256(raw).hexdigest(),
        })

    def invoke(operation, *args, **kwargs):
        try:
            guard()
            result = operation(*args, **kwargs)
            guard()
            return result
        except BaseException as primary:
            try:
                guard()
            except BaseException:
                pass
            if isinstance(primary, (KeyboardInterrupt, SystemExit)):
                raise
            raise refusal("revocation_snapshot") from None

    def canonical_snapshot(value):
        return invoke(canonical_impl, value)

    def decode(
        raw,
        *,
        expected_role,
        expected_issuer_id,
        expected_issuer_key_generation,
        expected_revocation_trust_generation,
    ):
        return invoke(
            decode_impl,
            raw,
            expected_role,
            expected_issuer_id,
            expected_issuer_key_generation,
            expected_revocation_trust_generation,
        )

    return canonical_snapshot, decode


canonical_snapshot, decode = _make_codec()
del _make_codec
