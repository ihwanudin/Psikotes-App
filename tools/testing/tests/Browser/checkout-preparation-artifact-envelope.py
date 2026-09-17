"""Pure structural codec for the ADR-021 detached-signature envelope.

This module validates supplied byte shape and derives digests only.  It does
not validate a role-specific artifact schema, verify a signature, resolve a
key, enforce a quorum, or grant preparation/runtime admission.

A composition caller must pin the imported ``canonical_envelope`` and
``decode`` callable identities.  This codec cannot defend a caller after an
exported module binding has been replaced.
"""

from __future__ import annotations

import hashlib
import json
import re
from types import MappingProxyType


MAX_ENVELOPE_BYTES = 4096
MAX_ARTIFACT_BYTES = 8 * 1024 * 1024
ID_PATTERN = r"[a-z0-9](?:[a-z0-9._-]{0,63})"
ROLES = (
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


class PreparationArtifactEnvelopeRefused(Exception):
    """Fixed refusal without artifact, signer, or dependency details."""


__all__ = (
    "PreparationArtifactEnvelopeRefused",
    "canonical_envelope",
    "decode",
)


def _make_codec():
    refusal = PreparationArtifactEnvelopeRefused
    mapping_proxy_type = MappingProxyType
    roles = ROLES
    maximum_envelope = MAX_ENVELOPE_BYTES
    maximum_artifact = MAX_ARTIFACT_BYTES
    identifier_pattern = ID_PATTERN
    public_surface = __all__
    envelope_keys = frozenset({
        "algorithm",
        "artifactDigest",
        "issuerId",
        "keyGeneration",
        "keyId",
        "role",
        "signature",
        "trustGeneration",
        "version",
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
        ("MAX_ENVELOPE_BYTES", maximum_envelope),
        ("MAX_ARTIFACT_BYTES", maximum_artifact),
        ("ID_PATTERN", identifier_pattern),
        ("ROLES", roles),
        ("PreparationArtifactEnvelopeRefused", refusal),
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
            raise refusal("preparation_artifact_envelope") from None

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

    def lowercase_hex(value, length):
        return type(value) is str and len(value) == length \
            and re_fullmatch(r"[a-f0-9]+", value) is not None

    def validate_envelope(value):
        if type(value) is not dict \
                or any(type(key) is not str for key in value) \
                or set(value) != envelope_keys \
                or type(value["version"]) is not int or value["version"] != 1 \
                or type(value["algorithm"]) is not str \
                or value["algorithm"] != "ed25519" \
                or type(value["role"]) is not str \
                or value["role"] not in roles \
                or not identifier(value["issuerId"]) \
                or not identifier(value["keyId"]) \
                or not positive_int63(value["keyGeneration"]) \
                or not positive_int63(value["trustGeneration"]) \
                or not lowercase_hex(value["artifactDigest"], 64) \
                or not lowercase_hex(value["signature"], 128):
            raise ValueError("envelope")

    def canonical_impl(value):
        validate_envelope(value)
        raw = json_bytes(value)
        if len(raw) > maximum_envelope:
            raise ValueError("size")
        return raw

    def decode_impl(raw, artifact_raw, expected_role):
        if type(raw) is not bytes or not 1 <= len(raw) <= maximum_envelope \
                or not raw.endswith(b"\n") or raw.endswith(b"\n\n"):
            raise ValueError("raw")
        if type(artifact_raw) is not bytes \
                or not 1 <= len(artifact_raw) <= maximum_artifact:
            raise ValueError("artifact")
        if type(expected_role) is not str or expected_role not in roles:
            raise ValueError("role")

        text = raw.decode("ascii")
        value = json_loads(
            text,
            object_pairs_hook=strict_object,
            parse_constant=lambda _value: (_ for _ in ()).throw(
                ValueError("constant")
            ),
        )
        validate_envelope(value)
        if json_bytes(value) != raw or value["role"] != expected_role:
            raise ValueError("canonical")

        artifact_digest = sha256(artifact_raw).hexdigest()
        if value["artifactDigest"] != artifact_digest:
            raise ValueError("digest")
        signed_message = (
            b"oncam.checkout."
            + expected_role.encode("ascii")
            + b".v1\0"
            + artifact_raw
        )
        return mapping_proxy_type({
            "structuralOnly": True,
            "role": expected_role,
            "issuerId": value["issuerId"],
            "keyId": value["keyId"],
            "keyGeneration": value["keyGeneration"],
            "trustGeneration": value["trustGeneration"],
            "artifactDigest": artifact_digest,
            "envelopeDigest": sha256(raw).hexdigest(),
            "signingMessageDigest": sha256(signed_message).hexdigest(),
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
            raise refusal("preparation_artifact_envelope") from None

    def canonical_envelope(value):
        return invoke(canonical_impl, value)

    def decode(raw, artifact_raw, *, expected_role):
        return invoke(decode_impl, raw, artifact_raw, expected_role)

    return canonical_envelope, decode


canonical_envelope, decode = _make_codec()
del _make_codec
