import copy
import hashlib
import importlib.util
import json
from pathlib import Path
from types import MappingProxyType
import unittest


MODULE_PATH = Path(__file__).with_name(
    "checkout-runtime-configuration-policy-artifact.py"
)
SPEC = importlib.util.spec_from_file_location(
    "checkout_runtime_configuration_policy_artifact", MODULE_PATH
)
policy = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(policy)


MAX_ARTIFACT_BYTES = 32 * 1024
MAX_LIFETIME_SECONDS = 7 * 24 * 60 * 60
ROLE = "runtime-configuration-policy"
ENVIRONMENT = "synthetic-test"
PUBLIC_ORIGIN = "https://psikotes.oncam.id"
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
RESULT_FIELDS = (
    "structuralOnly",
    "role",
    "issuerId",
    "artifactId",
    "generation",
    "environment",
    "issuedAt",
    "expiresAt",
    "replayId",
    "artifactDigest",
    "policyDigest",
)


def canonical(value):
    return (
        json.dumps(
            value,
            sort_keys=True,
            separators=(",", ":"),
            ensure_ascii=True,
            allow_nan=False,
        )
        + "\n"
    ).encode("ascii")


def fixture():
    return {
        "artifactId": "runtime-policy-20260907",
        "browserConfigTemplate": {
            "bytesDigest": "2" * 64,
            "schema": BROWSER_CONFIG_SCHEMA[0],
            "schemaVersion": BROWSER_CONFIG_SCHEMA[1],
        },
        "budgets": {
            "executionSeconds": BUDGETS[0],
            "recoverySeconds": BUDGETS[1],
        },
        "environment": ENVIRONMENT,
        "expiresAt": "2026-09-14T02:03:04Z",
        "generation": 4,
        "issuedAt": "2026-09-07T02:03:04Z",
        "issuerId": "runtime-policy-custodian-01",
        "launchArguments": list(BROWSER_LAUNCH_ARGS),
        "networkPolicy": {
            "browserOffline": NETWORK_POLICY[0],
            "denyExternalNetwork": NETWORK_POLICY[1],
            "serviceWorkers": NETWORK_POLICY[2],
        },
        "outputNames": {
            "browserConfig": OUTPUT_NAMES[0],
            "runtimeIni": OUTPUT_NAMES[1],
            "supervisorConfig": OUTPUT_NAMES[2],
        },
        "publicEndpoint": {"origin": PUBLIC_ORIGIN, "port": 443},
        "replayId": "runtime-policy-20260907-01",
        "role": ROLE,
        "runtimeIni": {
            "bytesDigest": "1" * 64,
            "schema": RUNTIME_INI_SCHEMA[0],
            "schemaVersion": RUNTIME_INI_SCHEMA[1],
        },
        "variables": [
            {
                "format": format_name,
                "name": name,
                "presence": presence,
                "valueType": value_type,
            }
            for name, presence, value_type, format_name in VARIABLE_RULES
        ],
        "version": 1,
    }


