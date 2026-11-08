#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

set -euo pipefail
: "${GH_TOKEN:?GitHub token required for public Actions artifact downloads}"
: "${GITHUB_OUTPUT:?GitHub output file is required}"

# Only use successful main runs that contain *both* a native Octocov report
# and a PHPUnit matrix HTML/Clover report. Never silently blend unrelated SHAs.
repo=LibreSign/libresign
mkdir -p downloads site/libresign site/badges
mapfile -t runs < <(
  gh api "repos/${repo}/actions/workflows/phpunit-sqlite.yml/runs?branch=main&status=success&per_page=30" \
    --jq '.workflow_runs[] | "\(.id) \(.head_sha)"'
)

for run in "${runs[@]}"; do
  read -r run_id source_sha <<< "${run}"
  [[ "${run_id}" =~ ^[0-9]+$ && "${source_sha}" =~ ^[0-9a-f]{40}$ ]] || exit 1
  gh api "repos/${repo}/actions/runs/${run_id}/artifacts" > downloads/artifacts.json
  artifact="$(python3 scripts/coverage_source.py select downloads/artifacts.json)"
  if [[ -z "${artifact}" ]]; then
    continue
  fi
  echo "Found complete native Octocov and PHPUnit artifacts: run ${run_id}"
  mkdir -p downloads/native downloads/phpunit
  gh run download "${run_id}" --repo "${repo}" --name octocov-report --dir downloads/native
  gh run download "${run_id}" --repo "${repo}" --name "${artifact}" --dir downloads/phpunit

  test -s downloads/native/report.json
  test -s downloads/phpunit/clover.xml
  test -s downloads/phpunit/html-coverage/index.html
  python3 scripts/coverage_source.py verify downloads/native/report.json "${source_sha}"

  cp -a downloads/phpunit/html-coverage/. site/libresign/
  cp downloads/phpunit/clover.xml site/libresign/clover.xml
  python3 - "${source_sha}" "${run_id}" <<'PYTHON'
import json
import sys
from pathlib import Path

sha, run_id = sys.argv[1:]
Path("downloads/metadata.json").write_text(json.dumps({
    "repository": "LibreSign/libresign",
    "source_branch": "main",
    "source_commit_sha": sha,
    "source_run_id": int(run_id),
    "preview": False,
}, indent=2) + "\n", encoding="utf-8")
PYTHON
  echo "available=true" >> "${GITHUB_OUTPUT}"
  echo "source_run_id=${run_id}" >> "${GITHUB_OUTPUT}"
  exit 0
done

echo "No completed native coverage report on LibreSign/libresign main yet."
echo "available=false" >> "${GITHUB_OUTPUT}"
