"""Pure structural-only bridge from privilege observations to broker transport.

The private result is not an authorization or transport-success decision. Native
provider/cache behavior and successful attest/load behavior remain unavailable.
"""

from __future__ import annotations

import hashlib
import importlib.util
import json
from pathlib import Path
import types
import weakref

__all__ = ("OrdinaryStructuralBoundaryRefused",)


class OrdinaryStructuralBoundaryRefused(Exception):
    """Stable refusal without request or authority details."""


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


def _load_fixed(label, filename):
    try:
        path = Path(__file__).resolve().with_name(filename)
        spec = importlib.util.spec_from_file_location(label, path)
        if spec is None or spec.loader is None:
            raise ValueError("module")
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return path, module
    except Exception:
        raise OrdinaryStructuralBoundaryRefused(
            "ordinary_structural_boundary"
        ) from None


_TRANSPORT_PATH, _TRANSPORT = _load_fixed(
    "_ordinary_structural_transport",
    "checkout-ordinary-broker-transport.py",
)
_AUTHORITY_PATH, _AUTHORITY = _load_fixed(
    "_ordinary_structural_privilege",
    "checkout-ordinary-privilege-authority.py",
)


def _make_boundary():
    refusal = OrdinaryStructuralBoundaryRefused
    transport = _TRANSPORT
    authority = _AUTHORITY
    transport_path = _TRANSPORT_PATH
    authority_path = _AUTHORITY_PATH
    request_type = transport._RequestSnapshot
    marker_type = authority._StructuralPrivilegeBinding
    manifest_module = authority._MANIFEST
    start_module = authority._START
    ordinary_module = authority._REQUEST
    request_values = request_type.values
    request_payload_bytes = request_type.payload_bytes
    manifest_decode = manifest_module.decode
    start_decode = start_module.decode
    ordinary_decode = ordinary_module.decode_request
    ordinary_values = ordinary_module._RequestSnapshot.values
    ordinary_bytes = ordinary_module._RequestSnapshot.bytes
    sha256 = hashlib.sha256
    json_dumps = json.dumps
    json_loads = json.loads
    marker_properties = tuple(
        (name, marker_type.__dict__[name])
        for name in (
            "structuralOnly", "bindingDigest", "phase", "boundary", "session"
        )
    )
    function_pins = tuple(
        (function, _callable_state(function))
        for function in (
            request_values,
            request_payload_bytes,
            marker_type._value,
            manifest_decode,
            start_decode,
            ordinary_decode,
            ordinary_values,
            ordinary_bytes,
        )
    )
    consumed = weakref.WeakKeyDictionary()
    result_values = weakref.WeakKeyDictionary()
    result_token = object()

    class DisabledStructuralBinding:
        __slots__ = ("__weakref__",)

        def __new__(cls, token, values=None):
            if token is not result_token or type(values) is not tuple:
                raise refusal("ordinary_structural_boundary")
            instance = object.__new__(cls)
            result_values[instance] = values
            return instance

        def __setattr__(self, _name, _value):
            raise refusal("ordinary_structural_boundary")

        def _value(self, index):
            try:
                return result_values[self][index]
            except Exception:
                raise refusal("ordinary_structural_boundary") from None

        structuralOnly = property(lambda self: self._value(0))
        privilegeBindingDigest = property(lambda self: self._value(1))
        requestDigest = property(lambda self: self._value(2))
        boundary = property(lambda self: self._value(3))
        phase = property(lambda self: self._value(4))
        session = property(lambda self: self._value(5))

        def __repr__(self):
            self._value(0)
            return "_DisabledStructuralBinding(structuralOnly=True)"

    result_type = DisabledStructuralBinding
    result_class_pins = tuple(
        (name, value)
        for name, value in result_type.__dict__.items()
        if name not in {"__dict__", "__weakref__"}
    )
    module_globals = globals()
    global_pins = (
        ("OrdinaryStructuralBoundaryRefused", refusal),
        ("_TRANSPORT", transport),
        ("_AUTHORITY", authority),
        ("_TRANSPORT_PATH", transport_path),
        ("_AUTHORITY_PATH", authority_path),
        ("hashlib", hashlib),
        ("json", json),
    )

    def check():
        try:
            for name, expected in global_pins:
                if module_globals.get(name) is not expected:
                    raise ValueError("global")
            if module_globals.get("_DisabledStructuralBinding") is not result_type:
                raise ValueError("result")
            if Path(getattr(transport, "__file__", "")).resolve() != transport_path:
                raise ValueError("transport")
            if Path(getattr(authority, "__file__", "")).resolve() != authority_path:
                raise ValueError("authority")
            if transport._RequestSnapshot is not request_type:
                raise ValueError("request")
            if request_type.__dict__.get("values") is not request_values \
                    or request_type.__dict__.get(
                        "payload_bytes"
                    ) is not request_payload_bytes:
                raise ValueError("request")
            if authority._StructuralPrivilegeBinding is not marker_type:
                raise ValueError("marker")
            if authority._MANIFEST is not manifest_module \
                    or authority._START is not start_module \
                    or authority._REQUEST is not ordinary_module:
                raise ValueError("authority")
            if manifest_module.decode is not manifest_decode \
                    or start_module.decode is not start_decode \
                    or ordinary_module.decode_request is not ordinary_decode \
                    or hashlib.sha256 is not sha256 \
                    or json.dumps is not json_dumps \
                    or json.loads is not json_loads:
                raise ValueError("dependency")
            for function, expected in function_pins:
                actual = _callable_state(function)
                if any(left is not right for left, right in zip(actual, expected)):
                    raise ValueError("function")
            for name, expected in marker_properties:
                if marker_type.__dict__.get(name) is not expected:
                    raise ValueError("property")
            current_names = {
                name for name in result_type.__dict__
                if name not in {"__dict__", "__weakref__"}
            }
            if current_names != {name for name, _ in result_class_pins}:
                raise ValueError("result")
            for name, expected in result_class_pins:
                if result_type.__dict__.get(name) is not expected:
                    raise ValueError("result")
        except refusal:
            raise
        except Exception:
            raise refusal("ordinary_structural_boundary") from None

    def expected_binding(manifest_raw, start_raw, request_raw, machine_digest):
        if not all(
            type(raw) is bytes for raw in (manifest_raw, start_raw, request_raw)
        ) or type(machine_digest) is not str:
            raise ValueError("input")
        manifest = manifest_decode(manifest_raw)
        check()
        start = start_decode(start_raw)
        check()
        ordinary = ordinary_decode(request_raw)
        check()
        ordinary_document = ordinary_values(ordinary)
        if ordinary_bytes(ordinary) != request_raw:
            raise ValueError("request")
        check()
        manifest_document = json_loads(manifest_raw.decode("ascii"))
        current = ordinary_document["currentTokenIdentity"]
        if (
            start.manifestDigest != manifest.digest
            or start.accountSid != manifest.accountSid
            or start.serviceSid != manifest.serviceSid
            or machine_digest != manifest.machineIdentityDigest
            or ordinary_document["policyDigest"]
            != manifest_document["bindings"]["policyDigest"]
            or current["userSid"] != manifest.checkoutSid
        ):
            raise ValueError("binding")
        payload = {
            "brokerStart": sha256(start_raw).hexdigest(),
            "machineIdentityDigest": machine_digest,
            "manifest": sha256(manifest_raw).hexdigest(),
            "request": sha256(request_raw).hexdigest(),
        }
        canonical = (
            json_dumps(
                payload,
                sort_keys=True,
                separators=(",", ":"),
                ensure_ascii=True,
            )
            + "\n"
        ).encode("ascii")
        return sha256(
            b"checkout-ordinary-privilege-authority-binding-v1\0" + canonical
        ).hexdigest()

    def bind_disabled_structural_attest(
        *, manifest_raw, broker_start_raw, machine_identity_digest,
        transport_request, privilege_binding,
    ):
        try:
            if type(privilege_binding) is not marker_type \
                    or privilege_binding in consumed:
                raise ValueError("marker")
            # The first attempt owns and irreversibly exhausts the marker, even
            # when later supplied data or dependency validation refuses.
            consumed[privilege_binding] = True
            check()
            if type(transport_request) is not request_type:
                raise ValueError("request")
            values = request_values(transport_request)
            request_raw = request_payload_bytes(transport_request)
            check()
            if values["method"] != "attest" \
                    or type(values["payload"]) is not types.MappingProxyType:
                raise ValueError("method")
            payload = dict(values["payload"])
            structural = privilege_binding.structuralOnly
            binding = privilege_binding.bindingDigest
            phase = privilege_binding.phase
            boundary = privilege_binding.boundary
            session = privilege_binding.session
            if (
                structural is not True
                or payload["phase"] != phase
                or payload["boundary"] != boundary
                or payload["session"] != session
                or binding != expected_binding(
                    manifest_raw, broker_start_raw, request_raw,
                    machine_identity_digest,
                )
            ):
                raise ValueError("binding")
            check()
            result = result_type(
                result_token,
                (True, binding, values["requestDigest"], boundary, phase, session),
            )
            check()
            return result
        except BaseException as primary:
            if isinstance(primary, (KeyboardInterrupt, SystemExit)):
                raise
            raise refusal("ordinary_structural_boundary") from None

    return bind_disabled_structural_attest, result_type


_bind_disabled_structural_attest, _DisabledStructuralBinding = _make_boundary()
del _make_boundary
