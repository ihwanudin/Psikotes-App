"""Pure canonical summary contract for checkout ACL source-tree evidence.

This module validates supplied data only. It does not traverse a filesystem or
claim that file contents or an ACL policy have been independently verified.
"""

from __future__ import annotations

import copy
import hashlib
import json
import re


MAX_RECORDS = 8192
MAX_DEPTH = 64
MAX_PATH_BYTES = 1024
MAX_CANONICAL_RECORD_BYTES = 8 * 1024 * 1024
MAX_IDENTITY = (1 << 128) - 1
ALGORITHM = "sha256-canonical-json-v2"
DOMAIN = "oncam.checkout.acl-source-tree.v2"

_DIGEST = re.compile(r"[a-f0-9]{64}")
_DECIMAL = re.compile(r"0|[1-9][0-9]{0,38}")
_DOS_DEVICE = re.compile(r"(?:con|prn|aux|nul|com[1-9¹²³]|lpt[1-9¹²³])")
_RECORD_KEYS = {
    "relativePath", "kind", "volumeSerial", "fileId", "ownerSid", "daclDigest",
}
_SUMMARY_KEYS = {"algorithm", "descendantCount", "digest"}


class SourceTreeRefused(Exception):
    """Fixed refusal only; never includes paths or supplied values."""


def _refuse():
    raise SourceTreeRefused("acl_source_tree") from None


def _digest(value):
    return type(value) is str and _DIGEST.fullmatch(value) is not None


def _decimal(value):
    return type(value) is str and _DECIMAL.fullmatch(value) is not None \
        and int(value) <= MAX_IDENTITY


def _validated_root_identity(value):
    if type(value) is not dict or set(value) != {"volumeSerial", "fileId"} \
            or not _decimal(value["volumeSerial"]) \
            or not _decimal(value["fileId"]):
        raise ValueError("root identity")
    return {
        "volumeSerial": value["volumeSerial"],
        "fileId": value["fileId"],
    }


def _sid(value):
    if type(value) is not str or len(value) > 184:
        return False
    parts = value.split("-")
    if len(parts) < 4 or parts[0] != "S" \
            or any(_DECIMAL.fullmatch(part) is None for part in parts[1:]):
        return False
    numbers = [int(part) for part in parts[1:]]
    return numbers[0] == 1 and numbers[1] <= (1 << 48) - 1 \
        and len(numbers[2:]) <= 15 \
        and all(number <= (1 << 32) - 1 for number in numbers[2:])


def _path_parts(value):
    if type(value) is not str or not value \
            or value.startswith("/") or value.endswith("/") \
            or "\\" in value or ":" in value \
            or any(character in '<>"|?*' or not 32 <= ord(character) <= 126
                   for character in value):
        raise ValueError("path")
    raw = value.encode("utf-8", errors="strict")
    parts = value.split("/")
    if len(raw) > MAX_PATH_BYTES or not 1 <= len(parts) <= MAX_DEPTH:
        raise ValueError("path")
    for part in parts:
        if not part or len(part.encode("ascii")) > 255 \
                or part in {".", ".."} or part.endswith((".", " ")) \
                or _DOS_DEVICE.fullmatch(
                    part.split(".", 1)[0].rstrip(" .").casefold()
                ) is not None:
            raise ValueError("path")
    return parts


def _collision_key(parts):
    return tuple(part.casefold() for part in parts)


def _validated_manifest(manifest):
    if type(manifest) is not dict or not manifest or len(manifest) > MAX_RECORDS:
        raise ValueError("manifest")
    files = set()
    directories = set()
    spelling = {}
    entries = []
    for path, digest in manifest.items():
        parts = _path_parts(path)
        if not _digest(digest):
            raise ValueError("manifest")
        for end in range(1, len(parts) + 1):
            prefix_parts = parts[:end]
            prefix = "/".join(prefix_parts)
            key = _collision_key(prefix_parts)
            if key in spelling and spelling[key] != prefix:
                raise ValueError("collision")
            spelling[key] = prefix
        files.add(path)
        if len(files) + len(directories) > MAX_RECORDS:
            raise ValueError("manifest")
        for end in range(1, len(parts)):
            directories.add("/".join(parts[:end]))
            if len(files) + len(directories) > MAX_RECORDS:
                raise ValueError("manifest")
        entries.append({"relativePath": path, "contentDigest": digest})
    if files & directories or len(files) + len(directories) > MAX_RECORDS:
        raise ValueError("manifest")
    return files, directories, sorted(entries, key=lambda item: (
        _collision_key(item["relativePath"].split("/")), item["relativePath"],
    ))


