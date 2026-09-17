"""Pure structural codec for an ADR-021 runtime-configuration policy artifact.

The codec validates only supplied canonical bytes and derives digests.  It does
not read or change an environment, reveal values, authenticate an issuer,
evaluate freshness/replay/revocation, or grant preparation/runtime admission.
Composition must pin the exported callables before using this module.
"""

from __future__ import annotations

import datetime
import hashlib
import json
import re
from types import MappingProxyType


MAX_ARTIFACT_BYTES = 32 * 1024
MAX_LIFETIME_SECONDS = 7 * 24 * 60 * 60
ROLE = "runtime-configuration-policy"
ENVIRONMENTS = ("synthetic-test",)
PUBLIC_ORIGINS = ("https://psikotes.oncam.id",)
BROWSER_LAUNCH_ARGS = (
    "--host-resolver-rules=MAP psikotes.oncam.id 127.0.0.1,MAP oncam.id 127.0.0.1,MAP * ~NOTFOUND",
    "--no-proxy-server",
    "--disable-background-networking",
)
RUNTIME_INI_SCHEMA = ("checkout-runtime-ini", 1)
BROWSER_CONFIG_SCHEMA = ("checkout-browser-config", 1)
NETWORK_POLICY = (True, True, "block")
BUDGETS = (120, 30)
OUTPUT_NAMES = (
    "browser-config.json",
    "runtime.ini",
    "supervisor-config.json",
)
VARIABLE_RULES = (
    ("APP_DEBUG", "required", "boolean", "boolean-literal"),
    ("APP_ENV", "required", "string", "environment-name"),
    ("APP_LOCALE", "required", "string", "locale-tag"),
    ("APP_URL", "required", "string", "https-origin"),
    ("CACHE_STORE", "required", "string", "driver-name"),
    (
        "CHECKOUT_PAYMENT_ACTIONS_ENABLED",
        "required",
        "boolean",
        "boolean-literal",
    ),
    ("DB_CONNECTION", "required", "string", "driver-name"),
    ("DB_DATABASE", "required", "string", "absolute-path"),
    ("DB_URL", "absent", "none", "none"),
    ("QUEUE_CONNECTION", "required", "string", "driver-name"),
    ("SESSION_DRIVER", "required", "string", "driver-name"),
)


class RuntimeConfigurationPolicyArtifactRefused(Exception):
    """Fixed refusal without policy or dependency details."""


__all__ = (
    "RuntimeConfigurationPolicyArtifactRefused",
    "canonical_artifact",
    "decode",
)


