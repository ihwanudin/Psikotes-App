"""Pure ADR-018 request codec; it is not a usable access provider.

The private current-token value is structural evidence only.  Its provenance is
not established until a future private Windows attestor composition supplies it.
"""

from __future__ import annotations

import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import secrets
from types import MappingProxyType
import types


MAX_REQUEST_BYTES = 16 * 1024
MAXIMUM_ALLOWED = 0x02000000
POLICY_DIGEST = "a63c221764f73a54e87513fc91cded6b3fa16825138f6b24b6118132829f4eeb"
DESCRIPTOR_EVIDENCE_DOMAIN = b"checkout-acl-descriptor-evidence-v1\0"
_VERSION = 1
_ROLES = ("coordinator", "run", "source")
_DIGEST = re.compile(r"[a-f0-9]{64}")
_SESSION = re.compile(r"checkout-[a-f0-9]{32}")
_DECIMAL = re.compile(r"0|[1-9][0-9]{0,38}")
_DOS_DEVICE = re.compile(r"(?:con|prn|aux|nul|com[1-9¹²³]|lpt[1-9¹²³])")


class OrdinaryAccessRefused(Exception):
    """Fixed refusal without token, SID, path, or decoder details."""


def _load_acl_codec():
    try:
        path = Path(__file__).resolve().with_name("checkout-acl-attestation.py")
        spec = importlib.util.spec_from_file_location(
            "checkout_acl_attestation_ordinary_request", path,
        )
        if spec is None or spec.loader is None:
            raise ValueError("codec")
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        names = (
            "canonical_request", "decode_request", "request_digest",
            "canonical_evidence", "decode_evidence",
        )
        functions = MappingProxyType({name: getattr(module, name) for name in names})
        authority = MappingProxyType({
            name: (
                value, value.__code__, value.__defaults__,
                value.__kwdefaults__,
                None if value.__kwdefaults__ is None
                else tuple(sorted(value.__kwdefaults__.items())),
                value.__closure__,
            )
            for name, value in module.__dict__.items()
            if type(value) is types.FunctionType and value.__module__ == module.__name__
        })
        globals_authority = MappingProxyType({
            name: (("value", type(value), value)
                   if type(value) in {int, str, bytes, type(None)}
                   else ("identity", value))
            for name, value in module.__dict__.items()
            if not name.startswith("__") and name not in authority
        })
        return path, module, functions, authority, globals_authority
    except Exception:
        raise OrdinaryAccessRefused("ordinary_principal_admission") from None


(_ACL_CODEC_PATH, _ACL_CODEC, _ACL_FUNCTIONS, _ACL_FUNCTION_AUTHORITY,
 _ACL_GLOBALS_AUTHORITY) = _load_acl_codec()
_ACL_CODEC_TYPE = type(_ACL_CODEC)


def _required_acl_codec():
    try:
        if type(_ACL_CODEC) is not _ACL_CODEC_TYPE \
                or Path(getattr(_ACL_CODEC, "__file__", "")).resolve() != _ACL_CODEC_PATH \
                or _ACL_CODEC.POLICY_DIGEST != POLICY_DIGEST:
            raise ValueError("codec")
        for name, function in _ACL_FUNCTIONS.items():
            if getattr(_ACL_CODEC, name, None) is not function:
                raise ValueError("codec")
        current_names = {
            name for name, value in _ACL_CODEC.__dict__.items()
            if type(value) is types.FunctionType
            and value.__module__ == _ACL_CODEC.__name__
        }
        if current_names != set(_ACL_FUNCTION_AUTHORITY):
            raise ValueError("codec")
        for name, expected in _ACL_FUNCTION_AUTHORITY.items():
            function = getattr(_ACL_CODEC, name, None)
            if type(function) is not types.FunctionType:
                raise ValueError("codec")
            kwdefaults = function.__kwdefaults__
            kwdefault_items = (None if kwdefaults is None
                               else tuple(sorted(kwdefaults.items())))
            if function is not expected[0] \
                    or function.__code__ is not expected[1] \
                    or function.__defaults__ is not expected[2] \
                    or kwdefaults is not expected[3] \
                    or kwdefault_items != expected[4] \
                    or function.__closure__ is not expected[5] \
                    or function.__globals__ is not _ACL_CODEC.__dict__:
                raise ValueError("codec")
        for name, expected in _ACL_GLOBALS_AUTHORITY.items():
            value = getattr(_ACL_CODEC, name, None)
            if expected[0] == "value" and (
                    type(value) is not expected[1] or value != expected[2]):
                raise ValueError("codec")
            if expected[0] == "identity" and value is not expected[1]:
                raise ValueError("codec")
        return _ACL_FUNCTIONS
    except OrdinaryAccessRefused:
        raise
    except Exception:
        raise OrdinaryAccessRefused("ordinary_principal_admission") from None


