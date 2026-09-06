"""Pure canonical codec for the static ADR-019 host authority manifest.

This module validates supplied bytes only.  It does not provision, discover, or
attest any Windows account, service, ACL, pipe, firewall rule, or filesystem.
"""

from __future__ import annotations

import hashlib
import json
import re
from types import MappingProxyType
import types
from typing import NamedTuple


MAX_MANIFEST_BYTES = 32 * 1024
POLICY_DIGEST = "a63c221764f73a54e87513fc91cded6b3fa16825138f6b24b6118132829f4eeb"
_DOMAIN = b"oncam.checkout.ordinary-authority-manifest.v1\0"
_DIGEST = re.compile(r"[a-f0-9]{64}")
_DECIMAL = re.compile(r"0|[1-9][0-9]*")
_DOS_DEVICE = re.compile(r"(?:con|prn|aux|nul|com[1-9]|lpt[1-9])", re.I)
_TOP = {"account", "bindings", "createdAt", "firewall", "generation", "machine",
        "pipe", "rotationState", "service", "version"}
_PRIVILEGES = ["SeChangeNotifyPrivilege", "SeImpersonatePrivilege"]
_REQUIRED_PRIVILEGES = ["SeImpersonatePrivilege"]
_DENIED_RIGHTS = ["SeDenyBatchLogonRight", "SeDenyInteractiveLogonRight",
                  "SeDenyNetworkLogonRight", "SeDenyRemoteInteractiveLogonRight"]
_DEPENDENCIES = (
    (json, "dumps", json.dumps, type(json.dumps), json.dumps.__code__),
    (json, "loads", json.loads, type(json.loads), json.loads.__code__),
    (hashlib, "sha256", hashlib.sha256, type(hashlib.sha256), None),
    (re, "fullmatch", re.fullmatch, type(re.fullmatch), re.fullmatch.__code__),
)
_JSON_DUMPS = json.dumps
_JSON_LOADS = json.loads
_SHA256 = hashlib.sha256
_RE_FULLMATCH = re.fullmatch


class OrdinaryAuthorityRefused(Exception):
    """Stable refusal with no supplied host details."""


class _AuthorityManifest(NamedTuple):
    digest: str
    generation: int
    createdAt: str
    rotationState: str
    accountSid: str
    serviceSid: str
    checkoutSid: str
    binaryPath: str
    binaryDigest: str
    candidateDigest: str
    coordinatorDigest: str
    machineIdentityDigest: str
    runtimeTokenGroupPolicyDigest: str
    runtimeTokenGroupPolicyVersion: int


def _strict_object(pairs):
    _require_authority()
    value = {}
    for key, item in pairs:
        if key in value:
            raise ValueError("duplicate")
        value[key] = item
    _require_authority()
    return value


def _require_dependencies():
    for module, name, function, function_type, code in _DEPENDENCIES:
        current = getattr(module, name, None)
        if current is not function or type(current) is not function_type \
                or (code is not None and current.__code__ is not code):
            raise ValueError("dependency")


