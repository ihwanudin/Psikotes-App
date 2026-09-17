"""Pure structural ADR-025 TLS evidence and browser-config binding codec.

This module validates only caller-supplied canonical bytes.  It does not sign,
authenticate, materialize TLS keys, inspect a filesystem, launch a browser, or
grant preparation, composition, or runtime authority.
"""

from __future__ import annotations

import base64
import datetime
import hashlib
import json
import re
from types import MappingProxyType


MAX_EVIDENCE_BYTES = 32 * 1024
MAX_BROWSER_CONFIG_BYTES = 16 * 1024
MAX_ARTIFACT_LIFETIME_SECONDS = 7 * 24 * 60 * 60
ROLE = "tls-material"
PUBLIC_ORIGIN = "https://psikotes.oncam.id"
EVIDENCE_DOMAIN = b"oncam.checkout.tls-material-evidence.v1\0"
BROWSER_CONFIG_DOMAIN = b"oncam.checkout.browser-tls-config.v1\0"
STATIC_BROWSER_ARGS = (
    "--host-resolver-rules=MAP psikotes.oncam.id 127.0.0.1,MAP oncam.id 127.0.0.1,MAP * ~NOTFOUND",
    "--no-proxy-server",
    "--disable-background-networking",
)


class TlsMaterialEvidenceRefused(Exception):
    """Fixed refusal without certificate, host, path, or dependency details."""


__all__ = (
    "TlsMaterialEvidenceRefused",
    "canonical_evidence",
    "decode",
    "canonical_browser_config",
    "bind_browser_config",
)


