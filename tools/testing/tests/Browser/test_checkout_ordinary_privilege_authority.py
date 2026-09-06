import ast
import copy
import hashlib
import importlib.util
import inspect
import json
import pickle
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
MODULE_PATH = HERE / "checkout-ordinary-privilege-authority.py"


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class Capture:
    def __init__(self, *responses):
        responses = tuple(
            ("raise", value) if isinstance(value, BaseException) else value
            for value in responses
        )
        self.responses = responses if len(responses) == 2 else responses * 2
        self.wrapped = None
        self.module = None

    @property
    def calls(self):
        return self.module._structural_fixture_capture_count_only(self.wrapped)
def fixture():
    mt = load("pa_mt", HERE / "test_checkout_ordinary_authority_manifest.py")
    mc = load("pa_mc", HERE / "checkout-ordinary-authority-manifest.py")
    sc = load("pa_sc", HERE / "checkout-ordinary-broker-start-identity.py")
    rt = load("pa_rt", HERE / "test_checkout_ordinary_access_request.py")
    manifest_raw = mc.canonical(mt.fixture())
    manifest = mc.decode(manifest_raw)
    start_raw = sc.canonical({
        "manifestDigest": manifest.digest,
        "servicePid": 42,
        "processCreationTime": "123",
        "accountSid": manifest.accountSid,
        "serviceSid": manifest.serviceSid,
        "authenticationId": [1, -1],
        "tokenId": [2, 3],
    })
    rm, acl, outer_value, evidence_value, outer_raw, _, _ = (
        rt.CheckoutOrdinaryAccessRequestTests().fixture()
    )
    for target in evidence_value["targets"]:
        target["ownerSid"] = manifest.checkoutSid
    evidence_raw = acl.canonical_evidence(evidence_value, outer_value)
    identity = rm._current_token_identity(
        manifest.checkoutSid, (1, -1), (0xFFFFFFFF, -0x80000000),
        (2, 0x7FFFFFFF),
    )
    request_raw = rm._build_request_with_challenge(
        outer_raw, evidence_raw, identity, "1" * 64
    )
    module=load("ordinary_privilege_authority_tested",MODULE_PATH)
    start = module._START.decode(start_raw)
    request = module._REQUEST.decode_request(request_raw).values()
    current = request["currentTokenIdentity"]
    live = module._LiveBinding(
        manifest.digest,
        manifest.machineIdentityDigest,
        manifest.accountSid,
        manifest.serviceSid,
        start.digest,
        start.servicePid,
        start.processCreationTime,
        start.authenticationId,
        start.tokenId,
        hashlib.sha256(request_raw).hexdigest(),
        request["boundary"],
        request["phase"],
        request["session"],
        request["configBinding"],
        request["leaseBinding"],
        request["policyDigest"],
        request["aclRequestDigest"],
        request["aclEvidenceDigest"],
        request["aclDescriptorEvidenceDigest"],
        request["challenge"],
        (
            current["userSid"],
            current["tokenId"],
            current["authenticationId"],
            current["modifiedId"],
        ),
    )
    entries = (
        ("SeBackupPrivilege", 1, -1, True, False, True, False),
        ("SeRestorePrivilege", 2, 0, False, False, False, False),
        ("SeTakeOwnershipPrivilege", 3, 1, True, False, True, False),
    )
    observation = module._PrivilegeObservation(entries, live)
    raw = dict(
        manifest_raw=manifest_raw,
        broker_start_raw=start_raw,
        request_raw=request_raw,
        machine_identity_digest=manifest.machineIdentityDigest,
    )
    return module, raw, live, entries, observation


def bind(module, raw, capability):
    capability.module = module
    capability.wrapped = module._structural_fixture_capability_only(
        *capability.responses
    )
    return module._bind_structural_authority(
        **raw, capture_capability=capability.wrapped
    )


