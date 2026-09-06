"""Pure disabled boundary between ADR-020 privilege and transport structures.

This module does not authorize transport success. Its result is structural-only
and carries an explicit false transport-success flag. Native/provider/cache and
successful attest/load behavior remain unavailable.
"""

from __future__ import annotations

import importlib.util
from pathlib import Path
from typing import NamedTuple
import types
import weakref


__all__ = ("OrdinaryStructuralBoundaryRefused",)


class OrdinaryStructuralBoundaryRefused(Exception):
    """Stable refusal without request or authority details."""


class _DisabledStructuralBinding(NamedTuple):
    structuralOnly: bool
    transportSuccessEnabled: bool
    privilegeBindingDigest: str
    requestDigest: str
    boundary: str
    phase: str
    session: str


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
    result_type = _DisabledStructuralBinding
    transport = _TRANSPORT
    authority = _AUTHORITY
    transport_path = _TRANSPORT_PATH
    authority_path = _AUTHORITY_PATH
    request_type = transport._RequestSnapshot
    marker_type = authority._StructuralPrivilegeBinding
    request_values = request_type.values
    marker_properties = tuple(
        (name, marker_type.__dict__[name])
        for name in (
            "structuralOnly",
            "bindingDigest",
            "phase",
            "boundary",
            "session",
        )
    )
    function_pins = (
        (request_values, _callable_state(request_values)),
        (marker_type._value, _callable_state(marker_type._value)),
    )
    module_globals = globals()
    global_pins = (
        ("OrdinaryStructuralBoundaryRefused", refusal),
        ("_DisabledStructuralBinding", result_type),
        ("_TRANSPORT", transport),
        ("_AUTHORITY", authority),
        ("_TRANSPORT_PATH", transport_path),
        ("_AUTHORITY_PATH", authority_path),
    )
    consumed = weakref.WeakKeyDictionary()

    def check():
        try:
            for name, expected in global_pins:
                if module_globals.get(name) is not expected:
                    raise ValueError("global")
            if Path(getattr(transport, "__file__", "")).resolve() != transport_path:
                raise ValueError("transport")
            if Path(getattr(authority, "__file__", "")).resolve() != authority_path:
                raise ValueError("authority")
            if transport._RequestSnapshot is not request_type \
                    or authority._StructuralPrivilegeBinding is not marker_type:
                raise ValueError("type")
            if request_type.__dict__.get("values") is not request_values:
                raise ValueError("method")
            for function, expected in function_pins:
                actual = _callable_state(function)
                if any(left is not right for left, right in zip(actual, expected)):
                    raise ValueError("function")
            for name, expected in marker_properties:
                if marker_type.__dict__.get(name) is not expected:
                    raise ValueError("property")
        except refusal:
            raise
        except Exception:
            raise refusal("ordinary_structural_boundary") from None

    def bind_disabled_structural_attest(*, transport_request, privilege_binding):
        try:
            check()
            if type(transport_request) is not request_type \
                    or type(privilege_binding) is not marker_type \
                    or privilege_binding in consumed:
                raise ValueError("input")
            values = request_values(transport_request)
            if values["method"] != "attest" \
                    or type(values["payload"]) is not types.MappingProxyType:
                raise ValueError("method")
            payload = dict(values["payload"])
            structural = privilege_binding.structuralOnly
            binding = privilege_binding.bindingDigest
            phase = privilege_binding.phase
            boundary = privilege_binding.boundary
            session = privilege_binding.session
            if structural is not True \
                    or payload["phase"] != phase \
                    or payload["boundary"] != boundary \
                    or payload["session"] != session:
                raise ValueError("binding")
            if type(binding) is not str or len(binding) != 64 \
                    or any(character not in "0123456789abcdef"
                           for character in binding):
                raise ValueError("digest")
            consumed[privilege_binding] = True
            result = result_type(
                True,
                False,
                binding,
                values["requestDigest"],
                boundary,
                phase,
                session,
            )
            check()
            return result
        except BaseException as primary:
            if isinstance(primary, (KeyboardInterrupt, SystemExit)):
                raise
            raise refusal("ordinary_structural_boundary") from None

    return bind_disabled_structural_attest


_bind_disabled_structural_attest = _make_boundary()
del _make_boundary
