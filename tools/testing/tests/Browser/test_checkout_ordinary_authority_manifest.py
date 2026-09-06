import copy
import importlib.util
import json
from pathlib import Path
import unittest


MODULE_PATH = Path(__file__).with_name("checkout-ordinary-authority-manifest.py")
SPEC = importlib.util.spec_from_file_location("checkout_ordinary_authority_manifest", MODULE_PATH)
manifest = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(manifest)


def fixture():
    account = "S-1-5-21-111-222-333-1001"
    service = "S-1-5-80-1-2-3-4-5"
    checkout = "S-1-5-21-111-222-333-1002"
    binary = "c:/programdata/oncam/ordinary/broker.exe"
    coordinator = "c:/programdata/oncam/checkout"
    return {
        "account": {
            "accountSid": account,
            "allowedPrivilegeNames": ["SeChangeNotifyPrivilege", "SeImpersonatePrivilege"],
            "checkoutSid": checkout,
            "requiredPrivilegeNames": ["SeImpersonatePrivilege"],
            "runtimeTokenGroupPolicyDigest": "5" * 64,
            "runtimeTokenGroupPolicyVersion": 1,
            "serviceSid": service,
            "directSamMembershipSids": ["S-1-5-32-545"],
            "userRights": {
                "denied": ["SeDenyBatchLogonRight", "SeDenyInteractiveLogonRight",
                           "SeDenyNetworkLogonRight", "SeDenyRemoteInteractiveLogonRight"],
                "granted": ["SeServiceLogonRight"],
            },
        },
        "bindings": {
            "candidateDigest": "1" * 64,
            "coordinatorDigest": "2" * 64,
            "policyDigest": "a63c221764f73a54e87513fc91cded6b3fa16825138f6b24b6118132829f4eeb",
            "rootRelations": {
                "coordinatorRoot": coordinator,
                "runRoot": coordinator + "/runs",
                "sourceRelativePath": "source",
            },
        },
        "createdAt": "2026-09-06T01:02:03Z",
        "firewall": {
            "action": "block", "direction": "outbound", "enabled": True,
            "localAddresses": "any", "profiles": ["domain", "private", "public"],
            "program": binary, "protocol": "any", "remoteAddresses": "any",
            "ruleName": "Oncam Checkout Ordinary Broker Outbound Block",
            "serviceName": "OncamCheckoutOrdinaryBroker",
        },
        "generation": 1,
        "machine": {
            "architecture": "amd64", "identityDigest": "3" * 64,
            "minimumBuild": 17763, "osFamily": "windows",
        },
        "pipe": {
            "direction": "duplex", "firstPipeInstance": True,
            "handlesInheritable": False, "maxInstances": 1,
            "maxRequestBytes": 20 * 1024, "maxResponseBytes": 36 * 1024,
            "mode": "message", "name": "\\\\.\\pipe\\oncam-checkout-ordinary-v1",
            "rejectRemoteClients": True,
            "securityDescriptor": {
                "aces": [
                    {"rights": ["FILE_READ_DATA", "FILE_WRITE_DATA", "SYNCHRONIZE"],
                     "sid": checkout, "type": "allow"},
                    {"rights": ["FILE_READ_DATA", "FILE_WRITE_DATA", "SYNCHRONIZE"],
                     "sid": service, "type": "allow"},
                ],
                "ownerSid": account, "protected": True,
            },
            "timeoutMs": 5000,
        },
        "rotationState": "active",
        "service": {
            "binaryDigest": "4" * 64, "binaryPath": binary,
            "dynamicArgumentsAllowed": False, "dynamicEnvironmentAllowed": False,
            "interactive": False, "name": "OncamCheckoutOrdinaryBroker",
            "networkListener": False, "recoveryActions": [],
            "requiredPrivilegeNames": ["SeImpersonatePrivilege"],
            "serviceObjectDacl": {
                "aces": [
                    {"rights": ["SERVICE_ALL_ACCESS"], "sid": "S-1-5-18", "type": "allow"},
                    {"rights": ["SERVICE_ALL_ACCESS"], "sid": "S-1-5-32-544", "type": "allow"},
                    {"rights": ["SERVICE_QUERY_STATUS"], "sid": checkout, "type": "allow"},
                ],
                "ownerSid": "S-1-5-18", "protected": True,
            },
            "serviceSidType": "unrestricted", "singleInstance": True,
            "startPolicy": "demand", "type": "SERVICE_WIN32_OWN_PROCESS",
        },
        "version": 1,
    }


