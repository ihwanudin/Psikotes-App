import importlib.util
import json
from pathlib import Path
import unittest


MODULE_PATH = Path(__file__).with_name("checkout-ordinary-broker-start-identity.py")
SPEC = importlib.util.spec_from_file_location("checkout_ordinary_broker_start_identity", MODULE_PATH)
identity = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(identity)


def fixture():
    return {
        "accountSid": "S-1-5-21-111-222-333-1001",
        "authenticationId": [0xFFFFFFFF, -0x80000000],
        "manifestDigest": "1" * 64,
        "processCreationTime": "134174567890123456",
        "servicePid": 4294967295,
        "serviceSid": "S-1-5-80-1-2-3-4-5",
        "tokenId": [0, 0x7FFFFFFF],
    }


def json_bytes(value):
    return (json.dumps(value, sort_keys=True, separators=(",", ":"),
                       ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")


class BrokerStartIdentityTests(unittest.TestCase):
    def refused(self, operation):
        with self.assertRaisesRegex(identity.BrokerStartIdentityRefused,
                                    "^broker_start_identity$"):
            operation()

    def test_known_vector_and_exact_digest_preimage(self):
        raw = json_bytes(fixture())
        snapshot = identity.decode(raw)
        self.assertEqual(identity.canonical(fixture()), raw)
        self.assertEqual(snapshot.digest, "ad6ca699523098d7d55dcffc0792b4191c7c18b85dd6ac599c1ea5d58ff09d87")
        self.assertEqual(snapshot.servicePid, 4294967295)
        self.assertEqual(snapshot.processCreationTime, "134174567890123456")
        self.assertEqual(snapshot.authenticationId, (0xFFFFFFFF, -0x80000000))
        self.assertEqual(snapshot.tokenId, (0, 0x7FFFFFFF))

    def test_pid_creation_time_and_luid_boundaries_are_exact(self):
        for pid in (1, 4294967295):
            value = fixture(); value["servicePid"] = pid
            self.assertEqual(identity.decode(json_bytes(value)).servicePid, pid)
        for timestamp in ("1", "18446744073709551615"):
            value = fixture(); value["processCreationTime"] = timestamp
            self.assertEqual(identity.decode(json_bytes(value)).processCreationTime, timestamp)
        for field in ("authenticationId", "tokenId"):
            value = fixture(); value[field] = [0, -2147483648]; identity.canonical(value)
            value[field] = [4294967295, 2147483647]; identity.canonical(value)
        bad = (True, 0, -1, 4294967296, 1.0, None)
        for candidate in bad:
            value = fixture(); value["servicePid"] = candidate; self.refused(lambda v=value: identity.canonical(v))
        for candidate in ("0", "00", "01", "18446744073709551616", 1, True, "-1"):
            value = fixture(); value["processCreationTime"] = candidate; self.refused(lambda v=value: identity.canonical(v))
        for candidate in ((1, 2), [True, 0], [0, True], [-1, 0], [0, -2147483649],
                          [4294967296, 0], [0, 2147483648], [0], [0, 0, 0]):
            value = fixture(); value["tokenId"] = candidate; self.refused(lambda v=value: identity.canonical(v))

    def test_digest_and_sid_contracts_are_strict(self):
        for field, candidate in (("manifestDigest", "A" * 64),
                                 ("manifestDigest", "0" * 63),
                                 ("accountSid", "S-1-5-18-00"),
                                 ("accountSid", "s-1-5-18"),
                                 ("accountSid", "S-1-5-18"),
                                 ("serviceSid", "S-2-5-80-1"),
                                 ("serviceSid", "S-1-281474976710656-1"),
                                 ("serviceSid", "S-1-5-21-1"),
                                 ("serviceSid", "S-1-5-80-1"),
                                 ("serviceSid", "S-1-5-80-1-2-3-4"),
                                 ("serviceSid", "S-1-5-80-1-2-3-4-5-6"),
                                 ("serviceSid", "S-1-5-80-1-2-3-4-4294967296")):
            value = fixture(); value[field] = candidate
            self.refused(lambda v=value: identity.canonical(v))
        value = fixture(); value["serviceSid"] = value["accountSid"]
        self.refused(lambda: identity.canonical(value))

    def test_json_boundary_rejects_duplicate_extra_noncanonical_and_oversize(self):
        raw = json_bytes(fixture())
        document = fixture(); document["pid"] = 1
        variants = [raw[:-1], raw + b"\n", b"\xef\xbb\xbf" + raw,
                    json.dumps(fixture()).encode("ascii"), json_bytes(document),
                    b'{"servicePid":1,"servicePid":2}\n', b'{"servicePid":NaN}\n',
                    b"{" + b" " * identity.MAX_START_IDENTITY_BYTES + b"}\n", "not-bytes"]
        for candidate in variants:
            with self.subTest(candidate=repr(candidate)[:48]):
                self.refused(lambda c=candidate: identity.decode(c))

    def test_result_is_immutable_and_isolated_from_input(self):
        value = fixture(); result = identity.decode(identity.canonical(value))
        value["authenticationId"][0] = 0
        self.assertEqual(result.authenticationId, (0xFFFFFFFF, -0x80000000))
        with self.assertRaises((AttributeError, TypeError)):
            result.servicePid = 1

    def test_dependency_and_authority_tamper_fail_closed_without_reseal(self):
        raw = json_bytes(fixture())
        self.assertFalse(hasattr(identity, "_make_codec"))
        for module, name, replacement in (
                (identity.json, "loads", lambda *a, **k: fixture()),
                (identity.json, "dumps", lambda *a, **k: "{}"),
                (identity.hashlib, "sha256", lambda *a, **k: None),
                (identity.re, "fullmatch", lambda *a, **k: None)):
            original = getattr(module, name)
            try:
                setattr(module, name, replacement)
                self.refused(lambda: identity.decode(raw))
            finally:
                setattr(module, name, original)
        original = identity.BrokerStartIdentityRefused
        try:
            identity.BrokerStartIdentityRefused = Exception
            with self.assertRaises(original) as raised:
                identity.canonical({})
            self.assertEqual(str(raised.exception), "broker_start_identity")
        finally:
            identity.BrokerStartIdentityRefused = original
        original_code = identity.json.loads.__code__
        try:
            identity.json.loads.__code__ = original_code.replace()
            self.refused(lambda: identity.decode(raw))
        finally:
            identity.json.loads.__code__ = original_code

    def test_exception_boundary_preserves_base_exception_identity(self):
        invoke = next(cell.cell_contents for cell in identity.decode.__closure__
                      if callable(cell.cell_contents)
                      and getattr(cell.cell_contents, "__name__", "") == "invoke")
        ordinary = RuntimeError("sensitive")
        with self.assertRaisesRegex(identity.BrokerStartIdentityRefused,
                                    "^broker_start_identity$"):
            invoke(lambda _value: (_ for _ in ()).throw(ordinary), None)
        for primary in (KeyboardInterrupt("k"), SystemExit("s")):
            with self.assertRaises(type(primary)) as raised:
                invoke(lambda _value, error=primary: (_ for _ in ()).throw(error), None)
            self.assertIs(raised.exception, primary)

    def test_no_runtime_or_credential_surface(self):
        source = MODULE_PATH.read_text(encoding="utf-8")
        for forbidden in ("WinDLL", "ctypes", "subprocess", "socket", "password",
                          "credential", "username", "os.environ"):
            self.assertNotIn(forbidden, source)
        for key in ("password", "credential", "username", "logonSid", "modifiedId"):
            value = fixture(); value[key] = "forbidden"
            self.refused(lambda v=value: identity.canonical(v))


if __name__ == "__main__":
    unittest.main()
