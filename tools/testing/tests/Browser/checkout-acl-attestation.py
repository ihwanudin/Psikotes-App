"""Pure strict codec for checkout Windows ACL attestation contracts.

This module validates data only.  It does not inspect ACLs, touch the filesystem,
cache evidence, or establish that a Windows policy is effective.
"""

from __future__ import annotations

import hashlib
import json
import re


POLICY_DIGEST = "a63c221764f73a54e87513fc91cded6b3fa16825138f6b24b6118132829f4eeb"
MAX_REQUEST_BYTES = 16 * 1024
MAX_EVIDENCE_BYTES = 32 * 1024
MAX_PATH_CHARS = 4096
MAX_IDENTITY = (1 << 128) - 1
_DIGEST = re.compile(r"[a-f0-9]{64}")
_SESSION = re.compile(r"checkout-[a-f0-9]{32}")
_DECIMAL = re.compile(r"0|[1-9][0-9]{0,38}")
_TARGET_ROLES = ("coordinator", "run", "source")
_DOS_DEVICE = re.compile(r"(?:con|prn|aux|nul|com[1-9¹²³]|lpt[1-9¹²³])")


class AttestationRefused(Exception):
    """Fixed refusal codes only; never includes input or decoder details."""


def sha256_bytes(raw):
    if type(raw) is not bytes:
        raise AttestationRefused("attestation_encoding")
    return hashlib.sha256(raw).hexdigest()


def run_binding(canonical_run_path):
    if not _path(canonical_run_path):
        raise AttestationRefused("attestation_request")
    return hashlib.sha256(canonical_run_path.encode("utf-8")).hexdigest()


def _strict_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("duplicate")
        result[key] = value
    return result


def _json_bytes(value):
    try:
        return (json.dumps(
            value, sort_keys=True, separators=(",", ":"), ensure_ascii=True,
            allow_nan=False,
        ) + "\n").encode("ascii")
    except Exception:
        raise ValueError("json") from None


def _digest(value):
    return type(value) is str and _DIGEST.fullmatch(value) is not None


def _decimal(value):
    return type(value) is str and _DECIMAL.fullmatch(value) is not None \
        and int(value) <= MAX_IDENTITY


def _identity(value):
    return type(value) is dict and set(value) == {"volumeSerial", "fileId"} \
        and _decimal(value["volumeSerial"]) and _decimal(value["fileId"])


def _path(value):
    if type(value) is not str or not 1 <= len(value) <= MAX_PATH_CHARS \
            or "\\" in value or value.endswith("/") \
            or any(ord(character) < 32 or ord(character) == 127 for character in value):
        return False
    drive = re.match(r"^[a-z]:/", value)
    absolute = value.startswith("/") or drive is not None
    remainder = value[3:] if drive else value[1:]
    parts = remainder.split("/")
    if not absolute or ":" in remainder:
        return False
    for part in parts:
        if part in {"", ".", ".."} or part.endswith((".", " ")) \
                or any(character in '<>"|?*' for character in part):
            return False
        device_alias = part.split(".", 1)[0].casefold()
        if _DOS_DEVICE.fullmatch(device_alias) is not None:
            return False
    return True


def _sid(value):
    if type(value) is not str or len(value) > 184:
        return False
    parts = value.split("-")
    if len(parts) < 4 or parts[0] != "S" or any(_DECIMAL.fullmatch(part) is None for part in parts[1:]):
        return False
    numbers = [int(part) for part in parts[1:]]
    return numbers[0] <= 255 and numbers[1] <= (1 << 48) - 1 \
        and len(numbers[2:]) <= 15 and all(number <= (1 << 32) - 1 for number in numbers[2:])


def _request_shape(value):
    keys = {
        "version", "boundary", "phase", "session", "configBinding",
        "leaseBinding", "leaseIdentity", "policyDigest", "challenge", "targets",
    }
    if type(value) is not dict or set(value) != keys or type(value["version"]) is not int \
            or value["version"] != 1 or type(value["boundary"]) is not str \
            or value["boundary"] not in {"anchor", "execution"} \
            or type(value["phase"]) is not str or value["phase"] not in {"fresh", "recovery"} \
            or type(value["session"]) is not str or _SESSION.fullmatch(value["session"]) is None \
            or not _digest(value["configBinding"]) or not _digest(value["leaseBinding"]) \
            or type(value["policyDigest"]) is not str \
            or value["policyDigest"] != POLICY_DIGEST or not _digest(value["challenge"]):
        return False
    lease = value["leaseIdentity"]
    if type(lease) is not dict \
            or set(lease) != {"path", "coordinator", "descriptor", "run"} \
            or not _path(lease["path"]) or not _identity(lease["coordinator"]) \
            or not _identity(lease["descriptor"]) or not _identity(lease["run"]):
        return False
    targets = value["targets"]
    if type(targets) is not list or len(targets) != 3:
        return False
    for target, role in zip(targets, _TARGET_ROLES, strict=True):
        if type(target) is not dict or set(target) != {"role", "path"} \
                or type(target["role"]) is not str \
                or target["role"] != role or not _path(target["path"]):
            return False
    run_path = targets[1]["path"]
    return value["leaseBinding"] == run_binding(run_path) \
        and lease["path"] == run_path + "/.checkout-coordinator.lease" \
        and targets[2]["path"] == run_path + "/source"


