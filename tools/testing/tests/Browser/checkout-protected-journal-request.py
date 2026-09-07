"""Pure structural request codec for a future protected-journal provider.

The codec validates supplied canonical bytes only.  Required collision-policy
path digests use SHA-256 over ``oncam.checkout.canonical-path.v1``, one NUL,
then exact canonical ASCII path bytes.  It does not read, write,
delete, lock, protect, or persist state; call DPAPI/TPM/ACL/native APIs; prove
atomicity or rollback protection; consume replay; grant admission; or run a
candidate.  Path-digest exclusions are supplied bindings, not filesystem
evidence.  A composition boundary must pin the exported callables.
"""

from __future__ import annotations

import hashlib
import json
import re
from types import MappingProxyType


KINDS = ("one-shot-ledger", "revocation-high-water")
ONE_SHOT_NAMESPACES = ("composition-admission", "preparation-authorization")
REVOCATION_ROLES = (
    "asset-review", "composition-admission", "preparation-acl",
    "preparation-authorization", "release-source", "revocation-snapshot",
    "runtime-configuration-policy", "tls-material", "tool-runtime-closure",
    "trust-root-bundle", "vendor-build",
)
MAX_REQUEST_BYTES = 32 * 1024
MAX_FORBIDDEN_PATH_DIGESTS = 32
ID_PATTERN = r"[a-z0-9](?:[a-z0-9._-]{0,127})"
ZERO_DIGEST = "0" * 64


class ProtectedJournalRequestRefused(Exception):
    """Fixed refusal without path, identity, provider, or state detail."""


__all__ = (
    "ProtectedJournalRequestRefused",
    "canonical_request",
    "decode",
)


