"""Pure structural request codec for future TLS-material verification.

The request binds already-validated structural evidence, package, payload, and
envelope digests to caller-supplied trust/revocation/time inputs. It does not
access a filesystem, verify trust/signatures/freshness, consume replay state,
materialize secrets, satisfy policy, or authorize runtime use.
"""

from __future__ import annotations

import hashlib
import importlib.util
import json
import re
from pathlib import Path
from types import MappingProxyType


MAX_REQUEST_BYTES = 16 * 1024
SCHEMA = "checkout-tls-material-verification-request"
ROLE = "tls-material"


class TlsMaterialVerificationRequestRefused(Exception):
    """Fixed refusal without trust input, identity, or dependency details."""


__all__ = (
    "TlsMaterialVerificationRequestRefused",
    "canonical_request",
    "decode",
)


def _load_v2():
    spec = importlib.util.spec_from_file_location(
        "checkout_tls_material_envelope_v2_for_verification_request",
        Path(__file__).resolve().with_name("checkout-tls-material-envelope-v2.py"),
    )
    if spec is None or spec.loader is None:
        raise RuntimeError("tls_material_verification_request")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


_V2 = _load_v2()
del _load_v2


def _make_codec():
    refusal = TlsMaterialVerificationRequestRefused
    proxy_type = MappingProxyType
    maximum = MAX_REQUEST_BYTES
    schema = SCHEMA
    role = ROLE
    public_surface = __all__
    v2_module = _V2
    v2_decode = v2_module.decode
    request_keys = frozenset({
        "version", "schema", "requestId", "role", "replayId", "generation",
        "runIdentityDigest", "leaseIdentityDigest", "tlsEvidenceDigest",
        "tlsPackageDigest", "payloadDigest", "envelopeDigest",
        "trustBundleDigest", "trustGeneration", "revocationSnapshotDigest",
        "revocationGeneration", "trustedTimeChallengeDigest",
    })
    identifier_pattern = r"[a-z0-9](?:[a-z0-9._-]{0,127})"

    json_module = json
    hashlib_module = hashlib
    re_module = re
    json_dumps = json.dumps
    json_loads = json.loads
    sha256 = hashlib.sha256
    re_fullmatch = re.fullmatch
    module_globals = globals()

    def frozen(value):
        if value is None or type(value) in (bool, int, str, bytes):
            return (type(value), value)
        if type(value) is tuple:
            return (tuple, tuple(frozen(item) for item in value))
        raise ValueError("metadata")

    def mapping_snapshot(value):
        if value is None:
            return None
        if type(value) is not dict or any(type(key) is not str for key in value):
            raise ValueError("metadata")
        return tuple((key, frozen(item)) for key, item in value.items())

    def mapping_matches(value, snapshot):
        if snapshot is None:
            return value is None
        if type(value) is not dict or len(value) != len(snapshot):
            return False
        for key, expected in snapshot:
            if key not in value:
                return False
            try:
                if frozen(value[key]) != expected:
                    return False
            except Exception:
                return False
        return True

    def callable_state(function):
        defaults = getattr(function, "__defaults__", None)
        kwdefaults = getattr(function, "__kwdefaults__", None)
        return (
            function, type(function), getattr(function, "__code__", None),
            defaults, frozen(defaults), kwdefaults, mapping_snapshot(kwdefaults),
            getattr(function, "__closure__", None),
            getattr(function, "__globals__", None),
        )

    dependencies = (
        (json_module, "dumps", callable_state(json_dumps)),
        (json_module, "loads", callable_state(json_loads)),
        (hashlib_module, "sha256", callable_state(sha256)),
        (re_module, "fullmatch", callable_state(re_fullmatch)),
        (v2_module, "decode", callable_state(v2_decode)),
    )
    global_pins = (
        ("MAX_REQUEST_BYTES", maximum), ("SCHEMA", schema), ("ROLE", role),
        ("TlsMaterialVerificationRequestRefused", refusal),
        ("MappingProxyType", proxy_type), ("__all__", public_surface),
        ("json", json_module), ("hashlib", hashlib_module), ("re", re_module),
        ("_V2", v2_module),
    )

    def guard():
        try:
            for name, expected in global_pins:
                if module_globals.get(name) is not expected:
                    raise ValueError("authority")
            for owner, name, expected in dependencies:
                current = getattr(owner, name, None)
                if current is not expected[0] or type(current) is not expected[1] \
                        or getattr(current, "__code__", None) is not expected[2] \
                        or getattr(current, "__defaults__", None) is not expected[3] \
                        or frozen(getattr(current, "__defaults__", None)) != expected[4] \
                        or getattr(current, "__kwdefaults__", None) is not expected[5] \
                        or not mapping_matches(
                            getattr(current, "__kwdefaults__", None), expected[6]
                        ) \
                        or getattr(current, "__closure__", None) is not expected[7] \
                        or getattr(current, "__globals__", None) is not expected[8]:
                    raise ValueError("dependency")
        except refusal:
            raise
        except Exception:
            raise refusal("tls_material_verification_request") from None

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
        return (json_dumps(
            value, sort_keys=True, separators=(",", ":"), ensure_ascii=True,
            allow_nan=False,
        ) + "\n").encode("ascii")

    def exact_dict(value, keys):
        return type(value) is dict \
            and all(type(key) is str for key in value) and set(value) == keys

    def identifier(value):
        return type(value) is str \
            and re_fullmatch(identifier_pattern, value) is not None

    def digest(value):
        return type(value) is str \
            and re_fullmatch(r"[a-f0-9]{64}", value) is not None

    def positive_int63(value):
        return type(value) is int and 1 <= value < (1 << 63)

    def validate(source, value):
        if not exact_dict(value, request_keys) \
                or type(value["version"]) is not int or value["version"] != 1 \
                or type(value["schema"]) is not str or value["schema"] != schema \
                or not identifier(value["requestId"]) \
                or type(value["role"]) is not str or value["role"] != role \
                or not identifier(value["replayId"]) \
                or value["replayId"] != source["replayId"] \
                or not positive_int63(value["generation"]) \
                or value["generation"] != source["generation"] \
                or not digest(value["runIdentityDigest"]) \
                or value["runIdentityDigest"] != source["runIdentityDigest"] \
                or not digest(value["leaseIdentityDigest"]) \
                or value["leaseIdentityDigest"] != source["leaseIdentityDigest"] \
                or not digest(value["tlsEvidenceDigest"]) \
                or value["tlsEvidenceDigest"] != source["tlsEvidenceDigest"] \
                or not digest(value["tlsPackageDigest"]) \
                or value["tlsPackageDigest"] != source["tlsPackageDigest"] \
                or not digest(value["payloadDigest"]) \
                or value["payloadDigest"] != source["artifactDigest"] \
                or not digest(value["envelopeDigest"]) \
                or value["envelopeDigest"] != source["envelopeDigest"] \
                or not digest(value["trustBundleDigest"]) \
                or not positive_int63(value["trustGeneration"]) \
                or value["trustGeneration"] != source["trustGeneration"] \
                or not digest(value["revocationSnapshotDigest"]) \
                or not positive_int63(value["revocationGeneration"]) \
                or not digest(value["trustedTimeChallengeDigest"]):
            raise ValueError("request")

    def parse(raw):
        if type(raw) is not bytes or not 1 <= len(raw) <= maximum \
                or not raw.endswith(b"\n") or raw.endswith(b"\n\n"):
            raise ValueError("raw")
        value = json_loads(
            raw.decode("ascii"), object_pairs_hook=strict_object,
            parse_constant=lambda _value: (_ for _ in ()).throw(
                ValueError("constant")
            ),
        )
        if json_bytes(value) != raw:
            raise ValueError("canonical")
        return value

    def source_result(evidence_raw, package_raw, payload_raw, envelope_raw):
        value = v2_decode(evidence_raw, package_raw, payload_raw, envelope_raw)
        if type(value) is not proxy_type or value.get("structuralOnly") is not True:
            raise ValueError("source")
        return value

    def canonical_impl(evidence_raw, package_raw, payload_raw, envelope_raw, value):
        source = source_result(
            evidence_raw, package_raw, payload_raw, envelope_raw
        )
        validate(source, value)
        raw = json_bytes(value)
        if len(raw) > maximum:
            raise ValueError("size")
        return raw

    def decode_impl(evidence_raw, package_raw, payload_raw, envelope_raw, raw):
        source = source_result(
            evidence_raw, package_raw, payload_raw, envelope_raw
        )
        value = parse(raw)
        validate(source, value)
        return proxy_type({
            "structuralOnly": True,
            "schema": schema,
            "requestId": value["requestId"],
            "role": role,
            "replayId": value["replayId"],
            "generation": value["generation"],
            "runIdentityDigest": value["runIdentityDigest"],
            "leaseIdentityDigest": value["leaseIdentityDigest"],
            "tlsEvidenceDigest": value["tlsEvidenceDigest"],
            "tlsPackageDigest": value["tlsPackageDigest"],
            "payloadDigest": value["payloadDigest"],
            "envelopeDigest": value["envelopeDigest"],
            "trustBundleDigest": value["trustBundleDigest"],
            "trustGeneration": value["trustGeneration"],
            "revocationSnapshotDigest": value["revocationSnapshotDigest"],
            "revocationGeneration": value["revocationGeneration"],
            "trustedTimeChallengeDigest": value["trustedTimeChallengeDigest"],
            "requestDigest": sha256(raw).hexdigest(),
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
            raise refusal("tls_material_verification_request") from None

    def canonical_request(
        evidence_raw, package_raw, payload_raw, envelope_raw, value
    ):
        return invoke(
            canonical_impl, evidence_raw, package_raw, payload_raw, envelope_raw,
            value,
        )

    def decode(evidence_raw, package_raw, payload_raw, envelope_raw, raw):
        return invoke(
            decode_impl, evidence_raw, package_raw, payload_raw, envelope_raw,
            raw,
        )

    return canonical_request, decode


canonical_request, decode = _make_codec()
del _make_codec
