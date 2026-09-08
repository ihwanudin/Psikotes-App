"""Pure structural future-v2 envelope codec for TLS-material artifacts.

This module validates canonical payload and detached Ed25519 field shape only.
It does not access a filesystem, sign or verify signatures, establish trust or
freshness, consume replay state, materialize secrets, or authorize runtime use.
"""

from __future__ import annotations

import hashlib
import importlib.util
import json
import re
from pathlib import Path
from types import MappingProxyType


MAX_PAYLOAD_BYTES = 16 * 1024
MAX_ENVELOPE_BYTES = 4096
PAYLOAD_VERSION = 2
ROLE = "tls-material"
SIGNING_DOMAIN = b"oncam.checkout.tls-material.v1\0"


class TlsMaterialEnvelopeV2Refused(Exception):
    """Fixed refusal without payload, signer, path, or dependency details."""


__all__ = (
    "TlsMaterialEnvelopeV2Refused",
    "canonical_payload",
    "canonical_envelope",
    "decode",
)


def _load_sibling(name, filename):
    spec = importlib.util.spec_from_file_location(
        name, Path(__file__).resolve().with_name(filename)
    )
    if spec is None or spec.loader is None:
        raise RuntimeError("tls_material_envelope_v2")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


_EVIDENCE = _load_sibling(
    "checkout_tls_material_evidence_for_envelope_v2",
    "checkout-tls-material-evidence.py",
)
_PACKAGE = _load_sibling(
    "checkout_tls_material_package_for_envelope_v2",
    "checkout-tls-material-package.py",
)
del _load_sibling


