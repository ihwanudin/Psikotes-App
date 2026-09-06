"""Pure strict ADR-019 broker transport-envelope codec.

This module performs no IPC, native calls, filesystem mutation, provisioning,
or evidence caching.  It validates canonical data only.
"""

from __future__ import annotations

import copy
import hashlib
import importlib.util
import json
import pathlib
import types


MAX_REQUEST_BYTES = 20 * 1024
MAX_RESPONSE_BYTES = 36 * 1024
MAX_EVIDENCE_BYTES = 32 * 1024
_VERSION = 1
_METHODS = ("attest", "load", "discard")
_STATUSES = ("ok", "refused")
_ROLES = ("coordinator", "run", "source")
_PROVENANCE = "externally_provisioned_windows_principal"
_PRIMARY = "TokenPrimary"
_IMPERSONATION = "TokenImpersonation"
_SNAPSHOT_MARKER = object()


class BrokerTransportRefused(Exception):
    """Fixed refusal without request, identity, or decoder detail."""


def _callable_state(value):
    defaults = getattr(value, "__defaults__", None)
    kwdefaults = getattr(value, "__kwdefaults__", None)
    closure = getattr(value, "__closure__", None)
    return (
        value, type(value), getattr(value, "__code__", None),
        defaults, None if defaults is None else id(defaults),
        kwdefaults, None if kwdefaults is None else id(kwdefaults),
        None if kwdefaults is None else tuple(sorted(kwdefaults.items())),
        closure, None if closure is None else id(closure),
        getattr(value, "__globals__", None),
        getattr(value, "__module__", None),
        getattr(value, "__qualname__", None),
    )


_DIRECT_DEPENDENCIES = (
    (copy, "deepcopy", _callable_state(copy.deepcopy)),
    (hashlib, "sha256", _callable_state(hashlib.sha256)),
    (json, "dumps", _callable_state(json.dumps)),
    (json, "loads", _callable_state(json.loads)),
)


def _load_ordinary_codec():
    try:
        path = pathlib.Path(__file__).resolve().with_name(
            "checkout-ordinary-access-request.py",
        )
        spec = importlib.util.spec_from_file_location(
            "checkout_ordinary_access_request_transport", path,
        )
        if spec is None or spec.loader is None:
            raise ValueError("codec")
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        functions = types.MappingProxyType({
            name: getattr(module, name)
            for name in ("decode_request", "request_digest")
        })
        authority = types.MappingProxyType({
            name: _callable_state(value)
            for name, value in module.__dict__.items()
            if type(value) is types.FunctionType
            and value.__module__ == module.__name__
        })
        class_authority = types.MappingProxyType({
            name: types.MappingProxyType({
                "identity": value,
                "attributes": types.MappingProxyType({
                    key: (("function", _callable_state(attribute))
                          if type(attribute) is types.FunctionType
                          else ("identity", attribute))
                    for key, attribute in value.__dict__.items()
                    if key not in {"__dict__", "__weakref__"}
                }),
            })
            for name, value in module.__dict__.items()
            if type(value) is type and value.__module__ == module.__name__
        })
        globals_authority = types.MappingProxyType({
            name: (("value", type(value), value)
                   if type(value) in {int, str, bytes, type(None)}
                   else ("identity", value))
            for name, value in module.__dict__.items()
            if not name.startswith("__") and name not in authority
            and name not in class_authority
        })
        return (path, module, functions, authority, class_authority,
                globals_authority)
    except Exception:
        raise BrokerTransportRefused("broker_transport") from None


(_ORDINARY_PATH, _ORDINARY_CODEC, _ORDINARY_FUNCTIONS,
 _ORDINARY_FUNCTION_AUTHORITY, _ORDINARY_CLASS_AUTHORITY,
 _ORDINARY_GLOBALS_AUTHORITY) = _load_ordinary_codec()
_ORDINARY_TYPE = type(_ORDINARY_CODEC)
_ORDINARY_REQUEST_TYPE = _ORDINARY_CLASS_AUTHORITY["_RequestSnapshot"][
    "identity"
]
_ORDINARY_SNAPSHOT_FUNCTIONS = types.MappingProxyType({
    name: _ORDINARY_REQUEST_TYPE.__dict__[name]
    for name in ("bytes", "values")
})


