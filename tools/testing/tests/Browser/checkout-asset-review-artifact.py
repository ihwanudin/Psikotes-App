"""Pure structural codec for the ADR-021 asset-review artifact.

This codec validates supplied canonical bytes only.  It does not read asset or
filesystem bytes, verify reviewers/signatures/2-of-2/key separation, consult a
trusted clock or revocation/replay state, mutate source, or grant admission.
A composition root must pin the imported callables and authenticate/bind the
exact bytes separately.
"""

from __future__ import annotations

import hashlib
import json
import re
from types import MappingProxyType


MAX_ARTIFACT_BYTES = 16 * 1024
ROLE = "asset-review"
ID_PATTERN = r"[a-z0-9](?:[a-z0-9._-]{0,63})"
REPLAY_ID_PATTERN = r"[a-z0-9](?:[a-z0-9._-]{0,127})"
ASSET_PATHS = (
    "public/css/checkout-summary-v1.css",
    "public/brand/oncam-logo-full-color.png",
    "public/js/checkout-confirmation-v1.js",
    "public/js/checkout-payment-v1.js",
    "resources/views/checkout/summary.blade.php",
    "tools/testing/tests/Browser/serve-checkout-session.php",
)


class AssetReviewArtifactRefused(Exception):
    """Fixed refusal without asset, reviewer, or parser details."""


__all__ = (
    "AssetReviewArtifactRefused",
    "canonical_artifact",
    "decode",
)


def _make_codec():
    refusal = AssetReviewArtifactRefused
    mapping_proxy_type = MappingProxyType
    maximum_bytes = MAX_ARTIFACT_BYTES
    role = ROLE
    identifier_pattern = ID_PATTERN
    replay_identifier_pattern = REPLAY_ID_PATTERN
    asset_paths = ASSET_PATHS
    public_surface = __all__
    top_keys = frozenset({
        "artifactId",
        "assets",
        "expiresAt",
        "generation",
        "issuedAt",
        "issuerId",
        "releaseSourceArtifactDigest",
        "replayId",
        "role",
        "version",
    })
    asset_keys = frozenset({"digest", "path"})

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
        ("ROLE", role),
        ("ID_PATTERN", identifier_pattern),
        ("REPLAY_ID_PATTERN", replay_identifier_pattern),
        ("ASSET_PATHS", asset_paths),
        ("AssetReviewArtifactRefused", refusal),
        ("MappingProxyType", mapping_proxy_type),
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
            raise refusal("asset_review_artifact") from None

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

    def lowercase_hex(value):
        return type(value) is str and len(value) == 64 \
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

    def validate(value):
        if not exact_keys(value, top_keys) \
                or type(value["version"]) is not int or value["version"] != 1 \
                or type(value["role"]) is not str or value["role"] != role \
                or not identifier(value["artifactId"], identifier_pattern) \
                or not identifier(value["issuerId"], identifier_pattern) \
                or not identifier(value["replayId"], replay_identifier_pattern) \
                or not positive_int63(value["generation"]) \
                or not lowercase_hex(value["releaseSourceArtifactDigest"]):
            raise ValueError("artifact")

        issued = timestamp(value["issuedAt"])
        expires = timestamp(value["expiresAt"])
        if issued is None or expires is None \
                or not 0 < expires - issued <= 7 * 24 * 60 * 60:
            raise ValueError("lifetime")

        assets = value["assets"]
        if type(assets) is not list or len(assets) != len(asset_paths):
            raise ValueError("assets")
        frozen_assets = []
        for item, expected_path in zip(assets, asset_paths):
            if not exact_keys(item, asset_keys) \
                    or type(item["path"]) is not str \
                    or item["path"] != expected_path \
                    or not lowercase_hex(item["digest"]):
                raise ValueError("asset")
            frozen_assets.append((expected_path, item["digest"]))
        return tuple(frozen_assets)

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
        value = json_loads(
            raw.decode("ascii"),
            object_pairs_hook=strict_object,
            parse_constant=lambda _value: (_ for _ in ()).throw(
                ValueError("constant")
            ),
        )
        frozen_assets = validate(value)
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
            "releaseSourceArtifactDigest": value["releaseSourceArtifactDigest"],
            "assetCount": len(frozen_assets),
            "assetEntries": frozen_assets,
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
            raise refusal("asset_review_artifact") from None

    def canonical_artifact(value):
        return invoke(canonical_impl, value)

    def decode(raw):
        return invoke(decode_impl, raw)

    return canonical_artifact, decode


canonical_artifact, decode = _make_codec()
del _make_codec
