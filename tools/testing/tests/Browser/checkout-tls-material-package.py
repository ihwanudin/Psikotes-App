"""Pure structural codec for candidate TLS-material packaging.

The codec binds public TLS evidence to fixed, relative, disposable destinations.
It does not access a filesystem, materialize files or keys, authenticate trust,
consume replay state, verify freshness or signatures, or authorize a launch.
"""

from __future__ import annotations

import hashlib
import importlib.util
import json
import re
from pathlib import Path
from types import MappingProxyType


MAX_PACKAGE_BYTES = 16 * 1024
SCHEMA = "checkout-tls-material-package"
PACKAGE_DOMAIN = b"oncam.checkout.tls-material-package.v1\0"


class TlsMaterialPackageRefused(Exception):
    """Fixed refusal without evidence, identity, path, or dependency details."""


__all__ = (
    "TlsMaterialPackageRefused",
    "canonical_package",
    "decode",
)


def _load_tls_codec():
    location = Path(__file__).resolve().with_name("checkout-tls-material-evidence.py")
    spec = importlib.util.spec_from_file_location(
        "checkout_tls_material_evidence_for_package", location
    )
    if spec is None or spec.loader is None:
        raise RuntimeError("tls_material_package")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


_TLS = _load_tls_codec()
del _load_tls_codec


