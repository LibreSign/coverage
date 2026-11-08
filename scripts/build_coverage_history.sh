#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

set -euo pipefail
: "${GH_TOKEN:?A GitHub token with public release read access is required}"
: "${GITHUB_REPOSITORY:?Expected LibreSign/coverage}"
[[ "${GITHUB_REPOSITORY}" == "LibreSign/coverage" ]] || exit 1

mkdir -p downloads/history/coveralls downloads/history/octocov site/assets
gh release download coveralls-archive-2026-11-07 --repo "${GITHUB_REPOSITORY}" --dir downloads/history/coveralls
(cd downloads/history/coveralls && sha256sum -c coveralls-archive.sha256)

# Once source PR #9387 starts archiving real main data, each per-run metadata
# asset includes just the counters/date needed for the graph. Do not fetch
# hundreds of full Clover/Octocov reports to render a small line chart.
mapfile -t tags < <(
  gh release list --repo "${GITHUB_REPOSITORY}" --limit 500 --json tagName \
    --jq '.[] | select(.tagName | startswith("coverage-history-")) | .tagName'
)
for tag in "${tags[@]}"; do
  [[ "${tag}" =~ ^coverage-history-[0-9]{4}-[0-9]{2}$ ]] || exit 1
  if gh release view "${tag}" --repo "${GITHUB_REPOSITORY}" --json assets \
       --jq '.assets[].name' | grep -Eq '^libresign-[0-9a-f]{40}-run-[0-9]+-attempt-[0-9]+-metadata\.json$'; then
    mkdir -p "downloads/history/octocov/${tag}"
    gh release download "${tag}" --repo "${GITHUB_REPOSITORY}" \
      --pattern 'libresign-*-metadata.json' \
      --dir "downloads/history/octocov/${tag}"
  fi
done

python3 scripts/coverage_trend.py \
  --legacy-archive downloads/history/coveralls/coveralls-history.json.gz \
  --native-metadata-dir downloads/history/octocov \
  --output site/assets/coverage-history.json
test -s site/assets/coverage-history.json