def _strict_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("duplicate")
        result[key] = value
    return result


def _json_bytes(value):
    return (json.dumps(
        value, sort_keys=True, separators=(",", ":"), ensure_ascii=True,
        allow_nan=False,
    ) + "\n").encode("ascii")


def _digest(value):
    return type(value) is str and _DIGEST.fullmatch(value) is not None


def _decimal(value):
    return type(value) is str and _DECIMAL.fullmatch(value) is not None \
        and int(value) <= (1 << 128) - 1


def _sid(value):
    if type(value) is not str or len(value) > 184:
        return False
    parts = value.split("-")
    if len(parts) < 4 or parts[0] != "S" \
            or any(_DECIMAL.fullmatch(part) is None for part in parts[1:]):
        return False
    values = [int(part) for part in parts[1:]]
    return values[0] == 1 and values[1] <= (1 << 48) - 1 \
        and 1 <= len(values[2:]) <= 15 \
        and all(value <= (1 << 32) - 1 for value in values[2:])


def _path(value):
    if type(value) is not str or not 1 <= len(value) <= 4096 \
            or "\\" in value or value.endswith("/") \
            or any(ord(character) < 32 or ord(character) == 127
                   for character in value):
        return False
    drive = re.match(r"^[a-z]:/", value)
    absolute = value.startswith("/") or drive is not None
    remainder = value[3:] if drive else value[1:]
    if not absolute or ":" in remainder:
        return False
    for part in remainder.split("/"):
        if part in {"", ".", ".."} or part.endswith((".", " ")) \
                or any(character in '<>"|?*' for character in part) \
                or _DOS_DEVICE.fullmatch(part.split(".", 1)[0].casefold()):
            return False
    return True


def _luid(value):
    return type(value) is list and len(value) == 2 \
        and type(value[0]) is int and 0 <= value[0] <= 0xFFFFFFFF \
        and type(value[1]) is int and -0x80000000 <= value[1] <= 0x7FFFFFFF


class _CurrentTokenIdentity:
    __slots__ = ("__document",)

    def __init__(self, user_sid, token_id, authentication_id, modified_id):
        if any(type(value) is not tuple for value in (
                token_id, authentication_id, modified_id)):
            raise OrdinaryAccessRefused("ordinary_principal_admission")
        document = {
            "userSid": user_sid,
            "tokenId": list(token_id),
            "authenticationId": list(authentication_id),
            "modifiedId": list(modified_id),
        }
        if set(document) != {
                "userSid", "tokenId", "authenticationId", "modifiedId"} \
                or not _sid(document["userSid"]) \
                or not all(_luid(document[key]) for key in (
                    "tokenId", "authenticationId", "modifiedId",
                )):
            raise OrdinaryAccessRefused("ordinary_principal_admission")
        object.__setattr__(self, "_CurrentTokenIdentity__document",
                           copy.deepcopy(document))

    def __setattr__(self, _name, _value):
        raise OrdinaryAccessRefused("ordinary_principal_admission")

    def _document_copy(self):
        return copy.deepcopy(self.__document)


