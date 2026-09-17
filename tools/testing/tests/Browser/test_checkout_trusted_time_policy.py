import importlib.util
from pathlib import Path
from types import MappingProxyType
import unittest


MODULE_PATH = Path(__file__).with_name("checkout-trusted-time-policy.py")
SPEC = importlib.util.spec_from_file_location("checkout_trusted_time_policy", MODULE_PATH)
policy = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(policy)


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


def inputs():
    artifacts = []
    for role in ARTIFACT_ROLES:
        issued_at = "2026-09-07T11:55:00Z" \
            if role == "composition-admission" else \
            "2026-09-07T11:50:00Z" \
            if role == "preparation-authorization" else \
            "2025-09-06T12:00:00Z" \
            if role == "trust-root-bundle" else \
            "2026-08-31T12:00:00Z"
        artifacts.append({
            "expiresAt": "2026-09-07T12:00:00Z",
            "issuedAt": issued_at,
            "role": role,
        })
    snapshots = [{
        "authorityRole": role,
        "issuedAt": "2026-09-07T00:00:00Z",
        "nextUpdate": "2026-09-08T00:00:00Z",
        "notBefore": "2026-09-07T00:00:00Z",
    } for role in REVOCATION_ROLES]
    return {
        "trusted_now": "2026-09-07T11:59:59Z",
        "trusted_time_evidence_digest": "a" * 64,
        "prior_trusted_now": "2026-09-07T11:59:58Z",
        "artifacts": tuple(artifacts),
        "revocation_snapshots": tuple(snapshots),
    }


