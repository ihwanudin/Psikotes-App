import copy
import hashlib
import importlib.util
import json
from pathlib import Path
from types import MappingProxyType
import unittest


MODULE_PATH = Path(__file__).with_name("checkout-one-shot-ledger-transition.py")
SPEC = importlib.util.spec_from_file_location(
    "checkout_one_shot_ledger_transition", MODULE_PATH
)
ledger = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(ledger)


NAMESPACES = ("composition-admission", "preparation-authorization")
ZERO_DIGEST = "0" * 64
MAX_INTENT_BYTES = 8 * 1024
MAX_LEDGER_BYTES = 1024 * 1024
MAX_RECORDS = 4096
RESULT_FIELDS = (
    "structuralOnly",
    "namespace",
    "phase",
    "proposedLedgerGeneration",
    "previousLedgerDigest",
    "proposedLedgerDigest",
    "proposedState",
    "records",
)


def canonical(value):
    return (
        json.dumps(
            value,
            sort_keys=True,
            separators=(",", ":"),
            ensure_ascii=True,
            allow_nan=False,
        )
        + "\n"
    ).encode("ascii")


def intent(
    *,
    namespace="preparation-authorization",
    artifact_id="preparation-0001",
    artifact_digest="1" * 64,
    replay_id="preparation-replay-0001",
    artifact_generation=7,
    phase="preparation",
    consumed_at="2026-09-07T06:00:00Z",
    prior_digest=ZERO_DIGEST,
    prior_generation=0,
    transition_generation=1,
):
    return {
        "artifactDigest": artifact_digest,
        "artifactGeneration": artifact_generation,
        "artifactId": artifact_id,
        "consumedAt": consumed_at,
        "namespace": namespace,
        "phase": phase,
        "priorLedgerDigest": prior_digest,
        "priorLedgerGeneration": prior_generation,
        "replayId": replay_id,
        "transitionGeneration": transition_generation,
        "version": 1,
    }


def second_intent(prior_raw):
    return intent(
        namespace="composition-admission",
        artifact_id="composition-0001",
        artifact_digest="2" * 64,
        replay_id="composition-replay-0001",
        artifact_generation=11,
        phase="composition",
        consumed_at="2026-09-07T06:01:00Z",
        prior_digest=hashlib.sha256(prior_raw).hexdigest(),
        prior_generation=1,
        transition_generation=2,
    )