class PrivilegeAuthorityTests(unittest.TestCase):
    def refused(self, module, operation):
        with self.assertRaisesRegex(
            module.OrdinaryPrivilegeAuthorityRefused,
            "^ordinary_privilege_authority$",
        ):
            operation()

    def test_exact_surface_and_keyword_only_seam(self):
        m, raw, _, _, obs = fixture()
        self.assertEqual(m.__all__, ("OrdinaryPrivilegeAuthorityRefused",))
        self.assertFalse(hasattr(m, "_construct"))
        sig = inspect.signature(m._bind_structural_authority)
        self.assertEqual(tuple(sig.parameters), (
            "manifest_raw", "broker_start_raw", "request_raw",
            "machine_identity_digest", "capture_capability",
        ))
        self.assertTrue(all(
            parameter.kind is inspect.Parameter.KEYWORD_ONLY
            and parameter.default is inspect.Parameter.empty
            for parameter in sig.parameters.values()
        ))
        with self.assertRaises(TypeError):
            m._bind_structural_authority(*raw.values(), Capture(obs))

    def test_initial_none_and_final_exact_marker(self):
        m, raw, _, _, obs = fixture()
        cap = Capture(obs, obs)
        authority = bind(m, raw, cap)
        self.assertEqual(
            [x for x in dir(authority) if not x.startswith("_")],
            ["final", "initial"],
        )
        self.assertIsNone(authority.initial())
        result = authority.final()
        request = m._REQUEST.decode_request(raw["request_raw"]).values()
        self.assertEqual(
            (result.structuralOnly, result.phase, result.boundary, result.session),
            (True, request["phase"], request["boundary"], request["session"]),
        )
        self.assertEqual(len(result.bindingDigest), 64)
        self.assertFalse(hasattr(result, "captureDigest"))
        self.assertFalse(hasattr(result, "admitted"))
        self.refused(m, lambda: cap.calls)
        with self.assertRaises(AttributeError):
            result.session = "x"
    def test_enabled_and_presence_contracts_exhaust(self):
        m, raw, live, entries, _ = fixture()
        for row in range(3):
            for column in (4,6):
                changed = [list(x) for x in entries]
                changed[row][column] = True
                observation = m._PrivilegeObservation(tuple(map(tuple, changed)), live)
                authority = bind(m, raw, Capture(observation))
                self.refused(m, authority.initial)
                self.refused(m, authority.final)
        changed = [list(x) for x in entries]
        changed[0][5] = False
        observation = m._PrivilegeObservation(tuple(map(tuple, changed)), live)
        self.refused(m, bind(m, raw, Capture(observation)).initial)
    def test_order_types_live_binding_and_final_drift(self):
        m, raw, live, entries, obs = fixture()
        for value in (entries[::-1],entries[:2]+((entries[2][0],True,1,True,False,True,False),)):
            self.refused(m,bind(m,raw,Capture(m._PrivilegeObservation(value,live))).initial)
        values = list(live)
        values[0] = "f" * 64
        bad_live = m._PrivilegeObservation(entries, type(live)(*values))
        self.refused(m, bind(m, raw, Capture(bad_live)).initial)
        drift = list(entries)
        drift[1] = (drift[1][0], 99, 0, False, False, False, False)
        authority = bind(
            m, raw, Capture(obs, m._PrivilegeObservation(tuple(drift), live))
        )
        self.assertIsNone(authority.initial())
        self.refused(m, authority.final)
        self.refused(m, authority.final)
    def test_all_supplied_bindings_and_cross_binding(self):
        m, raw, _, _, obs = fixture()
        cases = []
        changed = dict(raw)
        changed["machine_identity_digest"] = "0" * 64
        cases.append(changed)
        start = json.loads(raw["broker_start_raw"])
        start["manifestDigest"] = "0" * 64
        changed = dict(raw)
        changed["broker_start_raw"] = m._START.canonical(start)
        cases.append(changed)
        for field, value in (
            ("accountSid", "S-1-5-21-9"),
            ("serviceSid", "S-1-5-80-9-8-7-6-5"),
        ):
            start = json.loads(raw["broker_start_raw"])
            start[field] = value
            changed = dict(raw)
            changed["broker_start_raw"] = m._START.canonical(start)
            cases.append(changed)
        manifest = json.loads(raw["manifest_raw"])
        manifest["bindings"]["policyDigest"] = "0" * 64
        changed = dict(raw)
        changed["manifest_raw"] = (
            json.dumps(
                manifest,
                sort_keys=True,
                separators=(",", ":"),
                ensure_ascii=True,
            )
            + "\n"
        ).encode("ascii")
        cases.append(changed)
        for key in ("manifest_raw","broker_start_raw","request_raw"):
            changed = dict(raw)
            changed[key] += b" "
            cases.append(changed)
        for changed in cases:
            self.refused(
                m, lambda changed=changed: bind(m, changed, Capture(obs))
            )

    def test_dynamic_start_and_request_fields_are_bound_to_both_captures(self):
        m, raw, _, _, observation = fixture()
        start_mutations = {
            "servicePid": 43,
            "processCreationTime": "124",
            "authenticationId": [9, -9],
            "tokenId": [8, 7],
        }
        for field, value in start_mutations.items():
            start = json.loads(raw["broker_start_raw"])
            start[field] = value
            changed = dict(raw)
            changed["broker_start_raw"] = m._START.canonical(start)
            authority = bind(m, changed, Capture(observation))
            self.refused(m, authority.initial)
        request_mutations = {
            "boundary": "execution",
            "phase": "recovery",
            "session": "checkout-" + "9" * 32,
            "configBinding": "8" * 64,
            "aclRequestDigest": "7" * 64,
            "aclEvidenceDigest": "6" * 64,
            "aclDescriptorEvidenceDigest": "5" * 64,
            "challenge": "4" * 64,
        }
        for field, value in request_mutations.items():
            request = json.loads(raw["request_raw"])
            request[field] = value
            changed = dict(raw)
            changed["request_raw"] = (
                json.dumps(
                    request,
                    sort_keys=True,
                    separators=(",", ":"),
                    ensure_ascii=True,
                )
                + "\n"
            ).encode("ascii")
            authority = bind(m, changed, Capture(observation))
            self.refused(m, authority.initial)
        request = json.loads(raw["request_raw"])
        request["currentTokenIdentity"]["userSid"] = "S-1-5-21-9"
        for target in request["targets"]:
            target["ownerSid"] = "S-1-5-21-9"
        changed = dict(raw)
        changed["request_raw"] = (
            json.dumps(
                request,
                sort_keys=True,
                separators=(",", ":"),
                ensure_ascii=True,
            )
            + "\n"
        ).encode("ascii")
        self.refused(m, lambda: bind(m, changed, Capture(observation)))

    def test_transitions_failures_and_baseexception_exhaust(self):
        m, raw, _, _, obs = fixture()
        capability = Capture(obs, obs)
        authority = bind(m, raw, capability)
        self.refused(m, authority.final)
        self.refused(m, lambda: capability.calls)
        self.refused(m, authority.initial)
        capability = Capture(obs, obs)
        authority = bind(m, raw, capability)
        authority.initial()
        self.assertEqual(capability.calls, 1)
        self.refused(m, authority.initial)
        self.refused(m, authority.final)
        for failure in (
            RuntimeError("secret"),
            KeyboardInterrupt("stop"),
            SystemExit(9),
        ):
            authority = bind(m, raw, Capture(failure))
            if isinstance(failure, RuntimeError):
                self.refused(m, authority.initial)
            else:
                with self.assertRaises(type(failure)) as caught:
                    authority.initial()
                self.assertIs(caught.exception, failure)
            self.refused(m, authority.initial)

    def test_private_capability_is_single_owner_and_cannot_be_reused(self):
        m, raw, _, _, observation = fixture()
        capability = m._structural_fixture_capability_only(
            observation, observation
        )
        first = m._bind_structural_authority(
            **raw, capture_capability=capability
        )
        self.refused(
            m,
            lambda: m._bind_structural_authority(
                **raw, capture_capability=capability
            ),
        )
        self.assertIsNone(first.initial())
        self.assertTrue(first.final().structuralOnly)

    def test_copy_pickle_forgery_refused(self):
        m, raw, _, _, obs = fixture()
        for operation in (copy.copy, copy.deepcopy, pickle.dumps):
            self.refused(
                m,
                lambda operation=operation: operation(
                    bind(m, raw, Capture(obs, obs))
                ),
            )
        authority = bind(m, raw, Capture(obs, obs))
        authority.initial()
        result = authority.final()
        marker_text = repr(result)
        self.assertNotIn("SeBackupPrivilege", marker_text)
        self.assertNotIn("S-1-", marker_text)
        self.assertNotIn("c:/", marker_text)
        for operation in (copy.copy, copy.deepcopy, pickle.dumps):
            self.refused(m, lambda operation=operation: operation(result))
        self.refused(
            m, lambda: type(result)(object(), True, "a", "b", "c", "d")
        )
        forged = tuple.__new__(type(result), ())
        self.refused(m, lambda: forged.bindingDigest)

    def test_object_setattr_cannot_rewrite_state_binding_or_replay(self):
        m, raw, _, _, observation = fixture()
        capability = Capture(observation, observation)
        authority = bind(m, raw, capability)
        for name, value in (
            ("_PrivilegeLuidAuthority__state", 1),
            ("_PrivilegeLuidAuthority__binding", "f" * 64),
        ):
            with self.assertRaises(AttributeError):
                object.__setattr__(authority, name, value)
        self.assertIsNone(authority.initial())
        marker = authority.final()
        original_binding = marker.bindingDigest
        with self.assertRaises(AttributeError):
            object.__setattr__(
                marker,
                "_StructuralPrivilegeBinding__binding",
                "f" * 64,
            )
        self.assertEqual(marker.bindingDigest, original_binding)
        with self.assertRaises(AttributeError):
            object.__setattr__(authority, "_PrivilegeLuidAuthority__state", 1)
        self.refused(m, authority.final)

    def test_capability_module_and_type_drift(self):
        m, raw, _, _, obs = fixture()
        cap = Capture(obs, obs)
        authority = bind(m, raw, cap)
        with self.assertRaises(AttributeError):
            object.__setattr__(cap.wrapped, "_CaptureCapability__index", 1)
        self.assertIsNone(authority.initial())
        m, raw, _, _, obs = fixture()
        authority = bind(m, raw, Capture(obs, obs))
        original = m._MANIFEST.decode.__code__
        m._MANIFEST.decode.__code__ = original.replace()
        try:
            self.refused(m, authority.initial)
        finally:
            m._MANIFEST.decode.__code__ = original
        m, raw, _, _, obs = fixture()
        original = m._PrivilegeObservation
        m._PrivilegeObservation = tuple
        try:
            self.refused(m, lambda: bind(m, raw, Capture(obs)))
        finally:
            m._PrivilegeObservation = original
        m, raw, _, _, obs = fixture()
        authority = bind(m, raw, Capture(obs, obs))
        original_code = m._PrivilegeLuidAuthority.initial.__code__
        m._PrivilegeLuidAuthority.initial.__code__ = original_code.replace()
        try:
            self.refused(m, authority.initial)
        finally:
            m._PrivilegeLuidAuthority.initial.__code__ = original_code

    def test_transitive_decoder_helper_replacement_refuses(self):
        m, raw, _, _, observation = fixture()
        replacements = (
            (m._REQUEST, "_decode", lambda value: value),
            (m._MANIFEST, "_strict_object", lambda pairs: dict(pairs)),
        )
        for module, name, replacement in replacements:
            original = getattr(module, name)
            setattr(module, name, replacement)
            try:
                self.refused(
                    m, lambda: bind(m, raw, Capture(observation))
                )
            finally:
                setattr(module, name, original)
        start_helper = next(
            cell.cell_contents
            for cell in m._START.decode.__closure__
            if callable(cell.cell_contents)
            and getattr(cell.cell_contents, "__name__", "") == "decode_impl"
        )
        original_code = start_helper.__code__
        start_helper.__code__ = original_code.replace()
        try:
            self.refused(m, lambda: bind(m, raw, Capture(observation)))
        finally:
            start_helper.__code__ = original_code

    def test_hostile_repr_is_never_invoked_and_post_capture_state_is_pinned(self):
        class Hostile:
            calls = 0

            def __repr__(self):
                type(self).calls += 1
                raise AssertionError("repr called")

        m, raw, _, _, obs = fixture()
        self.refused(
            m,
            lambda: m._structural_fixture_capability_only(
                Hostile(), Hostile()
            ),
        )
        self.assertEqual(Hostile.calls, 0)
        capability = Capture(obs, obs)
        authority = bind(m, raw, capability)
        self.assertIsNone(authority.initial())
        with self.assertRaises(AttributeError):
            object.__setattr__(
                capability.wrapped,
                "_CaptureCapability__responses",
                tuple(list(capability.responses)),
            )
        self.assertTrue(authority.final().structuralOnly)
    def test_static_surface(self):
        source = MODULE_PATH.read_text(encoding="utf-8")
        tree = ast.parse(source)
        imports = {
            alias.name.split(".")[0]
            for node in ast.walk(tree)
            if isinstance(node, ast.Import)
            for alias in node.names
        } | {
            node.module.split(".")[0]
            for node in ast.walk(tree)
            if isinstance(node, ast.ImportFrom) and node.module
        }
        self.assertFalse({"ctypes", "os", "socket", "subprocess"} & imports)
        self.assertFalse(hasattr(load("pa_surface", MODULE_PATH),
                                 "_validated_live_binding"))
        for name in ("attest", "load", "discard", "provider", "transport"):
            self.assertNotIn("def " + name, source)


if __name__ == "__main__":
    unittest.main()