def _required_dependencies():
    try:
        for module, name, expected in _DIRECT_DEPENDENCIES:
            value = getattr(module, name, None)
            if _callable_state(value) != expected:
                raise ValueError("dependency")
        if type(_ORDINARY_CODEC) is not _ORDINARY_TYPE \
                or pathlib.Path(getattr(_ORDINARY_CODEC, "__file__", "")).resolve() \
                != _ORDINARY_PATH:
            raise ValueError("ordinary")
        for name, function in _ORDINARY_FUNCTIONS.items():
            if getattr(_ORDINARY_CODEC, name, None) is not function:
                raise ValueError("ordinary")
        current = {
            name for name, value in _ORDINARY_CODEC.__dict__.items()
            if type(value) is types.FunctionType
            and value.__module__ == _ORDINARY_CODEC.__name__
        }
        if current != set(_ORDINARY_FUNCTION_AUTHORITY):
            raise ValueError("ordinary")
        current_classes = {
            name for name, value in _ORDINARY_CODEC.__dict__.items()
            if type(value) is type and value.__module__ == _ORDINARY_CODEC.__name__
        }
        if current_classes != set(_ORDINARY_CLASS_AUTHORITY):
            raise ValueError("ordinary")
        for name, state in _ORDINARY_CLASS_AUTHORITY.items():
            value = getattr(_ORDINARY_CODEC, name, None)
            if value is not state["identity"]:
                raise ValueError("ordinary")
            attributes = {
                key for key in value.__dict__
                if key not in {"__dict__", "__weakref__"}
            }
            if attributes != set(state["attributes"]):
                raise ValueError("ordinary")
            for key, attribute_state in state["attributes"].items():
                attribute = value.__dict__[key]
                if attribute_state[0] == "function":
                    if _callable_state(attribute) != attribute_state[1]:
                        raise ValueError("ordinary")
                elif attribute is not attribute_state[1]:
                    raise ValueError("ordinary")
        ordinary_globals = {
            name for name in _ORDINARY_CODEC.__dict__
            if not name.startswith("__") and name not in current
            and name not in current_classes
        }
        if ordinary_globals != set(_ORDINARY_GLOBALS_AUTHORITY):
            raise ValueError("ordinary")
        for name, expected in _ORDINARY_FUNCTION_AUTHORITY.items():
            value = getattr(_ORDINARY_CODEC, name, None)
            if _callable_state(value) != expected \
                    or value.__globals__ is not _ORDINARY_CODEC.__dict__:
                raise ValueError("ordinary")
        for name, expected in _ORDINARY_GLOBALS_AUTHORITY.items():
            value = getattr(_ORDINARY_CODEC, name, None)
            if expected[0] == "value" and (
                    type(value) is not expected[1] or value != expected[2]):
                raise ValueError("ordinary")
            if expected[0] == "identity" and value is not expected[1]:
                raise ValueError("ordinary")
    except BrokerTransportRefused:
        raise
    except Exception:
        raise BrokerTransportRefused("broker_transport") from None


def _strict_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("duplicate")
        result[key] = value
    return result


def _json_bytes(value):
    _required_dependencies()
    try:
        raw = (json.dumps(
            value, sort_keys=True, separators=(",", ":"), ensure_ascii=True,
            allow_nan=False,
        ) + "\n").encode("ascii")
    except Exception:
        raise ValueError("json") from None
    _required_dependencies()
    return raw


def _decode_json(raw, maximum):
    if type(raw) is not bytes or not 1 <= len(raw) <= maximum:
        raise ValueError("size")
    _required_dependencies()
    try:
        value = json.loads(
            raw.decode("ascii"), object_pairs_hook=_strict_object,
            parse_constant=lambda _value: (_ for _ in ()).throw(
                ValueError("constant"),
            ),
        )
    except Exception:
        raise ValueError("encoding") from None
    _required_dependencies()
    if raw != _json_bytes(value):
        raise ValueError("canonical")
    return value


def _digest(value):
    return type(value) is str and len(value) == 64 \
        and all(character in "0123456789abcdef" for character in value)


def _sha256(raw):
    if type(raw) is not bytes:
        raise ValueError("digest")
    _required_dependencies()
    value = hashlib.sha256(raw).hexdigest()
    _required_dependencies()
    return value


