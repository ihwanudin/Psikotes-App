"""Native, point-in-time journal descriptor/token stability observation.

This test-only boundary compares handle identity, a protected owner-only DACL,
and the current process token profile immediately before and after the accepted
native storage primitive.  It returns only ``stabilityObservationOnly`` data.
It does not publish ADR-021/I18 evidence or prove ACL efficacy, ordinary-user
denial, DPAPI/TPM protection, durability, reboot/offline rollback resistance,
freshness, trust, admission, or runtime safety.  Every file observation ends
when its handle closes and therefore leaves an external path-open TOCTOU.
"""

from __future__ import annotations

import ctypes
import hashlib
import importlib.util
import json
from pathlib import Path
import sys
import types
from types import MappingProxyType


class WindowsJournalStabilityRefused(Exception):
    """Fixed refusal without path, SID, token, request, or native detail."""


__all__ = ("WindowsJournalStabilityRefused", "observe_stability")
RESULT_FIELDS = (
    "stabilityObservationOnly", "journalIdentityDigest", "ownerSidDigest",
    "processTokenProfileDigest", "providerAuthorityDigest",
    "providerEvidenceDigest", "providerGeneration", "policyDigest",
    "requestDigest", "securityDescriptorDigest", "storageEvidenceDigest",
    "stabilityObservationDigest",
)
_DOMAIN = b"oncam.checkout.windows-journal-stability.v1\0"


def _load(filename, name):
    path = Path(__file__).resolve().with_name(filename)
    spec = importlib.util.spec_from_file_location(name, path)
    if spec is None or spec.loader is None:
        raise WindowsJournalStabilityRefused("windows_journal_stability")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


_REQUEST = _load(
    "checkout-protected-journal-request.py", "_journal_stability_request",
)
_STORAGE = _load(
    "checkout-windows-protected-journal-provider.py", "_journal_stability_storage",
)
_ACL = _load(
    "checkout-windows-acl-attestor.py", "_journal_stability_acl",
)
del _load


def _refuse():
    raise WindowsJournalStabilityRefused("windows_journal_stability")


