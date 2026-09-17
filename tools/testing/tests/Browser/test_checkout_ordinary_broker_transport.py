import ast
import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import unittest


HERE = Path(__file__).resolve().parent
MODULE_PATH = HERE / "checkout-ordinary-broker-transport.py"
ORDINARY_PATH = HERE / "checkout-ordinary-access-request.py"
ACL_PATH = HERE / "checkout-acl-attestation.py"


def load_path(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def load_module():
    return load_path("checkout_ordinary_broker_transport_tested", MODULE_PATH)


class CheckoutOrdinaryBrokerTransportTests(unittest.TestCase):
    maxDiff = None

    manifest = "1" * 64
    start = "2" * 64

    def fixture(self):
        module = load_module()
        ordinary = load_path("ordinary_transport_request_fixture", ORDINARY_PATH)
        acl = load_path("ordinary_transport_acl_fixture", ACL_PATH)
        outer = {
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
            "policyDigest": ordinary.POLICY_DIGEST, "challenge": "d" * 64,
            "targets": [
                {"role": "coordinator", "path": "c:/checkout/control"},
                {"role": "run", "path": "c:/checkout/run"},
                {"role": "source", "path": "c:/checkout/run/source"},
            ],
        }
        outer_evidence = {
            "version": 1, "requestDigest": acl.request_digest(outer),
            "policyDigest": ordinary.POLICY_DIGEST,
            "leaseDigest": acl.lease_digest(
                outer["leaseBinding"], outer["leaseIdentity"],
            ),
            "targets": [],
        }
        for target, identity, digest in zip(
                outer["targets"], (("11", "12"), ("15", "16"), ("17", "18")),
                ("e" * 64, "f" * 64, "0" * 64), strict=True):
            outer_evidence["targets"].append({
                **target, "volumeSerial": identity[0], "fileId": identity[1],
                "ownerSid": "S-1-5-21-1", "daclDigest": digest,
                "reparse": False, "policySatisfied": True,
            })
        identity = ordinary._current_token_identity(
            "S-1-5-21-1", (1, -1), (0xFFFFFFFF, -0x80000000),
            (2, 0x7FFFFFFF),
        )
        request_raw = ordinary._build_request_with_challenge(
            acl.canonical_request(outer),
            acl.canonical_evidence(outer_evidence, outer), identity, "3" * 64,
        )
        return module, request_raw, b"{}\n"

    @staticmethod
    def json_bytes(value):
        return (json.dumps(
            value, sort_keys=True, separators=(",", ":"), ensure_ascii=True,
            allow_nan=False,
        ) + "\n").encode("ascii")

    def refused(self, module, callback):
        with self.assertRaisesRegex(
                module.BrokerTransportRefused, "^broker_transport$"):
            callback()

    def test_module_is_pure_codec_with_fixed_surface(self):
        module = load_module()
        tree = ast.parse(MODULE_PATH.read_text(encoding="utf-8"))
        imports = {
            alias.name.split(".", 1)[0]
            for node in ast.walk(tree) if isinstance(node, ast.Import)
            for alias in node.names
        }
        imports.update(
            node.module.split(".", 1)[0] for node in ast.walk(tree)
            if isinstance(node, ast.ImportFrom) and node.module
        )
        self.assertTrue(imports <= {
            "copy", "hashlib", "importlib", "json", "pathlib", "re",
            "types", "__future__",
        })
        forbidden = {
            "ctypes", "os", "socket", "subprocess", "winreg", "sqlite3",
        }
        self.assertTrue(imports.isdisjoint(forbidden))
        public = {
            name for name, value in module.__dict__.items()
            if callable(value) and not name.startswith("_")
        }
        self.assertEqual(public, {
            "BrokerTransportRefused", "canonical_request", "decode_request",
            "canonical_response", "decode_response",
        })
        self.assertFalse(any(
            isinstance(node, (ast.With, ast.AsyncWith)) for node in ast.walk(tree)
        ))

    def test_exact_request_methods_are_canonical_bound_and_immutable(self):
        module, request_raw, _evidence_raw = self.fixture()
        digest = hashlib.sha256(request_raw).hexdigest()
        cases = (
            ("attest", request_raw), ("load", digest), ("discard", digest),
        )
        for method, payload in cases:
            with self.subTest(method=method):
                raw = module.canonical_request(
                    method, self.manifest, self.start, payload,
                )
                snapshot = module.decode_request(raw, self.manifest, self.start)
                values = snapshot.values()
                self.assertEqual(values["transportVersion"], 1)
                self.assertEqual(values["method"], method)
                self.assertEqual(values["manifestDigest"], self.manifest)
                self.assertEqual(values["brokerStartIdentity"], self.start)
                self.assertEqual(values["requestDigest"], digest)
                self.assertEqual(snapshot.bytes(), raw)
                self.assertEqual(snapshot.payload_bytes(),
                                 request_raw if method == "attest" else None)
                with self.assertRaises(TypeError):
                    values["method"] = "load"
                with self.assertRaises(module.BrokerTransportRefused):
                    snapshot.extra = True

    def test_response_only_exposes_current_fail_closed_semantics(self):
        module, request_raw, evidence_raw = self.fixture()
        digest = hashlib.sha256(request_raw).hexdigest()
        for method, request_payload in (
                ("attest", request_raw), ("load", digest), ("discard", digest)):
            request = module.decode_request(
                module.canonical_request(
                    method, self.manifest, self.start, request_payload,
                ), self.manifest, self.start,
            )
            refusal_raw = module.canonical_response(request, "refused", None)
            refusal = module.decode_response(refusal_raw, request)
            self.assertEqual(refusal.values()["status"], "refused")
            self.assertIsNone(refusal.evidence_bytes())

            if method == "discard":
                success_raw = module.canonical_response(request, "ok", None)
                success = module.decode_response(success_raw, request)
                self.assertEqual(success.values()["status"], "ok")
                self.assertIsNone(success.evidence_bytes())
            elif method == "attest":
                self.refused(module, lambda request=request:
                             module.canonical_response(request, "ok", None))
                self.refused(module, lambda request=request:
                             module.canonical_response(
                                 request, "ok", evidence_raw,
                             ))
            else:
                self.refused(module, lambda request=request:
                             module.canonical_response(
                                 request, "ok", evidence_raw,
                             ))

        load = module.decode_request(module.canonical_request(
            "load", self.manifest, self.start, digest,
        ), self.manifest, self.start)
        sentinel = module.decode_response(
            module.canonical_response(load, "ok", None), load,
        )
        self.assertTrue(sentinel.is_load_sentinel())

    def test_request_rejects_malformed_confused_and_unbound_documents(self):
        module, request_raw, _evidence_raw = self.fixture()
        digest = hashlib.sha256(request_raw).hexdigest()
        valid = module.canonical_request(
            "attest", self.manifest, self.start, request_raw,
        )
        document = json.loads(valid)
        mutations = []
        for key, value in (
                ("extra", None), ("transportVersion", True),
                ("method", "inspect"), ("manifestDigest", "5" * 64),
                ("brokerStartIdentity", "6" * 64),
                ("requestDigest", "7" * 64)):
            changed = copy.deepcopy(document)
            changed[key] = value
            mutations.append(self.json_bytes(changed))
        wrong_payload = copy.deepcopy(document)
        wrong_payload["payload"] = digest
        mutations.append(self.json_bytes(wrong_payload))
        mutations.extend((
            valid.replace(b'"transportVersion":1',
                          b'"transportVersion":1,"transportVersion":1'),
            valid.replace(b'"transportVersion":1', b'"transportVersion":NaN'),
            valid[:-1], valid + b"\n", b"\xef\xbb\xbf" + valid,
            b"{" + b" " * module.MAX_REQUEST_BYTES,
        ))
        for candidate in mutations:
            with self.subTest(candidate=candidate[:64]):
                self.refused(module, lambda candidate=candidate:
                             module.decode_request(
                                 candidate, self.manifest, self.start,
                             ))

        for method, payload in (
                ("load", request_raw), ("discard", request_raw),
                ("attest", digest), ("status", digest),
        ):
            self.refused(module, lambda method=method, payload=payload:
                         module.canonical_request(
                             method, self.manifest, self.start, payload,
                         ))

    def test_response_rejects_binding_status_and_method_confusion(self):
        module, request_raw, evidence_raw = self.fixture()
        request = module.decode_request(module.canonical_request(
            "attest", self.manifest, self.start, request_raw,
        ), self.manifest, self.start)
        valid = module.canonical_response(request, "refused", None)
        document = json.loads(valid)
        mutations = []
        for key, value in (
                ("extra", None), ("transportVersion", True),
                ("method", "load"), ("manifestDigest", "5" * 64),
                ("brokerStartIdentity", "6" * 64),
                ("requestDigest", "7" * 64), ("status", "pending")):
            changed = copy.deepcopy(document)
            changed[key] = value
            mutations.append(self.json_bytes(changed))
        partial_refusal = copy.deepcopy(document)
        partial_refusal["payload"] = json.loads(evidence_raw)
        mutations.append(self.json_bytes(partial_refusal))
        mutations.extend((valid[:-1], valid + b"\n",
                          b"{" + b" " * module.MAX_RESPONSE_BYTES))
        for candidate in mutations:
            with self.subTest(candidate=candidate[:64]):
                self.refused(module, lambda candidate=candidate:
                             module.decode_response(candidate, request))

        digest = hashlib.sha256(request_raw).hexdigest()
        discard = module.decode_request(module.canonical_request(
            "discard", self.manifest, self.start, digest,
        ), self.manifest, self.start)
        self.refused(module, lambda: module.canonical_response(
            discard, "ok", evidence_raw,
        ))
        self.refused(module, lambda: module.canonical_response(
            request, "ok", None,
        ))
        self.refused(module, lambda: module.canonical_response(
            request, "refused", evidence_raw,
        ))

    def test_success_evidence_is_unavailable_until_authoritative_validator(self):
        module, request_raw, evidence_raw = self.fixture()
        digest = hashlib.sha256(request_raw).hexdigest()
        for method, payload in (("attest", request_raw), ("load", digest)):
            request = module.decode_request(module.canonical_request(
                method, self.manifest, self.start, payload,
            ), self.manifest, self.start)
            self.refused(module, lambda request=request:
                         module.canonical_response(
                             request, "ok", evidence_raw,
                         ))

            document = {
                "transportVersion": 1,
                "method": method,
                "manifestDigest": self.manifest,
                "brokerStartIdentity": self.start,
                "requestDigest": digest,
                "status": "ok",
                "payload": json.loads(evidence_raw),
            }
            self.refused(module, lambda document=document, request=request:
                         module.decode_response(
                             self.json_bytes(document), request,
                         ))

    def test_authority_replacement_refuses_even_when_value_is_equal(self):
        module, request_raw, _evidence_raw = self.fixture()
        digest = hashlib.sha256(request_raw).hexdigest()
        authorities = (
            ("_METHODS", tuple(list(module._METHODS))),
            ("_STATUSES", tuple(list(module._STATUSES))),
            ("_ROLES", tuple(list(module._ROLES))),
            ("MAX_REQUEST_BYTES", module.MAX_REQUEST_BYTES + 1),
            ("MAX_RESPONSE_BYTES", module.MAX_RESPONSE_BYTES + 1),
            ("MAX_EVIDENCE_BYTES", module.MAX_EVIDENCE_BYTES + 1),
            ("_VERSION", module._VERSION + 1),
            ("_PROVENANCE", module._PROVENANCE + "_forged"),
            ("_PRIMARY", module._PRIMARY + "Forged"),
            ("_IMPERSONATION", module._IMPERSONATION + "Forged"),
            ("_SNAPSHOT_MARKER", object()),
            ("_DIRECT_DEPENDENCIES",
             tuple(list(module._DIRECT_DEPENDENCIES))),
            ("_ORDINARY_CODEC", object()),
            ("_ORDINARY_TYPE", object()),
        )
        for name, replacement in authorities:
            with self.subTest(name=name):
                original = getattr(module, name)
                setattr(module, name, replacement)
                try:
                    self.refused(module, lambda: module.canonical_request(
                        "load", self.manifest, self.start, digest,
                    ))
                finally:
                    setattr(module, name, original)
                module.canonical_request(
                    "load", self.manifest, self.start, digest,
                )

        original_method = module._RequestSnapshot.bytes
        module._RequestSnapshot.bytes = lambda self: b"forged\n"
        try:
            self.refused(module, lambda: module.canonical_request(
                "load", self.manifest, self.start, digest,
            ))
        finally:
            module._RequestSnapshot.bytes = original_method

        original_method = module._ResponseSnapshot.is_load_sentinel
        module._ResponseSnapshot.is_load_sentinel = lambda self: True
        try:
            self.refused(module, lambda: module.canonical_request(
                "load", self.manifest, self.start, digest,
            ))
        finally:
            module._ResponseSnapshot.is_load_sentinel = original_method

        module._unexpected_authority = object()
        try:
            self.refused(module, lambda: module.canonical_request(
                "load", self.manifest, self.start, digest,
            ))
        finally:
            del module._unexpected_authority

    def test_mutation_cannot_be_resealed_or_blessed_by_rebound_anchors(self):
        module, request_raw, _unavailable_evidence = self.fixture()
        digest = hashlib.sha256(request_raw).hexdigest()
        original = module._METHODS
        module._METHODS = (*original, "status")
        fake = object()
        module._MODULE_AUTHORITY = fake
        module._MODULE_AUTHORITY_ID = fake
        module._required_module_authority = lambda: None
        module._required_module_authority.__defaults__ = (lambda: None,)
        try:
            self.assertFalse(hasattr(module, "_seal_module_authority"))
            self.assertFalse(hasattr(module, "_make_authority_checker"))
            self.assertFalse(hasattr(module, "_make_public_codec"))
            self.refused(module, lambda: module.canonical_request(
                "status", self.manifest, self.start, digest,
            ))
        finally:
            module._METHODS = original
            del module._MODULE_AUTHORITY
            del module._MODULE_AUTHORITY_ID
            del module._required_module_authority

    def test_primary_base_exception_wins_over_failing_postcheck(self):
        module = load_module()
        original = module._METHODS
        guard = next(
            cell.cell_contents for cell in module.canonical_request.__closure__
            if callable(cell.cell_contents)
            and getattr(cell.cell_contents, "__name__", None) == "guard"
        )
        for primary in (KeyboardInterrupt(), SystemExit(73)):
            with self.subTest(exception=type(primary).__name__):
                def interrupt(primary=primary):
                    module._METHODS = (*original, "status")
                    raise primary

                try:
                    with self.assertRaises(type(primary)) as raised:
                        guard(interrupt)
                    self.assertIs(raised.exception, primary)
                finally:
                    module._METHODS = original

    def test_direct_dependency_and_sibling_drift_fail_closed(self):
        module, request_raw, _evidence_raw = self.fixture()
        original = module._ORDINARY_CODEC.decode_request
        module._ORDINARY_CODEC.decode_request = lambda _raw: object()
        try:
            self.refused(module, lambda: module.canonical_request(
                "attest", self.manifest, self.start, request_raw,
            ))
        finally:
            module._ORDINARY_CODEC.decode_request = original

        original_loads = module.json.loads
        module.json.loads = lambda *_args, **_kwargs: {}
        try:
            self.refused(module, lambda: module.decode_request(
                b"{}\n", self.manifest, self.start,
            ))
        finally:
            module.json.loads = original_loads

        original_hook = module._strict_object
        module._strict_object = lambda _pairs: {}
        try:
            self.refused(module, lambda: module.canonical_request(
                "load", self.manifest, self.start,
                hashlib.sha256(request_raw).hexdigest(),
            ))
        finally:
            module._strict_object = original_hook

    def test_request_and_response_size_caps_remain_fail_closed(self):
        module, request_raw, _unavailable_evidence = self.fixture()
        request = module.decode_request(module.canonical_request(
            "attest", self.manifest, self.start, request_raw,
        ), self.manifest, self.start)
        self.refused(module, lambda: module.decode_request(
            b"{" + b" " * module.MAX_REQUEST_BYTES,
            self.manifest, self.start,
        ))
        self.refused(module, lambda: module.decode_response(
            b"{" + b" " * module.MAX_RESPONSE_BYTES, request,
        ))


if __name__ == "__main__":
    unittest.main()
