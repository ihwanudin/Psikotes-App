"""Pure structural planner for ADR-021 one-shot ledger transitions.

The planner consumes only supplied canonical bytes and returns a proposed next
state.  It does not read a clock, consume an artifact, accept replay, lock or
persist protected state, provide atomicity, verify signature/trust/freshness,
grant admission, or exercise runtime behavior.  A composition boundary must
pin these exported callables and perform protected storage and authority work.
"""

from __future__ import annotations

import hashlib
import json
import re
from types import MappingProxyType


NAMESPACES = ("composition-admission", "preparation-authorization")
MAX_INTENT_BYTES = 8 * 1024
MAX_LEDGER_BYTES = 1024 * 1024
MAX_RECORDS = 4096
ID_PATTERN = r"[a-z0-9](?:[a-z0-9._-]{0,127})"
ZERO_DIGEST = "0" * 64


class OneShotLedgerTransitionRefused(Exception):
    """Fixed refusal without identifiers, timestamps, or ledger content."""


__all__ = (
    "OneShotLedgerTransitionRefused",
    "canonical_intent",
    "canonical_state",
    "plan_transition",
)


def _make_planner():
    refusal = OneShotLedgerTransitionRefused
    mapping_proxy_type = MappingProxyType
    namespaces = NAMESPACES
    maximum_intent = MAX_INTENT_BYTES
    maximum_ledger = MAX_LEDGER_BYTES
    maximum_records = MAX_RECORDS
    identifier_pattern = ID_PATTERN
    zero_digest = ZERO_DIGEST
    public_surface = __all__
    intent_keys = frozenset({
        "artifactDigest", "artifactGeneration", "artifactId", "consumedAt",
        "namespace", "phase", "priorLedgerDigest", "priorLedgerGeneration",
        "replayId", "transitionGeneration", "version",
    })
    state_keys = frozenset({
        "ledgerGeneration", "previousLedgerDigest", "records", "version",
    })
    record_keys = frozenset({
        "artifactDigest", "artifactGeneration", "artifactId", "consumedAt",
        "namespace", "phase", "replayId", "transitionGeneration",
    })

    json_module = json
    hashlib_module = hashlib
    re_module = re
    json_dumps = json.dumps
    json_loads = json.loads
    sha256 = hashlib.sha256
    re_fullmatch = re.fullmatch
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
        ("NAMESPACES", namespaces),
        ("MAX_INTENT_BYTES", maximum_intent),
        ("MAX_LEDGER_BYTES", maximum_ledger),
        ("MAX_RECORDS", maximum_records),
        ("ID_PATTERN", identifier_pattern),
        ("ZERO_DIGEST", zero_digest),
        ("OneShotLedgerTransitionRefused", refusal),
        ("MappingProxyType", mapping_proxy_type),
        ("__all__", public_surface),
        ("json", json_module),
        ("hashlib", hashlib_module),
        ("re", re_module),
    )

    def guard():
        try:
            for name, expected in global_pins:
                if module_globals.get(name) is not expected:
                    raise ValueError("authority")
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
            raise refusal("one_shot_ledger_transition") from None

    def exact_dict(value, keys):
        return type(value) is dict \
            and all(type(key) is str for key in value) \
            and set(value) == keys

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
        return (
            json_dumps(
                value, sort_keys=True, separators=(",", ":"),
                ensure_ascii=True, allow_nan=False,
            ) + "\n"
        ).encode("ascii")

    def positive_int63(value):
        return type(value) is int and 1 <= value < (1 << 63)

    def nonnegative_int63(value):
        return type(value) is int and 0 <= value < (1 << 63)

    def identifier(value):
        return type(value) is str \
            and re_fullmatch(identifier_pattern, value) is not None

    def digest(value):
        return type(value) is str \
            and re_fullmatch(r"[a-f0-9]{64}", value) is not None

    def timestamp(value):
        if type(value) is not str:
            return False
        match = re_fullmatch(
            r"([0-9]{4})-([0-9]{2})-([0-9]{2})T"
            r"([0-9]{2}):([0-9]{2}):([0-9]{2})Z", value,
        )
        if match is None:
            return False
        year, month, day, hour, minute, second = (
            int(part) for part in match.groups()
        )
        if not 1 <= year <= 9999 or not 1 <= month <= 12 \
                or hour > 23 or minute > 59 or second > 59:
            return False
        leap = year % 4 == 0 and (year % 100 != 0 or year % 400 == 0)
        days = (31, 29 if leap else 28, 31, 30, 31, 30,
                31, 31, 30, 31, 30, 31)
        return 1 <= day <= days[month - 1]

    def phase_for(namespace):
        return "composition" if namespace == "composition-admission" \
            else "preparation"

    def validate_record(value):
        if not exact_dict(value, record_keys) \
                or type(value["namespace"]) is not str \
                or value["namespace"] not in namespaces \
                or type(value["phase"]) is not str \
                or value["phase"] != phase_for(value["namespace"]) \
                or not identifier(value["artifactId"]) \
                or not identifier(value["replayId"]) \
                or not digest(value["artifactDigest"]) \
                or not positive_int63(value["artifactGeneration"]) \
                or not positive_int63(value["transitionGeneration"]) \
                or not timestamp(value["consumedAt"]):
            raise ValueError("record")

    def record_tuple(value):
        return (
            value["namespace"], value["artifactId"], value["artifactDigest"],
            value["replayId"], value["artifactGeneration"], value["phase"],
            value["consumedAt"], value["transitionGeneration"],
        )

    def sort_key(value):
        return (
            value["namespace"], value["artifactId"], value["replayId"],
            value["artifactDigest"],
        )

    def validate_intent(value):
        if not exact_dict(value, intent_keys) \
                or type(value["version"]) is not int or value["version"] != 1 \
                or type(value["namespace"]) is not str \
                or value["namespace"] not in namespaces \
                or type(value["phase"]) is not str \
                or value["phase"] != phase_for(value["namespace"]) \
                or not identifier(value["artifactId"]) \
                or not identifier(value["replayId"]) \
                or not digest(value["artifactDigest"]) \
                or not positive_int63(value["artifactGeneration"]) \
                or not digest(value["priorLedgerDigest"]) \
                or not nonnegative_int63(value["priorLedgerGeneration"]) \
                or not positive_int63(value["transitionGeneration"]) \
                or not timestamp(value["consumedAt"]):
            raise ValueError("intent")

    def validate_state(value):
        if not exact_dict(value, state_keys) \
                or type(value["version"]) is not int or value["version"] != 1 \
                or not positive_int63(value["ledgerGeneration"]) \
                or not digest(value["previousLedgerDigest"]) \
                or type(value["records"]) is not list \
                or not 1 <= len(value["records"]) <= maximum_records \
                or value["ledgerGeneration"] != len(value["records"]):
            raise ValueError("state")
        if value["ledgerGeneration"] == 1 \
                and value["previousLedgerDigest"] != zero_digest:
            raise ValueError("state")
        if value["ledgerGeneration"] > 1 \
                and value["previousLedgerDigest"] == zero_digest:
            raise ValueError("state")
        records = value["records"]
        for record in records:
            validate_record(record)
        if records != sorted(records, key=sort_key):
            raise ValueError("record_order")
        if {record["transitionGeneration"] for record in records} \
                != set(range(1, value["ledgerGeneration"] + 1)):
            raise ValueError("transition_set")
        for key in ("artifactId", "artifactDigest", "replayId"):
            values = [record[key] for record in records]
            if len(values) != len(set(values)):
                raise ValueError("reuse")
        by_namespace = {namespace: [] for namespace in namespaces}
        for record in sorted(records, key=lambda item: item["transitionGeneration"]):
            generations = by_namespace[record["namespace"]]
            if generations and record["artifactGeneration"] <= generations[-1]:
                raise ValueError("rollback")
            generations.append(record["artifactGeneration"])
        return tuple(record_tuple(record) for record in records)

    def parse_raw(raw, maximum):
        if type(raw) is not bytes or not 1 <= len(raw) <= maximum \
                or not raw.endswith(b"\n") or raw.endswith(b"\n\n"):
            raise ValueError("raw")
        value = json_loads(
            raw.decode("ascii"), object_pairs_hook=strict_object,
            parse_constant=lambda _value: (_ for _ in ()).throw(
                ValueError("constant")
            ),
        )
        if json_bytes(value) != raw:
            raise ValueError("canonical")
        return value

    def canonical_intent_impl(value):
        validate_intent(value)
        raw = json_bytes(value)
        if len(raw) > maximum_intent:
            raise ValueError("size")
        return raw

    def canonical_state_impl(value):
        validate_state(value)
        raw = json_bytes(value)
        if len(raw) > maximum_ledger:
            raise ValueError("size")
        return raw

    def plan_impl(prior_raw, intent_raw):
        intent_value = parse_raw(intent_raw, maximum_intent)
        validate_intent(intent_value)
        if prior_raw is None:
            prior_records = []
            prior_digest = zero_digest
            prior_generation = 0
        else:
            prior_value = parse_raw(prior_raw, maximum_ledger)
            prior_records_tuple = validate_state(prior_value)
            prior_records = [dict(record) for record in prior_value["records"]]
            if tuple(record_tuple(record) for record in prior_records) \
                    != prior_records_tuple:
                raise ValueError("state")
            prior_digest = sha256(prior_raw).hexdigest()
            prior_generation = prior_value["ledgerGeneration"]
        if intent_value["priorLedgerDigest"] != prior_digest \
                or intent_value["priorLedgerGeneration"] != prior_generation \
                or intent_value["transitionGeneration"] != prior_generation + 1:
            raise ValueError("prior")
        if prior_raw is None and prior_digest != zero_digest:
            raise ValueError("bootstrap")

        for key in ("artifactId", "artifactDigest", "replayId"):
            if any(record[key] == intent_value[key] for record in prior_records):
                raise ValueError("reuse")
        namespace_generations = [
            record["artifactGeneration"] for record in prior_records
            if record["namespace"] == intent_value["namespace"]
        ]
        if namespace_generations \
                and intent_value["artifactGeneration"] <= max(namespace_generations):
            raise ValueError("rollback")

        new_record = {
            key: intent_value[key]
            for key in (
                "artifactDigest", "artifactGeneration", "artifactId", "consumedAt",
                "namespace", "phase", "replayId", "transitionGeneration",
            )
        }
        records = sorted(prior_records + [new_record], key=sort_key)
        proposed = {
            "ledgerGeneration": prior_generation + 1,
            "previousLedgerDigest": prior_digest,
            "records": records,
            "version": 1,
        }
        proposed_raw = canonical_state_impl(proposed)
        frozen_records = tuple(record_tuple(record) for record in records)
        return mapping_proxy_type({
            "structuralOnly": True,
            "namespace": intent_value["namespace"],
            "phase": intent_value["phase"],
            "proposedLedgerGeneration": prior_generation + 1,
            "previousLedgerDigest": prior_digest,
            "proposedLedgerDigest": sha256(proposed_raw).hexdigest(),
            "proposedState": proposed_raw,
            "records": frozen_records,
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
            raise refusal("one_shot_ledger_transition") from None

    def canonical_intent(value):
        return invoke(canonical_intent_impl, value)

    def canonical_state(value):
        return invoke(canonical_state_impl, value)

    def plan_transition(prior_raw, intent_raw):
        return invoke(plan_impl, prior_raw, intent_raw)

    return canonical_intent, canonical_state, plan_transition


canonical_intent, canonical_state, plan_transition = _make_planner()
del _make_planner
