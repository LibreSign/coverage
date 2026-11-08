# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Test main-run provenance, fallback and failure boundaries without GitHub network."""

import json
import os
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SHA = "ab" * 20
RUN_ID = "42427763206"


class NativeArtifactWorkflowTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / "bin").mkdir()
        (self.root / "scripts").mkdir()
        shutil.copyfile(ROOT / "scripts/coverage_source.py", self.root / "scripts/coverage_source.py")
        shutil.copyfile(ROOT / "scripts/prepare_native_coverage.sh", self.root / "scripts/prepare_native_coverage.sh")
        self.out = self.root / "output"
        self.out.touch()
        self.env = dict(os.environ, PATH=str(self.root / "bin") + ":" + os.environ["PATH"],
                        GH_TOKEN="test-token-not-used-for-network", GITHUB_OUTPUT=str(self.out))
        self.artifacts = [
            {"name": "octocov-report", "expired": False},
            {"name": "phpunit-coverage-php-8.5-nextcloud-master", "expired": False},
        ]
        (self.root / "artifacts.json").write_text(json.dumps({"artifacts": self.artifacts}))
        (self.root / "report.json").write_text(json.dumps({
            "repository": "LibreSign/libresign", "ref": "refs/heads/main", "commit": SHA,
            "coverage": {"covered": 2, "total": 3},
            "timestamp": "2026-11-08T12:00:00Z",
        }))
        (self.root / "clover.xml").write_text("<coverage></coverage>")
        (self.root / "index.html").write_text("<html>Real source</html>")
        self.requested = {
            "id": int(RUN_ID), "run_attempt": 1, "head_sha": SHA,
            "head_repository": {"full_name": "LibreSign/libresign"},
            "head_branch": "main", "event": "push",
            "status": "completed", "conclusion": "success",
            "path": ".github/workflows/phpunit-sqlite.yml",
        }
        self.write_requested()
        script = self.root / "bin/gh"
        script.write_text("""#!/bin/bash
set -euo pipefail
if [[ "$1" == "api" && "$2" == *"/runs/$RUN_ID" ]]; then
  cat "$FAKE_ROOT/requested-run.json"
elif [[ "$1" == "api" && "$2" == *"/runs?"* ]]; then
  printf '%s %s %s\\n' "$RUN_ID" "$SOURCE_SHA" "1"
elif [[ "$1" == "api" && "$2" == *"/artifacts" ]]; then
  if [[ "$ARTIFACT_API_ERROR" == "yes" ]]; then exit 22; fi
  cat "$FAKE_ROOT/artifacts.json"
elif [[ "$1" == "run" && "$2" == "download" ]]; then
  selected=''
  directory=''
  while [[ "$#" -gt 0 ]]; do
    case "$1" in
      --name) selected="$2"; shift 2;;
      --dir) directory="$2"; shift 2;;
      *) shift;;
    esac
  done
  mkdir -p "$directory"
  if [[ "$selected" == "octocov-report" ]]; then
    cp "$FAKE_ROOT/report.json" "$directory/report.json"
  elif [[ "$selected" == phpunit-coverage-* ]]; then
    cp "$FAKE_ROOT/clover.xml" "$directory/clover.xml"
    mkdir -p "$directory/html-coverage"
    cp "$FAKE_ROOT/index.html" "$directory/html-coverage/index.html"
  else exit 23; fi
else
  echo "Unexpected fake GitHub operation" >&2
  exit 24
fi
""")
        script.chmod(0o755)
        self.env.update(FAKE_ROOT=str(self.root), RUN_ID=RUN_ID, SOURCE_SHA=SHA,
                        ARTIFACT_API_ERROR="no")

    def write_requested(self):
        (self.root / "requested-run.json").write_text(json.dumps(self.requested))

    def pin_dispatch(self):
        self.env.update({
            "COVERAGE_SOURCE_REPOSITORY": "LibreSign/libresign",
            "COVERAGE_SOURCE_RUN_ID": RUN_ID,
            "COVERAGE_SOURCE_SHA": SHA,
        })

    def test_pinned_dispatch_archives_exact_verified_main_run(self):
        self.pin_dispatch()
        result = self.run_workflow()
        self.assertEqual(result.returncode, 0, result.stderr + result.stdout)
        self.assertIn(f"source_run_id={RUN_ID}", self.out.read_text())
        self.assertEqual(
            json.loads((self.root / "downloads/metadata.json").read_text())["source_run_id"],
            int(RUN_ID),
        )

    def test_pinned_dispatch_rejects_untrusted_run_metadata(self):
        self.pin_dispatch()
        for changed in (
            {"event": "pull_request"},
            {"head_branch": "feature"},
            {"conclusion": "failure"},
            {"status": "in_progress"},
            {"head_sha": "c" * 40},
            {"path": ".github/workflows/other.yml"},
            {"head_repository": {"full_name": "attacker/repo"}},
        ):
            with self.subTest(changed=changed):
                self.requested = {
                    "id": int(RUN_ID), "run_attempt": 1, "head_sha": SHA,
                    "head_repository": {"full_name": "LibreSign/libresign"},
                    "head_branch": "main", "event": "push",
                    "status": "completed", "conclusion": "success",
                    "path": ".github/workflows/phpunit-sqlite.yml",
                    **changed,
                }
                self.write_requested()
                result = self.run_workflow()
                self.assertNotEqual(result.returncode, 0, changed)
                self.assertNotIn("available=true", self.out.read_text())

    def test_pinned_dispatch_fails_if_artifact_is_missing(self):
        self.pin_dispatch()
        self.artifacts.pop(0)
        (self.root / "artifacts.json").write_text(json.dumps({"artifacts": self.artifacts}))
        result = self.run_workflow()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("no complete native", result.stdout)

    def run_workflow(self):
        return subprocess.run(
            ["bash", "scripts/prepare_native_coverage.sh"],
            cwd=self.root,
            env=self.env,
            text=True,
            capture_output=True,
        )

    def test_real_main_provenance_is_preserved_and_verified(self):
        result = self.run_workflow()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("available=true", self.out.read_text())
        self.assertEqual(json.loads((self.root / "downloads/metadata.json").read_text())["source_commit_sha"], SHA)
        self.assertFalse(json.loads((self.root / "downloads/metadata.json").read_text())["preview"])
        self.assertEqual(json.loads((self.root / "downloads/metadata.json").read_text())["source_run_attempt"], 1)
        self.assertEqual(json.loads((self.root / "downloads/metadata.json").read_text())["covered"], 2)
        self.assertTrue((self.root / "site/libresign/clover.xml").is_file())
        self.assertTrue((self.root / "site/libresign/index.html").is_file())

    def test_no_native_artifact_keeps_safe_fallback(self):
        self.artifacts.pop(0)
        (self.root / "artifacts.json").write_text(json.dumps({"artifacts": self.artifacts}))
        result = self.run_workflow()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("available=false", self.out.read_text())
        self.assertFalse((self.root / "downloads/metadata.json").exists())

    def test_mismatched_commit_must_never_publish(self):
        (self.root / "report.json").write_text(json.dumps({
            "repository": "LibreSign/libresign", "ref": "refs/heads/main",
            "commit": "cd" * 20, "coverage": {"covered": 2, "total": 3},
        }))
        result = self.run_workflow()
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn("available=true", self.out.read_text())

    def test_failed_artifact_api_is_not_treated_as_absent_report(self):
        self.env["ARTIFACT_API_ERROR"] = "yes"
        result = self.run_workflow()
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn("available=false", self.out.read_text())


if __name__ == "__main__":
    unittest.main()
