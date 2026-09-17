"""Pure structural codec for future repository-verifier evidence.

This module validates supplied canonical bytes and their internal bindings.  It
does not perform cryptography, authenticate a verifier, establish trust or
freshness, consume replay state, or grant admission.  Composition must pin the
exported callables before treating even the structural result as input.
"""

from __future__ import annotations

import hashlib
import importlib.util
import json
from pathlib import Path
from types import MappingProxyType


MAX_EVIDENCE_BYTES = 64 * 1024
ROLES = (
    "asset-review",
    "composition-admission",
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
REFUSAL_CODES = (
    "artifact-refused",
    "envelope-refused",
    "revocation-refused",
    "signature-refused",
    "time-refused",
    "trust-refused",
)


class VerifierEvidenceRefused(Exception):
    """Fixed structural refusal without sensitive details."""


__all__ = ("VerifierEvidenceRefused", "canonical_evidence", "decode")


def _load_request_codec():
    path = Path(__file__).with_name("checkout-verifier-request.py")
    spec = importlib.util.spec_from_file_location(
        "_checkout_verifier_request_for_evidence", path
    )
    if spec is None or spec.loader is None:
        raise RuntimeError("request codec")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


_REQUEST = _load_request_codec()
del _load_request_codec


def _make_codec():
    refusal = VerifierEvidenceRefused
    maximum = MAX_EVIDENCE_BYTES
    roles = ROLES
    refusal_codes = REFUSAL_CODES
    public_surface = __all__
    mapping_proxy_type = MappingProxyType
    request_module = _REQUEST
    request_decode = request_module.decode
    request_decode_state = (
        request_decode.__code__,
        request_decode.__defaults__,
        request_decode.__kwdefaults__,
        request_decode.__closure__,
    )
    json_module = json
    hashlib_module = hashlib
    json_dumps = json.dumps
    json_loads = json.loads
    sha256 = hashlib.sha256
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
        return tuple(
            (key, freeze_metadata(item)) for key, item in value.items()
        )

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
    )

    top_keys = frozenset(
        {
            "bindings",
            "domainMessageDigest",
            "excludedReleaseSourceKeyId",
            "issuerId",
            "issuerKeyGeneration",
            "priorTrustGeneration",
            "quorumMode",
            "quorums",
            "refusalCode",
            "revocationTrustGeneration",
            "role",
            "signatureAlgorithm",
            "signaturePrimitiveResult",
            "status",
            "verifierRequestDigest",
            "version",
        }
    )
    quorum_keys = frozenset(
        {
            "eligibleSigners",
            "kind",
            "matchedSigners",
            "threshold",
            "trustGeneration",
        }
    )
    eligible_signer_keys = frozenset(
        {"keyId", "principalId", "publicKeyDigest"}
    )
    matched_signer_keys = eligible_signer_keys | frozenset(
        {"envelopeDigest", "signatureEvidenceDigest"}
    )
    binding_keys = frozenset(
        {
            "artifactDigest",
            "cryptographyAcquisitionEvidenceDigest",
            "cryptographyClosureDigest",
            "cryptographyGeneration",
            "currentHighWaterGeneration",
            "currentHighWaterStateDigest",
            "envelopeDigest",
            "proposedHighWaterGeneration",
            "proposedHighWaterStateDigest",
            "revocationSnapshotDigest",
            "snapshotGeneration",
            "trustBundleDigest",
            "trustGeneration",
            "trustedTimeInputSetDigest",
            "verifierSourceDigest",
        }
    )
    digest_binding_keys = binding_keys - frozenset(
        {
            "cryptographyGeneration",
            "currentHighWaterGeneration",
            "proposedHighWaterGeneration",
            "snapshotGeneration",
            "trustGeneration",
        }
    )

    pinned_globals = (
        ("MAX_EVIDENCE_BYTES", maximum),
        ("ROLES", roles),
        ("REFUSAL_CODES", refusal_codes),
        ("VerifierEvidenceRefused", refusal),
        ("__all__", public_surface),
        ("MappingProxyType", mapping_proxy_type),
        ("json", json_module),
        ("hashlib", hashlib_module),
        ("_REQUEST", request_module),
    )

    def request_authority_matches():
        current = getattr(request_module, "decode", None)
        if current is not request_decode or type(current) is not type(request_decode):
            return False
        return (
            current.__code__ is request_decode_state[0]
            and current.__defaults__ is request_decode_state[1]
            and current.__kwdefaults__ is request_decode_state[2]
            and current.__closure__ is request_decode_state[3]
        )

    def guard():
        for name, expected in pinned_globals:
            if module_globals.get(name) is not expected:
                raise ValueError("authority")
        for module, name, state in dependencies:
            current = getattr(module, name, None)
            if current is not state[0] or type(current) is not state[1]:
                raise ValueError("dependency")
            defaults = getattr(current, "__defaults__", None)
            kwdefaults = getattr(current, "__kwdefaults__", None)
            if getattr(current, "__code__", None) is not state[2] \
                    or defaults is not state[3] \
                    or freeze_metadata(defaults) != state[4] \
                    or kwdefaults is not state[5] \
                    or not mapping_matches(kwdefaults, state[6]) \
                    or getattr(current, "__closure__", None) is not state[7] \
                    or getattr(current, "__globals__", None) is not state[8]:
                raise ValueError("dependency")
        if not request_authority_matches():
            raise ValueError("dependency")

    def strict_object(pairs):
        result = {}
        for key, value in pairs:
            if type(key) is not str or key in result:
                raise ValueError("object")
            result[key] = value
        return result

    def exact_dict(value, keys):
        return (
            type(value) is dict
            and all(type(key) is str for key in value)
            and frozenset(value) == keys
        )

    def digest(value):
        return (
            type(value) is str
            and len(value) == 64
            and all(character in "0123456789abcdef" for character in value)
        )

    def identifier(value):
        return (
            type(value) is str
            and 1 <= len(value.encode("ascii")) <= 128
            and value[0].isalnum()
            and all(c.islower() or c.isdigit() or c in "._-" for c in value)
        )

    def positive_generation(value):
        return type(value) is int and 1 <= value < (1 << 63)

    def generation(value):
        return type(value) is int and 0 <= value < (1 << 63)

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

    def validate_signer(value, matched=False):
        expected = matched_signer_keys if matched else eligible_signer_keys
        if not exact_dict(value, expected) \
                or not identifier(value["keyId"]) \
                or not identifier(value["principalId"]) \
                or not digest(value["publicKeyDigest"]):
            raise ValueError("signer")
        if matched and (
            not digest(value["envelopeDigest"])
            or not digest(value["signatureEvidenceDigest"])
        ):
            raise ValueError("signer evidence")
        return (
            value["keyId"],
            value["principalId"],
            value["publicKeyDigest"],
        )

    def validate_quorum(value, kind, eligible_count, threshold, trust_generation):
        if not exact_dict(value, quorum_keys) \
                or type(value["kind"]) is not str or value["kind"] != kind \
                or type(value["threshold"]) is not int \
                or value["threshold"] != threshold \
                or type(value["trustGeneration"]) is not int \
                or value["trustGeneration"] != trust_generation:
            raise ValueError("quorum")
        eligible_values = value["eligibleSigners"]
        matched_values = value["matchedSigners"]
        if type(eligible_values) is not list \
                or len(eligible_values) != eligible_count \
                or type(matched_values) is not list \
                or len(matched_values) != threshold:
            raise ValueError("quorum count")
        eligible = [validate_signer(item) for item in eligible_values]
        matched = [validate_signer(item, True) for item in matched_values]
        if eligible != sorted(eligible, key=lambda item: item[0]) \
                or len({item[0] for item in eligible}) != eligible_count \
                or len({item[1] for item in eligible}) != eligible_count \
                or len({item[2] for item in eligible}) != eligible_count:
            raise ValueError("eligible")
        try:
            matched_indices = [eligible.index(item) for item in matched]
        except ValueError:
            raise ValueError("matched subset") from None
        if matched_indices != sorted(set(matched_indices)):
            raise ValueError("matched order")
        return matched_values

    def validate_quorums(value, bindings):
        role = value["role"]
        current_generation = bindings["trustGeneration"]
        mode = value["quorumMode"]
        quorums = value["quorums"]
        excluded = value["excludedReleaseSourceKeyId"]
        prior = value["priorTrustGeneration"]
        if type(mode) is not str or type(quorums) is not list:
            raise ValueError("quorums")
        if value["status"] == "refused":
            if quorums:
                raise ValueError("refused quorum")
            if role == "asset-review":
                expected_mode = "asset-review"
            elif role == "revocation-snapshot":
                expected_mode = "revocation"
            elif role == "trust-root-bundle":
                expected_mode = (
                    mode if mode in ("bootstrap", "rotation") else None
                )
            else:
                expected_mode = "ordinary"
            if mode != expected_mode:
                raise ValueError("mode")
            if role == "asset-review":
                if not identifier(excluded) or prior is not None:
                    raise ValueError("asset metadata")
            elif role == "trust-root-bundle" and mode == "rotation":
                if not positive_generation(prior) or prior >= current_generation \
                        or excluded is not None:
                    raise ValueError("rotation metadata")
            elif excluded is not None or prior is not None:
                raise ValueError("metadata")
            return ()
        if role == "asset-review":
            if mode != "asset-review" or len(quorums) != 1 \
                    or not identifier(excluded) or prior is not None:
                raise ValueError("asset quorum")
            matched = validate_quorum(
                quorums[0], "asset-review", 2, 2, current_generation
            )
            if excluded in {
                signer["keyId"] for signer in quorums[0]["eligibleSigners"]
            }:
                raise ValueError("release exclusion")
        elif role == "revocation-snapshot":
            if mode != "revocation" or len(quorums) != 1 \
                    or excluded is not None or prior is not None:
                raise ValueError("revocation quorum")
            matched = validate_quorum(
                quorums[0], "revocation", 3, 2, current_generation
            )
            if any(
                signer["principalId"] == value["issuerId"]
                for signer in quorums[0]["eligibleSigners"]
            ) or any(
                signer["principalId"] == value["issuerId"]
                for signer in quorums[0]["matchedSigners"]
            ):
                raise ValueError("revocation separation")
        elif role == "trust-root-bundle" and mode == "bootstrap":
            if len(quorums) != 1 or excluded is not None or prior is not None:
                raise ValueError("bootstrap quorum")
            matched = validate_quorum(
                quorums[0], "current-root", 3, 2, current_generation
            )
        elif role == "trust-root-bundle" and mode == "rotation":
            if len(quorums) != 2 or excluded is not None \
                    or not positive_generation(prior) \
                    or prior >= current_generation:
                raise ValueError("rotation quorum")
            matched = list(
                validate_quorum(quorums[0], "old-root", 3, 2, prior)
            )
            matched = matched + validate_quorum(
                quorums[1], "new-root", 3, 2, current_generation
            )
        else:
            if mode != "ordinary" or len(quorums) != 1 \
                    or excluded is not None or prior is not None:
                raise ValueError("ordinary quorum")
            matched = validate_quorum(
                quorums[0], role, 1, 1, current_generation
            )
        envelope_digests = [item["envelopeDigest"] for item in matched]
        signature_digests = [item["signatureEvidenceDigest"] for item in matched]
        if len(set(envelope_digests)) != len(envelope_digests) \
                or len(set(signature_digests)) != len(signature_digests):
            raise ValueError("evidence reuse")
        return tuple(
            (item["kind"], len(item["matchedSigners"]),
             len(item["eligibleSigners"]))
            for item in quorums
        )

    def validate(value):
        if not exact_dict(value, top_keys):
            raise ValueError("schema")
        bindings = value["bindings"]
        if not exact_dict(bindings, binding_keys):
            raise ValueError("bindings")
        for key in digest_binding_keys:
            if not digest(bindings[key]):
                raise ValueError("binding digest")
        for key in (
            "cryptographyGeneration",
            "proposedHighWaterGeneration",
            "snapshotGeneration",
            "trustGeneration",
        ):
            if not positive_generation(bindings[key]):
                raise ValueError("binding generation")
        if not generation(bindings["currentHighWaterGeneration"]):
            raise ValueError("binding generation")
        if type(value["version"]) is not int or value["version"] != 1:
            raise ValueError("version")
        if type(value["role"]) is not str or value["role"] not in roles:
            raise ValueError("role")
        if not identifier(value["issuerId"]):
            raise ValueError("issuer")
        if not positive_generation(value["issuerKeyGeneration"]):
            raise ValueError("key generation")
        if not positive_generation(value["revocationTrustGeneration"]):
            raise ValueError("revocation generation")
        if type(value["signatureAlgorithm"]) is not str \
                or value["signatureAlgorithm"] != "ed25519":
            raise ValueError("algorithm")
        for key in ("domainMessageDigest", "verifierRequestDigest"):
            if not digest(value[key]):
                raise ValueError("digest")
        status = value["status"]
        if type(status) is not str or status not in (
            "refused",
            "verified-structural-evidence",
        ):
            raise ValueError("status")
        primitive = value["signaturePrimitiveResult"]
        if type(primitive) is not bool:
            raise ValueError("primitive")
        if status == "verified-structural-evidence":
            if primitive is not True or value["refusalCode"] is not None:
                raise ValueError("verified shape")
        else:
            if primitive is not False or type(value["refusalCode"]) is not str \
                    or value["refusalCode"] not in refusal_codes:
                raise ValueError("refused shape")
        return bindings, validate_quorums(value, bindings)

    def canonical_impl(value):
        validate(value)
        raw = json_bytes(value)
        if len(raw) > maximum:
            raise ValueError("size")
        return raw

    def decode_json(raw):
        if type(raw) is not bytes or not 1 <= len(raw) <= maximum \
                or not raw.endswith(b"\n") or raw.endswith(b"\n\n"):
            raise ValueError("raw")
        value = json_loads(
            raw.decode("ascii"),
            object_pairs_hook=strict_object,
            parse_constant=lambda _value: (_ for _ in ()).throw(
                ValueError("constant")
            ),
        )
        bindings, quorum_summary = validate(value)
        if json_bytes(value) != raw:
            raise ValueError("canonical")
        return value, bindings, quorum_summary

    def expected_bindings(request):
        return {
            "artifactDigest": request["artifactDigest"],
            "cryptographyAcquisitionEvidenceDigest": request[
                "cryptographyAcquisitionEvidenceDigest"
            ],
            "cryptographyClosureDigest": request["cryptographyClosureDigest"],
            "cryptographyGeneration": request["cryptographyGeneration"],
            "currentHighWaterGeneration": request[
                "currentHighWaterGeneration"
            ],
            "currentHighWaterStateDigest": request[
                "currentHighWaterStateDigest"
            ],
            "envelopeDigest": request["envelopeDigest"],
            "proposedHighWaterGeneration": request[
                "proposedHighWaterGeneration"
            ],
            "proposedHighWaterStateDigest": request[
                "proposedHighWaterStateDigest"
            ],
            "revocationSnapshotDigest": request["revocationSnapshotDigest"],
            "snapshotGeneration": request["revocationGeneration"],
            "trustBundleDigest": request["trustBundleDigest"],
            "trustGeneration": request["trustGeneration"],
            "trustedTimeInputSetDigest": request["trustedTimeInputSetDigest"],
            "verifierSourceDigest": request["verifierSourceDigest"],
        }

    def decode_impl(request_raw, evidence_raw):
        if type(request_raw) is not bytes:
            raise ValueError("request")
        request = request_decode(request_raw)
        guard()
        value, bindings, quorum_summary = decode_json(evidence_raw)
        authority_role, issuer_id, issuer_generation, revocation_generation = (
            request["revocationNamespace"]
        )
        if value["verifierRequestDigest"] != sha256(request_raw).hexdigest() \
                or bindings != expected_bindings(request) \
                or value["role"] != request["role"] \
                or authority_role != request["role"] \
                or value["issuerId"] != issuer_id \
                or value["issuerKeyGeneration"] != issuer_generation \
                or value["revocationTrustGeneration"] != revocation_generation:
            raise ValueError("request binding")
        result_bindings = tuple(
            (key, bindings[key]) for key in sorted(binding_keys)
        )
        return mapping_proxy_type(
            {
                "evidenceStructuralOnly": True,
                "status": value["status"],
                "refusalCode": value["refusalCode"],
                "role": value["role"],
                "issuerId": value["issuerId"],
                "issuerKeyGeneration": value["issuerKeyGeneration"],
                "revocationTrustGeneration": value[
                    "revocationTrustGeneration"
                ],
                "signatureAlgorithm": value["signatureAlgorithm"],
                "signaturePrimitiveResult": value[
                    "signaturePrimitiveResult"
                ],
                "quorumMode": value["quorumMode"],
                "quorumSummary": quorum_summary,
                "priorTrustGeneration": value["priorTrustGeneration"],
                "verifierRequestDigest": value["verifierRequestDigest"],
                "domainMessageDigest": value["domainMessageDigest"],
                "bindings": result_bindings,
                "evidenceDigest": sha256(evidence_raw).hexdigest(),
            }
        )

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
            raise refusal("verifier_evidence") from None

    def canonical_evidence(value):
        return invoke(canonical_impl, value)

    def decode(request_raw, evidence_raw):
        return invoke(decode_impl, request_raw, evidence_raw)

    return canonical_evidence, decode


canonical_evidence, decode = _make_codec()
del _make_codec
