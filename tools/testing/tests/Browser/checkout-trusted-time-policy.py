"""Pure temporal policy over supplied data for ADR-021 composition.

This structural evaluator does not authenticate the supplied time evidence,
read the system clock, maintain high-water state, consume replay state, or grant
freshness, trust, admission, or runtime authority.  A composition caller must
pin this module's exported ``evaluate`` identity and bind the exact supplied
evidence through its separately accepted authority.
"""

from types import MappingProxyType
import hashlib
import json
import re


class TrustedTimePolicyRefused(Exception):
    """Fixed refusal without timestamps or evidence details."""


ARTIFACT_ROLES = (
    "asset-review",
    "composition-admission",
    "preparation-authorization",
    "release-source",
    "runtime-configuration-policy",
    "tool-runtime-closure",
    "trust-root-bundle",
    "vendor-build",
)
REVOCATION_ROLES = (
    "asset-review",
    "composition-admission",
    "preparation-acl",
    "preparation-authorization",
    "release-source",
    "revocation-snapshot",
    "runtime-configuration-policy",
    "tls-material",
    "tool-runtime-closure",
    "trust-root-bundle",
    "vendor-build",
)
ROLE_TTLS = MappingProxyType({
    "asset-review": 7 * 24 * 60 * 60,
    "composition-admission": 5 * 60,
    "preparation-authorization": 10 * 60,
    "release-source": 7 * 24 * 60 * 60,
    "runtime-configuration-policy": 7 * 24 * 60 * 60,
    "tool-runtime-closure": 7 * 24 * 60 * 60,
    "trust-root-bundle": 366 * 24 * 60 * 60,
    "vendor-build": 7 * 24 * 60 * 60,
})

__all__ = ("TrustedTimePolicyRefused", "evaluate")


