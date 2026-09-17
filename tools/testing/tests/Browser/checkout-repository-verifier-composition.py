"""One-shot structural composition of repository verifier request and evidence.

The verifier capability and callable are caller-authenticated inputs and are
pinned for the invocation.  This composition does not discover cryptography,
establish trust/freshness/revocation, write high-water or replay state, grant
admission, or provide runtime authority.  Its output is canonical I17 evidence
for a successful structural cryptographic projection only.
"""

import hashlib
import importlib.util
import json
from pathlib import Path
from types import MappingProxyType


class RepositoryVerifierCompositionRefused(Exception):
    """Fixed refusal without artifact, signature, or dependency detail."""


__all__ = ("RepositoryVerifierCompositionRefused", "compose")
_ERROR = "repository_verifier_composition"
_MAX_ARTIFACT = 8 * 1024 * 1024
_MAX_ENVELOPES = 4


def _load(filename, name):
    path = Path(__file__).with_name(filename)
    spec = importlib.util.spec_from_file_location(name, path)
    if spec is None or spec.loader is None:
        raise RepositoryVerifierCompositionRefused(_ERROR)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


_REQUEST = _load("checkout-verifier-request.py", "_repository_composition_request")
_EVIDENCE = _load("checkout-verifier-evidence.py", "_repository_composition_evidence")
del _load


