import copy
import hashlib
import importlib.util
import json
from pathlib import Path
from types import MappingProxyType
import unittest


MODULE_PATH = Path(__file__).with_name("checkout-composition-admission-artifact.py")
SPEC = importlib.util.spec_from_file_location(
    "checkout_composition_admission_artifact", MODULE_PATH
)
artifact = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(artifact)


ROLE = "composition-admission"
MAX_ARTIFACT_BYTES = 64 * 1024
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
BINDING_KEYS = (
    "assetReviewArtifactDigest",
    "finalConfigBinding",
    "finalManifestDigest",
    "preparationAclEvidenceDigest",
    "preparationAuthorizationArtifactDigest",
    "publicTlsCertificateEvidenceDigest",
    "releaseSourceArtifactDigest",
    "runtimeConfigurationPolicyArtifactDigest",
    "toolRuntimeClosureArtifactDigest",
    "vendorArtifactDigest",
)
RESULT_FIELDS = (
    "structuralOnly",
    "role",
    "artifactId",
    "issuerId",
    "generation",
    "requestedGeneration",
    "issuedAt",
    "expiresAt",
    "replayId",
    "oneShot",
    "bindingDigest",
    "revocationSetDigest",
    "revocationSnapshotCount",
    "artifactDigest",
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
        "artifactId": "composition-20260907.1",
        "bindings": {
            key: format(index, "064x")
            for index, key in enumerate(BINDING_KEYS, start=1)
        },
        "destinationIdentity": {
            "fileId": "100",
            "path": "C:/oncam/candidates",
            "volumeSerial": "77",
        },
        "expiresAt": "2026-09-07T02:08:04Z",
        "generation": 12,
        "issuedAt": "2026-09-07T02:03:04Z",
        "issuerId": "composition-custodian-01",
        "lease": {
            "fileId": "102",
            "generation": 8,
            "path": "C:/oncam/candidates/oncam-checkout-a/.checkout-coordinator.lease",
            "volumeSerial": "77",
        },
        "oneShot": True,
        "requestedGeneration": 41,
        "replayId": "composition-20260907.1-attempt-01",
        "revocationSnapshots": [
            {
                "authorityRole": role,
                "issuedAt": "2026-09-07T00:00:00Z",
                "issuerId": f"{role}-issuer",
                "issuerKeyGeneration": 3,
                "nextUpdate": "2026-09-08T00:00:00Z",
                "revocationTrustGeneration": 5,
                "snapshotDigest": format(100 + index, "064x"),
                "snapshotGeneration": index,
            }
            for index, role in enumerate(REVOCATION_ROLES, start=1)
        ],
        "role": ROLE,
        "runIdentity": {
            "fileId": "101",
            "path": "C:/oncam/candidates/oncam-checkout-a",
            "volumeSerial": "77",
        },
        "version": 1,
    }


