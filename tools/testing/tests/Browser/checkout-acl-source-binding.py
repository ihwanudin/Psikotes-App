"""Pure binding of canonical ADR-017 boundaries to recomputed source summaries.

This validates supplied canonical data only. It does not attest a filesystem,
ACL policy efficacy, source completeness, or runtime provenance.
"""

from __future__ import annotations

import importlib.util
from pathlib import Path
from types import MappingProxyType
import types


class SourceBindingRefused(Exception):
    """Fixed refusal without path, identity, manifest, or sibling details."""


def _fingerprint(value):
    value_type = type(value)
    if value_type in {int, str, bytes, bool, type(None)}:
        return ("value", value_type, value)
    if value_type is tuple:
        return ("tuple", value, tuple(_fingerprint(item) for item in value))
    if value_type is frozenset:
        return (
            "frozenset", value,
            frozenset(_fingerprint(item) for item in value),
        )
    if value_type is set:
        return ("set", value, frozenset(_fingerprint(item) for item in value))
    if value_type is dict:
        return (
            "dict", value,
            tuple((_fingerprint(key), _fingerprint(item))
                  for key, item in value.items()),
        )
    if hasattr(value, "pattern") and hasattr(value, "flags"):
        return ("regex", value, value.pattern, value.flags)
    return ("identity", value)


def _load_sibling(filename, module_name):
    try:
        path = Path(__file__).resolve().with_name(filename)
        spec = importlib.util.spec_from_file_location(module_name, path)
        if spec is None or spec.loader is None:
            raise ValueError("sibling")
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        functions = MappingProxyType({
            name: value for name, value in module.__dict__.items()
            if type(value) is types.FunctionType
            and value.__module__ == module.__name__
        })
        function_authority = MappingProxyType({
            name: (
                value, value.__code__, value.__defaults__, value.__kwdefaults__,
                None if value.__kwdefaults__ is None
                else tuple(sorted(value.__kwdefaults__.items())),
                value.__closure__,
            ) for name, value in functions.items()
        })
        global_authority = MappingProxyType({
            name: _fingerprint(value)
            for name, value in module.__dict__.items()
            if not name.startswith("__") and name not in functions
        })
        return (
            path, module, type(module), functions,
            function_authority, global_authority,
        )
    except Exception:
        raise SourceBindingRefused("acl_source_binding") from None


_ACL_AUTHORITY = _load_sibling(
    "checkout-acl-attestation.py", "checkout_acl_attestation_source_binding",
)
_SOURCE_AUTHORITY = _load_sibling(
    "checkout-acl-source-tree.py", "checkout_acl_source_tree_source_binding",
)
_ACL_CODEC = _ACL_AUTHORITY[1]
_SOURCE_TREE = _SOURCE_AUTHORITY[1]


def _validate_fingerprint(value, expected):
    kind = expected[0]
    if kind == "value":
        return type(value) is expected[1] and value == expected[2]
    if kind == "tuple":
        return type(value) is tuple and value is expected[1] \
            and tuple(_fingerprint(item) for item in value) == expected[2]
    if kind == "frozenset":
        return type(value) is frozenset and value is expected[1] \
            and frozenset(_fingerprint(item) for item in value) == expected[2]
    if kind == "set":
        return type(value) is set and value is expected[1] \
            and frozenset(_fingerprint(item) for item in value) == expected[2]
    if kind == "dict":
        return type(value) is dict and value is expected[1] \
            and tuple((_fingerprint(key), _fingerprint(item))
                      for key, item in value.items()) == expected[2]
    if kind == "regex":
        return value is expected[1] and value.pattern == expected[2] \
            and value.flags == expected[3]
    return value is expected[1]


