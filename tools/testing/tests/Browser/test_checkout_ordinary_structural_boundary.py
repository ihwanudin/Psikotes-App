import importlib.util
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
        return boundary, transport, request, marker

    def refused(self, module, operation):
        with self.assertRaisesRegex(
            module.OrdinaryStructuralBoundaryRefused,
            "^ordinary_structural_boundary$",
        ):
            operation()

    def test_exact_structural_binding_is_immutable_and_disabled(self):
        module, _transport, request, marker = self.fixture()
        result = module._bind_disabled_structural_attest(
            transport_request=request, privilege_binding=marker
        )
        self.assertTrue(result.structuralOnly)
        self.assertFalse(result.transportSuccessEnabled)
        self.assertEqual(result.boundary, marker.boundary)
        self.assertEqual(result.phase, marker.phase)
        self.assertEqual(result.session, marker.session)
        self.assertEqual(result.privilegeBindingDigest, marker.bindingDigest)
        with self.assertRaises((AttributeError, TypeError)):
            result.transportSuccessEnabled = True
        self.refused(
            module,
            lambda: module._bind_disabled_structural_attest(
                transport_request=request, privilege_binding=marker
            ),
        )

    def test_non_attest_and_wrong_or_forged_marker_refuse(self):
        module, transport, request, marker = self.fixture()
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
                    transport_request=other, privilege_binding=marker
                ),
            )
        self.refused(
            module,
            lambda: module._bind_disabled_structural_attest(
                transport_request=request, privilege_binding=object()
            ),
        )
        forged = object.__new__(type(marker))
        self.refused(
            module,
            lambda: module._bind_disabled_structural_attest(
                transport_request=request, privilege_binding=forged
            ),
        )

    def test_transport_success_paths_remain_refused(self):
        module, transport, request, marker = self.fixture()
        module._bind_disabled_structural_attest(
            transport_request=request, privilege_binding=marker
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
        module, _transport, request, marker = self.fixture()
        original = module._TRANSPORT._RequestSnapshot.values
        module._TRANSPORT._RequestSnapshot.values = lambda self: {}
        try:
            self.refused(
                module,
                lambda: module._bind_disabled_structural_attest(
                    transport_request=request, privilege_binding=marker
                ),
            )
        finally:
            module._TRANSPORT._RequestSnapshot.values = original


if __name__ == "__main__":
    unittest.main()
