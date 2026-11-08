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
if [[ -n "${COVERAGE_SOURCE_RUN_ID:-}" ]]; then
  # A repository_dispatch event is a hint, never proof of a successful run.
  # Resolve its exact identity from the GitHub API to preserve every merged PR
  # even when a newer main run has already completed.
  [[ "${COVERAGE_SOURCE_REPOSITORY:-}" == "${repo}" ]] || {
    echo '::error::Unexpected dispatched source repository'
    exit 1
  }
  [[ "${COVERAGE_SOURCE_RUN_ID}" =~ ^[1-9][0-9]*$ && "${COVERAGE_SOURCE_SHA:-}" =~ ^[0-9a-f]{40}$ ]] || {
    echo '::error::Invalid dispatched run ID or commit SHA'
    exit 1
  }
  gh api "repos/${repo}/actions/runs/${COVERAGE_SOURCE_RUN_ID}" > downloads/requested-run.json
  jq -e --arg repository "${repo}" --arg sha "${COVERAGE_SOURCE_SHA}" \
    --argjson run_id "${COVERAGE_SOURCE_RUN_ID}" '
      .id == $run_id and .head_sha == $sha and
      .head_repository.full_name == $repository and
      .head_branch == "main" and .event == "push" and
      .status == "completed" and .conclusion == "success" and
      .path == ".github/workflows/phpunit-sqlite.yml" and
      (.run_attempt | type == "number" and . >= 1)
    ' downloads/requested-run.json >/dev/null || {
      echo '::error::Dispatched run is not a verified successful main PHPUnit run'
      exit 1
    }
  run_attempt="$(jq -r '.run_attempt' downloads/requested-run.json)"
  runs=("${COVERAGE_SOURCE_RUN_ID} ${COVERAGE_SOURCE_SHA} ${run_attempt}")
else
  mapfile -t runs < <(
    gh api "repos/${repo}/actions/workflows/phpunit-sqlite.yml/runs?branch=main&status=success&per_page=30" \
      --jq '.workflow_runs[] | select(.event == "push") | "\(.id) \(.head_sha) \(.run_attempt)"'
  )
fi

for run in "${runs[@]}"; do
  read -r run_id source_sha run_attempt <<< "${run}"
  [[ "${run_id}" =~ ^[1-9][0-9]*$ && "${source_sha}" =~ ^[0-9a-f]{40}$ && "${run_attempt}" =~ ^[1-9][0-9]*$ ]] || exit 1
  gh api "repos/${repo}/actions/runs/${run_id}/artifacts" > downloads/artifacts.json
  artifact="$(python3 scripts/coverage_source.py select downloads/artifacts.json)"
  if [[ -z "${artifact}" ]]; then
    if [[ -n "${COVERAGE_SOURCE_RUN_ID:-}" ]]; then
      echo '::error::The dispatched main run has no complete native coverage artifacts'
      exit 1
    fi
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
  python3 scripts/coverage_source.py metadata downloads/native/report.json \
    "${source_sha}" "${run_id}" "${run_attempt}" downloads/metadata.json
  echo "available=true" >> "${GITHUB_OUTPUT}"
  echo "source_run_id=${run_id}" >> "${GITHUB_OUTPUT}"
  exit 0
done

echo "No completed native coverage report on LibreSign/libresign main yet."
echo "available=false" >> "${GITHUB_OUTPUT}"
