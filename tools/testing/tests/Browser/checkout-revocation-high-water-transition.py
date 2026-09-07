"""Pure structural planner for an ADR-021 revocation high-water transition.

The planner consumes no signature and writes no state.  Its immutable result is
only a proposed canonical next document derived from supplied data; it does not
establish freshness, rollback protection, quorum, replay consumption, or
admission.  Composition must pin the exported callable.
"""

from __future__ import annotations

import datetime
import hashlib
import json
import re
from types import MappingProxyType


MAX_STATE_BYTES = 16 * 1024
AUTHORITY_ROLES = (
    "asset-review", "composition-admission", "preparation-acl",
    "preparation-authorization", "release-source", "revocation-snapshot",
    "runtime-configuration-policy", "tls-material", "tool-runtime-closure",
    "trust-root-bundle", "vendor-build",
)


class RevocationHighWaterTransitionRefused(Exception):
    """Fixed refusal without namespace, state, or parser details."""


__all__ = ("RevocationHighWaterTransitionRefused", "plan")


def _make_planner():
    refusal = RevocationHighWaterTransitionRefused
    mapping_proxy_type = MappingProxyType
    maximum_state = MAX_STATE_BYTES
    authority_roles = AUTHORITY_ROLES
    public_surface = __all__
    identifier_pattern = r"[a-z0-9](?:[a-z0-9._-]{0,63})"
    timestamp_pattern = (
        r"[0-9]{4}-[0-9]{2}-[0-9]{2}T"
        r"[0-9]{2}:[0-9]{2}:[0-9]{2}Z"
    )
    namespace_keys = frozenset({
        "authorityRole", "issuerId", "issuerKeyGeneration",
        "revocationTrustGeneration",
    })
    candidate_keys = namespace_keys | frozenset({
        "generation", "issuedAt", "nextUpdate", "notBefore", "snapshotDigest",
    })
    state_keys = frozenset({
        "generation", "issuedAt", "namespace", "nextUpdate", "notBefore",
        "previousStateDigest", "rebootUncertain", "snapshotDigest", "version",
    })

    json_module = json
    hashlib_module = hashlib
    re_module = re
    datetime_module = datetime
    json_dumps = json.dumps
    json_loads = json.loads
    sha256 = hashlib.sha256
    re_fullmatch = re.fullmatch
    datetime_class = datetime.datetime
    datetime_strptime_descriptor = datetime_class.__dict__["strptime"]
    datetime_strptime = datetime_class.strptime
    module_globals = globals()

    def freeze_metadata(value):
        if value is None:
            return ("none",)
        if type(value) is bool:
            return ("bool", value)
        if type(value) is int:
            return ("int", value)
        if type(value) is str:
            return ("str", value)
        if type(value) is tuple:
            return ("tuple", tuple(freeze_metadata(item) for item in value))
        raise ValueError("metadata")

    def mapping_snapshot(value):
        if value is None:
            return None
        if type(value) is not dict or any(type(key) is not str for key in value):
            raise ValueError("metadata")
        return tuple((key, freeze_metadata(item)) for key, item in value.items())

    def mapping_matches(value, expected):
        if expected is None:
            return value is None
        if type(value) is not dict or len(value) != len(expected):
            return False
        for key, item in expected:
            if key not in value:
                return False
            try:
                if freeze_metadata(value[key]) != item:
                    return False
            except Exception:
                return False
        return True

    def callable_state(function):
        defaults = getattr(function, "__defaults__", None)
        kwdefaults = getattr(function, "__kwdefaults__", None)
        return (
            function, type(function), getattr(function, "__code__", None),
            defaults, freeze_metadata(defaults), kwdefaults,
            mapping_snapshot(kwdefaults), getattr(function, "__closure__", None),
            getattr(function, "__globals__", None),
        )

    dependencies = (
        (json_module, "dumps", callable_state(json_dumps)),
        (json_module, "loads", callable_state(json_loads)),
        (hashlib_module, "sha256", callable_state(sha256)),
        (re_module, "fullmatch", callable_state(re_fullmatch)),
    )
    global_pins = (
        ("MAX_STATE_BYTES", maximum_state),
        ("AUTHORITY_ROLES", authority_roles),
        ("RevocationHighWaterTransitionRefused", refusal),
        ("MappingProxyType", mapping_proxy_type), ("__all__", public_surface),
        ("json", json_module), ("hashlib", hashlib_module),
        ("re", re_module), ("datetime", datetime_module),
    )

    def guard():
        try:
            for name, expected in global_pins:
                if module_globals.get(name) is not expected:
                    raise ValueError("authority")
            if datetime_module.datetime is not datetime_class \
                    or datetime_class.__dict__.get("strptime") \
                    is not datetime_strptime_descriptor:
                raise ValueError("dependency")
            for module, name, expected in dependencies:
                current = getattr(module, name, None)
                if current is not expected[0] or type(current) is not expected[1]:
                    raise ValueError("dependency")
                defaults = getattr(current, "__defaults__", None)
                kwdefaults = getattr(current, "__kwdefaults__", None)
                if getattr(current, "__code__", None) is not expected[2] \
                        or defaults is not expected[3] \
                        or freeze_metadata(defaults) != expected[4] \
                        or kwdefaults is not expected[5] \
                        or not mapping_matches(kwdefaults, expected[6]) \
                        or getattr(current, "__closure__", None) is not expected[7] \
                        or getattr(current, "__globals__", None) is not expected[8]:
                    raise ValueError("dependency")
        except refusal:
            raise
        except Exception:
            raise refusal("revocation_high_water_transition") from None

    def exact_dict(value, keys):
        return type(value) is dict and all(type(key) is str for key in value) \
            and set(value) == keys

    def strict_object(pairs):
        guard()
        result = {}
        for key, value in pairs:
            if type(key) is not str or key in result:
                raise ValueError("duplicate")
            result[key] = value
        guard()
        return result

    def json_bytes(value):
        return (json_dumps(value, sort_keys=True, separators=(",", ":"),
                           ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")

    def positive_int63(value):
        return type(value) is int and 1 <= value < (1 << 63)

    def identifier(value):
        return type(value) is str \
            and re_fullmatch(identifier_pattern, value) is not None

    def digest(value):
        return type(value) is str and len(value) == 64 \
            and re_fullmatch(r"[a-f0-9]{64}", value) is not None

    def parse_timestamp(value):
        if type(value) is not str \
                or re_fullmatch(timestamp_pattern, value) is None:
            raise ValueError("timestamp")
        parsed = datetime_strptime(value, "%Y-%m-%dT%H:%M:%SZ")
        if parsed.strftime("%Y-%m-%dT%H:%M:%SZ") != value:
            raise ValueError("timestamp")
        return parsed

    def freeze_namespace(value):
        if not exact_dict(value, namespace_keys) \
                or type(value["authorityRole"]) is not str \
                or value["authorityRole"] not in authority_roles \
                or not identifier(value["issuerId"]) \
                or not positive_int63(value["issuerKeyGeneration"]) \
                or not positive_int63(value["revocationTrustGeneration"]):
            raise ValueError("namespace")
        return (
            value["authorityRole"], value["issuerId"],
            value["issuerKeyGeneration"], value["revocationTrustGeneration"],
        )

    def freeze_candidate(value):
        if not exact_dict(value, candidate_keys) \
                or not positive_int63(value["generation"]) \
                or not digest(value["snapshotDigest"]):
            raise ValueError("candidate")
        namespace = freeze_namespace({key: value[key] for key in namespace_keys})
        issued = parse_timestamp(value["issuedAt"])
        next_update = parse_timestamp(value["nextUpdate"])
        not_before = parse_timestamp(value["notBefore"])
        window = (next_update - issued).total_seconds()
        if not not_before <= issued < next_update \
                or type(window) is not float or window > 24 * 60 * 60:
            raise ValueError("candidate")
        return namespace

    def decode_current(raw):
        if type(raw) is not bytes or not 1 <= len(raw) <= maximum_state \
                or not raw.endswith(b"\n") or raw.endswith(b"\n\n"):
            raise ValueError("state")
        value = json_loads(raw.decode("ascii"), object_pairs_hook=strict_object,
                           parse_constant=lambda _value: (_ for _ in ()).throw(
                               ValueError("constant")
                           ))
        if not exact_dict(value, state_keys) \
                or type(value["version"]) is not int or value["version"] != 1 \
                or not positive_int63(value["generation"]) \
                or not digest(value["snapshotDigest"]) \
                or type(value["rebootUncertain"]) is not bool \
                or value["rebootUncertain"] is not False:
            raise ValueError("state")
        namespace = freeze_namespace(value["namespace"])
        issued = parse_timestamp(value["issuedAt"])
        next_update = parse_timestamp(value["nextUpdate"])
        not_before = parse_timestamp(value["notBefore"])
        window = (next_update - issued).total_seconds()
        if not not_before <= issued < next_update \
                or type(window) is not float or window > 24 * 60 * 60:
            raise ValueError("state")
        previous = value["previousStateDigest"]
        if (value["generation"] == 1 and previous is not None) \
                or (value["generation"] > 1 and not digest(previous)) \
                or json_bytes(value) != raw:
            raise ValueError("state")
        return value, namespace

    def plan_impl(current_raw, candidate, expected_namespace, reboot_uncertain):
        if type(reboot_uncertain) is not bool or reboot_uncertain:
            raise ValueError("reboot")
        expected = freeze_namespace(expected_namespace)
        candidate_namespace = freeze_candidate(candidate)
        if candidate_namespace != expected:
            raise ValueError("namespace")
        if current_raw is None:
            if candidate["generation"] != 1:
                raise ValueError("bootstrap")
            previous_digest = None
        else:
            current, current_namespace = decode_current(current_raw)
            if current_namespace != expected \
                    or candidate["generation"] <= current["generation"]:
                raise ValueError("generation")
            previous_digest = sha256(current_raw).hexdigest()
        namespace_value = {
            "authorityRole": expected[0], "issuerId": expected[1],
            "issuerKeyGeneration": expected[2],
            "revocationTrustGeneration": expected[3],
        }
        next_state = {
            "generation": candidate["generation"],
            "issuedAt": candidate["issuedAt"],
            "namespace": namespace_value,
            "nextUpdate": candidate["nextUpdate"],
            "notBefore": candidate["notBefore"],
            "previousStateDigest": previous_digest,
            "rebootUncertain": False,
            "snapshotDigest": candidate["snapshotDigest"],
            "version": 1,
        }
        raw = json_bytes(next_state)
        if len(raw) > maximum_state:
            raise ValueError("size")
        return mapping_proxy_type({
            "structuralOnly": True,
            "namespace": expected,
            "generation": candidate["generation"],
            "snapshotDigest": candidate["snapshotDigest"],
            "previousStateDigest": previous_digest,
            "stateBytes": raw,
            "stateDigest": sha256(raw).hexdigest(),
        })

    def invoke(operation, *args):
        try:
            guard()
            result = operation(*args)
            guard()
            return result
        except BaseException as primary:
            try:
                guard()
            except BaseException:
                pass
            if isinstance(primary, (KeyboardInterrupt, SystemExit)):
                raise
            raise refusal("revocation_high_water_transition") from None

    def plan(current_state_raw, candidate, *, expected_namespace, reboot_uncertain):
        return invoke(plan_impl, current_state_raw, candidate,
                      expected_namespace, reboot_uncertain)

    return plan


plan = _make_planner()
del _make_planner
