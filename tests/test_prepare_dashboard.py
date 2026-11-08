# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

import json
import tempfile
import unittest
from pathlib import Path

from scripts.prepare_dashboard import MARKER, prepare, simplify_table

SAMPLE = '''<table><thead><tr><th>Repository</th><th>Coverage</th>
<th>Code to Test Ratio</th><th>Time Execution Time</th><th>Badges</th></tr></thead>
<tbody><tr><td><a href="https://github.com/LibreSign/libresign">LibreSign/libresign</a></td>
<td>76.0%</td><td>-</td><td>-</td>
<td><img src="badges/x.svg" alt="badge" /> <details>&lt;summary&gt;Copy status badge markdown
&lt;/summary&gt;<code>Copy</code></details></td></tr></tbody></table>'''


class DashboardMarkupTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.path = self.root / "index.html"
        (self.root / "assets").mkdir()
        (self.root / "assets" / "coverage-summary.json").write_text(json.dumps({
            "schema": 1, "repository": "LibreSign/libresign",
            "commit": "a" * 40, "source_branch": "feature/pr", "run_id": 1234,
            "preview": True, "measured_at": "2026-11-08T00:07:10Z",
            "runtime": "PHP 8.5 / Nextcloud master",
            "covered": 21, "total": 30, "uncovered": 9, "files_count": 11,
        }))
        self.path.write_text(
            '<html><head></head><body><h1>Home</h1>'
            '<!-- LibreSign trend chart --><section id="coverage-trend">Example</section>'
            '<h2 id="repositories">Repositories</h2>' + SAMPLE +
            '<footer><a href="https://github.com/LibreSign/coverage/edit/main/index.md">'
            'Improve this page.</a></footer></body></html>'
        )

    def test_injects_summary_explorer_and_pr_provenance(self):
        prepare(self.root)
        page = self.path.read_text()
        self.assertIn(MARKER, page)
        self.assertIn('<body class="coverage-home">', page)
        self.assertIn('70.0%', page)
        self.assertIn('21', page)
        self.assertIn('Uncovered lines', page)
        self.assertIn('Files to review', page)
        self.assertIn('Coverage color legend', page)
        self.assertIn('Low (below 50%)', page)
        self.assertIn('Moderate (50–79%)', page)
        self.assertIn('High (80% or more)', page)
        self.assertIn('PR snapshot · not a main-branch baseline', page)
        self.assertIn('Commit aaaaaaaaa', page)
        self.assertIn('CI run #1234', page)
        self.assertIn('assets/coverage-theme/overview.js?v=', page)
        self.assertIn('assets/coverage-theme/overview.css?v=', page)
        self.assertNotIn('<td>70.0%</td>', page)
        self.assertIn('<th scope="col">Coverage</th>', page)
        self.assertNotIn('<th scope="col">Badge</th>', page)
        self.assertIn('Publishing workflow', page)
        self.assertNotIn('Time Execution Time', page)
        self.assertNotIn('Code to Test Ratio', page)
        self.assertNotIn('Copy status badge markdown', page)
        self.assertIn('badges/x.svg', page)
        self.assertIn('href="libresign/"', page)
        self.assertNotIn('edit/main/index.md', page)

    def test_landing_layout_is_scoped_and_responsive(self):
        prepare(self.root)
        page = self.path.read_text()
        self.assertIn('<body class="coverage-home">', page)
        css = (Path(__file__).resolve().parents[1] / "site-theme" / "overview.css").read_text()
        self.assertIn("body.coverage-home > .container-lg", css)
        self.assertIn("max-width: 2880px", css)
        self.assertIn("@media (min-width: 2100px)", css)
        self.assertIn("grid-column: 2", css)

    def test_jekyll_body_missing_fails_explicitly(self):
        self.path.write_text(self.path.read_text().replace("<body>", "<main>"))
        with self.assertRaisesRegex(ValueError, "Jekyll homepage body"):
            prepare(self.root)

    def test_keeps_nonempty_octocov_metric_columns(self):
        page = SAMPLE.replace('<td>-</td><td>-</td>',
                              '<td>1:0.5</td><td>4m12s</td>')
        result = simplify_table(page)
        self.assertIn('Code / test ratio', result)
        self.assertIn('Test duration', result)
        self.assertIn('1:0.5', result)
        self.assertIn('4m12s', result)

    def test_idempotent_publication(self):
        prepare(self.root)
        before = self.path.read_bytes()
        prepare(self.root)
        self.assertEqual(before, self.path.read_bytes())

    def test_requires_upstream_octocov_table(self):
        self.path.write_text('<html><!-- LibreSign trend chart --><h2 id="repositories">'
                             'Repositories</h2></html>')
        with self.assertRaisesRegex(ValueError, "table"):
            prepare(self.root)

    def test_missing_metrics_is_error(self):
        (self.root / "assets" / "coverage-summary.json").unlink()
        with self.assertRaises(FileNotFoundError):
            prepare(self.root)


if __name__ == "__main__":
    unittest.main()
