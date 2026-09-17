"""Pure structural ADR-020 privilege-observation boundary.

The returned marker proves supplied-data consistency only. It is not native
provenance, policy satisfaction, or an admission decision, and this unpackaged
module is not an authorization boundary. Code already able to mutate private
``__closure__`` cells or vault objects in this interpreter is explicitly out of
scope; pure Python cannot honestly resist that reflective capability.
"""

from __future__ import annotations

import hashlib as _hashlib
import importlib.util as _importlib_util
import inspect as _inspect
import json as _json
import weakref as _weakref
from pathlib import Path as _Path
from typing import NamedTuple as _NamedTuple


__all__ = ("OrdinaryPrivilegeAuthorityRefused",)


class OrdinaryPrivilegeAuthorityRefused(Exception):
    """Stable refusal without supplied privilege or identity details."""


class _LiveBinding(_NamedTuple):
    manifestDigest: str
    machineIdentityDigest: str
    accountSid: str
    serviceSid: str
    brokerStartDigest: str
    servicePid: int
    processCreationTime: str
    brokerAuthenticationId: tuple
    brokerTokenId: tuple
    requestDigest: str
    boundary: str
    phase: str
    session: str
    configBinding: str
    leaseBinding: str
    policyDigest: str
    aclRequestDigest: str
    aclEvidenceDigest: str
    aclDescriptorEvidenceDigest: str
    challenge: str
    currentTokenIdentity: tuple


class _PrivilegeObservation(_NamedTuple):
    entries: tuple
    liveBinding: _LiveBinding


def _load_fixed(label, filename):
    path = _Path(__file__).resolve().with_name(filename)
    spec = _importlib_util.spec_from_file_location(label, path)
    if spec is None or spec.loader is None:
        raise OrdinaryPrivilegeAuthorityRefused("ordinary_privilege_authority")
    module = _importlib_util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


_MANIFEST = _load_fixed(
    "_pa_manifest", "checkout-ordinary-authority-manifest.py"
)
_START = _load_fixed(
    "_pa_start", "checkout-ordinary-broker-start-identity.py"
)
_REQUEST = _load_fixed(
    "_pa_request", "checkout-ordinary-access-request.py"
)


def _callable_state(value):
    return (
        value,
        type(value),
        getattr(value, "__code__", None),
        getattr(value, "__defaults__", None),
        getattr(value, "__kwdefaults__", None),
        getattr(value, "__closure__", None),
        getattr(value, "__globals__", None),
    )


