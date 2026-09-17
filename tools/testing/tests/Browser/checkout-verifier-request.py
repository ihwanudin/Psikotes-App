"""Pure structural request codec for the ADR-021 repository verifier.

This module validates supplied bindings only.  It performs no cryptography,
trust, revocation, freshness, high-water persistence, replay, admission, or
runtime work.  Composition must pin the exported callables.
"""

from __future__ import annotations

import hashlib
import json
import re
from types import MappingProxyType


MAX_REQUEST_BYTES = 32 * 1024
ROLES = (
    "asset-review", "composition-admission", "preparation-acl",
    "preparation-authorization", "release-source", "revocation-snapshot",
    "runtime-configuration-policy", "tls-material", "tool-runtime-closure",
    "trust-root-bundle", "vendor-build",
)
ZERO_DIGEST = "0" * 64


class VerifierRequestRefused(Exception):
    """Fixed refusal without binding or dependency details."""


__all__ = ("VerifierRequestRefused", "canonical_request", "decode")


def _make_codec():
    refusal = VerifierRequestRefused
    mapping_proxy_type = MappingProxyType
    maximum_request = MAX_REQUEST_BYTES
    roles = ROLES
    zero_digest = ZERO_DIGEST
    public_surface = __all__
    identifier_pattern = r"[a-z0-9](?:[a-z0-9._-]{0,127})"
    top_keys = frozenset({
        "artifact", "cryptographyRuntime", "detachedEnvelope", "highWater",
        "requestId", "revocationSnapshot", "role", "runId",
        "trustRootBundle", "trustedTime", "verifier", "version",
    })
    artifact_keys = frozenset({"bytesDigest", "role"})
    crypto_keys = frozenset({
        "acquisitionEvidenceDigest", "closureDigest", "generation"
    })
    envelope_keys = frozenset({"bytesDigest"})
    high_water_keys = frozenset({
        "currentGeneration", "currentStateDigest", "proposedGeneration",
        "proposedStateDigest",
    })
    snapshot_keys = frozenset({"bytesDigest", "generation", "namespace"})
    namespace_keys = frozenset({
        "authorityRole", "issuerId", "issuerKeyGeneration",
        "revocationTrustGeneration",
    })
    trust_keys = frozenset({"bytesDigest", "trustGeneration"})
    time_keys = frozenset({"inputSetDigest"})
    verifier_keys = frozenset({"sourceDigest"})

    json_module = json
    hashlib_module = hashlib
    re_module = re
    json_dumps = json.dumps
    json_loads = json.loads
    sha256 = hashlib.sha256
    re_fullmatch = re.fullmatch
    module_globals = globals()

    def freeze_metadata(value):
        if value is None: return ("none",)
        if type(value) is bool: return ("bool", value)
        if type(value) is int: return ("int", value)
        if type(value) is str: return ("str", value)
        if type(value) is tuple:
            return ("tuple", tuple(freeze_metadata(item) for item in value))
        raise ValueError("metadata")

    def mapping_snapshot(value):
        if value is None: return None
        if type(value) is not dict or any(type(key) is not str for key in value):
            raise ValueError("metadata")
        return tuple((key, freeze_metadata(item)) for key, item in value.items())

    def mapping_matches(value, expected):
        if expected is None: return value is None
        if type(value) is not dict or len(value) != len(expected): return False
        for key, item in expected:
            if key not in value: return False
            try:
                if freeze_metadata(value[key]) != item: return False
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
        ("MAX_REQUEST_BYTES", maximum_request), ("ROLES", roles),
        ("ZERO_DIGEST", zero_digest), ("VerifierRequestRefused", refusal),
        ("MappingProxyType", mapping_proxy_type), ("__all__", public_surface),
        ("json", json_module), ("hashlib", hashlib_module), ("re", re_module),
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
            raise refusal("verifier_request") from None

    def exact_dict(value, keys):
        return type(value) is dict and all(type(key) is str for key in value) \
            and set(value) == keys

    def strict_object(pairs):
        guard()
        result = {}
        for key, value in pairs:
            if type(key) is not str or key in result: raise ValueError("duplicate")
            result[key] = value
        guard()
        return result

    def json_bytes(value):
        return (json_dumps(value, sort_keys=True, separators=(",", ":"),
                           ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")

    def identifier(value):
        return type(value) is str \
            and re_fullmatch(identifier_pattern, value) is not None

    def digest(value):
        return type(value) is str \
            and re_fullmatch(r"[a-f0-9]{64}", value) is not None

    def positive(value):
        return type(value) is int and 1 <= value < (1 << 63)

    def nonnegative(value):
        return type(value) is int and 0 <= value < (1 << 63)

    def validate(value):
        if not exact_dict(value, top_keys) \
                or type(value["version"]) is not int or value["version"] != 1 \
                or not identifier(value["requestId"]) \
                or not identifier(value["runId"]) \
                or type(value["role"]) is not str or value["role"] not in roles:
            raise ValueError("request")
        role = value["role"]
        artifact = value["artifact"]
        crypto = value["cryptographyRuntime"]
        envelope = value["detachedEnvelope"]
        high = value["highWater"]
        snapshot = value["revocationSnapshot"]
        trust = value["trustRootBundle"]
        time = value["trustedTime"]
        verifier = value["verifier"]
        if not exact_dict(artifact, artifact_keys) \
                or type(artifact["role"]) is not str or artifact["role"] != role \
                or not digest(artifact["bytesDigest"]) \
                or not exact_dict(crypto, crypto_keys) \
                or not digest(crypto["acquisitionEvidenceDigest"]) \
                or not digest(crypto["closureDigest"]) \
                or not positive(crypto["generation"]) \
                or not exact_dict(envelope, envelope_keys) \
                or not digest(envelope["bytesDigest"]) \
                or not exact_dict(trust, trust_keys) \
                or not digest(trust["bytesDigest"]) \
                or not positive(trust["trustGeneration"]) \
                or not exact_dict(time, time_keys) \
                or not digest(time["inputSetDigest"]) \
                or not exact_dict(verifier, verifier_keys) \
                or not digest(verifier["sourceDigest"]):
            raise ValueError("binding")
        if not exact_dict(snapshot, snapshot_keys) \
                or not digest(snapshot["bytesDigest"]) \
                or not positive(snapshot["generation"]) \
                or not exact_dict(snapshot["namespace"], namespace_keys):
            raise ValueError("snapshot")
        namespace = snapshot["namespace"]
        if type(namespace["authorityRole"]) is not str \
                or namespace["authorityRole"] != role \
                or not identifier(namespace["issuerId"]) \
                or not positive(namespace["issuerKeyGeneration"]) \
                or not positive(namespace["revocationTrustGeneration"]):
            raise ValueError("namespace")
        if not exact_dict(high, high_water_keys) \
                or not nonnegative(high["currentGeneration"]) \
                or not positive(high["proposedGeneration"]) \
                or not digest(high["currentStateDigest"]) \
                or not digest(high["proposedStateDigest"]):
            raise ValueError("high_water")
        if snapshot["generation"] != high["proposedGeneration"] \
                or high["proposedStateDigest"] == zero_digest \
                or high["proposedStateDigest"] == high["currentStateDigest"]:
            raise ValueError("high_water")
        if high["currentGeneration"] == 0:
            if high["currentStateDigest"] != zero_digest \
                    or high["proposedGeneration"] != 1:
                raise ValueError("high_water")
        elif high["currentStateDigest"] == zero_digest \
                or high["proposedGeneration"] <= high["currentGeneration"]:
            raise ValueError("high_water")
        return namespace

    def canonical_impl(value):
        validate(value)
        raw = json_bytes(value)
        if len(raw) > maximum_request: raise ValueError("size")
        return raw

    def decode_impl(raw):
        if type(raw) is not bytes or not 1 <= len(raw) <= maximum_request \
                or not raw.endswith(b"\n") or raw.endswith(b"\n\n"):
            raise ValueError("raw")
        value = json_loads(raw.decode("ascii"), object_pairs_hook=strict_object,
                           parse_constant=lambda _v: (_ for _ in ()).throw(ValueError("constant")))
        namespace = validate(value)
        if json_bytes(value) != raw: raise ValueError("canonical")
        return mapping_proxy_type({
            "structuralOnly": True, "requestId": value["requestId"],
            "runId": value["runId"], "role": value["role"],
            "artifactDigest": value["artifact"]["bytesDigest"],
            "envelopeDigest": value["detachedEnvelope"]["bytesDigest"],
            "trustBundleDigest": value["trustRootBundle"]["bytesDigest"],
            "trustGeneration": value["trustRootBundle"]["trustGeneration"],
            "revocationSnapshotDigest": value["revocationSnapshot"]["bytesDigest"],
            "revocationGeneration": value["revocationSnapshot"]["generation"],
            "revocationNamespace": (
                namespace["authorityRole"], namespace["issuerId"],
                namespace["issuerKeyGeneration"], namespace["revocationTrustGeneration"],
            ),
            "trustedTimeInputSetDigest": value["trustedTime"]["inputSetDigest"],
            "currentHighWaterStateDigest": value["highWater"]["currentStateDigest"],
            "currentHighWaterGeneration": value["highWater"]["currentGeneration"],
            "proposedHighWaterStateDigest": value["highWater"]["proposedStateDigest"],
            "proposedHighWaterGeneration": value["highWater"]["proposedGeneration"],
            "verifierSourceDigest": value["verifier"]["sourceDigest"],
            "cryptographyAcquisitionEvidenceDigest": value["cryptographyRuntime"]["acquisitionEvidenceDigest"],
            "cryptographyClosureDigest": value["cryptographyRuntime"]["closureDigest"],
            "cryptographyGeneration": value["cryptographyRuntime"]["generation"],
            "requestDigest": sha256(raw).hexdigest(),
        })

    def invoke(operation, *args):
        try:
            guard(); result = operation(*args); guard(); return result
        except BaseException as primary:
            try: guard()
            except BaseException: pass
            if isinstance(primary, (KeyboardInterrupt, SystemExit)): raise
            raise refusal("verifier_request") from None

    def canonical_request(value): return invoke(canonical_impl, value)
    def decode(raw): return invoke(decode_impl, raw)
    return canonical_request, decode


canonical_request, decode = _make_codec()
del _make_codec
