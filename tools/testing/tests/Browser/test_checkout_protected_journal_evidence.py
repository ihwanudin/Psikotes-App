import copy
import hashlib
import importlib.util
import json
from pathlib import Path
from types import MappingProxyType
import unittest


MODULE_PATH = Path(__file__).with_name("checkout-protected-journal-evidence.py")
SPEC = importlib.util.spec_from_file_location("checkout_protected_journal_evidence", MODULE_PATH)
codec = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(codec)

MAX_EVIDENCE_BYTES = 32 * 1024
ZERO_DIGEST = "0" * 64
RESULT_FIELDS = (
    "evidenceStructuralOnly", "status", "journalRequestDigest", "journalKind",
    "namespace", "beforeGeneration", "afterGeneration", "beforeStateDigest",
    "afterStateDigest", "previousStateDigest", "providerGeneration",
    "evidenceDigest",
)


def canonical(value):
    return (json.dumps(value, sort_keys=True, separators=(",", ":"),
                       ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")


def identity(path, file_id):
    return {"fileId": str(file_id), "path": path, "volumeSerial": "77"}


def path_digest(value):
    return hashlib.sha256(
        b"oncam.checkout.canonical-path.v1\0" + value.encode("ascii")
    ).hexdigest()


def fixture(kind="one-shot-ledger", current_generation=7, status="written-structural-evidence"):
    leaf = ".checkout-one-shot-ledger.journal" if kind == "one-shot-ledger" \
        else ".checkout-revocation-high-water.journal"
    current_digest = format(10, "064x") if current_generation else ZERO_DIGEST
    value = {
        "afterState": {
            "generation": current_generation + 1,
            "previousDigest": current_digest,
            "rawDigest": "3" * 64,
        },
        "beforeState": {"generation": current_generation, "rawDigest": current_digest},
        "journalIdentity": identity(f"C:/oncam/runs/oncam-checkout-a/{leaf}", 102),
        "journalKind": kind,
        "journalRequestDigest": "1" * 64,
        "namespace": "composition-admission" if kind == "one-shot-ledger"
            else "release-source.issuer-01.key-3.trust-5",
        "operation": "compare-and-swap",
        "policyDigest": "2" * 64,
        "providerAuthorityDigest": "4" * 64,
        "providerEvidenceDigest": "5" * 64,
        "providerGeneration": 9,
        "rebootState": "certain",
        "runIdentity": identity("C:/oncam/runs/oncam-checkout-a", 101),
        "securityDescriptorDigest": "6" * 64,
        "status": status,
        "version": 1,
    }
    if status == "refused":
        value["refusalCode"] = "journal_refused"
    else:
        value.update({
            "afterFileIdentity": copy.deepcopy(value["journalIdentity"]),
            "beforeFileIdentity": copy.deepcopy(value["journalIdentity"]),
            "descriptorStable": True,
            "disposition": "created-new" if current_generation == 0 else "opened-existing",
            "flushFileBuffers": True,
            "heldHandle": True,
            "tokenStable": True,
            "writeThrough": True,
        })
    paired_request(value)
    return value


def request_fixture(evidence=None):
    value = fixture() if evidence is None else evidence
    run_path = value["runIdentity"]["path"]
    return {
        "currentState": copy.deepcopy(value["beforeState"]),
        "expectedFileIdentity": copy.deepcopy(value["journalIdentity"]),
        "forbiddenPathDigests": sorted([
            path_digest(run_path + "/runtime.ini"),
            path_digest(run_path + "/supervisor-config.json"),
            path_digest(run_path + "/tls/server.key"),
        ]),
        "journalKind": value["journalKind"],
        "namespace": value["namespace"],
        "operation": value["operation"],
        "policyDigest": value["policyDigest"],
        "proposedState": copy.deepcopy(value["afterState"]),
        "providerAuthorityDigest": value["providerAuthorityDigest"],
        "providerEvidenceDigest": value["providerEvidenceDigest"],
        "providerGeneration": value["providerGeneration"],
        "rebootState": value["rebootState"],
        "runIdentity": copy.deepcopy(value["runIdentity"]),
        "securityDescriptorDigest": value["securityDescriptorDigest"],
        "version": 1,
    }


def paired_request(evidence):
    request = request_fixture(evidence)
    evidence["journalRequestDigest"] = hashlib.sha256(canonical(request)).hexdigest()
    return request


class ProtectedJournalEvidenceTests(unittest.TestCase):
    def decode(self, value, request=None):
        if request is None and type(value) is dict:
            try:
                request_value = request_fixture(value)
            except (KeyError, TypeError):
                request_value = request_fixture()
        else:
            request_value = request_fixture() if request is None else request
        request_raw = canonical(request_value) if type(request_value) is dict else request_value
        evidence_raw = canonical(value) if type(value) is dict else value
        return codec.decode(request_raw, evidence_raw)

    def assert_refused(self, value):
        with self.assertRaisesRegex(codec.ProtectedJournalEvidenceRefused,
                                    "^protected_journal_evidence$"):
            self.decode(value)

    def test_known_success_vector_is_canonical_immutable_and_structural_only(self):
        value = fixture(); raw = canonical(value); result = self.decode(value)
        self.assertEqual(codec.canonical_evidence(canonical(request_fixture(value)), value), raw)
        self.assertEqual(codec.__all__, (
            "ProtectedJournalEvidenceRefused", "canonical_evidence", "decode",
        ))
        self.assertIs(type(result), MappingProxyType)
        self.assertEqual(tuple(result), RESULT_FIELDS)
        self.assertIs(result["evidenceStructuralOnly"], True)
        self.assertEqual(result["status"], "written-structural-evidence")
        with self.assertRaises(TypeError): result["persisted"] = True
        for forbidden in ("accepted", "atomic", "persisted", "protected", "rollbackSafe"):
            self.assertNotIn(forbidden, result)

    def test_schema_encoding_size_and_types_are_exact(self):
        value = fixture(); raw = canonical(value)
        for key in tuple(value):
            changed = copy.deepcopy(value); del changed[key]; self.assert_refused(changed)
        changed = fixture(); changed["nativeError"] = 5; self.assert_refused(changed)
        for candidate in (
            raw[:-1], raw + b"\n", b"\xef\xbb\xbf" + raw,
            json.dumps(value).encode("ascii"), b'{"version":1,"version":1}\n',
            b'{"providerGeneration":NaN}\n', b"[]\n", "not-bytes", bytearray(raw),
            b"{" + b" " * MAX_EVIDENCE_BYTES + b"}\n",
        ):
            self.assert_refused(candidate)

    def test_refusal_has_fixed_code_and_no_success_fields(self):
        result = self.decode(fixture(status="refused"))
        self.assertEqual(result["status"], "refused")
        for key in ("disposition", "heldHandle", "writeThrough", "flushFileBuffers",
                    "descriptorStable", "tokenStable", "beforeFileIdentity",
                    "afterFileIdentity"):
            changed = fixture(status="refused"); changed[key] = True; self.assert_refused(changed)
        for bad in ("access_denied_5", "", True, None):
            changed = fixture(status="refused"); changed["refusalCode"] = bad
            self.assert_refused(changed)
        changed = fixture(); changed["refusalCode"] = "journal_refused"; self.assert_refused(changed)

    def test_kind_namespace_operation_and_reboot_are_exact(self):
        for kind in ("one-shot-ledger", "revocation-high-water"):
            self.assertEqual(self.decode(fixture(kind))["journalKind"], kind)
        for key, bad in (
            ("journalKind", "other"), ("journalKind", True),
            ("namespace", "Namespace"), ("operation", "write"),
            ("operation", True), ("rebootState", "uncertain"),
            ("rebootState", True), ("version", True),
        ):
            changed = fixture(); changed[key] = bad; self.assert_refused(changed)
        changed = fixture(); changed["namespace"] = fixture("revocation-high-water")["namespace"]
        self.assert_refused(changed)

    def test_state_transition_and_previous_link_are_exact(self):
        for current, proposed in ((7, 7), (7, 9), (0, 2)):
            changed = fixture(current_generation=current)
            changed["afterState"]["generation"] = proposed; self.assert_refused(changed)
        changed = fixture(); changed["afterState"]["previousDigest"] = "9" * 64
        self.assert_refused(changed)
        changed = fixture(); changed["afterState"]["rawDigest"] = changed["beforeState"]["rawDigest"]
        self.assert_refused(changed)
        changed = fixture(current_generation=0); changed["beforeState"]["rawDigest"] = "a" * 64
        self.assert_refused(changed)
        changed = fixture("revocation-high-water", current_generation=1)
        changed["afterState"]["generation"] = 3
        self.assertEqual(self.decode(changed, paired_request(changed))["afterGeneration"], 3)

    def test_success_requires_exact_handle_write_flush_and_stability_flags(self):
        for key in ("heldHandle", "writeThrough", "flushFileBuffers",
                    "descriptorStable", "tokenStable"):
            for bad in (False, 1, "true", None):
                changed = fixture(); changed[key] = bad
                with self.subTest(key=key, bad=bad): self.assert_refused(changed)

    def test_success_disposition_matches_bootstrap_or_existing_state(self):
        self.assertEqual(self.decode(fixture(current_generation=0))["beforeGeneration"], 0)
        for current, bad in ((0, "opened-existing"), (7, "created-new")):
            changed = fixture(current_generation=current); changed["disposition"] = bad
            self.assert_refused(changed)
        changed = fixture(); changed["disposition"] = "replaced"; self.assert_refused(changed)

    def test_run_journal_and_pre_post_file_identities_are_exact_and_stable(self):
        value = fixture()
        for section in ("runIdentity", "journalIdentity", "beforeFileIdentity",
                        "afterFileIdentity"):
            for key in tuple(value[section]):
                changed = copy.deepcopy(value); del changed[section][key]; self.assert_refused(changed)
        for section, key, bad in (
            ("runIdentity", "path", "c:/oncam/runs/oncam-checkout-a"),
            ("journalIdentity", "path", "C:/oncam/other.journal"),
            ("beforeFileIdentity", "fileId", "103"),
            ("afterFileIdentity", "volumeSerial", "78"),
        ):
            changed = fixture(); changed[section][key] = bad; self.assert_refused(changed)
        changed = fixture(); changed["journalIdentity"]["fileId"] = "101"
        self.assert_refused(changed)

    def test_all_digest_and_generation_bindings_are_exact(self):
        for key in ("journalRequestDigest", "providerAuthorityDigest",
                    "providerEvidenceDigest", "securityDescriptorDigest", "policyDigest"):
            for bad in ("A" * 64, "0" * 63, True, None):
                changed = fixture(); changed[key] = bad; self.assert_refused(changed)
        for bad in (0, True, 1 << 63):
            changed = fixture(); changed["providerGeneration"] = bad; self.assert_refused(changed)

    def test_paths_secrets_native_details_and_capabilities_are_rejected(self):
        for key in ("credential", "dpapiBlob", "errorMessage", "keyPath", "nativeError",
                    "password", "privateKey", "providerHandle", "tlsMaterialCapability"):
            changed = fixture(); changed[key] = "forbidden"; self.assert_refused(changed)

    def test_every_common_binding_mutation_changes_evidence_digest(self):
        original = self.decode(fixture())["evidenceDigest"]
        mutations = []
        for key, value in (("namespace", "preparation-authorization"),
                           ("providerGeneration", 10), ("policyDigest", "8" * 64)):
            changed = fixture(); changed[key] = value; mutations.append(changed)
        changed = fixture(); changed["afterState"]["rawDigest"] = "9" * 64; mutations.append(changed)
        changed = fixture(); changed["journalIdentity"]["fileId"] = "103"
        changed["beforeFileIdentity"]["fileId"] = "103"
        changed["afterFileIdentity"]["fileId"] = "103"; mutations.append(changed)
        for changed in mutations:
            self.assertNotEqual(
                original, self.decode(changed, paired_request(changed))["evidenceDigest"]
            )

    def test_request_digest_and_every_duplicated_binding_are_cross_checked(self):
        base_evidence = fixture(); base_request = request_fixture(base_evidence)
        changed = fixture(); changed["journalRequestDigest"] = "7" * 64
        self.assert_refused_with_request(changed, base_request)

        variants = []
        changed = fixture(); changed["namespace"] = "preparation-authorization"; variants.append(changed)
        changed = fixture("revocation-high-water"); variants.append(changed)
        changed = fixture(current_generation=8); variants.append(changed)
        for key in ("policyDigest", "providerAuthorityDigest", "providerEvidenceDigest",
                    "securityDescriptorDigest"):
            changed = fixture(); changed[key] = "8" * 64; variants.append(changed)
        changed = fixture(); changed["providerGeneration"] = 10; variants.append(changed)
        changed = fixture(); changed["afterState"]["rawDigest"] = "8" * 64; variants.append(changed)
        changed = fixture()
        changed["journalIdentity"]["fileId"] = "103"
        changed["beforeFileIdentity"]["fileId"] = "103"
        changed["afterFileIdentity"]["fileId"] = "103"
        variants.append(changed)
        changed = fixture()
        changed["runIdentity"] = identity("C:/oncam/runs/oncam-checkout-b", 111)
        journal_path = "C:/oncam/runs/oncam-checkout-b/.checkout-one-shot-ledger.journal"
        changed["journalIdentity"] = identity(journal_path, 112)
        changed["beforeFileIdentity"] = identity(journal_path, 112)
        changed["afterFileIdentity"] = identity(journal_path, 112)
        variants.append(changed)
        for changed in variants:
            with self.subTest(binding=changed):
                self.decode(changed, paired_request(changed))
                self.assert_refused_with_request(changed, base_request)

    def test_refused_evidence_cannot_bypass_request_digest_or_common_binding_pairing(self):
        refused = fixture(status="refused")
        request = request_fixture(refused)
        self.assertEqual(self.decode(refused, request)["status"], "refused")

        wrong_digest = copy.deepcopy(refused)
        wrong_digest["journalRequestDigest"] = "7" * 64
        self.assert_refused_with_request(wrong_digest, request)

        wrong_generation = copy.deepcopy(refused)
        wrong_generation["providerGeneration"] += 1
        self.assert_refused_with_request(wrong_generation, request)

        wrong_common_binding = copy.deepcopy(refused)
        wrong_common_binding["providerEvidenceDigest"] = "8" * 64
        self.assert_refused_with_request(wrong_common_binding, request)

    def assert_refused_with_request(self, evidence, request):
        with self.assertRaisesRegex(codec.ProtectedJournalEvidenceRefused,
                                    "^protected_journal_evidence$"):
            self.decode(evidence, request)

    def test_dependency_and_authority_mutation_fail_closed_before_replacement(self):
        value = fixture(); raw = canonical(value); request_raw = canonical(request_fixture(value))
        refusal = codec.ProtectedJournalEvidenceRefused
        for name, replacement in (
            ("KINDS", tuple(list(codec.KINDS))), ("MAX_EVIDENCE_BYTES", MAX_EVIDENCE_BYTES + 1),
            ("ZERO_DIGEST", "f" * 64), ("ProtectedJournalEvidenceRefused", Exception),
            ("MappingProxyType", dict), ("json", object()), ("hashlib", object()),
            ("re", object()), ("__all__", tuple(list(codec.__all__))),
            ("_REQUEST", object()), ("_REQUEST_PATH", MODULE_PATH),
        ):
            original = getattr(codec, name)
            try:
                setattr(codec, name, replacement)
                with self.assertRaises(refusal): codec.decode(request_raw, raw)
            finally:
                setattr(codec, name, original)
        for owner, name in ((codec.json, "loads"), (codec.json, "dumps"),
                            (codec.hashlib, "sha256"), (codec.re, "fullmatch")):
            original = getattr(owner, name); calls = []
            def replacement(*_args, **_kwargs): calls.append(True); return {}
            try:
                setattr(owner, name, replacement)
                with self.assertRaises(refusal): codec.decode(request_raw, raw)
                self.assertEqual(calls, [])
            finally:
                setattr(owner, name, original)
        for name in ("decode", "canonical_request"):
            original = getattr(codec._REQUEST, name); calls = []
            def replacement(*_args, **_kwargs): calls.append(True); return {}
            try:
                setattr(codec._REQUEST, name, replacement)
                with self.assertRaises(refusal): codec.decode(request_raw, raw)
                self.assertEqual(calls, [])
            finally:
                setattr(codec._REQUEST, name, original)

    def test_baseexception_identity_and_no_io_authority_surface(self):
        invoke = next(cell.cell_contents for cell in codec.decode.__closure__
                      if callable(cell.cell_contents)
                      and getattr(cell.cell_contents, "__name__", "") == "invoke")
        original = codec.KINDS; replacement = tuple(list(original))
        for primary in (KeyboardInterrupt("k"), SystemExit("s")):
            def drift_then_stop(error=primary):
                codec.KINDS = replacement
                raise error
            try:
                with self.assertRaises(type(primary)) as raised: invoke(drift_then_stop)
                self.assertIs(raised.exception, primary)
            finally:
                codec.KINDS = original
        for forbidden in ("apply", "commit", "load", "open", "persist", "protect",
                          "read", "rollback", "verify_acl", "write"):
            self.assertFalse(hasattr(codec, forbidden))
        text = (codec.__doc__ or "").lower()
        for phrase in ("structural", "does not", "supplied", "future"):
            self.assertIn(phrase, text)


if __name__ == "__main__":
    unittest.main()