def _make_codec():
    refusal = TlsMaterialPackageRefused
    proxy_type = MappingProxyType
    maximum = MAX_PACKAGE_BYTES
    schema = SCHEMA
    domain = PACKAGE_DOMAIN
    public_surface = __all__
    tls_module = _TLS
    tls_decode = tls_module.decode
    package_keys = frozenset({
        "version", "schema", "packageId", "generation", "tlsArtifactId",
        "tlsReplayId", "tlsArtifactDigest", "tlsEvidenceDigest",
        "runIdentityDigest", "leaseIdentityDigest", "evidenceDestination",
        "profileDestination", "packageDigest",
    })
    evidence_destination_keys = frozenset({
        "content", "disposition", "relativePath",
    })
    profile_destination_keys = frozenset({
        "disposable", "disposition", "mode", "relativePath",
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

    def callable_state(function):
        defaults = getattr(function, "__defaults__", None)
        kwdefaults = getattr(function, "__kwdefaults__", None)
        return (
            function, type(function), getattr(function, "__code__", None),
            defaults, frozen(defaults), kwdefaults,
            None if kwdefaults is None else tuple(
                (key, frozen(value)) for key, value in kwdefaults.items()
            ),
            getattr(function, "__closure__", None),
            getattr(function, "__globals__", None),
        )

    dependencies = (
        (json_module, "dumps", callable_state(json_dumps)),
        (json_module, "loads", callable_state(json_loads)),
        (hashlib_module, "sha256", callable_state(sha256)),
        (re_module, "fullmatch", callable_state(re_fullmatch)),
        (tls_module, "decode", callable_state(tls_decode)),
    )
    global_pins = (
        ("MAX_PACKAGE_BYTES", maximum), ("SCHEMA", schema),
        ("PACKAGE_DOMAIN", domain), ("TlsMaterialPackageRefused", refusal),
        ("MappingProxyType", proxy_type), ("__all__", public_surface),
        ("json", json_module), ("hashlib", hashlib_module),
        ("re", re_module), ("_TLS", tls_module),
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
                        or (
                            None if getattr(current, "__kwdefaults__", None) is None
                            else tuple((key, frozen(value)) for key, value
                                       in current.__kwdefaults__.items())
                        ) != expected[6] \
                        or getattr(current, "__closure__", None) is not expected[7] \
                        or getattr(current, "__globals__", None) is not expected[8]:
                    raise ValueError("dependency")
        except refusal:
            raise
        except Exception:
            raise refusal("tls_material_package") from None

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

    def digest(value):
        return type(value) is str and len(value) == 64 \
            and re_fullmatch(r"[a-f0-9]{64}", value) is not None

    def identifier(value):
        return type(value) is str \
            and re_fullmatch(identifier_pattern, value) is not None

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

    def validate(evidence, value):
        evidence_destination = value.get("evidenceDestination") \
            if type(value) is dict else None
        profile_destination = value.get("profileDestination") \
            if type(value) is dict else None
        if not exact_dict(value, package_keys) \
                or type(value["version"]) is not int or value["version"] != 1 \
                or type(value["schema"]) is not str \
                or value["schema"] != schema \
                or not identifier(value["packageId"]) \
                or type(value["generation"]) is not int \
                or not 1 <= value["generation"] < (1 << 63) \
                or value["generation"] != evidence["generation"] \
                or not identifier(value["tlsArtifactId"]) \
                or value["tlsArtifactId"] != evidence["artifactId"] \
                or not identifier(value["tlsReplayId"]) \
                or value["tlsReplayId"] != evidence["replayId"] \
                or not digest(value["tlsArtifactDigest"]) \
                or value["tlsArtifactDigest"] != evidence["artifactDigest"] \
                or not digest(value["tlsEvidenceDigest"]) \
                or value["tlsEvidenceDigest"] != evidence["evidenceDigest"] \
                or not digest(value["runIdentityDigest"]) \
                or value["runIdentityDigest"] != evidence["runIdentityDigest"] \
                or not digest(value["leaseIdentityDigest"]) \
                or value["leaseIdentityDigest"] != evidence["leaseIdentityDigest"] \
                or not exact_dict(evidence_destination, evidence_destination_keys) \
                or type(evidence_destination["content"]) is not str \
                or evidence_destination["content"] != "canonical-public-evidence" \
                or type(evidence_destination["disposition"]) is not str \
                or evidence_destination["disposition"] != "create-new" \
                or type(evidence_destination["relativePath"]) is not str \
                or evidence_destination["relativePath"] \
                != "tls/tls-material-evidence.json" \
                or not exact_dict(profile_destination, profile_destination_keys) \
                or type(profile_destination["disposable"]) is not bool \
                or profile_destination["disposable"] is not True \
                or type(profile_destination["disposition"]) is not str \
                or profile_destination["disposition"] != "create-new" \
                or type(profile_destination["mode"]) is not str \
                or profile_destination["mode"] != "persistent-context" \
                or type(profile_destination["relativePath"]) is not str \
                or profile_destination["relativePath"] != "browser-profile" \
                or not digest(value["packageDigest"]):
            raise ValueError("schema")
        predecessor = dict(value)
        package_digest = predecessor.pop("packageDigest")
        if package_digest != sha256(domain + json_bytes(predecessor)).hexdigest():
            raise ValueError("package_digest")

    def validated_evidence(raw):
        evidence = tls_decode(raw)
        if type(evidence) is not proxy_type \
                or evidence.get("structuralOnly") is not True:
            raise ValueError("evidence")
        return evidence

    def canonical_impl(evidence_raw, value):
        evidence = validated_evidence(evidence_raw)
        validate(evidence, value)
        raw = json_bytes(value)
        if len(raw) > maximum:
            raise ValueError("size")
        return raw

    def decode_impl(evidence_raw, package_raw):
        evidence = validated_evidence(evidence_raw)
        value = parse(package_raw)
        validate(evidence, value)
        return proxy_type({
            "structuralOnly": True,
            "schema": value["schema"],
            "packageId": value["packageId"],
            "generation": value["generation"],
            "tlsArtifactId": value["tlsArtifactId"],
            "tlsReplayId": value["tlsReplayId"],
            "tlsArtifactDigest": value["tlsArtifactDigest"],
            "tlsEvidenceDigest": value["tlsEvidenceDigest"],
            "runIdentityDigest": value["runIdentityDigest"],
            "leaseIdentityDigest": value["leaseIdentityDigest"],
            "evidenceRelativePath": value["evidenceDestination"]["relativePath"],
            "evidenceDisposition": value["evidenceDestination"]["disposition"],
            "profileRelativePath": value["profileDestination"]["relativePath"],
            "profileDisposition": value["profileDestination"]["disposition"],
            "profileMode": value["profileDestination"]["mode"],
            "profileDisposable": value["profileDestination"]["disposable"],
            "packageDigest": value["packageDigest"],
            "packageArtifactDigest": sha256(package_raw).hexdigest(),
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
            raise refusal("tls_material_package") from None

    def canonical_package(evidence_raw, value):
        return invoke(canonical_impl, evidence_raw, value)

    def decode(evidence_raw, package_raw):
        return invoke(decode_impl, evidence_raw, package_raw)

    return canonical_package, decode


canonical_package, decode = _make_codec()
del _make_codec
