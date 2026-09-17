"""Pure structural codec for the ADR-021 trust-root bundle.

This module validates only supplied canonical bytes and derives digests.  It
does not authenticate keys, verify signatures or thresholds, bootstrap trust,
evaluate freshness/revocation/replay, authorize rotation, or grant admission.
Composition must pin the exported callables before using this module.
"""

from __future__ import annotations

import datetime
import hashlib
import json
import re
from types import MappingProxyType


MAX_BUNDLE_BYTES = 64 * 1024
ROLE = "trust-root-bundle"
ARTIFACT_ROLES = (
    "asset-review",
    "composition-admission",
    "preparation-authorization",
    "release-source",
    "runtime-configuration-policy",
    "tool-runtime-closure",
    "vendor-build",
)
ARTIFACT_ROLE_SEQUENCE = ("asset-review",) + ARTIFACT_ROLES


class TrustRootBundleRefused(Exception):
    """Fixed refusal without key, signer, or dependency details."""


__all__ = (
    "TrustRootBundleRefused",
    "canonical_bundle",
    "decode",
)


def _make_codec():
    refusal = TrustRootBundleRefused
    mapping_proxy_type = MappingProxyType
    maximum_bundle = MAX_BUNDLE_BYTES
    role = ROLE
    artifact_roles = ARTIFACT_ROLES
    artifact_role_sequence = ARTIFACT_ROLE_SEQUENCE
    public_surface = __all__
    identifier_pattern = r"[a-z0-9](?:[a-z0-9._-]{0,63})"
    timestamp_pattern = (
        r"[0-9]{4}-[0-9]{2}-[0-9]{2}T"
        r"[0-9]{2}:[0-9]{2}:[0-9]{2}Z"
    )
    top_keys = frozenset({
        "artifactId",
        "artifactIssuerKeys",
        "expiresAt",
        "issuedAt",
        "issuerId",
        "offlineRootCustodians",
        "replayId",
        "revocationCustodians",
        "role",
        "rotation",
        "trustGeneration",
        "version",
    })
    artifact_key_keys = frozenset({
        "issuerId", "keyGeneration", "keyId", "publicKey", "role"
    })
    custodian_keys = frozenset({
        "custodianId", "keyGeneration", "keyId", "publicKey"
    })
    rotation_keys = frozenset({
        "newRootSignatureEnvelopes",
        "newTrustGeneration",
        "oldRootSignatureEnvelopes",
        "priorBundleDigest",
        "priorTrustGeneration",
    })
    signature_reference_keys = frozenset({"envelopeDigest", "keyId"})
    domain = b"oncam.checkout.trust-root-bundle.v1\0"

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
        ("MAX_BUNDLE_BYTES", maximum_bundle),
        ("ROLE", role),
        ("ARTIFACT_ROLES", artifact_roles),
        ("ARTIFACT_ROLE_SEQUENCE", artifact_role_sequence),
        ("TrustRootBundleRefused", refusal),
        ("MappingProxyType", mapping_proxy_type),
        ("__all__", public_surface),
        ("json", json_module),
        ("hashlib", hashlib_module),
        ("re", re_module),
        ("datetime", datetime_module),
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
            raise refusal("trust_root_bundle") from None

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

    def exact_dict(value, keys):
        return type(value) is dict \
            and all(type(key) is str for key in value) \
            and set(value) == keys

    def identifier(value):
        return type(value) is str \
            and re_fullmatch(identifier_pattern, value) is not None

    def positive_int63(value):
        return type(value) is int and 1 <= value < (1 << 63)

    def lowercase_hex(value):
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

    def artifact_key(value, expected_role):
        if not exact_dict(value, artifact_key_keys) \
                or type(value["role"]) is not str \
                or value["role"] != expected_role \
                or not identifier(value["issuerId"]) \
                or not identifier(value["keyId"]) \
                or not positive_int63(value["keyGeneration"]) \
                or not lowercase_hex(value["publicKey"]):
            raise ValueError("artifact_key")
        return (
            value["role"], value["issuerId"], value["keyId"],
            value["keyGeneration"], value["publicKey"],
        )

    def custodian_key(value):
        if not exact_dict(value, custodian_keys) \
                or not identifier(value["custodianId"]) \
                or not identifier(value["keyId"]) \
                or not positive_int63(value["keyGeneration"]) \
                or not lowercase_hex(value["publicKey"]):
            raise ValueError("custodian_key")
        return (
            value["custodianId"], value["keyId"],
            value["keyGeneration"], value["publicKey"],
        )

    def ordered_custodians(value):
        if type(value) is not list or len(value) != 3:
            raise ValueError("custodians")
        records = tuple(custodian_key(item) for item in value)
        if tuple(item[0] for item in records) \
                != tuple(sorted(item[0] for item in records)):
            raise ValueError("custodians")
        return records

    def signature_references(value):
        if type(value) is not list or len(value) != 2:
            raise ValueError("signatures")
        result = []
        for item in value:
            if not exact_dict(item, signature_reference_keys) \
                    or not identifier(item["keyId"]) \
                    or not lowercase_hex(item["envelopeDigest"]):
                raise ValueError("signatures")
            result.append((item["keyId"], item["envelopeDigest"]))
        records = tuple(result)
        if tuple(item[0] for item in records) \
                != tuple(sorted(set(item[0] for item in records))) \
                or len(set(item[1] for item in records)) != len(records):
            raise ValueError("signatures")
        return records

    def rotation(value, trust_generation, root_key_ids):
        if value is None:
            if trust_generation != 1:
                raise ValueError("rotation")
            return None
        if trust_generation == 1:
            raise ValueError("rotation")
        if not exact_dict(value, rotation_keys) \
                or not positive_int63(value["priorTrustGeneration"]) \
                or not positive_int63(value["newTrustGeneration"]) \
                or value["priorTrustGeneration"] >= value["newTrustGeneration"] \
                or value["newTrustGeneration"] != trust_generation \
                or not lowercase_hex(value["priorBundleDigest"]):
            raise ValueError("rotation")
        old_references = signature_references(
            value["oldRootSignatureEnvelopes"]
        )
        new_references = signature_references(
            value["newRootSignatureEnvelopes"]
        )
        if not set(item[0] for item in new_references).issubset(root_key_ids):
            raise ValueError("rotation")
        all_envelope_digests = tuple(
            item[1] for item in old_references + new_references
        )
        if len(set(all_envelope_digests)) != 4:
            raise ValueError("rotation")
        return (
            value["priorTrustGeneration"],
            value["newTrustGeneration"],
            value["priorBundleDigest"],
            old_references,
            new_references,
        )

    def validate(value):
        if not exact_dict(value, top_keys) \
                or type(value["version"]) is not int or value["version"] != 1 \
                or type(value["role"]) is not str or value["role"] != role \
                or not identifier(value["issuerId"]) \
                or not identifier(value["artifactId"]) \
                or not identifier(value["replayId"]) \
                or not positive_int63(value["trustGeneration"]):
            raise ValueError("bundle")
        issued_at = parse_timestamp(value["issuedAt"])
        expires_at = parse_timestamp(value["expiresAt"])
        if issued_at >= expires_at:
            raise ValueError("lifetime")
        if type(value["artifactIssuerKeys"]) is not list \
                or len(value["artifactIssuerKeys"]) \
                != len(artifact_role_sequence):
            raise ValueError("artifact_keys")
        artifact_keys = tuple(
            artifact_key(item, expected_role)
            for item, expected_role in zip(
                value["artifactIssuerKeys"], artifact_role_sequence
            )
        )
        asset_reviewers = artifact_keys[:2]
        if asset_reviewers[0][1] >= asset_reviewers[1][1]:
            raise ValueError("asset_review")
        offline_roots = ordered_custodians(value["offlineRootCustodians"])
        revocation_custodians = ordered_custodians(
            value["revocationCustodians"]
        )
        all_records = artifact_keys + offline_roots + revocation_custodians
        key_ids = tuple(item[2] for item in artifact_keys) \
            + tuple(item[1] for item in offline_roots + revocation_custodians)
        public_keys = tuple(item[4] for item in artifact_keys) \
            + tuple(item[3] for item in offline_roots + revocation_custodians)
        principals = tuple(item[1] for item in artifact_keys) \
            + tuple(item[0] for item in offline_roots + revocation_custodians)
        if len(set(key_ids)) != len(all_records) \
                or len(set(public_keys)) != len(all_records) \
                or len(set(principals)) != len(all_records):
            raise ValueError("separation")
        rotation_value = rotation(
            value["rotation"],
            value["trustGeneration"],
            frozenset(item[1] for item in offline_roots),
        )
        return artifact_keys, offline_roots, revocation_custodians, rotation_value

    def canonical_impl(value):
        validate(value)
        raw = json_bytes(value)
        if len(raw) > maximum_bundle:
            raise ValueError("size")
        return raw

    def decode_impl(raw):
        if type(raw) is not bytes or not 1 <= len(raw) <= maximum_bundle \
                or not raw.endswith(b"\n") or raw.endswith(b"\n\n"):
            raise ValueError("raw")
        value = json_loads(
            raw.decode("ascii"),
            object_pairs_hook=strict_object,
            parse_constant=lambda _value: (_ for _ in ()).throw(
                ValueError("constant")
            ),
        )
        artifact_keys, roots, revocation, rotation_value = validate(value)
        if json_bytes(value) != raw:
            raise ValueError("canonical")
        digest = sha256(raw).hexdigest()
        return mapping_proxy_type({
            "structuralOnly": True,
            "role": role,
            "issuerId": value["issuerId"],
            "artifactId": value["artifactId"],
            "trustGeneration": value["trustGeneration"],
            "issuedAt": value["issuedAt"],
            "expiresAt": value["expiresAt"],
            "replayId": value["replayId"],
            "artifactIssuerKeys": artifact_keys,
            "offlineRootCustodians": roots,
            "revocationCustodians": revocation,
            "rotation": rotation_value,
            "artifactDigest": digest,
            "trustBundleDigest": sha256(domain + raw).hexdigest(),
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
            raise refusal("trust_root_bundle") from None

    def canonical_bundle(value):
        return invoke(canonical_impl, value)

    def decode(raw):
        return invoke(decode_impl, raw)

    return canonical_bundle, decode


canonical_bundle, decode = _make_codec()
del _make_codec