def _make_evaluator():
    refusal = TrustedTimePolicyRefused
    mapping_proxy_type = MappingProxyType
    artifact_roles = ARTIFACT_ROLES
    revocation_roles = REVOCATION_ROLES
    role_ttls = ROLE_TTLS
    public_surface = __all__
    hashlib_module = hashlib
    json_module = json
    re_module = re
    sha256 = hashlib.sha256
    json_dumps = json.dumps
    re_fullmatch = re.fullmatch
    module_globals = globals()
    artifact_keys = frozenset({"expiresAt", "issuedAt", "role"})
    snapshot_keys = frozenset({
        "authorityRole", "issuedAt", "nextUpdate", "notBefore"
    })

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

    def function_state(function):
        defaults = getattr(function, "__defaults__", None)
        kwdefaults = getattr(function, "__kwdefaults__", None)
        return (
            function,
            type(function),
            getattr(function, "__code__", None),
            defaults,
            freeze_metadata(defaults),
            kwdefaults,
            mapping_snapshot(kwdefaults),
            getattr(function, "__closure__", None),
            getattr(function, "__globals__", None),
        )

    dependency_states = (
        (hashlib_module, "sha256", function_state(sha256)),
        (json_module, "dumps", function_state(json_dumps)),
        (re_module, "fullmatch", function_state(re_fullmatch)),
    )
    global_pins = (
        ("TrustedTimePolicyRefused", refusal),
        ("MappingProxyType", mapping_proxy_type),
        ("ARTIFACT_ROLES", artifact_roles),
        ("REVOCATION_ROLES", revocation_roles),
        ("ROLE_TTLS", role_ttls),
        ("__all__", public_surface),
        ("hashlib", hashlib_module),
        ("json", json_module),
        ("re", re_module),
    )

    def guard():
        try:
            for name, expected in global_pins:
                if module_globals.get(name) is not expected:
                    raise ValueError("authority")
            for module, name, state in dependency_states:
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
            raise refusal("trusted_time_policy") from None

    def exact_dict(value, keys):
        return type(value) is dict \
            and all(type(key) is str for key in value) \
            and set(value) == keys

    def timestamp(value):
        if type(value) is not str or re_fullmatch(
            r"[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z",
            value,
        ) is None:
            raise ValueError("timestamp")
        year = int(value[0:4])
        month = int(value[5:7])
        day = int(value[8:10])
        hour = int(value[11:13])
        minute = int(value[14:16])
        second = int(value[17:19])
        if not 1 <= year <= 9999 or not 1 <= month <= 12 \
                or hour > 23 or minute > 59 or second > 59:
            raise ValueError("timestamp")
        leap = year % 4 == 0 and (year % 100 != 0 or year % 400 == 0)
        month_days = (31, 29 if leap else 28, 31, 30, 31, 30,
                      31, 31, 30, 31, 30, 31)
        if not 1 <= day <= month_days[month - 1]:
            raise ValueError("timestamp")
        prior_year = year - 1
        days = 365 * prior_year + prior_year // 4 \
            - prior_year // 100 + prior_year // 400
        days += sum(month_days[:month - 1]) + day - 1
        return ((days * 24 + hour) * 60 + minute) * 60 + second

    def digest(value):
        return type(value) is str \
            and re_fullmatch(r"[a-f0-9]{64}", value) is not None

    def evaluate_impl(
        trusted_now,
        trusted_time_evidence_digest,
        prior_trusted_now,
        artifacts,
        revocation_snapshots,
    ):
        if not digest(trusted_time_evidence_digest):
            raise ValueError("evidence")
        now = timestamp(trusted_now)
        prior = timestamp(prior_trusted_now)
        if now < prior:
            raise ValueError("clock_regression")

        if type(artifacts) is not tuple or len(artifacts) != len(artifact_roles):
            raise ValueError("artifacts")
        for index, item in enumerate(artifacts):
            role = artifact_roles[index]
            if not exact_dict(item, artifact_keys) \
                    or type(item["role"]) is not str or item["role"] != role:
                raise ValueError("artifact")
            issued = timestamp(item["issuedAt"])
            expires = timestamp(item["expiresAt"])
            if not issued <= now < expires \
                    or not 0 < expires - issued <= role_ttls[role]:
                raise ValueError("artifact_interval")

        if type(revocation_snapshots) is not tuple \
                or len(revocation_snapshots) != len(revocation_roles):
            raise ValueError("snapshots")
        for index, item in enumerate(revocation_snapshots):
            role = revocation_roles[index]
            if not exact_dict(item, snapshot_keys) \
                    or type(item["authorityRole"]) is not str \
                    or item["authorityRole"] != role:
                raise ValueError("snapshot")
            issued = timestamp(item["issuedAt"])
            not_before = timestamp(item["notBefore"])
            next_update = timestamp(item["nextUpdate"])
            if not not_before <= issued <= now < next_update \
                    or not 0 < next_update - issued <= 24 * 60 * 60:
                raise ValueError("snapshot_interval")

        input_document = {
            "artifacts": artifacts,
            "priorTrustedNow": prior_trusted_now,
            "revocationSnapshots": revocation_snapshots,
            "trustedNow": trusted_now,
            "trustedTimeEvidenceDigest": trusted_time_evidence_digest,
        }
        input_bytes = (
            json_dumps(
                input_document,
                sort_keys=True,
                separators=(",", ":"),
                ensure_ascii=True,
                allow_nan=False,
            ) + "\n"
        ).encode("ascii")
        input_set_digest = sha256(
            b"oncam.checkout.trusted-time-policy-input-set.v1\0" + input_bytes
        ).hexdigest()
        return mapping_proxy_type({
            "structuralOnly": True,
            "intervalsMatch": True,
            "trustedNow": trusted_now,
            "trustedTimeEvidenceDigest": trusted_time_evidence_digest,
            "priorTrustedNow": prior_trusted_now,
            "artifactCount": len(artifacts),
            "revocationSnapshotCount": len(revocation_snapshots),
            "inputSetDigest": input_set_digest,
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
            raise refusal("trusted_time_policy") from None

    def evaluate(
        *,
        trusted_now,
        trusted_time_evidence_digest,
        prior_trusted_now,
        artifacts,
        revocation_snapshots,
    ):
        return invoke(
            evaluate_impl,
            trusted_now,
            trusted_time_evidence_digest,
            prior_trusted_now,
            artifacts,
            revocation_snapshots,
        )

    return evaluate


evaluate = _make_evaluator()
del _make_evaluator
