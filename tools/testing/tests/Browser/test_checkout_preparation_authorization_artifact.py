import copy
import hashlib
import importlib.util
import json
from pathlib import Path
from types import MappingProxyType
import unittest


MODULE_PATH = Path(__file__).with_name(
    "checkout-preparation-authorization-artifact.py"
)
SPEC = importlib.util.spec_from_file_location(
    "checkout_preparation_authorization_artifact", MODULE_PATH
)
artifact = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(artifact)


STATIC_ROLES = (
    "asset-review",
    "release-source",
    "runtime-configuration-policy",
    "tool-runtime-closure",
    "vendor-build",
)


def canonical(value):
    return (json.dumps(value, sort_keys=True, separators=(",", ":"),
                       ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")


def fixture():
    static = [
        {
            "artifactDigest": f"{index + 1:x}" * 64,
            "artifactId": f"{role}-artifact",
            "generation": index + 1,
            "role": role,
        }
        for index, role in enumerate(STATIC_ROLES)
    ]
    snapshots = [
        {
            "authorityRole": role,
            "issuerId": f"{role}-issuer",
            "issuerKeyGeneration": index + 1,
            "issuedAt": "2026-09-07T04:00:00Z",
            "nextUpdate": "2026-09-08T04:00:00Z",
            "revocationTrustGeneration": 4,
            "snapshotDigest": f"{index + 6:x}" * 64,
            "snapshotGeneration": 20 + index,
        }
        for index, role in enumerate(STATIC_ROLES)
    ]
    return {
        "artifactId": "preparation-authorization-17",
        "destination": {
            "parentIdentity": {"fileId": "22", "volumeSerial": "11"},
            "parentPath": "d:/checkout-candidates",
            "preparationPolicyDigest": "b" * 64,
            "requestedGeneration": 17,
            "runPath": "d:/checkout-candidates/run-17",
            "securityDescriptorDigest": "c" * 64,
        },
        "expiresAt": "2026-09-07T04:10:00Z",
        "generation": 17,
        "issuedAt": "2026-09-07T04:00:00Z",
        "issuerId": "composition-admission-issuer",
        "replayId": "preparation-replay-17",
        "revocationSnapshots": snapshots,
        "role": "preparation-authorization",
        "staticArtifacts": static,
        "version": 1,
    }


class PreparationAuthorizationArtifactTests(unittest.TestCase):
    def decode(self, document=None):
        value = fixture() if document is None else document
        raw = canonical(value) if type(value) is dict else value
        return artifact.decode(raw)

    def assert_refused(self, document):
        with self.assertRaisesRegex(
            artifact.PreparationAuthorizationArtifactRefused,
            "^preparation_authorization_artifact$",
        ):
            self.decode(document)

    def test_known_vector_is_exact_immutable_and_structural_only(self):
        document = fixture()
        raw = canonical(document)
        result = self.decode(document)
        self.assertIs(type(result), type(MappingProxyType({})))
        self.assertIs(result["structuralOnly"], True)
        self.assertEqual(result["role"], "preparation-authorization")
        self.assertEqual(result["generation"], 17)
        self.assertEqual(len(result["staticArtifacts"]), 5)
        self.assertEqual(len(result["revocationSnapshots"]), 5)
        self.assertIs(type(result["staticArtifacts"]), tuple)
        self.assertIs(type(result["destination"]), tuple)
        self.assertEqual(result["artifactDigest"], hashlib.sha256(raw).hexdigest())
        with self.assertRaises(TypeError):
            result["accepted"] = True
        for field in ("accepted", "authenticated", "consumed", "current",
                      "finalAdmission", "fresh", "replayAccepted", "trusted"):
            self.assertNotIn(field, result)

    def test_top_schema_is_closed_and_excludes_downstream_or_private_fields(self):
        document = fixture()
        for field in tuple(document):
            changed = copy.deepcopy(document)
            del changed[field]
            with self.subTest(missing=field):
                self.assert_refused(changed)
        for forbidden in (
            "compositionAdmission", "finalManifest", "preparationAclEvidence",
            "publicCertificateEvidence", "tls", "tlsMaterialCapability",
        ):
            changed = copy.deepcopy(document)
            changed[forbidden] = "forbidden"
            with self.subTest(forbidden=forbidden):
                self.assert_refused(changed)

    def test_canonical_ascii_json_one_lf_duplicate_nonfinite_and_bound(self):
        document = fixture()
        raw = canonical(document)
        self.assertEqual(artifact.canonical_artifact(document), raw)
        invalid = (
            raw[:-1], raw + b"\n", json.dumps(document).encode("ascii"),
            b"\xef\xbb\xbf" + raw, "not-bytes", bytearray(raw),
            b'{"role":"preparation-authorization","role":"preparation-authorization"}\n',
            b'{"generation":NaN}\n',
            b"{" + b" " * (128 * 1024) + b"}\n",
        )
        for candidate in invalid:
            with self.subTest(candidate=repr(candidate)[:60]):
                self.assert_refused(candidate)

    def test_identity_generation_replay_and_role_are_strict(self):
        cases = {
            "role": ("composition-admission", True),
            "artifactId": ("", "../artifact", "A", True),
            "issuerId": ("", "../issuer", "A", True),
            "replayId": ("", "../replay", "A", True),
            "generation": (0, 1 << 63, True, 1.0, "17"),
        }
        for field, values in cases.items():
            for bad in values:
                changed = copy.deepcopy(fixture())
                changed[field] = bad
                with self.subTest(field=field, bad=bad):
                    self.assert_refused(changed)

    def test_lifetime_is_positive_canonical_utc_and_at_most_ten_minutes(self):
        for expires in (
            "2026-09-07T04:00:00Z", "2026-09-07T03:59:59Z",
            "2026-09-07T04:10:01Z", "2026-09-07T04:10:00+00:00",
            "2026-02-29T04:10:00Z",
        ):
            changed = copy.deepcopy(fixture())
            changed["expiresAt"] = expires
            with self.subTest(expires=expires):
                self.assert_refused(changed)
        changed = copy.deepcopy(fixture())
        changed["issuedAt"] = "2026-09-07T04:00:00.000Z"
        self.assert_refused(changed)

    def test_static_artifact_set_is_exact_ordered_and_unique(self):
        self.assertEqual(
            tuple(item[0] for item in self.decode()["staticArtifacts"]),
            STATIC_ROLES,
        )
        cases = []
        missing = fixture(); missing["staticArtifacts"].pop(); cases.append(missing)
        extra = fixture(); extra["staticArtifacts"].append(copy.deepcopy(extra["staticArtifacts"][0])); cases.append(extra)
        swapped = fixture(); swapped["staticArtifacts"][0], swapped["staticArtifacts"][1] = swapped["staticArtifacts"][1], swapped["staticArtifacts"][0]; cases.append(swapped)
        duplicate_digest = fixture(); duplicate_digest["staticArtifacts"][1]["artifactDigest"] = duplicate_digest["staticArtifacts"][0]["artifactDigest"]; cases.append(duplicate_digest)
        for changed in cases:
            self.assert_refused(changed)

    def test_static_artifact_entries_are_closed_and_strict(self):
        for field, bad in (
            ("artifactDigest", "A" * 64), ("artifactDigest", "a" * 63),
            ("artifactId", "../id"), ("generation", True), ("generation", 0),
        ):
            changed = copy.deepcopy(fixture())
            changed["staticArtifacts"][0][field] = bad
            with self.subTest(field=field, bad=bad):
                self.assert_refused(changed)
        changed = copy.deepcopy(fixture())
        changed["staticArtifacts"][0]["signature"] = "0" * 128
        self.assert_refused(changed)

    def test_revocation_snapshot_references_are_exact_ordered_and_namespaced(self):
        result = self.decode()
        self.assertEqual(
            tuple(item[0] for item in result["revocationSnapshots"]),
            STATIC_ROLES,
        )
        for mutation in ("missing", "extra", "swapped"):
            changed = copy.deepcopy(fixture())
            refs = changed["revocationSnapshots"]
            if mutation == "missing": refs.pop()
            elif mutation == "extra": refs.append(copy.deepcopy(refs[0]))
            else: refs[0], refs[1] = refs[1], refs[0]
            with self.subTest(mutation=mutation):
                self.assert_refused(changed)

    def test_revocation_references_bind_digest_generations_times_and_namespace(self):
        cases = (
            ("snapshotDigest", "A" * 64), ("snapshotGeneration", True),
            ("snapshotGeneration", 0), ("issuerId", "../issuer"),
            ("issuerKeyGeneration", 0), ("revocationTrustGeneration", True),
            ("issuedAt", "2026-09-07T04:00:00+00:00"),
            ("nextUpdate", "2026-09-07T04:00:00Z"),
        )
        for field, bad in cases:
            changed = copy.deepcopy(fixture())
            changed["revocationSnapshots"][0][field] = bad
            with self.subTest(field=field, bad=bad):
                self.assert_refused(changed)
        changed = copy.deepcopy(fixture())
        changed["revocationSnapshots"][0]["artifactRevoked"] = False
        self.assert_refused(changed)

    def test_destination_is_closed_canonical_direct_child_and_identity_bounded(self):
        destination = fixture()["destination"]
        self.assertEqual(self.decode()["destination"][0], destination["parentPath"])
        cases = (
            ("parentPath", "D:\\checkout-candidates"),
            ("parentPath", "d:/checkout-candidates/.."),
            ("runPath", "d:/other/run-17"),
            ("runPath", "d:/checkout-candidates/run-17/child"),
            ("securityDescriptorDigest", "A" * 64),
            ("preparationPolicyDigest", "b" * 63),
            ("requestedGeneration", True),
        )
        for field, bad in cases:
            changed = copy.deepcopy(fixture())
            changed["destination"][field] = bad
            with self.subTest(field=field, bad=bad):
                self.assert_refused(changed)
        for field, bad in (("fileId", "01"), ("volumeSerial", True),
                           ("fileId", str(1 << 128))):
            changed = copy.deepcopy(fixture())
            changed["destination"]["parentIdentity"][field] = bad
            self.assert_refused(changed)

    def test_destination_rejects_device_alias_component_and_depth_overflow(self):
        long_component = "a" * 300
        sixty_five = "/".join(f"p{index}" for index in range(65))
        sixty_four = "/".join(f"p{index}" for index in range(64))
        cases = (
            ("d:/CON .txt", "d:/CON .txt/run-17"),
            (f"d:/{long_component}", f"d:/{long_component}/run-17"),
            (f"d:/{sixty_five}", f"d:/{sixty_five}/run-17"),
            (f"d:/{sixty_four}", f"d:/{sixty_four}/run-17"),
        )
        for parent_path, run_path in cases:
            changed = copy.deepcopy(fixture())
            changed["destination"]["parentPath"] = parent_path
            changed["destination"]["runPath"] = run_path
            with self.subTest(parent=parent_path[-40:], run=run_path[-40:]):
                self.assert_refused(changed)

    def test_requested_generation_is_independent_and_exactly_bound(self):
        changed = copy.deepcopy(fixture())
        changed["destination"]["requestedGeneration"] = 18
        result = self.decode(changed)
        self.assertEqual(result["generation"], 17)
        self.assertEqual(result["destination"][-1], 18)

    def test_module_and_dependency_mutation_fail_closed(self):
        raw = canonical(fixture())
        original_refusal = artifact.PreparationAuthorizationArtifactRefused
        for owner, name, replacement in (
            (artifact, "MAX_ARTIFACT_BYTES", 128 * 1024 + 1),
            (artifact, "MAX_PATH_DEPTH", artifact.MAX_PATH_DEPTH + 1),
            (artifact, "MAX_COMPONENT_BYTES", artifact.MAX_COMPONENT_BYTES + 1),
            (artifact, "STATIC_ROLES", tuple(list(artifact.STATIC_ROLES))),
            (artifact, "ROLE", "composition-admission"),
            (artifact, "MappingProxyType", dict),
            (artifact, "json", object()), (artifact, "hashlib", object()),
            (artifact, "re", object()), (artifact, "datetime", object()),
            (artifact, "PreparationAuthorizationArtifactRefused", Exception),
            (artifact, "__all__", tuple(list(artifact.__all__))),
        ):
            original = getattr(owner, name)
            try:
                setattr(owner, name, replacement)
                with self.assertRaises(original_refusal):
                    artifact.decode(raw)
            finally:
                setattr(owner, name, original)

    def test_public_surface_has_no_consumer_filesystem_or_admission_api(self):
        self.assertEqual(artifact.__all__, (
            "PreparationAuthorizationArtifactRefused", "canonical_artifact", "decode",
        ))
        for forbidden in ("accept", "admit", "apply_acl", "consume", "create",
                          "is_current", "mark_replay", "verify", "verify_signature"):
            self.assertFalse(hasattr(artifact, forbidden))


if __name__ == "__main__":
    unittest.main()