class RuntimeConfigurationPolicyArtifactTests(unittest.TestCase):
    def assert_refused(self, value):
        raw = canonical(value) if type(value) is dict else value
        with self.assertRaisesRegex(
            policy.RuntimeConfigurationPolicyArtifactRefused,
            "^runtime_configuration_policy_artifact$",
        ):
            policy.decode(raw)

    def test_known_vector_is_canonical_narrow_immutable_and_structural_only(self):
        document = fixture()
        raw = canonical(document)
        result = policy.decode(raw)

        self.assertEqual(policy.canonical_artifact(document), raw)
        self.assertIs(type(result), MappingProxyType)
        self.assertEqual(tuple(result.keys()), RESULT_FIELDS)
        self.assertEqual(
            dict(result),
            {
                "structuralOnly": True,
                "role": ROLE,
                "issuerId": "runtime-policy-custodian-01",
                "artifactId": "runtime-policy-20260907",
                "generation": 4,
                "environment": ENVIRONMENT,
                "issuedAt": "2026-09-07T02:03:04Z",
                "expiresAt": "2026-09-14T02:03:04Z",
                "replayId": "runtime-policy-20260907-01",
                "artifactDigest": "99fc56dfa354bb65999392ce3a3db9e562a817ae6373d17d7d0080d9c439eb54",
                "policyDigest": "8a189a8bdc92a3c358e949ba4089270d6ddc0344dcdc1107d129466d8de15cfe",
            },
        )
        with self.assertRaises(TypeError):
            result["admitted"] = True
        for forbidden in (
            "admitted",
            "authenticated",
            "credential",
            "privateKey",
            "runtimeAccepted",
            "secret",
            "trusted",
            "value",
        ):
            self.assertNotIn(forbidden, result)
            self.assertFalse(hasattr(result, forbidden))

    def test_public_contract_and_structural_constants_are_exact(self):
        self.assertEqual(
            policy.__all__,
            (
                "RuntimeConfigurationPolicyArtifactRefused",
                "canonical_artifact",
                "decode",
            ),
        )
        self.assertEqual(policy.MAX_ARTIFACT_BYTES, MAX_ARTIFACT_BYTES)
        self.assertEqual(policy.MAX_LIFETIME_SECONDS, MAX_LIFETIME_SECONDS)
        self.assertEqual(policy.ROLE, ROLE)
        self.assertEqual(policy.ENVIRONMENTS, (ENVIRONMENT,))
        self.assertEqual(policy.PUBLIC_ORIGINS, (PUBLIC_ORIGIN,))
        self.assertEqual(policy.VARIABLE_RULES, VARIABLE_RULES)
        self.assertEqual(policy.BROWSER_LAUNCH_ARGS, BROWSER_LAUNCH_ARGS)
        self.assertEqual(policy.RUNTIME_INI_SCHEMA, RUNTIME_INI_SCHEMA)
        self.assertEqual(policy.BROWSER_CONFIG_SCHEMA, BROWSER_CONFIG_SCHEMA)
        self.assertEqual(policy.NETWORK_POLICY, NETWORK_POLICY)
        self.assertEqual(policy.BUDGETS, BUDGETS)
        self.assertEqual(policy.OUTPUT_NAMES, OUTPUT_NAMES)

    def test_json_must_be_bounded_canonical_ascii_with_one_lf(self):
        raw = canonical(fixture())
        variants = (
            raw[:-1],
            raw + b"\n",
            b"\xef\xbb\xbf" + raw,
            json.dumps(fixture()).encode("ascii"),
            raw.replace(b'"environment":"synthetic-test",', b' "environment": "synthetic-test",'),
            b'{"version":1,"version":1}\n',
            b'{"version":NaN}\n',
            b"[]\n",
            b"null\n",
            b"1\n",
            b"{" + b" " * MAX_ARTIFACT_BYTES + b"}\n",
            "not-bytes",
            bytearray(raw),
        )
        for candidate in variants:
            with self.subTest(candidate=repr(candidate)[:60]):
                self.assert_refused(candidate)

    def test_top_level_and_nested_schema_are_exact_and_types_strict(self):
        document = fixture()
        for key in tuple(document):
            changed = copy.deepcopy(document)
            del changed[key]
            with self.subTest(missing=key):
                self.assert_refused(changed)
        for key, bad in (
            ("version", True),
            ("version", 1.0),
            ("version", 2),
            ("environment", True),
            ("environment", "production"),
            ("variables", tuple(document["variables"])),
            ("publicEndpoint", [PUBLIC_ORIGIN, 443]),
        ):
            changed = copy.deepcopy(document)
            changed[key] = bad
            with self.subTest(key=key, bad=bad):
                if type(bad) is tuple:
                    with self.assertRaises(
                        policy.RuntimeConfigurationPolicyArtifactRefused
                    ):
                        policy.canonical_artifact(changed)
                else:
                    self.assert_refused(changed)
        changed = copy.deepcopy(document)
        changed["runtimeIni"] = "secret"
        self.assert_refused(changed)
        for key in ("name", "presence", "valueType", "format"):
            changed = copy.deepcopy(document)
            del changed["variables"][0][key]
            with self.subTest(variable_missing=key):
                self.assert_refused(changed)
        changed = copy.deepcopy(document)
        changed["variables"][0]["value"] = "false"
        self.assert_refused(changed)

    def test_variable_inventory_is_exact_ordered_and_cannot_name_secrets(self):
        document = fixture()
        mutations = []
        missing = copy.deepcopy(document)
        missing["variables"].pop()
        mutations.append(missing)
        duplicate = copy.deepcopy(document)
        duplicate["variables"].append(copy.deepcopy(duplicate["variables"][0]))
        mutations.append(duplicate)
        reordered = copy.deepcopy(document)
        reordered["variables"][0], reordered["variables"][1] = (
            reordered["variables"][1],
            reordered["variables"][0],
        )
        mutations.append(reordered)
        for name in ("APP_KEY", "DB_PASSWORD", "TOKEN", "TLS_PRIVATE_KEY_PATH"):
            changed = copy.deepcopy(document)
            changed["variables"][0]["name"] = name
            mutations.append(changed)
        for index, field, bad in (
            (0, "presence", "optional"),
            (0, "valueType", "string"),
            (0, "format", "string"),
            (8, "presence", "required"),
            (8, "valueType", "string"),
            (8, "format", "uri"),
        ):
            changed = copy.deepcopy(document)
            changed["variables"][index][field] = bad
            mutations.append(changed)
        for changed in mutations:
            with self.subTest(changed=changed["variables"][:1]):
                self.assert_refused(changed)

    def test_signed_identity_fields_are_exact_strict_and_bounded(self):
        document = fixture()
        for key, bad in (
            ("role", "release-source"),
            ("role", True),
            ("issuerId", ""),
            ("issuerId", "Issuer"),
            ("issuerId", "a" * 65),
            ("artifactId", "../artifact"),
            ("artifactId", "a" * 65),
            ("generation", 0),
            ("generation", True),
            ("generation", 1.0),
            ("generation", 1 << 63),
        ):
            changed = copy.deepcopy(document)
            changed[key] = bad
            with self.subTest(key=key, bad=bad):
                self.assert_refused(changed)

    def test_runtime_ini_and_browser_template_bind_exact_bytes_and_schema(self):
        document = fixture()
        for binding_name in ("runtimeIni", "browserConfigTemplate"):
            for field in ("bytesDigest", "schema", "schemaVersion"):
                changed = copy.deepcopy(document)
                del changed[binding_name][field]
                with self.subTest(binding=binding_name, missing=field):
                    self.assert_refused(changed)
            changed = copy.deepcopy(document)
            changed[binding_name]["path"] = "candidate/private.ini"
            self.assert_refused(changed)

        mutations = (
            ("runtimeIni", "bytesDigest", "A" * 64),
            ("runtimeIni", "bytesDigest", "1" * 63),
            ("runtimeIni", "schema", "other-runtime-ini"),
            ("runtimeIni", "schemaVersion", True),
            ("runtimeIni", "schemaVersion", 2),
            ("browserConfigTemplate", "bytesDigest", "g" * 64),
            ("browserConfigTemplate", "schema", "other-browser-config"),
            ("browserConfigTemplate", "schemaVersion", 2),
        )
        for binding_name, field, bad in mutations:
            changed = copy.deepcopy(document)
            changed[binding_name][field] = bad
            with self.subTest(binding=binding_name, field=field, bad=bad):
                self.assert_refused(changed)

    def test_launch_arguments_are_exact_ordered_unique_and_not_extensible(self):
        document = fixture()
        variants = (
            [],
            list(BROWSER_LAUNCH_ARGS[:-1]),
            list(reversed(BROWSER_LAUNCH_ARGS)),
            list(BROWSER_LAUNCH_ARGS) + [BROWSER_LAUNCH_ARGS[0]],
            list(BROWSER_LAUNCH_ARGS) + ["--proxy-server=http://127.0.0.1"],
            [BROWSER_LAUNCH_ARGS[0], "--no-proxy-server", "--no-proxy-server"],
            tuple(BROWSER_LAUNCH_ARGS),
        )
        for args in variants:
            changed = copy.deepcopy(document)
            changed["launchArguments"] = args
            with self.subTest(args=args):
                if type(args) is tuple:
                    with self.assertRaises(
                        policy.RuntimeConfigurationPolicyArtifactRefused
                    ):
                        policy.canonical_artifact(changed)
                else:
                    self.assert_refused(changed)

    def test_network_policy_budgets_and_output_names_are_exact_and_closed(self):
        document = fixture()
        nested = {
            "networkPolicy": {
                "browserOffline": True,
                "denyExternalNetwork": True,
                "serviceWorkers": "block",
            },
            "budgets": {"executionSeconds": 120, "recoverySeconds": 30},
            "outputNames": {
                "browserConfig": "browser-config.json",
                "runtimeIni": "runtime.ini",
                "supervisorConfig": "supervisor-config.json",
            },
        }
        for section, expected in nested.items():
            for key in tuple(expected):
                changed = copy.deepcopy(document)
                del changed[section][key]
                with self.subTest(section=section, missing=key):
                    self.assert_refused(changed)
            changed = copy.deepcopy(document)
            changed[section]["extra"] = True
            self.assert_refused(changed)

        mutations = (
            ("networkPolicy", "browserOffline", False),
            ("networkPolicy", "browserOffline", 1),
            ("networkPolicy", "denyExternalNetwork", False),
            ("networkPolicy", "serviceWorkers", "allow"),
            ("budgets", "executionSeconds", True),
            ("budgets", "executionSeconds", 0),
            ("budgets", "executionSeconds", 121),
            ("budgets", "recoverySeconds", 31),
            ("outputNames", "browserConfig", "../browser-config.json"),
            ("outputNames", "runtimeIni", "key.pem"),
            ("outputNames", "supervisorConfig", "runtime.ini"),
        )
        for section, key, bad in mutations:
            changed = copy.deepcopy(document)
            changed[section][key] = bad
            with self.subTest(section=section, key=key, bad=bad):
                self.assert_refused(changed)

    def test_environment_and_public_origin_are_fixed_public_constraints(self):
        document = fixture()
        for bad in (
            "http://psikotes.oncam.id",
            "https://localhost",
            "https://127.0.0.1",
            "https://user@psikotes.oncam.id",
            "https://psikotes.oncam.id:444",
            "https://psikotes.oncam.id/path",
            "https://psikotes.oncam.id?query=1",
            "https://psikotes.oncam.id#fragment",
            "https://PSIKOTES.ONCAM.ID",
            "https://example.com",
        ):
            changed = copy.deepcopy(document)
            changed["publicEndpoint"]["origin"] = bad
            with self.subTest(origin=bad):
                self.assert_refused(changed)
        for bad in (0, 80, 444, True, "443"):
            changed = copy.deepcopy(document)
            changed["publicEndpoint"]["port"] = bad
            with self.subTest(port=bad):
                self.assert_refused(changed)
        for key in ("origin", "port"):
            changed = copy.deepcopy(document)
            del changed["publicEndpoint"][key]
            self.assert_refused(changed)
        changed = copy.deepcopy(document)
        changed["publicEndpoint"]["path"] = "/checkout"
        self.assert_refused(changed)

    def test_timestamps_are_exact_utc_calendar_values_with_positive_seven_day_cap(self):
        document = fixture()
        valid = copy.deepcopy(document)
        valid["issuedAt"] = "2024-02-29T00:00:00Z"
        valid["expiresAt"] = "2024-03-07T00:00:00Z"
        policy.decode(canonical(valid))

        for issued, expires in (
            ("2026-09-07T02:03:04Z", "2026-09-07T02:03:04Z"),
            ("2026-09-07T02:03:04Z", "2026-09-14T02:03:05Z"),
            ("2026-09-07T02:03:04+00:00", "2026-09-08T02:03:04Z"),
            ("2026-09-07t02:03:04Z", "2026-09-08T02:03:04Z"),
            ("2026-09-07T02:03:04.000Z", "2026-09-08T02:03:04Z"),
            ("2026-02-29T00:00:00Z", "2026-03-01T00:00:00Z"),
            ("9999-12-31T23:59:59Z", "9999-12-31T23:59:59Z"),
        ):
            changed = copy.deepcopy(document)
            changed["issuedAt"] = issued
            changed["expiresAt"] = expires
            with self.subTest(issued=issued, expires=expires):
                self.assert_refused(changed)

    def test_replay_id_is_lowercase_ascii_opaque_and_bounded(self):
        document = fixture()
        for valid in ("a", "a" + "-" * 63):
            changed = copy.deepcopy(document)
            changed["replayId"] = valid
            policy.decode(canonical(changed))
        for bad in ("", "a" * 65, "Replay", "../replay", "replay id", True, None):
            changed = copy.deepcopy(document)
            changed["replayId"] = bad
            with self.subTest(replay_id=bad):
                self.assert_refused(changed)

    def test_secret_value_path_credential_and_private_key_metadata_are_closed(self):
        document = fixture()
        forbidden_fields = (
            "credentials",
            "environmentValues",
            "password",
            "privateKey",
            "secretValues",
            "tlsPrivateKeyHash",
            "tlsPrivateKeyMetadata",
            "tlsPrivateKeyPath",
            "token",
            "values",
        )
        for field in forbidden_fields:
            changed = copy.deepcopy(document)
            changed[field] = "do-not-serialize"
            with self.subTest(field=field):
                self.assert_refused(changed)

    def test_digest_is_domain_separated_and_deterministic(self):
        document = fixture()
        raw = canonical(document)
        result = policy.decode(raw)
        domain = b"oncam.checkout.runtime-configuration-policy-artifact.v1\0"
        self.assertEqual(result["artifactDigest"], hashlib.sha256(raw).hexdigest())
        self.assertEqual(
            result["policyDigest"], hashlib.sha256(domain + raw).hexdigest()
        )
        reordered = dict(reversed(tuple(document.items())))
        self.assertEqual(policy.canonical_artifact(reordered), raw)
        self.assertEqual(dict(policy.decode(raw)), dict(result))

    def test_dependency_and_authority_replacement_refuse_before_execution(self):
        raw = canonical(fixture())
        original_refusal = policy.RuntimeConfigurationPolicyArtifactRefused
        cases = (
            (policy, "ROLE", "release-source", None),
            (policy, "VARIABLE_RULES", tuple(list(policy.VARIABLE_RULES)), None),
            (policy, "ENVIRONMENTS", policy.ENVIRONMENTS + ("production",), None),
            (policy, "PUBLIC_ORIGINS", policy.PUBLIC_ORIGINS + ("https://example.com",), None),
            (policy, "BROWSER_LAUNCH_ARGS", tuple(list(policy.BROWSER_LAUNCH_ARGS)), None),
            (policy, "RUNTIME_INI_SCHEMA", tuple(list(policy.RUNTIME_INI_SCHEMA)), None),
            (policy, "BROWSER_CONFIG_SCHEMA", tuple(list(policy.BROWSER_CONFIG_SCHEMA)), None),
            (policy, "NETWORK_POLICY", tuple(list(policy.NETWORK_POLICY)), None),
            (policy, "BUDGETS", tuple(list(policy.BUDGETS)), None),
            (policy, "OUTPUT_NAMES", tuple(list(policy.OUTPUT_NAMES)), None),
            (policy, "MAX_ARTIFACT_BYTES", MAX_ARTIFACT_BYTES + 1, None),
            (policy, "MAX_LIFETIME_SECONDS", MAX_LIFETIME_SECONDS + 1, None),
            (policy, "RuntimeConfigurationPolicyArtifactRefused", Exception, None),
            (policy, "json", object(), None),
            (policy, "hashlib", object(), None),
            (policy, "re", object(), None),
            (policy, "datetime", object(), None),
        )
        for owner, name, replacement, _unused in cases:
            original = getattr(owner, name)
            try:
                setattr(owner, name, replacement)
                with self.subTest(authority=name):
                    with self.assertRaises(original_refusal):
                        policy.decode(raw)
            finally:
                setattr(owner, name, original)

        dependency_cases = (
            (policy.json, "loads", {}),
            (policy.json, "dumps", "{}"),
            (policy.hashlib, "sha256", None),
            (policy.re, "fullmatch", None),
        )
        for owner, name, replacement_result in dependency_cases:
            original = getattr(owner, name)
            called = []

            def replacement(*_args, value=replacement_result, **_kwargs):
                called.append(True)
                return value

            try:
                setattr(owner, name, replacement)
                with self.subTest(dependency=name):
                    with self.assertRaises(original_refusal):
                        policy.decode(raw)
                    self.assertEqual(called, [])
            finally:
                setattr(owner, name, original)

    def test_private_guard_preserves_baseexception_identity_and_postcheck(self):
        invoke = next(
            cell.cell_contents
            for cell in policy.decode.__closure__
            if callable(cell.cell_contents)
            and getattr(cell.cell_contents, "__name__", "") == "invoke"
        )
        for primary in (KeyboardInterrupt("k"), SystemExit("s")):
            def stop(error=primary):
                raise error

            with self.assertRaises(type(primary)) as raised:
                invoke(stop)
            self.assertIs(raised.exception, primary)

        original_rules = policy.VARIABLE_RULES
        replacement_rules = tuple(list(original_rules))
        for primary in (KeyboardInterrupt("drift-k"), SystemExit("drift-s")):
            def drift_then_stop(error=primary):
                policy.VARIABLE_RULES = replacement_rules
                raise error

            try:
                with self.assertRaises(type(primary)) as raised:
                    invoke(drift_then_stop)
                self.assertIs(raised.exception, primary)
            finally:
                policy.VARIABLE_RULES = original_rules

    def test_no_environment_runtime_mutation_or_admission_surface_exists(self):
        # Export rebinding is a composition-caller boundary; the composition
        # layer must pin canonical_artifact/decode before using this codec.
        for forbidden in (
            "admit",
            "apply",
            "configure",
            "getenv",
            "load_environment",
            "mutate_config",
            "read_environment",
            "setenv",
            "verify_signature",
        ):
            self.assertFalse(hasattr(policy, forbidden))


if __name__ == "__main__":
    unittest.main()
