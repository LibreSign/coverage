# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Regression checks for theme paths and the generated report contract."""

import json
import tempfile
import unittest
from pathlib import Path

from scripts.prepare_site import MARKER, publish_theme


class PublishThemeTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.public = self.root / "public"
        (self.public / "libresign" / "Service").mkdir(parents=True)
        self.metadata = self.root / "metadata.json"
        self.sha = "a" * 40
        self.metadata.write_text(json.dumps({"source_commit_sha": self.sha}))
        self.files = (
            self.public / "index.html",
            self.public / "libresign" / "index.html",
            self.public / "libresign" / "dashboard.html",
            self.public / "libresign" / "Service" / "Example.php.html",
        )
        for path in self.files:
            path.write_text("<html><head><title>Generated</title></head><body>Coverage</body></html>")

    def test_relative_asset_links_work_at_every_depth(self):
        self.assertEqual(publish_theme(self.public, self.metadata), 4)
        for path in self.files:
            html = path.read_text()
            self.assertIn(MARKER, html)
            self.assertIn(f'content="{self.sha}"', html)
            import re

            for asset in ("theme.css", "theme.js"):
                match = re.search(r'(?:href|src)="([^"]*' + asset + r')"', html)
                self.assertIsNotNone(match)
                self.assertTrue((path.parent / match.group(1)).is_file(), path)

    def test_publication_is_idempotent(self):
        publish_theme(self.public, self.metadata)
        before = [p.read_bytes() for p in self.files]
        publish_theme(self.public, self.metadata)
        self.assertEqual(before, [p.read_bytes() for p in self.files])

    def test_invalid_commit_is_rejected(self):
        self.metadata.write_text(json.dumps({"source_commit_sha": "invalid"}))
        with self.assertRaisesRegex(ValueError, "valid commit SHA"):
            publish_theme(self.public, self.metadata)

    def test_broken_generated_html_is_rejected(self):
        self.files[-1].write_text("<html><body>Missing head</body></html>")
        with self.assertRaisesRegex(ValueError, "Missing </head>"):
            publish_theme(self.public, self.metadata)

    def test_required_phpunit_report_must_exist(self):
        (self.public / "libresign" / "index.html").unlink()
        with self.assertRaisesRegex(FileNotFoundError, "Missing PHPUnit"):
            publish_theme(self.public, self.metadata)


if __name__ == "__main__":
    unittest.main()