def _require_sibling(authority):
    path, module, module_type, functions, function_authority, globals_authority = authority
    if type(module) is not module_type \
            or Path(getattr(module, "__file__", "")).resolve() != path:
        raise SourceBindingRefused("acl_source_binding")
    current_function_names = {
        name for name, value in module.__dict__.items()
        if type(value) is types.FunctionType and value.__module__ == module.__name__
    }
    if current_function_names != set(function_authority):
        raise SourceBindingRefused("acl_source_binding")
    for name, expected in function_authority.items():
        function = getattr(module, name, None)
        if type(function) is not types.FunctionType:
            raise SourceBindingRefused("acl_source_binding")
        kwdefaults = function.__kwdefaults__
        kwdefault_items = (None if kwdefaults is None
                           else tuple(sorted(kwdefaults.items())))
        if function is not expected[0] or function.__code__ is not expected[1] \
                or function.__defaults__ is not expected[2] \
                or kwdefaults is not expected[3] \
                or kwdefault_items != expected[4] \
                or function.__closure__ is not expected[5] \
                or function.__globals__ is not module.__dict__:
            raise SourceBindingRefused("acl_source_binding")
    for name, expected in globals_authority.items():
        if name not in module.__dict__ \
                or not _validate_fingerprint(module.__dict__[name], expected):
            raise SourceBindingRefused("acl_source_binding")
    return functions


def _required_siblings():
    return _require_sibling(_ACL_AUTHORITY), _require_sibling(_SOURCE_AUTHORITY)


def _sibling_call(which, name, *args):
    acl, source = _required_siblings()
    functions = acl if which == "acl" else source
    if name not in functions:
        raise SourceBindingRefused("acl_source_binding")
    result = functions[name](*args)
    _required_siblings()
    return result


def _freeze(value):
    if type(value) is dict:
        return MappingProxyType({key: _freeze(item) for key, item in value.items()})
    if type(value) is list:
        return tuple(_freeze(item) for item in value)
    return value


class _SourceBindingSnapshot:
    __slots__ = ("__values",)

    def __init__(self, source_identity, summary):
        values = {
            "sourceRootIdentity": {
                "volumeSerial": source_identity["volumeSerial"],
                "fileId": source_identity["fileId"],
            },
            "algorithm": summary["algorithm"],
            "descendantCount": summary["descendantCount"],
            "digest": summary["digest"],
        }
        object.__setattr__(self, "_SourceBindingSnapshot__values", _freeze(values))

    def __setattr__(self, _name, _value):
        raise SourceBindingRefused("acl_source_binding")

    def values(self):
        return self.__values


def bind_source_boundaries(*, anchor_request_raw, anchor_evidence_raw,
                           anchor_manifest, anchor_records,
                           execution_request_raw, execution_evidence_raw,
                           execution_manifest, execution_records):
    try:
        _required_siblings()
        anchor_request = _sibling_call(
            "acl", "decode_request", anchor_request_raw,
        )
        if _sibling_call("acl", "canonical_request", anchor_request) \
                != anchor_request_raw:
            raise ValueError("request")
        anchor_evidence = _sibling_call(
            "acl", "decode_evidence", anchor_evidence_raw, anchor_request,
        )
        if _sibling_call(
                "acl", "canonical_evidence", anchor_evidence, anchor_request,
        ) != anchor_evidence_raw:
            raise ValueError("evidence")

        execution_request = _sibling_call(
            "acl", "decode_request", execution_request_raw,
        )
        if _sibling_call("acl", "canonical_request", execution_request) \
                != execution_request_raw:
            raise ValueError("request")
        execution_evidence = _sibling_call(
            "acl", "decode_evidence", execution_evidence_raw,
            execution_request,
        )
        if _sibling_call(
                "acl", "canonical_evidence", execution_evidence,
                execution_request,
        ) != execution_evidence_raw:
            raise ValueError("evidence")

        _sibling_call(
            "acl", "validate_boundary_pair",
            anchor_request, anchor_evidence,
            execution_request, execution_evidence,
        )
        anchor_source = anchor_evidence["targets"][2]
        execution_source = execution_evidence["targets"][2]
        anchor_identity = {
            "volumeSerial": anchor_source["volumeSerial"],
            "fileId": anchor_source["fileId"],
        }
        execution_identity = {
            "volumeSerial": execution_source["volumeSerial"],
            "fileId": execution_source["fileId"],
        }
        anchor_summary = _sibling_call(
            "source", "summarize", anchor_manifest, anchor_records,
            anchor_identity,
        )
        execution_summary = _sibling_call(
            "source", "summarize", execution_manifest, execution_records,
            execution_identity,
        )
        if _sibling_call(
                "source", "compare_boundaries",
                anchor_summary, execution_summary,
        ) is not True:
            raise ValueError("summary")
        _required_siblings()
        return _SourceBindingSnapshot(anchor_identity, anchor_summary)
    except SourceBindingRefused:
        raise
    except Exception:
        raise SourceBindingRefused("acl_source_binding") from None