def _freeze(value):
    if type(value) is dict:
        return types.MappingProxyType({
            key: _freeze(item) for key, item in value.items()
        })
    if type(value) is list:
        return tuple(_freeze(item) for item in value)
    return value


def _copy(value):
    _required_dependencies()
    result = copy.deepcopy(value)
    _required_dependencies()
    return result


def _ordinary_request(raw):
    _required_dependencies()
    snapshot = _ORDINARY_FUNCTIONS["decode_request"](raw)
    _required_dependencies()
    if type(snapshot) is not _ORDINARY_REQUEST_TYPE \
            or _ORDINARY_SNAPSHOT_FUNCTIONS["bytes"](snapshot) != raw:
        raise ValueError("request")
    _required_dependencies()
    values = _thaw(_ORDINARY_SNAPSHOT_FUNCTIONS["values"](snapshot))
    _required_dependencies()
    digest = _ORDINARY_FUNCTIONS["request_digest"](raw)
    _required_dependencies()
    if not _digest(digest) or digest != _sha256(raw):
        raise ValueError("request")
    return values, digest


class _RequestSnapshot:
    __slots__ = ("__raw", "__document", "__payload_raw", "__marker")

    def __init__(self, raw, document, payload_raw):
        object.__setattr__(self, "_RequestSnapshot__raw", raw)
        object.__setattr__(self, "_RequestSnapshot__document", _freeze(document))
        object.__setattr__(self, "_RequestSnapshot__payload_raw", payload_raw)
        object.__setattr__(self, "_RequestSnapshot__marker", _SNAPSHOT_MARKER)

    def __setattr__(self, _name, _value):
        raise BrokerTransportRefused("broker_transport")

    def bytes(self):
        return self.__raw

    def values(self):
        return self.__document

    def payload_bytes(self):
        return self.__payload_raw


class _ResponseSnapshot:
    __slots__ = ("__raw", "__document", "__evidence_raw", "__marker")

    def __init__(self, raw, document, evidence_raw):
        object.__setattr__(self, "_ResponseSnapshot__raw", raw)
        object.__setattr__(self, "_ResponseSnapshot__document", _freeze(document))
        object.__setattr__(self, "_ResponseSnapshot__evidence_raw", evidence_raw)
        object.__setattr__(self, "_ResponseSnapshot__marker", _SNAPSHOT_MARKER)

    def __setattr__(self, _name, _value):
        raise BrokerTransportRefused("broker_transport")

    def bytes(self):
        return self.__raw

    def values(self):
        return self.__document

    def evidence_bytes(self):
        return self.__evidence_raw

    def is_load_sentinel(self):
        return self.__document["method"] == "load" \
            and self.__document["status"] == "ok" \
            and self.__document["payload"] is None


def _request_document(method, manifest_digest, broker_start_identity, payload):
    if type(method) is not str or method not in _METHODS \
            or not _digest(manifest_digest) or not _digest(broker_start_identity):
        raise ValueError("binding")
    payload_raw = None
    if method == "attest":
        if type(payload) is not bytes:
            raise ValueError("payload")
        payload_document, request_digest = _ordinary_request(payload)
        payload_raw = payload
    else:
        if not _digest(payload):
            raise ValueError("payload")
        payload_document = payload
        request_digest = payload
    document = {
        "transportVersion": _VERSION,
        "method": method,
        "manifestDigest": manifest_digest,
        "brokerStartIdentity": broker_start_identity,
        "requestDigest": request_digest,
        "payload": payload_document,
    }
    return document, payload_raw


def _validate_request_document(document, manifest_digest, broker_start_identity):
    keys = {
        "transportVersion", "method", "manifestDigest",
        "brokerStartIdentity", "requestDigest", "payload",
    }
    if type(document) is not dict or set(document) != keys \
            or type(document["transportVersion"]) is not int \
            or document["transportVersion"] != _VERSION \
            or type(document["method"]) is not str \
            or document["method"] not in _METHODS \
            or document["manifestDigest"] != manifest_digest \
            or document["brokerStartIdentity"] != broker_start_identity \
            or not _digest(document["requestDigest"]):
        raise ValueError("request")
    method = document["method"]
    if method == "attest":
        if type(document["payload"]) is not dict:
            raise ValueError("payload")
        payload_raw = _json_bytes(document["payload"])
        _payload, digest = _ordinary_request(payload_raw)
        if digest != document["requestDigest"]:
            raise ValueError("digest")
        return payload_raw
    if not _digest(document["payload"]) \
            or document["payload"] != document["requestDigest"]:
        raise ValueError("payload")
    return None


