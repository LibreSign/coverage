# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Enhance the generated Octocov index without changing its upstream renderer."""

import argparse
import hashlib
import html
import json
import re
import shutil
from datetime import datetime
from pathlib import Path

MARKER = "<!-- LibreSign coverage overview -->"
TABLE = re.compile(
    r'(<h2 id="repositories">Repositories</h2>\s*)(<table>.*?</table>)',
    re.DOTALL,
)
ROW = re.compile(r"<tr>\s*(.*?)\s*</tr>", re.DOTALL)
TH = re.compile(r"<th[^>]*>(.*?)</th>", re.DOTALL)
TD = re.compile(r"<td[^>]*>(.*?)</td>", re.DOTALL)


def text(value: object) -> str:
    return html.escape(str(value), quote=True)


def simplify_table(markup: str, current_percent: float | None = None) -> str:
    """Hide entirely unavailable metrics and remove broken copy markup.

    The original Octocov markdown is not modified; badges are still generated
    by its native Central Mode. A changed upstream table fails explicitly.
    """
    rows = ROW.findall(markup)
    if len(rows) < 2:
        raise ValueError("Missing Octocov repository rows")
    headings = TH.findall(rows[0])
    expected = ["Repository", "Coverage", "Code to Test Ratio", "Time Execution Time", "Badges"]
    if [re.sub(r"<[^>]+>", "", h).strip() for h in headings] != expected:
        raise ValueError("Unexpected Octocov repository table format")
    body = [TD.findall(row) for row in rows[1:]]
    if any(len(cells) != len(headings) for cells in body):
        raise ValueError("Unexpected Octocov repository cell count")

    visible = [0, 1]
    for i in (2, 3):
        if any(re.sub(r"<[^>]+>", "", cells[i]).strip() not in ("-", "—", "")
               for cells in body):
            visible.append(i)
    labels = {
        0: "Repository", 1: "Coverage", 2: "Code / test ratio",
        3: "Test duration",
    }
    out = ['<table class="coverage-repository-table"><thead><tr>']
    out.extend(f'<th scope="col">{labels[i]}</th>' for i in visible)
    out.append('</tr></thead><tbody>')
    for cells in body:
        out.append('<tr>')
        for i in visible:
            content = cells[i].strip()
            if i == 1:
                # Keep the original Octocov badge in the compact coverage
                # column. The precise counter-based score lives in the KPI.
                badge = re.sub(r'\s*<details\b[^>]*>.*?</details>', "", cells[4],
                               flags=re.DOTALL).strip()
                if badge:
                    content = badge
                elif current_percent is not None and "LibreSign/libresign" in cells[0]:
                    content = f"{current_percent:.1f}%"
            if i == 0:
                # This is a coverage index; the application source stays
                # independently linked in the page header.
                content = re.sub(
                    r'<a href="https://github.com/LibreSign/libresign">([^<]+)</a>',
                    r'<a href="libresign/">\1</a>', content, count=1
                )
            out.append('<td>' + content + '</td>')
        out.append('</tr>')
    out.append('</tbody></table>')
    return "".join(out)


def overview(summary: dict) -> str:
    if summary.get("repository") != "LibreSign/libresign" or summary.get("schema") != 1:
        raise ValueError("Invalid summary for LibreSign homepage")
    sha = summary.get("commit", "")
    if not isinstance(sha, str) or not re.fullmatch(r"[0-9a-f]{40}", sha):
        raise ValueError("Invalid summary source SHA")
    run = summary.get("run_id")
    if type(run) is not int or run <= 0 or type(summary.get("preview")) is not bool:
        raise ValueError("Invalid source run or preview indicator")
    covered, total, missing = summary["covered"], summary["total"], summary["uncovered"]
    if not 0 <= covered <= total or missing != total - covered or total == 0:
        raise ValueError("Inconsistent summary counters")
    stamp = datetime.fromisoformat(summary["measured_at"].replace("Z", "+00:00"))
    date = stamp.strftime("%b %-d, %Y")
    badges = ('<span class="coverage-preview-badge">Preview</span>'
              if summary["preview"] else '<span class="coverage-main-badge">main</span>')
    label = ('PR snapshot · not a main-branch baseline'
             if summary["preview"] else 'Verified main-branch report')
    runtime = summary.get("runtime")
    runtime_label = f'<span>{text(runtime)}</span>' if runtime else ''
    url = f"https://github.com/LibreSign/libresign/actions/runs/{run}"
    sha_url = f"https://github.com/LibreSign/libresign/commit/{sha}"
    pct = f'{covered * 100 / total:.1f}%'

    return f'''<section id="coverage-overview" aria-labelledby="coverage-overview-title">
      <div class="coverage-overview-heading">
        <h2 id="coverage-overview-title">Latest report</h2>{badges}
      </div>
      <div class="coverage-kpis">
        <div class="coverage-kpi coverage-kpi-primary">
          <span class="coverage-kpi-label">Line coverage</span>
          <strong class="coverage-kpi-value">{pct}</strong>
          <span class="coverage-kpi-caption">{text(label)}</span>
          <span class="coverage-kpi-track" aria-hidden="true">
            <span style="width:{covered * 100 / total:.2f}%"></span>
          </span>
        </div>
        <div class="coverage-kpi">
          <span class="coverage-kpi-label">Covered lines</span>
          <strong class="coverage-kpi-value">{covered:,}</strong>
        </div>
        <div class="coverage-kpi">
          <span class="coverage-kpi-label">Uncovered lines</span>
          <strong class="coverage-kpi-value">{missing:,}</strong>
        </div>
        <div class="coverage-kpi">
          <span class="coverage-kpi-label">Files analyzed</span>
          <strong class="coverage-kpi-value">{summary["files_count"]:,}</strong>
        </div>
      </div>
      <p class="coverage-overview-meta">
        <time datetime="{text(summary["measured_at"])}">{text(date)}</time>
        {runtime_label}
        <a href="{sha_url}">Commit {sha[:9]}</a>
        <a href="{url}">CI run #{run}</a>
      </p>
    </section>'''