def canonical(value):
    return (json.dumps(value, sort_keys=True, separators=(",", ":"),
                       ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")


class AuthorityManifestTest(unittest.TestCase):
    def assert_refused(self, value):
        with self.assertRaisesRegex(manifest.OrdinaryAuthorityRefused,
                                    "^ordinary_authority_manifest$"):
            manifest.decode(canonical(value) if type(value) is dict else value)

    def test_known_vector_and_immutable_projection(self):
        raw = canonical(fixture())
        result = manifest.decode(raw)
        self.assertEqual(raw, manifest.canonical(fixture()))
        self.assertEqual(result.digest, "f5aad34ef55d0339e039897f72f7f39c6f7fbfc332d2877b47b181de008c3129")
        self.assertEqual(result.generation, 1)
        self.assertEqual(result.accountSid, fixture()["account"]["accountSid"])
        self.assertEqual(result.binaryPath, fixture()["service"]["binaryPath"])
        self.assertEqual(result.rotationState, "active")
        with self.assertRaises((AttributeError, TypeError)):
            result.generation = 2

    def test_canonical_json_boundary_is_exact_and_bounded(self):
        value = fixture()
        variants = [canonical(value)[:-1], canonical(value) + b"\n", b"\xef\xbb\xbf" + canonical(value),
                    json.dumps(value).encode(), b'{"x":1,"x":2}\n', b'{"x":NaN}\n',
                    b"{" + b" " * (manifest.MAX_MANIFEST_BYTES + 1) + b"}\n", "not-bytes"]
        for variant in variants:
            with self.subTest(variant=repr(variant)[:40]):
                self.assert_refused(variant)

    def test_static_schema_rejects_dynamic_or_secret_fields(self):
        for key in ("username", "password", "domain", "credential", "logonSid",
                    "authenticationId", "tokenId", "modifiedId", "servicePid",
                    "processCreationTime", "manifestPath"):
            value = fixture()
            value[key] = "forbidden"
            with self.subTest(key=key):
                self.assert_refused(value)
        value = fixture(); value["version"] = True; self.assert_refused(value)
        value = fixture(); value["generation"] = True; self.assert_refused(value)
        value = fixture(); value["machine"]["minimumBuild"] = True; self.assert_refused(value)
        value = fixture(); value["pipe"]["maxInstances"] = True; self.assert_refused(value)

    def test_account_sid_membership_privilege_and_rights_policy_is_exact(self):
        mutations = []
        for field, bad in (("accountSid", "S-1-5-18"), ("serviceSid", "S-1-5-18"),
                           ("checkoutSid", "S-1-5-18"),
                           ("directSamMembershipSids", ["S-1-5-32-544"]),
                           ("allowedPrivilegeNames", ["SeImpersonatePrivilege"]),
                           ("requiredPrivilegeNames", ["SeDebugPrivilege"])):
            value = fixture(); value["account"][field] = bad; mutations.append(value)
        value = fixture(); value["account"]["userRights"]["granted"].append("SeNetworkLogonRight"); mutations.append(value)
        value = fixture(); value["account"]["userRights"]["denied"].pop(); mutations.append(value)
        value = fixture(); value["account"]["serviceSid"] = value["account"]["accountSid"]; mutations.append(value)
        for value in mutations: self.assert_refused(value)

    def test_service_paths_digests_type_and_dacl_are_exact(self):
        changes = (("binaryPath", "C:\\secret\\broker.exe"), ("binaryDigest", "A" * 64),
                   ("type", "SERVICE_WIN32_SHARE_PROCESS"), ("startPolicy", "auto"),
                   ("serviceSidType", "restricted"), ("interactive", True),
                   ("networkListener", True), ("dynamicArgumentsAllowed", True))
        for field, bad in changes:
            value = fixture(); value["service"][field] = bad; self.assert_refused(value)
        value = fixture(); value["service"]["serviceObjectDacl"]["aces"][2]["rights"] = ["SERVICE_START"]; self.assert_refused(value)
        value = fixture(); value["service"]["serviceObjectDacl"]["protected"] = False; self.assert_refused(value)

    def test_pipe_contract_and_security_descriptor_are_exact(self):
        for field, bad in (("name", "local/dynamic"), ("direction", "inbound"),
                           ("mode", "byte"), ("firstPipeInstance", False),
                           ("rejectRemoteClients", False), ("handlesInheritable", True),
                           ("maxInstances", 2), ("maxRequestBytes", 0), ("timeoutMs", True)):
            value = fixture(); value["pipe"][field] = bad; self.assert_refused(value)
        value = fixture(); value["pipe"]["securityDescriptor"]["aces"].append(
            {"rights": ["FILE_READ_DATA"], "sid": "S-1-1-0", "type": "allow"}); self.assert_refused(value)
        value = fixture(); value["pipe"]["securityDescriptor"]["ownerSid"] = "S-1-5-18"; self.assert_refused(value)
        for field, bad in (("maxRequestBytes", 20 * 1024 - 1),
                           ("maxRequestBytes", 20 * 1024 + 1),
                           ("maxResponseBytes", 36 * 1024 - 1),
                           ("maxResponseBytes", 36 * 1024 + 1)):
            value = fixture(); value["pipe"][field] = bad; self.assert_refused(value)

    def test_firewall_and_binding_relations_are_exact(self):
        for field, bad in (("enabled", False), ("direction", "inbound"), ("action", "allow"),
                           ("protocol", "tcp"), ("profiles", ["private"])):
            value = fixture(); value["firewall"][field] = bad; self.assert_refused(value)
        value = fixture(); value["firewall"]["program"] += ".old"; self.assert_refused(value)
        value = fixture(); value["bindings"]["policyDigest"] = "0" * 64; self.assert_refused(value)
        value = fixture(); value["bindings"]["rootRelations"]["runRoot"] = "c:/elsewhere/runs"; self.assert_refused(value)

    def test_rotation_timestamp_and_machine_contract(self):
        for path, bad in (("rotationState", "revoked"), ("createdAt", "2026-02-30T00:00:00Z"),
                          ("generation", 0)):
            value = fixture(); value[path] = bad; self.assert_refused(value)
        for field, bad in (("osFamily", "linux"), ("architecture", "x86"),
                           ("minimumBuild", 0), ("identityDigest", "x" * 64)):
            value = fixture(); value["machine"][field] = bad; self.assert_refused(value)

    def test_input_mutation_isolated_and_exception_hygiene(self):
        value = fixture(); raw = canonical(value); result = manifest.decode(raw)
        value["account"]["directSamMembershipSids"].append("S-1-5-32-544")
        self.assertEqual(result.accountSid, "S-1-5-21-111-222-333-1001")
        original = manifest.json.loads
        try:
            manifest.json.loads = lambda *args, **kwargs: (_ for _ in ()).throw(RuntimeError("secret"))
            with self.assertRaisesRegex(manifest.OrdinaryAuthorityRefused, "^ordinary_authority_manifest$"):
                manifest.decode(raw)
        finally:
            manifest.json.loads = original
        for exception in (KeyboardInterrupt("k"), SystemExit("s")):
            original = manifest._require_authority
            try:
                def boom(*args, **kwargs): raise exception
                manifest._require_authority = boom
                with self.assertRaises(type(exception)) as raised: manifest.decode(raw)
                self.assertIs(raised.exception, exception)
            finally:
                manifest._require_authority = original

    def test_direct_stdlib_authority_replacement_refuses(self):
        raw = canonical(fixture())
        for module, name, replacement in (
                (manifest.json, "loads", lambda *a, **k: fixture()),
                (manifest.json, "dumps", lambda *a, **k: "{}"),
                (manifest.hashlib, "sha256", lambda value: None),
                (manifest.re, "fullmatch", lambda *a, **k: None)):
            original = getattr(module, name)
            try:
                setattr(module, name, replacement)
                with self.assertRaisesRegex(manifest.OrdinaryAuthorityRefused,
                                            "^ordinary_authority_manifest$"):
                    manifest.decode(raw)
            finally:
                setattr(module, name, original)

    def test_mutable_and_rebound_policy_authority_refuses(self):
        raw = canonical(fixture())
        original = manifest._PRIVILEGES
        try:
            manifest._PRIVILEGES.append("SeDebugPrivilege")
            value = fixture()
            value["account"]["allowedPrivilegeNames"].append("SeDebugPrivilege")
            self.assert_refused(value)
        finally:
            manifest._PRIVILEGES[:] = original[:2]
        manifest.re.purge()
        equal_regex = manifest.re.compile(manifest._DIGEST.pattern, manifest._DIGEST.flags)
        self.assertIsNot(equal_regex, manifest._DIGEST)
        for name, replacement in (
                ("_PRIVILEGES", tuple(manifest._PRIVILEGES)),
                ("_TOP", set(manifest._TOP)),
                ("_DIGEST", equal_regex),
                ("POLICY_DIGEST", "0" * 64),
                ("_JSON_LOADS", lambda *a, **k: fixture()),
                ("_DEPENDENCIES", tuple(list(manifest._DEPENDENCIES)))):
            original = getattr(manifest, name)
            try:
                setattr(manifest, name, replacement)
                with self.subTest(authority=name):
                    with self.assertRaisesRegex(manifest.OrdinaryAuthorityRefused,
                                                "^ordinary_authority_manifest$"):
                        manifest.decode(raw)
            finally:
                setattr(manifest, name, original)

    def test_runtime_group_policy_is_static_binding_not_dynamic_evidence(self):
        result = manifest.decode(canonical(fixture()))
        self.assertEqual(result.runtimeTokenGroupPolicyVersion, 1)
        self.assertEqual(result.runtimeTokenGroupPolicyDigest, "5" * 64)
        for field, bad in (("runtimeTokenGroupPolicyVersion", True),
                           ("runtimeTokenGroupPolicyVersion", 2),
                           ("runtimeTokenGroupPolicyDigest", "A" * 64)):
            value = fixture(); value["account"][field] = bad; self.assert_refused(value)
        value = fixture(); value["account"]["tokenGroups"] = []; self.assert_refused(value)

    def test_authority_cannot_be_resealed_after_policy_mutation(self):
        raw = canonical(fixture())
        original = manifest._PRIVILEGES
        try:
            manifest._PRIVILEGES.append("SeDebugPrivilege")
            self.assertFalse(hasattr(manifest, "_seal_authority"))
            for name in ("_GLOBAL_AUTHORITY", "_FUNCTION_AUTHORITY"):
                self.assertFalse(hasattr(manifest, name))
            value = fixture()
            value["account"]["allowedPrivilegeNames"].append("SeDebugPrivilege")
            self.assert_refused(value)
            with self.assertRaisesRegex(manifest.OrdinaryAuthorityRefused,
                                        "^ordinary_authority_manifest$"):
                manifest.decode(raw)
        finally:
            manifest._PRIVILEGES[:] = original[:2]

    def test_refusal_class_rebinding_cannot_leak_internal_exception(self):
        original = manifest.OrdinaryAuthorityRefused
        try:
            manifest.OrdinaryAuthorityRefused = Exception
            with self.assertRaises(original) as raised:
                manifest.canonical({})
            self.assertEqual(str(raised.exception), "ordinary_authority_manifest")
            self.assertIsNone(raised.exception.__cause__)
        finally:
            manifest.OrdinaryAuthorityRefused = original


if __name__ == "__main__":
    unittest.main()