def canonical_request(request):
    if not _request_shape(request):
        raise AttestationRefused("attestation_request")
    raw = _json_bytes(request)
    if len(raw) > MAX_REQUEST_BYTES:
        raise AttestationRefused("attestation_request_size")
    return raw


def _decode(raw, maximum, size_code, encoding_code, canonical):
    if type(raw) is not bytes or not 1 <= len(raw) <= maximum:
        raise AttestationRefused(size_code)
    try:
        value = json.loads(
            raw.decode("ascii"), object_pairs_hook=_strict_object,
            parse_constant=lambda _value: (_ for _ in ()).throw(ValueError("constant")),
        )
    except Exception:
        raise AttestationRefused(encoding_code) from None
    try:
        expected = canonical(value)
    except AttestationRefused:
        raise
    if raw != expected:
        raise AttestationRefused(encoding_code)
    return value


def decode_request(raw):
    return _decode(raw, MAX_REQUEST_BYTES, "attestation_request_size",
                   "attestation_request_encoding", canonical_request)


def request_digest(request):
    return sha256_bytes(canonical_request(request))


def lease_digest(lease_binding, lease_identity):
    if not _digest(lease_binding) or type(lease_identity) is not dict \
            or set(lease_identity) != {"path", "coordinator", "descriptor", "run"} \
            or not _path(lease_identity["path"]) \
            or not _identity(lease_identity["coordinator"]) \
            or not _identity(lease_identity["descriptor"]) or not _identity(lease_identity["run"]):
        raise AttestationRefused("attestation_request")
    return sha256_bytes(_json_bytes({"binding": lease_binding, "identity": lease_identity}))


def _evidence_target(value, expected):
    keys = {
        "role", "path", "volumeSerial", "fileId", "ownerSid", "daclDigest",
        "reparse", "policySatisfied",
    }
    return type(value) is dict and set(value) == keys \
        and type(value["role"]) is str \
        and type(value["path"]) is str \
        and value["role"] == expected["role"] and value["path"] == expected["path"] \
        and _decimal(value["volumeSerial"]) and _decimal(value["fileId"]) \
        and _sid(value["ownerSid"]) and _digest(value["daclDigest"]) \
        and type(value["reparse"]) is bool and value["reparse"] is False \
        and type(value["policySatisfied"]) is bool and value["policySatisfied"] is True


def _validate_evidence(evidence, request):
    if not _request_shape(request):
        raise AttestationRefused("attestation_request")
    keys = {"version", "requestDigest", "policyDigest", "leaseDigest", "targets"}
    if type(evidence) is not dict or set(evidence) != keys \
            or type(evidence["version"]) is not int or evidence["version"] != 1 \
            or not _digest(evidence["requestDigest"]) \
            or not _digest(evidence["policyDigest"]) \
            or not _digest(evidence["leaseDigest"]) \
            or type(evidence["targets"]) is not list or len(evidence["targets"]) != 3:
        raise AttestationRefused("attestation_evidence")
    for target, expected in zip(evidence["targets"], request["targets"], strict=True):
        if not _evidence_target(target, expected):
            raise AttestationRefused("attestation_evidence")
    if evidence["requestDigest"] != request_digest(request) \
            or evidence["policyDigest"] != POLICY_DIGEST \
            or evidence["leaseDigest"] != lease_digest(
                request["leaseBinding"], request["leaseIdentity"],
            ):
        raise AttestationRefused("attestation_evidence_binding")
    run = evidence["targets"][1]
    if {"volumeSerial": run["volumeSerial"], "fileId": run["fileId"]} \
            != request["leaseIdentity"]["run"]:
        raise AttestationRefused("attestation_evidence_binding")
    coordinator = evidence["targets"][0]
    if {"volumeSerial": coordinator["volumeSerial"], "fileId": coordinator["fileId"]} \
            != request["leaseIdentity"]["coordinator"]:
        raise AttestationRefused("attestation_evidence_binding")


def canonical_evidence(evidence, request):
    _validate_evidence(evidence, request)
    raw = _json_bytes(evidence)
    if len(raw) > MAX_EVIDENCE_BYTES:
        raise AttestationRefused("attestation_evidence_size")
    return raw


def decode_evidence(raw, request):
    return _decode(
        raw, MAX_EVIDENCE_BYTES, "attestation_evidence_size",
        "attestation_evidence_encoding", lambda value: canonical_evidence(value, request),
    )


def validate_boundary_pair(anchor_request, anchor_evidence, execution_request,
                           execution_evidence):
    try:
        canonical_evidence(anchor_evidence, anchor_request)
        canonical_evidence(execution_evidence, execution_request)
        if anchor_request["boundary"] != "anchor" or execution_request["boundary"] != "execution" \
                or anchor_request["challenge"] == execution_request["challenge"]:
            raise ValueError("boundary")
        varying = {"boundary", "challenge"}
        if {key: value for key, value in anchor_request.items() if key not in varying} \
                != {key: value for key, value in execution_request.items() if key not in varying}:
            raise ValueError("request")
        anchor_source = anchor_evidence["targets"][2]
        execution_source = execution_evidence["targets"][2]
        identity_keys = ("role", "path", "volumeSerial", "fileId")
        if tuple(anchor_source[key] for key in identity_keys) \
                != tuple(execution_source[key] for key in identity_keys):
            raise ValueError("source")
    except Exception:
        raise AttestationRefused("attestation_boundary") from None
    return True
