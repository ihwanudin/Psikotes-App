"""Pure structural ADR-018 evidence decoder.

The returned marker is deliberately structural-only and cannot authorize an
admission.  Native provenance, policy efficacy, temporal stability, and live
Windows checks remain outside this codec.
"""

from __future__ import annotations

import hashlib
import importlib.util
import json
from pathlib import Path
import re
import types
from typing import NamedTuple


MAX_EVIDENCE_BYTES = 32 * 1024


class OrdinaryEvidenceRefused(Exception):
    """Stable structural refusal without evidence details."""


class _StructuralEvidence(NamedTuple):
    structuralOnly: bool
    requestDigest: str
    evidenceDigest: str
    boundary: str
    phase: str
    session: str


def _load_request_codec():
    try:
        path = Path(__file__).resolve().with_name("checkout-ordinary-access-request.py")
        spec = importlib.util.spec_from_file_location(
            "checkout_ordinary_access_request_evidence", path,
        )
        if spec is None or spec.loader is None:
            raise ValueError("codec")
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return path, module
    except Exception:
        raise OrdinaryEvidenceRefused("ordinary_access_evidence") from None


_REQUEST_PATH, _REQUEST_CODEC = _load_request_codec()


def _make_codec():
    maximum = MAX_EVIDENCE_BYTES
    refusal = OrdinaryEvidenceRefused
    result_type = _StructuralEvidence
    request_path = _REQUEST_PATH
    request_module = _REQUEST_CODEC
    request_type = request_module._RequestSnapshot
    decode_request = request_module.decode_request
    request_digest = request_module.request_digest
    request_bytes_method = request_type.bytes
    json_module = json
    hashlib_module = hashlib
    re_module = re
    json_loads = json.loads
    json_dumps = json.dumps
    sha256 = hashlib.sha256
    digest_pattern = re.compile(r"[a-f0-9]{64}")
    decimal_pattern = re.compile(r"0|[1-9][0-9]*")
    roles = ("coordinator", "run", "source")
    request_keys = frozenset({
        "version", "boundary", "phase", "session", "configBinding", "leaseBinding",
        "policyDigest", "aclRequestDigest", "aclEvidenceDigest",
        "aclDescriptorEvidenceDigest", "challenge", "currentTokenIdentity", "targets",
    })
    evidence_keys = request_keys | {
        "requestDigest", "provenanceKind", "originalToken", "derivedToken",
    }
    token_keys = frozenset({
        "authenticationId", "groups", "impersonationLevel", "isAppContainer",
        "isAppContainerRaw", "modifiedId", "origin", "privileges", "restrictingSids",
        "tokenId", "tokenType", "userSid",
    })
    target_extra = frozenset({
        "descriptorDigest", "functionSuccess", "accessStatus", "grantedAccess",
        "policySatisfied",
    })
    module_globals = globals()

    def callable_state(function):
        kwdefaults = getattr(function, "__kwdefaults__", None)
        return (function, type(function), getattr(function, "__code__", None),
                getattr(function, "__defaults__", None), kwdefaults,
                None if kwdefaults is None else tuple(sorted(kwdefaults.items())),
                getattr(function, "__closure__", None),
                getattr(function, "__globals__", None))

    def class_state(class_value):
        return {
            name: (("callable", callable_state(value))
                   if type(value) is types.FunctionType else ("identity", value))
            for name, value in class_value.__dict__.items()
            if name not in {"__dict__", "__weakref__"}
        }

    result_type_state = class_state(result_type)

    dependencies = (
        (json_module, "loads", callable_state(json_loads)),
        (json_module, "dumps", callable_state(json_dumps)),
        (hashlib_module, "sha256", callable_state(sha256)),
    )
    sibling_functions = {
        name: callable_state(value)
        for name, value in request_module.__dict__.items()
        if type(value) is types.FunctionType and value.__module__ == request_module.__name__
    }
    sibling_globals = {
        name: (("value", type(value), value)
               if type(value) in {str, int, bytes, type(None)} else ("identity", value))
        for name, value in request_module.__dict__.items()
        if not name.startswith("__") and name not in sibling_functions
    }
    sibling_function_names = frozenset(sibling_functions)
    sibling_global_names = frozenset(sibling_globals)
    request_type_state = {
        name: (("callable", callable_state(value))
               if type(value) is types.FunctionType else ("identity", value))
        for name, value in request_type.__dict__.items()
        if name not in {"__dict__", "__weakref__"}
    }
    global_pins = (
        ("MAX_EVIDENCE_BYTES", maximum), ("OrdinaryEvidenceRefused", refusal),
        ("_StructuralEvidence", result_type), ("_REQUEST_PATH", request_path),
        ("_REQUEST_CODEC", request_module), ("json", json_module),
        ("hashlib", hashlib_module), ("re", re_module),
    )

    def state_matches(current, expected):
        actual = callable_state(current)
        return all(actual[index] is expected[index] for index in (0, 1, 2, 3, 4, 6, 7)) \
            and actual[5] == expected[5]

    def guard():
        try:
            for name, expected in global_pins:
                if module_globals.get(name) is not expected:
                    raise ValueError("authority")
            current_result_names = {
                name for name in result_type.__dict__
                if name not in {"__dict__", "__weakref__"}
            }
            if current_result_names != set(result_type_state):
                raise ValueError("result_type")
            for name, expected in result_type_state.items():
                current = result_type.__dict__.get(name)
                if expected[0] == "callable":
                    if not state_matches(current, expected[1]):
                        raise ValueError("result_type")
                elif current is not expected[1]:
                    raise ValueError("result_type")
            if Path(getattr(request_module, "__file__", "")).resolve() != request_path \
                    or request_module._RequestSnapshot is not request_type \
                    or request_type.bytes is not request_bytes_method \
                    or request_module.decode_request is not decode_request \
                    or request_module.request_digest is not request_digest:
                raise ValueError("request_codec")
            current_type_names = {
                name for name in request_type.__dict__
                if name not in {"__dict__", "__weakref__"}
            }
            if current_type_names != set(request_type_state):
                raise ValueError("request_codec")
            for name, expected in request_type_state.items():
                current = request_type.__dict__.get(name)
                if expected[0] == "callable":
                    if not state_matches(current, expected[1]):
                        raise ValueError("request_codec")
                elif current is not expected[1]:
                    raise ValueError("request_codec")
            current_names = {
                name for name, value in request_module.__dict__.items()
                if type(value) is types.FunctionType and value.__module__ == request_module.__name__
            }
            current_global_names = {
                name for name in request_module.__dict__
                if not name.startswith("__") and name not in current_names
            }
            if current_names != sibling_function_names \
                    or current_global_names != sibling_global_names:
                raise ValueError("request_codec")
            for name, expected in sibling_functions.items():
                if not state_matches(getattr(request_module, name, None), expected):
                    raise ValueError("request_codec")
            for name, expected in sibling_globals.items():
                current = getattr(request_module, name, None)
                if expected[0] == "value":
                    if type(current) is not expected[1] or current != expected[2]:
                        raise ValueError("request_codec")
                elif current is not expected[1]:
                    raise ValueError("request_codec")
            for module, name, expected in dependencies:
                if not state_matches(getattr(module, name, None), expected):
                    raise ValueError("dependency")
        except refusal:
            raise
        except Exception:
            raise refusal("ordinary_access_evidence") from None

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
        return (json_dumps(value, sort_keys=True, separators=(",", ":"),
                           ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")

    def uint32(value):
        return type(value) is int and 0 <= value <= 0xFFFFFFFF

    def int32(value):
        return type(value) is int and -0x80000000 <= value <= 0x7FFFFFFF

    def digest(value):
        return type(value) is str and digest_pattern.fullmatch(value) is not None

    def luid(value):
        return type(value) is list and len(value) == 2 \
            and uint32(value[0]) and int32(value[1])

    def sid(value):
        if type(value) is not str or len(value) > 184:
            return False
        parts = value.split("-")
        if len(parts) < 4 or parts[0] != "S" \
                or any(decimal_pattern.fullmatch(part) is None for part in parts[1:]):
            return False
        numbers = [int(part) for part in parts[1:]]
        return numbers[0] == 1 and numbers[1] <= (1 << 48) - 1 \
            and 1 <= len(numbers[2:]) <= 15 \
            and all(number <= 0xFFFFFFFF for number in numbers[2:])

    def token_profile(value, original):
        if type(value) is not dict or set(value) != token_keys \
                or not sid(value["userSid"]) \
                or not all(luid(value[name]) for name in (
                    "tokenId", "authenticationId", "modifiedId", "origin")) \
                or not uint32(value["isAppContainerRaw"]) \
                or type(value["isAppContainer"]) is not bool \
                or value["isAppContainer"] is not (value["isAppContainerRaw"] != 0) \
                or value["isAppContainerRaw"] != 0 or value["isAppContainer"] is not False:
            raise ValueError("token")
        if original:
            if value["tokenType"] != "TokenPrimary" or value["impersonationLevel"] is not None:
                raise ValueError("token")
        elif value["tokenType"] != "TokenImpersonation" \
                or type(value["impersonationLevel"]) is not int \
                or value["impersonationLevel"] != 2:
            raise ValueError("token")
        for group in value["groups"]:
            if type(group) is not dict or set(group) != {"sid", "attributes"} \
                    or not sid(group["sid"]) or not uint32(group["attributes"]):
                raise ValueError("group")
        for privilege in value["privileges"]:
            if type(privilege) is not dict or set(privilege) != {"luid", "attributes"} \
                    or not luid(privilege["luid"]) or not uint32(privilege["attributes"]):
                raise ValueError("privilege")
        if type(value["groups"]) is not list or len(value["groups"]) > 4096 \
                or type(value["privileges"]) is not list or len(value["privileges"]) > 4096 \
                or type(value["restrictingSids"]) is not list \
                or value["restrictingSids"] != []:
            raise ValueError("token_lists")

    def request_document(request):
        guard()
        if type(request) is not bytes:
            raise ValueError("request")
        raw = request
        snapshot = decode_request(raw)
        guard()
        if type(snapshot) is not request_type or snapshot.bytes() != raw:
            raise ValueError("request")
        document = json_loads(
            raw.decode("ascii"), object_pairs_hook=strict_object,
            parse_constant=lambda _value: (_ for _ in ()).throw(ValueError("constant")),
        )
        if type(document) is not dict or set(document) != request_keys:
            raise ValueError("request")
        return raw, document

    def validate_target(item, requested, role):
        if type(item) is not dict or set(item) != set(requested) | target_extra \
                or item["role"] != role:
            raise ValueError("target")
        for key in requested:
            if item[key] != requested[key] or type(item[key]) is not type(requested[key]):
                raise ValueError("target")
        if not digest(item["descriptorDigest"]) \
                or item["policySatisfied"] is not True \
                or item["functionSuccess"] is not True \
                or item["accessStatus"] is not False \
                or type(item["grantedAccess"]) is not int or item["grantedAccess"] != 0:
            raise ValueError("denial")

    def decode_impl(request, raw):
        request_raw, requested = request_document(request)
        if type(raw) is not bytes or not 1 <= len(raw) <= maximum \
                or not raw.endswith(b"\n") or raw.endswith(b"\n\n"):
            raise ValueError("evidence")
        value = json_loads(
            raw.decode("ascii"), object_pairs_hook=strict_object,
            parse_constant=lambda _value: (_ for _ in ()).throw(ValueError("constant")),
        )
        if type(value) is not dict or set(value) != evidence_keys or json_bytes(value) != raw:
            raise ValueError("evidence")
        for key in request_keys - {"targets"}:
            if value[key] != requested[key] or type(value[key]) is not type(requested[key]):
                raise ValueError("request_repeat")
        expected_digest = request_digest(request_raw)
        guard()
        if value["requestDigest"] != expected_digest \
                or value["provenanceKind"] != "externally_provisioned_windows_principal":
            raise ValueError("binding")
        if type(value["targets"]) is not list or len(value["targets"]) != 3:
            raise ValueError("targets")
        for item, requested_item, role in zip(value["targets"], requested["targets"], roles, strict=True):
            validate_target(item, requested_item, role)
        original = value["originalToken"]
        derived = value["derivedToken"]
        token_profile(original, True)
        token_profile(derived, False)
        current = requested["currentTokenIdentity"]
        if original["userSid"] == "S-1-5-18" or original["userSid"] == current["userSid"] \
                or original["authenticationId"] == current["authenticationId"] \
                or any(group["sid"] == current["userSid"] for group in original["groups"]):
            raise ValueError("principal")
        for key in ("userSid", "authenticationId", "origin", "groups", "privileges",
                    "restrictingSids", "isAppContainerRaw", "isAppContainer"):
            if original[key] != derived[key] or type(original[key]) is not type(derived[key]):
                raise ValueError("derivation")
        if original["tokenId"] == derived["tokenId"]:
            raise ValueError("derivation")
        return result_type(
            structuralOnly=True, requestDigest=expected_digest,
            evidenceDigest=sha256(raw).hexdigest(), boundary=value["boundary"],
            phase=value["phase"], session=value["session"],
        )

    def decode(request, raw):
        try:
            guard()
            result = decode_impl(request, raw)
            guard()
            return result
        except BaseException as primary:
            try:
                guard()
            except BaseException:
                pass
            if isinstance(primary, (KeyboardInterrupt, SystemExit)):
                raise
            raise refusal("ordinary_access_evidence") from None

    return decode


decode = _make_codec()
del _make_codec
del _load_request_codec