def _request_snapshot(raw, manifest_digest, broker_start_identity):
    document = _decode_json(raw, MAX_REQUEST_BYTES)
    payload_raw = _validate_request_document(
        document, manifest_digest, broker_start_identity,
    )
    return _RequestSnapshot(raw, document, payload_raw)


def _validated_request_snapshot(snapshot):
    if type(snapshot) is not _RequestSnapshot \
            or snapshot._RequestSnapshot__marker is not _SNAPSHOT_MARKER:
        raise ValueError("snapshot")
    raw = snapshot._RequestSnapshot__raw
    document = snapshot._RequestSnapshot__document
    if type(raw) is not bytes or type(document) is not types.MappingProxyType:
        raise ValueError("snapshot")
    mutable = _copy(_thaw(document))
    canonical = _json_bytes(mutable)
    checked = _request_snapshot(
        canonical, mutable["manifestDigest"], mutable["brokerStartIdentity"],
    )
    if raw != canonical or checked.payload_bytes() \
            != snapshot._RequestSnapshot__payload_raw:
        raise ValueError("snapshot")
    return mutable


def _thaw(value):
    if type(value) is types.MappingProxyType:
        return {key: _thaw(item) for key, item in value.items()}
    if type(value) is tuple:
        return [_thaw(item) for item in value]
    return value


def _canonical_request(method, manifest_digest, broker_start_identity, payload):
    document, _payload_raw = _request_document(
        method, manifest_digest, broker_start_identity, payload,
    )
    raw = _json_bytes(document)
    if len(raw) > MAX_REQUEST_BYTES:
        raise ValueError("size")
    _request_snapshot(raw, manifest_digest, broker_start_identity)
    return raw


def _decode_request(raw, manifest_digest, broker_start_identity):
    if not _digest(manifest_digest) or not _digest(broker_start_identity):
        raise ValueError("binding")
    return _request_snapshot(raw, manifest_digest, broker_start_identity)


def _response_document(request, status, payload):
    request_document = _validated_request_snapshot(request)
    if type(status) is not str or status not in _STATUSES:
        raise ValueError("status")
    method = request_document["method"]
    evidence_raw = None
    if status == "refused":
        if payload is not None:
            raise ValueError("partial")
        payload_document = None
    elif method == "discard":
        if payload is not None:
            raise ValueError("discard")
        payload_document = None
    elif method == "load" and payload is None:
        payload_document = None
    else:
        raise ValueError("evidence_unavailable")
    return {
        "transportVersion": _VERSION,
        "method": method,
        "manifestDigest": request_document["manifestDigest"],
        "brokerStartIdentity": request_document["brokerStartIdentity"],
        "requestDigest": request_document["requestDigest"],
        "status": status,
        "payload": payload_document,
    }, evidence_raw


def _validate_response_document(document, request):
    request_document = _validated_request_snapshot(request)
    keys = {
        "transportVersion", "method", "manifestDigest",
        "brokerStartIdentity", "requestDigest", "status", "payload",
    }
    if type(document) is not dict or set(document) != keys \
            or type(document["transportVersion"]) is not int \
            or document["transportVersion"] != _VERSION \
            or document["method"] != request_document["method"] \
            or document["manifestDigest"] != request_document["manifestDigest"] \
            or document["brokerStartIdentity"] \
            != request_document["brokerStartIdentity"] \
            or document["requestDigest"] != request_document["requestDigest"] \
            or type(document["status"]) is not str \
            or document["status"] not in _STATUSES:
        raise ValueError("response")
    method = request_document["method"]
    payload = document["payload"]
    if document["status"] == "refused" or method == "discard":
        if payload is not None:
            raise ValueError("partial")
        return None
    if method == "load" and payload is None:
        return None
    raise ValueError("evidence_unavailable")


def _canonical_response(request, status, payload):
    document, _evidence_raw = _response_document(request, status, payload)
    raw = _json_bytes(document)
    if len(raw) > MAX_RESPONSE_BYTES:
        raise ValueError("size")
    _validate_response_document(document, request)
    return raw


