# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

import json
import re
import tempfile
import unittest
from pathlib import Path

from scripts.prepare_trend import MARKER, prepare


class TrendInjectionTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.public = Path(self.directory.name)
        self.html = self.public / "index.html"
        self.data = self.public / "assets/coverage-history.json"
        self.data.parent.mkdir()
        self.html.write_text('<html><body><!-- coverage-trend-slot --><h2 id="repositories">Repositories</h2></body></html>')
        self.data.write_text('{"schema":1,"repository":"LibreSign/libresign"}')

    def test_inserts_graph_without_modifying_octocov(self):
        before = self.html.read_text()
        prepare(self.public)
        result = self.html.read_text()
        self.assertIn(MARKER, result)
        self.assertIn('id="coverage-trend"', result)
        self.assertIn('id="repositories"', result)
        self.assertEqual(result.count('id="repositories"'), before.count('id="repositories"'))
        self.assertTrue((self.public / "assets/coverage-theme/trend.js").is_file())
        self.assertTrue((self.public / "assets/coverage-theme/trend.css").is_file())
        self.assertIn('assets/coverage-theme/trend.js', result)
        self.assertRegex(result, r'assets/coverage-theme/trend\.js\?v=[0-9a-f]{12}')
        self.assertRegex(result, r'assets/coverage-theme/trend\.css\?v=[0-9a-f]{12}')

    def test_chart_uses_english_and_no_source_selector(self):
        prepare(self.public)
        markup = self.html.read_text()
        self.assertIn('Total coverage over time', markup)
        self.assertIn('id="trend-period"', markup)
        self.assertNotIn('id="trend-source"', markup)
        self.assertIn('>Report</th>', markup)
        self.assertIn('About the data', markup)
        for phrase in ('Evolução', 'Fonte', 'Período', 'medição',
                       'Origem', 'histórico', 'Octocov — main'):
            self.assertNotIn(phrase, markup)

    def test_svg_chart_keeps_its_aspect_ratio_at_wide_resolutions(self):
        # A 340px height cap caused the viewBox to letterbox the plot
        # horizontally instead of using the panel width at 190% zoom.
        css = (Path(__file__).resolve().parents[1] / "site-theme" / "trend.css").read_text()
        match = re.search(r"\.coverage-chart\s*\{([^}]*)\}", css)
        self.assertIsNotNone(match)
        declarations = match.group(1)
        self.assertRegex(declarations, r"\bwidth\s*:\s*100%")
        self.assertRegex(declarations, r"\bheight\s*:\s*auto")
        self.assertNotRegex(declarations, r"\bmax-height\s*:")

    def test_measured_points_are_exposed_as_svg_report_links(self):
        script = (Path(__file__).resolve().parents[1] / "site-theme" / "trend.js").read_text()
        self.assertIn("node('a', {href: report.href", script)
        self.assertIn("class: 'coverage-point-link'", script)
        self.assertIn("chart.setAttribute('role', 'group')", script)
        self.assertNotIn("dot.addEventListener('click'", script)

    def test_rerunning_does_not_duplicate_chart(self):
        prepare(self.public)
        before = self.html.read_bytes()
        prepare(self.public)
        self.assertEqual(before, self.html.read_bytes())

    def test_missing_dataset_is_an_error(self):
        self.data.unlink()
        with self.assertRaises(FileNotFoundError):
            prepare(self.public)

    def test_unexpected_site_template_does_not_silently_succeed(self):
        self.html.write_text("<html><body>Missing index</body></html>")
        with self.assertRaises(ValueError):
            prepare(self.public)


if __name__ == "__main__":
    unittest.main()
