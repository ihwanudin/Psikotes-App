"""Strict pure parser for the reviewed checkout Windows ACL policy."""

from __future__ import annotations

import copy
import hashlib
import json


ACL_POLICY_FILE = "checkout-windows-acl-policy-v1.json"
ACL_POLICY_DIGEST = "a63c221764f73a54e87513fc91cded6b3fa16825138f6b24b6118132829f4eeb"
MAX_POLICY_BYTES = 8192

_EXPECTED_POLICY = {
    "ordinarySecondPrincipal": {
        "accessCheck": {
            "api": "AccessCheck",
            "desiredAccessMask": 33554432,
            "expectedAccessStatus": False,
            "expectedFunctionSuccess": True,
            "expectedGrantedAccessMask": 0,
        },
        "excludedEnabledPrivileges": [
            "SeBackupPrivilege",
            "SeRestorePrivilege",
            "SeTakeOwnershipPrivilege",
        ],
        "excludedTokenUserSids": ["S-1-5-18"],
        "forbiddenSid": "PROCESS_TOKEN_USER",
        "membershipScopes": ["TOKEN_USER", "TOKEN_GROUPS"],
        "selector": "DISTINCT_NON_PRIVILEGED_TEST_TOKEN",
    },
    "policyId": "checkout-windows-owner-only-v1",
    "targets": [
        {
            "dacl": {
                "aceCount": 1,
                "aces": [{
                    "accessMask": 2032127,
                    "flags": {
                        "inheritance": ["OBJECT_INHERIT_ACE", "CONTAINER_INHERIT_ACE"],
                        "inherited": False,
                        "numeric": 3,
                        "propagation": [],
                    },
                    "order": 0,
                    "trustee": "PROCESS_TOKEN_USER",
                    "type": {"name": "ACCESS_ALLOWED_ACE_TYPE", "numeric": 0},
                }],
                "autoInherited": False,
                "present": True,
                "protected": True,
                "revision": 2,
            },
            "owner": "PROCESS_TOKEN_USER",
            "role": "coordinator",
        },
        {
            "dacl": {
                "aceCount": 1,
                "aces": [{
                    "accessMask": 2032127,
                    "flags": {
                        "inheritance": ["OBJECT_INHERIT_ACE", "CONTAINER_INHERIT_ACE"],
                        "inherited": False,
                        "numeric": 3,
                        "propagation": [],
                    },
                    "order": 0,
                    "trustee": "PROCESS_TOKEN_USER",
                    "type": {"name": "ACCESS_ALLOWED_ACE_TYPE", "numeric": 0},
                }],
                "autoInherited": False,
                "present": True,
                "protected": True,
                "revision": 2,
            },
            "owner": "PROCESS_TOKEN_USER",
            "role": "run",
        },
        {
            "dacl": {
                "aceCount": 1,
                "aces": [{
                    "accessMask": 1179817,
                    "flags": {
                        "inheritance": ["OBJECT_INHERIT_ACE", "CONTAINER_INHERIT_ACE"],
                        "inherited": False,
                        "numeric": 3,
                        "propagation": [],
                    },
                    "order": 0,
                    "trustee": "PROCESS_TOKEN_USER",
                    "type": {"name": "ACCESS_ALLOWED_ACE_TYPE", "numeric": 0},
                }],
                "autoInherited": False,
                "present": True,
                "protected": True,
                "revision": 2,
            },
            "owner": "PROCESS_TOKEN_USER",
            "role": "source",
        },
    ],
    "version": 1,
}


class PolicyRefused(Exception):
    """Fixed policy refusal only; never includes policy contents."""


def _strict_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("duplicate")
        result[key] = value
    return result


def _reject_nonfinite(_value):
    raise ValueError("nonfinite")


def _same_exact(value, expected):
    if type(value) is not type(expected):
        return False
    if type(expected) is dict:
        return list(value) == list(expected) and all(
            _same_exact(value[key], expected[key]) for key in expected
        )
    if type(expected) is list:
        return len(value) == len(expected) and all(
            _same_exact(item, wanted) for item, wanted in zip(value, expected, strict=True)
        )
    return value == expected


def validated_policy(raw):
    try:
        if type(raw) is not bytes or not 0 < len(raw) <= MAX_POLICY_BYTES \
                or not raw.endswith(b"\n") or raw.endswith(b"\n\n"):
            raise ValueError("bounds")
        text = raw.decode("ascii", errors="strict")
        document = json.loads(
            text,
            object_pairs_hook=_strict_object,
            parse_constant=_reject_nonfinite,
        )
        canonical = (json.dumps(
            document, sort_keys=True, separators=(",", ":"), ensure_ascii=True,
        ) + "\n").encode("ascii")
        if raw != canonical or not _same_exact(document, _EXPECTED_POLICY):
            raise ValueError("policy")
        return copy.deepcopy(document)
    except Exception:
        raise PolicyRefused("acl_policy") from None


def policy_digest(raw):
    validated_policy(raw)
    digest = hashlib.sha256(raw).hexdigest()
    if digest != ACL_POLICY_DIGEST:
        raise PolicyRefused("acl_policy")
    return digest
