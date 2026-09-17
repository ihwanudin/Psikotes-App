import copy
import importlib.util
import json
from pathlib import Path
import sys
import unittest


HERE = Path(__file__).parent
MODULE_PATH = HERE / "checkout-ordinary-access-evidence.py"
REQUEST_PATH = HERE / "checkout-ordinary-access-request.py"


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


evidence_codec = load("checkout_ordinary_access_evidence_tested", MODULE_PATH)
request_codec = load("checkout_ordinary_access_request_fixture", REQUEST_PATH)


def canonical(value):
    return (json.dumps(value, sort_keys=True, separators=(",", ":"),
                       ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")


def request_value():
    current = {
        "authenticationId": [2, 0], "modifiedId": [3, 0],
        "tokenId": [1, 0], "userSid": "S-1-5-21-111-222-333-1002",
    }
    targets = []
    for role, suffix in zip(("coordinator", "run", "source"), ("a", "b", "c")):
        targets.append({
            "daclDigest": suffix * 64, "desiredAccess": 0x02000000,
            "fileId": str(ord(suffix)), "ownerSid": current["userSid"],
            "path": f"c:/checkout/{role}", "reparse": False, "role": role,
            "volumeSerial": "1",
        })
    return {
        "aclDescriptorEvidenceDigest": "4" * 64, "aclEvidenceDigest": "5" * 64,
        "aclRequestDigest": "6" * 64, "boundary": "anchor", "challenge": "7" * 64,
        "configBinding": "8" * 64, "currentTokenIdentity": current,
        "leaseBinding": "9" * 64, "phase": "fresh",
        "policyDigest": request_codec.POLICY_DIGEST,
        "session": "checkout-" + "a" * 32, "targets": targets, "version": 1,
    }


def token(token_type, level, token_id, modified_id):
    return {
        "authenticationId": [20, -1],
        "groups": [{"attributes": 4, "sid": "S-1-5-32-545"}],
        "impersonationLevel": level, "isAppContainer": False,
        "isAppContainerRaw": 0, "modifiedId": modified_id,
        "origin": [25, 0],
        "privileges": [
            {"attributes": 0, "luid": [17, 0]},
            {"attributes": 2, "luid": [18, 0]},
        ],
        "restrictingSids": [], "tokenId": token_id,
        "tokenType": token_type, "userSid": "S-1-5-21-111-222-333-2001",
    }


def evidence_value(request_raw):
    request = json.loads(request_raw)
    value = copy.deepcopy(request)
    value.update({
        "derivedToken": token("TokenImpersonation", 2, [22, 0], [24, 0]),
        "originalToken": token("TokenPrimary", None, [21, 0], [23, 0]),
        "provenanceKind": "externally_provisioned_windows_principal",
        "requestDigest": request_codec.request_digest(request_raw),
    })
    value["targets"] = []
    for target in request["targets"]:
        item = copy.deepcopy(target)
        item.update({
            "accessStatus": False, "descriptorDigest": "d" * 64,
            "functionSuccess": True, "grantedAccess": 0,
            "policySatisfied": True,
        })
        value["targets"].append(item)
    return value


class OrdinaryAccessEvidenceTests(unittest.TestCase):
    def fixture(self):
        request_raw = canonical(request_value())
        request_snapshot = evidence_codec._REQUEST_CODEC.decode_request(request_raw)
        return request_raw, request_snapshot, evidence_value(request_raw)

    def refused(self, operation):
        with self.assertRaisesRegex(evidence_codec.OrdinaryEvidenceRefused,
                                    "^ordinary_access_evidence$"):
            operation()

    def test_known_vector_is_structural_only_and_immutable(self):
        request_raw, _snapshot, value = self.fixture()
        raw = canonical(value)
        result = evidence_codec.decode(request_raw, raw)
        self.assertIs(result.structuralOnly, True)
        self.assertEqual(result.requestDigest, request_codec.request_digest(request_raw))
        self.assertEqual(result.evidenceDigest, "92c49b3044e34bf66415fd48fb8b9e3548e6ea1a0f49c452a4a602a3fbc7c2ad")
        self.assertEqual(result.boundary, "anchor")
        with self.assertRaises((AttributeError, TypeError)):
            result.structuralOnly = False

    def test_accepts_only_exact_canonical_request_bytes(self):
        request_raw, snapshot, value = self.fixture(); raw = canonical(value)
        self.assertTrue(evidence_codec.decode(request_raw, raw).structuralOnly)
        request_b_value = request_value(); request_b_value["challenge"] = "e" * 64
        request_b = canonical(request_b_value)
        object.__setattr__(snapshot, "_RequestSnapshot__raw", request_b)
        object.__setattr__(snapshot, "_RequestSnapshot__document", {"attack": True})
        for request in (snapshot, bytearray(request_raw), json.loads(request_raw), object()):
            self.refused(lambda r=request: evidence_codec.decode(r, raw))

    def test_repeated_request_fields_are_exact_but_request_digest_is_derived(self):
        request_raw, _snapshot, value = self.fixture()
        for key in request_value():
            changed = copy.deepcopy(value)
            if key == "targets":
                changed[key][0]["path"] += "x"
            elif key == "currentTokenIdentity":
                changed[key]["modifiedId"] = [99, 0]
            elif type(changed[key]) is str:
                changed[key] = "f" * 64
            else:
                changed[key] = 2
            with self.subTest(key=key):
                self.refused(lambda v=changed: evidence_codec.decode(request_raw, canonical(v)))
        changed = copy.deepcopy(value); changed["requestDigest"] = "0" * 64
        self.refused(lambda: evidence_codec.decode(request_raw, canonical(changed)))

    def test_token_relationships_and_serialized_policy_are_strict(self):
        request_raw, _snapshot, value = self.fixture()
        mutations = []
        for side, field, bad in (
                ("originalToken", "tokenType", "TokenImpersonation"),
                ("originalToken", "impersonationLevel", 2),
                ("derivedToken", "tokenType", "TokenPrimary"),
                ("derivedToken", "impersonationLevel", None),
                ("originalToken", "userSid", "S-1-5-18"),
                ("originalToken", "authenticationId", [2, 0]),
                ("originalToken", "isAppContainerRaw", 1),
                ("derivedToken", "isAppContainer", True)):
            changed = copy.deepcopy(value); changed[side][field] = bad; mutations.append(changed)
        changed = copy.deepcopy(value); changed["derivedToken"]["groups"] = []; mutations.append(changed)
        changed = copy.deepcopy(value); changed["derivedToken"]["privileges"][0]["attributes"] = 2; mutations.append(changed)
        changed = copy.deepcopy(value); changed["originalToken"]["restrictingSids"] = [{"attributes": 0, "sid": "S-1-5-12"}]; mutations.append(changed)
        changed = copy.deepcopy(value); changed["derivedToken"]["tokenId"] = changed["originalToken"]["tokenId"]; mutations.append(changed)
        changed = copy.deepcopy(value); changed["originalToken"]["groups"].append(
            {"attributes": 0, "sid": request_value()["currentTokenIdentity"]["userSid"]}); mutations.append(changed)
        for changed in mutations:
            self.refused(lambda v=changed: evidence_codec.decode(request_raw, canonical(v)))

    def test_dangerous_privilege_names_and_native_proofs_are_deliberately_not_schema(self):
        request_raw, _snapshot, value = self.fixture()
        # Raw LUIDs are structural only: this codec deliberately has no name authority.
        value["originalToken"]["privileges"][0]["luid"] = [999, -7]
        value["derivedToken"]["privileges"][0]["luid"] = [999, -7]
        self.assertTrue(evidence_codec.decode(request_raw, canonical(value)).structuralOnly)
        for forbidden in ("isTokenRestricted", "accessCheckCalled", "temporallyStable"):
            changed = copy.deepcopy(value); changed[forbidden] = True
            self.refused(lambda v=changed: evidence_codec.decode(request_raw, canonical(v)))

    def test_ordered_target_and_denial_tuple_are_exact(self):
        request_raw, _snapshot, value = self.fixture()
        mutations = []
        changed = copy.deepcopy(value); changed["targets"].reverse(); mutations.append(changed)
        for field, bad in (("role", "run"), ("desiredAccess", 0),
                           ("descriptorDigest", "D" * 64), ("policySatisfied", False),
                           ("functionSuccess", False), ("accessStatus", True),
                           ("grantedAccess", 1)):
            changed = copy.deepcopy(value); changed["targets"][0][field] = bad; mutations.append(changed)
        for changed in mutations:
            self.refused(lambda v=changed: evidence_codec.decode(request_raw, canonical(v)))

    def test_json_boundary_exact_types_and_bounds(self):
        request_raw, _snapshot, value = self.fixture(); raw = canonical(value)
        changed = copy.deepcopy(value); changed["originalToken"]["groups"][0]["attributes"] = True
        variants = [raw[:-1], raw + b"\n", b"\xef\xbb\xbf" + raw,
                    json.dumps(value).encode("ascii"), canonical(changed),
                    b'{"x":1,"x":2}\n', b'{"x":NaN}\n',
                    b"{" + b" " * evidence_codec.MAX_EVIDENCE_BYTES + b"}\n", "not-bytes"]
        for raw_value in variants:
            self.refused(lambda r=raw_value: evidence_codec.decode(request_raw, r))

    def test_input_mutation_isolated_and_authority_tamper_refuses(self):
        request_raw, _snapshot, value = self.fixture(); raw = canonical(value)
        result = evidence_codec.decode(request_raw, raw)
        value["originalToken"]["groups"].clear()
        self.assertEqual(result.requestDigest, request_codec.request_digest(request_raw))
        self.assertFalse(hasattr(evidence_codec, "_make_codec"))
        original = evidence_codec.json.loads
        try:
            evidence_codec.json.loads = lambda *a, **k: {}
            self.refused(lambda: evidence_codec.decode(request_raw, raw))
        finally:
            evidence_codec.json.loads = original
        original = evidence_codec.OrdinaryEvidenceRefused
        try:
            evidence_codec.OrdinaryEvidenceRefused = Exception
            with self.assertRaises(original) as raised:
                evidence_codec.decode(request_raw, b"{}\n")
            self.assertEqual(str(raised.exception), "ordinary_access_evidence")
        finally:
            evidence_codec.OrdinaryEvidenceRefused = original

    def test_public_boundary_preserves_keyboard_interrupt_and_system_exit(self):
        request_raw, _snapshot, value = self.fixture(); raw = canonical(value)
        for primary in (KeyboardInterrupt("k"), SystemExit("s")):
            def trace(frame, event, _arg, error=primary):
                if event == "call" and frame.f_code.co_name == "decode_impl":
                    raise error
                return trace
            try:
                sys.settrace(trace)
                with self.assertRaises(type(primary)) as raised:
                    evidence_codec.decode(request_raw, raw)
                self.assertIs(raised.exception, primary)
            finally:
                sys.settrace(None)

    def test_request_snapshot_method_code_identity_is_pinned(self):
        request_raw, snapshot, value = self.fixture(); raw = canonical(value)
        snapshot_type = evidence_codec._REQUEST_CODEC._RequestSnapshot
        original = snapshot_type.bytes.__code__
        try:
            snapshot_type.bytes.__code__ = original.replace()
            self.refused(lambda: evidence_codec.decode(snapshot, raw))
        finally:
            snapshot_type.bytes.__code__ = original

    def test_cross_request_snapshot_and_added_sibling_global_cannot_substitute_bytes(self):
        request_a, _snapshot_a, _value_a = self.fixture()
        request_b_value = request_value()
        request_b_value["challenge"] = "e" * 64
        request_b = canonical(request_b_value)
        evidence_b = canonical(evidence_value(request_b))
        sibling = evidence_codec._REQUEST_CODEC
        snapshot_type = sibling._RequestSnapshot
        original_code = snapshot_type.bytes.__code__

        def substituted(self):
            return ATTACK_RAW

        try:
            sibling.ATTACK_RAW = request_b
            snapshot_type.bytes.__code__ = substituted.__code__
            self.refused(lambda: evidence_codec.decode(request_a, evidence_b))
        finally:
            snapshot_type.bytes.__code__ = original_code
            del sibling.ATTACK_RAW
        self.assertNotEqual(request_a, request_b)

    def test_structural_result_constructor_cannot_be_forged(self):
        request_raw, _snapshot, value = self.fixture(); raw = canonical(value)
        result_type = evidence_codec._StructuralEvidence
        original_new = result_type.__new__

        def forged(cls, *_args, **_kwargs):
            return tuple.__new__(cls, (False, "0" * 64, "0" * 64,
                                      "anchor", "fresh", "checkout-" + "0" * 32))

        try:
            result_type.__new__ = forged
            self.refused(lambda: evidence_codec.decode(request_raw, raw))
        finally:
            result_type.__new__ = original_new


if __name__ == "__main__":
    unittest.main()
