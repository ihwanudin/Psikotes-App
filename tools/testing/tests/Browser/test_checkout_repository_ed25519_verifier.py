"""Synthetic tests; ambient cryptography is an explicit non-authority adapter.

Official API: https://cryptography.io/en/latest/hazmat/primitives/asymmetric/ed25519/
``Ed25519PublicKey.verify(signature, data)`` returns ``None`` or raises
``InvalidSignature``.  Nothing in this test adapter authorizes ambient packages
for candidate or production use.
"""

import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import re
from types import MappingProxyType
import unittest

import cryptography
from cryptography.exceptions import InvalidSignature
from cryptography.hazmat.primitives.asymmetric.ed25519 import (
    Ed25519PrivateKey,
    Ed25519PublicKey,
)


MODULE_PATH = Path(__file__).with_name("checkout-repository-ed25519-verifier.py")
SPEC = importlib.util.spec_from_file_location("checkout_repository_ed25519_verifier", MODULE_PATH)
verifier = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(verifier)

RFC_SEED = bytes.fromhex("9d61b19deffd5a60ba844af492ec2cc4" "4449c5697b326919703bac031cae7f60")
RFC_PUBLIC = bytes.fromhex("d75a980182b10ab7d54bfed3c964073a" "0ee172f3daa62325af021a68f707511a")
RFC_EMPTY_SIGNATURE = bytes.fromhex(
    "e5564300c360ac729086e2cc806e828a84877f1eb8e5d974d873e06522490155"
    "5fb8821590a33bacc61e39701cf9b46bd25bf5f0595bbe24655141438e7a100b"
)