class CompositionAdmissionArtifactTests(unittest.TestCase):
    def assert_refused(self, value):
        raw = canonical(value) if type(value) is dict else value
        with self.assertRaisesRegex(
            artifact.CompositionAdmissionArtifactRefused,
            "^composition_admission_artifact$",
        ):
            artifact.decode(raw)

    def test_known_vector_is_canonical_immutable_narrow_and_structural_only(self):
        value = fixture()
        raw = canonical(value)
        result = artifact.decode(raw)

        self.assertEqual(artifact.canonical_artifact(value), raw)
        self.assertIs(type(result), MappingProxyType)
        self.assertEqual(tuple(result), RESULT_FIELDS)
        self.assertIs(result["structuralOnly"], True)
        self.assertIs(result["oneShot"], True)
        self.assertEqual(result["requestedGeneration"], 41)
        self.assertEqual(result["revocationSnapshotCount"], len(REVOCATION_ROLES))
        self.assertEqual(
            result["artifactDigest"],
            "4541f3a584fa9590c688935da979216f4d3647ce08cad89e528340fdbf4bd05e",
        )
        with self.assertRaises(TypeError):
            result["consumed"] = True
        for forbidden in (
            "admitted",
            "authenticated",
            "consumed",
            "privateKey",
            "tlsMaterialCapability",
            "trusted",
        ):
            self.assertNotIn(forbidden, result)

    def test_json_schema_and_encoding_are_exact_closed_and_bounded(self):
        value = fixture()
        raw = canonical(value)
        for key in tuple(value):
            changed = copy.deepcopy(value)
            del changed[key]
            with self.subTest(missing=key):
                self.assert_refused(changed)
        for extra in (
            "compositionAdmissionDigest",
            "downstreamArtifactDigest",
            "privateKeyHash",
            "privateKeyMetadata",
            "privateKeyPath",
            "selfDigest",
            "tlsMaterialCapability",
        ):
            changed = copy.deepcopy(value)
            changed[extra] = "forbidden"
            with self.subTest(extra=extra):
                self.assert_refused(changed)
        for candidate in (
            raw[:-1],
            raw + b"\n",
            b"\xef\xbb\xbf" + raw,
            json.dumps(value).encode("ascii"),
            b'{"version":1,"version":1}\n',
            b'{"generation":NaN}\n',
            b"[]\n",
            b"null\n",
            "not-bytes",
            bytearray(raw),
            b"{" + b" " * MAX_ARTIFACT_BYTES + b"}\n",
        ):
            with self.subTest(candidate=repr(candidate)[:48]):
                self.assert_refused(candidate)

    def test_identity_generation_one_shot_and_lifetime_are_structural_only(self):
        for key, bad in (
            ("version", 2),
            ("version", True),
            ("role", "preparation-authorization"),
            ("role", True),
            ("artifactId", "Artifact"),
            ("issuerId", "../issuer"),
            ("replayId", "replay id"),
            ("generation", 0),
            ("generation", True),
            ("generation", 1 << 63),
            ("requestedGeneration", 0),
            ("requestedGeneration", True),
            ("requestedGeneration", 1 << 63),
            ("oneShot", False),
            ("oneShot", 1),
        ):
            changed = copy.deepcopy(fixture())
            changed[key] = bad
            with self.subTest(key=key, bad=bad):
                self.assert_refused(changed)
        for issued, expires in (
            ("2026-09-07T02:03:04Z", "2026-09-07T02:03:04Z"),
            ("2026-09-07T02:03:04Z", "2026-09-07T02:08:05Z"),
            ("2026-09-07T02:03:04+00:00", "2026-09-07T02:08:04Z"),
            ("2026-02-29T00:00:00Z", "2026-03-01T00:01:00Z"),
        ):
            changed = copy.deepcopy(fixture())
            changed["issuedAt"] = issued
            changed["expiresAt"] = expires
            with self.subTest(issued=issued, expires=expires):
                self.assert_refused(changed)

    def test_all_ten_cross_binding_digests_are_required_exact_and_sensitive(self):
        value = fixture()
        original = artifact.decode(canonical(value))
        for key in BINDING_KEYS:
            missing = copy.deepcopy(value)
            del missing["bindings"][key]
            self.assert_refused(missing)
            changed = copy.deepcopy(value)
            changed["bindings"][key] = "f" * 64
            revised = artifact.decode(canonical(changed))
            with self.subTest(binding=key):
                self.assertNotEqual(original["bindingDigest"], revised["bindingDigest"])
                self.assertNotEqual(original["artifactDigest"], revised["artifactDigest"])
        for bad in ("A" * 64, "0" * 63, "g" * 64, True, None):
            changed = copy.deepcopy(value)
            changed["bindings"][BINDING_KEYS[0]] = bad
            self.assert_refused(changed)
        changed = copy.deepcopy(value)
        changed["bindings"]["trustRootBundleDigest"] = "a" * 64
        self.assert_refused(changed)
        changed = copy.deepcopy(value)
        changed["bindings"][BINDING_KEYS[1]] = changed["bindings"][BINDING_KEYS[0]]
        self.assert_refused(changed)

    def test_requested_generation_is_a_distinct_final_lifecycle_binding(self):
        original = artifact.decode(canonical(fixture()))
        missing = fixture()
        del missing["requestedGeneration"]
        self.assert_refused(missing)
        changed = fixture()
        changed["requestedGeneration"] += 1
        revised = artifact.decode(canonical(changed))
        self.assertNotEqual(original["bindingDigest"], revised["bindingDigest"])
        self.assertNotEqual(original["artifactDigest"], revised["artifactDigest"])

    def test_destination_run_and_lease_are_canonical_related_and_collision_safe(self):
        value = fixture()
        for section in ("destinationIdentity", "runIdentity"):
            for key in ("path", "volumeSerial", "fileId"):
                changed = copy.deepcopy(value)
                del changed[section][key]
                self.assert_refused(changed)
        for key in ("path", "volumeSerial", "fileId", "generation"):
            changed = copy.deepcopy(value)
            del changed["lease"][key]
            self.assert_refused(changed)
        mutations = (
            ("destinationIdentity", "path", "c:/oncam/candidates"),
            ("runIdentity", "path", "C:/other/oncam-checkout-a"),
            ("lease", "path", "C:/oncam/candidates/oncam-checkout-a/other.lease"),
            ("lease", "generation", 0),
            ("lease", "generation", True),
            ("runIdentity", "fileId", "0"),
            ("runIdentity", "fileId", "01"),
            ("runIdentity", "volumeSerial", 77),
        )
        for section, key, bad in mutations:
            changed = copy.deepcopy(value)
            changed[section][key] = bad
            with self.subTest(section=section, key=key, bad=bad):
                self.assert_refused(changed)
        for left, right in (
            ("destinationIdentity", "runIdentity"),
            ("destinationIdentity", "lease"),
            ("runIdentity", "lease"),
        ):
            changed = copy.deepcopy(value)
            changed[right]["volumeSerial"] = changed[left]["volumeSerial"]
            changed[right]["fileId"] = changed[left]["fileId"]
            self.assert_refused(changed)

    def test_revocation_snapshot_set_is_exact_sorted_unique_and_structural(self):
        value = fixture()
        for snapshots in (
            [],
            value["revocationSnapshots"][:-1],
            list(reversed(value["revocationSnapshots"])),
            value["revocationSnapshots"] + [copy.deepcopy(value["revocationSnapshots"][0])],
        ):
            changed = copy.deepcopy(value)
            changed["revocationSnapshots"] = snapshots
            self.assert_refused(changed)
        for index, role in enumerate(REVOCATION_ROLES):
            changed = copy.deepcopy(value)
            changed["revocationSnapshots"][index]["authorityRole"] = role.upper()
            self.assert_refused(changed)
        # These are final-lifecycle authority namespaces, not I1 envelope roles.
        for required in ("preparation-acl", "tls-material"):
            changed = copy.deepcopy(value)
            changed["revocationSnapshots"] = [
                item for item in changed["revocationSnapshots"]
                if item["authorityRole"] != required
            ]
            with self.subTest(missing_namespace=required):
                self.assert_refused(changed)

    def test_revocation_snapshot_entry_schema_types_time_and_digest_are_exact(self):
        value = fixture()
        entry_keys = tuple(value["revocationSnapshots"][0])
        for key in entry_keys:
            changed = copy.deepcopy(value)
            del changed["revocationSnapshots"][0][key]
            self.assert_refused(changed)
        changed = copy.deepcopy(value)
        changed["revocationSnapshots"][0]["current"] = True
        self.assert_refused(changed)
        changed = copy.deepcopy(value)
        changed["revocationSnapshots"][1]["snapshotDigest"] = \
            changed["revocationSnapshots"][0]["snapshotDigest"]
        self.assert_refused(changed)
        for key, bad in (
            ("issuerId", "Issuer"),
            ("issuerKeyGeneration", True),
            ("issuerKeyGeneration", 0),
            ("revocationTrustGeneration", 0),
            ("snapshotDigest", "A" * 64),
            ("issuedAt", "2026-09-07T00:00:00+00:00"),
            ("nextUpdate", "2026-09-07T00:00:00Z"),
            ("nextUpdate", "2026-09-08T00:00:01Z"),
        ):
            changed = copy.deepcopy(value)
            changed["revocationSnapshots"][0][key] = bad
            with self.subTest(key=key, bad=bad):
                self.assert_refused(changed)
        for index in range(len(REVOCATION_ROLES)):
            for bad in (0, True, 1 << 63):
                changed = copy.deepcopy(value)
                changed["revocationSnapshots"][index]["snapshotGeneration"] = bad
                with self.subTest(snapshot=index, snapshotGeneration=bad):
                    self.assert_refused(changed)

    def test_revocation_and_binding_changes_change_derived_digests(self):
        original = artifact.decode(canonical(fixture()))
        changed = fixture()
        changed["revocationSnapshots"][0]["snapshotGeneration"] += 1
        revised = artifact.decode(canonical(changed))
        self.assertNotEqual(
            original["revocationSetDigest"], revised["revocationSetDigest"]
        )
        self.assertNotEqual(original["artifactDigest"], revised["artifactDigest"])

    def test_dependency_and_authority_rebinding_refuse_before_execution(self):
        raw = canonical(fixture())
        refusal = artifact.CompositionAdmissionArtifactRefused
        replacements = (
            (artifact, "ROLE", "release-source"),
            (artifact, "BINDING_KEYS", tuple(list(artifact.BINDING_KEYS))),
            (artifact, "REVOCATION_ROLES", tuple(list(artifact.REVOCATION_ROLES))),
            (artifact, "MAX_ARTIFACT_BYTES", MAX_ARTIFACT_BYTES + 1),
            (artifact, "CompositionAdmissionArtifactRefused", Exception),
            (artifact, "MappingProxyType", dict),
            (artifact, "json", object()),
            (artifact, "hashlib", object()),
            (artifact, "re", object()),
            (artifact, "__all__", tuple(list(artifact.__all__))),
        )
        for owner, name, replacement in replacements:
            original = getattr(owner, name)
            try:
                setattr(owner, name, replacement)
                with self.assertRaises(refusal):
                    artifact.decode(raw)
            finally:
                setattr(owner, name, original)
        for owner, name in (
            (artifact.json, "loads"),
            (artifact.json, "dumps"),
            (artifact.hashlib, "sha256"),
            (artifact.re, "fullmatch"),
        ):
            original = getattr(owner, name)
            calls = []

            def replacement(*_args, **_kwargs):
                calls.append(True)
                return {}

            try:
                setattr(owner, name, replacement)
                with self.assertRaises(refusal):
                    artifact.decode(raw)
                self.assertEqual(calls, [])
            finally:
                setattr(owner, name, original)

        original_code = artifact.json.loads.__code__
        try:
            artifact.json.loads.__code__ = original_code.replace()
            with self.assertRaises(refusal):
                artifact.decode(raw)
        finally:
            artifact.json.loads.__code__ = original_code

        original_kwdefaults = artifact.json.loads.__kwdefaults__
        original_items = dict(original_kwdefaults)

        class HostileDecoder:
            calls = 0

            def __init__(self, *_args, **_kwargs):
                type(self).calls += 1

        try:
            original_kwdefaults["cls"] = HostileDecoder
            with self.assertRaises(refusal):
                artifact.decode(raw)
            self.assertEqual(HostileDecoder.calls, 0)
        finally:
            original_kwdefaults.clear()
            original_kwdefaults.update(original_items)

    def test_baseexception_identity_survives_postcheck_drift(self):
        invoke = next(
            cell.cell_contents
            for cell in artifact.decode.__closure__
            if callable(cell.cell_contents)
            and getattr(cell.cell_contents, "__name__", "") == "invoke"
        )
        original = artifact.BINDING_KEYS
        replacement = tuple(list(original))
        for primary in (KeyboardInterrupt("k"), SystemExit("s")):
            def drift_then_stop(error=primary):
                artifact.BINDING_KEYS = replacement
                raise error

            try:
                with self.assertRaises(type(primary)) as raised:
                    invoke(drift_then_stop)
                self.assertIs(raised.exception, primary)
            finally:
                artifact.BINDING_KEYS = original

    def test_public_surface_and_non_authority_scope_are_exact(self):
        self.assertEqual(
            artifact.__all__,
            ("CompositionAdmissionArtifactRefused", "canonical_artifact", "decode"),
        )
        text = (artifact.__doc__ or "").lower()
        for phrase in ("structural", "does not", "one-shot", "composition"):
            self.assertIn(phrase, text)
        for forbidden in (
            "admit",
            "consume",
            "load_high_water",
            "open_tls_capability",
            "verify",
            "verify_signature",
        ):
            self.assertFalse(hasattr(artifact, forbidden))


if __name__ == "__main__":
    unittest.main()