def _make_codec():
    refusal = TlsMaterialEnvelopeV2Refused
    proxy_type = MappingProxyType
    maximum_payload = MAX_PAYLOAD_BYTES
    maximum_envelope = MAX_ENVELOPE_BYTES
    payload_version = PAYLOAD_VERSION
    role = ROLE
    signing_domain = SIGNING_DOMAIN
    public_surface = __all__
    evidence_module = _EVIDENCE
    package_module = _PACKAGE
    evidence_decode = evidence_module.decode
    package_decode = package_module.decode
    payload_keys = frozenset({
        "version", "role", "artifactId", "generation", "replayId",
        "runIdentityDigest", "leaseIdentityDigest", "tlsArtifactDigest",
        "tlsEvidenceDigest", "tlsPackageArtifactDigest", "tlsPackageDigest",
    })
    envelope_keys = frozenset({
        "version", "algorithm", "role", "issuerId", "keyId",
        "keyGeneration", "trustGeneration", "artifactDigest", "signature",
    })
    identifier_pattern = r"[a-z0-9](?:[a-z0-9._-]{0,63})"

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

    def callable_state(function):
        defaults = getattr(function, "__defaults__", None)
        kwdefaults = getattr(function, "__kwdefaults__", None)
        return (
            function, type(function), getattr(function, "__code__", None),
            defaults, frozen(defaults), kwdefaults,
            mapping_snapshot(kwdefaults), getattr(function, "__closure__", None),
            getattr(function, "__globals__", None),
        )

    dependencies = (
        (json_module, "dumps", callable_state(json_dumps)),
        (json_module, "loads", callable_state(json_loads)),
        (hashlib_module, "sha256", callable_state(sha256)),
        (re_module, "fullmatch", callable_state(re_fullmatch)),
        (evidence_module, "decode", callable_state(evidence_decode)),
        (package_module, "decode", callable_state(package_decode)),
    )
    global_pins = (
        ("MAX_PAYLOAD_BYTES", maximum_payload),
        ("MAX_ENVELOPE_BYTES", maximum_envelope),
        ("PAYLOAD_VERSION", payload_version), ("ROLE", role),
        ("SIGNING_DOMAIN", signing_domain),
        ("TlsMaterialEnvelopeV2Refused", refusal),
        ("MappingProxyType", proxy_type), ("__all__", public_surface),
        ("json", json_module), ("hashlib", hashlib_module), ("re", re_module),
        ("_EVIDENCE", evidence_module), ("_PACKAGE", package_module),
    )

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
            raise refusal("tls_material_envelope_v2") from None

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

    def positive_int63(value):
        return type(value) is int and 1 <= value < (1 << 63)

    def lowercase_hex(value, length):
        return type(value) is str and len(value) == length \
            and re_fullmatch(r"[a-f0-9]+", value) is not None

    def parse(raw, maximum):
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

    def validate_sources(evidence_raw, package_raw):
        evidence = evidence_decode(evidence_raw)
        package = package_decode(evidence_raw, package_raw)
        if type(evidence) is not proxy_type or type(package) is not proxy_type \
                or evidence.get("structuralOnly") is not True \
                or package.get("structuralOnly") is not True:
            raise ValueError("sources")
        return evidence, package

    def validate_payload(evidence, package, value):
        if not exact_dict(value, payload_keys) \
                or type(value["version"]) is not int \
                or value["version"] != payload_version \
                or type(value["role"]) is not str or value["role"] != role \
                or not identifier(value["artifactId"]) \
                or value["artifactId"] != evidence["artifactId"] \
                or not positive_int63(value["generation"]) \
                or value["generation"] != evidence["generation"] \
                or not identifier(value["replayId"]) \
                or value["replayId"] != evidence["replayId"] \
                or not lowercase_hex(value["runIdentityDigest"], 64) \
                or value["runIdentityDigest"] != evidence["runIdentityDigest"] \
                or not lowercase_hex(value["leaseIdentityDigest"], 64) \
                or value["leaseIdentityDigest"] != evidence["leaseIdentityDigest"] \
                or not lowercase_hex(value["tlsArtifactDigest"], 64) \
                or value["tlsArtifactDigest"] != evidence["artifactDigest"] \
                or not lowercase_hex(value["tlsEvidenceDigest"], 64) \
                or value["tlsEvidenceDigest"] != evidence["evidenceDigest"] \
                or not lowercase_hex(value["tlsPackageArtifactDigest"], 64) \
                or value["tlsPackageArtifactDigest"] \
                != package["packageArtifactDigest"] \
                or not lowercase_hex(value["tlsPackageDigest"], 64) \
                or value["tlsPackageDigest"] != package["packageDigest"]:
            raise ValueError("payload")

    def validate_envelope(value):
        if not exact_dict(value, envelope_keys) \
                or type(value["version"]) is not int \
                or value["version"] != payload_version \
                or type(value["algorithm"]) is not str \
                or value["algorithm"] != "ed25519" \
                or type(value["role"]) is not str or value["role"] != role \
                or not identifier(value["issuerId"]) \
                or not identifier(value["keyId"]) \
                or not positive_int63(value["keyGeneration"]) \
                or not positive_int63(value["trustGeneration"]) \
                or not lowercase_hex(value["artifactDigest"], 64) \
                or not lowercase_hex(value["signature"], 128):
            raise ValueError("envelope")

    def canonical_payload_impl(evidence_raw, package_raw, value):
        evidence, package = validate_sources(evidence_raw, package_raw)
        validate_payload(evidence, package, value)
        raw = json_bytes(value)
        if len(raw) > maximum_payload:
            raise ValueError("size")
        return raw

    def canonical_envelope_impl(value):
        validate_envelope(value)
        raw = json_bytes(value)
        if len(raw) > maximum_envelope:
            raise ValueError("size")
        return raw

    def decode_impl(evidence_raw, package_raw, payload_raw, envelope_raw):
        evidence, package = validate_sources(evidence_raw, package_raw)
        payload = parse(payload_raw, maximum_payload)
        validate_payload(evidence, package, payload)
        document = parse(envelope_raw, maximum_envelope)
        validate_envelope(document)
        artifact_digest = sha256(payload_raw).hexdigest()
        if document["artifactDigest"] != artifact_digest:
            raise ValueError("artifact_digest")
        return proxy_type({
            "structuralOnly": True,
            "role": role,
            "artifactId": payload["artifactId"],
            "generation": payload["generation"],
            "replayId": payload["replayId"],
            "runIdentityDigest": payload["runIdentityDigest"],
            "leaseIdentityDigest": payload["leaseIdentityDigest"],
            "tlsArtifactDigest": payload["tlsArtifactDigest"],
            "tlsEvidenceDigest": payload["tlsEvidenceDigest"],
            "tlsPackageArtifactDigest": payload["tlsPackageArtifactDigest"],
            "tlsPackageDigest": payload["tlsPackageDigest"],
            "issuerId": document["issuerId"],
            "keyId": document["keyId"],
            "keyGeneration": document["keyGeneration"],
            "trustGeneration": document["trustGeneration"],
            "artifactDigest": artifact_digest,
            "envelopeDigest": sha256(envelope_raw).hexdigest(),
            "signingMessageDigest": sha256(
                signing_domain + payload_raw
            ).hexdigest(),
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
            raise refusal("tls_material_envelope_v2") from None

    def canonical_payload(evidence_raw, package_raw, value):
        return invoke(canonical_payload_impl, evidence_raw, package_raw, value)

    def canonical_envelope(value):
        return invoke(canonical_envelope_impl, value)

    def decode(evidence_raw, package_raw, payload_raw, envelope_raw):
        return invoke(
            decode_impl, evidence_raw, package_raw, payload_raw, envelope_raw
        )

    return canonical_payload, canonical_envelope, decode


canonical_payload, canonical_envelope, decode = _make_codec()
del _make_codec