def _make_composition():
    refusal = RepositoryVerifierCompositionRefused
    error = _ERROR
    maximum_artifact = _MAX_ARTIFACT
    maximum_envelopes = _MAX_ENVELOPES
    request_module = _REQUEST
    evidence_module = _EVIDENCE
    request_decode = request_module.decode
    evidence_canonical = evidence_module.canonical_evidence
    evidence_decode = evidence_module.decode
    json_module = json
    hashlib_module = hashlib
    json_dumps = json.dumps
    json_loads = json.loads
    sha256 = hashlib.sha256
    mapping_proxy_type = MappingProxyType
    module_globals = globals()
    attempted = False

    def freeze(value):
        if value is None or type(value) in (bool, int, str, bytes):
            return (type(value).__name__, value)
        if type(value) is tuple:
            return ("tuple", tuple(freeze(item) for item in value))
        if type(value) is dict:
            return ("dict", tuple((key, freeze(item)) for key, item in value.items()))
        raise ValueError("metadata")

    def state(value):
        defaults = getattr(value, "__defaults__", None)
        kwdefaults = getattr(value, "__kwdefaults__", None)
        return (
            value, type(value), getattr(value, "__code__", None),
            defaults, freeze(defaults), kwdefaults,
            None if kwdefaults is None else freeze(kwdefaults),
            getattr(value, "__closure__", None), getattr(value, "__globals__", None),
        )

    request_state = state(request_decode)
    evidence_states = (state(evidence_canonical), state(evidence_decode))
    dependency_states = (
        (json_module, "dumps", state(json_dumps)),
        (json_module, "loads", state(json_loads)),
        (hashlib_module, "sha256", state(sha256)),
    )
    pins = (
        ("RepositoryVerifierCompositionRefused", refusal), ("_ERROR", error),
        ("_MAX_ARTIFACT", maximum_artifact), ("_MAX_ENVELOPES", maximum_envelopes),
        ("_REQUEST", request_module), ("_EVIDENCE", evidence_module),
        ("MappingProxyType", mapping_proxy_type), ("json", json_module),
        ("hashlib", hashlib_module), ("__all__", __all__),
    )
    helper_states = ()

    def same(value, expected):
        try:
            current = state(value)
            return all(current[index] is expected[index] for index in (0, 1, 2, 3, 5, 7, 8)) \
                and current[4] == expected[4] and current[6] == expected[6]
        except Exception:
            return False

    def guard():
        try:
            for name, expected in pins:
                if module_globals.get(name) is not expected:
                    raise ValueError("authority")
            if not same(getattr(request_module, "decode", None), request_state) \
                    or not same(getattr(evidence_module, "canonical_evidence", None),
                                evidence_states[0]) \
                    or not same(getattr(evidence_module, "decode", None), evidence_states[1]):
                raise ValueError("codec")
            for owner, name, expected in dependency_states:
                if not same(getattr(owner, name, None), expected):
                    raise ValueError("dependency")
            for helper, expected in helper_states:
                if not same(helper, expected):
                    raise ValueError("helper")
        except refusal:
            raise
        except Exception:
            raise refusal(error) from None

    def canonical(value):
        return (json_dumps(value, sort_keys=True, separators=(",", ":"),
                           ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")

    def parse(raw):
        if type(raw) is not bytes or not raw.endswith(b"\n") or raw.endswith(b"\n\n"):
            raise ValueError("canonical")
        value = json_loads(raw.decode("ascii"))
        if type(value) is not dict or canonical(value) != raw:
            raise ValueError("canonical")
        return value

    def envelope_set_digest(envelope_raws):
        inventory = [sha256(raw).hexdigest() for raw in envelope_raws]
        return sha256(
            b"oncam.checkout.detached-envelope-set.v1\0" + canonical(inventory)
        ).hexdigest()

    def expected_result(request, artifact_raw, policy, mode, groups):
        role = request["role"]
        message = b"oncam.checkout." + role.encode("ascii") + b".v1\0" + artifact_raw
        return {
            "acquisitionEvidenceDigest": request["cryptographyAcquisitionEvidenceDigest"],
            "artifactDigest": request["artifactDigest"],
            "backendIdentityDigest": None,
            "cryptoVerifiedStructuralOnly": True,
            "cryptographyGeneration": request["cryptographyGeneration"],
            "domainMessageDigest": sha256(message).hexdigest(),
            "groupMatches": tuple(
                (group["kind"], group["trustGeneration"], group["threshold"])
                for group in groups
            ),
            "matchedKeyIds": None,
            "matchedSignerCount": sum(group["threshold"] for group in groups),
            "mode": mode,
            "role": role,
        }

    def validate_result(result, expected, policy, envelopes):
        keys = tuple(sorted(expected))
        if type(result) is not mapping_proxy_type or tuple(sorted(result)) != keys:
            raise ValueError("verifier result")
        for key, value in expected.items():
            if value is not None and result[key] != value:
                raise ValueError("verifier binding")
        if type(result["backendIdentityDigest"]) is not str \
                or len(result["backendIdentityDigest"]) != 64:
            raise ValueError("backend")
        matched_keys = result["matchedKeyIds"]
        if type(matched_keys) is not tuple \
                or len(matched_keys) != expected["matchedSignerCount"] \
                or any(type(key) is not str for key in matched_keys) \
                or len(set(matched_keys)) != len(matched_keys):
            raise ValueError("matched")
        eligible_by_group = tuple(
            tuple(signer["keyId"] for signer in group["eligibleSigners"])
            for group in policy["groups"]
        )
        if any(
            len(set(matched_keys) & set(keys)) != group["threshold"]
            for keys, group in zip(eligible_by_group, policy["groups"])
        ) or set(matched_keys) != set().union(*(set(keys) & set(matched_keys)
                                                for keys in eligible_by_group)):
            raise ValueError("matched")
        parsed = [parse(raw) for raw in envelopes]
        by_key = {value["keyId"]: (value, raw) for value, raw in zip(parsed, envelopes)}
        if len(by_key) != len(envelopes) or set(by_key) != set(matched_keys):
            raise ValueError("envelopes")
        return by_key

    def evidence_value(request_raw, request, result, policy, envelopes_by_key):
        bindings = {
            "artifactDigest": request["artifactDigest"],
            "cryptographyAcquisitionEvidenceDigest": request[
                "cryptographyAcquisitionEvidenceDigest"
            ],
            "cryptographyClosureDigest": request["cryptographyClosureDigest"],
            "cryptographyGeneration": request["cryptographyGeneration"],
            "currentHighWaterGeneration": request["currentHighWaterGeneration"],
            "currentHighWaterStateDigest": request["currentHighWaterStateDigest"],
            "envelopeDigest": request["envelopeDigest"],
            "proposedHighWaterGeneration": request["proposedHighWaterGeneration"],
            "proposedHighWaterStateDigest": request["proposedHighWaterStateDigest"],
            "revocationSnapshotDigest": request["revocationSnapshotDigest"],
            "snapshotGeneration": request["revocationGeneration"],
            "trustBundleDigest": request["trustBundleDigest"],
            "trustGeneration": request["trustGeneration"],
            "trustedTimeInputSetDigest": request["trustedTimeInputSetDigest"],
            "verifierSourceDigest": request["verifierSourceDigest"],
        }
        quorums = []
        for group in policy["groups"]:
            eligible = [{
                "keyId": signer["keyId"], "principalId": signer["principalId"],
                "publicKeyDigest": sha256(bytes.fromhex(signer["publicKey"])).hexdigest(),
            } for signer in group["eligibleSigners"]]
            matched = []
            matched_keys = set(result["matchedKeyIds"])
            for signer in (item for item in eligible if item["keyId"] in matched_keys):
                _envelope, raw = envelopes_by_key[signer["keyId"]]
                matched.append({
                    **signer,
                    "envelopeDigest": sha256(raw).hexdigest(),
                    "signatureEvidenceDigest": sha256(
                        b"oncam.checkout.signature-evidence.v1\0"
                        + result["domainMessageDigest"].encode("ascii") + b"\0" + raw
                    ).hexdigest(),
                })
            quorums.append({
                "eligibleSigners": eligible, "kind": group["kind"],
                "matchedSigners": matched, "threshold": group["threshold"],
                "trustGeneration": group["trustGeneration"],
            })
        namespace = request["revocationNamespace"]
        return {
            "bindings": bindings,
            "domainMessageDigest": result["domainMessageDigest"],
            "excludedReleaseSourceKeyId": policy["excludedReleaseSourceKeyId"],
            "issuerId": namespace[1], "issuerKeyGeneration": namespace[2],
            "priorTrustGeneration": policy["priorTrustGeneration"],
            "quorumMode": policy["mode"], "quorums": quorums,
            "refusalCode": None, "revocationTrustGeneration": namespace[3],
            "role": request["role"], "signatureAlgorithm": "ed25519",
            "signaturePrimitiveResult": True,
            "status": "verified-structural-evidence",
            "verifierRequestDigest": sha256(request_raw).hexdigest(), "version": 1,
        }

    def compose_impl(request_raw, artifact_raw, envelope_raws, policy,
                     verifier_capability, verifier_callable):
        if type(request_raw) is not bytes or type(artifact_raw) is not bytes \
                or not 1 <= len(artifact_raw) <= maximum_artifact \
                or type(envelope_raws) is not list \
                or not 1 <= len(envelope_raws) <= maximum_envelopes \
                or any(type(raw) is not bytes for raw in envelope_raws) \
                or type(policy) is not dict or not callable(verifier_callable):
            raise ValueError("input")
        request = request_decode(request_raw)
        guard()
        if request["artifactDigest"] != sha256(artifact_raw).hexdigest() \
                or request["envelopeDigest"] != envelope_set_digest(envelope_raws):
            raise ValueError("input binding")
        mode = policy.get("mode")
        groups = policy.get("groups")
        if type(mode) is not str or type(groups) is not list:
            raise ValueError("policy")
        namespace = request["revocationNamespace"]
        if policy.get("artifactIssuerId") != namespace[1]:
            raise ValueError("issuer")
        callable_pin = state(verifier_callable)
        acquisition = {
            "cryptographyAcquisitionEvidenceDigest": request[
                "cryptographyAcquisitionEvidenceDigest"
            ],
            "cryptographyClosureDigest": request["cryptographyClosureDigest"],
            "cryptographyGeneration": request["cryptographyGeneration"],
        }
        primary = None
        result = None
        try:
            result = verifier_callable(
                verifier_capability, artifact_raw, envelope_raws, policy, acquisition, ()
            )
        except BaseException as caught:
            primary = caught
        if not same(verifier_callable, callable_pin):
            if primary is None:
                raise ValueError("verifier mutation")
        guard()
        if primary is not None:
            if isinstance(primary, (KeyboardInterrupt, SystemExit)):
                raise primary
            raise ValueError("verifier")
        expected = expected_result(request, artifact_raw, policy, mode, groups)
        envelope_map = validate_result(result, expected, policy, envelope_raws)
        evidence_raw = evidence_canonical(
            evidence_value(request_raw, request, result, policy, envelope_map)
        )
        guard()
        decoded = evidence_decode(request_raw, evidence_raw)
        guard()
        if decoded["status"] != "verified-structural-evidence" \
                or decoded["domainMessageDigest"] != result["domainMessageDigest"]:
            raise ValueError("evidence")
        return evidence_raw

    helper_states = tuple(
        (helper, state(helper)) for helper in (
            canonical, parse, envelope_set_digest, expected_result,
            validate_result, evidence_value, compose_impl,
        )
    )

    def compose(request_raw, artifact_raw, envelope_raws, policy,
                verifier_capability, verifier_callable):
        nonlocal attempted
        if attempted:
            raise refusal(error)
        attempted = True
        try:
            guard()
            result = compose_impl(
                request_raw, artifact_raw, envelope_raws, policy,
                verifier_capability, verifier_callable,
            )
            guard()
            return result
        except BaseException as primary:
            try:
                guard()
            except BaseException:
                pass
            if isinstance(primary, (KeyboardInterrupt, SystemExit)):
                raise
            raise refusal(error) from None

    return compose


compose = _make_composition()
del _make_composition
