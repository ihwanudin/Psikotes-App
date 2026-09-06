"""Pure contract tests for checkout ACL attestation request/evidence bytes."""

from __future__ import annotations

import copy
import importlib.util
import json
from pathlib import Path
import unittest


HERE = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location(
    "checkout_acl_attestation_tested", HERE / "checkout-acl-attestation.py"
)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("attestation codec unavailable")
module = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(module)


class CheckoutAclAttestationTests(unittest.TestCase):
    digest = "a" * 64
    session = "checkout-" + "b" * 32

    def identity(self, volume="1", file_id="2"):
        return {"volumeSerial": volume, "fileId": file_id}

    def request(self):
        request = {
            "version": 1,
            "boundary": "anchor",
            "phase": "fresh",
            "session": self.session,
            "configBinding": "c" * 64,
            "leaseBinding": "",
            "leaseIdentity": {
                "path": "c:/candidate/.checkout-coordinator.lease",
                "coordinator": self.identity("50", "60"),
                "descriptor": self.identity("10", "20"),
                "run": self.identity("30", "40"),
            },
            "policyDigest": module.POLICY_DIGEST,
            "challenge": "e" * 64,
            "targets": [
                {"role": "coordinator", "path": "c:/coordinator"},
                {"role": "run", "path": "c:/candidate"},
                {"role": "source", "path": "c:/candidate/source"},
            ],
        }
        request["leaseBinding"] = module.run_binding(request["targets"][1]["path"])
        return request

    def evidence(self, request=None):
        request = request or self.request()
        identities = (self.identity("50", "60"), request["leaseIdentity"]["run"],
                      self.identity("70", "80"))
        targets = []
        for target, identity in zip(request["targets"], identities, strict=True):
            targets.append({
                "role": target["role"],
                "path": target["path"],
                "volumeSerial": identity["volumeSerial"],
                "fileId": identity["fileId"],
                "ownerSid": "S-1-5-21-100-200-300-400",
                "daclDigest": "f" * 64,
                "reparse": False,
                "policySatisfied": True,
            })
        return {
            "version": 1,
            "requestDigest": module.request_digest(request),
            "policyDigest": module.POLICY_DIGEST,
            "leaseDigest": module.lease_digest(
                request["leaseBinding"], request["leaseIdentity"],
            ),
            "targets": targets,
        }

    def assert_refused(self, code, operation):
        with self.assertRaisesRegex(module.AttestationRefused, f"^{code}$") as caught:
            operation()
        self.assertNotIn("PRIVATE", str(caught.exception))

    def test_request_round_trip_is_canonical_ascii_and_digest_exact(self):
        request = self.request()
        raw = module.canonical_request(request)
        self.assertTrue(raw.endswith(b"\n"))
        self.assertTrue(raw.isascii())
        self.assertLessEqual(len(raw), module.MAX_REQUEST_BYTES)
        self.assertEqual(module.decode_request(raw), request)
        self.assertEqual(module.request_digest(request), module.sha256_bytes(raw))
        self.assertEqual(
            len(module.lease_digest(request["leaseBinding"], request["leaseIdentity"])), 64,
        )

    def test_request_rejects_schema_type_enum_digest_identity_and_target_errors(self):
        valid = self.request()
        class StringSubclass(str):
            pass

        cases = []
        for key in valid:
            changed = copy.deepcopy(valid)
            changed.pop(key)
            cases.append(changed)
        cases.extend([
            {**valid, "extra": "PRIVATE"},
            {**valid, "version": True},
            {**valid, "boundary": "other"},
            {**valid, "boundary": StringSubclass("anchor")},
            {**valid, "phase": "other"},
            {**valid, "policyDigest": StringSubclass(module.POLICY_DIGEST)},
            {**valid, "session": "checkout-" + "A" * 32},
            {**valid, "configBinding": "A" * 64},
            {**valid, "leaseBinding": "0" * 63},
            {**valid, "leaseBinding": "d" * 64},
            {**valid, "policyDigest": "0" * 64},
            {**valid, "challenge": "0" * 65},
            {**valid, "targets": list(reversed(valid["targets"]))},
            {**valid, "targets": [valid["targets"][0]] * 3},
        ])
        invalid_path = copy.deepcopy(valid)
        invalid_path["targets"][1]["path"] = "c:/candidate/../other"
        cases.append(invalid_path)
        invalid_identity = copy.deepcopy(valid)
        invalid_identity["leaseIdentity"]["run"]["fileId"] = "01"
        cases.append(invalid_identity)
        invalid_role = copy.deepcopy(valid)
        invalid_role["targets"][0]["role"] = StringSubclass("coordinator")
        cases.append(invalid_role)
        for request in cases:
            with self.subTest(request=request):
                self.assert_refused("attestation_request", lambda request=request:
                                    module.canonical_request(request))

    def test_request_rejects_noncanonical_windows_path_components(self):
        paths = (
            "c:/candidate/file:stream",
            "c:/candidate/bad\x01name",
            "c:/candidate/trailing.",
            "c:/candidate/trailing ",
            "c:/candidate/CON",
            "c:/candidate/con.txt",
            "c:/candidate/AuX.log",
            "c:/candidate/COM1.json",
            "c:/candidate/lpt9",
            "c:/candidate/NUL.anything",
        )
        for path in paths:
            request = self.request()
            request["targets"][0]["path"] = path
            with self.subTest(path=path):
                self.assert_refused(
                    "attestation_request", lambda request=request:
                    module.canonical_request(request),
                )

    def test_request_decoder_rejects_noncanonical_duplicate_nonfinite_and_size(self):
        canonical = module.canonical_request(self.request())
        decoded = json.loads(canonical)
        noncanonical = json.dumps(decoded, indent=2).encode("ascii") + b"\n"
        duplicate = canonical.replace(b'{"boundary"', b'{"boundary":"anchor","boundary"', 1)
        nonfinite = canonical.replace(b'"version":1', b'"version":NaN', 1)
        for raw, code in (
            (canonical[:-1], "attestation_request_encoding"),
            (noncanonical, "attestation_request_encoding"),
            (duplicate, "attestation_request_encoding"),
            (nonfinite, "attestation_request_encoding"),
            (b"x" * (module.MAX_REQUEST_BYTES + 1), "attestation_request_size"),
        ):
            with self.subTest(code=code):
                self.assert_refused(code, lambda raw=raw: module.decode_request(raw))

    def test_evidence_round_trip_binds_exact_request_policy_lease_and_targets(self):
        request = self.request()
        evidence = self.evidence(request)
        raw = module.canonical_evidence(evidence, request)
        self.assertTrue(raw.endswith(b"\n"))
        self.assertTrue(raw.isascii())
        self.assertLessEqual(len(raw), module.MAX_EVIDENCE_BYTES)
        self.assertEqual(module.decode_evidence(raw, request), evidence)

        changed = copy.deepcopy(request)
        changed["challenge"] = "9" * 64
        self.assert_refused(
            "attestation_evidence_binding",
            lambda: module.canonical_evidence(evidence, changed),
        )

    def test_evidence_rejects_schema_types_order_sid_and_noncanonical_identity(self):
        request = self.request()
        valid = self.evidence(request)
        class StringSubclass(str):
            pass

        cases = []
        for key in valid:
            changed = copy.deepcopy(valid)
            changed.pop(key)
            cases.append(changed)
        cases.extend([
            {**valid, "extra": "PRIVATE"},
            {**valid, "version": True},
            {**valid, "requestDigest": "A" * 64},
            {**valid, "leaseDigest": None},
            {**valid, "targets": list(reversed(valid["targets"]))},
        ])
        for field, value in (
            ("ownerSid", "s-1-5-18"),
            ("role", StringSubclass("coordinator")),
            ("ownerSid", "S-01-5-18"),
            ("volumeSerial", "01"),
            ("fileId", 2),
            ("daclDigest", "F" * 64),
            ("reparse", 0),
            ("policySatisfied", 1),
        ):
            changed = copy.deepcopy(valid)
            changed["targets"][0][field] = value
            cases.append(changed)
        path_subclass = copy.deepcopy(valid)
        path_subclass["targets"][0]["path"] = StringSubclass("c:/coordinator")
        cases.append(path_subclass)
        for evidence in cases:
            with self.subTest(evidence=evidence):
                self.assert_refused("attestation_evidence", lambda evidence=evidence:
                                    module.canonical_evidence(evidence, request))

    def test_evidence_run_identity_must_equal_current_lease_run_identity(self):
        request = self.request()
        evidence = self.evidence(request)
        evidence["targets"][1]["fileId"] = "999"
        self.assert_refused(
            "attestation_evidence_binding",
            lambda: module.canonical_evidence(evidence, request),
        )

    def test_evidence_coordinator_identity_must_equal_current_lease_coordinator_identity(self):
        request = self.request()
        evidence = self.evidence(request)
        evidence["targets"][0]["volumeSerial"] = "999"
        self.assert_refused(
            "attestation_evidence_binding",
            lambda: module.canonical_evidence(evidence, request),
        )

    def test_evidence_decoder_rejects_noncanonical_duplicate_nonfinite_and_size(self):
        request = self.request()
        canonical = module.canonical_evidence(self.evidence(request), request)
        decoded = json.loads(canonical)
        noncanonical = json.dumps(decoded, indent=2).encode("ascii") + b"\n"
        duplicate = canonical.replace(b'{"leaseDigest"',
                                      b'{"leaseDigest":"' + b"0" * 64 + b'","leaseDigest"', 1)
        nonfinite = canonical.replace(b'"version":1', b'"version":Infinity', 1)
        for raw, code in (
            (canonical[:-1], "attestation_evidence_encoding"),
            (noncanonical, "attestation_evidence_encoding"),
            (duplicate, "attestation_evidence_encoding"),
            (nonfinite, "attestation_evidence_encoding"),
            (b"x" * (module.MAX_EVIDENCE_BYTES + 1), "attestation_evidence_size"),
        ):
            with self.subTest(code=code):
                self.assert_refused(code, lambda raw=raw: module.decode_evidence(raw, request))

    def test_second_boundary_requires_distinct_challenge_but_same_source_identity(self):
        anchor_request = self.request()
        anchor_evidence = self.evidence(anchor_request)
        execution_request = copy.deepcopy(anchor_request)
        execution_request["boundary"] = "execution"
        execution_request["challenge"] = "9" * 64
        execution_evidence = self.evidence(execution_request)
        module.validate_boundary_pair(
            anchor_request, anchor_evidence, execution_request, execution_evidence,
        )

        repeated = copy.deepcopy(execution_request)
        repeated["challenge"] = anchor_request["challenge"]
        self.assert_refused(
            "attestation_boundary",
            lambda: module.validate_boundary_pair(
                anchor_request, anchor_evidence, repeated, self.evidence(repeated),
            ),
        )
        changed_source = copy.deepcopy(execution_evidence)
        changed_source["targets"][2]["fileId"] = "999"
        self.assert_refused(
            "attestation_boundary",
            lambda: module.validate_boundary_pair(
                anchor_request, anchor_evidence, execution_request, changed_source,
            ),
        )


if __name__ == "__main__":
    unittest.main()
