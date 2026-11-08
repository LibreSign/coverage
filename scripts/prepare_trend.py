# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Insert the compact coverage history chart into the generated homepage."""

import argparse
import shutil
from pathlib import Path

MARKER = '<!-- LibreSign trend chart -->'
WIDGET = '''<section id="coverage-trend" aria-labelledby="trend-title">
  <h2 id="trend-title">Total coverage over time</h2>
  <div class="coverage-controls">
    <label>Period
      <select id="trend-period">
        <option value="all">All time</option>
        <option value="year">Last 12 months</option>
        <option value="quarter">Last 90 days</option>
      </select>
    </label>
  </div>
  <p id="trend-status" aria-live="polite">Loading history…</p>
  <svg class="coverage-chart" aria-label="Total coverage over time"></svg>
  <div id="trend-hover-tooltip" role="tooltip" hidden></div>
  <a id="trend-point-details" href="#" hidden rel="noopener noreferrer"></a>
  <p class="coverage-legend"><small>Dots: measurements · Dashed: last known</small></p>
  <details id="trend-table-details"><summary>View measurements</summary>
    <table id="trend-data"><thead><tr><th>Date</th><th>Coverage</th><th>Covered / Total</th>
      <th>Commit</th><th>Report</th></tr></thead><tbody></tbody></table>
  </details>
  <details class="coverage-about"><summary>About the data</summary>
    <p>Historical measurements come from Coveralls; newer measurements come from Octocov.
       Their calculations differ, so we do not connect the two methods.</p>
    <a href="https://github.com/LibreSign/coverage/releases/tag/coveralls-archive-2026-11-07">
      Historical data archive</a>
  </details>
</section>'''


def prepare(public: Path):
    root = public / "index.html"
    data = public / "assets/coverage-history.json"
    if not root.is_file() or not data.is_file():
        raise FileNotFoundError("Generated index or history data not found")
    destination = public / "assets" / "coverage-theme"
    destination.mkdir(parents=True, exist_ok=True)
    source = Path(__file__).resolve().parents[1] / "site-theme"
    for name in ("trend.js", "trend.css"):
        shutil.copyfile(source / name, destination / name)

    content = root.read_text(encoding="utf-8")
    if MARKER in content:
        return
    target = '<!-- coverage-trend-slot -->'
    if target not in content:
        raise ValueError("Generated site lacks the coverage chart insertion slot")
    markup = (
        MARKER + '\n' + WIDGET + '\n'
        + '<link rel="stylesheet" href="assets/coverage-theme/trend.css">\n'
        + '<script src="assets/coverage-theme/trend.js" defer></script>\n'
    )
    root.write_text(content.replace(target, markup, 1), encoding="utf-8")


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--public", type=Path, required=True)
    prepare(parser.parse_args().public)
