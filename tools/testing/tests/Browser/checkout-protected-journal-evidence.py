"""Pure structural evidence codec for a future protected-journal provider.

The codec validates supplied canonical bytes only.  It does not perform journal
I/O, open or hold a journal handle, call DPAPI/TPM/ACL/native APIs, persist state, prove
atomicity or rollback protection, consume replay, grant admission, or run a
candidate.  Success-shaped fields are structural claims for a future native
provider and must be independently authenticated by composition.
The before/after file identities denote observations through one retained
handle immediately before the write and after the flush, not path-based
pre-existence evidence.
"""

from __future__ import annotations

import hashlib
import importlib.util
import json
from pathlib import Path
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
STATUSES = ("refused", "written-structural-evidence")
MAX_EVIDENCE_BYTES = 32 * 1024
ID_PATTERN = r"[a-z0-9](?:[a-z0-9._-]{0,127})"
ZERO_DIGEST = "0" * 64


class ProtectedJournalEvidenceRefused(Exception):
    """Fixed refusal without path, provider, state, or native error detail."""


__all__ = (
    "ProtectedJournalEvidenceRefused",
    "canonical_evidence",
    "decode",
)


def _load_request_codec():
    try:
        path = Path(__file__).resolve().with_name("checkout-protected-journal-request.py")
        spec = importlib.util.spec_from_file_location(
            "_checkout_protected_journal_request_for_evidence", path,
        )
        if spec is None or spec.loader is None:
            raise ValueError("request_codec")
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return path, module
    except Exception:
        raise ProtectedJournalEvidenceRefused("protected_journal_evidence") from None


_REQUEST_PATH, _REQUEST = _load_request_codec()
del _load_request_codec


