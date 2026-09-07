import copy
import hashlib
import importlib.util
import json
from pathlib import Path
from types import MappingProxyType
import unittest


MODULE_PATH = Path(__file__).with_name(
    "checkout-revocation-high-water-transition.py"
)
SPEC = importlib.util.spec_from_file_location(
    "checkout_revocation_high_water_transition", MODULE_PATH
)
transition = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(transition)

I10_MODULE_PATH = Path(__file__).with_name(
    "checkout-composition-admission-artifact.py"
)
I10_SPEC = importlib.util.spec_from_file_location(
    "checkout_composition_admission_artifact_i10_oracle", I10_MODULE_PATH
)
i10 = importlib.util.module_from_spec(I10_SPEC)
I10_SPEC.loader.exec_module(i10)


def canonical(value):
    return (json.dumps(value, sort_keys=True, separators=(",", ":"),
                       ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")


def namespace():
    return {
        "authorityRole": "release-source",
        "issuerId": "release-issuer-01",
        "issuerKeyGeneration": 3,
        "revocationTrustGeneration": 5,
    }


def candidate(generation=1):
    return {
        **namespace(),
        "generation": generation,
        "issuedAt": "2026-09-07T04:00:00Z",
        "nextUpdate": "2026-09-08T04:00:00Z",
        "notBefore": "2026-09-07T03:00:00Z",
        "snapshotDigest": format(generation, "064x"),
    }


def state(generation=1, previous=None):
    item = candidate(generation)
    return {
        "generation": item["generation"],
        "issuedAt": item["issuedAt"],
        "namespace": namespace(),
        "nextUpdate": item["nextUpdate"],
        "notBefore": item["notBefore"],
        "previousStateDigest": previous,
        "rebootUncertain": False,
        "snapshotDigest": item["snapshotDigest"],
        "version": 1,
    }


class RevocationHighWaterTransitionTests(unittest.TestCase):
    def plan(self, current=None, proposed=None, **overrides):
        arguments = {
            "expected_namespace": namespace(),
            "reboot_uncertain": False,
        }
        arguments.update(overrides)
        return transition.plan(
            None if current is None else (
                canonical(current) if type(current) is dict else current
            ),
            candidate() if proposed is None else proposed,
            **arguments,
        )

    def assert_refused(self, current=None, proposed=None, **overrides):
        with self.assertRaisesRegex(
            transition.RevocationHighWaterTransitionRefused,
            "^revocation_high_water_transition$",
        ):
            self.plan(current, proposed, **overrides)

    def test_bootstrap_generation_one_proposes_exact_immutable_state(self):
        result = self.plan()
        expected = state()
        raw = canonical(expected)
        self.assertIs(type(result), type(MappingProxyType({})))
        self.assertIs(result["structuralOnly"], True)
        self.assertEqual(result["generation"], 1)
        self.assertIsNone(result["previousStateDigest"])
        self.assertEqual(result["stateBytes"], raw)
        self.assertEqual(result["stateDigest"], hashlib.sha256(raw).hexdigest())
        self.assertIs(type(result["namespace"]), tuple)
        with self.assertRaises(TypeError):
            result["accepted"] = True
        for field in ("accepted", "authenticated", "current", "fresh",
                      "persisted", "quorumVerified", "rollbackProtected"):
            self.assertNotIn(field, result)

    def test_next_transition_is_strictly_higher_and_links_current_digest(self):
        current = state()
        current_raw = canonical(current)
        result = self.plan(current, candidate(3))
        proposed = state(3, hashlib.sha256(current_raw).hexdigest())
        self.assertEqual(result["generation"], 3)
        self.assertEqual(result["previousStateDigest"], hashlib.sha256(current_raw).hexdigest())
        self.assertEqual(result["stateBytes"], canonical(proposed))

    def test_bootstrap_only_allows_generation_one(self):
        for generation in (0, 2, 9, True):
            with self.subTest(generation=generation):
                self.assert_refused(None, candidate(generation))

    def test_same_generation_even_same_digest_conflict_and_rollback_refuse(self):
        current = state(4, "a" * 64)
        for generation in (4, 3, 1):
            proposed = candidate(generation)
            if generation == 4:
                proposed["snapshotDigest"] = current["snapshotDigest"]
            with self.subTest(generation=generation):
                self.assert_refused(current, proposed)

    def test_namespace_must_match_expected_and_current_exactly(self):
        for field, bad in (
            ("authorityRole", "vendor-build"),
            ("issuerId", "other-issuer"),
            ("issuerKeyGeneration", 4),
            ("revocationTrustGeneration", 6),
        ):
            proposed = candidate(2)
            proposed[field] = bad
            with self.subTest(candidate=field):
                self.assert_refused(state(), proposed)
            current = state()
            current["namespace"][field] = bad
            with self.subTest(current=field):
                self.assert_refused(current, candidate(2))
            expected = namespace()
            expected[field] = bad
            with self.subTest(expected=field):
                self.assert_refused(state(), candidate(2), expected_namespace=expected)

    def test_all_accepted_namespaces_bootstrap_and_advance(self):
        for role in i10.REVOCATION_ROLES:
            expected = namespace()
            expected["authorityRole"] = role
            first = candidate()
            first["authorityRole"] = role
            with self.subTest(role=role, transition="bootstrap"):
                bootstrap = self.plan(
                    None, first, expected_namespace=expected
                )
                self.assertEqual(bootstrap["namespace"][0], role)

            current = state()
            current["namespace"]["authorityRole"] = role
            second = candidate(2)
            second["authorityRole"] = role
            with self.subTest(role=role, transition="advance"):
                advanced = self.plan(
                    current, second, expected_namespace=expected
                )
                self.assertEqual(advanced["generation"], 2)

    def test_authority_roles_exactly_match_i10_revocation_vocabulary(self):
        self.assertIs(type(transition.AUTHORITY_ROLES), tuple)
        self.assertIs(type(i10.REVOCATION_ROLES), tuple)
        self.assertEqual(transition.AUTHORITY_ROLES, i10.REVOCATION_ROLES)

    def test_equal_looking_authority_role_tuple_rebind_refuses(self):
        original = transition.AUTHORITY_ROLES
        replacement = tuple(list(original))
        self.assertEqual(replacement, original)
        self.assertIsNot(replacement, original)
        try:
            transition.AUTHORITY_ROLES = replacement
            with self.assertRaisesRegex(
                transition.RevocationHighWaterTransitionRefused,
                "^revocation_high_water_transition$",
            ):
                transition.plan(
                    None, candidate(), expected_namespace=namespace(),
                    reboot_uncertain=False,
                )
        finally:
            transition.AUTHORITY_ROLES = original

    def test_candidate_schema_types_digest_and_timestamps_are_exact(self):
        base = candidate()
        for field in tuple(base):
            changed = copy.deepcopy(base)
            del changed[field]
            self.assert_refused(None, changed)
        changed = candidate(); changed["accepted"] = True; self.assert_refused(None, changed)
        for field, bad in (
            ("generation", True), ("issuerKeyGeneration", 0),
            ("revocationTrustGeneration", 1.0),
            ("snapshotDigest", "A" * 64), ("snapshotDigest", "a" * 63),
            ("issuedAt", "2026-09-07T04:00:00+00:00"),
            ("nextUpdate", "2026-09-07T04:00:00Z"),
            ("notBefore", "2026-09-07T04:00:01Z"),
        ):
            changed = candidate()
            changed[field] = bad
            with self.subTest(field=field, bad=bad):
                self.assert_refused(None, changed)

    def test_candidate_window_is_positive_at_most_24_hours(self):
        for next_update in (
            "2026-09-07T04:00:00Z",
            "2026-09-07T03:59:59Z",
            "2026-09-08T04:00:01Z",
        ):
            changed = candidate()
            changed["nextUpdate"] = next_update
            self.assert_refused(None, changed)

    def test_current_state_must_be_canonical_closed_and_link_shape_valid(self):
        valid = state()
        raw = canonical(valid)
        invalid = (
            raw[:-1], raw + b"\n", json.dumps(valid).encode("ascii"),
            b'{"version":1,"version":1}\n', b'{"generation":NaN}\n',
            "not-bytes", bytearray(raw), b"{" + b" " * (16 * 1024) + b"}\n",
        )
        for item in invalid:
            with self.subTest(item=repr(item)[:50]):
                self.assert_refused(item, candidate(2))
        changed = state(); changed["extra"] = True; self.assert_refused(changed, candidate(2))
        changed = state(); changed["previousStateDigest"] = "a" * 64; self.assert_refused(changed, candidate(2))
        changed = state(2, None); self.assert_refused(changed, candidate(3))

    def test_corrupt_or_reboot_uncertain_state_refuses(self):
        changed = state()
        changed["rebootUncertain"] = True
        self.assert_refused(changed, candidate(2))
        self.assert_refused(None, candidate(), reboot_uncertain=True)
        self.assert_refused(state(), candidate(2), reboot_uncertain=True)
        for bad in (1, "false", None):
            self.assert_refused(state(), candidate(2), reboot_uncertain=bad)

    def test_expected_namespace_is_closed_strict_and_not_candidate_selected(self):
        expected = namespace(); expected["extra"] = True
        self.assert_refused(None, candidate(), expected_namespace=expected)
        for field, bad in (
            ("authorityRole", True), ("issuerId", "../issuer"),
            ("issuerKeyGeneration", True), ("revocationTrustGeneration", 0),
        ):
            expected = namespace(); expected[field] = bad
            self.assert_refused(None, candidate(), expected_namespace=expected)

    def test_dependency_and_module_mutation_fail_closed(self):
        original_refusal = transition.RevocationHighWaterTransitionRefused
        for owner, name, replacement in (
            (transition, "MAX_STATE_BYTES", transition.MAX_STATE_BYTES + 1),
            (transition, "AUTHORITY_ROLES", tuple(list(transition.AUTHORITY_ROLES))),
            (transition, "MappingProxyType", dict),
            (transition, "json", object()), (transition, "hashlib", object()),
            (transition, "re", object()), (transition, "datetime", object()),
            (transition, "RevocationHighWaterTransitionRefused", Exception),
            (transition, "__all__", tuple(list(transition.__all__))),
        ):
            original = getattr(owner, name)
            try:
                setattr(owner, name, replacement)
                with self.assertRaises(original_refusal):
                    transition.plan(None, candidate(), expected_namespace=namespace(),
                                    reboot_uncertain=False)
            finally:
                setattr(owner, name, original)

    def test_direct_dependency_replacement_refuses_before_replacement_call(self):
        refusal = transition.RevocationHighWaterTransitionRefused
        for owner, name in (
            (transition.json, "dumps"), (transition.json, "loads"),
            (transition.hashlib, "sha256"), (transition.re, "fullmatch"),
        ):
            original = getattr(owner, name)
            calls = []
            def replacement(*_args, **_kwargs):
                calls.append(True)
                return None
            try:
                setattr(owner, name, replacement)
                with self.subTest(dependency=f"{owner.__name__}.{name}"):
                    with self.assertRaises(refusal):
                        transition.plan(
                            None, candidate(), expected_namespace=namespace(),
                            reboot_uncertain=False,
                        )
                    self.assertEqual(calls, [])
            finally:
                setattr(owner, name, original)

    def test_dependency_code_defaults_and_kwdefaults_drift_fail_closed(self):
        refusal = transition.RevocationHighWaterTransitionRefused
        for function in (transition.json.dumps, transition.json.loads,
                         transition.re.fullmatch):
            original_code = function.__code__
            try:
                function.__code__ = (lambda: None).__code__
                with self.subTest(function=function.__name__, drift="code"):
                    with self.assertRaises(refusal):
                        transition.plan(
                            None, candidate(), expected_namespace=namespace(),
                            reboot_uncertain=False,
                        )
            finally:
                function.__code__ = original_code

            original_defaults = function.__defaults__
            try:
                function.__defaults__ = (None,) if original_defaults is None \
                    else tuple(list(original_defaults))
                with self.subTest(function=function.__name__, drift="defaults"):
                    with self.assertRaises(refusal):
                        transition.plan(
                            None, candidate(), expected_namespace=namespace(),
                            reboot_uncertain=False,
                        )
            finally:
                function.__defaults__ = original_defaults

            original_kwdefaults = function.__kwdefaults__
            if original_kwdefaults is not None:
                saved = dict(original_kwdefaults)
                try:
                    original_kwdefaults["__hostile__"] = True
                    with self.subTest(function=function.__name__, drift="kwdefaults"):
                        with self.assertRaises(refusal):
                            transition.plan(
                                None, candidate(), expected_namespace=namespace(),
                                reboot_uncertain=False,
                            )
                finally:
                    original_kwdefaults.clear()
                    original_kwdefaults.update(saved)

    def test_exception_redaction_and_baseexception_identity_are_preserved(self):
        invoke = next(
            cell.cell_contents for cell in transition.plan.__closure__
            if callable(cell.cell_contents)
            and getattr(cell.cell_contents, "__name__", "") == "invoke"
        )

        def ordinary_failure():
            raise RuntimeError("secret namespace and host details")

        with self.assertRaisesRegex(
            transition.RevocationHighWaterTransitionRefused,
            "^revocation_high_water_transition$",
        ) as raised:
            invoke(ordinary_failure)
        self.assertNotIn("secret", str(raised.exception))

        for primary in (KeyboardInterrupt("keyboard-secret"), SystemExit(23)):
            def stop(error=primary):
                raise error
            with self.subTest(primary=type(primary).__name__):
                with self.assertRaises(type(primary)) as caught:
                    invoke(stop)
                self.assertIs(caught.exception, primary)

    def test_public_surface_has_no_storage_signature_or_admission_api(self):
        self.assertEqual(transition.__all__, (
            "RevocationHighWaterTransitionRefused", "plan",
        ))
        for forbidden in ("accept", "admit", "consume", "load", "save", "write",
                          "verify", "verify_signature", "recover"):
            self.assertFalse(hasattr(transition, forbidden))


if __name__ == "__main__":
    unittest.main()
