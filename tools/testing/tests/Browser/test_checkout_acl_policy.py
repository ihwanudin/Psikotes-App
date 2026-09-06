"""Pure tests for the reviewed checkout Windows ACL policy artifact."""

from __future__ import annotations

import importlib.util
import json
from pathlib import Path
import unittest


HERE = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location(
    "checkout_acl_policy_tested", HERE / "checkout-acl-policy.py"
)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("ACL policy module unavailable")
module = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(module)


class CheckoutAclPolicyTests(unittest.TestCase):
    def raw(self):
        return (HERE / module.ACL_POLICY_FILE).read_bytes()

    def test_reviewed_artifact_is_exact_canonical_and_digest_pinned(self):
        raw = self.raw()
        document = module.validated_policy(raw)
        self.assertEqual(module.policy_digest(raw), module.ACL_POLICY_DIGEST)
        self.assertEqual(list(document), [
            "ordinarySecondPrincipal", "policyId", "targets", "version",
        ])
        self.assertEqual([target["role"] for target in document["targets"]], [
            "coordinator", "run", "source",
        ])
        self.assertEqual([
            target["dacl"]["aces"][0]["accessMask"] for target in document["targets"]
        ], [2032127, 2032127, 1179817])
        self.assertEqual(
            document["ordinarySecondPrincipal"]["accessCheck"]["desiredAccessMask"],
            33554432,
        )
        self.assertEqual(
            document["ordinarySecondPrincipal"]["accessCheck"],
            {
                "api": "AccessCheck",
                "desiredAccessMask": 33554432,
                "expectedAccessStatus": False,
                "expectedFunctionSuccess": True,
                "expectedGrantedAccessMask": 0,
            },
        )
        self.assertEqual(
            document["ordinarySecondPrincipal"]["membershipScopes"],
            ["TOKEN_USER", "TOKEN_GROUPS"],
        )
        document["targets"][0]["dacl"]["aces"][0]["flags"]["propagation"].append(
            "PRIVATE"
        )
        self.assertEqual(
            module.validated_policy(raw)["targets"][0]["dacl"]["aces"][0]["flags"]["propagation"],
            [],
        )

    def test_noncanonical_encoding_duplicate_nonfinite_and_bounds_fail_closed(self):
        raw = self.raw()
        cases = (
            None,
            raw[:-1],
            raw + b"\n",
            b"\xef\xbb\xbf" + raw,
            raw.replace(b'"ordinarySecondPrincipal":', b' "ordinarySecondPrincipal":', 1),
            raw.replace(
                b'{"ordinarySecondPrincipal":',
                b'{"version":1,"ordinarySecondPrincipal":',
                1,
            ),
            raw.replace(b'"version":1}', b'"version":NaN}', 1),
            b"{" + b'"x":0,' * 5000 + b'"version":1}\n',
        )
        for value in cases:
            with self.subTest(value_type=type(value).__name__), \
                    self.assertRaisesRegex(module.PolicyRefused, "^acl_policy$"):
                module.validated_policy(value)

    def test_schema_values_types_order_and_uniqueness_are_exact(self):
        document = json.loads(self.raw())
        mutations = (
            lambda value: value.__setitem__("extra", False),
            lambda value: value.__setitem__("version", True),
            lambda value: value.__setitem__("policyId", "other"),
            lambda value: value["ordinarySecondPrincipal"].__setitem__("extra", False),
            lambda value: value["ordinarySecondPrincipal"]["accessCheck"].__setitem__(
                "api", "AuthzAccessCheck"
            ),
            lambda value: value["ordinarySecondPrincipal"]["accessCheck"].__setitem__(
                "desiredAccessMask", True
            ),
            lambda value: value["ordinarySecondPrincipal"]["accessCheck"].__setitem__(
                "expectedAccessStatus", True
            ),
            lambda value: value["ordinarySecondPrincipal"]["accessCheck"].__setitem__(
                "expectedFunctionSuccess", False
            ),
            lambda value: value["ordinarySecondPrincipal"]["accessCheck"].__setitem__(
                "expectedGrantedAccessMask", False
            ),
            lambda value: value["ordinarySecondPrincipal"][
                "excludedEnabledPrivileges"
            ].reverse(),
            lambda value: value["ordinarySecondPrincipal"][
                "excludedTokenUserSids"
            ].append("S-1-5-32-544"),
            lambda value: value["ordinarySecondPrincipal"].__setitem__(
                "forbiddenSid", "CALLER"
            ),
            lambda value: value["ordinarySecondPrincipal"]["membershipScopes"].reverse(),
            lambda value: value["ordinarySecondPrincipal"]["membershipScopes"].append(
                "TOKEN_RESTRICTED_SIDS"
            ),
            lambda value: value["ordinarySecondPrincipal"].__setitem__(
                "selector", "CALLER"
            ),
            lambda value: value["targets"].reverse(),
            lambda value: value["targets"].append(dict(value["targets"][0])),
            lambda value: value["targets"][0].__setitem__("owner", "CALLER"),
            lambda value: value["targets"][0]["dacl"].__setitem__("aceCount", True),
            lambda value: value["targets"][0]["dacl"].__setitem__("present", 1),
            lambda value: value["targets"][0]["dacl"].__setitem__("protected", False),
            lambda value: value["targets"][0]["dacl"].__setitem__("autoInherited", True),
            lambda value: value["targets"][0]["dacl"].__setitem__("revision", 4),
            lambda value: value["targets"][0]["dacl"]["aces"].append(
                dict(value["targets"][0]["dacl"]["aces"][0])
            ),
            lambda value: value["targets"][0]["dacl"]["aces"][0].__setitem__(
                "accessMask", 1179817
            ),
            lambda value: value["targets"][0]["dacl"]["aces"][0].__setitem__(
                "order", True
            ),
            lambda value: value["targets"][0]["dacl"]["aces"][0].__setitem__(
                "trustee", "S-1-5-18"
            ),
            lambda value: value["targets"][0]["dacl"]["aces"][0]["type"].__setitem__(
                "numeric", 1
            ),
            lambda value: value["targets"][0]["dacl"]["aces"][0]["flags"].__setitem__(
                "numeric", 1
            ),
            lambda value: value["targets"][0]["dacl"]["aces"][0]["flags"][
                "inheritance"
            ].reverse(),
            lambda value: value["targets"][0]["dacl"]["aces"][0]["flags"].__setitem__(
                "inherited", True
            ),
            lambda value: value["targets"][0]["dacl"]["aces"][0]["flags"][
                "propagation"
            ].append("NO_PROPAGATE_INHERIT_ACE"),
            lambda value: value["targets"][2]["dacl"]["aces"][0].__setitem__(
                "accessMask", 2032127
            ),
        )
        for mutate in mutations:
            candidate = json.loads(json.dumps(document))
            mutate(candidate)
            raw = (json.dumps(candidate, sort_keys=True, separators=(",", ":")) + "\n").encode()
            with self.subTest(candidate=candidate), \
                    self.assertRaisesRegex(module.PolicyRefused, "^acl_policy$"):
                module.validated_policy(raw)


if __name__ == "__main__":
    unittest.main()
