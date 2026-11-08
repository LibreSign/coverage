# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Release archive integrity checks with a fake GitHub CLI, no remote writes."""

import json
import os
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SHA = "ab" * 20


class ArchiveCoverageTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        for folder in ("bin", "downloads/native", "downloads/phpunit", "site/libresign", "scripts"):
            (self.root / folder).mkdir(parents=True)
        for name in ("archive_coverage.sh", "coverage_source.py"):
            shutil.copyfile(ROOT / "scripts" / name, self.root / "scripts" / name)
        (self.root / "downloads/phpunit/clover.xml").write_bytes(b"<coverage>actual clover</coverage>")
        (self.root / "site/libresign/index.html").write_text("<html>PHPUnit files</html>")
        (self.root / "site/libresign/dashboard.html").write_text("<html>Dashboard</html>")
        self.report = {
            "repository": "LibreSign/libresign",
            "ref": "refs/heads/main",
            "commit": SHA,
            "timestamp": "2026-11-08T12:00:00Z",
            "coverage": {"covered": 2, "total": 3},
        }
        self.attempt = 1
        self.write_report()
        self.state = self.root / "fake-gh.json"
        self.state.write_text('{"releases":{"coverage-latest":{}},"events":[]}')
        self.fake_gh = self.root / "bin/gh"
        self.fake_gh.write_text(r'''#!/usr/bin/env python3
import hashlib,json,os,re,sys
from pathlib import Path
state=Path(os.environ["FAKE_GH_STATE"])
db=json.loads(state.read_text())
args=sys.argv[1:]
operation=args[0]
if operation=="release":
    subcommand=args[1]
    tag=args[2]
    if subcommand=="view":
        if tag not in db["releases"]:sys.exit(1)
    elif subcommand=="create":
        if tag in db["releases"]:sys.exit(2)
        db["releases"][tag]={}
    elif subcommand=="upload":
        if tag not in db["releases"]:sys.exit(3)
        for filename in args[3:]:
            if filename.startswith("--"):break
            p=Path(filename)
            digest="sha256:"+hashlib.sha256(p.read_bytes()).hexdigest()
            if p.name in db["releases"][tag] and "--clobber" not in args:sys.exit(4)
            db["releases"][tag][p.name]=digest
    elif subcommand=="edit":
        if tag not in db["releases"]:sys.exit(5)
    else:
        sys.exit(6)
elif operation=="api":
    if "--method" in args:sys.exit(7)
    path=next(a for a in args if a.startswith("repos/"))
    if "/releases/tags/" in path:
        tag=path.rsplit("/",1)[1]
        if tag not in db["releases"]:sys.exit(8)
        print(7)
    elif "/assets?" in path and "--paginate" in args:
        for tag,assets in db["releases"].items():
            if tag.startswith("coverage-history-"):
                for name,digest in assets.items():
                    print(name+"\t"+digest)
    else:
        sys.exit(9)
else:
    sys.exit(9)
state.write_text(json.dumps(db))
''')
        self.fake_gh.chmod(0o755)
        self.env = dict(os.environ)
        self.env.update({
            "PATH": str(self.root / "bin") + ":" + os.environ["PATH"],
            "GH_TOKEN": "fake-never-used-for-network",
            "GITHUB_REPOSITORY": "LibreSign/coverage",
            "FAKE_GH_STATE": str(self.state),
        })

    def write_report(self):
        (self.root / "downloads/native/report.json").write_text(json.dumps(self.report))
        metadata = {
            "repository": "LibreSign/libresign",
            "source_branch": "main",
            "source_commit_sha": SHA,
            "source_run_id": 1028,
            "source_run_attempt": self.attempt,
            "recorded_at": "2026-11-08T12:00:00Z",
            "covered": self.report["coverage"]["covered"],
            "total": self.report["coverage"]["total"],
            "preview": False,
        }
        (self.root / "downloads/metadata.json").write_text(json.dumps(metadata, indent=2) + "\n")

    def run_archive(self):
        return subprocess.run(["bash", "scripts/archive_coverage.sh"], cwd=self.root,
                              env=self.env, text=True, capture_output=True)

    def releases(self):
        return json.loads(self.state.read_text())["releases"]

    def history(self):
        history = [release for name, release in self.releases().items()
                   if name.startswith("coverage-history-")]
        self.assertEqual(len(history), 1)
        return history[0]

    def test_first_archive_and_retry_are_immutable(self):
        first = self.run_archive()
        self.assertEqual(first.returncode, 0, first.stderr + first.stdout)
        originals = self.history().copy()
        self.assertEqual(len(originals), 4)
        self.assertTrue(all("html" not in name for name in originals))
        second = self.run_archive()
        self.assertEqual(second.returncode, 0, second.stderr + second.stdout)
        self.assertEqual(self.history(), originals)
        self.assertIn("libresign-html.tar.gz", self.releases()["coverage-latest"])

    def test_conflicting_same_run_does_not_replace_history(self):
        self.assertEqual(self.run_archive().returncode, 0)
        original = self.history().copy()
        self.report["coverage"]["covered"] = 1
        self.write_report()
        again = self.run_archive()
        self.assertNotEqual(again.returncode, 0)
        self.assertIn("Conflicting archived coverage asset", again.stdout)
        self.assertEqual(self.history(), original)

    def test_new_attempt_has_distinct_historical_assets(self):
        self.assertEqual(self.run_archive().returncode, 0)
        self.attempt = 2
        self.write_report()
        second = self.run_archive()
        self.assertEqual(second.returncode, 0, second.stderr + second.stdout)
        self.assertEqual(len(self.history()), 8)

    def test_reject_invalid_provenance_before_any_release_write(self):
        self.report["ref"] = "refs/pull/9387/merge"
        self.write_report()
        failure = self.run_archive()
        self.assertNotEqual(failure.returncode, 0)
        self.assertFalse(any(k.startswith("coverage-history-") for k in self.releases()))

    def test_dispatched_old_run_cannot_replace_current_fallback(self):
        self.env["ARCHIVE_CURRENT_FALLBACK"] = "false"
        result = self.run_archive()
        self.assertEqual(result.returncode, 0, result.stderr + result.stdout)
        self.assertEqual(len(self.history()), 4)
        self.assertFalse(self.releases()["coverage-latest"])

    def test_reject_metadata_mismatch(self):
        self.assertEqual(self.run_archive().returncode, 0)
        original = self.history().copy()
        self.report["coverage"]["covered"] = 1
        (self.root / "downloads/native/report.json").write_text(json.dumps(self.report))
        failure = self.run_archive()
        self.assertNotEqual(failure.returncode, 0)
        self.assertIn("metadata changed", failure.stdout)
        self.assertEqual(self.history(), original)


if __name__ == "__main__":
    unittest.main()