EXPLORER = '''<section id="coverage-explorer" aria-labelledby="coverage-explorer-title">
  <div class="coverage-explorer-heading">
    <div><h2 id="coverage-explorer-title">Explore coverage</h2>
      <p class="coverage-explorer-subtitle">Find the files with the most uncovered lines.</p>
    </div>
    <a href="libresign/">Browse all source lines</a>
  </div>
  <p class="coverage-level-legend" aria-label="Coverage color legend">
    <span class="coverage-level-key is-low">Low (below 50%)</span>
    <span class="coverage-level-key is-medium">Moderate (50–79%)</span>
    <span class="coverage-level-key is-high">High (80% or more)</span>
  </p>
  <div class="coverage-explorer-columns">
    <section class="coverage-panel" aria-labelledby="coverage-directories-title">
      <div class="coverage-panel-heading">
        <h3 id="coverage-directories-title">Directories</h3>
        <button id="coverage-clear-directory" type="button" hidden>Clear filter</button>
      </div>
      <div id="coverage-directory-list" aria-live="polite">Loading directories…</div>
      <button id="coverage-more-directories" class="coverage-secondary-button" type="button" hidden>Show all directories</button>
    </section>
    <section class="coverage-panel" aria-labelledby="coverage-files-title">
      <div class="coverage-panel-heading"><h3 id="coverage-files-title">Files to review</h3>
        <span id="coverage-file-count" aria-live="polite"></span>
      </div>
      <div class="coverage-file-controls">
        <input id="coverage-file-search" type="search" placeholder="Search files" aria-label="Search coverage files">
        <select id="coverage-file-sort" aria-label="Sort coverage files">
          <option value="uncovered">Most uncovered</option>
          <option value="percent">Lowest coverage</option>
          <option value="name">File name</option>
        </select>
      </div>
      <div class="coverage-table-scroll">
        <table class="coverage-file-table"><thead><tr>
          <th scope="col">File</th><th scope="col">Coverage</th>
          <th scope="col">Uncovered</th>
        </tr></thead><tbody id="coverage-file-list"></tbody></table>
      </div>
      <button id="coverage-more-files" class="coverage-secondary-button" type="button" hidden>Show more files</button>
      <noscript>Enable JavaScript to search the coverage files. <a href="libresign/">View the complete PHPUnit report</a>.</noscript>
    </section>
  </div>
</section>'''


def prepare(public: Path) -> None:
    index = public / "index.html"
    payload = public / "assets" / "coverage-summary.json"
    if not index.is_file() or not payload.is_file():
        raise FileNotFoundError("Generated homepage or coverage summary is missing")
    summary = json.loads(payload.read_text(encoding="utf-8"))
    content = index.read_text(encoding="utf-8")
    if MARKER in content:
        return
    if content.count("<!-- LibreSign trend chart -->") != 1:
        raise ValueError("Coverage trend insertion point missing or duplicated")
    if content.count('<h2 id="repositories">Repositories</h2>') != 1:
        raise ValueError("Octocov repository section missing or duplicated")
    table = TABLE.search(content)
    if table is None:
        raise ValueError("Octocov repository table is missing")
    content = content[:table.start(2)] + simplify_table(table.group(2), summary["covered"] * 100 / summary["total"]) + content[table.end(2):]
    # Only the homepage opts into the fluid dashboard. The Jekyll Primer
    # theme otherwise caps .container-lg at 1012px on every viewport.
    if content.count("<body>") != 1:
        raise ValueError("Expected one Jekyll homepage body")
    content = content.replace("<body>", '<body class="coverage-home">', 1)
    content = content.replace(
        "<!-- LibreSign trend chart -->",
        MARKER + overview(summary) + "\n<!-- LibreSign trend chart -->", 1
    )
    content = content.replace(
        '<h2 id="repositories">Repositories</h2>',
        EXPLORER + '\n<h2 id="repositories">Repositories</h2>', 1
    )
    content = content.replace(
        "https://github.com/LibreSign/coverage/edit/main/index.md",
        "https://github.com/LibreSign/coverage/blob/main/.github/workflows/pages.yml"
    ).replace("Improve this page", "Publishing workflow")

    theme = Path(__file__).resolve().parents[1] / "site-theme"
    assets = public / "assets" / "coverage-theme"
    assets.mkdir(parents=True, exist_ok=True)
    for name in ("overview.css", "overview.js"):
        shutil.copyfile(theme / name, assets / name)
    css = hashlib.sha256((theme / "overview.css").read_bytes()).hexdigest()[:12]
    js = hashlib.sha256((theme / "overview.js").read_bytes()).hexdigest()[:12]
    content = content.replace(
        "</head>", f'<link rel="stylesheet" href="assets/coverage-theme/overview.css?v={css}">\n</head>', 1
    )
    # The script needs only DOM-ready content and must not mutate reports.
    content = content.replace(
        "</body>", f'<script src="assets/coverage-theme/overview.js?v={js}" defer></script>\n</body>', 1
    )
    index.write_text(content, encoding="utf-8")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--public", required=True, type=Path)
    prepare(parser.parse_args().public)