class OneShotLedgerTransitionTests(unittest.TestCase):
    def plan(self, prior, value):
        raw = canonical(value) if type(value) is dict else value
        return ledger.plan_transition(prior, raw)

    def assert_refused(self, prior, value):
        with self.assertRaisesRegex(
            ledger.OneShotLedgerTransitionRefused,
            "^one_shot_ledger_transition$",
        ):
            self.plan(prior, value)

    def bootstrap(self):
        return self.plan(None, intent())

    def test_bootstrap_known_vector_is_canonical_immutable_and_proposed_only(self):
        result = self.bootstrap()
        self.assertEqual(
            ledger.__all__,
            (
                "OneShotLedgerTransitionRefused",
                "canonical_intent",
                "canonical_state",
                "plan_transition",
            ),
        )
        self.assertIs(type(result), MappingProxyType)
        self.assertEqual(tuple(result), RESULT_FIELDS)
        self.assertIs(result["structuralOnly"], True)
        self.assertEqual(result["proposedLedgerGeneration"], 1)
        self.assertEqual(result["previousLedgerDigest"], ZERO_DIGEST)
        self.assertEqual(
            result["proposedLedgerDigest"],
            "03575f55d57f41a0ee70f1030c3e869e5c3c0952b68f85a20aeae2696eed6b45",
        )
        self.assertEqual(result["proposedLedgerDigest"], hashlib.sha256(
            result["proposedState"]
        ).hexdigest())
        self.assertEqual(result["records"][0][0], "preparation-authorization")
        with self.assertRaises(TypeError):
            result["accepted"] = True
        for forbidden in ("accepted", "consumed", "fresh", "replayAccepted"):
            self.assertNotIn(forbidden, result)

    def test_second_transition_links_exact_prior_digest_and_generation(self):
        first = self.bootstrap()
        result = self.plan(first["proposedState"], second_intent(
            first["proposedState"]
        ))
        self.assertEqual(result["proposedLedgerGeneration"], 2)
        self.assertEqual(
            result["previousLedgerDigest"], first["proposedLedgerDigest"]
        )
        self.assertEqual(len(result["records"]), 2)
        self.assertEqual(
            ledger.canonical_state(json.loads(result["proposedState"])),
            result["proposedState"],
        )

    def test_intent_schema_encoding_and_caps_are_exact(self):
        value = intent()
        raw = canonical(value)
        self.assertEqual(ledger.canonical_intent(value), raw)
        for key in tuple(value):
            changed = copy.deepcopy(value)
            del changed[key]
            self.assert_refused(None, changed)
        changed = copy.deepcopy(value)
        changed["accepted"] = False
        self.assert_refused(None, changed)
        for candidate in (
            raw[:-1], raw + b"\n", b"\xef\xbb\xbf" + raw,
            json.dumps(value).encode("ascii"), b'{"version":1,"version":1}\n',
            b'{"version":NaN}\n', b"[]\n", "not-bytes", bytearray(raw),
            b"{" + b" " * MAX_INTENT_BYTES + b"}\n",
        ):
            with self.subTest(candidate=repr(candidate)[:50]):
                self.assert_refused(None, candidate)

    def test_only_two_namespaces_and_exact_phase_are_allowed(self):
        cases = (
            ("release-source", "preparation"),
            ("Preparation-Authorization", "preparation"),
            ("preparation-authorization", "composition"),
            ("composition-admission", "preparation"),
            ("composition-admission", "Composition"),
        )
        for namespace, phase in cases:
            changed = intent(namespace=namespace, phase=phase)
            self.assert_refused(None, changed)
        result = self.plan(None, intent(
            namespace="composition-admission",
            phase="composition",
        ))
        self.assertEqual(result["namespace"], "composition-admission")

    def test_ids_digests_generations_and_timestamp_are_strict(self):
        for key, bad in (
            ("artifactId", "Artifact"), ("artifactId", "../artifact"),
            ("replayId", "replay id"), ("artifactDigest", "A" * 64),
            ("artifactDigest", "0" * 63), ("artifactGeneration", 0),
            ("artifactGeneration", True), ("artifactGeneration", 1 << 63),
            ("priorLedgerGeneration", True), ("transitionGeneration", True),
            ("consumedAt", "2026-09-07T06:00:00+00:00"),
            ("consumedAt", "2026-02-29T00:00:00Z"),
        ):
            changed = intent()
            changed[key] = bad
            with self.subTest(key=key, bad=bad):
                self.assert_refused(None, changed)

    def test_bootstrap_and_transition_generation_are_exact(self):
        for prior_generation, transition_generation, prior_digest in (
            (1, 1, ZERO_DIGEST), (0, 0, ZERO_DIGEST),
            (0, 2, ZERO_DIGEST), (0, 1, "1" * 64),
        ):
            self.assert_refused(None, intent(
                prior_generation=prior_generation,
                transition_generation=transition_generation,
                prior_digest=prior_digest,
            ))
        first = self.bootstrap()
        for prior_generation, transition_generation, prior_digest in (
            (0, 2, first["proposedLedgerDigest"]),
            (1, 1, first["proposedLedgerDigest"]),
            (1, 3, first["proposedLedgerDigest"]),
            (1, 2, "3" * 64),
        ):
            changed = second_intent(first["proposedState"])
            changed["priorLedgerGeneration"] = prior_generation
            changed["transitionGeneration"] = transition_generation
            changed["priorLedgerDigest"] = prior_digest
            self.assert_refused(first["proposedState"], changed)

    def test_reuse_of_artifact_replay_or_digest_is_refused(self):
        first = self.bootstrap()
        baseline = second_intent(first["proposedState"])
        for key, value in (
            ("artifactId", "preparation-0001"),
            ("replayId", "preparation-replay-0001"),
            ("artifactDigest", "1" * 64),
        ):
            changed = copy.deepcopy(baseline)
            changed[key] = value
            with self.subTest(reused=key):
                self.assert_refused(first["proposedState"], changed)

    def test_artifact_generation_must_advance_within_namespace(self):
        first = self.bootstrap()
        for generation in (6, 7):
            changed = second_intent(first["proposedState"])
            changed.update({
                "namespace": "preparation-authorization",
                "phase": "preparation",
                "artifactGeneration": generation,
            })
            self.assert_refused(first["proposedState"], changed)

    def test_prior_state_is_closed_canonical_bounded_sorted_and_consistent(self):
        first = self.bootstrap()
        state = json.loads(first["proposedState"])
        mutations = []
        extra = copy.deepcopy(state); extra["accepted"] = False; mutations.append(extra)
        wrong_generation = copy.deepcopy(state); wrong_generation["ledgerGeneration"] = 2; mutations.append(wrong_generation)
        wrong_link = copy.deepcopy(state); wrong_link["previousLedgerDigest"] = "4" * 64; mutations.append(wrong_link)
        duplicate = copy.deepcopy(state); duplicate["records"].append(copy.deepcopy(duplicate["records"][0])); duplicate["ledgerGeneration"] = 2; mutations.append(duplicate)
        for changed in mutations:
            self.assert_refused(canonical(changed), second_intent(canonical(changed)))
        second = self.plan(first["proposedState"], second_intent(
            first["proposedState"]
        ))
        zero_link = json.loads(second["proposedState"])
        zero_link["previousLedgerDigest"] = ZERO_DIGEST
        self.assert_refused(
            canonical(zero_link), second_intent(canonical(zero_link))
        )
        for bad in (
            first["proposedState"][:-1], first["proposedState"] + b"\n",
            b"[]\n", b"{" + b" " * MAX_LEDGER_BYTES + b"}\n",
        ):
            self.assert_refused(bad, second_intent(first["proposedState"]))

    def test_records_are_deterministically_sorted_and_immutable(self):
        first = self.plan(None, intent(
            namespace="composition-admission", phase="composition",
            artifact_id="composition-z", artifact_digest="9" * 64,
            replay_id="composition-replay-z",
        ))
        next_value = intent(
            artifact_id="preparation-a", artifact_digest="8" * 64,
            replay_id="preparation-replay-a", artifact_generation=8,
            prior_digest=first["proposedLedgerDigest"], prior_generation=1,
            transition_generation=2,
        )
        result = self.plan(first["proposedState"], next_value)
        self.assertEqual(tuple(item[0] for item in result["records"]), NAMESPACES)
        self.assertIs(type(result["records"]), tuple)
        self.assertIs(type(result["records"][0]), tuple)

    def test_dependency_and_authority_tampering_refuses_before_replacement(self):
        raw = canonical(intent())
        refusal = ledger.OneShotLedgerTransitionRefused
        for owner, name, replacement in (
            (ledger, "NAMESPACES", tuple(list(ledger.NAMESPACES))),
            (ledger, "MAX_INTENT_BYTES", MAX_INTENT_BYTES + 1),
            (ledger, "MAX_LEDGER_BYTES", MAX_LEDGER_BYTES + 1),
            (ledger, "MAX_RECORDS", MAX_RECORDS + 1),
            (ledger, "OneShotLedgerTransitionRefused", Exception),
            (ledger, "json", object()), (ledger, "hashlib", object()),
            (ledger, "re", object()), (ledger, "MappingProxyType", dict),
            (ledger, "__all__", tuple(list(ledger.__all__))),
        ):
            original = getattr(owner, name)
            try:
                setattr(owner, name, replacement)
                with self.assertRaises(refusal):
                    ledger.plan_transition(None, raw)
            finally:
                setattr(owner, name, original)
        for owner, name in (
            (ledger.json, "loads"), (ledger.json, "dumps"),
            (ledger.hashlib, "sha256"), (ledger.re, "fullmatch"),
        ):
            original = getattr(owner, name)
            calls = []
            def replacement(*_args, **_kwargs):
                calls.append(True)
                return {}
            try:
                setattr(owner, name, replacement)
                with self.assertRaises(refusal):
                    ledger.plan_transition(None, raw)
                self.assertEqual(calls, [])
            finally:
                setattr(owner, name, original)

    def test_baseexception_identity_and_non_authority_surface(self):
        invoke = next(
            cell.cell_contents for cell in ledger.plan_transition.__closure__
            if callable(cell.cell_contents)
            and getattr(cell.cell_contents, "__name__", "") == "invoke"
        )
        original = ledger.NAMESPACES
        replacement = tuple(list(original))
        for primary in (KeyboardInterrupt("k"), SystemExit("s")):
            def drift_then_stop(error=primary):
                ledger.NAMESPACES = replacement
                raise error
            try:
                with self.assertRaises(type(primary)) as raised:
                    invoke(drift_then_stop)
                self.assertIs(raised.exception, primary)
            finally:
                ledger.NAMESPACES = original
        for forbidden in (
            "accept", "admit", "commit", "consume", "lock", "persist",
            "read_clock", "verify_signature",
        ):
            self.assertFalse(hasattr(ledger, forbidden))
        text = (ledger.__doc__ or "").lower()
        for phrase in ("structural", "does not", "proposed", "supplied"):
            self.assertIn(phrase, text)


if __name__ == "__main__":
    unittest.main()