def _validated_records(records, files, directories, root_identity):
    if type(records) is not list or not records or len(records) > MAX_RECORDS:
        raise ValueError("records")
    paths = set()
    file_paths = set()
    directory_paths = set()
    identities = {
        (root_identity["volumeSerial"], root_identity["fileId"]),
    }
    spellings = {}
    copied = []
    for record in records:
        if type(record) is not dict or set(record) != _RECORD_KEYS:
            raise ValueError("record")
        path = record["relativePath"]
        parts = _path_parts(path)
        key = _collision_key(parts)
        if path in paths or key in spellings and spellings[key] != path:
            raise ValueError("collision")
        paths.add(path)
        spellings[key] = path
        kind = record["kind"]
        if type(kind) is not str or kind not in {"file", "directory"} \
                or not _decimal(record["volumeSerial"]) \
                or not _decimal(record["fileId"]) \
                or not _sid(record["ownerSid"]) \
                or not _digest(record["daclDigest"]):
            raise ValueError("record")
        identity = (record["volumeSerial"], record["fileId"])
        if identity in identities:
            raise ValueError("identity")
        identities.add(identity)
        (file_paths if kind == "file" else directory_paths).add(path)
        copied.append(dict(record))
    if file_paths != files or directory_paths != directories \
            or len(copied) != len(files) + len(directories):
        raise ValueError("inventory")
    return sorted(copied, key=lambda item: (
        _collision_key(item["relativePath"].split("/")), item["relativePath"],
    ))


def _canonical_payload(manifest, records, root_identity):
    raw = (json.dumps(
        {
            "algorithm": ALGORITHM,
            "domain": DOMAIN,
            "manifest": manifest,
            "records": records,
            "sourceRootIdentity": root_identity,
            "version": 2,
        },
        # Domain and algorithm are part of the preimage, not metadata beside it.
        # Keep this exact shape synchronized with the fixed known-vector test.
        sort_keys=True, separators=(",", ":"), ensure_ascii=True,
        allow_nan=False,
    ) + "\n").encode("ascii")
    if len(raw) > MAX_CANONICAL_RECORD_BYTES:
        raise ValueError("size")
    return raw


def summarize(manifest, records, source_root_identity=None):
    try:
        root_identity = _validated_root_identity(source_root_identity)
        files, directories, manifest_entries = _validated_manifest(manifest)
        ordered = _validated_records(
            records, files, directories, root_identity,
        )
        raw = _canonical_payload(manifest_entries, ordered, root_identity)
        return {
            "algorithm": ALGORITHM,
            "descendantCount": len(ordered),
            "digest": hashlib.sha256(raw).hexdigest(),
        }
    except SourceTreeRefused:
        raise
    except Exception:
        _refuse()


def validate_summary(summary):
    try:
        if type(summary) is not dict or set(summary) != _SUMMARY_KEYS \
                or type(summary["algorithm"]) is not str \
                or summary["algorithm"] != ALGORITHM \
                or type(summary["descendantCount"]) is not int \
                or not 1 <= summary["descendantCount"] <= MAX_RECORDS \
                or not _digest(summary["digest"]):
            raise ValueError("summary")
        return copy.deepcopy(summary)
    except SourceTreeRefused:
        raise
    except Exception:
        _refuse()


def compare_boundaries(anchor, execution):
    try:
        left = validate_summary(anchor)
        right = validate_summary(execution)
        if left != right:
            raise ValueError("boundary")
        return True
    except SourceTreeRefused:
        raise
    except Exception:
        _refuse()