def _decode_response(raw, request):
    document = _decode_json(raw, MAX_RESPONSE_BYTES)
    evidence_raw = _validate_response_document(document, request)
    return _ResponseSnapshot(raw, document, evidence_raw)


def _make_public_codec():
    namespace = globals()
    state_of = _callable_state
    function_type = types.FunctionType
    mapping_type = types.MappingProxyType
    external_check = _required_dependencies
    refusal_type = BrokerTransportRefused
    request_encoder = _canonical_request
    request_decoder = _decode_request
    response_encoder = _canonical_response
    response_decoder = _decode_response
    public_names = frozenset({
        "canonical_request", "decode_request",
        "canonical_response", "decode_response",
    })
    inventory = frozenset(
        name for name in namespace
        if not name.startswith("__")
        and name != "_make_public_codec" and name not in public_names
    )
    functions = {}
    classes = {}
    objects = {}
    for name in inventory:
        value = namespace[name]
        if type(value) is function_type and value.__module__ == __name__:
            functions[name] = state_of(value)
        elif type(value) is type and value.__module__ == __name__:
            attributes = {}
            for key, attribute in value.__dict__.items():
                if key in {"__dict__", "__weakref__"}:
                    continue
                if type(attribute) is function_type:
                    attributes[key] = ("function", state_of(attribute))
                else:
                    attributes[key] = ("identity", attribute)
            classes[name] = mapping_type({
                "identity": value,
                "attributes": mapping_type(attributes),
            })
        elif type(value) in {int, str, bytes, type(None)}:
            objects[name] = ("value", type(value), value)
        else:
            objects[name] = ("identity", value)
    functions = mapping_type(functions)
    classes = mapping_type(classes)
    objects = mapping_type(objects)

    def check():
        try:
            current_inventory = frozenset(
                name for name in namespace
                if not name.startswith("__")
                and name != "_make_public_codec" and name not in public_names
            )
            if current_inventory != inventory:
                raise ValueError("inventory")
            for name, state in functions.items():
                value = namespace.get(name)
                if state_of(value) != state \
                        or value.__globals__ is not namespace:
                    raise ValueError("function")
            for name, state in classes.items():
                value = namespace.get(name)
                if value is not state["identity"]:
                    raise ValueError("class")
                attributes = {
                    key for key in value.__dict__
                    if key not in {"__dict__", "__weakref__"}
                }
                if attributes != set(state["attributes"]):
                    raise ValueError("class")
                for key, attribute_state in state["attributes"].items():
                    attribute = value.__dict__[key]
                    if attribute_state[0] == "function":
                        if state_of(attribute) != attribute_state[1]:
                            raise ValueError("method")
                    elif attribute is not attribute_state[1]:
                        raise ValueError("attribute")
            for name, state in objects.items():
                value = namespace.get(name)
                if state[0] == "value" and (
                        type(value) is not state[1] or value != state[2]):
                    raise ValueError("value")
                if state[0] == "identity" and value is not state[1]:
                    raise ValueError("identity")
        except refusal_type:
            raise
        except Exception:
            raise refusal_type("broker_transport") from None

    def guard(callback):
        check()
        external_check()
        primary = None
        postcheck = None
        result = None
        try:
            result = callback()
        except refusal_type as error:
            primary = error
        except Exception:
            primary = refusal_type("broker_transport")
        except BaseException as error:
            primary = error
        try:
            external_check()
            check()
        except BaseException as error:
            postcheck = error
        if primary is not None:
            raise primary from None
        if postcheck is not None:
            raise postcheck from None
        return result

    def canonical_request(method, manifest_digest, broker_start_identity,
                          payload):
        return guard(lambda: request_encoder(
            method, manifest_digest, broker_start_identity, payload,
        ))

    def decode_request(raw, manifest_digest, broker_start_identity):
        return guard(lambda: request_decoder(
            raw, manifest_digest, broker_start_identity,
        ))

    def canonical_response(request, status, payload=None):
        return guard(lambda: response_encoder(request, status, payload))

    def decode_response(raw, request):
        return guard(lambda: response_decoder(raw, request))

    return (canonical_request, decode_request, canonical_response,
            decode_response)


(canonical_request, decode_request,
 canonical_response, decode_response) = _make_public_codec()
del _make_public_codec
