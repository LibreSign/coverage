# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Prevent old GitHub Pages project paths from leaking into the custom-domain site."""

import subprocess
import tempfile
import unittest
from pathlib import Path
from urllib.parse import urljoin


ROOT = Path(__file__).resolve().parents[1]
DOMAIN = "https://coverage.libresign.coop/"


class CustomDomainTests(unittest.TestCase):
    def test_jekyll_build_sets_canonical_root_and_rejects_old_asset_path(self):
        workflow = (ROOT / ".github/workflows/pages.yml").read_text()
        self.assertIn('url: "https://coverage.libresign.coop"', workflow)
        self.assertIn('baseurl: ""', workflow)
        self.assertIn("grep -Fq '/coverage/assets/' public/index.html", workflow)
        self.assertIn("test -s public/assets/css/style.css", workflow)

    def test_badge_link_rewrite_is_relative_to_site_root(self):
        workflow = (ROOT / ".github/workflows/pages.yml").read_text()
        command = next(
            line.strip() for line in workflow.splitlines()
            if line.lstrip().startswith("sed -i ")
            and "site/octocov-index.md" in line
        )
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "site/octocov-index.md"
            path.parent.mkdir()
            path.write_text(
                "![Coverage](https://raw.githubusercontent.com/LibreSign/coverage/"
                "main/site/badges/LibreSign/libresign/coverage.svg)"
            )
            subprocess.run(["bash", "-e", "-c", command], cwd=directory, check=True)
            output = path.read_text()
        target = "badges/LibreSign/libresign/coverage.svg"
        self.assertIn(f"![Coverage]({target})", output)
        self.assertEqual(urljoin(DOMAIN, target), DOMAIN + target)
        self.assertEqual(
            urljoin("https://libresign.github.io/coverage/", target),
            "https://libresign.github.io/coverage/" + target,
        )

    def test_readme_uses_canonical_host(self):
        readme = (ROOT / "README.md").read_text()
        self.assertIn(DOMAIN, readme)
        self.assertNotIn("https://libresign.github.io/coverage/", readme)

    def test_custom_domain_instructions_live_in_maintainer_guide(self):
        guide = (ROOT / "docs/maintainers.md").read_text()
        self.assertIn(DOMAIN, guide)
        self.assertIn("GitHub Pages settings", guide)
        self.assertIn("empty `baseurl`", guide)


if __name__ == "__main__":
    unittest.main()
