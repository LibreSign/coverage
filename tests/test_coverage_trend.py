# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

import unittest

from scripts.coverage_trend import legacy_series, native_series

S = "a" * 40


class TrendTests(unittest.TestCase):
    def legacy(self, **changes):
        result = {
            "repo_name": "LibreSign/libresign", "branch": "main",
            "commit_sha": S, "calculated_at": "2023-01-01T00:00:00Z",
            "covered_percent": 75.0, "covered_lines": 3, "relevant_lines": 4, "id": 1,
        }
        result.update(changes)
        return result

    def test_only_main_and_unique_commits(self):
        records = [
            self.legacy(),
            self.legacy(branch="feature"),
            self.legacy(calculated_at="2023-01-02T00:00:00Z"),
            self.legacy(commit_sha="b" * 40, calculated_at="2023-01-03T00:00:00Z"),
        ]
        points = legacy_series(records)
        self.assertEqual(len(points), 2)
        self.assertEqual(points[0]["date"], "2023-01-02T00:00:00Z")

    def test_reject_mismatched_legacy_counter(self):
        self.assertEqual(legacy_series([self.legacy(covered_percent=99)]), [])

    def test_source_and_preview_are_excluded(self):
        record = {
            "repository": "LibreSign/libresign", "source_branch": "main", "preview": False,
            "source_commit_sha": S, "source_run_id": 123,
            "covered": 9, "total": 10, "recorded_at": "2026-11-08T00:00:00Z",
        }
        self.assertEqual(len(native_series([record])), 1)
        self.assertEqual(native_series([{**record, "preview": True}]), [])
        self.assertEqual(native_series([{**record, "source_branch": "dev"}]), [])
        with self.assertRaises(ValueError):
            native_series([{**record, "covered": 11}])

    def test_source_stays_separate_from_legacy(self):
        self.assertEqual(len(legacy_series([self.legacy()])), 1)
        self.assertEqual(native_series([]), [])

    def test_invalid_date_is_not_silently_assumed(self):
        with self.assertRaises(ValueError):
            legacy_series([self.legacy(calculated_at="2023-01-01")])


if __name__ == "__main__":
    unittest.main()