def _canonical(value):
    return (json.dumps(value, sort_keys=True, separators=(",", ":"),
                       ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")


def _primitive(value):
    if type(value) is bytes:
        return {"bytesHex": value.hex()}
    if type(value) is tuple:
        return [_primitive(item) for item in value]
    if type(value) in {str, int, bool, type(None)}:
        return value
    _refuse()


def _digest(value):
    return hashlib.sha256(_canonical(value)).hexdigest()


def _bound_descriptor(bundle, handle):
    with _ACL._open_current_process_token(bundle) as process_token:
        token_before = _token_profile(process_token)
        first_functions = _ACL._resolve_security_functions(bundle)
        first = _ACL._one_security_snapshot(bundle, first_functions, handle)
        second_functions = _ACL._resolve_security_functions(bundle)
        if tuple(first_functions.items()) != tuple(second_functions.items()):
            _refuse()
        second = _ACL._one_security_snapshot(bundle, second_functions, handle)
        token_after = _token_profile(process_token)
        if first != second or token_before != token_after:
            _refuse()
        profile = token_before
        user, groups, privileges, sensitive, fixed, restricting, _statistics = profile
        sid_bytes, sid = user
        if first._owner_binding() != (sid_bytes, sid):
            _refuse()
        bound_first = first._bind_process_token(
            sid_bytes, sid, groups, privileges, sensitive, fixed, restricting,
        )
        bound_second = second._bind_process_token(
            sid_bytes, sid, groups, privileges, sensitive, fixed, restricting,
        )
        if bound_first != bound_second:
            _refuse()
        descriptor_state = bound_first._target_policy_state()
        if descriptor_state["accessMask"] != _ACL._TARGET_ACCESS_MASKS["run"]:
            _refuse()
        return descriptor_state, profile


def _token_profile(process_token):
    functions = process_token.validate()
    handle = object.__getattribute__(
        process_token, "_OpenedProcessToken__handle"
    )
    user = process_token._user_snapshot().values()
    groups = process_token._groups_snapshot().values()
    privileges_object = process_token._privileges_snapshot()
    privileges = privileges_object.values()
    sensitive = process_token._sensitive_privileges_snapshot(
        privileges_object
    ).values()
    fixed = process_token._fixed_snapshot().values()

    restricting = process_token._restricted_sids_snapshot().values()

    statistics = _ACL.TOKEN_STATISTICS()
    statistics_size = ctypes.sizeof(statistics)
    statistics_returned = _ACL.DWORD()
    if not functions["GetTokenInformation"](
            handle, _ACL.TokenStatistics, ctypes.byref(statistics),
            statistics_size, ctypes.byref(statistics_returned),
    ) or statistics_returned.value != statistics_size:
        _refuse()
    statistics_value = (
        statistics.TokenId.LowPart, statistics.TokenId.HighPart,
        statistics.AuthenticationId.LowPart,
        statistics.AuthenticationId.HighPart,
        statistics.ExpirationTime, statistics.TokenType,
        statistics.ImpersonationLevel, statistics.DynamicCharged,
        statistics.DynamicAvailable, statistics.GroupCount,
        statistics.PrivilegeCount, statistics.ModifiedId.LowPart,
        statistics.ModifiedId.HighPart,
    )
    process_token.validate()
    return (
        user, groups, privileges, sensitive, fixed, restricting,
        statistics_value,
    )


def _capture(request):
    handle = None
    primary = None
    result = None
    try:
        handle = _STORAGE._open_handle(request["expectedFileIdentity"]["path"])
        identity = _STORAGE._validate_handle(
            handle, request["expectedFileIdentity"]
        )
        bundle = _ACL._load_native()
        descriptor, profile = _bound_descriptor(bundle, handle)
        _STORAGE._validate_handle(handle, request["expectedFileIdentity"])
        descriptor_values = {
            key: _primitive(value) for key, value in descriptor.items()
            if not key.startswith("processToken")
        }
        descriptor_digest = hashlib.sha256(
            b"oncam.checkout.journal-security-descriptor.v1\0"
            + _canonical(descriptor_values)
        ).hexdigest()
        if type(descriptor_digest) is not str or len(descriptor_digest) != 64:
            _refuse()
        result = (
            _digest(identity),
            hashlib.sha256(descriptor["ownerSidBytes"]).hexdigest(),
            descriptor_digest,
            hashlib.sha256(
                b"oncam.checkout.current-process-token-profile.v1\0"
                + _canonical(_primitive(profile))
            ).hexdigest(),
        )
    except BaseException as error:
        primary = error
    if handle is not None:
        try:
            _STORAGE._close(handle)
        except BaseException as error:
            if primary is None:
                primary = error
    if primary is not None:
        raise primary
    if type(result) is not tuple or len(result) != 4:
        _refuse()
    return result


def _compare(before, after):
    if type(before) is not tuple or type(after) is not tuple \
            or len(before) != 4 or len(after) != 4 or before != after:
        _refuse()
    return before


def _validate_storage_result(result, request, request_digest):
    expected = (
        "nativeStorageOnly", "afterGeneration", "afterStateDigest",
        "beforeGeneration", "beforeStateDigest", "journalIdentityDigest",
        "journalKind", "namespace", "requestDigest",
        "finalHandleClosedBeforeReturn", "storageEvidenceDigest",
    )
    if type(result) is not MappingProxyType or tuple(result) != expected \
            or result["nativeStorageOnly"] is not True \
            or result["finalHandleClosedBeforeReturn"] is not True \
            or result["requestDigest"] != request_digest \
            or result["beforeGeneration"] != request["currentState"]["generation"] \
            or result["beforeStateDigest"] != request["currentState"]["rawDigest"] \
            or result["afterGeneration"] != request["proposedState"]["generation"] \
            or result["afterStateDigest"] != request["proposedState"]["rawDigest"] \
            or result["journalKind"] != request["journalKind"] \
            or result["namespace"] != request["namespace"]:
        _refuse()
    return result["storageEvidenceDigest"]


def _observe_impl(request_raw, storage_callable):
    if type(request_raw) is not bytes or storage_callable is not _STORAGE.apply_storage:
        _refuse()
    _REQUEST.decode(request_raw)
    request = json.loads(request_raw.decode("ascii"))
    request_digest = hashlib.sha256(request_raw).hexdigest()
    if request["policyDigest"] != _ACL.ACL_POLICY_DIGEST:
        _refuse()
    before = _capture(request)
    if before[2] != request["securityDescriptorDigest"]:
        _refuse()
    storage_result = storage_callable(request_raw)
    storage_digest = _validate_storage_result(
        storage_result, request, request_digest,
    )
    after = _capture(request)
    identity_digest, owner_digest, descriptor_digest, token_digest = \
        _compare(before, after)
    values = {
        "stabilityObservationOnly": True,
        "journalIdentityDigest": identity_digest,
        "ownerSidDigest": owner_digest,
        "processTokenProfileDigest": token_digest,
        "providerAuthorityDigest": request["providerAuthorityDigest"],
        "providerEvidenceDigest": request["providerEvidenceDigest"],
        "providerGeneration": request["providerGeneration"],
        "policyDigest": request["policyDigest"],
        "requestDigest": request_digest,
        "securityDescriptorDigest": descriptor_digest,
        "storageEvidenceDigest": storage_digest,
    }
    values["stabilityObservationDigest"] = hashlib.sha256(
        _DOMAIN + _canonical(values)
    ).hexdigest()
    if tuple(values) != RESULT_FIELDS:
        _refuse()
    return MappingProxyType(values)


def _canonical_path_for_test(path):
    value = str(Path(path).resolve()).replace("\\", "/")
    return value[0].upper() + value[1:]


def _identity_for_test(path):
    return _STORAGE._identity_for_test(
        _canonical_path_for_test(path), directory=Path(path).is_dir()
    )


def _descriptor_digest_for_test(request_raw):
    _REQUEST.decode(request_raw)
    request = json.loads(request_raw.decode("ascii"))
    return _capture(request)[2]


def _compare_snapshots_for_test(before, after):
    return _compare(before, after)


def _guarded_call(guard, operation, *arguments):
    refusal = WindowsJournalStabilityRefused
    try:
        guard()
        result = operation(*arguments)
        guard()
        return result
    except BaseException as primary:
        try:
            guard()
        except BaseException:
            pass
        if isinstance(primary, (KeyboardInterrupt, SystemExit)):
            raise
        raise refusal("windows_journal_stability") from None


def _invoke_for_test(operation):
    return _guarded_call(_guard, operation)


_PIN_NAMES = (
    "ctypes", "hashlib", "importlib", "json", "Path", "sys", "types",
    "MappingProxyType", "WindowsJournalStabilityRefused", "__all__",
    "RESULT_FIELDS", "_DOMAIN", "_REQUEST", "_STORAGE", "_ACL", "_PIN_NAMES",
)


def _callable_state(value):
    if type(value) is types.MethodType:
        implementation, owner = value.__func__, value.__self__
    else:
        implementation, owner = value, None
    defaults = getattr(implementation, "__defaults__", None)
    kwdefaults = getattr(implementation, "__kwdefaults__", None)
    return (
        type(value), owner, implementation,
        getattr(implementation, "__code__", None),
        defaults, _frozen(defaults), kwdefaults, _frozen(kwdefaults),
        getattr(implementation, "__closure__", None),
        getattr(implementation, "__globals__", None),
    )


def _same_callable(value, expected):
    current = _callable_state(value)
    return all(current[index] is expected[index]
               for index in (0, 1, 2, 3, 4, 6, 8, 9)) \
        and current[5] == expected[5] and current[7] == expected[7]


def _frozen(value):
    if type(value) in {tuple, list}:
        return (type(value).__name__, tuple(_frozen(item) for item in value))
    if type(value) in {dict, MappingProxyType}:
        return (type(value).__name__, tuple(
            (key, _frozen(item)) for key, item in value.items()
        ))
    if type(value) in {str, bytes, int, bool, type(None)}:
        return (type(value), value)
    return ("identity", value)


def _module_state(module):
    functions = {
        name: _callable_state(value)
        for name, value in vars(module).items()
        if type(value) is types.FunctionType and value.__module__ == module.__name__
    }
    constants = {
        name: (value, _frozen(value)) for name, value in vars(module).items()
        if not name.startswith("__")
        and type(value) in {
            str, bytes, int, tuple, list, dict, frozenset, MappingProxyType,
        }
    }
    classes = {
        name: (value, tuple(value.__dict__.items()))
        for name, value in vars(module).items()
        if type(value) is type and value.__module__ == module.__name__
    }
    return functions, constants, classes


def _module_matches(module, state):
    functions, constants, classes = state
    current_function_names = {
        name for name, value in vars(module).items()
        if type(value) is types.FunctionType and value.__module__ == module.__name__
    }
    if current_function_names != set(functions) \
            or any(not _same_callable(getattr(module, name, None), expected)
                   for name, expected in functions.items()):
        return False
    for name, (expected, frozen) in constants.items():
        current = getattr(module, name, None)
        if current is not expected or _frozen(current) != frozen:
            return False
    for name, (expected_class, expected_dictionary) in classes.items():
        current = getattr(module, name, None)
        if current is not expected_class \
                or tuple(current.__dict__.items()) != expected_dictionary \
                or any(left is not right for (_, left), (_, right)
                       in zip(current.__dict__.items(), expected_dictionary)):
            return False
    return True


def _seal():
    module_globals = globals()
    refusal = WindowsJournalStabilityRefused
    pins = {name: module_globals[name] for name in _PIN_NAMES}
    excluded = {"observe_stability", "_seal", "_guard"}
    functions = {
        name: _callable_state(value)
        for name, value in module_globals.items()
        if type(value) is types.FunctionType and value.__module__ == __name__
        and name not in excluded
    }
    dependencies = (
        (_REQUEST, "decode", _callable_state(_REQUEST.decode)),
        (_STORAGE, "apply_storage", _callable_state(_STORAGE.apply_storage)),
        (_STORAGE, "_open_handle", _callable_state(_STORAGE._open_handle)),
        (_STORAGE, "_validate_handle", _callable_state(_STORAGE._validate_handle)),
        (_STORAGE, "_close", _callable_state(_STORAGE._close)),
        (_ACL, "_load_native", _callable_state(_ACL._load_native)),
        (_ACL, "_open_current_process_token",
         _callable_state(_ACL._open_current_process_token)),
        (_ACL, "_resolve_security_functions",
         _callable_state(_ACL._resolve_security_functions)),
        (_ACL, "_one_security_snapshot",
         _callable_state(_ACL._one_security_snapshot)),
        (_ACL, "_match_target_policy", _callable_state(_ACL._match_target_policy)),
        (json, "dumps", _callable_state(json.dumps)),
        (hashlib, "sha256", _callable_state(hashlib.sha256)),
        (ctypes, "byref", _callable_state(ctypes.byref)),
        (ctypes, "create_string_buffer", _callable_state(ctypes.create_string_buffer)),
        (ctypes, "get_last_error", _callable_state(ctypes.get_last_error)),
        (ctypes, "set_last_error", _callable_state(ctypes.set_last_error)),
        (_ACL.ctypes, "WinDLL", _callable_state(_ACL.ctypes.WinDLL)),
        (_ACL.ctypes, "addressof", _callable_state(_ACL.ctypes.addressof)),
        (_ACL.ctypes, "alignment", _callable_state(_ACL.ctypes.alignment)),
        (_ACL.ctypes, "byref", _callable_state(_ACL.ctypes.byref)),
        (_ACL.ctypes, "cast", _callable_state(_ACL.ctypes.cast)),
        (_ACL.ctypes, "create_string_buffer",
         _callable_state(_ACL.ctypes.create_string_buffer)),
        (_ACL.ctypes, "create_unicode_buffer",
         _callable_state(_ACL.ctypes.create_unicode_buffer)),
        (_ACL.ctypes, "get_last_error",
         _callable_state(_ACL.ctypes.get_last_error)),
        (_ACL.ctypes, "sizeof", _callable_state(_ACL.ctypes.sizeof)),
        (_ACL.ctypes, "string_at", _callable_state(_ACL.ctypes.string_at)),
        (_ACL.hashlib, "sha256", _callable_state(_ACL.hashlib.sha256)),
        (_ACL.re, "fullmatch", _callable_state(_ACL.re.fullmatch)),
    )
    callable_same = _same_callable
    module_matches = _module_matches
    acl_state = _module_state(_ACL)
    storage_guard = _STORAGE._require_authority
    storage_guard_state = _callable_state(storage_guard)

    def guard():
        try:
            storage_guard()
            if any(module_globals.get(name) is not expected
                   for name, expected in pins.items()):
                raise ValueError("authority")
            current = {
                name for name, value in module_globals.items()
                if type(value) is types.FunctionType and value.__module__ == __name__
                and name not in excluded
            }
            if current != set(functions):
                raise ValueError("authority")
            if any(not callable_same(module_globals[name], state)
                   for name, state in functions.items()):
                raise ValueError("authority")
            for owner, name, state in dependencies:
                if not callable_same(getattr(owner, name, None), state):
                    raise ValueError("authority")
            if not callable_same(_STORAGE._require_authority,
                                 storage_guard_state) \
                    or not module_matches(_ACL, acl_state):
                raise ValueError("authority")
        except refusal:
            raise
        except Exception:
            raise refusal("windows_journal_stability") from None

    return guard


_guard = _seal()
del _seal


def _bind_public(guard, operation, guarded_call):
    def observe_stability(request_raw, storage_callable):
        return guarded_call(guard, operation, request_raw, storage_callable)

    return observe_stability


observe_stability = _bind_public(
    _guard, _observe_impl, _guarded_call,
)
del _bind_public
