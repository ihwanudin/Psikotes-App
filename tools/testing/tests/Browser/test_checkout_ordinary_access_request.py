import ast
import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import unittest
from unittest.mock import patch


HERE = Path(__file__).resolve().parent
MODULE_PATH = HERE / "checkout-ordinary-access-request.py"
ACL_CODEC_PATH = HERE / "checkout-acl-attestation.py"


def load_path(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def load_module():
    return load_path("checkout_ordinary_access_request_tested", MODULE_PATH)


class CheckoutOrdinaryAccessRequestTests(unittest.TestCase):
    maxDiff = None

    def fixture(self):
        module = load_module()
        acl = load_path("checkout_acl_attestation_request_fixture", ACL_CODEC_PATH)
        request = {
            "version": 1, "boundary": "anchor", "phase": "fresh",
            "session": "checkout-" + "a" * 32,
            "configBinding": "b" * 64,
            "leaseBinding": acl.run_binding("c:/checkout/run"),
            "leaseIdentity": {
                "path": "c:/checkout/run/.checkout-coordinator.lease",
                "coordinator": {"volumeSerial": "11", "fileId": "12"},
                "descriptor": {"volumeSerial": "13", "fileId": "14"},
                "run": {"volumeSerial": "15", "fileId": "16"},
            },
            "policyDigest": module.POLICY_DIGEST, "challenge": "d" * 64,
            "targets": [
                {"role": "coordinator", "path": "c:/checkout/control"},
                {"role": "run", "path": "c:/checkout/run"},
                {"role": "source", "path": "c:/checkout/run/source"},
            ],
        }
        evidence = {
            "version": 1, "requestDigest": acl.request_digest(request),
            "policyDigest": module.POLICY_DIGEST,
            "leaseDigest": acl.lease_digest(
                request["leaseBinding"], request["leaseIdentity"],
            ),
            "targets": [],
        }
        identities = (("11", "12"), ("15", "16"), ("17", "18"))
        for target, identity, digest in zip(
                request["targets"], identities, ("e" * 64, "f" * 64, "0" * 64),
                strict=True):
            evidence["targets"].append({
                **target, "volumeSerial": identity[0], "fileId": identity[1],
                "ownerSid": "S-1-5-21-1", "daclDigest": digest,
                "reparse": False, "policySatisfied": True,
            })
        request_raw = acl.canonical_request(request)
        evidence_raw = acl.canonical_evidence(evidence, request)
        identity = module._current_token_identity(
            "S-1-5-21-1", (1, -1), (0xFFFFFFFF, -0x80000000),
            (2, 0x7FFFFFFF),
        )
        return module, acl, request, evidence, request_raw, evidence_raw, identity

    def refused(self, module, callback):
        with self.assertRaisesRegex(
                module.OrdinaryAccessRefused,
                "^ordinary_principal_admission$"):
            callback()

    def test_module_is_request_codec_only_and_has_no_provider_surface(self):
        module = load_module()
        self.assertFalse(hasattr(module, "attest"))
        self.assertFalse(hasattr(module, "load"))
        self.assertFalse(hasattr(module, "discard"))
        source = MODULE_PATH.read_text(encoding="utf-8")
        tree = ast.parse(source)
        imports = {
            alias.name.split(".", 1)[0]
            for node in ast.walk(tree)
            if isinstance(node, ast.Import) for alias in node.names
        }
        imports.update(
            node.module.split(".", 1)[0]
            for node in ast.walk(tree)
            if isinstance(node, ast.ImportFrom) and node.module
        )
        self.assertFalse({"ctypes", "os", "subprocess", "socket"}.intersection(imports))
        self.assertFalse(any(
            isinstance(node, ast.ClassDef) and "Provider" in node.name
            for node in ast.walk(tree)
        ))

    def test_build_derives_bindings_and_returns_canonical_immutable_bytes(self):
        module, acl, outer, evidence, outer_raw, evidence_raw, identity = self.fixture()
        raw = module._build_request_with_challenge(
            outer_raw, evidence_raw, identity, "1" * 64,
        )
        snapshot = module.decode_request(raw)
        values = snapshot.values()
        self.assertIs(type(raw), bytes)
        self.assertEqual(snapshot.bytes(), raw)
        self.assertEqual(raw, (json.dumps(
            json.loads(raw), sort_keys=True, separators=(",", ":"),
            ensure_ascii=True,
        ) + "\n").encode("ascii"))
        self.assertLessEqual(len(raw), 16 * 1024)
        for key in ("boundary", "phase", "session", "configBinding", "leaseBinding"):
            self.assertEqual(values[key], outer[key])
        self.assertEqual(values["policyDigest"], module.POLICY_DIGEST)
        self.assertEqual(values["challenge"], "1" * 64)
        self.assertEqual(values["aclRequestDigest"], hashlib.sha256(outer_raw).hexdigest())
        self.assertEqual(values["aclEvidenceDigest"], hashlib.sha256(evidence_raw).hexdigest())
        self.assertEqual(tuple(target["role"] for target in values["targets"]),
                         ("coordinator", "run", "source"))
        self.assertTrue(all(target["desiredAccess"] == 0x02000000
                            for target in values["targets"]))
        self.assertEqual(values["currentTokenIdentity"]["userSid"], "S-1-5-21-1")
        with self.assertRaises(TypeError):
            values["phase"] = "recovery"
        with self.assertRaises(TypeError):
            values["targets"][0]["path"] = "c:/changed"
        with self.assertRaisesRegex(module.OrdinaryAccessRefused,
                                    "^ordinary_principal_admission$"):
            identity.extra = object()
        self.assertEqual(acl.request_digest(outer), evidence["requestDigest"])

    def test_build_api_generates_entropy_and_has_no_cross_call_cache(self):
        module, _acl, _outer, _evidence, outer_raw, evidence_raw, identity = self.fixture()
        with patch.object(module.secrets, "token_hex",
                          side_effect=["1" * 64, "2" * 64]) as entropy:
            first = module.build_request(outer_raw, evidence_raw, identity)
            second = module.build_request(outer_raw, evidence_raw, identity)
        self.assertNotEqual(first, second)
        self.assertEqual(entropy.call_args_list[0].args, (32,))
        self.assertEqual(entropy.call_count, 2)
        self.assertFalse(hasattr(module, "cache"))

    def test_outer_bytes_and_semantic_bindings_are_strict(self):
        module, acl, outer, evidence, outer_raw, evidence_raw, identity = self.fixture()
        cases = []
        for name, raw in (
            ("request_bom", b"\xef\xbb\xbf" + outer_raw),
            ("request_trailing", outer_raw + b"\n"),
            ("evidence_bom", b"\xef\xbb\xbf" + evidence_raw),
            ("evidence_trailing", evidence_raw + b"\n"),
        ):
            cases.append((name, raw if name.startswith("request") else outer_raw,
                          raw if name.startswith("evidence") else evidence_raw,
                          identity))
        mismatched = copy.deepcopy(evidence)
        mismatched["targets"][0]["ownerSid"] = "S-1-5-21-2"
        cases.append(("owner", outer_raw,
                      acl.canonical_evidence(mismatched, outer), identity))
        reordered = copy.deepcopy(evidence)
        reordered["targets"].reverse()
        cases.append(("order", outer_raw, acl._json_bytes(reordered), identity))
        changed_outer = copy.deepcopy(outer)
        changed_outer["configBinding"] = "9" * 64
        cases.append(("stale_evidence", acl.canonical_request(changed_outer),
                      evidence_raw, identity))
        changed_policy = copy.deepcopy(outer)
        changed_policy["policyDigest"] = "9" * 64
        cases.append(("policy", acl._json_bytes(changed_policy), evidence_raw,
                      identity))
        invalid_identity = module._CurrentTokenIdentity.__new__(
            module._CurrentTokenIdentity,
        )
        cases.append(("identity_type", outer_raw, evidence_raw, invalid_identity))
        for name, request_bytes, evidence_bytes, token_identity in cases:
            with self.subTest(case=name):
                self.refused(module, lambda: module._build_request_with_challenge(
                    request_bytes, evidence_bytes, token_identity, "1" * 64,
                ))

    def test_targets_require_pairwise_distinct_paths_and_identities(self):
        module, acl, outer, evidence, _outer_raw, _evidence_raw, identity = self.fixture()
        path_request = copy.deepcopy(outer)
        path_request["targets"][0]["path"] = path_request["targets"][1]["path"]
        path_evidence = copy.deepcopy(evidence)
        path_evidence["targets"][0]["path"] = path_request["targets"][0]["path"]

        identity_request = copy.deepcopy(outer)
        identity_request["leaseIdentity"]["coordinator"] = copy.deepcopy(
            identity_request["leaseIdentity"]["run"],
        )
        identity_evidence = copy.deepcopy(evidence)
        identity_evidence["targets"][0]["volumeSerial"] = "15"
        identity_evidence["targets"][0]["fileId"] = "16"
        identity_evidence["requestDigest"] = acl.request_digest(identity_request)
        identity_evidence["leaseDigest"] = acl.lease_digest(
            identity_request["leaseBinding"], identity_request["leaseIdentity"],
        )
        for name, request_raw, evidence_raw in (
            ("path", acl._json_bytes(path_request), acl._json_bytes(path_evidence)),
            ("identity", acl.canonical_request(identity_request),
             acl._json_bytes(identity_evidence)),
        ):
            with self.subTest(case=name):
                self.refused(module, lambda: module._build_request_with_challenge(
                    request_raw, evidence_raw, identity, "1" * 64,
                ))

    def test_exact_outer_evidence_digest_binds_nonprojected_fields(self):
        module, acl, outer, evidence, outer_raw, evidence_raw, identity = self.fixture()
        first = module.decode_request(module._build_request_with_challenge(
            outer_raw, evidence_raw, identity, "1" * 64,
        )).values()
        changed_request = copy.deepcopy(outer)
        changed_request["configBinding"] = "9" * 64
        changed_evidence = copy.deepcopy(evidence)
        changed_evidence["requestDigest"] = acl.request_digest(changed_request)
        changed_request_raw = acl.canonical_request(changed_request)
        changed_evidence_raw = acl.canonical_evidence(
            changed_evidence, changed_request,
        )
        second = module.decode_request(module._build_request_with_challenge(
            changed_request_raw, changed_evidence_raw, identity, "1" * 64,
        )).values()
        self.assertEqual(first["targets"], second["targets"])
        self.assertEqual(first["aclDescriptorEvidenceDigest"],
                         second["aclDescriptorEvidenceDigest"])
        self.assertNotEqual(first["aclRequestDigest"], second["aclRequestDigest"])
        self.assertNotEqual(first["aclEvidenceDigest"], second["aclEvidenceDigest"])

    def test_current_identity_luids_and_entropy_are_exact(self):
        module, _acl, outer, _evidence, outer_raw, evidence_raw, identity = self.fixture()
        invalid_identities = (
            (True, -1), (-1, 0), (0x100000000, 0), (0, -0x80000001),
            (0, 0x80000000), [0, 0], (0,), (0, 0, 0),
        )
        for value in invalid_identities:
            with self.subTest(luid=value):
                self.refused(module, lambda value=value: module._current_token_identity(
                    "S-1-5-21-1", value, (1, 0), (2, 0),
                ))
        for challenge in (True, None, "A" * 64, "1" * 63,
                          outer["challenge"]):
            with self.subTest(challenge=challenge):
                self.refused(module, lambda challenge=challenge:
                             module._build_request_with_challenge(
                                 outer_raw, evidence_raw, identity, challenge,
                             ))
        with patch.object(module, "_new_challenge",
                          side_effect=RuntimeError("PRIVATE ENTROPY")):
            with self.assertRaisesRegex(
                    module.OrdinaryAccessRefused,
                    "^ordinary_principal_admission$") as raised:
                module.build_request(outer_raw, evidence_raw, identity)
            self.assertNotIn("PRIVATE", str(raised.exception))
        for primary in (KeyboardInterrupt(), SystemExit(61)):
            with patch.object(module, "_new_challenge", side_effect=primary):
                with self.assertRaises(type(primary)) as raised:
                    module.build_request(outer_raw, evidence_raw, identity)
                self.assertIs(raised.exception, primary)

    def test_decode_rejects_duplicate_nonfinite_extra_and_type_confusion(self):
        module, _acl, _outer, _evidence, outer_raw, evidence_raw, identity = self.fixture()
        raw = module._build_request_with_challenge(
            outer_raw, evidence_raw, identity, "1" * 64,
        )
        document = json.loads(raw)
        extra = copy.deepcopy(document)
        extra["extra"] = None
        boolean = copy.deepcopy(document)
        boolean["targets"][0]["desiredAccess"] = True
        path = copy.deepcopy(document)
        path["targets"][0]["path"] = "c:/bad/../path"
        decimal = copy.deepcopy(document)
        decimal["targets"][0]["fileId"] = "01"
        sid = copy.deepcopy(document)
        sid["currentTokenIdentity"]["userSid"] = "S-2-5-21"
        mutations = [module._json_bytes(value)
                     for value in (extra, boolean, path, decimal, sid)]
        mutations.extend((
            raw.replace(b'"version":1', b'"version":NaN'),
            raw.replace(b'"version":1', b'"version":1,"version":1'),
            raw[:-1], raw + b"\n", b"\xef\xbb\xbf" + raw,
        ))
        for candidate in mutations:
            with self.subTest(candidate=candidate[:40]):
                self.refused(module, lambda candidate=candidate:
                             module.decode_request(candidate))

    def test_codec_identity_drift_fails_closed(self):
        module, _acl, _outer, _evidence, outer_raw, evidence_raw, identity = self.fixture()
        original = module._ACL_CODEC.decode_request
        module._ACL_CODEC.decode_request = lambda _raw: {}
        try:
            self.refused(module, lambda: module.build_request(
                outer_raw, evidence_raw, identity,
            ))
        finally:
            module._ACL_CODEC.decode_request = original

        helper = module._ACL_CODEC._sid
        original_code = helper.__code__
        helper.__code__ = (lambda _value: True).__code__
        try:
            self.refused(module, lambda: module.build_request(
                outer_raw, evidence_raw, identity,
            ))
        finally:
            helper.__code__ = original_code

        replacement_code = original_code.replace()
        self.assertIsNot(replacement_code, original_code)
        helper.__code__ = replacement_code
        try:
            self.refused(module, lambda: module.build_request(
                outer_raw, evidence_raw, identity,
            ))
        finally:
            helper.__code__ = original_code

        exported = module._ACL_CODEC.decode_request
        for attribute, changed in (
            ("__defaults__", (None,)),
            ("__kwdefaults__", {"unexpected": None}),
        ):
            original_metadata = getattr(exported, attribute)
            setattr(exported, attribute, changed)
            try:
                self.refused(module, lambda: module.build_request(
                    outer_raw, evidence_raw, identity,
                ))
            finally:
                setattr(exported, attribute, original_metadata)

        original_digest_pattern = module._ACL_CODEC._DIGEST
        module._ACL_CODEC._DIGEST = object()
        try:
            self.refused(module, lambda: module.build_request(
                outer_raw, evidence_raw, identity,
            ))
        finally:
            module._ACL_CODEC._DIGEST = original_digest_pattern

    def test_sibling_exceptions_and_post_call_drift_are_checked(self):
        module, _acl, _outer, _evidence, outer_raw, evidence_raw, identity = self.fixture()
        original_loads = module._ACL_CODEC.json.loads
        for primary in (RuntimeError("PRIVATE CODEC"), KeyboardInterrupt(), SystemExit(67)):
            def interrupt(*_args, primary=primary, **_kwargs):
                raise primary

            module._ACL_CODEC.json.loads = interrupt
            try:
                if isinstance(primary, Exception):
                    with self.assertRaisesRegex(
                            module.OrdinaryAccessRefused,
                            "^ordinary_principal_admission$") as raised:
                        module.build_request(outer_raw, evidence_raw, identity)
                    self.assertNotIn("PRIVATE", str(raised.exception))
                else:
                    with self.assertRaises(type(primary)) as raised:
                        module.build_request(outer_raw, evidence_raw, identity)
                    self.assertIs(raised.exception, primary)
            finally:
                module._ACL_CODEC.json.loads = original_loads

        identity_method = module._CurrentTokenIdentity._document_copy
        helper = module._ACL_CODEC._sid
        original_code = helper.__code__

        def mutate_after_callbacks(self):
            result = identity_method(self)
            helper.__code__ = (lambda _value: True).__code__
            return result

        module._CurrentTokenIdentity._document_copy = mutate_after_callbacks
        try:
            self.refused(module, lambda: module.build_request(
                outer_raw, evidence_raw, identity,
            ))
        finally:
            module._CurrentTokenIdentity._document_copy = identity_method
            helper.__code__ = original_code

    def test_known_vector_canonical_bytes_and_three_derived_digests(self):
        module, _acl, _outer, _evidence, outer_raw, evidence_raw, identity = self.fixture()
        raw = module._build_request_with_challenge(
            outer_raw, evidence_raw, identity, "1" * 64,
        )
        self.assertEqual(hashlib.sha256(outer_raw).hexdigest(),
                         "9391a515e92ce6cbf27d7424f543ca587b91cf7f9ddea093171b4be5737b55e8")
        self.assertEqual(hashlib.sha256(evidence_raw).hexdigest(),
                         "6d37addc609f77cfeac55b590a9c530e7589df80097618fb37059214d34e7f74")
        self.assertEqual(
            module.decode_request(raw).values()["aclDescriptorEvidenceDigest"],
            "d0fdf75957f2cf6b35add2b29431c203345d9c0afe6ce61f22269bb036de0334",
        )
        expected = (
            '{"aclDescriptorEvidenceDigest":"d0fdf75957f2cf6b35add2b29431c203345d9c0afe6ce61f22269bb036de0334",'
            '"aclEvidenceDigest":"6d37addc609f77cfeac55b590a9c530e7589df80097618fb37059214d34e7f74",'
            '"aclRequestDigest":"9391a515e92ce6cbf27d7424f543ca587b91cf7f9ddea093171b4be5737b55e8",'
            '"boundary":"anchor","challenge":"' + "1" * 64 + '",'
            '"configBinding":"' + "b" * 64 + '",'
            '"currentTokenIdentity":{"authenticationId":[4294967295,-2147483648],'
            '"modifiedId":[2,2147483647],"tokenId":[1,-1],"userSid":"S-1-5-21-1"},'
            '"leaseBinding":"aac3ad8f2b8230de6e0b9869b5700f31f004dc04baedd81e50e09a6e022250e7",'
            '"phase":"fresh","policyDigest":"a63c221764f73a54e87513fc91cded6b3fa16825138f6b24b6118132829f4eeb",'
            '"session":"checkout-' + "a" * 32 + '","targets":['
            '{"daclDigest":"' + "e" * 64 + '","desiredAccess":33554432,"fileId":"12",'
            '"ownerSid":"S-1-5-21-1","path":"c:/checkout/control","reparse":false,'
            '"role":"coordinator","volumeSerial":"11"},'
            '{"daclDigest":"' + "f" * 64 + '","desiredAccess":33554432,"fileId":"16",'
            '"ownerSid":"S-1-5-21-1","path":"c:/checkout/run","reparse":false,'
            '"role":"run","volumeSerial":"15"},'
            '{"daclDigest":"' + "0" * 64 + '","desiredAccess":33554432,"fileId":"18",'
            '"ownerSid":"S-1-5-21-1","path":"c:/checkout/run/source","reparse":false,'
            '"role":"source","volumeSerial":"17"}],"version":1}\n'
        ).encode("ascii")
        self.assertEqual(raw, expected)


if __name__ == "__main__":
    unittest.main()