def _json_bytes(value):
    return (_JSON_DUMPS(value, sort_keys=True, separators=(",", ":"),
                        ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")


def _keys(value, keys):
    return type(value) is dict and set(value) == set(keys)


def _integer(value, low, high):
    return type(value) is int and low <= value <= high


def _digest(value):
    return type(value) is str and _DIGEST.fullmatch(value) is not None


def _sid(value):
    if type(value) is not str or len(value) > 184:
        return False
    parts = value.split("-")
    if len(parts) < 4 or parts[0] != "S" or any(_DECIMAL.fullmatch(x) is None for x in parts[1:]):
        return False
    numbers = [int(x) for x in parts[1:]]
    return numbers[0] == 1 and numbers[1] <= (1 << 48) - 1 \
        and 1 <= len(numbers[2:]) <= 15 \
        and all(x <= (1 << 32) - 1 for x in numbers[2:])


def _path(value):
    if type(value) is not str or not 4 <= len(value) <= 4096 \
            or re.fullmatch(r"[a-z]:/.*", value) is None or "\\" in value \
            or value.endswith("/") or any(ord(c) < 32 or ord(c) > 126 for c in value):
        return False
    remainder = value[3:]
    if ":" in remainder:
        return False
    for part in remainder.split("/"):
        if not part or len(part) > 255 or part in {".", ".."} or part.endswith((".", " ")) \
                or any(c in '<>"|?*' for c in part) \
                or _DOS_DEVICE.fullmatch(part.split(".", 1)[0].rstrip(" .")):
            return False
    return True


def _timestamp(value):
    match = _RE_FULLMATCH(r"(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})Z", value or "")
    if match is None or type(value) is not str:
        return False
    year, month, day, hour, minute, second = map(int, match.groups())
    leap = year % 4 == 0 and (year % 100 != 0 or year % 400 == 0)
    days = [31, 29 if leap else 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31]
    return 1 <= year <= 9999 and 1 <= month <= 12 and 1 <= day <= days[month - 1] \
        and hour <= 23 and minute <= 59 and second <= 59


def _exact_dacl(value, owner, aces):
    return _keys(value, {"ownerSid", "protected", "aces"}) \
        and value["ownerSid"] == owner and value["protected"] is True \
        and value["aces"] == aces


def _validate(value):
    if not _keys(value, _TOP) or not _integer(value["version"], 1, 1) \
            or not _integer(value["generation"], 1, (1 << 63) - 1) \
            or not _timestamp(value["createdAt"]) or value["rotationState"] != "active":
        raise ValueError("manifest")

    machine = value["machine"]
    if not _keys(machine, {"architecture", "identityDigest", "minimumBuild", "osFamily"}) \
            or machine["architecture"] != "amd64" or machine["osFamily"] != "windows" \
            or not _digest(machine["identityDigest"]) \
            or not _integer(machine["minimumBuild"], 1, (1 << 31) - 1):
        raise ValueError("machine")

    account = value["account"]
    if not _keys(account, {"accountSid", "allowedPrivilegeNames", "checkoutSid",
                           "directSamMembershipSids", "requiredPrivilegeNames",
                           "runtimeTokenGroupPolicyDigest", "runtimeTokenGroupPolicyVersion",
                           "serviceSid", "userRights"}):
        raise ValueError("account")
    sids = (account["accountSid"], account["serviceSid"], account["checkoutSid"])
    if not all(_sid(x) for x in sids) or len(set(sids)) != 3 \
            or "S-1-5-18" in sids or not account["serviceSid"].startswith("S-1-5-80-") \
            or account["allowedPrivilegeNames"] != _PRIVILEGES \
            or account["requiredPrivilegeNames"] != _REQUIRED_PRIVILEGES \
            or not _digest(account["runtimeTokenGroupPolicyDigest"]) \
            or not _integer(account["runtimeTokenGroupPolicyVersion"], 1, 1):
        raise ValueError("account")
    memberships = account["directSamMembershipSids"]
    if type(memberships) is not list or not memberships or memberships != sorted(set(memberships)) \
            or not all(_sid(x) for x in memberships) or memberships != ["S-1-5-32-545"]:
        raise ValueError("membership")
    rights = account["userRights"]
    if not _keys(rights, {"denied", "granted"}) \
            or rights["granted"] != ["SeServiceLogonRight"] or rights["denied"] != _DENIED_RIGHTS:
        raise ValueError("rights")

    service = value["service"]
    service_keys = {"binaryDigest", "binaryPath", "dynamicArgumentsAllowed",
                    "dynamicEnvironmentAllowed", "interactive", "name", "networkListener",
                    "recoveryActions", "requiredPrivilegeNames", "serviceObjectDacl",
                    "serviceSidType", "singleInstance", "startPolicy", "type"}
    if not _keys(service, service_keys) or service["name"] != "OncamCheckoutOrdinaryBroker" \
            or service["type"] != "SERVICE_WIN32_OWN_PROCESS" \
            or service["startPolicy"] != "demand" or service["serviceSidType"] != "unrestricted" \
            or not _path(service["binaryPath"]) or not _digest(service["binaryDigest"]) \
            or service["requiredPrivilegeNames"] != _REQUIRED_PRIVILEGES \
            or service["recoveryActions"] != [] or service["interactive"] is not False \
            or service["networkListener"] is not False or service["singleInstance"] is not True \
            or service["dynamicArgumentsAllowed"] is not False \
            or service["dynamicEnvironmentAllowed"] is not False:
        raise ValueError("service")
    service_aces = [
        {"rights": ["SERVICE_ALL_ACCESS"], "sid": "S-1-5-18", "type": "allow"},
        {"rights": ["SERVICE_ALL_ACCESS"], "sid": "S-1-5-32-544", "type": "allow"},
        {"rights": ["SERVICE_QUERY_STATUS"], "sid": account["checkoutSid"], "type": "allow"},
    ]
    if not _exact_dacl(service["serviceObjectDacl"], "S-1-5-18", service_aces):
        raise ValueError("service_acl")

    pipe = value["pipe"]
    pipe_keys = {"direction", "firstPipeInstance", "handlesInheritable", "maxInstances",
                 "maxRequestBytes", "maxResponseBytes", "mode", "name", "rejectRemoteClients",
                 "securityDescriptor", "timeoutMs"}
    if not _keys(pipe, pipe_keys) or pipe["name"] != "\\\\.\\pipe\\oncam-checkout-ordinary-v1" \
            or pipe["direction"] != "duplex" or pipe["mode"] != "message" \
            or pipe["firstPipeInstance"] is not True or pipe["rejectRemoteClients"] is not True \
            or pipe["handlesInheritable"] is not False or not _integer(pipe["maxInstances"], 1, 1) \
            or not _integer(pipe["maxRequestBytes"], 20 * 1024, 20 * 1024) \
            or not _integer(pipe["maxResponseBytes"], 36 * 1024, 36 * 1024) \
            or not _integer(pipe["timeoutMs"], 1, 60000):
        raise ValueError("pipe")
    pipe_rights = ["FILE_READ_DATA", "FILE_WRITE_DATA", "SYNCHRONIZE"]
    pipe_aces = [{"rights": pipe_rights, "sid": account["checkoutSid"], "type": "allow"},
                 {"rights": pipe_rights, "sid": account["serviceSid"], "type": "allow"}]
    if not _exact_dacl(pipe["securityDescriptor"], account["accountSid"], pipe_aces):
        raise ValueError("pipe_acl")

    firewall = value["firewall"]
    firewall_keys = {"action", "direction", "enabled", "localAddresses", "profiles", "program",
                     "protocol", "remoteAddresses", "ruleName", "serviceName"}
    if not _keys(firewall, firewall_keys) or firewall["action"] != "block" \
            or firewall["direction"] != "outbound" or firewall["enabled"] is not True \
            or firewall["profiles"] != ["domain", "private", "public"] \
            or firewall["program"] != service["binaryPath"] or firewall["protocol"] != "any" \
            or firewall["localAddresses"] != "any" or firewall["remoteAddresses"] != "any" \
            or firewall["serviceName"] != service["name"] \
            or firewall["ruleName"] != "Oncam Checkout Ordinary Broker Outbound Block":
        raise ValueError("firewall")

    bindings = value["bindings"]
    if not _keys(bindings, {"candidateDigest", "coordinatorDigest", "policyDigest", "rootRelations"}) \
            or bindings["policyDigest"] != POLICY_DIGEST \
            or not _digest(bindings["candidateDigest"]) or not _digest(bindings["coordinatorDigest"]):
        raise ValueError("bindings")
    roots = bindings["rootRelations"]
    if not _keys(roots, {"coordinatorRoot", "runRoot", "sourceRelativePath"}) \
            or not _path(roots["coordinatorRoot"]) or not _path(roots["runRoot"]) \
            or roots["runRoot"] != roots["coordinatorRoot"] + "/runs" \
            or roots["sourceRelativePath"] != "source":
        raise ValueError("roots")
    return account, service, machine, bindings


def canonical(value):
    try:
        _require_authority()
        _validate(value)
        raw = _json_bytes(value)
        if len(raw) > MAX_MANIFEST_BYTES:
            raise ValueError("size")
        _require_authority()
        return raw
    except OrdinaryAuthorityRefused:
        raise
    except Exception:
        raise OrdinaryAuthorityRefused("ordinary_authority_manifest") from None


def decode(raw):
    try:
        _require_authority()
        if type(raw) is not bytes or not 1 <= len(raw) <= MAX_MANIFEST_BYTES \
                or not raw.endswith(b"\n") or raw.endswith(b"\n\n"):
            raise ValueError("bytes")
        text = raw.decode("ascii")
        value = _JSON_LOADS(text, object_pairs_hook=_strict_object,
                            parse_constant=lambda _: (_ for _ in ()).throw(ValueError("constant")))
        _require_authority()
        account, service, machine, bindings = _validate(value)
        if _json_bytes(value) != raw:
            raise ValueError("canonical")
        digest = _SHA256(_DOMAIN + raw).hexdigest()
        result = _AuthorityManifest(
            digest=digest, generation=value["generation"], createdAt=value["createdAt"],
            rotationState=value["rotationState"], accountSid=account["accountSid"],
            serviceSid=account["serviceSid"], checkoutSid=account["checkoutSid"],
            binaryPath=service["binaryPath"], binaryDigest=service["binaryDigest"],
            candidateDigest=bindings["candidateDigest"],
            coordinatorDigest=bindings["coordinatorDigest"],
            machineIdentityDigest=machine["identityDigest"],
            runtimeTokenGroupPolicyDigest=account["runtimeTokenGroupPolicyDigest"],
            runtimeTokenGroupPolicyVersion=account["runtimeTokenGroupPolicyVersion"],
        )
        _require_authority()
        return result
    except OrdinaryAuthorityRefused:
        raise
    except Exception:
        raise OrdinaryAuthorityRefused("ordinary_authority_manifest") from None


_AUTHORITY_NAMES = (
    "json", "hashlib", "re", "types", "MappingProxyType",
    "MAX_MANIFEST_BYTES", "POLICY_DIGEST", "_DOMAIN", "_DIGEST", "_DECIMAL",
    "_DOS_DEVICE", "_TOP", "_PRIVILEGES", "_REQUIRED_PRIVILEGES",
    "_DENIED_RIGHTS", "_DEPENDENCIES", "_JSON_DUMPS", "_JSON_LOADS",
    "_SHA256", "_RE_FULLMATCH", "_AuthorityManifest", "OrdinaryAuthorityRefused",
)


def _frozen_value(value):
    if type(value) is list:
        return ("list", tuple(_frozen_value(item) for item in value))
    if type(value) is tuple:
        return ("tuple", tuple(_frozen_value(item) for item in value))
    if type(value) is set:
        return ("set", tuple(sorted((_frozen_value(item) for item in value), key=repr)))
    if type(value) is dict:
        return ("dict", tuple(sorted(((key, _frozen_value(item)) for key, item in value.items()),
                                     key=lambda item: repr(item[0]))))
    if isinstance(value, re.Pattern):
        return ("regex", value.pattern, value.flags)
    if type(value) in {str, bytes, int, type(None)}:
        return ("value", type(value), value)
    return ("identity", value)


def _seal_authority():
    globals_state = MappingProxyType({
        name: (globals()[name], _frozen_value(globals()[name])) for name in _AUTHORITY_NAMES
    })
    excluded = frozenset({"canonical", "decode", "_require_authority", "_seal_authority"})
    functions_state = MappingProxyType({
        name: (value, value.__code__, value.__defaults__, value.__kwdefaults__, value.__closure__)
        for name, value in globals().items()
        if type(value) is types.FunctionType and value.__module__ == __name__
        and name not in excluded
    })
    module_globals = globals()
    freeze = _frozen_value
    require_dependencies = _require_dependencies
    refusal = OrdinaryAuthorityRefused

    def guard():
        try:
            for name, expected in globals_state.items():
                current = module_globals.get(name)
                if current is not expected[0] or freeze(current) != expected[1]:
                    raise ValueError("authority")
            current_functions = {
                name for name, value in module_globals.items()
                if type(value) is types.FunctionType and value.__module__ == __name__
                and name not in excluded
            }
            if current_functions != set(functions_state):
                raise ValueError("authority")
            for name, expected in functions_state.items():
                current = module_globals.get(name)
                if type(current) is not types.FunctionType or current is not expected[0] \
                        or current.__code__ is not expected[1] \
                        or current.__defaults__ is not expected[2] \
                        or current.__kwdefaults__ is not expected[3] \
                        or current.__closure__ is not expected[4] \
                        or current.__globals__ is not module_globals:
                    raise ValueError("authority")
            require_dependencies()
        except refusal:
            raise
        except Exception:
            raise refusal("ordinary_authority_manifest") from None

    return guard


_require_authority = _seal_authority()
del _seal_authority


def _bind_public(guard, canonical_impl, decode_impl, refusal):
    def invoke(operation, argument):
        try:
            guard()
            result = operation(argument)
            guard()
            return result
        except BaseException as primary:
            try:
                guard()
            except BaseException:
                pass
            if isinstance(primary, (KeyboardInterrupt, SystemExit)):
                raise
            raise refusal("ordinary_authority_manifest") from None

    def guarded_canonical(value):
        return invoke(canonical_impl, value)

    def guarded_decode(raw):
        return invoke(decode_impl, raw)

    return guarded_canonical, guarded_decode


canonical, decode = _bind_public(
    _require_authority, canonical, decode, OrdinaryAuthorityRefused,
)
del _bind_public
