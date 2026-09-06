import importlib.util
import json
from pathlib import Path
import unittest


HERE = Path(__file__).resolve().parent
MODULE_PATH = HERE / "checkout-ordinary-structural-boundary.py"


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class StructuralBoundaryTests(unittest.TestCase):
    def fixture(self):
        boundary = load("ordinary_structural_boundary_tested", MODULE_PATH)
        authority_test = load(
            "ordinary_structural_authority_fixture",
            HERE / "test_checkout_ordinary_privilege_authority.py",
        )
        _other, raw, live, entries, _observation = authority_test.fixture()
        authority = boundary._AUTHORITY
        observation = authority._PrivilegeObservation(
            entries, authority._LiveBinding(*live)
        )
        capability = authority._structural_fixture_capability_only(
            observation, observation
        )
        resolver = authority._bind_structural_authority(
            **raw, capture_capability=capability
        )
        self.assertIsNone(resolver.initial())
        marker = resolver.final()
        transport = boundary._TRANSPORT
        manifest = authority._MANIFEST.decode(raw["manifest_raw"])
        start = authority._START.decode(raw["broker_start_raw"])
        encoded = transport.canonical_request(
            "attest", manifest.digest, start.digest, raw["request_raw"]
        )
        request = transport.decode_request(encoded, manifest.digest, start.digest)
        binding_inputs = {
            "manifest_raw": raw["manifest_raw"],
            "broker_start_raw": raw["broker_start_raw"],
            "machine_identity_digest": raw["machine_identity_digest"],
        }
        return boundary, transport, request, marker, binding_inputs

    def refused(self, module, operation):
        with self.assertRaisesRegex(
            module.OrdinaryStructuralBoundaryRefused,
            "^ordinary_structural_boundary$",
        ):
            operation()

    def test_exact_structural_binding_is_immutable_and_disabled(self):
        module, _transport, request, marker, inputs = self.fixture()
        result = module._bind_disabled_structural_attest(
            transport_request=request, privilege_binding=marker, **inputs
        )
        self.assertTrue(result.structuralOnly)
        self.assertEqual(result.boundary, marker.boundary)
        self.assertEqual(result.phase, marker.phase)
        self.assertEqual(result.session, marker.session)
        self.assertEqual(result.privilegeBindingDigest, marker.bindingDigest)
        self.assertFalse(hasattr(result, "transportSuccessEnabled"))
        self.assertEqual(repr(result), "_DisabledStructuralBinding(structuralOnly=True)")
        with self.assertRaises(
            (AttributeError, TypeError, module.OrdinaryStructuralBoundaryRefused)
        ):
            result.session = "changed"
        self.refused(
            module,
            lambda: module._DisabledStructuralBinding(
                True, (True, "0" * 64, "0" * 64, "anchor", "fresh", "s")
            ),
        )
        forged = object.__new__(module._DisabledStructuralBinding)
        self.refused(module, lambda: forged.structuralOnly)
        self.refused(
            module,
            lambda: module._bind_disabled_structural_attest(
                transport_request=request, privilege_binding=marker, **inputs
            ),
        )

    def test_non_attest_and_wrong_or_forged_marker_refuse(self):
        module, transport, request, marker, inputs = self.fixture()
        values = request.values()
        digest = values["requestDigest"]
        for method in ("load", "discard"):
            encoded = transport.canonical_request(
                method,
                values["manifestDigest"],
                values["brokerStartIdentity"],
                digest,
            )
            other = transport.decode_request(
                encoded,
                values["manifestDigest"],
                values["brokerStartIdentity"],
            )
            self.refused(
                module,
                lambda other=other: module._bind_disabled_structural_attest(
                    transport_request=other, privilege_binding=marker, **inputs
                ),
            )
        self.refused(
            module,
            lambda: module._bind_disabled_structural_attest(
                transport_request=request, privilege_binding=object(), **inputs
            ),
        )
        forged = object.__new__(type(marker))
        self.refused(
            module,
            lambda: module._bind_disabled_structural_attest(
                transport_request=request, privilege_binding=forged, **inputs
            ),
        )

    def test_transport_success_paths_remain_refused(self):
        module, transport, request, marker, inputs = self.fixture()
        module._bind_disabled_structural_attest(
            transport_request=request, privilege_binding=marker, **inputs
        )
        for method in ("attest", "load"):
            values = request.values()
            if method == "attest":
                selected = request
            else:
                encoded = transport.canonical_request(
                    "load",
                    values["manifestDigest"],
                    values["brokerStartIdentity"],
                    values["requestDigest"],
                )
                selected = transport.decode_request(
                    encoded,
                    values["manifestDigest"],
                    values["brokerStartIdentity"],
                )
            with self.assertRaises(transport.BrokerTransportRefused):
                transport.canonical_response(selected, "ok", b"{}\n")

    def test_dependency_rebinding_and_baseexception_are_fail_closed(self):
        module, _transport, request, marker, inputs = self.fixture()
        original = module._TRANSPORT._RequestSnapshot.values
        module._TRANSPORT._RequestSnapshot.values = lambda self: {}
        try:
            self.refused(
                module,
                lambda: module._bind_disabled_structural_attest(
                    transport_request=request, privilege_binding=marker, **inputs
                ),
            )
        finally:
            module._TRANSPORT._RequestSnapshot.values = original
        self.refused(
            module,
            lambda: module._bind_disabled_structural_attest(
                transport_request=request, privilege_binding=marker, **inputs
            ),
        )

    def test_marker_is_bound_to_exact_payload_request_and_failure_consumes(self):
        module, transport, request, marker, inputs = self.fixture()
        ordinary = json.loads(request.payload_bytes().decode("ascii"))
        ordinary["challenge"] = "2" * 64
        changed_raw = (
            json.dumps(
                ordinary,
                sort_keys=True,
                separators=(",", ":"),
                ensure_ascii=True,
            )
            + "\n"
        ).encode("ascii")
        values = request.values()
        encoded = transport.canonical_request(
            "attest",
            values["manifestDigest"],
            values["brokerStartIdentity"],
            changed_raw,
        )
        changed = transport.decode_request(
            encoded,
            values["manifestDigest"],
            values["brokerStartIdentity"],
        )
        self.refused(
            module,
            lambda: module._bind_disabled_structural_attest(
                transport_request=changed, privilege_binding=marker, **inputs
            ),
        )
        self.refused(
            module,
            lambda: module._bind_disabled_structural_attest(
                transport_request=request, privilege_binding=marker, **inputs
            ),
        )

    def test_result_constructor_drift_refuses_and_consumes_marker(self):
        module, _transport, request, marker, inputs = self.fixture()
        result_type = module._DisabledStructuralBinding
        original = result_type.__dict__["__new__"]
        result_type.__new__ = lambda cls, _token, values=None: object.__new__(cls)
        try:
            self.refused(
                module,
                lambda: module._bind_disabled_structural_attest(
                    transport_request=request,
                    privilege_binding=marker,
                    **inputs,
                ),
            )
        finally:
            result_type.__new__ = original
        self.refused(
            module,
            lambda: module._bind_disabled_structural_attest(
                transport_request=request, privilege_binding=marker, **inputs
            ),
        )


if __name__ == "__main__":
    unittest.main()
