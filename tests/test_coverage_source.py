# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

import unittest

from scripts.coverage_source import choose_reference, complete_source, validate_report


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

    def test_reject_invalid_input_sha(self):
        with self.assertRaises(ValueError):
            validate_report({}, "../../main")


if __name__ == "__main__":
    unittest.main()