def _make_api():
    refusal = OrdinaryPrivilegeAuthorityRefused
    live_type = _LiveBinding
    observation_type = _PrivilegeObservation
    names = (
        "SeBackupPrivilege",
        "SeRestorePrivilege",
        "SeTakeOwnershipPrivilege",
    )
    construction_token = object()
    module_globals = globals()
    modules = (_MANIFEST, _START, _REQUEST)

    def class_state(value):
        return tuple(
            (
                name,
                item,
                _callable_state(item) if callable(item) else None,
            )
            for name, item in value.__dict__.items()
        )

    dependency_functions = []
    dependency_globals = []
    dependency_closures = []
    dependency_types = []
    visited = set()

    def seal_dependency(value):
        if len(visited) > 512:
            raise refusal("ordinary_privilege_authority")
        identity = id(value)
        if identity in visited:
            return
        visited.add(identity)
        if isinstance(value, type):
            dependency_types.append((value, class_state(value)))
            for item in value.__dict__.values():
                if callable(item):
                    seal_dependency(item)
            return
        if not callable(value):
            return
        state = _callable_state(value)
        dependency_functions.append((value, state))
        code = state[2]
        function_globals = state[6]
        if code is not None and type(function_globals) is dict:
            for name in code.co_names:
                if name in function_globals:
                    item = function_globals[name]
                    dependency_globals.append((function_globals, name, item))
                    if callable(item) and getattr(item, "__module__", None) \
                            == getattr(value, "__module__", None):
                        seal_dependency(item)
        closure = state[5]
        if closure is not None:
            for cell in closure:
                item = cell.cell_contents
                dependency_closures.append((cell, item))
                if callable(item):
                    seal_dependency(item)

    for root in (
        _MANIFEST.decode,
        _START.decode,
        _START.canonical,
        _REQUEST.decode_request,
    ):
        seal_dependency(root)
    global_pins = (
        ("OrdinaryPrivilegeAuthorityRefused", refusal),
        ("_LiveBinding", live_type),
        ("_PrivilegeObservation", observation_type),
        ("_MANIFEST", modules[0]),
        ("_START", modules[1]),
        ("_REQUEST", modules[2]),
    )

    def guard_authority():
        for name, expected in global_pins:
            if module_globals.get(name) is not expected:
                raise ValueError("authority")
        for expected_function, expected in dependency_functions:
            actual = _callable_state(expected_function)
            if any(left is not right for left, right in zip(actual, expected)):
                raise ValueError("function")
        for namespace, name, expected in dependency_globals:
            if namespace.get(name) is not expected:
                raise ValueError("helper")
        for cell, expected in dependency_closures:
            if cell.cell_contents is not expected:
                raise ValueError("closure")
        for expected_type, expected_state in dependency_types:
            actual_state = class_state(expected_type)
            if len(actual_state) != len(expected_state):
                raise ValueError("type")
            for actual, expected in zip(actual_state, expected_state):
                if actual[0] != expected[0] or actual[1] is not expected[1]:
                    raise ValueError("type")
                if expected[2] is not None and any(
                    left is not right
                    for left, right in zip(actual[2], expected[2])
                ):
                    raise ValueError("type")

    def invoke(operation):
        try:
            guard_authority()
            result = operation()
            guard_authority()
            return result
        except BaseException as primary:
            if isinstance(primary, (KeyboardInterrupt, SystemExit)):
                raise
            raise refusal("ordinary_privilege_authority") from None

    def valid_digest(value):
        return (
            type(value) is str
            and len(value) == 64
            and all(character in "0123456789abcdef" for character in value)
        )

    def freeze_primitive(value, depth=0):
        if depth > 32:
            raise ValueError("capability")
        if value is None or type(value) in (bool, int, str, bytes):
            return (type(value), value)
        if type(value) is live_type:
            return (live_type, freeze_primitive(tuple(value), depth + 1))
        if type(value) is observation_type:
            return (
                observation_type,
                freeze_primitive(value.entries, depth + 1),
                freeze_primitive(value.liveBinding, depth + 1),
            )
        if (
            type(value) is tuple
            and len(value) == 2
            and type(value[0]) is str
            and value[0] == "raise"
            and isinstance(value[1], BaseException)
        ):
            return (tuple, "raise", type(value[1]), id(value[1]))
        if type(value) is tuple and len(value) <= 256:
            return (
                tuple,
                tuple(freeze_primitive(item, depth + 1) for item in value),
            )
        if type(value) is frozenset and len(value) <= 256:
            frozen = tuple(freeze_primitive(item, depth + 1) for item in value)
            return (frozenset, tuple(sorted(frozen, key=lambda item: str(item[0]))))
        raise ValueError("capability")

    capability_vault = _weakref.WeakKeyDictionary()
    authority_vault = _weakref.WeakKeyDictionary()
    marker_vault = _weakref.WeakKeyDictionary()
    maximum_live_objects = 64

    class CaptureCapability:
        __slots__ = ("__weakref__",)

        def __new__(cls, token, responses):
            if token is not construction_token:
                raise refusal("ordinary_privilege_authority")
            return object.__new__(cls)

        def __init__(self, token, responses):
            pass

        def claim(self, owner):
            state = capability_vault.get(self)
            if state is None or state[2] is not None:
                raise refusal("ordinary_privilege_authority")
            capability_vault[self] = (state[0], state[1], _weakref.ref(owner))

        def validate(self, owner, index):
            state = capability_vault.get(self)
            if state is None or state[1] != index or state[2] is None \
                    or state[2]() is not owner:
                raise refusal("ordinary_privilege_authority")
            freeze_primitive(state[0])

        def __call__(self):
            state = capability_vault.get(self)
            if state is None or state[1] not in (0, 1) or state[2] is None \
                    or state[2]() is None:
                raise refusal("ordinary_privilege_authority")
            response = state[0][state[1]]
            capability_vault[self] = (state[0], state[1] + 1, state[2])
            if type(response) is tuple and len(response) == 2 \
                    and response[0] == "raise":
                raise response[1]
            return response

    capability_type = CaptureCapability
    if tuple(_inspect.signature(CaptureCapability.__call__).parameters) != ("self",):
        raise refusal("ordinary_privilege_authority")
    capability_call_pin = _callable_state(CaptureCapability.__call__)

    class StructuralPrivilegeBinding:
        __slots__ = ("__weakref__",)

        def __new__(cls, token, structural, binding, phase, boundary, session):
            if token is not construction_token:
                raise refusal("ordinary_privilege_authority")
            if len(marker_vault) >= maximum_live_objects:
                raise refusal("ordinary_privilege_authority")
            marker = object.__new__(cls)
            marker_vault[marker] = (
                structural, binding, phase, boundary, session
            )
            return marker

        def __init__(self, token, structural, binding, phase, boundary, session):
            pass

        def _value(self, index):
            state = marker_vault.get(self)
            if state is None:
                raise refusal("ordinary_privilege_authority")
            return state[index]

        structuralOnly = property(lambda self: self._value(0))
        bindingDigest = property(lambda self: self._value(1))
        phase = property(lambda self: self._value(2))
        boundary = property(lambda self: self._value(3))
        session = property(lambda self: self._value(4))

        def __copy__(self):
            raise refusal("ordinary_privilege_authority")

        def __deepcopy__(self, memo):
            return self.__copy__()

        def __reduce__(self):
            return self.__copy__()

        def __reduce_ex__(self, protocol):
            return self.__copy__()

        def __repr__(self):
            self._value(1)
            return "_StructuralPrivilegeBinding(structuralOnly=True)"

    marker_type = StructuralPrivilegeBinding

    def decode_bindings(manifest_raw, start_raw, request_raw, machine_digest):
        if not all(
            type(raw) is bytes for raw in (manifest_raw, start_raw, request_raw)
        ) or not valid_digest(machine_digest):
            raise ValueError("input")
        manifest = modules[0].decode(manifest_raw)
        guard_authority()
        start = modules[1].decode(start_raw)
        guard_authority()
        request = modules[2].decode_request(request_raw)
        guard_authority()
        request_values = request.values()
        manifest_values = _json.loads(manifest_raw.decode("ascii"))
        current = request_values["currentTokenIdentity"]
        if (
            request.bytes() != request_raw
            or start.manifestDigest != manifest.digest
            or start.accountSid != manifest.accountSid
            or start.serviceSid != manifest.serviceSid
            or machine_digest != manifest.machineIdentityDigest
            or request_values["policyDigest"]
            != manifest_values["bindings"]["policyDigest"]
            or current["userSid"] != manifest.checkoutSid
        ):
            raise ValueError("binding")
        current_identity = (
            current["userSid"],
            current["tokenId"],
            current["authenticationId"],
            current["modifiedId"],
        )
        live = live_type(
            manifest.digest,
            machine_digest,
            manifest.accountSid,
            manifest.serviceSid,
            start.digest,
            start.servicePid,
            start.processCreationTime,
            start.authenticationId,
            start.tokenId,
            _hashlib.sha256(request_raw).hexdigest(),
            request_values["boundary"],
            request_values["phase"],
            request_values["session"],
            request_values["configBinding"],
            request_values["leaseBinding"],
            request_values["policyDigest"],
            request_values["aclRequestDigest"],
            request_values["aclEvidenceDigest"],
            request_values["aclDescriptorEvidenceDigest"],
            request_values["challenge"],
            current_identity,
        )
        payload = {
            "brokerStart": _hashlib.sha256(start_raw).hexdigest(),
            "machineIdentityDigest": machine_digest,
            "manifest": _hashlib.sha256(manifest_raw).hexdigest(),
            "request": _hashlib.sha256(request_raw).hexdigest(),
        }
        canonical = (
            _json.dumps(
                payload,
                sort_keys=True,
                separators=(",", ":"),
                ensure_ascii=True,
            )
            + "\n"
        ).encode("ascii")
        binding = _hashlib.sha256(
            b"checkout-ordinary-privilege-authority-binding-v1\0" + canonical
        ).hexdigest()
        return live, binding

    def validate_observation(value, expected_live):
        if (
            type(value) is not observation_type
            or type(value.liveBinding) is not live_type
            or value.liveBinding != expected_live
            or type(value.entries) is not tuple
            or len(value.entries) != 3
        ):
            raise ValueError("observation")
        seen = set()
        for entry, name in zip(value.entries, names, strict=True):
            if type(entry) is not tuple or len(entry) != 7 or entry[0] != name:
                raise ValueError("entry")
            (
                low,
                high,
                original_present,
                original_enabled,
                derived_present,
                derived_enabled,
            ) = entry[1:]
            if (
                type(low) is not int
                or not 0 <= low <= 0xFFFFFFFF
                or type(high) is not int
                or not -0x80000000 <= high <= 0x7FFFFFFF
                or any(
                    type(flag) is not bool
                    for flag in (
                        original_present,
                        original_enabled,
                        derived_present,
                        derived_enabled,
                    )
                )
                or original_present != derived_present
                or original_enabled
                or derived_enabled
                or (low, high) in seen
            ):
                raise ValueError("policy")
            seen.add((low, high))
        return value.entries

    class PrivilegeLuidAuthority:
        __slots__ = ("__weakref__",)

        def __new__(cls, token, capability, live, binding):
            if token is not construction_token:
                raise refusal("ordinary_privilege_authority")
            return object.__new__(cls)

        def __init__(self, token, capability, live, binding):
            pass

        def _exhaust(self):
            state = authority_vault.pop(self, None)
            if state is not None:
                capability_vault.pop(state[0], None)

        def _capture(self, expected_state):
            try:
                state = authority_vault.get(self)
                if state is None or state[3] != expected_state:
                    raise ValueError("state")
                guard_authority()
                guard_internal_types()
                capability = state[0]
                capability.validate(self, expected_state)
                actual_call = _callable_state(type(capability).__call__)
                if any(
                    left is not right
                    for left, right in zip(actual_call, capability_call_pin)
                ):
                    raise ValueError("capability")
                value = capability()
                capability.validate(self, expected_state + 1)
                guard_authority()
                guard_internal_types()
                return validate_observation(value, state[1])
            except BaseException as primary:
                self._exhaust()
                if isinstance(primary, (KeyboardInterrupt, SystemExit)):
                    raise
                raise refusal("ordinary_privilege_authority") from None

        def initial(self):
            value = self._capture(0)
            state = authority_vault.get(self)
            if state is None:
                self._exhaust()
                raise refusal("ordinary_privilege_authority")
            authority_vault[self] = (
                state[0], state[1], state[2], 1, value
            )
            return None

        def final(self):
            value = self._capture(1)
            state = authority_vault.get(self)
            if state is None:
                self._exhaust()
                raise refusal("ordinary_privilege_authority")
            live = state[1]
            binding = state[2]
            initial = state[4]
            self._exhaust()
            if value != initial:
                raise refusal("ordinary_privilege_authority")
            guard_internal_types()
            return marker_type(
                construction_token,
                True,
                binding,
                live.phase,
                live.boundary,
                live.session,
            )

        def __copy__(self):
            self._exhaust()
            raise refusal("ordinary_privilege_authority")

        def __deepcopy__(self, memo):
            return self.__copy__()

        def __reduce__(self):
            return self.__copy__()

        def __reduce_ex__(self, protocol):
            return self.__copy__()

    authority_type = PrivilegeLuidAuthority
    internal_type_pins = tuple(
        (value, class_state(value))
        for value in (capability_type, marker_type, authority_type)
    )

    def guard_internal_types():
        for value, expected_state in internal_type_pins:
            if value.__hash__ is not object.__hash__ \
                    or value.__eq__ is not object.__eq__:
                raise ValueError("identity")
            actual_state = class_state(value)
            if len(actual_state) != len(expected_state):
                raise ValueError("internal_type")
            for actual, expected in zip(actual_state, expected_state):
                if actual[0] != expected[0] or actual[1] is not expected[1]:
                    raise ValueError("internal_type")
                if expected[2] is not None and any(
                    left is not right
                    for left, right in zip(actual[2], expected[2])
                ):
                    raise ValueError("internal_type")

    def structural_fixture_capability_only(initial, final):
        def operation():
            if len(capability_vault) >= maximum_live_objects:
                raise ValueError("capacity")
            responses = (initial, final)
            freeze_primitive(responses)
            capability = capability_type(construction_token, responses)
            capability_vault[capability] = (responses, 0, None)
            return capability

        return invoke(operation)

    def structural_fixture_capture_count_only(capability):
        state = capability_vault.get(capability)
        if state is None:
            raise refusal("ordinary_privilege_authority")
        return state[1]

    def structural_fixture_vault_counts_only():
        return (
            len(capability_vault),
            len(authority_vault),
            len(marker_vault),
        )

    def bind_structural_authority(
        *,
        manifest_raw,
        broker_start_raw,
        request_raw,
        machine_identity_digest,
        capture_capability,
    ):
        def operation():
            if type(capture_capability) is not capability_type:
                raise ValueError("capability")
            live, binding = decode_bindings(
                manifest_raw,
                broker_start_raw,
                request_raw,
                machine_identity_digest,
            )
            if len(authority_vault) >= maximum_live_objects:
                raise ValueError("capacity")
            authority = authority_type(
                construction_token,
                capture_capability,
                live,
                binding,
            )
            capture_capability.claim(authority)
            authority_vault[authority] = (
                capture_capability, live, binding, 0, None
            )
            return authority

        return invoke(operation)

    return (
        CaptureCapability,
        StructuralPrivilegeBinding,
        PrivilegeLuidAuthority,
        structural_fixture_capability_only,
        structural_fixture_capture_count_only,
        structural_fixture_vault_counts_only,
        bind_structural_authority,
    )


(
    _CaptureCapability,
    _StructuralPrivilegeBinding,
    _PrivilegeLuidAuthority,
    _structural_fixture_capability_only,
    _structural_fixture_capture_count_only,
    _structural_fixture_vault_counts_only,
    _bind_structural_authority,
) = _make_api()
del _make_api