def canonical(value):
    return (json.dumps(value, sort_keys=True, separators=(",", ":"),
                       ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")


class AmbientCryptographyAdapter:
    __slots__ = ()

    def verify_ed25519(self, public_key, signature, message):
        try:
            Ed25519PublicKey.from_public_bytes(public_key).verify(signature, message)
            return True
        except InvalidSignature:
            return False


ACQUISITION = {
    "acquisitionEvidenceDigest": "a" * 64,
    "backendIdentityDigest": "b" * 64,
    "backendVersion": cryptography.__version__,
    "closureDigest": "c" * 64,
    "generation": 3,
    "implementation": "cryptography-ed25519-adapter-v1",
}
BINDING = {
    "cryptographyAcquisitionEvidenceDigest": "a" * 64,
    "cryptographyClosureDigest": "c" * 64,
    "cryptographyGeneration": 3,
}


def key(seed_byte, key_id, principal, generation=1):
    private = Ed25519PrivateKey.from_private_bytes(bytes([seed_byte]) * 32)
    public = private.public_key().public_bytes_raw()
    return private, {
        "keyGeneration": generation,
        "keyId": key_id,
        "principalId": principal,
        "publicKey": public.hex(),
    }


def artifact(role="release-source", replay_id="artifact-replay-01"):
    return canonical({"replayId": replay_id, "role": role, "version": 1})


def envelope(raw, role, signer, private, trust_generation):
    message = b"oncam.checkout." + role.encode("ascii") + b".v1\0" + raw
    return canonical({
        "algorithm": "ed25519",
        "artifactDigest": hashlib.sha256(raw).hexdigest(),
        "issuerId": signer["principalId"],
        "keyGeneration": signer["keyGeneration"],
        "keyId": signer["keyId"],
        "role": role,
        "signature": private.sign(message).hex(),
        "trustGeneration": trust_generation,
        "version": 1,
    })


def group(kind, trust_generation, records, threshold):
    return {
        "eligibleSigners": sorted(records, key=lambda item: item["keyId"]),
        "kind": kind,
        "threshold": threshold,
        "trustGeneration": trust_generation,
    }


def case(role="release-source", mode="ordinary"):
    raw = artifact(role)
    if mode == "ordinary":
        private, signer = key(1, "release-key-01", "release-issuer-01")
        groups = [group(role, 8, [signer], 1)]
        envelopes = [envelope(raw, role, signer, private, 8)]
        excluded = None; prior = None; artifact_issuer = "release-issuer-01"
    elif mode == "asset-review":
        first, one = key(2, "asset-key-01", "asset-reviewer-01")
        second, two = key(3, "asset-key-02", "asset-reviewer-02")
        groups = [group("asset-review", 8, [one, two], 2)]
        envelopes = [envelope(raw, role, one, first, 8), envelope(raw, role, two, second, 8)]
        excluded = "release-key-01"; prior = None; artifact_issuer = "asset-authority-01"
    elif mode == "revocation":
        values = [key(index, f"revocation-key-0{index}", f"revocation-custodian-0{index}")
                  for index in (4, 5, 6)]
        groups = [group("revocation", 8, [item[1] for item in values], 2)]
        envelopes = [envelope(raw, role, item[1], item[0], 8) for item in values[:2]]
        excluded = None; prior = None; artifact_issuer = "release-issuer-01"
    elif mode == "bootstrap":
        values = [key(index, f"root-key-0{index}", f"root-custodian-0{index}")
                  for index in (7, 8, 9)]
        groups = [group("current-root", 8, [item[1] for item in values], 2)]
        envelopes = [envelope(raw, role, item[1], item[0], 8) for item in values[:2]]
        excluded = None; prior = None; artifact_issuer = "trust-root-authority-01"
    else:
        old = [key(index, f"old-root-key-0{index}", f"old-root-0{index}")
               for index in (1, 2, 3)]
        new = [key(index, f"new-root-key-0{index}", f"new-root-0{index}")
               for index in (7, 8, 9)]
        groups = [
            group("old-root", 7, [item[1] for item in old], 2),
            group("new-root", 8, [item[1] for item in new], 2),
        ]
        envelopes = [envelope(raw, role, item[1], item[0], generation)
                     for values, generation in ((old[:2], 7), (new[:2], 8)) for item in values]
        excluded = None; prior = 7; artifact_issuer = "trust-root-authority-01"
    policy = {
        "artifactIssuerId": artifact_issuer,
        "excludedReleaseSourceKeyId": excluded,
        "groups": groups,
        "mode": mode,
        "priorTrustGeneration": prior,
    }
    return raw, envelopes, policy


class RepositoryEd25519VerifierTests(unittest.TestCase):
    def setUp(self):
        spec = importlib.util.spec_from_file_location(
            f"checkout_repository_ed25519_verifier_{id(self)}", MODULE_PATH
        )
        self.module = importlib.util.module_from_spec(spec); spec.loader.exec_module(self.module)
        self.adapter = AmbientCryptographyAdapter()
        self.capability = self.module.seal_backend(self.adapter, copy.deepcopy(ACQUISITION))

    def verify(self, role="release-source", mode="ordinary", consumed=()):
        raw, envelopes, policy = case(role, mode)
        return self.module.verify(
            self.capability, raw, envelopes, policy, copy.deepcopy(BINDING), tuple(consumed)
        )

    def assert_refused(self, callback):
        with self.assertRaisesRegex(self.module.RepositoryEd25519Refused,
                                    "^repository_ed25519_verifier$"):
            callback()

    def test_rfc8032_vector_and_exact_domain_message_verify(self):
        self.assertIsNone(Ed25519PublicKey.from_public_bytes(RFC_PUBLIC).verify(
            RFC_EMPTY_SIGNATURE, b""
        ))
        result = self.verify()
        self.assertIs(type(result), MappingProxyType)
        self.assertIs(result["cryptoVerifiedStructuralOnly"], True)
        self.assertEqual(result["matchedSignerCount"], 1)
        for forbidden in ("accepted", "admitted", "fresh", "trusted", "replayConsumed"):
            self.assertNotIn(forbidden, result)

    def test_rfc8032_invalid_signature_and_wrong_domain_role_digest_key_refuse(self):
        with self.assertRaises(InvalidSignature):
            Ed25519PublicKey.from_public_bytes(RFC_PUBLIC).verify(RFC_EMPTY_SIGNATURE, b"x")
        raw, envelopes, policy = case()
        mutations = []
        value = json.loads(envelopes[0])
        value["signature"] = ("0" if value["signature"][0] != "0" else "1") + value["signature"][1:]
        mutations.append(canonical(value))
        value = json.loads(envelopes[0]); value["role"] = "vendor-build"; mutations.append(canonical(value))
        value = json.loads(envelopes[0]); value["artifactDigest"] = "0" * 64; mutations.append(canonical(value))
        value = json.loads(envelopes[0]); value["keyId"] = "other-key"; mutations.append(canonical(value))
        for bad in mutations:
            self.assert_refused(lambda bad=bad: self.module.verify(
                self.capability, raw, [bad], policy, copy.deepcopy(BINDING), ()
            ))
        # A cryptographically valid signature over another domain must not pass.
        private, signer = key(1, "release-key-01", "release-issuer-01")
        value = json.loads(envelopes[0])
        wrong_message = b"oncam.checkout.vendor-build.v1\0" + raw
        value["signature"] = private.sign(wrong_message).hex()
        self.assert_refused(lambda: self.module.verify(
            self.capability, raw, [canonical(value)], policy, copy.deepcopy(BINDING), ()
        ))
        _, other_signer = key(12, "release-key-01", "release-issuer-01")
        policy["groups"][0]["eligibleSigners"][0]["publicKey"] = other_signer["publicKey"]
        self.assert_refused(lambda: self.module.verify(
            self.capability, raw, envelopes, policy, copy.deepcopy(BINDING), ()
        ))

    def test_threshold_modes_ordinary_asset_revocation_bootstrap_rotation(self):
        cases = (
            ("release-source", "ordinary", 1), ("asset-review", "asset-review", 2),
            ("revocation-snapshot", "revocation", 2),
            ("trust-root-bundle", "bootstrap", 2), ("trust-root-bundle", "rotation", 4),
        )
        for role, mode, count in cases:
            with self.subTest(mode=mode):
                self.assertEqual(self.verify(role, mode)["matchedSignerCount"], count)

    def test_missing_signature_threshold_duplicate_key_or_envelope_refuses(self):
        for role, mode in (("asset-review", "asset-review"),
                           ("revocation-snapshot", "revocation"),
                           ("trust-root-bundle", "bootstrap"),
                           ("trust-root-bundle", "rotation")):
            raw, envelopes, policy = case(role, mode)
            self.assert_refused(lambda: self.module.verify(
                self.capability, raw, envelopes[:-1], policy, copy.deepcopy(BINDING), ()
            ))
            self.assert_refused(lambda: self.module.verify(
                self.capability, raw, envelopes + [envelopes[0]], policy,
                copy.deepcopy(BINDING), ()
            ))

    def test_asset_excludes_release_key_and_revocation_excludes_artifact_issuer(self):
        raw, envelopes, policy = case("asset-review", "asset-review")
        policy["excludedReleaseSourceKeyId"] = policy["groups"][0]["eligibleSigners"][0]["keyId"]
        self.assert_refused(lambda: self.module.verify(
            self.capability, raw, envelopes, policy, copy.deepcopy(BINDING), ()
        ))
        raw, envelopes, policy = case("revocation-snapshot", "revocation")
        policy["artifactIssuerId"] = policy["groups"][0]["eligibleSigners"][0]["principalId"]
        self.assert_refused(lambda: self.module.verify(
            self.capability, raw, envelopes, policy, copy.deepcopy(BINDING), ()
        ))

    def test_cross_role_group_generation_and_rotation_reuse_refuse(self):
        raw, envelopes, policy = case("trust-root-bundle", "rotation")
        policy["groups"][1]["trustGeneration"] = 7
        self.assert_refused(lambda: self.module.verify(
            self.capability, raw, envelopes, policy, copy.deepcopy(BINDING), ()
        ))
        raw, envelopes, policy = case("asset-review", "asset-review")
        policy["groups"][0]["kind"] = "ordinary"
        self.assert_refused(lambda: self.module.verify(
            self.capability, raw, envelopes, policy, copy.deepcopy(BINDING), ()
        ))

    def test_supplied_replay_inputs_are_unique_and_never_consumed(self):
        self.assert_refused(lambda: self.verify(consumed=("artifact-replay-01",)))
        self.assert_refused(lambda: self.verify(consumed=("x", "x")))
        result = self.verify(consumed=("older-replay",))
        self.assertNotIn("replayConsumed", result)

    def test_acquisition_binding_must_exactly_match_sealed_backend(self):
        raw, envelopes, policy = case()
        for key in tuple(BINDING):
            changed = copy.deepcopy(BINDING)
            changed[key] = changed[key] + 1 if type(changed[key]) is int else "d" * 64
            self.assert_refused(lambda changed=changed: self.module.verify(
                self.capability, raw, envelopes, policy, changed, ()
            ))
        changed = copy.deepcopy(ACQUISITION); changed["implementation"] = "ambient"
        fresh = importlib.util.module_from_spec(SPEC)
        # A new module is required because sealing is deliberately one-shot.
        fresh_spec = importlib.util.spec_from_file_location("checkout_repo_ed_bad", MODULE_PATH)
        fresh = importlib.util.module_from_spec(fresh_spec); fresh_spec.loader.exec_module(fresh)
        with self.assertRaises(fresh.RepositoryEd25519Refused):
            fresh.seal_backend(AmbientCryptographyAdapter(), changed)

    def test_backend_is_one_shot_sealed_and_mutation_refuses_before_call(self):
        self.assert_refused(lambda: self.module.seal_backend(
            AmbientCryptographyAdapter(), copy.deepcopy(ACQUISITION)
        ))
        original = AmbientCryptographyAdapter.verify_ed25519; calls = []
        def replacement(*_args): calls.append(True); return True
        try:
            AmbientCryptographyAdapter.verify_ed25519 = replacement
            self.assert_refused(self.verify)
            self.assertEqual(calls, [])
        finally:
            AmbientCryptographyAdapter.verify_ed25519 = original

        self.module._sealed = None
        self.assert_refused(lambda: self.module.seal_backend(
            AmbientCryptographyAdapter(), copy.deepcopy(ACQUISITION)
        ))

    def test_capability_internal_state_rebinding_is_rejected(self):
        original = self.capability._acquisition
        try:
            object.__setattr__(self.capability, "_acquisition", tuple(list(original)))
            self.assert_refused(self.verify)
        finally:
            object.__setattr__(self.capability, "_acquisition", original)

    def test_backend_false_and_exceptions_are_fixed_redacted(self):
        for index, returned in enumerate((False, 1, None)):
            class ReturnAdapter:
                __slots__ = ()
                def verify_ed25519(self, *_args, value=returned): return value
            spec = importlib.util.spec_from_file_location(f"repo_return_{index}", MODULE_PATH)
            module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
            cap = module.seal_backend(ReturnAdapter(), copy.deepcopy(ACQUISITION))
            raw, envelopes, policy = case()
            with self.assertRaisesRegex(module.RepositoryEd25519Refused,
                                        "^repository_ed25519_verifier$"):
                module.verify(cap, raw, envelopes, policy, copy.deepcopy(BINDING), ())

        class ErrorAdapter:
            __slots__ = ()
            def verify_ed25519(self, *_args): raise RuntimeError("native detail")
        spec = importlib.util.spec_from_file_location("repo_error", MODULE_PATH)
        module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
        cap = module.seal_backend(ErrorAdapter(), copy.deepcopy(ACQUISITION))
        raw, envelopes, policy = case()
        with self.assertRaisesRegex(module.RepositoryEd25519Refused,
                                    "^repository_ed25519_verifier$"):
            module.verify(cap, raw, envelopes, policy, copy.deepcopy(BINDING), ())

    def test_direct_dependency_mutation_refuses_before_hostile_call(self):
        called = []
        original = self.module.json.loads

        def hostile(*_args, **_kwargs):
            called.append(True)
            return {}

        try:
            self.module.json.loads = hostile
            self.assert_refused(self.verify)
            self.assertEqual(called, [])
        finally:
            self.module.json.loads = original

        original_kwdefaults = self.module.json.loads.__kwdefaults__
        original_items = dict(original_kwdefaults)
        try:
            original_kwdefaults["parse_int"] = lambda _value: 1
            self.assert_refused(self.verify)
        finally:
            original_kwdefaults.clear()
            original_kwdefaults.update(original_items)
        self.assertEqual(self.verify()["matchedSignerCount"], 1)

    def test_owned_authority_mutations_refuse_before_crypto_and_restore(self):
        calls = []

        class CountingAdapter:
            __slots__ = ()
            def verify_ed25519(self, public_key, signature, message):
                calls.append(True)
                return AmbientCryptographyAdapter().verify_ed25519(public_key, signature, message)

        spec = importlib.util.spec_from_file_location("repo_authority", MODULE_PATH)
        module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
        cap = module.seal_backend(CountingAdapter(), copy.deepcopy(ACQUISITION))
        raw, envelopes, policy = case()
        verify_call = module.verify
        refusal = module.RepositoryEd25519Refused
        mutations = (
            ("_ERROR", "changed"),
            ("_IMPLEMENTATION", "changed"),
            ("_MAX_ARTIFACT", 1),
            ("_MAX_ENVELOPE", 1),
            ("_IDENTIFIER", re.compile(r".*")),
            ("_HEX64", re.compile(r".*")),
            ("_HEX128", re.compile(r".*")),
            ("MappingProxyType", lambda value: value),
            ("RepositoryEd25519Refused", RuntimeError),
            ("__all__", tuple()),
            ("verify", lambda *_args: None),
            ("_refuse", lambda: None),
            ("_policy", lambda *_args: ("ordinary", tuple())),
            ("_artifact", lambda _raw: {"role": "release-source", "replayId": "x"}),
            ("_invoke", lambda *_args: None),
            ("_Capability", object),
            ("_DEPENDENCIES", tuple()),
        )
        for name, hostile in mutations:
            original = getattr(module, name)
            try:
                setattr(module, name, hostile)
                with self.assertRaisesRegex(refusal, "^repository_ed25519_verifier$"):
                    verify_call(cap, raw, envelopes, policy, copy.deepcopy(BINDING), ())
                self.assertEqual(calls, [])
            finally:
                setattr(module, name, original)
        self.assertEqual(verify_call(
            cap, raw, envelopes, policy, copy.deepcopy(BINDING), ()
        )["matchedSignerCount"], 1)
        self.assertEqual(calls, [True])

    def test_exported_seal_callable_rebinding_is_detected(self):
        spec = importlib.util.spec_from_file_location("repo_seal_export", MODULE_PATH)
        module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
        seal_call = module.seal_backend
        module.seal_backend = lambda *_args: object()
        with self.assertRaisesRegex(module.RepositoryEd25519Refused,
                                    "^repository_ed25519_verifier$"):
            seal_call(AmbientCryptographyAdapter(), copy.deepcopy(ACQUISITION))

    def test_baseexception_identity_is_preserved(self):
        original = AmbientCryptographyAdapter.verify_ed25519
        for primary in (KeyboardInterrupt("k"), SystemExit("s")):
            def stop(*_args, error=primary): raise error
            try:
                AmbientCryptographyAdapter.verify_ed25519 = stop
                # Reseal a fresh module so the throwing callable is the pinned backend.
                spec = importlib.util.spec_from_file_location(f"repo_stop_{id(primary)}", MODULE_PATH)
                module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
                cap = module.seal_backend(AmbientCryptographyAdapter(), copy.deepcopy(ACQUISITION))
                raw, envelopes, policy = case()
                with self.assertRaises(type(primary)) as raised:
                    module.verify(cap, raw, envelopes, policy, copy.deepcopy(BINDING), ())
                self.assertIs(raised.exception, primary)
            finally:
                AmbientCryptographyAdapter.verify_ed25519 = original

    def test_no_implicit_crypto_import_or_authority_surface(self):
        source = MODULE_PATH.read_text(encoding="utf-8")
        self.assertNotIn("import cryptography", source)
        self.assertNotIn("from cryptography", source)
        self.assertEqual(self.module.__all__, (
            "RepositoryEd25519Refused", "seal_backend", "verify",
        ))
        text = (self.module.__doc__ or "").lower()
        for phrase in ("caller-supplied", "structural", "does not", "ambient"):
            self.assertIn(phrase, text)


if __name__ == "__main__":
    unittest.main()
