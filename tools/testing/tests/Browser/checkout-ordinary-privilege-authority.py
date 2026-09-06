"""Pure structural ADR-020 privilege-observation boundary.

The returned marker proves supplied-data consistency only. It is not native
provenance, policy satisfaction, or an admission decision.
"""

from __future__ import annotations

import hashlib as _hashlib
import importlib.util as _importlib_util
import inspect as _inspect
import json as _json
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

    function_pins = tuple(
        (module, name, _callable_state(getattr(module, name)))
        for module, name in (
            (_MANIFEST, "decode"),
            (_START, "decode"),
            (_START, "canonical"),
            (_REQUEST, "decode_request"),
        )
    )

    def class_state(value):
        return tuple(
            (
                name,
                item,
                _callable_state(item) if callable(item) else None,
            )
            for name, item in value.__dict__.items()
        )

    type_pins = tuple(
        (module, name, value, class_state(value))
        for module, name, value in (
            (_MANIFEST, "_AuthorityManifest", _MANIFEST._AuthorityManifest),
            (_START, "_BrokerStartIdentity", _START._BrokerStartIdentity),
            (_REQUEST, "_RequestSnapshot", _REQUEST._RequestSnapshot),
        )
    )
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
        for module, name, expected in function_pins:
            actual = _callable_state(getattr(module, name, None))
            if any(left is not right for left, right in zip(actual, expected)):
                raise ValueError("function")
        for module, name, expected_type, expected_state in type_pins:
            actual_type = getattr(module, name, None)
            if actual_type is not expected_type:
                raise ValueError("type")
            actual_state = class_state(actual_type)
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

    class CaptureCapability:
        __slots__ = ("__responses", "__responses_pin", "__index", "__owner")

        def __new__(cls, token, responses):
            if token is not construction_token:
                raise refusal("ordinary_privilege_authority")
            return object.__new__(cls)

        def __init__(self, token, responses):
            freeze_primitive(responses)
            object.__setattr__(self, "_CaptureCapability__responses", responses)
            object.__setattr__(self, "_CaptureCapability__responses_pin", responses)
            object.__setattr__(self, "_CaptureCapability__index", 0)
            object.__setattr__(self, "_CaptureCapability__owner", None)

        def __setattr__(self, name, value):
            raise refusal("ordinary_privilege_authority")

        def claim(self, owner):
            if self.__owner is not None or self.__index != 0:
                raise refusal("ordinary_privilege_authority")
            object.__setattr__(self, "_CaptureCapability__owner", owner)

        def validate(self, owner, index):
            freeze_primitive(self.__responses)
            if (
                self.__responses is not self.__responses_pin
                or self.__owner is not owner
                or self.__index != index
            ):
                raise refusal("ordinary_privilege_authority")

        def __call__(self):
            if self.__owner is None or self.__index not in (0, 1):
                raise refusal("ordinary_privilege_authority")
            response = self.__responses[self.__index]
            object.__setattr__(self, "_CaptureCapability__index", self.__index + 1)
            if type(response) is tuple and len(response) == 2 \
                    and response[0] == "raise":
                raise response[1]
            return response

    capability_type = CaptureCapability
    if tuple(_inspect.signature(CaptureCapability.__call__).parameters) != ("self",):
        raise refusal("ordinary_privilege_authority")
    capability_call_pin = _callable_state(CaptureCapability.__call__)

    class StructuralPrivilegeBinding:
        __slots__ = (
            "__structural",
            "__binding",
            "__phase",
            "__boundary",
            "__session",
        )

        def __new__(cls, token, structural, binding, phase, boundary, session):
            if token is not construction_token:
                raise refusal("ordinary_privilege_authority")
            return object.__new__(cls)

        def __init__(self, token, structural, binding, phase, boundary, session):
            object.__setattr__(self, "_StructuralPrivilegeBinding__structural", structural)
            object.__setattr__(self, "_StructuralPrivilegeBinding__binding", binding)
            object.__setattr__(self, "_StructuralPrivilegeBinding__phase", phase)
            object.__setattr__(self, "_StructuralPrivilegeBinding__boundary", boundary)
            object.__setattr__(self, "_StructuralPrivilegeBinding__session", session)

        structuralOnly = property(lambda self: self.__structural)
        bindingDigest = property(lambda self: self.__binding)
        phase = property(lambda self: self.__phase)
        boundary = property(lambda self: self.__boundary)
        session = property(lambda self: self.__session)

        def __setattr__(self, name, value):
            raise refusal("ordinary_privilege_authority")

        def __copy__(self):
            raise refusal("ordinary_privilege_authority")

        def __deepcopy__(self, memo):
            return self.__copy__()

        def __reduce__(self):
            return self.__copy__()

        def __reduce_ex__(self, protocol):
            return self.__copy__()

        def __repr__(self):
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
        __slots__ = (
            "__capability",
            "__live",
            "__binding",
            "__initial",
            "__state",
        )

        def __new__(cls, token, capability, live, binding):
            if token is not construction_token:
                raise refusal("ordinary_privilege_authority")
            return object.__new__(cls)

        def __init__(self, token, capability, live, binding):
            object.__setattr__(self, "_PrivilegeLuidAuthority__capability", capability)
            object.__setattr__(self, "_PrivilegeLuidAuthority__live", live)
            object.__setattr__(self, "_PrivilegeLuidAuthority__binding", binding)
            object.__setattr__(self, "_PrivilegeLuidAuthority__initial", None)
            object.__setattr__(self, "_PrivilegeLuidAuthority__state", 0)
            capability.claim(self)

        def __setattr__(self, name, value):
            self._exhaust()
            raise refusal("ordinary_privilege_authority")

        def _exhaust(self):
            object.__setattr__(self, "_PrivilegeLuidAuthority__state", 2)

        def _capture(self, state):
            try:
                if self.__state != state:
                    raise ValueError("state")
                guard_authority()
                guard_internal_types()
                self.__capability.validate(self, state)
                actual_call = _callable_state(type(self.__capability).__call__)
                if any(
                    left is not right
                    for left, right in zip(actual_call, capability_call_pin)
                ):
                    raise ValueError("capability")
                value = self.__capability()
                self.__capability.validate(self, state + 1)
                guard_authority()
                guard_internal_types()
                return validate_observation(value, self.__live)
            except BaseException as primary:
                self._exhaust()
                if isinstance(primary, (KeyboardInterrupt, SystemExit)):
                    raise
                raise refusal("ordinary_privilege_authority") from None

        def initial(self):
            value = self._capture(0)
            object.__setattr__(self, "_PrivilegeLuidAuthority__initial", value)
            object.__setattr__(self, "_PrivilegeLuidAuthority__state", 1)
            return None

        def final(self):
            value = self._capture(1)
            initial = self.__initial
            self._exhaust()
            if value != initial:
                raise refusal("ordinary_privilege_authority")
            guard_internal_types()
            return marker_type(
                construction_token,
                True,
                self.__binding,
                self.__live.phase,
                self.__live.boundary,
                self.__live.session,
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
        return invoke(
            lambda: capability_type(construction_token, (initial, final))
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
            return authority_type(
                construction_token,
                capture_capability,
                live,
                binding,
            )

        return invoke(operation)

    return (
        CaptureCapability,
        StructuralPrivilegeBinding,
        PrivilegeLuidAuthority,
        structural_fixture_capability_only,
        bind_structural_authority,
    )


(
    _CaptureCapability,
    _StructuralPrivilegeBinding,
    _PrivilegeLuidAuthority,
    _structural_fixture_capability_only,
    _bind_structural_authority,
) = _make_api()
del _make_api