def _make_codec():
    refusal = ProtectedJournalEvidenceRefused
    mapping_proxy_type = MappingProxyType
    kinds = KINDS
    one_shot_namespaces = ONE_SHOT_NAMESPACES
    revocation_roles = REVOCATION_ROLES
    statuses = STATUSES
    maximum_evidence = MAX_EVIDENCE_BYTES
    identifier_pattern = ID_PATTERN
    zero_digest = ZERO_DIGEST
    public_surface = __all__
    request_path = _REQUEST_PATH
    request_path_text = str(request_path)
    request_module = _REQUEST
    request_module_type = type(request_module)
    common_keys = frozenset({
        "afterState", "beforeState", "journalIdentity", "journalKind",
        "journalRequestDigest", "namespace", "operation", "policyDigest",
        "providerAuthorityDigest", "providerEvidenceDigest", "providerGeneration",
        "rebootState", "runIdentity", "securityDescriptorDigest", "status", "version",
    })
    success_keys = common_keys | frozenset({
        "afterFileIdentity", "beforeFileIdentity", "descriptorStable", "disposition",
        "flushFileBuffers", "heldHandle", "tokenStable", "writeThrough",
    })
    refused_keys = common_keys | frozenset({"refusalCode"})
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
    request_functions = (
        ("canonical_request", request_module.canonical_request,
         callable_state(request_module.canonical_request)),
        ("decode", request_module.decode, callable_state(request_module.decode)),
    )
    request_decode = request_module.decode
    request_globals = (
        ("KINDS", request_module.KINDS),
        ("ONE_SHOT_NAMESPACES", request_module.ONE_SHOT_NAMESPACES),
        ("REVOCATION_ROLES", request_module.REVOCATION_ROLES),
        ("MAX_REQUEST_BYTES", request_module.MAX_REQUEST_BYTES),
        ("MAX_FORBIDDEN_PATH_DIGESTS", request_module.MAX_FORBIDDEN_PATH_DIGESTS),
        ("ID_PATTERN", request_module.ID_PATTERN),
        ("ZERO_DIGEST", request_module.ZERO_DIGEST),
        ("ProtectedJournalRequestRefused", request_module.ProtectedJournalRequestRefused),
        ("MappingProxyType", request_module.MappingProxyType),
        ("__all__", request_module.__all__),
        ("json", request_module.json), ("hashlib", request_module.hashlib),
        ("re", request_module.re),
    )
    global_pins = (
        ("KINDS", kinds), ("ONE_SHOT_NAMESPACES", one_shot_namespaces),
        ("REVOCATION_ROLES", revocation_roles), ("STATUSES", statuses),
        ("MAX_EVIDENCE_BYTES", maximum_evidence), ("ID_PATTERN", identifier_pattern),
        ("ZERO_DIGEST", zero_digest), ("ProtectedJournalEvidenceRefused", refusal),
        ("MappingProxyType", mapping_proxy_type), ("__all__", public_surface),
        ("json", json_module), ("hashlib", hashlib_module), ("re", re_module),
        ("_REQUEST_PATH", request_path), ("_REQUEST", request_module),
    )

    def callable_matches(current, state):
        defaults = getattr(current, "__defaults__", None)
        kwdefaults = getattr(current, "__kwdefaults__", None)
        return current is state[0] and type(current) is state[1] \
            and getattr(current, "__code__", None) is state[2] \
            and defaults is state[3] and freeze_metadata(defaults) == state[4] \
            and kwdefaults is state[5] and mapping_matches(kwdefaults, state[6]) \
            and getattr(current, "__closure__", None) is state[7] \
            and getattr(current, "__globals__", None) is state[8]

    def request_guard():
        module_file = getattr(request_module, "__file__", None)
        if type(request_module) is not request_module_type \
                or type(module_file) is not str or module_file != request_path_text:
            raise ValueError("request_codec")
        for name, expected, state in request_functions:
            current = getattr(request_module, name, None)
            if current is not expected or not callable_matches(current, state):
                raise ValueError("request_codec")
        for name, expected in request_globals:
            current = getattr(request_module, name, None)
            if type(expected) in (int, str, bytes, type(None)):
                if type(current) is not type(expected) or current != expected:
                    raise ValueError("request_codec")
            elif current is not expected:
                raise ValueError("request_codec")

    def guard():
        try:
            for name, expected in global_pins:
                if module_globals.get(name) is not expected:
                    raise ValueError("authority")
            request_guard()
            for module, name, state in dependencies:
                current = getattr(module, name, None)
                if not callable_matches(current, state):
                    raise ValueError("dependency")
        except refusal:
            raise
        except Exception:
            raise refusal("protected_journal_evidence") from None

    def exact_dict(value, keys):
        return type(value) is dict \
            and all(type(key) is str for key in value) \
            and set(value) == keys

    def strict_object(pairs):
        guard()
        value = {}
        for key, item in pairs:
            if type(key) is not str or key in value:
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

    def validate_state(value, journal_kind):
        current = value["beforeState"]
        proposed = value["afterState"]
        if not exact_dict(current, current_keys) \
                or not nonnegative_int63(current["generation"]) \
                or not digest(current["rawDigest"]) \
                or not exact_dict(proposed, proposed_keys) \
                or not positive_int63(proposed["generation"]) \
                or not digest(proposed["rawDigest"]) \
                or not digest(proposed["previousDigest"]):
            raise ValueError("state")
        if journal_kind == "one-shot-ledger":
            generation_matches = proposed["generation"] == current["generation"] + 1
        else:
            generation_matches = proposed["generation"] == 1 \
                if current["generation"] == 0 \
                else proposed["generation"] > current["generation"]
        if not generation_matches \
                or proposed["previousDigest"] != current["rawDigest"] \
                or proposed["rawDigest"] == current["rawDigest"] \
                or (current["generation"] == 0) != (current["rawDigest"] == zero_digest):
            raise ValueError("transition")
        return current, proposed

    def validate(value):
        if type(value) is not dict or type(value.get("status")) is not str \
                or value["status"] not in statuses:
            raise ValueError("evidence")
        expected_keys = refused_keys if value["status"] == "refused" else success_keys
        if not exact_dict(value, expected_keys) \
                or type(value["version"]) is not int or value["version"] != 1 \
                or type(value["journalKind"]) is not str \
                or value["journalKind"] not in kinds \
                or type(value["operation"]) is not str \
                or value["operation"] != "compare-and-swap" \
                or type(value["rebootState"]) is not str \
                or value["rebootState"] != "certain" \
                or not namespace_matches(value["journalKind"], value["namespace"]):
            raise ValueError("evidence")
        for key in (
            "journalRequestDigest", "policyDigest", "providerAuthorityDigest",
            "providerEvidenceDigest", "securityDescriptorDigest",
        ):
            if not digest(value[key]):
                raise ValueError("digest")
        if not positive_int63(value["providerGeneration"]):
            raise ValueError("provider")
        current, proposed = validate_state(value, value["journalKind"])
        run = identity(value["runIdentity"])
        journal = identity(value["journalIdentity"])
        if run[1] != journal[1] or run[2] == journal[2] \
                or journal[0] != run[0] + "/" + leaves[value["journalKind"]]:
            raise ValueError("journal_identity")
        if value["status"] == "refused":
            if type(value["refusalCode"]) is not str \
                    or value["refusalCode"] != "journal_refused":
                raise ValueError("refusal")
        else:
            expected_disposition = "created-new" \
                if current["generation"] == 0 else "opened-existing"
            if type(value["disposition"]) is not str \
                    or value["disposition"] != expected_disposition:
                raise ValueError("disposition")
            for key in (
                "heldHandle", "writeThrough", "flushFileBuffers",
                "descriptorStable", "tokenStable",
            ):
                if value[key] is not True:
                    raise ValueError("success_claim")
            before_file = identity(value["beforeFileIdentity"])
            after_file = identity(value["afterFileIdentity"])
            if before_file != journal or after_file != journal:
                raise ValueError("file_identity")
        return current, proposed

    def validated_request(raw):
        request_guard()
        request_decode(raw)
        request_guard()
        request = json_loads(
            raw.decode("ascii"), object_pairs_hook=strict_object,
            parse_constant=lambda _value: (_ for _ in ()).throw(ValueError("constant")),
        )
        request_guard()
        return request

    def bind_request(request_raw, request, value):
        expected_request_digest = sha256(request_raw).hexdigest()
        pairs = (
            (value["journalRequestDigest"], expected_request_digest),
            (value["journalKind"], request["journalKind"]),
            (value["namespace"], request["namespace"]),
            (value["operation"], request["operation"]),
            (value["rebootState"], request["rebootState"]),
            (value["beforeState"], request["currentState"]),
            (value["afterState"], request["proposedState"]),
            (value["runIdentity"], request["runIdentity"]),
            (value["journalIdentity"], request["expectedFileIdentity"]),
            (value["securityDescriptorDigest"], request["securityDescriptorDigest"]),
            (value["policyDigest"], request["policyDigest"]),
            (value["providerAuthorityDigest"], request["providerAuthorityDigest"]),
            (value["providerEvidenceDigest"], request["providerEvidenceDigest"]),
            (value["providerGeneration"], request["providerGeneration"]),
        )
        if any(actual != expected for actual, expected in pairs):
            raise ValueError("request_binding")

    def canonical_impl(request_raw, value):
        request = validated_request(request_raw)
        validate(value)
        bind_request(request_raw, request, value)
        raw = json_bytes(value)
        if len(raw) > maximum_evidence:
            raise ValueError("size")
        return raw

    def decode_impl(request_raw, evidence_raw):
        request = validated_request(request_raw)
        if type(evidence_raw) is not bytes or not 1 <= len(evidence_raw) <= maximum_evidence \
                or not evidence_raw.endswith(b"\n") or evidence_raw.endswith(b"\n\n"):
            raise ValueError("raw")
        value = json_loads(
            evidence_raw.decode("ascii"), object_pairs_hook=strict_object,
            parse_constant=lambda _value: (_ for _ in ()).throw(ValueError("constant")),
        )
        current, proposed = validate(value)
        bind_request(request_raw, request, value)
        if json_bytes(value) != evidence_raw:
            raise ValueError("canonical")
        return mapping_proxy_type({
            "evidenceStructuralOnly": True,
            "status": value["status"],
            "journalRequestDigest": value["journalRequestDigest"],
            "journalKind": value["journalKind"],
            "namespace": value["namespace"],
            "beforeGeneration": current["generation"],
            "afterGeneration": proposed["generation"],
            "beforeStateDigest": current["rawDigest"],
            "afterStateDigest": proposed["rawDigest"],
            "previousStateDigest": proposed["previousDigest"],
            "providerGeneration": value["providerGeneration"],
            "evidenceDigest": sha256(evidence_raw).hexdigest(),
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
            raise refusal("protected_journal_evidence") from None

    def canonical_evidence(request_raw, value):
        return invoke(canonical_impl, request_raw, value)

    def decode(request_raw, evidence_raw):
        return invoke(decode_impl, request_raw, evidence_raw)

    return canonical_evidence, decode


canonical_evidence, decode = _make_codec()
del _make_codec
