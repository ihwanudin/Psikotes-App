"""Pure repository Ed25519 verification boundary.

Cryptography is a caller-supplied, acquisition-bound capability.  This module
does not import or discover an ambient crypto package and its result is only
structural cryptographic evidence: it does not establish trust, freshness,
revocation, replay consumption, admission, or runtime authority.
"""

import hashlib
import json
import re
from types import MappingProxyType

__all__ = ("RepositoryEd25519Refused", "seal_backend", "verify")

_ERROR = "repository_ed25519_verifier"
_IMPLEMENTATION = "cryptography-ed25519-adapter-v1"
_MAX_ARTIFACT = 8 * 1024 * 1024
_MAX_ENVELOPE = 16 * 1024
_IDENTIFIER = re.compile(r"[a-z0-9][a-z0-9._-]{0,127}\Z")
_HEX64 = re.compile(r"[0-9a-f]{64}\Z")
_HEX128 = re.compile(r"[0-9a-f]{128}\Z")


class RepositoryEd25519Refused(Exception):
    """Fixed, redacted refusal."""


def _refuse():
    raise RepositoryEd25519Refused(_ERROR)


def _exact_dict(value, keys):
    if type(value) is not dict or tuple(sorted(value)) != tuple(sorted(keys)):
        _refuse()


def _identifier(value):
    if type(value) is not str or _IDENTIFIER.fullmatch(value) is None:
        _refuse()
    return value


def _digest(value):
    if type(value) is not str or _HEX64.fullmatch(value) is None:
        _refuse()
    return value


def _positive(value):
    if type(value) is not int or value < 1 or value > 2**31 - 1:
        _refuse()
    return value


