"""Pure tests for ADR-017 source-tree boundary binding."""

import copy
import importlib.util
import json
from pathlib import Path
import unittest
from unittest.mock import patch


HERE = Path(__file__).resolve().parent
MODULE_PATH = HERE / "checkout-acl-source-binding.py"
ACL_PATH = HERE / "checkout-acl-attestation.py"


def load_path(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def load_module():
    return load_path("checkout_acl_source_binding_tested", MODULE_PATH)


class CheckoutAclSourceBindingTests(unittest.TestCase):
    def fixture(self):
        module = load_module()
        acl = load_path("checkout_acl_source_binding_acl_fixture", ACL_PATH)
        anchor_request = {
            "version": 1,
            "boundary": "anchor",
            "phase": "fresh",
            "session": "checkout-" + "1" * 32,
            "configBinding": "2" * 64,
            "leaseBinding": acl.run_binding("c:/candidate"),
            "leaseIdentity": {
                "path": "c:/candidate/.checkout-coordinator.lease",
                "coordinator": {"volumeSerial": "50", "fileId": "60"},
                "descriptor": {"volumeSerial": "10", "fileId": "20"},
                "run": {"volumeSerial": "30", "fileId": "40"},
            },
            "policyDigest": acl.POLICY_DIGEST,
            "challenge": "3" * 64,
            "targets": [
                {"role": "coordinator", "path": "c:/coordinator"},
                {"role": "run", "path": "c:/candidate"},
                {"role": "source", "path": "c:/candidate/source"},
            ],
        }
        execution_request = copy.deepcopy(anchor_request)
        execution_request["boundary"] = "execution"
        execution_request["challenge"] = "4" * 64

        def evidence(request):
            identities = (("50", "60"), ("30", "40"), ("70", "80"))
            return {
                "version": 1,
                "requestDigest": acl.request_digest(request),
                "policyDigest": acl.POLICY_DIGEST,
                "leaseDigest": acl.lease_digest(
                    request["leaseBinding"], request["leaseIdentity"],
                ),
                "targets": [{
                    **target,
                    "volumeSerial": identity[0],
                    "fileId": identity[1],
                    "ownerSid": "S-1-5-21-1",
                    "daclDigest": character * 64,
                    "reparse": False,
                    "policySatisfied": True,
                } for target, identity, character in zip(
                    request["targets"], identities, ("a", "b", "c"),
                    strict=True,
                )],
            }

        anchor_evidence = evidence(anchor_request)
        execution_evidence = evidence(execution_request)
        manifest = {"app.php": "a" * 64}
        records = [{
            "relativePath": "app.php",
            "kind": "file",
            "volumeSerial": "71",
            "fileId": "81",
            "ownerSid": "S-1-5-21-1",
            "daclDigest": "b" * 64,
        }]
        return {
            "module": module,
            "acl": acl,
            "anchor_request": anchor_request,
            "execution_request": execution_request,
            "anchor_evidence": anchor_evidence,
            "execution_evidence": execution_evidence,
            "anchor_request_raw": acl.canonical_request(anchor_request),
            "execution_request_raw": acl.canonical_request(execution_request),
            "anchor_evidence_raw": acl.canonical_evidence(
                anchor_evidence, anchor_request,
            ),
            "execution_evidence_raw": acl.canonical_evidence(
                execution_evidence, execution_request,
            ),
            "manifest": manifest,
            "records": records,
        }

    def bind(self, fixture, **changes):
        values = {
            "anchor_request_raw": fixture["anchor_request_raw"],
            "anchor_evidence_raw": fixture["anchor_evidence_raw"],
            "anchor_manifest": copy.deepcopy(fixture["manifest"]),
            "anchor_records": copy.deepcopy(fixture["records"]),
            "execution_request_raw": fixture["execution_request_raw"],
            "execution_evidence_raw": fixture["execution_evidence_raw"],
            "execution_manifest": copy.deepcopy(fixture["manifest"]),
            "execution_records": copy.deepcopy(fixture["records"]),
        }
        values.update(changes)
        return fixture["module"].bind_source_boundaries(**values)

    def refused(self, module, callback):
        with self.assertRaisesRegex(
                module.SourceBindingRefused, "^acl_source_binding$"):
            callback()

    def test_known_vector_derives_source_identity_and_summary_from_inputs(self):
        fixture = self.fixture()
        result = self.bind(fixture)
        self.assertIs(type(result), fixture["module"]._SourceBindingSnapshot)
        self.assertEqual(result.values(), {
            "sourceRootIdentity": {"volumeSerial": "70", "fileId": "80"},
            "algorithm": "sha256-canonical-json-v2",
            "descendantCount": 1,
            "digest": "35b3c0038d100c58bdba90f86a0a8a48c7510c5185d0d13f8c0c62ee855ff794",
        })
        self.assertFalse(hasattr(fixture["module"], "bind_summary"))
        with self.assertRaises(TypeError):
            result.values()["digest"] = "0" * 64
        with self.assertRaises(TypeError):
            result.values()["sourceRootIdentity"]["fileId"] = "99"
        with self.assertRaises(fixture["module"].SourceBindingRefused):
            result.extra = "PRIVATE"

    def test_boundary_request_evidence_and_source_identity_drift_fail_closed(self):
        fixture = self.fixture()
        acl = fixture["acl"]
        cases = []
        for name, field, value in (
            ("boundary", "boundary", "anchor"),
            ("session", "session", "checkout-" + "9" * 32),
            ("config", "configBinding", "9" * 64),
            ("policy", "policyDigest", "9" * 64),
        ):
            request = copy.deepcopy(fixture["execution_request"])
            request[field] = value
            cases.append((name, acl._json_bytes(request), fixture["execution_evidence_raw"]))
        changed_evidence = copy.deepcopy(fixture["execution_evidence"])
        changed_evidence["targets"][2]["fileId"] = "99"
        cases.append((
            "source_identity", fixture["execution_request_raw"],
            acl._json_bytes(changed_evidence),
        ))
        changed_target = copy.deepcopy(fixture["execution_evidence"])
        changed_target["targets"][2]["path"] = "c:/candidate/other"
        cases.append((
            "source_target", fixture["execution_request_raw"],
            acl._json_bytes(changed_target),
        ))
        for name, execution_request_raw, execution_evidence_raw in cases:
            with self.subTest(name=name):
                self.refused(fixture["module"], lambda:
                             self.bind(
                                 fixture,
                                 execution_request_raw=execution_request_raw,
                                 execution_evidence_raw=execution_evidence_raw,
                             ))

    def test_manifest_content_record_and_inventory_drift_fail_closed(self):
        fixture = self.fixture()
        changed_manifest = copy.deepcopy(fixture["manifest"])
        changed_manifest["app.php"] = "f" * 64
        changed_record = copy.deepcopy(fixture["records"])
        changed_record[0]["daclDigest"] = "f" * 64
        cases = (
            ("content", {"execution_manifest": changed_manifest}),
            ("record", {"execution_records": changed_record}),
            ("count", {"execution_records": []}),
            ("manifest_type", {"anchor_manifest": []}),
            ("record_type", {"anchor_records": tuple(fixture["records"])}),
            ("digest_type", {"anchor_manifest": {"app.php": True}}),
        )
        for name, changes in cases:
            with self.subTest(name=name):
                self.refused(
                    fixture["module"], lambda changes=changes:
                    self.bind(fixture, **changes),
                )

    def test_descendant_cannot_reuse_derived_source_root_identity(self):
        fixture = self.fixture()
        aliased = copy.deepcopy(fixture["records"])
        aliased[0]["volumeSerial"] = "70"
        aliased[0]["fileId"] = "80"
        self.refused(
            fixture["module"],
            lambda: self.bind(
                fixture, anchor_records=aliased, execution_records=aliased,
            ),
        )

    def test_inputs_are_isolated_and_raw_bytes_are_strict(self):
        fixture = self.fixture()
        anchor_manifest = copy.deepcopy(fixture["manifest"])
        anchor_records = copy.deepcopy(fixture["records"])
        result = self.bind(
            fixture,
            anchor_manifest=anchor_manifest,
            anchor_records=anchor_records,
        )
        before = result.values()
        anchor_manifest["app.php"] = "f" * 64
        anchor_records[0]["fileId"] = "999"
        self.assertEqual(result.values(), before)
        for name, changed in (
            ("request_bom", {"anchor_request_raw": b"\xef\xbb\xbf" + fixture["anchor_request_raw"]}),
            ("request_trailing", {"anchor_request_raw": fixture["anchor_request_raw"] + b"\n"}),
            ("evidence_bom", {"anchor_evidence_raw": b"\xef\xbb\xbf" + fixture["anchor_evidence_raw"]}),
            ("evidence_trailing", {"anchor_evidence_raw": fixture["anchor_evidence_raw"] + b"\n"}),
            ("request_type", {"anchor_request_raw": bytearray(fixture["anchor_request_raw"])}),
        ):
            with self.subTest(name=name):
                self.refused(
                    fixture["module"],
                    lambda changed=changed: self.bind(fixture, **changed),
                )

    def test_sibling_authority_drift_is_checked_before_and_after_callbacks(self):
        fixture = self.fixture()
        module = fixture["module"]
        original = module._SOURCE_TREE.summarize
        module._SOURCE_TREE.summarize = lambda *_args: {
            "algorithm": "sha256-canonical-json-v2",
            "descendantCount": 1,
            "digest": "0" * 64,
        }
        try:
            self.refused(module, lambda: self.bind(fixture))
        finally:
            module._SOURCE_TREE.summarize = original

        helper = module._SOURCE_TREE._digest
        original_code = helper.__code__
        replacement_code = original_code.replace()
        self.assertIsNot(replacement_code, original_code)
        helper.__code__ = replacement_code
        try:
            self.refused(module, lambda: self.bind(fixture))
        finally:
            helper.__code__ = original_code

        module._SOURCE_TREE._RECORD_KEYS.add("PRIVATE")
        try:
            self.refused(module, lambda: self.bind(fixture))
        finally:
            module._SOURCE_TREE._RECORD_KEYS.remove("PRIVATE")

        original_roles = module._ACL_CODEC._TARGET_ROLES
        equal_roles = tuple(list(original_roles))
        self.assertIsNot(equal_roles, original_roles)
        module._ACL_CODEC._TARGET_ROLES = equal_roles
        try:
            self.refused(module, lambda: self.bind(fixture))
        finally:
            module._ACL_CODEC._TARGET_ROLES = original_roles

        original_required = module._required_siblings
        role_checks = 0

        def mutate_roles_after_precheck():
            nonlocal role_checks
            result = original_required()
            role_checks += 1
            if role_checks == 1:
                module._ACL_CODEC._TARGET_ROLES = tuple(list(original_roles))
            return result

        try:
            with patch.object(
                    module, "_required_siblings",
                    side_effect=mutate_roles_after_precheck):
                self.refused(
                    module,
                    lambda: module._sibling_call(
                        "acl", "sha256_bytes", b"source-binding",
                    ),
                )
        finally:
            module._ACL_CODEC._TARGET_ROLES = original_roles
        self.assertEqual(role_checks, 1)

        helper_checks = 0

        def mutate_helper_after_precheck():
            nonlocal helper_checks
            result = original_required()
            helper_checks += 1
            if helper_checks == 1:
                helper.__code__ = helper.__code__.replace()
            return result

        try:
            with patch.object(
                    module, "_required_siblings",
                    side_effect=mutate_helper_after_precheck):
                self.refused(
                    module,
                    lambda: module._sibling_call(
                        "source", "validate_summary", {
                            "algorithm": "sha256-canonical-json-v2",
                            "descendantCount": 1,
                            "digest": "a" * 64,
                        },
                    ),
                )
        finally:
            helper.__code__ = original_code
        self.assertEqual(helper_checks, 1)

    def test_sibling_dependency_callables_are_exact_and_checked_around_calls(self):
        shared = self.fixture()
        self.assertIs(shared["module"]._ACL_CODEC.json,
                      shared["module"]._SOURCE_TREE.json)

        dependency_cases = (
            ("acl_json_dumps", "_ACL_CODEC", "json", "dumps"),
            ("acl_json_loads", "_ACL_CODEC", "json", "loads"),
            ("acl_hash", "_ACL_CODEC", "hashlib", "sha256"),
            ("acl_match", "_ACL_CODEC", "re", "match"),
            ("source_json_dumps", "_SOURCE_TREE", "json", "dumps"),
            ("source_hash", "_SOURCE_TREE", "hashlib", "sha256"),
            ("source_copy", "_SOURCE_TREE", "copy", "deepcopy"),
        )
        for name, sibling_name, module_name, attribute_name in dependency_cases:
            with self.subTest(name=name):
                fixture = self.fixture()
                module = fixture["module"]
                dependency = getattr(getattr(module, sibling_name), module_name)
                original = getattr(dependency, attribute_name)

                def equal_behavior(*args, __original=original, **kwargs):
                    return __original(*args, **kwargs)

                setattr(dependency, attribute_name, equal_behavior)
                try:
                    self.refused(module, lambda: self.bind(fixture))
                finally:
                    setattr(dependency, attribute_name, original)

        fixture = self.fixture()
        module = fixture["module"]
        original_json_module = module._ACL_CODEC.json
        module._ACL_CODEC.json = module._SOURCE_TREE.copy
        try:
            self.refused(module, lambda: self.bind(fixture))
        finally:
            module._ACL_CODEC.json = original_json_module

        fixture = self.fixture()
        module = fixture["module"]
        original_code = module._ACL_CODEC.json.dumps.__code__
        replacement_code = original_code.replace()
        self.assertIsNot(replacement_code, original_code)
        module._ACL_CODEC.json.dumps.__code__ = replacement_code
        try:
            self.refused(module, lambda: self.bind(fixture))
        finally:
            module._ACL_CODEC.json.dumps.__code__ = original_code

        during_cases = (
            ("acl", "sha256_bytes", (b"source-binding",),
             "_ACL_CODEC", "hashlib", "sha256"),
            ("source", "validate_summary", ({
                "algorithm": "sha256-canonical-json-v2",
                "descendantCount": 1,
                "digest": "a" * 64,
            },), "_SOURCE_TREE", "copy", "deepcopy"),
        )
        for (which, call_name, args, sibling_name, dependency_name,
             attribute_name) in during_cases:
            with self.subTest(during=which):
                fixture = self.fixture()
                module = fixture["module"]
                dependency = getattr(
                    getattr(module, sibling_name), dependency_name,
                )
                original_attribute = getattr(dependency, attribute_name)
                original_required = module._required_siblings
                checks = 0
                dependency_calls = 0

                def replacement(*values, __original=original_attribute,
                                **keywords):
                    nonlocal dependency_calls
                    dependency_calls += 1
                    return __original(*values, **keywords)

                def mutate_after_precheck():
                    nonlocal checks
                    result = original_required()
                    checks += 1
                    if checks == 1:
                        setattr(dependency, attribute_name, replacement)
                    return result

                try:
                    with patch.object(
                            module, "_required_siblings",
                            side_effect=mutate_after_precheck):
                        self.refused(
                            module,
                            lambda: module._sibling_call(
                                which, call_name, *args,
                            ),
                        )
                finally:
                    setattr(dependency, attribute_name, original_attribute)
                self.assertEqual(checks, 1)
                self.assertEqual(dependency_calls, 1)

        fixture = self.fixture()
        module = fixture["module"]
        module._ACL_CODEC.json.source_binding_unrelated = object()
        try:
            self.bind(fixture)
        finally:
            del module._ACL_CODEC.json.source_binding_unrelated

    def test_ordinary_errors_are_redacted_and_baseexceptions_preserved(self):
        fixture = self.fixture()
        module = fixture["module"]
        for primary in (
            RuntimeError("PRIVATE source"), KeyboardInterrupt(), SystemExit(82),
        ):
            with patch.object(module, "_required_siblings", side_effect=primary):
                if isinstance(primary, Exception):
                    with self.assertRaisesRegex(
                            module.SourceBindingRefused,
                            "^acl_source_binding$") as raised:
                        self.bind(fixture)
                    self.assertNotIn("PRIVATE", str(raised.exception))
                else:
                    with self.assertRaises(type(primary)) as raised:
                        self.bind(fixture)
                    self.assertIs(raised.exception, primary)


if __name__ == "__main__":
    unittest.main()
