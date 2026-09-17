import copy
import hashlib
import importlib.util
import json
from pathlib import Path
from types import MappingProxyType
import unittest


MODULE_PATH = Path(__file__).with_name("checkout-protected-journal-request.py")
SPEC = importlib.util.spec_from_file_location("checkout_protected_journal_request", MODULE_PATH)
codec = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(codec)

KINDS = ("one-shot-ledger", "revocation-high-water")
MAX_REQUEST_BYTES = 32 * 1024
MAX_FORBIDDEN_PATH_DIGESTS = 32
ZERO_DIGEST = "0" * 64
RESULT_FIELDS = (
    "structuralOnly", "journalKind", "namespace", "currentGeneration",
    "proposedGeneration", "currentStateDigest", "proposedStateDigest",
    "previousStateDigest", "providerGeneration", "journalPathDigest",
    "requestDigest",
)


def canonical(value):
    return (json.dumps(value, sort_keys=True, separators=(",", ":"),
                       ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")


def path_digest(value):
    return hashlib.sha256(
        b"oncam.checkout.canonical-path.v1\0" + value.encode("ascii")
    ).hexdigest()


def fixture(kind="one-shot-ledger", current_generation=7):
    leaf = ".checkout-one-shot-ledger.journal" if kind == "one-shot-ledger" \
        else ".checkout-revocation-high-water.journal"
    current_digest = format(10, "064x") if current_generation else ZERO_DIGEST
    return {
        "currentState": {"generation": current_generation, "rawDigest": current_digest},
        "expectedFileIdentity": {
            "fileId": "102",
            "path": f"C:/oncam/runs/oncam-checkout-a/{leaf}",
            "volumeSerial": "77",
        },
        "forbiddenPathDigests": sorted([
            path_digest("C:/oncam/runs/oncam-checkout-a/runtime.ini"),
            path_digest("C:/oncam/runs/oncam-checkout-a/supervisor-config.json"),
            path_digest("C:/oncam/runs/oncam-checkout-a/tls/server.key"),
        ]),
        "journalKind": kind,
        "namespace": "composition-admission" if kind == "one-shot-ledger"
            else "release-source.issuer-01.key-3.trust-5",
        "operation": "compare-and-swap",
        "policyDigest": "2" * 64,
        "proposedState": {
            "generation": current_generation + 1,
            "previousDigest": current_digest,
            "rawDigest": "3" * 64,
        },
        "providerAuthorityDigest": "4" * 64,
        "providerEvidenceDigest": "5" * 64,
        "providerGeneration": 6,
        "rebootState": "certain",
        "runIdentity": {
            "fileId": "101", "path": "C:/oncam/runs/oncam-checkout-a",
            "volumeSerial": "77",
        },
        "securityDescriptorDigest": "6" * 64,
        "version": 1,
    }


class ProtectedJournalRequestTests(unittest.TestCase):
    def decode(self, value):
        return codec.decode(canonical(value) if type(value) is dict else value)

    def assert_refused(self, value):
        with self.assertRaisesRegex(codec.ProtectedJournalRequestRefused,
                                    "^protected_journal_request$"):
            self.decode(value)

    def test_known_vector_is_canonical_immutable_and_structural_only(self):
        value = fixture(); raw = canonical(value); result = self.decode(value)
        self.assertEqual(codec.canonical_request(value), raw)
        self.assertEqual(codec.__all__, (
            "ProtectedJournalRequestRefused", "canonical_request", "decode",
        ))
        self.assertIs(type(result), MappingProxyType)
        self.assertEqual(tuple(result), RESULT_FIELDS)
        self.assertIs(result["structuralOnly"], True)
        self.assertEqual(result["providerGeneration"], 6)
        self.assertEqual(
            result["requestDigest"],
            "453b167e9494ca194c9dccbbb2cdf03827622c5b97059380ae61362b1c78ace3",
        )
        with self.assertRaises(TypeError):
            result["persisted"] = True
        for forbidden in ("accepted", "atomic", "consumed", "persisted", "protected"):
            self.assertNotIn(forbidden, result)

    def test_schema_encoding_and_size_are_exact_closed_and_canonical(self):
        value = fixture(); raw = canonical(value)
        for key in tuple(value):
            changed = copy.deepcopy(value); del changed[key]; self.assert_refused(changed)
        changed = copy.deepcopy(value); changed["commit"] = True; self.assert_refused(changed)
        for candidate in (
            raw[:-1], raw + b"\n", b"\xef\xbb\xbf" + raw,
            json.dumps(value).encode("ascii"), b'{"version":1,"version":1}\n',
            b'{"version":Infinity}\n', b"[]\n", "not-bytes", bytearray(raw),
            b"{" + b" " * MAX_REQUEST_BYTES + b"}\n",
        ):
            self.assert_refused(candidate)

    def test_two_kinds_operation_reboot_and_namespace_are_exact(self):
        for kind in KINDS:
            self.assertEqual(self.decode(fixture(kind))["journalKind"], kind)
        for key, bad in (
            ("journalKind", "other"), ("journalKind", True),
            ("operation", "write"), ("operation", True),
            ("rebootState", "unknown"), ("rebootState", True),
            ("namespace", "Namespace"), ("namespace", "../namespace"),
            ("version", 2), ("version", True),
        ):
            changed = fixture(); changed[key] = bad; self.assert_refused(changed)
        changed = fixture(); changed["namespace"] = "release-source.issuer-01.key-3.trust-5"
        self.assert_refused(changed)
        changed = fixture("revocation-high-water"); changed["namespace"] = "composition-admission"
        self.assert_refused(changed)
        for bad in (
            "release-source.issuer-01.key-0.trust-5",
            "release-source.issuer-01.key-03.trust-5",
            "release-source.issuer-01.key-3.trust-0",
        ):
            changed = fixture("revocation-high-water"); changed["namespace"] = bad
            self.assert_refused(changed)

    def test_compare_and_swap_generation_and_digest_linkage_are_exact(self):
        for current, proposed in ((7, 7), (7, 9), (0, 2)):
            changed = fixture(current_generation=current)
            changed["proposedState"]["generation"] = proposed
            self.assert_refused(changed)
        for section, key, bad in (
            ("currentState", "generation", True), ("currentState", "generation", -1),
            ("proposedState", "generation", True),
            ("proposedState", "generation", 1 << 63),
            ("proposedState", "previousDigest", "9" * 64),
            ("proposedState", "rawDigest", "a" * 63),
        ):
            changed = fixture(); changed[section][key] = bad; self.assert_refused(changed)
        self.assertEqual(self.decode(fixture(current_generation=0))["proposedGeneration"], 1)
        changed = fixture(current_generation=0)
        changed["currentState"]["rawDigest"] = "1" * 64; self.assert_refused(changed)
        changed = fixture()
        changed["proposedState"]["rawDigest"] = changed["currentState"]["rawDigest"]
        self.assert_refused(changed)

    def test_revocation_high_water_allows_strict_forward_generation_jump(self):
        changed = fixture("revocation-high-water", current_generation=1)
        changed["proposedState"]["generation"] = 3
        self.assertEqual(self.decode(changed)["proposedGeneration"], 3)
        for proposed in (0, 1):
            changed = fixture("revocation-high-water", current_generation=1)
            changed["proposedState"]["generation"] = proposed
            self.assert_refused(changed)
        changed = fixture("revocation-high-water", current_generation=0)
        changed["proposedState"]["generation"] = 2
        self.assert_refused(changed)

    def test_state_and_security_digest_shapes_are_closed(self):
        value = fixture()
        for section in ("currentState", "proposedState"):
            for key in tuple(value[section]):
                changed = copy.deepcopy(value); del changed[section][key]
                self.assert_refused(changed)
            changed = copy.deepcopy(value); changed[section]["raw"] = "secret"
            self.assert_refused(changed)
        for key in ("policyDigest", "providerAuthorityDigest",
                    "providerEvidenceDigest", "securityDescriptorDigest"):
            for bad in ("A" * 64, "0" * 63, True, None):
                changed = fixture(); changed[key] = bad; self.assert_refused(changed)
        for bad in (0, True, 1 << 63, "6", None):
            changed = fixture(); changed["providerGeneration"] = bad
            self.assert_refused(changed)

    def test_run_and_expected_file_identity_are_canonical_related_and_distinct(self):
        value = fixture()
        for section in ("runIdentity", "expectedFileIdentity"):
            for key in tuple(value[section]):
                changed = copy.deepcopy(value); del changed[section][key]
                self.assert_refused(changed)
        for section, key, bad in (
            ("runIdentity", "path", "c:/oncam/runs/oncam-checkout-a"),
            ("expectedFileIdentity", "path", "C:/oncam/other.journal"),
            ("expectedFileIdentity", "path", "C:/oncam/runs/oncam-checkout-a/../x"),
            ("runIdentity", "volumeSerial", 77),
            ("expectedFileIdentity", "fileId", "01"),
        ):
            changed = fixture(); changed[section][key] = bad; self.assert_refused(changed)
        changed = fixture()
        changed["expectedFileIdentity"]["fileId"] = changed["runIdentity"]["fileId"]
        self.assert_refused(changed)
        changed = fixture("revocation-high-water")
        changed["expectedFileIdentity"]["path"] = fixture()["expectedFileIdentity"]["path"]
        self.assert_refused(changed)

    def test_forbidden_path_digest_set_is_sorted_unique_bounded_and_excludes_journal(self):
        value = fixture(); journal_digest = path_digest(value["expectedFileIdentity"]["path"])
        invalid_sets = (
            [], value["forbiddenPathDigests"][:2],
            list(reversed(value["forbiddenPathDigests"])),
            value["forbiddenPathDigests"] + [value["forbiddenPathDigests"][0]],
            ["A" * 64] * 3, [True] * 3,
            [format(index + 900, "064x") for index in range(3)],
            [format(index + 100, "064x")
             for index in range(MAX_FORBIDDEN_PATH_DIGESTS + 1)],
        )
        for forbidden in invalid_sets:
            changed = fixture(); changed["forbiddenPathDigests"] = forbidden
            self.assert_refused(changed)
        changed = fixture()
        changed["forbiddenPathDigests"] = sorted(
            changed["forbiddenPathDigests"] + [journal_digest]
        )
        self.assert_refused(changed)
        for index in range(3):
            changed = fixture()
            changed["forbiddenPathDigests"][index] = format(800 + index, "064x")
            changed["forbiddenPathDigests"].sort()
            self.assert_refused(changed)

    def test_private_capability_and_secret_fields_are_rejected(self):
        for key in ("candidatePath", "configPath", "credential", "dpapiBlob",
                    "keyPath", "password", "privateKeyDigest",
                    "privateKeyMetadata", "secret", "tlsMaterialCapability"):
            changed = fixture(); changed[key] = "forbidden"; self.assert_refused(changed)

    def test_every_binding_mutation_changes_request_digest(self):
        value = fixture(); original = self.decode(value)["requestDigest"]
        mutations = []
        changed = copy.deepcopy(value); changed["namespace"] = "preparation-authorization"; mutations.append(changed)
        changed = copy.deepcopy(value); changed["providerEvidenceDigest"] = "7" * 64; mutations.append(changed)
        changed = copy.deepcopy(value); changed["providerGeneration"] += 1; mutations.append(changed)
        changed = copy.deepcopy(value); changed["proposedState"]["rawDigest"] = "8" * 64; mutations.append(changed)
        changed = copy.deepcopy(value); changed["expectedFileIdentity"]["fileId"] = "103"; mutations.append(changed)
        for changed in mutations:
            self.assertNotEqual(original, self.decode(changed)["requestDigest"])

    def test_dependency_and_authority_tampering_refuses_before_replacement(self):
        raw = canonical(fixture()); refusal = codec.ProtectedJournalRequestRefused
        for name, replacement in (
            ("KINDS", tuple(list(codec.KINDS))),
            ("ONE_SHOT_NAMESPACES", tuple(list(codec.ONE_SHOT_NAMESPACES))),
            ("REVOCATION_ROLES", tuple(list(codec.REVOCATION_ROLES))),
            ("MAX_REQUEST_BYTES", MAX_REQUEST_BYTES + 1),
            ("MAX_FORBIDDEN_PATH_DIGESTS", MAX_FORBIDDEN_PATH_DIGESTS + 1),
            ("ZERO_DIGEST", "f" * 64),
            ("ProtectedJournalRequestRefused", Exception),
            ("MappingProxyType", dict), ("json", object()),
            ("hashlib", object()), ("re", object()),
            ("__all__", tuple(list(codec.__all__))),
        ):
            original = getattr(codec, name)
            try:
                setattr(codec, name, replacement)
                with self.assertRaises(refusal): codec.decode(raw)
            finally:
                setattr(codec, name, original)
        for owner, name in ((codec.json, "loads"), (codec.json, "dumps"),
                            (codec.hashlib, "sha256"), (codec.re, "fullmatch")):
            original = getattr(owner, name); calls = []
            def replacement(*_args, **_kwargs): calls.append(True); return {}
            try:
                setattr(owner, name, replacement)
                with self.assertRaises(refusal): codec.decode(raw)
                self.assertEqual(calls, [])
            finally:
                setattr(owner, name, original)

    def test_baseexception_identity_and_no_provider_authority_surface(self):
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
        for forbidden in ("apply", "commit", "delete", "load", "open", "persist",
                          "protect", "read", "rollback", "verify_acl", "write"):
            self.assertFalse(hasattr(codec, forbidden))
        text = (codec.__doc__ or "").lower()
        for phrase in ("structural", "does not", "supplied", "protected"):
            self.assertIn(phrase, text)


if __name__ == "__main__":
    unittest.main()
