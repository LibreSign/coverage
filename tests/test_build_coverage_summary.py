# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

import tempfile
import unittest
from pathlib import Path

from scripts.build_coverage_summary import make_summary

SHA = "a" * 40


class CoverageSummaryTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        (self.root / "Service").mkdir()
        (self.root / "Service" / "Signer.php.html").write_text("HTML")
        (self.root / "Service" / "index.html").write_text("HTML")
        self.metadata = {
            "repository": "LibreSign/libresign", "source_commit_sha": SHA,
            "source_branch": "ci/coverage-pr", "source_run_id": 456, "preview": True,
        }
        self.report = {
            "repository": "LibreSign/libresign", "ref": "refs/heads/ci/coverage-pr",
            "commit": SHA, "timestamp": "2026-11-08T12:00:00.023454Z",
            "coverage": {"type": "loc", "total": 30, "covered": 18, "files": [
                {"normalized_path": "lib/Service/Signer.php", "total": 20, "covered": 10},
                {"normalized_path": "lib/Controller/Action.php", "total": 10, "covered": 8},
                {"normalized_path": "lib/Service/Empty.php", "total": 0, "covered": 0},
            ]},
        }

    def test_summary_is_correct_and_uses_existing_phpunit_pages(self):
        result = make_summary(self.report, self.metadata, self.root)
        self.assertEqual((result["percent"], result["covered"], result["uncovered"]), (60, 18, 12))
        self.assertEqual(result["files_count"], 3)
        self.assertTrue(result["preview"])
        self.assertEqual(result["measured_at"], "2026-11-08T12:00:00Z")
        self.assertEqual(result["directories"][0]["name"], "lib/Service")
        self.assertEqual(result["directories"][0]["uncovered"], 10)
        self.assertEqual(result["directories"][0]["report_url"], "libresign/Service/index.html")
        self.assertEqual(result["files"][0]["report_url"], "libresign/Service/Signer.php.html")
        self.assertTrue(result["files"][1]["report_url"] is None)
        self.assertIn("/blob/" + SHA + "/lib/Controller/Action.php", result["files"][1]["source_url"])
        self.assertIsNone(result["files"][-1]["percent"])

    def test_production_must_be_main_with_matching_identity(self):
        main_meta = {**self.metadata, "source_branch": "main", "preview": False}
        report = {**self.report, "ref": "refs/heads/main"}
        self.assertFalse(make_summary(report, main_meta, self.root)["preview"])
        for modified in (
            {**main_meta, "source_branch": "feature"},
            {**main_meta, "source_commit_sha": "b" * 40},
            {**main_meta, "repository": "other/repo"},
            {**main_meta, "source_run_id": 0},
        ):
            with self.subTest(modified=modified):
                with self.assertRaises(ValueError):
                    make_summary(report, modified, self.root)

    def test_rejects_malformed_and_traversal_paths(self):
        for path in ("lib/../secret.php", "/absolute.php", "lib/Service/../../escape.php",
                     "vendor/package.php", "lib/Service/bad.txt", "lib\\Service\\foo.php"):
            clone = {**self.report, "coverage": {**self.report["coverage"],
                "files": [{**self.report["coverage"]["files"][0], "normalized_path": path},
                          *self.report["coverage"]["files"][1:]]}}
            with self.subTest(path=path), self.assertRaises(ValueError):
                make_summary(clone, self.metadata, self.root)

    def test_rejects_inconsistent_source_metrics(self):
        coverage = self.report["coverage"]
        for changed in (
            {**coverage, "total": 31},
            {**coverage, "covered": 19},
            {**coverage, "type": "branch"},
            {**coverage, "files": [coverage["files"][0]]},
        ):
            with self.subTest(changed=changed), self.assertRaises(ValueError):
                make_summary({**self.report, "coverage": changed}, self.metadata, self.root)

    def test_does_not_leak_ci_runner_absolute_paths(self):
        result = make_summary(self.report, self.metadata, self.root)
        self.assertFalse(any("file" in x for x in result["files"]))
        self.assertFalse(any("/home/runner" in x["path"] for x in result["files"]))


if __name__ == "__main__":
    unittest.main()
