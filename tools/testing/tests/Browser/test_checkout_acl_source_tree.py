"""Pure tests for the bounded checkout ACL source-tree summary contract."""

import hashlib
import importlib.util
import json
from pathlib import Path
import unittest


spec = importlib.util.spec_from_file_location(
    "checkout_acl_source_tree_tested",
    Path(__file__).with_name("checkout-acl-source-tree.py"),
)
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class CheckoutAclSourceTreeTests(unittest.TestCase):
    DIGEST_A = "a" * 64
    DIGEST_B = "b" * 64
    SID = "S-1-5-21-1"

    def root_identity(self, volume="9", file_id="10"):
        return {"volumeSerial": volume, "fileId": file_id}

    def summarize(self, manifest, records, root_identity=None):
        return m.summarize(
            manifest,
            records,
            self.root_identity() if root_identity is None else root_identity,
        )

    def record(self, path, kind, identity, dacl=None):
        return {
            "relativePath": path,
            "kind": kind,
            "volumeSerial": str(identity[0]),
            "fileId": str(identity[1]),
            "ownerSid": self.SID,
            "daclDigest": dacl or self.DIGEST_B,
        }

    def valid(self):
        manifest = {
            "app/Actions/Run.php": self.DIGEST_A,
            "public/index.php": self.DIGEST_B,
        }
        records = [
            self.record("public/index.php", "file", (1, 5)),
            self.record("app", "directory", (1, 1)),
            self.record("app/Actions/Run.php", "file", (1, 3)),
            self.record("public", "directory", (1, 4)),
            self.record("app/Actions", "directory", (1, 2)),
        ]
        return manifest, records

    def test_summary_is_exact_canonical_ordered_records_with_one_lf(self):
        manifest, records = self.valid()
        summary = self.summarize(manifest, records)
        ordered = sorted(records, key=lambda item: (
            tuple(part.casefold() for part in item["relativePath"].split("/")),
            item["relativePath"],
        ))
        ordered_manifest = [
            {"relativePath": path, "contentDigest": manifest[path]}
            for path in sorted(manifest, key=lambda path: (
                tuple(part.casefold() for part in path.split("/")), path,
            ))
        ]
        raw = (json.dumps(
            {
                "algorithm": "sha256-canonical-json-v2",
                "domain": "oncam.checkout.acl-source-tree.v2",
                "manifest": ordered_manifest,
                "records": ordered,
                "sourceRootIdentity": self.root_identity(),
                "version": 2,
            },
            sort_keys=True, separators=(",", ":"),
            ensure_ascii=True, allow_nan=False,
        ) + "\n").encode("ascii")
        self.assertEqual(summary, {
            "algorithm": "sha256-canonical-json-v2",
            "descendantCount": 5,
            "digest": "cd85b1310c011aebcb552d123c34605a257d48a94da27e0176079e36daaf3c59",
        })
        self.assertEqual(summary["digest"], hashlib.sha256(raw).hexdigest())
        self.assertEqual(self.summarize(
            dict(reversed(list(manifest.items()))), list(reversed(records)),
        ), summary)
        changed_content_digests = {path: self.DIGEST_B for path in manifest}
        self.assertNotEqual(self.summarize(changed_content_digests, records), summary)
        changed_root = self.root_identity(file_id="11")
        changed_root_summary = self.summarize(manifest, records, changed_root)
        self.assertNotEqual(changed_root_summary, summary)
        with self.assertRaisesRegex(m.SourceTreeRefused, "^acl_source_tree$"):
            m.compare_boundaries(summary, changed_root_summary)
        self.assertEqual(m.validate_summary(summary), summary)
        self.assertIsNot(m.validate_summary(summary), summary)
        self.assertTrue(m.compare_boundaries(summary, dict(summary)))

    def test_manifest_and_records_must_be_nonempty_exact_and_complete(self):
        manifest, records = self.valid()
        cases = (
            ({}, records),
            (manifest, []),
            ({"app/Actions/Run.php": self.DIGEST_A}, records),
            ({**manifest, "missing.php": self.DIGEST_A}, records),
            ({**manifest, "APP/actions/run.php": self.DIGEST_A}, records),
            ({"a": self.DIGEST_A, "a/b.php": self.DIGEST_B}, records),
            ({"app": self.DIGEST_A}, [self.record("app", "directory", (1, 1))]),
            ({"a.php": "A" * 64}, [self.record("a.php", "file", (1, 1))]),
        )
        for candidate_manifest, candidate_records in cases:
            with self.subTest(manifest=candidate_manifest, records=candidate_records):
                with self.assertRaisesRegex(m.SourceTreeRefused, "^acl_source_tree$"):
                    self.summarize(candidate_manifest, candidate_records)

    def test_directory_set_must_equal_all_and_only_implied_parents(self):
        manifest, records = self.valid()
        cases = (
            [record for record in records if record["relativePath"] != "app/Actions"],
            records + [self.record("extra", "directory", (1, 9))],
            [
                {**record, "kind": "file"}
                if record["relativePath"] == "app/Actions" else record
                for record in records
            ],
        )
        for candidate in cases:
            with self.subTest(records=candidate):
                with self.assertRaisesRegex(m.SourceTreeRefused, "^acl_source_tree$"):
                    self.summarize(manifest, candidate)

    def test_paths_are_relative_printable_ascii_posix_bounded_and_windows_safe(self):
        bad = (
            "/a.php", "a\\b.php", "c:a.php", ".", "..", "a/./b.php",
            "a/../b.php", "a//b.php", "a.php/", "a./b.php", "a /b.php",
            "NUL.txt", "CON .txt", "dir/COM1.log", "bad\x00name.php",
            "bad\u0085name.php",
            "bad?.php", "e\u0301.php",
            "/".join(["a"] * 65) + ".php", "a" * 1021 + ".php",
        )
        for path in bad:
            with self.subTest(path=path), \
                    self.assertRaisesRegex(m.SourceTreeRefused, "^acl_source_tree$"):
                self.summarize(
                    {path: self.DIGEST_A}, [self.record(path, "file", (1, 1))],
                )

        for path in ("caf\u00e9.php", "a" * 256 + ".php"):
            with self.subTest(path=path), \
                    self.assertRaisesRegex(m.SourceTreeRefused, "^acl_source_tree$"):
                self.summarize(
                    {path: self.DIGEST_A}, [self.record(path, "file", (1, 1))],
                )

        longest_component = "a" * 251 + ".php"
        self.assertEqual(self.summarize(
            {longest_component: self.DIGEST_A},
            [self.record(longest_component, "file", (1, 1))],
        )["descendantCount"], 1)

    def test_casefold_collisions_and_duplicate_paths_or_identities_refuse(self):
        manifest = {"A.php": self.DIGEST_A, "a.PHP": self.DIGEST_B}
        records = [self.record("A.php", "file", (1, 1)),
                   self.record("a.PHP", "file", (1, 2))]
        for candidate_manifest, candidate_records in (
            (manifest, records),
            ({"A/x.php": self.DIGEST_A, "a/y.php": self.DIGEST_B}, [
                self.record("A", "directory", (1, 1)),
                self.record("A/x.php", "file", (1, 2)),
                self.record("a", "directory", (1, 3)),
                self.record("a/y.php", "file", (1, 4)),
            ]),
            ({"a.php": self.DIGEST_A}, [
                self.record("a.php", "file", (1, 1)),
                self.record("a.php", "file", (1, 2)),
            ]),
            ({"a.php": self.DIGEST_A, "b.php": self.DIGEST_B}, [
                self.record("a.php", "file", (1, 1)),
                self.record("b.php", "file", (1, 1)),
            ]),
        ):
            with self.subTest(records=candidate_records), \
                    self.assertRaisesRegex(m.SourceTreeRefused, "^acl_source_tree$"):
                self.summarize(candidate_manifest, candidate_records)

    def test_record_schema_identity_sid_and_digest_are_strict(self):
        manifest = {"a.php": self.DIGEST_A}
        baseline = self.record("a.php", "file", (1, 2))
        mutations = (
            {**baseline, "extra": None},
            {key: value for key, value in baseline.items() if key != "ownerSid"},
            {**baseline, "kind": "link"},
            {**baseline, "volumeSerial": "01"},
            {**baseline, "volumeSerial": True},
            {**baseline, "fileId": str(1 << 128)},
            {**baseline, "ownerSid": "S-0-5-21-1"},
            {**baseline, "ownerSid": "S-2-5-21-1"},
            {**baseline, "ownerSid": "S-255-5-21-1"},
            {**baseline, "ownerSid": "s-1-5-21-1"},
            {**baseline, "ownerSid": "S-1-281474976710656-1"},
            {**baseline, "daclDigest": "B" * 64},
        )
        for record in mutations:
            with self.subTest(record=record), \
                    self.assertRaisesRegex(m.SourceTreeRefused, "^acl_source_tree$"):
                self.summarize(manifest, [record])
        self.assertEqual(self.summarize(manifest, [baseline])["descendantCount"], 1)

    def test_summary_and_boundary_comparison_are_exact_and_bool_safe(self):
        summary = {"algorithm": "sha256-canonical-json-v2",
                   "descendantCount": 1, "digest": self.DIGEST_A}
        mutations = (
            {**summary, "extra": None},
            {**summary, "algorithm": "sha256"},
            {**summary, "descendantCount": True},
            {**summary, "descendantCount": 0},
            {**summary, "descendantCount": 8193},
            {**summary, "digest": "A" * 64},
        )
        for candidate in mutations:
            with self.subTest(candidate=candidate), \
                    self.assertRaisesRegex(m.SourceTreeRefused, "^acl_source_tree$"):
                m.validate_summary(candidate)
            with self.assertRaisesRegex(m.SourceTreeRefused, "^acl_source_tree$"):
                m.compare_boundaries(summary, candidate)
        with self.assertRaisesRegex(m.SourceTreeRefused, "^acl_source_tree$"):
            m.compare_boundaries(summary, {**summary, "digest": self.DIGEST_B})

    def test_record_count_and_aggregate_canonical_bytes_are_bounded(self):
        manifest = {f"f{index:04}.php": self.DIGEST_A for index in range(8193)}
        records = [self.record(path, "file", (1, index + 1)) for index, path in enumerate(manifest)]
        with self.assertRaisesRegex(m.SourceTreeRefused, "^acl_source_tree$"):
            self.summarize(manifest, records)

        original_records = m.MAX_RECORDS
        try:
            m.MAX_RECORDS = 4
            shallow_records = [self.record("a/b/c.php", "file", (1, 1))]
            with self.assertRaisesRegex(m.SourceTreeRefused, "^acl_source_tree$"):
                self.summarize({
                    "a/b/c.php": self.DIGEST_A,
                    "d/e/f.php": self.DIGEST_B,
                }, shallow_records)
        finally:
            m.MAX_RECORDS = original_records

        original = m.MAX_CANONICAL_RECORD_BYTES
        try:
            m.MAX_CANONICAL_RECORD_BYTES = 64
            manifest = {"a.php": self.DIGEST_A}
            with self.assertRaisesRegex(m.SourceTreeRefused, "^acl_source_tree$"):
                self.summarize(
                    manifest, [self.record("a.php", "file", (1, 1))],
                )
        finally:
            m.MAX_CANONICAL_RECORD_BYTES = original

    def test_source_root_identity_is_exact_bounded_and_mandatory(self):
        manifest, records = self.valid()
        invalid = (
            None,
            {},
            [],
            {"volumeSerial": "9"},
            {"volumeSerial": "9", "fileId": "10", "extra": "PRIVATE"},
            {"volumeSerial": True, "fileId": "10"},
            {"volumeSerial": "9", "fileId": True},
            {"volumeSerial": 9, "fileId": "10"},
            {"volumeSerial": "9", "fileId": 10},
            {"volumeSerial": "09", "fileId": "10"},
            {"volumeSerial": "9", "fileId": str(1 << 128)},
        )
        for root_identity in invalid:
            with self.subTest(root_identity=root_identity), \
                    self.assertRaisesRegex(m.SourceTreeRefused, "^acl_source_tree$"):
                m.summarize(manifest, records, root_identity)
        with self.assertRaisesRegex(m.SourceTreeRefused, "^acl_source_tree$"):
            m.summarize(manifest, records)
        self.assertEqual(self.summarize(
            manifest, records,
            {"volumeSerial": str((1 << 128) - 1),
             "fileId": str((1 << 128) - 1)},
        )["descendantCount"], len(records))

    def test_descendant_must_not_reuse_source_root_identity(self):
        manifest, records = self.valid()
        root_identity = self.root_identity()
        aliased = [dict(record) for record in records]
        aliased[0]["volumeSerial"] = root_identity["volumeSerial"]
        aliased[0]["fileId"] = root_identity["fileId"]
        with self.assertRaisesRegex(m.SourceTreeRefused, "^acl_source_tree$"):
            self.summarize(manifest, aliased, root_identity)

    def test_source_root_refusal_is_redacted_and_baseexceptions_are_preserved(self):
        manifest, records = self.valid()
        with self.assertRaisesRegex(m.SourceTreeRefused, "^acl_source_tree$") as caught:
            self.summarize(
                manifest, records,
                {"volumeSerial": "PRIVATE", "fileId": "10"},
            )
        self.assertNotIn("PRIVATE", str(caught.exception))

        original = m._validated_root_identity
        for primary in (KeyboardInterrupt(), SystemExit(73)):
            def interrupt(_value, primary=primary):
                raise primary

            m._validated_root_identity = interrupt
            try:
                with self.assertRaises(type(primary)) as raised:
                    self.summarize(manifest, records)
                self.assertIs(raised.exception, primary)
            finally:
                m._validated_root_identity = original


if __name__ == "__main__":
    unittest.main()
