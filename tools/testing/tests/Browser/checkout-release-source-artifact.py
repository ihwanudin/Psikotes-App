"""Pure structural codec for the ADR-021 release/source artifact.

This supplied-bytes boundary has no trusted clock and grants no signature,
trust, revocation, replay, filesystem, Git, or runtime authority.  A
composition root must pin the imported callables, validate the role-specific envelope
separately, and bind these exact canonical bytes to it.

``artifactDigest`` is the plain SHA-256 of the exact canonical artifact bytes required
by I1.  The Ed25519 signing-message domain is derived only by the envelope codec.
"""

from __future__ import annotations

import hashlib
import json
import re
from types import MappingProxyType


MAX_ARTIFACT_BYTES = 8 * 1024 * 1024
MAX_SOURCE_FILES = 8192
MAX_PATH_BYTES = 1024
MAX_PATH_DEPTH = 64
MAX_COMPONENT_BYTES = 255
ROLE = "release-source"
ID_PATTERN = r"[a-z0-9](?:[a-z0-9._-]{0,63})"
REPLAY_ID_PATTERN = r"[a-z0-9](?:[a-z0-9._-]{0,127})"


class ReleaseSourceArtifactRefused(Exception):
    """Fixed refusal without source, revision, path, or parser details."""


__all__ = (
    "ReleaseSourceArtifactRefused",
    "canonical_artifact",
    "decode",
)


