# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

import unittest

from scripts.coverage_source import choose_reference, complete_source, metadata_for, validate_report


class SourceArtifactSelectionTests(unittest.TestCase):
    def test_prioritize_master_highest_php_version(self):
        artifacts = [
            {"name": "octocov-report", "expired": False},
            {"name": "phpunit-coverage-php-8.10-nextcloud-master", "expired": False},
            {"name": "phpunit-coverage-php-8.9-nextcloud-master", "expired": False},
            {"name": "phpunit-coverage-php-8.12-nextcloud-stable35", "expired": False},
        ]
        self.assertEqual(complete_source(artifacts), "phpunit-coverage-php-8.10-nextcloud-master")

    def test_skip_expired_or_missing_native_artifact(self):
        html = {"name": "phpunit-coverage-php-8.5-nextcloud-master", "expired": False}
        self.assertIsNone(complete_source([html]))
        self.assertIsNone(complete_source([html, {"name": "octocov-report", "expired": True}]))
        self.assertEqual(choose_reference([html]), html["name"])

    def test_fallback_to_latest_php_from_stable_when_master_missing(self):
        names = [
            {"name": "phpunit-coverage-php-8.4-nextcloud-stable34", "expired": False},
            {"name": "phpunit-coverage-php-8.5-nextcloud-stable35", "expired": False},
            {"name": "octocov-report", "expired": False},
        ]
        self.assertEqual(complete_source(names), "phpunit-coverage-php-8.5-nextcloud-stable35")

    def test_match_source_commit_and_repository(self):
        sha = "a" * 40
        valid = {"repository": "LibreSign/libresign", "ref": "refs/heads/main",
                 "commit": sha, "coverage": {"covered": 1, "total": 2}}
        validate_report(valid, sha)
        for updates in (
            {"commit": "b" * 40},
            {"repository": "SomeOther/repository"},
            {"ref": "refs/pull/42/merge"},
            {"coverage": {"total": 0}},
        ):
            modified = {**valid, **updates}
            with self.subTest(updates=updates):
                with self.assertRaises(ValueError):
                    validate_report(modified, sha)


    def test_native_metadata_records_measured_counters_and_run_attempt(self):
        sha = "a" * 40
        report = {
            "repository": "LibreSign/libresign", "ref": "refs/heads/main", "commit": sha,
            "timestamp": "2026-11-07T23:51:03.341546702Z",
            "coverage": {"covered": 21718, "total": 28544},
        }
        metadata = metadata_for(report, sha, 123, 2)
        self.assertEqual(metadata["recorded_at"], "2026-11-07T23:51:03Z")
        self.assertEqual(metadata["source_run_attempt"], 2)
        self.assertEqual(metadata["covered"], 21718)
        self.assertFalse(metadata["preview"])
        for modified in (
            {**report, "timestamp": "2026-11-07T23:51:03"},
            {**report, "ref": "refs/pull/123/merge"},
            {**report, "coverage": {"covered": True, "total": 10}},
            {**report, "coverage": {"covered": 11, "total": 10}},
        ):
            with self.subTest(modified=modified):
                with self.assertRaises(ValueError):
                    metadata_for(modified, sha, 123, 1)
        for invalid_run in (0, True, -1):
            with self.subTest(run=invalid_run):
                with self.assertRaises(ValueError):
                    metadata_for(report, sha, invalid_run, 1)

    def test_reject_invalid_input_sha(self):
        with self.assertRaises(ValueError):
            validate_report({}, "../../main")


if __name__ == "__main__":
    unittest.main()