def _pairs(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            _refuse()
        result[key] = value
    return result


def _canonical(raw, maximum):
    if type(raw) is not bytes or not raw or len(raw) > maximum or not raw.endswith(b"\n"):
        _refuse()
    try:
        text = raw.decode("ascii")
        value = json.loads(
            text,
            object_pairs_hook=_pairs,
            parse_constant=lambda _value: _refuse(),
        )
        encoded = (json.dumps(value, sort_keys=True, separators=(",", ":"),
                              ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")
    except (RepositoryEd25519Refused, KeyboardInterrupt, SystemExit):
        raise
    except Exception:
        _refuse()
    if encoded != raw:
        _refuse()
    return value


def _callable_state(callable_value):
    function = getattr(callable_value, "__func__", callable_value)
    return (
        callable_value,
        function,
        getattr(function, "__code__", None),
        getattr(function, "__defaults__", None),
        getattr(function, "__kwdefaults__", None),
    )


_DEPENDENCIES = (
    (json, "loads", _callable_state(json.loads)),
    (json, "dumps", _callable_state(json.dumps)),
    (hashlib, "sha256", _callable_state(hashlib.sha256)),
)


def _guard_dependencies():
    for owner, name, expected in _DEPENDENCIES:
        current = getattr(owner, name, None)
        if not callable(current) or _callable_state(current) != expected:
            _refuse()


class _Capability:
    __slots__ = (
        "_adapter", "_adapter_type", "_method_state", "_class_items",
        "_acquisition", "_locked",
    )

    def __init__(self, adapter, acquisition):
        object.__setattr__(self, "_adapter", adapter)
        object.__setattr__(self, "_adapter_type", type(adapter))
        object.__setattr__(self, "_method_state", _callable_state(adapter.verify_ed25519))
        object.__setattr__(self, "_class_items", tuple(type(adapter).__dict__.items()))
        object.__setattr__(self, "_acquisition", acquisition)
        object.__setattr__(self, "_locked", True)

    def __setattr__(self, _name, _value):
        if getattr(self, "_locked", False):
            _refuse()
        object.__setattr__(self, _name, _value)


_sealed = None


def _acquisition(value):
    keys = (
        "acquisitionEvidenceDigest", "backendIdentityDigest", "backendVersion",
        "closureDigest", "generation", "implementation",
    )
    _exact_dict(value, keys)
    if value["implementation"] != _IMPLEMENTATION:
        _refuse()
    if type(value["backendVersion"]) is not str or not value["backendVersion"] \
            or len(value["backendVersion"]) > 128 or not value["backendVersion"].isascii():
        _refuse()
    return (
        _digest(value["acquisitionEvidenceDigest"]),
        _digest(value["backendIdentityDigest"]),
        value["backendVersion"],
        _digest(value["closureDigest"]),
        _positive(value["generation"]),
        value["implementation"],
    )


def seal_backend(adapter, acquisition_metadata):
    """Seal exactly one explicit crypto adapter for this module instance."""
    global _sealed
    if _sealed is not None or type(acquisition_metadata) is not dict:
        _refuse()
    if hasattr(adapter, "__dict__") or type(adapter).__module__ == "builtins":
        _refuse()
    method = getattr(adapter, "verify_ed25519", None)
    if not callable(method):
        _refuse()
    acquisition = _acquisition(acquisition_metadata)
    capability = _Capability(adapter, acquisition)
    _sealed = capability
    return capability


def _guard(capability):
    _guard_dependencies()
    if type(capability) is not _Capability or capability is not _sealed:
        _refuse()
    if type(capability._adapter) is not capability._adapter_type:
        _refuse()
    if tuple(capability._adapter_type.__dict__.items()) != capability._class_items:
        _refuse()
    method = getattr(capability._adapter, "verify_ed25519", None)
    if not callable(method) or _callable_state(method) != capability._method_state:
        _refuse()


def _binding(value, capability):
    keys = (
        "cryptographyAcquisitionEvidenceDigest", "cryptographyClosureDigest",
        "cryptographyGeneration",
    )
    _exact_dict(value, keys)
    acquisition = capability._acquisition
    if (
        _digest(value["cryptographyAcquisitionEvidenceDigest"]) != acquisition[0]
        or _digest(value["cryptographyClosureDigest"]) != acquisition[3]
        or _positive(value["cryptographyGeneration"]) != acquisition[4]
    ):
        _refuse()


def _artifact(raw):
    value = _canonical(raw, _MAX_ARTIFACT)
    if type(value) is not dict or "role" not in value or "replayId" not in value:
        _refuse()
    _identifier(value["role"])
    _identifier(value["replayId"])
    if "version" in value and value["version"] != 1:
        _refuse()
    return value


def _envelope(raw):
    value = _canonical(raw, _MAX_ENVELOPE)
    keys = (
        "algorithm", "artifactDigest", "issuerId", "keyGeneration", "keyId",
        "role", "signature", "trustGeneration", "version",
    )
    _exact_dict(value, keys)
    if value["algorithm"] != "ed25519" or value["version"] != 1:
        _refuse()
    _digest(value["artifactDigest"])
    _identifier(value["issuerId"]); _identifier(value["keyId"]); _identifier(value["role"])
    _positive(value["keyGeneration"]); _positive(value["trustGeneration"])
    if type(value["signature"]) is not str or _HEX128.fullmatch(value["signature"]) is None:
        _refuse()
    return value


def _signer(value):
    _exact_dict(value, ("keyGeneration", "keyId", "principalId", "publicKey"))
    if type(value["publicKey"]) is not str or _HEX64.fullmatch(value["publicKey"]) is None:
        _refuse()
    return (
        _positive(value["keyGeneration"]), _identifier(value["keyId"]),
        _identifier(value["principalId"]), value["publicKey"],
    )


def _policy(value, role):
    _exact_dict(value, (
        "artifactIssuerId", "excludedReleaseSourceKeyId", "groups", "mode",
        "priorTrustGeneration",
    ))
    issuer = _identifier(value["artifactIssuerId"])
    mode = value["mode"]
    if mode not in ("ordinary", "asset-review", "revocation", "bootstrap", "rotation"):
        _refuse()
    groups_raw = value["groups"]
    if type(groups_raw) is not list or not groups_raw or len(groups_raw) > 2:
        _refuse()
    groups = []
    all_keys = set(); all_principals = set(); all_material = set()
    for item in groups_raw:
        _exact_dict(item, ("eligibleSigners", "kind", "threshold", "trustGeneration"))
        signers_raw = item["eligibleSigners"]
        if type(signers_raw) is not list or not signers_raw or len(signers_raw) > 3:
            _refuse()
        signers = tuple(_signer(signer) for signer in signers_raw)
        if tuple(s[1] for s in signers) != tuple(sorted(s[1] for s in signers)):
            _refuse()
        for signer in signers:
            if signer[1] in all_keys or signer[2] in all_principals or signer[3] in all_material:
                _refuse()
            all_keys.add(signer[1]); all_principals.add(signer[2]); all_material.add(signer[3])
        threshold = _positive(item["threshold"])
        if threshold > len(signers):
            _refuse()
        groups.append((_identifier(item["kind"]), _positive(item["trustGeneration"]),
                       threshold, signers))
    excluded = value["excludedReleaseSourceKeyId"]
    prior = value["priorTrustGeneration"]
    if mode == "ordinary":
        if role in ("asset-review", "revocation-snapshot", "trust-root-bundle") \
                or len(groups) != 1 or groups[0][:3] != (role, groups[0][1], 1) \
                or len(groups[0][3]) != 1 or excluded is not None or prior is not None:
            _refuse()
    elif mode == "asset-review":
        if role != "asset-review" or len(groups) != 1 or groups[0][0] != "asset-review" \
                or groups[0][2] != 2 or len(groups[0][3]) != 2:
            _refuse()
        excluded = _identifier(excluded)
        if excluded in all_keys or prior is not None:
            _refuse()
    elif mode == "revocation":
        if role != "revocation-snapshot" or len(groups) != 1 or groups[0][0] != "revocation" \
                or groups[0][2] != 2 or len(groups[0][3]) != 3 or excluded is not None \
                or prior is not None or issuer in all_principals:
            _refuse()
    elif mode == "bootstrap":
        if role != "trust-root-bundle" or len(groups) != 1 or groups[0][0] != "current-root" \
                or groups[0][2] != 2 or len(groups[0][3]) != 3 or excluded is not None \
                or prior is not None:
            _refuse()
    else:
        if role != "trust-root-bundle" or len(groups) != 2 \
                or tuple(group[0] for group in groups) != ("old-root", "new-root") \
                or any(group[2] != 2 or len(group[3]) != 3 for group in groups) \
                or excluded is not None:
            _refuse()
        prior = _positive(prior)
        if groups[0][1] != prior or groups[1][1] <= prior:
            _refuse()
    return mode, tuple(groups)


def _invoke(capability, public_key, signature, message):
    _guard(capability)
    primary = None
    result = None
    try:
        result = capability._adapter.verify_ed25519(public_key, signature, message)
    except BaseException as error:
        primary = error
    try:
        _guard(capability)
    except BaseException:
        if primary is None:
            _refuse()
    if primary is not None:
        if isinstance(primary, (KeyboardInterrupt, SystemExit)):
            raise primary
        _refuse()
    if type(result) is not bool or result is not True:
        _refuse()


def verify(capability, artifact_raw, envelope_raws, policy, acquisition_binding,
           consumed_replay_ids):
    """Verify supplied detached signatures without granting higher authority."""
    _guard(capability)
    _binding(acquisition_binding, capability)
    artifact_value = _artifact(artifact_raw)
    role = artifact_value["role"]
    if type(envelope_raws) is not list or not envelope_raws or len(envelope_raws) > 4:
        _refuse()
    if type(consumed_replay_ids) is not tuple \
            or any(type(item) is not str for item in consumed_replay_ids):
        _refuse()
    for item in consumed_replay_ids:
        _identifier(item)
    if consumed_replay_ids != tuple(sorted(set(consumed_replay_ids))) \
            or artifact_value["replayId"] in consumed_replay_ids:
        _refuse()
    if len(set(envelope_raws)) != len(envelope_raws):
        _refuse()
    mode, groups = _policy(policy, role)
    artifact_digest = hashlib.sha256(artifact_raw).hexdigest()
    message = b"oncam.checkout." + role.encode("ascii") + b".v1\0" + artifact_raw
    envelopes = tuple(_envelope(raw) for raw in envelope_raws)
    matched = []
    group_matches = []
    used_keys = set()
    for kind, trust_generation, threshold, signers in groups:
        group_count = 0
        signer_by_key = {signer[1]: signer for signer in signers}
        for envelope_value in envelopes:
            if envelope_value["keyId"] not in signer_by_key:
                continue
            signer = signer_by_key[envelope_value["keyId"]]
            if envelope_value["keyId"] in used_keys or (
                envelope_value["role"] != role
                or envelope_value["artifactDigest"] != artifact_digest
                or envelope_value["issuerId"] != signer[2]
                or envelope_value["keyGeneration"] != signer[0]
                or envelope_value["trustGeneration"] != trust_generation
            ):
                _refuse()
            _invoke(capability, bytes.fromhex(signer[3]),
                    bytes.fromhex(envelope_value["signature"]), message)
            used_keys.add(envelope_value["keyId"])
            matched.append(envelope_value["keyId"]); group_count += 1
        if group_count != threshold:
            _refuse()
        group_matches.append((kind, trust_generation, group_count))
    if len(used_keys) != len(envelopes):
        _refuse()
    _guard(capability)
    return MappingProxyType({
        "acquisitionEvidenceDigest": capability._acquisition[0],
        "artifactDigest": artifact_digest,
        "backendIdentityDigest": capability._acquisition[1],
        "cryptoVerifiedStructuralOnly": True,
        "cryptographyGeneration": capability._acquisition[4],
        "domainMessageDigest": hashlib.sha256(message).hexdigest(),
        "groupMatches": tuple(group_matches),
        "matchedKeyIds": tuple(matched),
        "matchedSignerCount": len(matched),
        "mode": mode,
        "role": role,
    })


def _make_public_boundary(seal_impl, verify_impl):
    module_globals = globals()
    refusal = RepositoryEd25519Refused
    error = _ERROR
    implementation = _IMPLEMENTATION
    maximum_artifact = _MAX_ARTIFACT
    maximum_envelope = _MAX_ENVELOPE
    identifier = _IDENTIFIER
    hex64 = _HEX64
    hex128 = _HEX128
    regex_type = type(identifier)
    mapping_proxy = MappingProxyType
    public_surface = __all__
    json_module = json
    hashlib_module = hashlib
    re_module = re
    helper_names = (
        "_refuse", "_exact_dict", "_identifier", "_digest", "_positive",
        "_pairs", "_canonical", "_callable_state", "_guard_dependencies",
        "_acquisition", "_guard", "_binding", "_artifact", "_envelope",
        "_signer", "_policy", "_invoke",
    )

    def state(value):
        defaults = getattr(value, "__defaults__", None)
        kwdefaults = getattr(value, "__kwdefaults__", None)
        return (
            value, type(value), getattr(value, "__code__", None),
            defaults, None if defaults is None else tuple(defaults),
            kwdefaults,
            None if kwdefaults is None else tuple(sorted(kwdefaults.items())),
            getattr(value, "__closure__", None), getattr(value, "__globals__", None),
        )

    dependencies = (
        (json_module, "loads", state(json_module.loads)),
        (json_module, "dumps", state(json_module.dumps)),
        (hashlib_module, "sha256", state(hashlib_module.sha256)),
        (re_module, "compile", state(re_module.compile)),
    )
    helper_states = tuple(
        (name, state(module_globals[name])) for name in helper_names
    )
    pins = (
        ("_ERROR", error), ("_IMPLEMENTATION", implementation),
        ("_MAX_ARTIFACT", maximum_artifact), ("_MAX_ENVELOPE", maximum_envelope),
        ("_IDENTIFIER", identifier), ("_HEX64", hex64), ("_HEX128", hex128),
        ("MappingProxyType", mapping_proxy),
        ("RepositoryEd25519Refused", refusal), ("__all__", public_surface),
        ("json", json_module), ("hashlib", hashlib_module), ("re", re_module),
        ("_Capability", _Capability), ("_DEPENDENCIES", _DEPENDENCIES),
    )
    sealed_capability = None
    sealed_snapshot = None

    def boundary_guard():
        try:
            for name, expected in pins:
                if module_globals.get(name) is not expected:
                    raise ValueError("authority")
            for regex in (identifier, hex64, hex128):
                if type(regex) is not regex_type or type(regex.pattern) is not str \
                        or type(regex.flags) is not int:
                    raise ValueError("regex")
            for owner, name, expected in dependencies:
                current = getattr(owner, name, None)
                if state(current) != expected:
                    raise ValueError("dependency")
            for name, expected in helper_states:
                current = module_globals.get(name)
                if state(current) != expected:
                    raise ValueError("helper")
            if module_globals.get("seal_backend") is not seal_public \
                    or module_globals.get("verify") is not verify_public:
                raise ValueError("exports")
        except (KeyboardInterrupt, SystemExit):
            raise
        except BaseException:
            raise refusal(error) from None

    def invoke(operation, *args):
        primary = None
        result = None
        try:
            boundary_guard()
            result = operation(*args)
        except BaseException as caught:
            primary = caught
        try:
            boundary_guard()
        except BaseException:
            if primary is None:
                raise refusal(error) from None
        if primary is not None:
            if isinstance(primary, (KeyboardInterrupt, SystemExit)):
                raise primary
            if type(primary) is refusal and str(primary) == error:
                raise primary
            raise refusal(error) from None
        return result

    def capability_matches(capability):
        return (
            capability is sealed_capability
            and type(capability) is _Capability
            and capability._adapter is sealed_snapshot[0]
            and capability._adapter_type is sealed_snapshot[1]
            and capability._method_state is sealed_snapshot[2]
            and capability._class_items is sealed_snapshot[3]
            and capability._acquisition is sealed_snapshot[4]
            and capability._locked is True
        )

    def seal_public(adapter, acquisition_metadata):
        nonlocal sealed_capability, sealed_snapshot
        if sealed_capability is not None:
            raise refusal(error)
        result = invoke(seal_impl, adapter, acquisition_metadata)
        sealed_capability = result
        sealed_snapshot = (
            result._adapter, result._adapter_type, result._method_state,
            result._class_items, result._acquisition,
        )
        return result

    def verify_public(capability, artifact_raw, envelope_raws, policy,
                      acquisition_binding, consumed_replay_ids):
        if sealed_capability is None or not capability_matches(capability):
            raise refusal(error)
        result = invoke(
            verify_impl, capability, artifact_raw, envelope_raws, policy,
            acquisition_binding, consumed_replay_ids,
        )
        if not capability_matches(capability):
            raise refusal(error)
        return result

    return seal_public, verify_public


seal_backend, verify = _make_public_boundary(seal_backend, verify)
del _make_public_boundary
