"""Pure ADR-019 codec for supplied per-start broker identity values.

Canonical data consistency is the only claim.  Process and token provenance
remain the responsibility of the future live Windows verifier.
"""

from __future__ import annotations

import hashlib
import json
import re
from typing import NamedTuple


MAX_START_IDENTITY_BYTES = 4 * 1024


class BrokerStartIdentityRefused(Exception):
    """Stable refusal without supplied process, token, or SID details."""


class _BrokerStartIdentity(NamedTuple):
    digest: str
    manifestDigest: str
    servicePid: int
    processCreationTime: str
    accountSid: str
    serviceSid: str
    authenticationId: tuple[int, int]
    tokenId: tuple[int, int]


def _make_codec():
    maximum = MAX_START_IDENTITY_BYTES
    refusal = BrokerStartIdentityRefused
    result_type = _BrokerStartIdentity
    prefix = b"checkout-ordinary-broker-start-v1\0"
    keys = frozenset({"manifestDigest", "servicePid", "processCreationTime",
                      "accountSid", "serviceSid", "authenticationId", "tokenId"})
    digest_pattern = re.compile(r"[a-f0-9]{64}")
    decimal_pattern = re.compile(r"0|[1-9][0-9]*")
    json_module = json
    hashlib_module = hashlib
    re_module = re
    json_dumps = json.dumps
    json_loads = json.loads
    sha256 = hashlib.sha256
    re_fullmatch = re.fullmatch

    def callable_state(function):
        code = getattr(function, "__code__", None)
        defaults = getattr(function, "__defaults__", None)
        kwdefaults = getattr(function, "__kwdefaults__", None)
        closure = getattr(function, "__closure__", None)
        function_globals = getattr(function, "__globals__", None)
        return (function, type(function), code, defaults, kwdefaults,
                None if kwdefaults is None else tuple(sorted(kwdefaults.items())),
                closure, function_globals)

    dependencies = (
        (json_module, "dumps", callable_state(json_dumps)),
        (json_module, "loads", callable_state(json_loads)),
        (hashlib_module, "sha256", callable_state(sha256)),
        (re_module, "fullmatch", callable_state(re_fullmatch)),
    )
    module_globals = globals()
    global_pins = (
        ("MAX_START_IDENTITY_BYTES", maximum),
        ("BrokerStartIdentityRefused", refusal),
        ("_BrokerStartIdentity", result_type),
        ("json", json_module), ("hashlib", hashlib_module), ("re", re_module),
    )

    def guard():
        try:
            for name, expected in global_pins:
                if module_globals.get(name) is not expected:
                    raise ValueError("authority")
            for module, name, expected in dependencies:
                current = getattr(module, name, None)
                kwdefaults = getattr(current, "__kwdefaults__", None)
                state = (current, type(current), getattr(current, "__code__", None),
                         getattr(current, "__defaults__", None), kwdefaults,
                         None if kwdefaults is None else tuple(sorted(kwdefaults.items())),
                         getattr(current, "__closure__", None),
                         getattr(current, "__globals__", None))
                if any(actual is not wanted for actual, wanted in zip(state[:5], expected[:5])) \
                        or state[5] != expected[5] or state[6] is not expected[6] \
                        or state[7] is not expected[7]:
                    raise ValueError("dependency")
        except refusal:
            raise
        except Exception:
            raise refusal("broker_start_identity") from None

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

    def decimal64(value):
        return type(value) is str and decimal_pattern.fullmatch(value) is not None \
            and 1 <= int(value) <= 0xFFFFFFFFFFFFFFFF

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

    def service_sid(value):
        if not sid(value):
            return False
        parts = value.split("-")
        return len(parts) == 9 and parts[1:4] == ["1", "5", "80"] \
            and all(decimal_pattern.fullmatch(part) is not None
                    and int(part) <= 0xFFFFFFFF for part in parts[4:])

    def luid(value):
        return type(value) is list and len(value) == 2 \
            and uint32(value[0]) and int32(value[1])

    def validate(value):
        if type(value) is not dict or set(value) != keys \
                or not digest(value["manifestDigest"]) \
                or not uint32(value["servicePid"]) or value["servicePid"] == 0 \
                or not decimal64(value["processCreationTime"]) \
                or not sid(value["accountSid"]) or not service_sid(value["serviceSid"]) \
                or value["accountSid"] == "S-1-5-18" \
                or value["accountSid"] == value["serviceSid"] \
                or not luid(value["authenticationId"]) or not luid(value["tokenId"]):
            raise ValueError("identity")

    def canonical_impl(value):
        validate(value)
        raw = json_bytes(value)
        if len(raw) > maximum:
            raise ValueError("size")
        return raw

    def decode_impl(raw):
        if type(raw) is not bytes or not 1 <= len(raw) <= maximum \
                or not raw.endswith(b"\n") or raw.endswith(b"\n\n"):
            raise ValueError("bytes")
        text = raw.decode("ascii")
        value = json_loads(
            text, object_pairs_hook=strict_object,
            parse_constant=lambda _value: (_ for _ in ()).throw(ValueError("constant")),
        )
        validate(value)
        if json_bytes(value) != raw:
            raise ValueError("canonical")
        start_digest = sha256(prefix + raw).hexdigest()
        return result_type(
            digest=start_digest, manifestDigest=value["manifestDigest"],
            servicePid=value["servicePid"],
            processCreationTime=value["processCreationTime"],
            accountSid=value["accountSid"], serviceSid=value["serviceSid"],
            authenticationId=tuple(value["authenticationId"]),
            tokenId=tuple(value["tokenId"]),
        )

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
            raise refusal("broker_start_identity") from None

    def canonical(value):
        return invoke(canonical_impl, value)

    def decode(raw):
        return invoke(decode_impl, raw)

    return canonical, decode


canonical, decode = _make_codec()
del _make_codec