def _make_codec():
    refusal = ReleaseSourceArtifactRefused
    mapping_proxy_type = MappingProxyType
    maximum_bytes = MAX_ARTIFACT_BYTES
    maximum_files = MAX_SOURCE_FILES
    maximum_path_bytes = MAX_PATH_BYTES
    maximum_depth = MAX_PATH_DEPTH
    maximum_component_bytes = MAX_COMPONENT_BYTES
    role = ROLE
    identifier_pattern = ID_PATTERN
    replay_identifier_pattern = REPLAY_ID_PATTERN
    public_surface = __all__
    top_keys = frozenset({
        "artifactId",
        "expiresAt",
        "generation",
        "issuedAt",
        "issuerId",
        "replayId",
        "reviewedCommit",
        "reviewedTree",
        "role",
        "sourceFiles",
        "sourceRevision",
        "version",
    })
    file_keys = frozenset({"digest", "kind", "path", "reparse"})

    json_module = json
    hashlib_module = hashlib
    re_module = re
    json_dumps = json.dumps
    json_loads = json.loads
    sha256 = hashlib.sha256
    re_fullmatch = re.fullmatch
    re_ignorecase = re.IGNORECASE
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

    def mapping_matches(value, snapshot):
        if snapshot is None:
            return value is None
        if type(value) is not dict or len(value) != len(snapshot):
            return False
        for key, expected in snapshot:
            if key not in value:
                return False
            try:
                if freeze_metadata(value[key]) != expected:
                    return False
            except Exception:
                return False
        return True

    def callable_state(function):
        defaults = getattr(function, "__defaults__", None)
        kwdefaults = getattr(function, "__kwdefaults__", None)
        return (
            function,
            type(function),
            getattr(function, "__code__", None),
            defaults,
            freeze_metadata(defaults),
            kwdefaults,
            mapping_snapshot(kwdefaults),
            getattr(function, "__closure__", None),
            getattr(function, "__globals__", None),
        )

    dependencies = (
        (json_module, "dumps", callable_state(json_dumps)),
        (json_module, "loads", callable_state(json_loads)),
        (hashlib_module, "sha256", callable_state(sha256)),
        (re_module, "fullmatch", callable_state(re_fullmatch)),
    )
    global_pins = (
        ("MAX_ARTIFACT_BYTES", maximum_bytes),
        ("MAX_SOURCE_FILES", maximum_files),
        ("MAX_PATH_BYTES", maximum_path_bytes),
        ("MAX_PATH_DEPTH", maximum_depth),
        ("MAX_COMPONENT_BYTES", maximum_component_bytes),
        ("ROLE", role),
        ("ID_PATTERN", identifier_pattern),
        ("REPLAY_ID_PATTERN", replay_identifier_pattern),
        ("ReleaseSourceArtifactRefused", refusal),
        ("__all__", public_surface),
        ("json", json_module),
        ("hashlib", hashlib_module),
        ("re", re_module),
    )

    def guard():
        try:
            for name, expected in global_pins:
                if module_globals.get(name) is not expected:
                    raise ValueError("authority")
            if re_module.IGNORECASE is not re_ignorecase:
                raise ValueError("dependency")
            for module, name, expected in dependencies:
                current = getattr(module, name, None)
                if current is not expected[0] or type(current) is not expected[1]:
                    raise ValueError("dependency")
                defaults = getattr(current, "__defaults__", None)
                kwdefaults = getattr(current, "__kwdefaults__", None)
                if getattr(current, "__code__", None) is not expected[2] \
                        or defaults is not expected[3] \
                        or freeze_metadata(defaults) != expected[4] \
                        or kwdefaults is not expected[5] \
                        or not mapping_matches(kwdefaults, expected[6]) \
                        or getattr(current, "__closure__", None) is not expected[7] \
                        or getattr(current, "__globals__", None) is not expected[8]:
                    raise ValueError("dependency")
        except refusal:
            raise
        except Exception:
            raise refusal("release_source_artifact") from None

    def exact_keys(value, expected):
        return type(value) is dict \
            and all(type(key) is str for key in value) \
            and set(value) == expected

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
            json_dumps(
                value,
                sort_keys=True,
                separators=(",", ":"),
                ensure_ascii=True,
                allow_nan=False,
            )
            + "\n"
        ).encode("ascii")

    def positive_int63(value):
        return type(value) is int and 1 <= value < (1 << 63)

    def identifier(value, pattern):
        return type(value) is str and re_fullmatch(pattern, value) is not None

    def lowercase_hex(value, length):
        return type(value) is str and len(value) == length \
            and re_fullmatch(r"[a-f0-9]+", value) is not None

    def timestamp(value):
        if type(value) is not str:
            return None
        match = re_fullmatch(
            r"([0-9]{4})-([0-9]{2})-([0-9]{2})T"
            r"([0-9]{2}):([0-9]{2}):([0-9]{2})Z",
            value,
        )
        if match is None:
            return None
        year, month, day, hour, minute, second = (
            int(part) for part in match.groups()
        )
        if not 1 <= year <= 9999 or not 1 <= month <= 12 \
                or hour > 23 or minute > 59 or second > 59:
            return None
        leap = year % 4 == 0 and (year % 100 != 0 or year % 400 == 0)
        month_days = (31, 29 if leap else 28, 31, 30, 31, 30,
                      31, 31, 30, 31, 30, 31)
        if not 1 <= day <= month_days[month - 1]:
            return None
        prior_year = year - 1
        days = 365 * prior_year + prior_year // 4 \
            - prior_year // 100 + prior_year // 400
        days += sum(month_days[:month - 1]) + day - 1
        return ((days * 24 + hour) * 60 + minute) * 60 + second

    def safe_path(value):
        if type(value) is not str or not value \
                or len(value.encode("utf-8")) > maximum_path_bytes \
                or value.startswith("/") or value.endswith("/") \
                or "\\" in value or ":" in value \
                or any(ord(character) < 32 or ord(character) > 126
                       for character in value):
            return False
        parts = value.split("/")
        if len(parts) > maximum_depth or parts[0].lower() == "vendor":
            return False
        for part in parts:
            if not part or part in {".", ".."} \
                    or len(part.encode("ascii")) > maximum_component_bytes \
                    or part.endswith((".", " ")) \
                    or any(character in '<>"|?*' for character in part):
                return False
            base = part.split(".", 1)[0].rstrip(" .")
            if re_fullmatch(
                    r"(?:con|prn|aux|nul|conin\$|conout\$|com[1-9]|lpt[1-9])",
                    base,
                    flags=re_ignorecase,
            ) is not None:
                return False
        return True

    def validate(value):
        if not exact_keys(value, top_keys) \
                or type(value["version"]) is not int or value["version"] != 1 \
                or type(value["role"]) is not str or value["role"] != role \
                or not identifier(value["artifactId"], identifier_pattern) \
                or not identifier(value["issuerId"], identifier_pattern) \
                or not identifier(value["replayId"], replay_identifier_pattern) \
                or not positive_int63(value["generation"]) \
                or not lowercase_hex(value["reviewedCommit"], 40) \
                or not lowercase_hex(value["reviewedTree"], 40) \
                or not lowercase_hex(value["sourceRevision"], 40) \
                or value["sourceRevision"] != value["reviewedCommit"]:
            raise ValueError("artifact")

        issued = timestamp(value["issuedAt"])
        expires = timestamp(value["expiresAt"])
        if issued is None or expires is None \
                or not 0 < expires - issued <= 7 * 24 * 60 * 60:
            raise ValueError("lifetime")

        files = value["sourceFiles"]
        if type(files) is not list or not 1 <= len(files) <= maximum_files:
            raise ValueError("inventory")
        paths = []
        frozen_files = []
        collision_keys = set()
        for item in files:
            if not exact_keys(item, file_keys) \
                    or type(item["kind"]) is not str or item["kind"] != "file" \
                    or item["reparse"] is not False \
                    or not safe_path(item["path"]) \
                    or not lowercase_hex(item["digest"], 64):
                raise ValueError("source_file")
            collision_key = item["path"].lower()
            if collision_key in collision_keys:
                raise ValueError("collision")
            collision_keys.add(collision_key)
            paths.append(item["path"])
            frozen_files.append((item["path"], item["digest"]))
        if paths != sorted(paths, key=lambda path: path.lower()) \
                or "composer.lock" not in paths:
            raise ValueError("inventory")
        return tuple(frozen_files)

    def canonical_impl(value):
        validate(value)
        raw = json_bytes(value)
        if len(raw) > maximum_bytes:
            raise ValueError("size")
        return raw

    def decode_impl(raw):
        if type(raw) is not bytes or not 1 <= len(raw) <= maximum_bytes \
                or not raw.endswith(b"\n") or raw.endswith(b"\n\n"):
            raise ValueError("raw")
        text = raw.decode("ascii")
        value = json_loads(
            text,
            object_pairs_hook=strict_object,
            parse_constant=lambda _value: (_ for _ in ()).throw(
                ValueError("constant")
            ),
        )
        frozen_files = validate(value)
        if json_bytes(value) != raw:
            raise ValueError("canonical")
        return mapping_proxy_type({
            "structuralOnly": True,
            "role": role,
            "artifactId": value["artifactId"],
            "issuerId": value["issuerId"],
            "generation": value["generation"],
            "issuedAt": value["issuedAt"],
            "expiresAt": value["expiresAt"],
            "replayId": value["replayId"],
            "reviewedCommit": value["reviewedCommit"],
            "reviewedTree": value["reviewedTree"],
            "sourceRevision": value["sourceRevision"],
            "sourceFileCount": len(frozen_files),
            "sourceFiles": frozen_files,
            "artifactDigest": sha256(raw).hexdigest(),
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
            raise refusal("release_source_artifact") from None

    def canonical_artifact(value):
        return invoke(canonical_impl, value)

    def decode(raw):
        return invoke(decode_impl, raw)

    return canonical_artifact, decode


canonical_artifact, decode = _make_codec()
del _make_codec
