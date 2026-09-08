import base64
import copy
import hashlib
import importlib.util
import json
from pathlib import Path
from types import MappingProxyType
import unittest


MODULE_PATH = Path(__file__).with_name("checkout-tls-material-evidence.py")
SPEC = importlib.util.spec_from_file_location("checkout_tls_material_evidence", MODULE_PATH)
tls = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(tls)


DOMAIN = b"oncam.checkout.tls-material-evidence.v1\0"
STATIC_ARGS = (
    "--host-resolver-rules=MAP psikotes.oncam.id 127.0.0.1,MAP oncam.id 127.0.0.1,MAP * ~NOTFOUND",
    "--no-proxy-server",
    "--disable-background-networking",
)


def canonical(value):
    return (json.dumps(value, sort_keys=True, separators=(",", ":"),
                       ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")


def evidence_fixture():
    value = {
        "version": 1,
        "role": "tls-material",
        "issuerId": "tls-evidence-custodian-01",
        "artifactId": "tls-material-20260908.1",
        "generation": 7,
        "issuedAt": "2026-09-08T02:03:04Z",
        "expiresAt": "2026-09-09T01:58:04Z",
        "replayId": "tls-material-20260908.1-attempt-01",
        "requestDigest": "1" * 64,
        "preparationBindingDigest": "2" * 64,
        "runtimeEvidenceDigest": "3" * 64,
        "runtimeConfigurationPolicyDigest": "4" * 64,
        "runIdentityDigest": "5" * 64,
        "leaseIdentityDigest": "6" * 64,
        "certificateSha256": "7" * 64,
        "spkiSha256": "8" * 64,
        "serialHex": "1a2b3c",
        "publicKeyAlgorithm": "rsa-3072",
        "signatureAlgorithm": "sha256WithRSAEncryption",
        "subjectDigest": "9" * 64,
        "sanPolicy": "psikotes-two-dns-v1",
        "notBefore": "2026-09-08T01:58:04Z",
        "notAfter": "2026-09-09T01:58:04Z",
        "certificatePolicyDigest": "a" * 64,
    }
    value["evidenceDigest"] = hashlib.sha256(DOMAIN + canonical(value)).hexdigest()
    return value


def config_fixture(evidence):
    spki = base64.b64encode(bytes.fromhex(evidence["spkiSha256"])).decode("ascii")
    return {
        "version": 1,
        "schema": "checkout-browser-tls-config",
        "publicOrigin": "https://psikotes.oncam.id",
        "publicTlsEvidenceDigest": evidence["evidenceDigest"],
        "runtimeConfigurationPolicyDigest": evidence["runtimeConfigurationPolicyDigest"],
        "runIdentityDigest": evidence["runIdentityDigest"],
        "launchArguments": list(STATIC_ARGS),
        "transportException": {
            "argument": "--ignore-certificate-errors-spki-list=" + spki,
            "spkiSha256": evidence["spkiSha256"],
        },
        "processProfile": {
            "disposable": True,
            "mode": "persistent-context",
            "relativeName": "browser-profile",
            "runIdentityDigest": evidence["runIdentityDigest"],
        },
        "contextOptions": {
            "ignoreHTTPSErrors": False,
            "serviceWorkers": "block",
        },
    }


class TlsMaterialEvidenceTests(unittest.TestCase):
    def assert_evidence_refused(self, candidate):
        raw = canonical(candidate) if type(candidate) is dict else candidate
        with self.assertRaisesRegex(
            tls.TlsMaterialEvidenceRefused, "^tls_material_evidence$"
        ):
            tls.decode(raw)

    def assert_config_refused(self, evidence, candidate):
        evidence_raw = canonical(evidence)
        raw = canonical(candidate) if type(candidate) is dict else candidate
        with self.assertRaisesRegex(
            tls.TlsMaterialEvidenceRefused, "^tls_material_evidence$"
        ):
            tls.bind_browser_config(evidence_raw, raw)

    def test_evidence_is_exact_canonical_immutable_and_structural_only(self):
        value = evidence_fixture()
        raw = canonical(value)
        result = tls.decode(raw)

        self.assertEqual(tls.canonical_evidence(value), raw)
        self.assertIs(type(result), MappingProxyType)
        self.assertIs(result["structuralOnly"], True)
        self.assertEqual(result["role"], "tls-material")
        self.assertEqual(result["evidenceDigest"], value["evidenceDigest"])
        self.assertEqual(result["artifactDigest"], hashlib.sha256(raw).hexdigest())
        self.assertEqual(
            result["evidenceDigest"],
            "8144d5fc11a687245a828ae6f152949f9b95044eb403a0f6040533a0056b297d",
        )
        self.assertEqual(
            result["artifactDigest"],
            "614baf72a9492f9139c67acee0dc5f636a8e98293d2961d804a0b267ac48d565",
        )
        with self.assertRaises(TypeError):
            result["authenticated"] = True
        for forbidden in (
            "authenticated", "privateKey", "privateKeyPath", "trusted", "verified"
        ):
            self.assertNotIn(forbidden, result)

    def test_evidence_schema_and_json_encoding_are_closed_and_bounded(self):
        value = evidence_fixture()
        for key in tuple(value):
            changed = copy.deepcopy(value)
            del changed[key]
            self.assert_evidence_refused(changed)
        for extra in (
            "certificate", "certificatePath", "privateKeyHash", "privateKeyPath",
            "password", "handle", "consumerAcknowledgment",
        ):
            changed = copy.deepcopy(value)
            changed[extra] = "forbidden"
            self.assert_evidence_refused(changed)
        raw = canonical(value)
        for bad in (
            raw[:-1], raw + b"\n", b"\xef\xbb\xbf" + raw,
            json.dumps(value).encode("ascii"), b'{"version":1,"version":1}\n',
            b'{"generation":NaN}\n', b"[]\n", "not-bytes", bytearray(raw),
            b"{" + b" " * (32 * 1024) + b"}\n",
        ):
            self.assert_evidence_refused(bad)

    def test_identity_algorithm_serial_and_timestamp_policy_are_exact(self):
        for key, bad in (
            ("version", 2), ("version", True), ("role", "tls"),
            ("issuerId", "Issuer"), ("artifactId", "../artifact"),
            ("replayId", "replay id"), ("generation", 0), ("generation", True),
            ("publicKeyAlgorithm", "rsa-2048"),
            ("signatureAlgorithm", "ed25519"),
            ("sanPolicy", "localhost-v1"), ("serialHex", "0"),
            ("serialHex", "01"), ("serialHex", "AB"),
        ):
            changed = evidence_fixture()
            changed[key] = bad
            self.assert_evidence_refused(changed)
        for key in (
            "requestDigest", "preparationBindingDigest", "runtimeEvidenceDigest",
            "runtimeConfigurationPolicyDigest", "runIdentityDigest",
            "leaseIdentityDigest", "certificateSha256", "spkiSha256",
            "subjectDigest", "certificatePolicyDigest",
        ):
            for bad in ("A" * 64, "0" * 63, "g" * 64, True):
                changed = evidence_fixture()
                changed[key] = bad
                self.assert_evidence_refused(changed)
        for key, bad in (
            ("issuedAt", "2026-09-08T02:03:04+00:00"),
            ("expiresAt", "2026-09-08T02:03:04Z"),
            ("notBefore", "2026-09-08T01:58:05Z"),
            ("notAfter", "2026-09-09T01:58:05Z"),
            ("notAfter", "2026-09-08T01:58:04Z"),
        ):
            changed = evidence_fixture()
            changed[key] = bad
            self.assert_evidence_refused(changed)

    def test_evidence_digest_is_domain_separated_exact_and_sensitive(self):
        value = evidence_fixture()
        self.assertEqual(
            value["evidenceDigest"],
            hashlib.sha256(DOMAIN + canonical({k: v for k, v in value.items()
                                               if k != "evidenceDigest"})).hexdigest(),
        )
        changed = evidence_fixture()
        changed["spkiSha256"] = "b" * 64
        self.assert_evidence_refused(changed)
        changed["evidenceDigest"] = hashlib.sha256(
            DOMAIN + canonical({k: v for k, v in changed.items()
                                if k != "evidenceDigest"})
        ).hexdigest()
        self.assertNotEqual(
            tls.decode(canonical(value))["evidenceDigest"],
            tls.decode(canonical(changed))["evidenceDigest"],
        )

    def test_browser_config_binds_one_spki_runtime_policy_and_disposable_profile(self):
        evidence = evidence_fixture()
        value = config_fixture(evidence)
        raw = canonical(value)
        result = tls.bind_browser_config(canonical(evidence), raw)

        self.assertEqual(tls.canonical_browser_config(canonical(evidence), value), raw)
        self.assertIs(type(result), MappingProxyType)
        self.assertIs(result["structuralOnly"], True)
        self.assertEqual(result["spkiSha256"], evidence["spkiSha256"])
        self.assertEqual(result["profileRelativeName"], "browser-profile")
        self.assertEqual(result["publicOrigin"], "https://psikotes.oncam.id")
        self.assertEqual(result["effectiveArgumentCount"], 4)
        self.assertIs(result["ignoreHTTPSErrors"], False)
        self.assertEqual(
            result["effectiveArgumentsDigest"],
            "1141ac975ba57fc8b00e4893f1a98070749992cb51b26d766845d06729447112",
        )
        self.assertEqual(
            result["browserConfigDigest"],
            "989392305bc536da5847e6833092e1a54046f5319e3bb224cfc5bc14cf1e96e1",
        )
        self.assertNotIn("userDataDirPath", result)
        self.assertNotIn("launch", result)

    def test_browser_config_rejects_drift_bypass_extra_spki_and_profile_reuse(self):
        evidence = evidence_fixture()
        value = config_fixture(evidence)
        mutations = (
            lambda v: v.update(publicOrigin="http://127.0.0.1"),
            lambda v: v.update(publicTlsEvidenceDigest="f" * 64),
            lambda v: v.update(runtimeConfigurationPolicyDigest="f" * 64),
            lambda v: v.update(runIdentityDigest="f" * 64),
            lambda v: v["contextOptions"].update(ignoreHTTPSErrors=True),
            lambda v: v["processProfile"].update(disposable=False),
            lambda v: v["processProfile"].update(mode="ephemeral-context"),
            lambda v: v["processProfile"].update(relativeName="../profile"),
            lambda v: v["transportException"].update(spkiSha256="f" * 64),
            lambda v: v["transportException"].update(argument="--ignore-certificate-errors"),
            lambda v: v["launchArguments"].append("--ignore-certificate-errors"),
            lambda v: v["launchArguments"].append(
                "--ignore-certificate-errors-spki-list=" + "A" * 44
            ),
        )
        for mutate in mutations:
            changed = copy.deepcopy(value)
            mutate(changed)
            self.assert_config_refused(evidence, changed)
        for section in ("transportException", "processProfile", "contextOptions"):
            changed = copy.deepcopy(value)
            changed[section]["extra"] = True
            self.assert_config_refused(evidence, changed)
        changed = copy.deepcopy(value)
        changed["extra"] = True
        self.assert_config_refused(evidence, changed)

    def test_browser_config_requires_exact_canonical_bytes_and_current_evidence(self):
        evidence = evidence_fixture()
        value = config_fixture(evidence)
        for key in tuple(value):
            changed = copy.deepcopy(value)
            del changed[key]
            self.assert_config_refused(evidence, changed)
        raw = canonical(value)
        for bad in (raw[:-1], raw + b"\n", b"{} {}\n", b"[]\n", "not-bytes"):
            self.assert_config_refused(evidence, bad)
        changed_evidence = evidence_fixture()
        changed_evidence["spkiSha256"] = "b" * 64
        changed_evidence["evidenceDigest"] = hashlib.sha256(
            DOMAIN + canonical({k: v for k, v in changed_evidence.items()
                                if k != "evidenceDigest"})
        ).hexdigest()
        self.assert_config_refused(changed_evidence, value)

        class StringSubclass(str):
            pass

        for section, key in (
            (None, "publicTlsEvidenceDigest"),
            (None, "runtimeConfigurationPolicyDigest"),
            (None, "runIdentityDigest"),
            ("transportException", "argument"),
            ("transportException", "spkiSha256"),
            ("processProfile", "runIdentityDigest"),
        ):
            changed = copy.deepcopy(value)
            target = changed if section is None else changed[section]
            target[key] = StringSubclass(target[key])
            with self.subTest(section=section, key=key):
                with self.assertRaises(tls.TlsMaterialEvidenceRefused):
                    tls.canonical_browser_config(canonical(evidence), changed)

    def test_dependency_and_authority_rebinding_fail_closed(self):
        raw = canonical(evidence_fixture())
        refusal = tls.TlsMaterialEvidenceRefused
        for owner, name, replacement in (
            (tls, "ROLE", "tls"), (tls, "MAX_EVIDENCE_BYTES", 1),
            (tls, "STATIC_BROWSER_ARGS", tuple(list(tls.STATIC_BROWSER_ARGS))),
            (tls, "TlsMaterialEvidenceRefused", Exception),
            (tls, "MappingProxyType", dict), (tls, "json", object()),
            (tls, "hashlib", object()), (tls, "base64", object()),
            (tls, "__all__", tuple(list(tls.__all__))),
        ):
            original = getattr(owner, name)
            try:
                setattr(owner, name, replacement)
                with self.assertRaises(refusal):
                    tls.decode(raw)
            finally:
                setattr(owner, name, original)

    def test_baseexception_identity_survives_postcheck_drift(self):
        invoke = next(cell.cell_contents for cell in tls.decode.__closure__
                      if callable(cell.cell_contents)
                      and getattr(cell.cell_contents, "__name__", "") == "invoke")
        original = tls.ROLE
        for primary in (KeyboardInterrupt("k"), SystemExit("s")):
            def drift_then_stop(error=primary):
                tls.ROLE = "drift"
                raise error
            try:
                with self.assertRaises(type(primary)) as raised:
                    invoke(drift_then_stop)
                self.assertIs(raised.exception, primary)
            finally:
                tls.ROLE = original

    def test_public_surface_is_exact_and_non_authorizing(self):
        self.assertEqual(tls.__all__, (
            "TlsMaterialEvidenceRefused", "canonical_evidence", "decode",
            "canonical_browser_config", "bind_browser_config",
        ))
        for forbidden in (
            "authenticate", "launch", "materialize", "sign", "verify_signature"
        ):
            self.assertFalse(hasattr(tls, forbidden))
        text = (tls.__doc__ or "").lower()
        for phrase in ("structural", "does not", "browser", "tls"):
            self.assertIn(phrase, text)


if __name__ == "__main__":
    unittest.main()