def _current_token_identity(user_sid, token_id, authentication_id, modified_id):
    try:
        return _CurrentTokenIdentity(
            user_sid, token_id, authentication_id, modified_id,
        )
    except OrdinaryAccessRefused:
        raise
    except Exception:
        raise OrdinaryAccessRefused("ordinary_principal_admission") from None


def _freeze(value):
    if type(value) is dict:
        return MappingProxyType({key: _freeze(item) for key, item in value.items()})
    if type(value) is list:
        return tuple(_freeze(item) for item in value)
    return value


class _RequestSnapshot:
    __slots__ = ("__raw", "__document")

    def __init__(self, raw, document):
        if type(raw) is not bytes or type(document) is not dict:
            raise OrdinaryAccessRefused("ordinary_principal_admission")
        object.__setattr__(self, "_RequestSnapshot__raw", raw)
        object.__setattr__(self, "_RequestSnapshot__document", _freeze(document))

    def __setattr__(self, _name, _value):
        raise OrdinaryAccessRefused("ordinary_principal_admission")

    def bytes(self):
        return self.__raw

    def values(self):
        return self.__document


def _request_shape(value):
    keys = {
        "version", "boundary", "phase", "session", "configBinding",
        "leaseBinding", "policyDigest", "aclRequestDigest",
        "aclEvidenceDigest", "aclDescriptorEvidenceDigest", "challenge",
        "currentTokenIdentity", "targets",
    }
    if type(value) is not dict or set(value) != keys \
            or type(value["version"]) is not int or value["version"] != 1 \
            or type(value["boundary"]) is not str \
            or value["boundary"] not in {"anchor", "execution"} \
            or type(value["phase"]) is not str \
            or value["phase"] not in {"fresh", "recovery"} \
            or type(value["session"]) is not str \
            or _SESSION.fullmatch(value["session"]) is None \
            or not all(_digest(value[key]) for key in (
                "configBinding", "leaseBinding", "policyDigest",
                "aclRequestDigest", "aclEvidenceDigest",
                "aclDescriptorEvidenceDigest", "challenge",
            )) or value["policyDigest"] != POLICY_DIGEST:
        return False
    identity = value["currentTokenIdentity"]
    if type(identity) is not dict or set(identity) != {
            "userSid", "tokenId", "authenticationId", "modifiedId"} \
            or not _sid(identity["userSid"]) \
            or not all(_luid(identity[key]) for key in (
                "tokenId", "authenticationId", "modifiedId",
            )):
        return False
    targets = value["targets"]
    if type(targets) is not list or len(targets) != 3:
        return False
    for target, role in zip(targets, _ROLES, strict=True):
        if type(target) is not dict or set(target) != {
                "role", "path", "volumeSerial", "fileId", "ownerSid",
                "daclDigest", "reparse", "desiredAccess"} \
                or target["role"] != role or not _path(target["path"]) \
                or not _decimal(target["volumeSerial"]) \
                or not _decimal(target["fileId"]) \
                or target["ownerSid"] != identity["userSid"] \
                or not _digest(target["daclDigest"]) \
                or type(target["reparse"]) is not bool \
                or target["reparse"] is not False \
                or type(target["desiredAccess"]) is not int \
                or target["desiredAccess"] != MAXIMUM_ALLOWED:
            return False
    return len({target["path"].casefold() for target in targets}) == 3 \
        and len({(target["volumeSerial"], target["fileId"])
                 for target in targets}) == 3


def _decode(raw):
    if type(raw) is not bytes or not 1 <= len(raw) <= MAX_REQUEST_BYTES:
        raise ValueError("bounds")
    value = json.loads(
        raw.decode("ascii"), object_pairs_hook=_strict_object,
        parse_constant=lambda _value: (_ for _ in ()).throw(ValueError("constant")),
    )
    if not _request_shape(value) or raw != _json_bytes(value):
        raise ValueError("request")
    return value


