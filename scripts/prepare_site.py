# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Add navigation and theme support to pre-generated static coverage HTML.

The HTML remains produced by upstream Jekyll, Octocov and PHPUnit. This module
does not calculate coverage, change source-line data or render report pages.
"""

import argparse
import html
import json
import os
import re
import shutil
from pathlib import Path

ASSETS = Path(__file__).resolve().parents[1] / "site-theme"
COMMIT_PATTERN = re.compile(r"^[0-9a-f]{40}$")
MARKER = "<!-- LibreSign coverage navigation and theme -->"


def publish_theme(public: Path, metadata: Path) -> int:
    """Install shared assets and links on every generated HTML page, idempotently."""
    meta = json.loads(metadata.read_text(encoding="utf-8"))
    commit = meta.get("source_commit_sha")
    if not isinstance(commit, str) or not COMMIT_PATTERN.fullmatch(commit):
        raise ValueError("Coverage metadata must contain a valid commit SHA")

    public = public.resolve()
    if not (public / "index.html").is_file():
        raise FileNotFoundError(f"Missing generated index: {public / 'index.html'}")
    if not (public / "libresign" / "index.html").is_file():
        raise FileNotFoundError("Missing PHPUnit coverage HTML")

    destination = public / "assets" / "coverage-theme"
    destination.mkdir(parents=True, exist_ok=True)
    for name in ("theme.css", "theme.js"):
        shutil.copyfile(ASSETS / name, destination / name)

    pages = sorted(public.rglob("*.html"))
    for page in pages:
        content = page.read_text(encoding="utf-8")
        if MARKER in content:
            continue
        if "</head>" not in content:
            raise ValueError(f"Missing </head> in generated page {page}")
        prefix = os.path.relpath(destination, page.parent).replace(os.sep, "/")
        injections = (
            f"\n{MARKER}\n"
            f'<meta name="coverage-source-commit" content="{html.escape(commit)}">\n'
            f'<link rel="stylesheet" href="{prefix}/theme.css">\n'
            f'<script src="{prefix}/theme.js"></script>\n'
        )
        page.write_text(
            content.replace("</head>", injections + "</head>", 1),
            encoding="utf-8",
        )
    return len(pages)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--public", type=Path, required=True)
    parser.add_argument("--metadata", type=Path, required=True)
    args = parser.parse_args()
    print(f"Theme installed on {publish_theme(args.public, args.metadata)} pages")


if __name__ == "__main__":
    main()