def _make_codec():
    refusal = RuntimeConfigurationPolicyArtifactRefused
    mapping_proxy_type = MappingProxyType
    maximum_artifact = MAX_ARTIFACT_BYTES
    maximum_lifetime = MAX_LIFETIME_SECONDS
    role = ROLE
    environments = ENVIRONMENTS
    public_origins = PUBLIC_ORIGINS
    launch_arguments = BROWSER_LAUNCH_ARGS
    runtime_ini_schema = RUNTIME_INI_SCHEMA
    browser_config_schema = BROWSER_CONFIG_SCHEMA
    network_policy = NETWORK_POLICY
    budgets = BUDGETS
    output_names = OUTPUT_NAMES
    variable_rules = VARIABLE_RULES
    public_surface = __all__
    timestamp_pattern = r"[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z"
    identifier_pattern = r"[a-z0-9](?:[a-z0-9._-]{0,63})"
    top_keys = frozenset({
        "artifactId",
        "browserConfigTemplate",
        "budgets",
        "environment",
        "expiresAt",
        "generation",
        "issuedAt",
        "issuerId",
        "launchArguments",
        "networkPolicy",
        "outputNames",
        "publicEndpoint",
        "replayId",
        "role",
        "runtimeIni",
        "variables",
        "version",
    })
    variable_keys = frozenset({"format", "name", "presence", "valueType"})
    binding_keys = frozenset({"bytesDigest", "schema", "schemaVersion"})
    budget_keys = frozenset({"executionSeconds", "recoverySeconds"})
    network_keys = frozenset({
        "browserOffline", "denyExternalNetwork", "serviceWorkers"
    })
    output_keys = frozenset({
        "browserConfig", "runtimeIni", "supervisorConfig"
    })
    endpoint_keys = frozenset({"origin", "port"})
    domain = b"oncam.checkout.runtime-configuration-policy-artifact.v1\0"

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

    def mapping_matches(value, snapshot):
        if snapshot is None:
            return value is None
        if type(value) is not dict or len(value) != len(snapshot):
            return False
        for key, expected in snapshot:
            if key not in value:
                return False
            try:
                if freeze_metadata(value[key]) != expected:
                    return False
            except Exception:
                return False
        return True

    def callable_state(function):
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

    dependencies = (
        (json_module, "dumps", callable_state(json_dumps)),
        (json_module, "loads", callable_state(json_loads)),
        (hashlib_module, "sha256", callable_state(sha256)),
        (re_module, "fullmatch", callable_state(re_fullmatch)),
    )
    global_pins = (
        ("MAX_ARTIFACT_BYTES", maximum_artifact),
        ("MAX_LIFETIME_SECONDS", maximum_lifetime),
        ("ROLE", role),
        ("ENVIRONMENTS", environments),
        ("PUBLIC_ORIGINS", public_origins),
        ("BROWSER_LAUNCH_ARGS", launch_arguments),
        ("RUNTIME_INI_SCHEMA", runtime_ini_schema),
        ("BROWSER_CONFIG_SCHEMA", browser_config_schema),
        ("NETWORK_POLICY", network_policy),
        ("BUDGETS", budgets),
        ("OUTPUT_NAMES", output_names),
        ("VARIABLE_RULES", variable_rules),
        ("RuntimeConfigurationPolicyArtifactRefused", refusal),
        ("MappingProxyType", mapping_proxy_type),
        ("__all__", public_surface),
        ("json", json_module),
        ("hashlib", hashlib_module),
        ("re", re_module),
        ("datetime", datetime_module),
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
            raise refusal("runtime_configuration_policy_artifact") from None

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
                value,
                sort_keys=True,
                separators=(",", ":"),
                ensure_ascii=True,
                allow_nan=False,
            )
            + "\n"
        ).encode("ascii")

    def exact_dict(value, keys):
        return type(value) is dict \
            and all(type(key) is str for key in value) \
            and set(value) == keys

    def exact_rule(value, expected):
        if not exact_dict(value, variable_keys):
            return False
        values = (
            value["name"],
            value["presence"],
            value["valueType"],
            value["format"],
        )
        return all(type(item) is str for item in values) and values == expected

    def identifier(value):
        return type(value) is str \
            and re_fullmatch(identifier_pattern, value) is not None

    def positive_int63(value):
        return type(value) is int and 1 <= value < (1 << 63)

    def lowercase_hex(value):
        return type(value) is str and len(value) == 64 \
            and re_fullmatch(r"[a-f0-9]+", value) is not None

    def exact_binding(value, expected):
        return exact_dict(value, binding_keys) \
            and lowercase_hex(value["bytesDigest"]) \
            and type(value["schema"]) is str \
            and value["schema"] == expected[0] \
            and type(value["schemaVersion"]) is int \
            and value["schemaVersion"] == expected[1]

    def parse_timestamp(value):
        if type(value) is not str \
                or re_fullmatch(timestamp_pattern, value) is None:
            raise ValueError("timestamp")
        parsed = datetime_strptime(value, "%Y-%m-%dT%H:%M:%SZ")
        if parsed.strftime("%Y-%m-%dT%H:%M:%SZ") != value:
            raise ValueError("timestamp")
        return parsed

    def validate(value):
        if not exact_dict(value, top_keys) \
                or type(value["version"]) is not int or value["version"] != 1 \
                or type(value["role"]) is not str or value["role"] != role \
                or not identifier(value["issuerId"]) \
                or not identifier(value["artifactId"]) \
                or not positive_int63(value["generation"]) \
                or type(value["environment"]) is not str \
                or value["environment"] not in environments \
                or type(value["replayId"]) is not str \
                or re_fullmatch(identifier_pattern, value["replayId"]) is None \
                or not exact_binding(value["runtimeIni"], runtime_ini_schema) \
                or not exact_binding(
                    value["browserConfigTemplate"], browser_config_schema
                ) \
                or type(value["launchArguments"]) is not list \
                or any(type(item) is not str for item in value["launchArguments"]) \
                or tuple(value["launchArguments"]) != launch_arguments \
                or not exact_dict(value["publicEndpoint"], endpoint_keys) \
                or type(value["publicEndpoint"]["origin"]) is not str \
                or value["publicEndpoint"]["origin"] not in public_origins \
                or type(value["publicEndpoint"]["port"]) is not int \
                or value["publicEndpoint"]["port"] != 443 \
                or not exact_dict(value["networkPolicy"], network_keys) \
                or type(value["networkPolicy"]["browserOffline"]) is not bool \
                or value["networkPolicy"]["browserOffline"] is not network_policy[0] \
                or type(value["networkPolicy"]["denyExternalNetwork"]) is not bool \
                or value["networkPolicy"]["denyExternalNetwork"] is not network_policy[1] \
                or type(value["networkPolicy"]["serviceWorkers"]) is not str \
                or value["networkPolicy"]["serviceWorkers"] != network_policy[2] \
                or not exact_dict(value["budgets"], budget_keys) \
                or type(value["budgets"]["executionSeconds"]) is not int \
                or value["budgets"]["executionSeconds"] != budgets[0] \
                or type(value["budgets"]["recoverySeconds"]) is not int \
                or value["budgets"]["recoverySeconds"] != budgets[1] \
                or not exact_dict(value["outputNames"], output_keys) \
                or any(type(item) is not str for item in value["outputNames"].values()) \
                or (
                    value["outputNames"]["browserConfig"],
                    value["outputNames"]["runtimeIni"],
                    value["outputNames"]["supervisorConfig"],
                ) != output_names \
                or type(value["variables"]) is not list \
                or len(value["variables"]) != len(variable_rules) \
                or any(
                    not exact_rule(item, expected)
                    for item, expected in zip(value["variables"], variable_rules)
                ):
            raise ValueError("schema")
        issued = parse_timestamp(value["issuedAt"])
        expires = parse_timestamp(value["expiresAt"])
        lifetime = (expires - issued).total_seconds()
        if type(lifetime) is not float \
                or not 0 < lifetime <= maximum_lifetime:
            raise ValueError("lifetime")

    def canonical_impl(value):
        validate(value)
        raw = json_bytes(value)
        if len(raw) > maximum_artifact:
            raise ValueError("size")
        return raw

    def decode_impl(raw):
        if type(raw) is not bytes or not 1 <= len(raw) <= maximum_artifact \
                or not raw.endswith(b"\n") or raw.endswith(b"\n\n"):
            raise ValueError("raw")
        text = raw.decode("ascii")
        value = json_loads(
            text,
            object_pairs_hook=strict_object,
            parse_constant=lambda _value: (_ for _ in ()).throw(
                ValueError("constant")
            ),
        )
        validate(value)
        if json_bytes(value) != raw:
            raise ValueError("canonical")
        artifact_digest = sha256(raw).hexdigest()
        return mapping_proxy_type({
            "structuralOnly": True,
            "role": role,
            "issuerId": value["issuerId"],
            "artifactId": value["artifactId"],
            "generation": value["generation"],
            "environment": value["environment"],
            "issuedAt": value["issuedAt"],
            "expiresAt": value["expiresAt"],
            "replayId": value["replayId"],
            "artifactDigest": artifact_digest,
            "policyDigest": sha256(domain + raw).hexdigest(),
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
            raise refusal("runtime_configuration_policy_artifact") from None

    def canonical_artifact(value):
        return invoke(canonical_impl, value)

    def decode(raw):
        return invoke(decode_impl, raw)

    return canonical_artifact, decode


canonical_artifact, decode = _make_codec()
del _make_codec
