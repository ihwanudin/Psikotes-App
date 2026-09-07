"""Pure structural codec for supplied ADR-021 trust-bootstrap evidence.

This codec checks canonical structure for a two-operator/two-channel ceremony
and derives a digest.  The two opaque source-copy IDs and provenance digests
must be distinct; their source-content digests may match.  None authenticates
copy independence or provenance.  Opaque identifiers are not personal identity
evidence.
It does not authenticate operators, channels, copies, fingerprints, or trust;
perform bootstrap acceptance; verify signatures; grant admission; or run code.
A composition boundary must pin the exported callables and supply authority.
"""

from __future__ import annotations

import hashlib
import json
import re
from types import MappingProxyType


MAX_EVIDENCE_BYTES = 32 * 1024
MAX_AUTHORITY_IDS = 32
CHANNELS = (
    "offline-paper-record",
    "offline-read-only-media",
    "offline-secure-display",
)
ID_PATTERN = r"[a-z0-9](?:[a-z0-9._-]{0,127})"


class TrustBootstrapEvidenceRefused(Exception):
    """Fixed refusal without operator, channel, timestamp, or digest detail."""


__all__ = (
    "TrustBootstrapEvidenceRefused",
    "canonical_evidence",
    "decode",
)


def _make_codec():
    refusal = TrustBootstrapEvidenceRefused
    mapping_proxy_type = MappingProxyType
    maximum_evidence = MAX_EVIDENCE_BYTES
    maximum_authorities = MAX_AUTHORITY_IDS
    channels = CHANNELS
    identifier_pattern = ID_PATTERN
    public_surface = __all__
    top_keys = frozenset({
        "acquisitionEvidenceDigest", "acquisitionEvidenceGeneration",
        "bundleDigest", "bundleGeneration", "custodianIds",
        "expectedFingerprint", "fingerprintAlgorithm", "observations",
        "roleIssuerIds", "verifierDigest", "verifierGeneration", "version",
    })
    observation_keys = frozenset({
        "channelId", "fingerprint", "observedAt", "operatorId",
        "sourceCopyDigest", "sourceCopyId", "sourceCopyProvenanceDigest",
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
        ("MAX_EVIDENCE_BYTES", maximum_evidence),
        ("MAX_AUTHORITY_IDS", maximum_authorities),
        ("CHANNELS", channels),
        ("ID_PATTERN", identifier_pattern),
        ("TrustBootstrapEvidenceRefused", refusal),
        ("MappingProxyType", mapping_proxy_type),
        ("__all__", public_surface),
        ("json", json_module), ("hashlib", hashlib_module), ("re", re_module),
    )

    def guard():
        try:
            for name, expected in global_pins:
                if module_globals.get(name) is not expected:
                    raise ValueError("authority")
            for module, name, state in dependencies:
                current = getattr(module, name, None)
                if current is not state[0] or type(current) is not state[1]:
                    raise ValueError("dependency")
                defaults = getattr(current, "__defaults__", None)
                kwdefaults = getattr(current, "__kwdefaults__", None)
                if getattr(current, "__code__", None) is not state[2] \
                        or defaults is not state[3] \
                        or freeze_metadata(defaults) != state[4] \
                        or kwdefaults is not state[5] \
                        or not mapping_matches(kwdefaults, state[6]) \
                        or getattr(current, "__closure__", None) is not state[7] \
                        or getattr(current, "__globals__", None) is not state[8]:
                    raise ValueError("dependency")
        except refusal:
            raise
        except Exception:
            raise refusal("trust_bootstrap_evidence") from None

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
            json_dumps(value, sort_keys=True, separators=(",", ":"),
                       ensure_ascii=True, allow_nan=False) + "\n"
        ).encode("ascii")

    def identifier(value):
        return type(value) is str \
            and re_fullmatch(identifier_pattern, value) is not None

    def digest(value):
        return type(value) is str \
            and re_fullmatch(r"[a-f0-9]{64}", value) is not None

    def positive_int63(value):
        return type(value) is int and 1 <= value < (1 << 63)

    def timestamp(value):
        if type(value) is not str:
            return None
        match = re_fullmatch(
            r"([0-9]{4})-([0-9]{2})-([0-9]{2})T"
            r"([0-9]{2}):([0-9]{2}):([0-9]{2})Z", value,
        )
        if match is None:
            return None
        year, month, day, hour, minute, second = (
            int(part) for part in match.groups()
        )
        if not 1 <= year <= 9999 or not 1 <= month <= 12 \
                or hour > 23 or minute > 59 or second > 59:
            return None
        leap = year % 4 == 0 and (year % 100 != 0 or year % 400 == 0)
        month_days = (31, 29 if leap else 28, 31, 30, 31, 30,
                      31, 31, 30, 31, 30, 31)
        if not 1 <= day <= month_days[month - 1]:
            return None
        prior_year = year - 1
        days = 365 * prior_year + prior_year // 4 \
            - prior_year // 100 + prior_year // 400
        days += sum(month_days[:month - 1]) + day - 1
        return ((days * 24 + hour) * 60 + minute) * 60 + second

    def authority_ids(value):
        if type(value) is not list or not 1 <= len(value) <= maximum_authorities \
                or any(not identifier(item) for item in value) \
                or value != sorted(set(value)):
            raise ValueError("authority_ids")
        return tuple(value)

    def validate(value):
        if not exact_dict(value, top_keys) \
                or type(value["version"]) is not int or value["version"] != 1 \
                or type(value["fingerprintAlgorithm"]) is not str \
                or value["fingerprintAlgorithm"] != "sha256" \
                or not digest(value["expectedFingerprint"]):
            raise ValueError("evidence")
        for key in ("bundleDigest", "verifierDigest", "acquisitionEvidenceDigest"):
            if not digest(value[key]):
                raise ValueError("binding")
        for key in ("bundleGeneration", "verifierGeneration",
                    "acquisitionEvidenceGeneration"):
            if not positive_int63(value[key]):
                raise ValueError("binding")
        issuers = authority_ids(value["roleIssuerIds"])
        custodians = authority_ids(value["custodianIds"])
        if set(issuers) & set(custodians):
            raise ValueError("authority_ids")
        observations = value["observations"]
        if type(observations) is not list or len(observations) != 2:
            raise ValueError("observations")
        frozen = []
        observed_seconds = []
        for observation in observations:
            if not exact_dict(observation, observation_keys) \
                    or not identifier(observation["operatorId"]) \
                    or type(observation["channelId"]) is not str \
                    or observation["channelId"] not in channels \
                    or not digest(observation["fingerprint"]) \
                    or observation["fingerprint"] != value["expectedFingerprint"] \
                    or not digest(observation["sourceCopyDigest"]) \
                    or not identifier(observation["sourceCopyId"]) \
                    or not digest(observation["sourceCopyProvenanceDigest"]):
                raise ValueError("observation")
            seconds = timestamp(observation["observedAt"])
            if seconds is None:
                raise ValueError("timestamp")
            observed_seconds.append(seconds)
            frozen.append((
                observation["operatorId"], observation["channelId"],
                observation["observedAt"], observation["fingerprint"],
                observation["sourceCopyDigest"], observation["sourceCopyId"],
                observation["sourceCopyProvenanceDigest"],
            ))
        operators = [item[0] for item in frozen]
        used_channels = [item[1] for item in frozen]
        source_copy_ids = [item[5] for item in frozen]
        source_copy_provenance = [item[6] for item in frozen]
        if len(set(operators)) != 2 or len(set(used_channels)) != 2 \
                or len(set(source_copy_ids)) != 2 \
                or len(set(source_copy_provenance)) != 2 \
                or any(operator in issuers or operator in custodians
                       for operator in operators) \
                or frozen != sorted(
                    frozen, key=lambda item: (item[2], item[0], item[1])
                ) \
                or not 0 <= observed_seconds[1] - observed_seconds[0] <= 30 * 60:
            raise ValueError("ceremony")
        return observations[0]["observedAt"], observations[1]["observedAt"]

    def canonical_impl(value):
        validate(value)
        raw = json_bytes(value)
        if len(raw) > maximum_evidence:
            raise ValueError("size")
        return raw

    def decode_impl(raw):
        if type(raw) is not bytes or not 1 <= len(raw) <= maximum_evidence \
                or not raw.endswith(b"\n") or raw.endswith(b"\n\n"):
            raise ValueError("raw")
        value = json_loads(
            raw.decode("ascii"), object_pairs_hook=strict_object,
            parse_constant=lambda _value: (_ for _ in ()).throw(ValueError("constant")),
        )
        started, ended = validate(value)
        if json_bytes(value) != raw:
            raise ValueError("canonical")
        return mapping_proxy_type({
            "evidenceStructuralOnly": True,
            "bundleDigest": value["bundleDigest"],
            "bundleGeneration": value["bundleGeneration"],
            "verifierDigest": value["verifierDigest"],
            "verifierGeneration": value["verifierGeneration"],
            "acquisitionEvidenceDigest": value["acquisitionEvidenceDigest"],
            "acquisitionEvidenceGeneration": value["acquisitionEvidenceGeneration"],
            "expectedFingerprint": value["expectedFingerprint"],
            "ceremonyStartedAt": started,
            "ceremonyEndedAt": ended,
            "observationCount": 2,
            "evidenceDigest": sha256(raw).hexdigest(),
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
            raise refusal("trust_bootstrap_evidence") from None

    def canonical_evidence(value):
        return invoke(canonical_impl, value)

    def decode(raw):
        return invoke(decode_impl, raw)

    return canonical_evidence, decode


canonical_evidence, decode = _make_codec()
del _make_codec