class TrustedTimePolicyTests(unittest.TestCase):
    def assert_refused(self, **changes):
        values = inputs()
        values.update(changes)
        with self.assertRaisesRegex(
            policy.TrustedTimePolicyRefused, "^trusted_time_policy$"
        ):
            policy.evaluate(**values)

    def test_valid_intervals_return_narrow_immutable_structural_result(self):
        result = policy.evaluate(**inputs())
        self.assertIs(type(result), MappingProxyType)
        self.assertEqual(tuple(result), (
            "structuralOnly", "intervalsMatch", "trustedNow",
            "trustedTimeEvidenceDigest", "priorTrustedNow",
            "artifactCount", "revocationSnapshotCount", "inputSetDigest",
        ))
        self.assertIs(result["structuralOnly"], True)
        self.assertIs(result["intervalsMatch"], True)
        self.assertEqual(result["artifactCount"], 8)
        self.assertEqual(result["revocationSnapshotCount"], 11)
        with self.assertRaises(TypeError):
            result["trusted"] = True
        for forbidden in ("trusted", "fresh", "admitted", "current"):
            self.assertNotIn(forbidden, result)

    def test_api_and_exact_types_are_closed(self):
        self.assertEqual(policy.__all__, ("TrustedTimePolicyRefused", "evaluate"))
        with self.assertRaises(TypeError):
            policy.evaluate(*inputs().values())
        self.assert_refused(trusted_now=True)
        self.assert_refused(trusted_time_evidence_digest="A" * 64)
        self.assert_refused(trusted_time_evidence_digest=True)
        self.assert_refused(artifacts=list(inputs()["artifacts"]))
        self.assert_refused(revocation_snapshots=list(inputs()["revocation_snapshots"]))

    def test_timestamp_canonicality_and_calendar_are_strict(self):
        for bad in (
            "2026-09-07T11:59:59+00:00", "2026-09-07t11:59:59Z",
            "2026-09-07T11:59:59.0Z", "2026-02-29T00:00:00Z",
            "2026-09-07T24:00:00Z", "２０２６-09-07T11:59:59Z",
        ):
            with self.subTest(value=bad):
                self.assert_refused(trusted_now=bad)

    def test_clock_regression_refuses_but_equal_prior_is_unambiguous(self):
        values = inputs()
        values["prior_trusted_now"] = values["trusted_now"]
        self.assertTrue(policy.evaluate(**values)["intervalsMatch"])
        self.assert_refused(prior_trusted_now="2026-09-07T12:00:00Z")

    def test_artifact_roles_order_schema_and_ttls_are_exact(self):
        values = inputs()
        for mutation in (
            values["artifacts"][:-1],
            tuple(reversed(values["artifacts"])),
            values["artifacts"] + (values["artifacts"][0],),
        ):
            self.assert_refused(artifacts=mutation)
        changed = [dict(item) for item in values["artifacts"]]
        changed[0]["extra"] = True
        self.assert_refused(artifacts=tuple(changed))
        changed = [dict(item) for item in values["artifacts"]]
        changed[0]["role"] = "preparation-acl"
        self.assert_refused(artifacts=tuple(changed))

    def test_artifact_future_expiry_and_exact_role_limits_refuse(self):
        values = inputs()
        for index, key, timestamp in (
            (0, "issuedAt", "2026-09-07T12:00:00Z"),
            (0, "expiresAt", "2026-09-07T11:59:59Z"),
            (0, "expiresAt", "2026-09-14T12:00:01Z"),
            (1, "expiresAt", "2026-09-07T12:00:01Z"),
            (2, "expiresAt", "2026-09-07T12:05:01Z"),
            (6, "issuedAt", "2025-09-06T11:59:59Z"),
        ):
            changed = [dict(item) for item in values["artifacts"]]
            changed[index][key] = timestamp
            with self.subTest(index=index, key=key):
                self.assert_refused(artifacts=tuple(changed))

    def test_trust_root_bundle_exact_366_day_boundary_and_omission(self):
        values = inputs()
        trust_index = ARTIFACT_ROLES.index("trust-root-bundle")
        self.assertTrue(policy.evaluate(**values)["intervalsMatch"])
        self.assert_refused(artifacts=tuple(
            item for index, item in enumerate(values["artifacts"])
            if index != trust_index
        ))
        changed = [dict(item) for item in values["artifacts"]]
        changed[trust_index]["expiresAt"] = values["trusted_now"]
        self.assert_refused(artifacts=tuple(changed))

    def test_input_set_digest_binds_exact_ordered_interval_inputs(self):
        values = inputs()
        original = policy.evaluate(**values)
        changed = [dict(item) for item in values["artifacts"]]
        changed[0]["issuedAt"] = "2026-08-31T12:00:01Z"
        revised = policy.evaluate(**{**values, "artifacts": tuple(changed)})
        self.assertRegex(original["inputSetDigest"], r"^[a-f0-9]{64}$")
        self.assertNotEqual(original["inputSetDigest"], revised["inputSetDigest"])
        snapshots = [dict(item) for item in values["revocation_snapshots"]]
        snapshots[0]["issuedAt"] = "2026-09-07T00:00:01Z"
        revised_snapshot = policy.evaluate(**{
            **values,
            "revocation_snapshots": tuple(snapshots),
        })
        self.assertNotEqual(
            original["inputSetDigest"], revised_snapshot["inputSetDigest"]
        )

    def test_revocation_roles_order_schema_and_intervals_are_exact(self):
        values = inputs()
        for mutation in (
            values["revocation_snapshots"][:-1],
            tuple(reversed(values["revocation_snapshots"])),
        ):
            self.assert_refused(revocation_snapshots=mutation)
        cases = (
            (0, "notBefore", "2026-09-07T12:00:00Z"),
            (0, "issuedAt", "2026-09-07T12:00:00Z"),
            (0, "nextUpdate", "2026-09-07T11:59:59Z"),
            (0, "nextUpdate", "2026-09-08T00:00:01Z"),
            (0, "notBefore", "2026-09-07T00:00:01Z"),
        )
        for index, key, timestamp in cases:
            changed = [dict(item) for item in values["revocation_snapshots"]]
            changed[index][key] = timestamp
            with self.subTest(key=key):
                self.assert_refused(revocation_snapshots=tuple(changed))

    def test_boundary_semantics_are_half_open(self):
        values = inputs()
        changed = [dict(item) for item in values["artifacts"]]
        changed[0]["expiresAt"] = values["trusted_now"]
        self.assert_refused(artifacts=tuple(changed))
        changed = [dict(item) for item in values["revocation_snapshots"]]
        changed[0]["nextUpdate"] = values["trusted_now"]
        self.assert_refused(revocation_snapshots=tuple(changed))

    def test_dependency_and_authority_drift_fail_closed(self):
        refusal = policy.TrustedTimePolicyRefused
        for name, replacement in (
            ("ARTIFACT_ROLES", tuple(list(policy.ARTIFACT_ROLES))),
            ("REVOCATION_ROLES", tuple(list(policy.REVOCATION_ROLES))),
            ("ROLE_TTLS", dict(policy.ROLE_TTLS)),
            ("MappingProxyType", dict),
            ("hashlib", object()),
            ("json", object()),
            ("re", object()),
            ("TrustedTimePolicyRefused", Exception),
            ("__all__", tuple(list(policy.__all__))),
        ):
            original = getattr(policy, name)
            try:
                setattr(policy, name, replacement)
                with self.assertRaises(refusal):
                    policy.evaluate(**inputs())
            finally:
                setattr(policy, name, original)
        for owner, name in (
            (policy.hashlib, "sha256"),
            (policy.json, "dumps"),
            (policy.re, "fullmatch"),
        ):
            original = getattr(owner, name)
            calls = []

            def replacement(*_args, **_kwargs):
                calls.append(True)
                return None

            try:
                setattr(owner, name, replacement)
                with self.assertRaises(refusal):
                    policy.evaluate(**inputs())
                self.assertEqual(calls, [])
            finally:
                setattr(owner, name, original)

        original_code = policy.re.fullmatch.__code__
        try:
            policy.re.fullmatch.__code__ = original_code.replace()
            with self.assertRaises(refusal):
                policy.evaluate(**inputs())
        finally:
            policy.re.fullmatch.__code__ = original_code

        original_kwdefaults = policy.json.dumps.__kwdefaults__
        original_items = dict(original_kwdefaults)

        class HostileEncoder:
            calls = 0

            def __init__(self, *_args, **_kwargs):
                type(self).calls += 1

        try:
            original_kwdefaults["cls"] = HostileEncoder
            with self.assertRaises(refusal):
                policy.evaluate(**inputs())
            self.assertEqual(HostileEncoder.calls, 0)
        finally:
            original_kwdefaults.clear()
            original_kwdefaults.update(original_items)

    def test_errors_are_redacted_and_baseexceptions_preserve_identity(self):
        with self.assertRaisesRegex(
            policy.TrustedTimePolicyRefused, "^trusted_time_policy$"
        ):
            policy.evaluate(**{**inputs(), "artifacts": (object(),)})
        invoke = next(
            cell.cell_contents for cell in policy.evaluate.__closure__
            if callable(cell.cell_contents)
            and getattr(cell.cell_contents, "__name__", "") == "invoke"
        )
        for primary in (KeyboardInterrupt("k"), SystemExit("s")):
            with self.assertRaises(type(primary)) as raised:
                invoke(lambda error=primary: (_ for _ in ()).throw(error))
            self.assertIs(raised.exception, primary)

    def test_no_clock_storage_or_authority_surface(self):
        text = (policy.__doc__ or "").lower()
        for phrase in ("supplied", "does not", "system clock", "structural"):
            self.assertIn(phrase, text)
        for forbidden in (
            "admit", "consume", "is_fresh", "load_high_water", "now",
            "save_high_water", "verify", "verify_signature",
        ):
            self.assertFalse(hasattr(policy, forbidden))


if __name__ == "__main__":
    unittest.main()