def _make_codec():
    refusal = TlsMaterialEvidenceRefused
    proxy_type = MappingProxyType
    max_evidence = MAX_EVIDENCE_BYTES
    max_config = MAX_BROWSER_CONFIG_BYTES
    max_lifetime = MAX_ARTIFACT_LIFETIME_SECONDS
    role = ROLE
    origin = PUBLIC_ORIGIN
    evidence_domain = EVIDENCE_DOMAIN
    config_domain = BROWSER_CONFIG_DOMAIN
    static_args = STATIC_BROWSER_ARGS
    public_surface = __all__
    evidence_keys = frozenset({
        "version", "role", "issuerId", "artifactId", "generation",
        "issuedAt", "expiresAt", "replayId", "requestDigest",
        "preparationBindingDigest", "runtimeEvidenceDigest",
        "runtimeConfigurationPolicyDigest", "runIdentityDigest",
        "leaseIdentityDigest", "certificateSha256", "spkiSha256",
        "serialHex", "publicKeyAlgorithm", "signatureAlgorithm",
        "subjectDigest", "sanPolicy", "notBefore", "notAfter",
        "certificatePolicyDigest", "evidenceDigest",
    })
    digest_keys = (
        "requestDigest", "preparationBindingDigest", "runtimeEvidenceDigest",
        "runtimeConfigurationPolicyDigest", "runIdentityDigest",
        "leaseIdentityDigest", "certificateSha256", "spkiSha256",
        "subjectDigest", "certificatePolicyDigest", "evidenceDigest",
    )
    config_keys = frozenset({
        "version", "schema", "publicOrigin", "publicTlsEvidenceDigest",
        "runtimeConfigurationPolicyDigest", "runIdentityDigest",
        "launchArguments", "transportException", "processProfile",
        "contextOptions",
    })
    transport_keys = frozenset({"argument", "spkiSha256"})
    profile_keys = frozenset({
        "disposable", "mode", "relativeName", "runIdentityDigest"
    })
    context_keys = frozenset({"ignoreHTTPSErrors", "serviceWorkers"})
    identifier_pattern = r"[a-z0-9](?:[a-z0-9._-]{0,63})"
    timestamp_pattern = r"[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z"

    json_module = json
    hashlib_module = hashlib
    base64_module = base64
    re_module = re
    datetime_module = datetime
    json_dumps = json.dumps
    json_loads = json.loads
    sha256 = hashlib.sha256
    b64encode = base64.b64encode
    re_fullmatch = re.fullmatch
    datetime_class = datetime.datetime
    datetime_strptime_descriptor = datetime_class.__dict__["strptime"]
    datetime_strptime = datetime_class.strptime
    timedelta_class = datetime.timedelta
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
        (base64_module, "b64encode", callable_state(b64encode)),
        (re_module, "fullmatch", callable_state(re_fullmatch)),
    )
    global_pins = (
        ("MAX_EVIDENCE_BYTES", max_evidence),
        ("MAX_BROWSER_CONFIG_BYTES", max_config),
        ("MAX_ARTIFACT_LIFETIME_SECONDS", max_lifetime),
        ("ROLE", role), ("PUBLIC_ORIGIN", origin),
        ("EVIDENCE_DOMAIN", evidence_domain),
        ("BROWSER_CONFIG_DOMAIN", config_domain),
        ("STATIC_BROWSER_ARGS", static_args),
        ("TlsMaterialEvidenceRefused", refusal),
        ("MappingProxyType", proxy_type), ("__all__", public_surface),
        ("json", json_module), ("hashlib", hashlib_module),
        ("base64", base64_module), ("re", re_module),
        ("datetime", datetime_module),
    )

    def guard():
        try:
            for name, expected in global_pins:
                if module_globals.get(name) is not expected:
                    raise ValueError("authority")
            if datetime_module.datetime is not datetime_class \
                    or datetime_class.__dict__.get("strptime") \
                    is not datetime_strptime_descriptor \
                    or datetime_module.timedelta is not timedelta_class:
                raise ValueError("dependency")
            for module, name, expected in dependencies:
                current = getattr(module, name, None)
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
            raise refusal("tls_material_evidence") from None

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

    def positive_int63(value):
        return type(value) is int and 1 <= value < (1 << 63)

    def timestamp(value):
        if type(value) is not str or re_fullmatch(timestamp_pattern, value) is None:
            raise ValueError("timestamp")
        parsed = datetime_strptime(value, "%Y-%m-%dT%H:%M:%SZ")
        if parsed.strftime("%Y-%m-%dT%H:%M:%SZ") != value:
            raise ValueError("timestamp")
        return parsed

    def validate_evidence(value):
        if not exact_dict(value, evidence_keys) \
                or type(value["version"]) is not int or value["version"] != 1 \
                or type(value["role"]) is not str or value["role"] != role \
                or not identifier(value["issuerId"]) \
                or not identifier(value["artifactId"]) \
                or not identifier(value["replayId"]) \
                or not positive_int63(value["generation"]) \
                or any(not digest(value[key]) for key in digest_keys) \
                or type(value["serialHex"]) is not str \
                or re_fullmatch(r"[1-9a-f][0-9a-f]*", value["serialHex"]) is None \
                or value["publicKeyAlgorithm"] != "rsa-3072" \
                or type(value["publicKeyAlgorithm"]) is not str \
                or value["signatureAlgorithm"] != "sha256WithRSAEncryption" \
                or type(value["signatureAlgorithm"]) is not str \
                or value["sanPolicy"] != "psikotes-two-dns-v1" \
                or type(value["sanPolicy"]) is not str:
            raise ValueError("schema")
        issued = timestamp(value["issuedAt"])
        expires = timestamp(value["expiresAt"])
        not_before = timestamp(value["notBefore"])
        not_after = timestamp(value["notAfter"])
        if not_before != issued - timedelta_class(minutes=5) \
                or not issued < expires <= not_after \
                or not 0 < (expires - issued).total_seconds() <= max_lifetime \
                or not issued < not_after <= issued + timedelta_class(hours=23, minutes=55) \
                or (not_after - not_before).total_seconds() > 24 * 60 * 60:
            raise ValueError("lifetime")
        predecessor = dict(value)
        evidence_digest = predecessor.pop("evidenceDigest")
        if evidence_digest != sha256(evidence_domain + json_bytes(predecessor)).hexdigest():
            raise ValueError("evidence_digest")

    def parse(raw, maximum):
        if type(raw) is not bytes or not 1 <= len(raw) <= maximum \
                or not raw.endswith(b"\n") or raw.endswith(b"\n\n"):
            raise ValueError("raw")
        value = json_loads(
            raw.decode("ascii"), object_pairs_hook=strict_object,
            parse_constant=lambda _value: (_ for _ in ()).throw(ValueError("constant")),
        )
        if json_bytes(value) != raw:
            raise ValueError("canonical")
        return value

    def evidence_result(value, raw):
        return proxy_type({
            "structuralOnly": True,
            "role": value["role"],
            "issuerId": value["issuerId"],
            "artifactId": value["artifactId"],
            "generation": value["generation"],
            "issuedAt": value["issuedAt"],
            "expiresAt": value["expiresAt"],
            "replayId": value["replayId"],
            "requestDigest": value["requestDigest"],
            "runtimeConfigurationPolicyDigest": value[
                "runtimeConfigurationPolicyDigest"
            ],
            "runIdentityDigest": value["runIdentityDigest"],
            "leaseIdentityDigest": value["leaseIdentityDigest"],
            "certificateSha256": value["certificateSha256"],
            "spkiSha256": value["spkiSha256"],
            "certificatePolicyDigest": value["certificatePolicyDigest"],
            "evidenceDigest": value["evidenceDigest"],
            "artifactDigest": sha256(raw).hexdigest(),
        })

    def validated_evidence(raw):
        value = parse(raw, max_evidence)
        validate_evidence(value)
        return value

    def expected_spki_argument(spki_hex):
        encoded = b64encode(bytes.fromhex(spki_hex)).decode("ascii")
        return "--ignore-certificate-errors-spki-list=" + encoded

    def validate_config(evidence, value):
        if not exact_dict(value, config_keys) \
                or type(value["version"]) is not int or value["version"] != 1 \
                or type(value["schema"]) is not str \
                or value["schema"] != "checkout-browser-tls-config" \
                or type(value["publicOrigin"]) is not str \
                or value["publicOrigin"] != origin \
                or not digest(value["publicTlsEvidenceDigest"]) \
                or value["publicTlsEvidenceDigest"] != evidence["evidenceDigest"] \
                or not digest(value["runtimeConfigurationPolicyDigest"]) \
                or value["runtimeConfigurationPolicyDigest"] \
                != evidence["runtimeConfigurationPolicyDigest"] \
                or not digest(value["runIdentityDigest"]) \
                or value["runIdentityDigest"] != evidence["runIdentityDigest"] \
                or type(value["launchArguments"]) is not list \
                or any(type(item) is not str for item in value["launchArguments"]) \
                or tuple(value["launchArguments"]) != static_args \
                or not exact_dict(value["transportException"], transport_keys) \
                or not digest(value["transportException"]["spkiSha256"]) \
                or value["transportException"]["spkiSha256"] \
                != evidence["spkiSha256"] \
                or type(value["transportException"]["argument"]) is not str \
                or value["transportException"]["argument"] \
                != expected_spki_argument(evidence["spkiSha256"]) \
                or not exact_dict(value["processProfile"], profile_keys) \
                or value["processProfile"]["disposable"] is not True \
                or type(value["processProfile"]["disposable"]) is not bool \
                or value["processProfile"]["mode"] != "persistent-context" \
                or type(value["processProfile"]["mode"]) is not str \
                or value["processProfile"]["relativeName"] != "browser-profile" \
                or type(value["processProfile"]["relativeName"]) is not str \
                or not digest(value["processProfile"]["runIdentityDigest"]) \
                or value["processProfile"]["runIdentityDigest"] \
                != evidence["runIdentityDigest"] \
                or not exact_dict(value["contextOptions"], context_keys) \
                or value["contextOptions"]["ignoreHTTPSErrors"] is not False \
                or type(value["contextOptions"]["ignoreHTTPSErrors"]) is not bool \
                or value["contextOptions"]["serviceWorkers"] != "block" \
                or type(value["contextOptions"]["serviceWorkers"]) is not str:
            raise ValueError("browser_config")

    def canonical_evidence_impl(value):
        validate_evidence(value)
        raw = json_bytes(value)
        if len(raw) > max_evidence:
            raise ValueError("size")
        return raw

    def decode_impl(raw):
        value = validated_evidence(raw)
        return evidence_result(value, raw)

    def canonical_config_impl(evidence_raw, value):
        evidence = validated_evidence(evidence_raw)
        validate_config(evidence, value)
        raw = json_bytes(value)
        if len(raw) > max_config:
            raise ValueError("size")
        return raw

    def bind_config_impl(evidence_raw, config_raw):
        evidence = validated_evidence(evidence_raw)
        value = parse(config_raw, max_config)
        validate_config(evidence, value)
        effective_args = [*value["launchArguments"],
                          value["transportException"]["argument"]]
        return proxy_type({
            "structuralOnly": True,
            "publicOrigin": value["publicOrigin"],
            "publicTlsEvidenceDigest": value["publicTlsEvidenceDigest"],
            "runtimeConfigurationPolicyDigest": value[
                "runtimeConfigurationPolicyDigest"
            ],
            "runIdentityDigest": value["runIdentityDigest"],
            "spkiSha256": value["transportException"]["spkiSha256"],
            "profileRelativeName": value["processProfile"]["relativeName"],
            "profileMode": value["processProfile"]["mode"],
            "profileDisposable": value["processProfile"]["disposable"],
            "ignoreHTTPSErrors": value["contextOptions"]["ignoreHTTPSErrors"],
            "effectiveArgumentCount": len(effective_args),
            "effectiveArgumentsDigest": sha256(json_bytes(effective_args)).hexdigest(),
            "browserConfigDigest": sha256(config_domain + config_raw).hexdigest(),
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
            raise refusal("tls_material_evidence") from None

    def canonical_evidence(value):
        return invoke(canonical_evidence_impl, value)

    def decode(raw):
        return invoke(decode_impl, raw)

    def canonical_browser_config(evidence_raw, value):
        return invoke(canonical_config_impl, evidence_raw, value)

    def bind_browser_config(evidence_raw, config_raw):
        return invoke(bind_config_impl, evidence_raw, config_raw)

    return canonical_evidence, decode, canonical_browser_config, bind_browser_config


canonical_evidence, decode, canonical_browser_config, bind_browser_config = _make_codec()
del _make_codec