def decode_request(raw):
    try:
        value = _decode(raw)
        return _RequestSnapshot(raw, value)
    except OrdinaryAccessRefused:
        raise
    except Exception:
        raise OrdinaryAccessRefused("ordinary_principal_admission") from None


def request_digest(raw):
    snapshot = decode_request(raw)
    return hashlib.sha256(snapshot.bytes()).hexdigest()


def _descriptor_evidence_digest(targets):
    selected = [{key: target[key] for key in (
        "daclDigest", "fileId", "ownerSid", "path", "policySatisfied",
        "reparse", "role", "volumeSerial",
    )} for target in targets]
    return hashlib.sha256(
        DESCRIPTOR_EVIDENCE_DOMAIN + _json_bytes(selected),
    ).hexdigest()


def _build_request_with_challenge(outer_request_raw, outer_evidence_raw,
                                  current_identity, challenge):
    try:
        if type(current_identity) is not _CurrentTokenIdentity \
                or not _digest(challenge):
            raise ValueError("input")
        codec = _required_acl_codec()
        outer_request = codec["decode_request"](outer_request_raw)
        if codec["canonical_request"](outer_request) != outer_request_raw:
            raise ValueError("request")
        outer_evidence = codec["decode_evidence"](
            outer_evidence_raw, outer_request,
        )
        if codec["canonical_evidence"](
                outer_evidence, outer_request) != outer_evidence_raw \
                or outer_evidence["requestDigest"] \
                != codec["request_digest"](outer_request) \
                or challenge == outer_request["challenge"]:
            raise ValueError("evidence")
        _required_acl_codec()

        identity = current_identity._document_copy()
        targets = []
        for evidence, role in zip(outer_evidence["targets"], _ROLES, strict=True):
            if evidence["role"] != role or evidence["ownerSid"] != identity["userSid"] \
                    or evidence["reparse"] is not False \
                    or evidence["policySatisfied"] is not True:
                raise ValueError("target")
            targets.append({
                "role": evidence["role"], "path": evidence["path"],
                "volumeSerial": evidence["volumeSerial"],
                "fileId": evidence["fileId"], "ownerSid": evidence["ownerSid"],
                "daclDigest": evidence["daclDigest"], "reparse": False,
                "desiredAccess": MAXIMUM_ALLOWED,
            })
        request = {
            "version": _VERSION,
            "boundary": outer_request["boundary"],
            "phase": outer_request["phase"],
            "session": outer_request["session"],
            "configBinding": outer_request["configBinding"],
            "leaseBinding": outer_request["leaseBinding"],
            "policyDigest": POLICY_DIGEST,
            "aclRequestDigest": hashlib.sha256(outer_request_raw).hexdigest(),
            "aclEvidenceDigest": hashlib.sha256(outer_evidence_raw).hexdigest(),
            "aclDescriptorEvidenceDigest": _descriptor_evidence_digest(
                outer_evidence["targets"],
            ),
            "challenge": challenge,
            "currentTokenIdentity": identity,
            "targets": targets,
        }
        raw = _json_bytes(request)
        if len(raw) > MAX_REQUEST_BYTES or not _request_shape(request):
            raise ValueError("request")
        _required_acl_codec()
        return raw
    except OrdinaryAccessRefused:
        raise
    except Exception:
        raise OrdinaryAccessRefused("ordinary_principal_admission") from None


def _new_challenge():
    return secrets.token_hex(32)


def build_request(outer_request_raw, outer_evidence_raw, current_identity):
    try:
        return _build_request_with_challenge(
            outer_request_raw, outer_evidence_raw, current_identity,
            _new_challenge(),
        )
    except OrdinaryAccessRefused:
        raise
    except Exception:
        raise OrdinaryAccessRefused("ordinary_principal_admission") from None