def _make_codec():
    refusal = ProtectedJournalRequestRefused
    mapping_proxy_type = MappingProxyType
    kinds = KINDS
    one_shot_namespaces = ONE_SHOT_NAMESPACES
    revocation_roles = REVOCATION_ROLES
    maximum_request = MAX_REQUEST_BYTES
    maximum_forbidden = MAX_FORBIDDEN_PATH_DIGESTS
    identifier_pattern = ID_PATTERN
    zero_digest = ZERO_DIGEST
    public_surface = __all__
    top_keys = frozenset({
        "currentState", "expectedFileIdentity", "forbiddenPathDigests",
        "journalKind", "namespace", "operation", "policyDigest",
        "proposedState", "providerAuthorityDigest", "providerEvidenceDigest",
        "rebootState", "runIdentity", "securityDescriptorDigest", "version",
    })
    current_keys = frozenset({"generation", "rawDigest"})
    proposed_keys = frozenset({"generation", "previousDigest", "rawDigest"})
    identity_keys = frozenset({"fileId", "path", "volumeSerial"})
    leaves = {
        "one-shot-ledger": ".checkout-one-shot-ledger.journal",
        "revocation-high-water": ".checkout-revocation-high-water.journal",
    }
    reserved_names = frozenset({
        "con", "prn", "aux", "nul", "conin$", "conout$",
        *(f"com{index}" for index in range(1, 10)),
        *(f"lpt{index}" for index in range(1, 10)),
    })

    json_module = json
    hashlib_module = hashlib
    re_module = re
    json_dumps = json.dumps
    json_loads = json.loads
    sha256 = hashlib.sha256
    re_fullmatch = re.fullmatch
    module_globals = globals()

    def freeze_metadata(value):
        if value is None:
            return ("none",)
        if type(value) is bool:
            return ("bool", value)
        if type(value) is int:
            return ("int", value)
        if type(value) is str:
            return ("str", value)
        if type(value) is tuple:
            return ("tuple", tuple(freeze_metadata(item) for item in value))
        raise ValueError("metadata")

    def mapping_snapshot(value):
        if value is None:
            return None
        if type(value) is not dict or any(type(key) is not str for key in value):
            raise ValueError("metadata")
        return tuple((key, freeze_metadata(item)) for key, item in value.items())

    def mapping_matches(value, expected):
        if expected is None:
            return value is None
        if type(value) is not dict or len(value) != len(expected):
            return False
        for key, item in expected:
            if key not in value:
                return False
            try:
                if freeze_metadata(value[key]) != item:
                    return False
            except Exception:
                return False
        return True

    def callable_state(function):
        defaults = getattr(function, "__defaults__", None)
        kwdefaults = getattr(function, "__kwdefaults__", None)
        return (
            function, type(function), getattr(function, "__code__", None),
            defaults, freeze_metadata(defaults), kwdefaults,
            mapping_snapshot(kwdefaults), getattr(function, "__closure__", None),
            getattr(function, "__globals__", None),
        )

    dependencies = (
        (json_module, "dumps", callable_state(json_dumps)),
        (json_module, "loads", callable_state(json_loads)),
        (hashlib_module, "sha256", callable_state(sha256)),
        (re_module, "fullmatch", callable_state(re_fullmatch)),
    )
    global_pins = (
        ("KINDS", kinds),
        ("ONE_SHOT_NAMESPACES", one_shot_namespaces),
        ("REVOCATION_ROLES", revocation_roles),
        ("MAX_REQUEST_BYTES", maximum_request),
        ("MAX_FORBIDDEN_PATH_DIGESTS", maximum_forbidden),
        ("ID_PATTERN", identifier_pattern),
        ("ZERO_DIGEST", zero_digest),
        ("ProtectedJournalRequestRefused", refusal),
        ("MappingProxyType", mapping_proxy_type),
        ("__all__", public_surface),
        ("json", json_module), ("hashlib", hashlib_module), ("re", re_module),
    )

    def guard():
        try:
            for name, expected in global_pins:
                if module_globals.get(name) is not expected:
                    raise ValueError("authority")
            for module, name, state in dependencies:
                current = getattr(module, name, None)
                if current is not state[0] or type(current) is not state[1]:
                    raise ValueError("dependency")
                defaults = getattr(current, "__defaults__", None)
                kwdefaults = getattr(current, "__kwdefaults__", None)
                if getattr(current, "__code__", None) is not state[2] \
                        or defaults is not state[3] \
                        or freeze_metadata(defaults) != state[4] \
                        or kwdefaults is not state[5] \
                        or not mapping_matches(kwdefaults, state[6]) \
                        or getattr(current, "__closure__", None) is not state[7] \
                        or getattr(current, "__globals__", None) is not state[8]:
                    raise ValueError("dependency")
        except refusal:
            raise
        except Exception:
            raise refusal("protected_journal_request") from None

    def exact_dict(value, keys):
        return type(value) is dict \
            and all(type(key) is str for key in value) \
            and set(value) == keys

    def strict_object(pairs):
        guard()
        value = {}
        for key, item in pairs:
            if key in value:
                raise ValueError("duplicate")
            value[key] = item
        guard()
        return value

    def json_bytes(value):
        return (
            json_dumps(value, sort_keys=True, separators=(",", ":"),
                       ensure_ascii=True, allow_nan=False) + "\n"
        ).encode("ascii")

    def digest(value):
        return type(value) is str \
            and re_fullmatch(r"[a-f0-9]{64}", value) is not None

    def identifier(value):
        return type(value) is str \
            and re_fullmatch(identifier_pattern, value) is not None

    def positive_int63(value):
        return type(value) is int and 1 <= value < (1 << 63)

    def nonnegative_int63(value):
        return type(value) is int and 0 <= value < (1 << 63)

    def canonical_positive_decimal(value):
        return type(value) is str \
            and re_fullmatch(r"[1-9][0-9]{0,18}", value) is not None \
            and int(value) < (1 << 63)

    def decimal_uint128(value):
        return type(value) is str \
            and re_fullmatch(r"[1-9][0-9]{0,38}", value) is not None \
            and int(value) < (1 << 128)

    def canonical_path(value):
        if type(value) is not str or not 4 <= len(value) <= 4096 \
                or not "A" <= value[0] <= "Z" or value[1:3] != ":/" \
                or value.endswith("/") or "\\" in value or ":" in value[2:] \
                or any(ord(character) < 32 or ord(character) > 126 for character in value):
            return False
        parts = value[3:].split("/")
        if not parts or len(parts) > 64:
            return False
        for part in parts:
            if not part or part in (".", "..") or len(part) > 255 \
                    or part.endswith((".", " ")) \
                    or any(character in '<>"|?*' for character in part):
                return False
            if part.split(".", 1)[0].rstrip(" .").lower() in reserved_names:
                return False
        return True

    def identity(value):
        if not exact_dict(value, identity_keys) \
                or not canonical_path(value["path"]) \
                or not decimal_uint128(value["volumeSerial"]) \
                or not decimal_uint128(value["fileId"]):
            raise ValueError("identity")
        return value["path"], value["volumeSerial"], value["fileId"]

    def namespace_matches(kind, value):
        if not identifier(value):
            return False
        if kind == "one-shot-ledger":
            return value in one_shot_namespaces
        for role in revocation_roles:
            prefix = role + "."
            if not value.startswith(prefix):
                continue
            remainder = value[len(prefix):]
            issuer, key_marker, generation_tail = remainder.rpartition(".key-")
            key_generation, trust_marker, trust_generation = \
                generation_tail.partition(".trust-")
            return key_marker == ".key-" and trust_marker == ".trust-" \
                and identifier(issuer) \
                and canonical_positive_decimal(key_generation) \
                and canonical_positive_decimal(trust_generation)
        return False

    def validate(value):
        if not exact_dict(value, top_keys) \
                or type(value["version"]) is not int or value["version"] != 1 \
                or type(value["journalKind"]) is not str \
                or value["journalKind"] not in kinds \
                or type(value["operation"]) is not str \
                or value["operation"] != "compare-and-swap" \
                or type(value["rebootState"]) is not str \
                or value["rebootState"] != "certain" \
                or not namespace_matches(value["journalKind"], value["namespace"]):
            raise ValueError("request")
        current = value["currentState"]
        proposed = value["proposedState"]
        if not exact_dict(current, current_keys) \
                or not nonnegative_int63(current["generation"]) \
                or not digest(current["rawDigest"]) \
                or not exact_dict(proposed, proposed_keys) \
                or not positive_int63(proposed["generation"]) \
                or not digest(proposed["rawDigest"]) \
                or not digest(proposed["previousDigest"]):
            raise ValueError("state")
        if value["journalKind"] == "one-shot-ledger":
            generation_matches = \
                proposed["generation"] == current["generation"] + 1
        else:
            generation_matches = proposed["generation"] == 1 \
                if current["generation"] == 0 \
                else proposed["generation"] > current["generation"]
        if not generation_matches \
                or proposed["previousDigest"] != current["rawDigest"] \
                or proposed["rawDigest"] == current["rawDigest"] \
                or (current["generation"] == 0) != (current["rawDigest"] == zero_digest):
            raise ValueError("transition")
        for key in ("policyDigest", "providerAuthorityDigest",
                    "providerEvidenceDigest", "securityDescriptorDigest"):
            if not digest(value[key]):
                raise ValueError("digest")
        run = identity(value["runIdentity"])
        journal = identity(value["expectedFileIdentity"])
        if run[1] != journal[1] or run[2] == journal[2] \
                or journal[0] != run[0] + "/" + leaves[value["journalKind"]]:
            raise ValueError("journal_identity")
        forbidden = value["forbiddenPathDigests"]
        if type(forbidden) is not list or not 3 <= len(forbidden) <= maximum_forbidden \
                or any(not digest(item) for item in forbidden) \
                or forbidden != sorted(set(forbidden)):
            raise ValueError("forbidden")
        journal_path_digest = sha256(
            b"oncam.checkout.canonical-path.v1\0" + journal[0].encode("ascii")
        ).hexdigest()
        mandatory_paths = (
            run[0] + "/runtime.ini",
            run[0] + "/supervisor-config.json",
            run[0] + "/tls/server.key",
        )
        mandatory_digests = {
            sha256(
                b"oncam.checkout.canonical-path.v1\0" + path.encode("ascii")
            ).hexdigest()
            for path in mandatory_paths
        }
        if not mandatory_digests.issubset(forbidden) \
                or journal_path_digest in forbidden:
            raise ValueError("path_collision")
        return journal_path_digest

    def canonical_impl(value):
        validate(value)
        raw = json_bytes(value)
        if len(raw) > maximum_request:
            raise ValueError("size")
        return raw

    def decode_impl(raw):
        if type(raw) is not bytes or not 1 <= len(raw) <= maximum_request \
                or not raw.endswith(b"\n") or raw.endswith(b"\n\n"):
            raise ValueError("raw")
        value = json_loads(
            raw.decode("ascii"), object_pairs_hook=strict_object,
            parse_constant=lambda _value: (_ for _ in ()).throw(ValueError("constant")),
        )
        journal_path_digest = validate(value)
        if json_bytes(value) != raw:
            raise ValueError("canonical")
        return mapping_proxy_type({
            "structuralOnly": True,
            "journalKind": value["journalKind"],
            "namespace": value["namespace"],
            "currentGeneration": value["currentState"]["generation"],
            "proposedGeneration": value["proposedState"]["generation"],
            "currentStateDigest": value["currentState"]["rawDigest"],
            "proposedStateDigest": value["proposedState"]["rawDigest"],
            "previousStateDigest": value["proposedState"]["previousDigest"],
            "journalPathDigest": journal_path_digest,
            "requestDigest": sha256(raw).hexdigest(),
        })

    def invoke(operation, *args):
        try:
            guard()
            result = operation(*args)
            guard()
            return result
        except BaseException as primary:
            try:
                guard()
            except BaseException:
                pass
            if isinstance(primary, (KeyboardInterrupt, SystemExit)):
                raise
            raise refusal("protected_journal_request") from None

    def canonical_request(value):
        return invoke(canonical_impl, value)

    def decode(raw):
        return invoke(decode_impl, raw)

    return canonical_request, decode


canonical_request, decode = _make_codec()
del _make_codec
