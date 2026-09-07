"""Synthetic tests for the structural repository-verifier composition."""

import copy
import hashlib
import importlib.util
import json
from pathlib import Path
from types import MappingProxyType
import unittest


ROOT = Path(__file__).parent


def load(name, filename):
    spec = importlib.util.spec_from_file_location(name, ROOT / filename)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


support = load("repository_verifier_support", "test_checkout_repository_ed25519_verifier.py")
request_codec = load("composition_request_codec", "checkout-verifier-request.py")
evidence_codec = load("composition_evidence_codec", "checkout-verifier-evidence.py")
repository_verifier = load("composition_repository_verifier", "checkout-repository-ed25519-verifier.py")
MODULE_PATH = ROOT / "checkout-repository-verifier-composition.py"


def canonical(value):
    return (json.dumps(value, sort_keys=True, separators=(",", ":"),
                       ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")


def envelope_set_digest(envelopes):
    inventory = [hashlib.sha256(raw).hexdigest() for raw in envelopes]
    return hashlib.sha256(
        b"oncam.checkout.detached-envelope-set.v1\0" + canonical(inventory)
    ).hexdigest()


def request_value(raw, envelopes, policy):
    artifact = json.loads(raw)
    role = artifact["role"]
    return {
        "artifact": {"bytesDigest": hashlib.sha256(raw).hexdigest(), "role": role},
        "cryptographyRuntime": {
            "acquisitionEvidenceDigest": support.BINDING["cryptographyAcquisitionEvidenceDigest"],
            "closureDigest": support.BINDING["cryptographyClosureDigest"],
            "generation": support.BINDING["cryptographyGeneration"],
        },
        "detachedEnvelope": {"bytesDigest": envelope_set_digest(envelopes)},
        "highWater": {
            "currentGeneration": 0, "currentStateDigest": "0" * 64,
            "proposedGeneration": 1, "proposedStateDigest": "5" * 64,
        },
        "requestId": "verify-request-01", "role": role, "runId": "run-01",
        "revocationSnapshot": {
            "bytesDigest": "6" * 64, "generation": 1,
            "namespace": {
                "authorityRole": role,
                "issuerId": policy["artifactIssuerId"],
                "issuerKeyGeneration": 1,
                "revocationTrustGeneration": 2,
            },
        },
        "trustRootBundle": {
            "bytesDigest": "7" * 64,
            "trustGeneration": max(group["trustGeneration"] for group in policy["groups"]),
        },
        "trustedTime": {"inputSetDigest": "8" * 64},
        "verifier": {
            "sourceDigest": hashlib.sha256(
                (ROOT / "checkout-repository-ed25519-verifier.py").read_bytes()
            ).hexdigest()
        },
        "version": 1,
    }


class RepositoryVerifierCompositionTests(unittest.TestCase):
    def setUp(self):
        self.module = load(f"repository_composition_{id(self)}", MODULE_PATH.name)
        self.verifier = load(f"repository_verifier_{id(self)}", "checkout-repository-ed25519-verifier.py")
        self.capability = self.verifier.seal_backend(
            support.AmbientCryptographyAdapter(), copy.deepcopy(support.ACQUISITION)
        )

    def values(self, role="release-source", mode="ordinary"):
        raw, envelopes, policy = support.case(role, mode)
        request = request_value(raw, envelopes, policy)
        return request_codec.canonical_request(request), raw, envelopes, policy

    def compose(self, role="release-source", mode="ordinary"):
        request_raw, raw, envelopes, policy = self.values(role, mode)
        evidence_raw = self.module.compose(
            request_raw, raw, envelopes, policy,
            self.capability, self.verifier.verify,
        )
        return request_raw, evidence_raw

    def refused(self, callback, module=None):
        module = module or self.module
        with self.assertRaisesRegex(
            module.RepositoryVerifierCompositionRefused,
            "^repository_verifier_composition$",
        ):
            callback()

    def test_all_exact_quorum_modes_project_canonical_i17_evidence(self):
        cases = (
            ("release-source", "ordinary", 1),
            ("asset-review", "asset-review", 2),
            ("revocation-snapshot", "revocation", 2),
            ("trust-root-bundle", "bootstrap", 2),
            ("trust-root-bundle", "rotation", 4),
        )
        for index, (role, mode, count) in enumerate(cases):
            module = load(f"repository_mode_{index}", MODULE_PATH.name)
            verifier = load(f"repository_crypto_{index}", "checkout-repository-ed25519-verifier.py")
            capability = verifier.seal_backend(
                support.AmbientCryptographyAdapter(), copy.deepcopy(support.ACQUISITION)
            )
            request_raw, raw, envelopes, policy = self.values(role, mode)
            evidence_raw = module.compose(
                request_raw, raw, envelopes, policy, capability, verifier.verify
            )
            self.assertEqual(evidence_raw, evidence_codec.canonical_evidence(json.loads(evidence_raw)))
            result = evidence_codec.decode(request_raw, evidence_raw)
            self.assertEqual(result["status"], "verified-structural-evidence")
            self.assertEqual(sum(item[1] for item in result["quorumSummary"]), count)
            for forbidden in ("trusted", "fresh", "admitted", "replayConsumed"):
                self.assertNotIn(forbidden, result)

    def test_request_artifact_envelope_and_crypto_bindings_are_exact(self):
        for path in ("artifact", "detachedEnvelope", "cryptographyRuntime"):
            module = load(f"repository_binding_{path}", MODULE_PATH.name)
            verifier = load(f"repository_binding_crypto_{path}", "checkout-repository-ed25519-verifier.py")
            capability = verifier.seal_backend(
                support.AmbientCryptographyAdapter(), copy.deepcopy(support.ACQUISITION)
            )
            request_raw, raw, envelopes, policy = self.values()
            request = json.loads(request_raw)
            if path == "artifact": request[path]["bytesDigest"] = "a" * 64
            elif path == "detachedEnvelope": request[path]["bytesDigest"] = "b" * 64
            else: request[path]["closureDigest"] = "d" * 64
            changed = request_codec.canonical_request(request)
            self.refused(lambda: module.compose(
                changed, raw, envelopes, policy, capability, verifier.verify
            ), module)

    def test_forged_or_mismatched_verifier_result_refuses(self):
        request_raw, raw, envelopes, policy = self.values()
        actual = self.verifier.verify

        def forged(*args):
            result = dict(actual(*args))
            result["artifactDigest"] = "f" * 64
            return MappingProxyType(result)

        self.refused(lambda: self.module.compose(
            request_raw, raw, envelopes, policy, self.capability, forged
        ))

    def test_policy_issuer_must_match_request_namespace(self):
        request_raw, raw, envelopes, policy = self.values()
        policy["artifactIssuerId"] = "different-issuer"
        self.refused(lambda: self.module.compose(
            request_raw, raw, envelopes, policy,
            self.capability, self.verifier.verify,
        ))

    def test_attempt_is_one_shot_on_success_and_failure(self):
        request_raw, raw, envelopes, policy = self.values()
        self.module.compose(
            request_raw, raw, envelopes, policy, self.capability, self.verifier.verify
        )
        self.refused(lambda: self.module.compose(
            request_raw, raw, envelopes, policy, self.capability, self.verifier.verify
        ))

        failed = load("repository_failed_once", MODULE_PATH.name)
        verifier = load("repository_failed_crypto", "checkout-repository-ed25519-verifier.py")
        capability = verifier.seal_backend(
            support.AmbientCryptographyAdapter(), copy.deepcopy(support.ACQUISITION)
        )
        self.refused(lambda: failed.compose(
            request_raw, raw + b"x", envelopes, policy, capability, verifier.verify
        ), failed)
        self.refused(lambda: failed.compose(
            request_raw, raw, envelopes, policy, capability, verifier.verify
        ), failed)

    def test_callable_and_codec_mutation_refuse_before_verifier(self):
        request_raw, raw, envelopes, policy = self.values()
        called = []
        original = self.module._REQUEST.decode
        try:
            self.module._REQUEST.decode = lambda *_args: called.append(True)
            self.refused(lambda: self.module.compose(
                request_raw, raw, envelopes, policy,
                self.capability, self.verifier.verify,
            ))
            self.assertEqual(called, [])
        finally:
            self.module._REQUEST.decode = original

    def test_verifier_baseexception_is_preserved_and_ordinary_error_redacted(self):
        for index, primary in enumerate((KeyboardInterrupt("k"), SystemExit("s"))):
            module = load(f"repository_stop_{index}", MODULE_PATH.name)
            request_raw, raw, envelopes, policy = self.values()

            def make_stop(error):
                def stop(*_args):
                    raise error
                return stop
            stop = make_stop(primary)
            with self.assertRaises(type(primary)) as raised:
                module.compose(request_raw, raw, envelopes, policy, object(), stop)
            self.assertIs(raised.exception, primary)

        module = load("repository_error", MODULE_PATH.name)
        request_raw, raw, envelopes, policy = self.values()
        def error(*_args): raise RuntimeError("secret native detail")
        self.refused(lambda: module.compose(
            request_raw, raw, envelopes, policy, object(), error
        ), module)

    def test_scope_has_no_crypto_discovery_or_authority_claim(self):
        source = MODULE_PATH.read_text(encoding="utf-8")
        self.assertNotIn("import cryptography", source)
        self.assertEqual(self.module.__all__, (
            "RepositoryVerifierCompositionRefused", "compose",
        ))
        for phrase in ("one-shot", "structural", "does not", "caller"):
            self.assertIn(phrase, (self.module.__doc__ or "").lower())


if __name__ == "__main__":
    unittest.main()
