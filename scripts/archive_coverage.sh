#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

# The publishing repository owns release archiving; the PHP project only
# uploads Actions artifacts. Never archive PR/previews or mutate Git history.
set -euo pipefail
: "${GH_TOKEN:?Repository token with contents:write required}"
: "${GITHUB_REPOSITORY:?Publishing repository required}"
[[ "${GITHUB_REPOSITORY}" == LibreSign/coverage ]] || {
  echo '::error::Coverage archive must run in LibreSign/coverage'
  exit 1
}

test -s downloads/native/report.json
test -s downloads/phpunit/clover.xml
test -s downloads/metadata.json
test -s site/libresign/index.html
test -s site/libresign/dashboard.html

source_sha="$(jq -r '.source_commit_sha' downloads/metadata.json)"
source_run_id="$(jq -r '.source_run_id' downloads/metadata.json)"
source_run_attempt="$(jq -r '.source_run_attempt' downloads/metadata.json)"
[[ "${source_sha}" =~ ^[0-9a-f]{40}$ && "${source_run_id}" =~ ^[1-9][0-9]*$ && "${source_run_attempt}" =~ ^[1-9][0-9]*$ ]] || {
  echo '::error::Invalid main-run identity'
  exit 1
}

snapshot=coverage-snapshot
mkdir -p "${snapshot}"
# Recompute metadata from the actual native report, and require exactly the
# snapshot already validated during download. Do not trust dispatch payloads.
python3 scripts/coverage_source.py metadata downloads/native/report.json \
  "${source_sha}" "${source_run_id}" "${source_run_attempt}" "${snapshot}/metadata.json"
cmp -s "${snapshot}/metadata.json" downloads/metadata.json || {
  echo '::error::Coverage report metadata changed between preparation and archive'
  exit 1
}
tar -C site/libresign -czf "${snapshot}/libresign-html.tar.gz" .
gzip -n -9 < downloads/phpunit/clover.xml > "${snapshot}/libresign-clover.xml.gz"
gzip -n -9 < downloads/native/report.json > "${snapshot}/libresign-octocov.json.gz"
(
  cd "${snapshot}"
  sha256sum libresign-html.tar.gz libresign-clover.xml.gz libresign-octocov.json.gz metadata.json > checksums.sha256
  sha256sum -c checksums.sha256
)

archive_tag="coverage-history-$(date -u +%Y-%m)"
archive_key="${source_sha}-run-${source_run_id}-attempt-${source_run_attempt}"
mkdir -p coverage-history
cp "${snapshot}/libresign-octocov.json.gz" "coverage-history/libresign-${archive_key}-octocov.json.gz"
cp "${snapshot}/libresign-clover.xml.gz" "coverage-history/libresign-${archive_key}-clover.xml.gz"
cp "${snapshot}/metadata.json" "coverage-history/libresign-${archive_key}-metadata.json"
(
  cd coverage-history
  sha256sum "libresign-${archive_key}-octocov.json.gz" \
    "libresign-${archive_key}-clover.xml.gz" \
    "libresign-${archive_key}-metadata.json" > "libresign-${archive_key}-checksums.sha256"
)

if ! gh release view "${archive_tag}" --repo "${GITHUB_REPOSITORY}" >/dev/null 2>&1; then
  gh release create "${archive_tag}" --repo "${GITHUB_REPOSITORY}" --target main \
    --title "LibreSign coverage archive ${archive_tag#coverage-history-}" \
    --notes 'Immutable-by-policy, per-run Octocov and Clover measurements. HTML remains available from the latest publication.' \
      || gh release view "${archive_tag}" --repo "${GITHUB_REPOSITORY}" >/dev/null
fi

# Fetch all release assets with pagination: a busy month easily exceeds
# the first API page. Cache once rather than doing a remote lookup per asset.
release_id="$(gh api "repos/${GITHUB_REPOSITORY}/releases/tags/${archive_tag}" --jq '.id')"
[[ "${release_id}" =~ ^[1-9][0-9]*$ ]] || exit 1
existing_assets="$(mktemp)"
trap 'rm -f "${existing_assets}"' EXIT
gh api --paginate "repos/${GITHUB_REPOSITORY}/releases/${release_id}/assets?per_page=100" \
  --jq '.[] | [.name, (.digest // "")] | @tsv' > "${existing_assets}"

# Never overwrite an archived measurement; distinct run attempts have
# distinct SHA-addressed filenames, and all existing digests must match.
for file in coverage-history/*; do
  name="${file##*/}"
  expected="sha256:$(sha256sum "${file}" | cut -d' ' -f1)"
  if awk -F '\t' -v n="${name}" '$1==n {found=1} END {exit !found}' "${existing_assets}"; then
    actual="$(awk -F '\t' -v n="${name}" '$1==n {print $2}' "${existing_assets}")"
    if [[ "${actual}" != "${expected}" ]]; then
      echo "::error::Conflicting archived coverage asset: ${name}"
      exit 1
    fi
    continue
  fi
  gh release upload "${archive_tag}" "${file}" --repo "${GITHUB_REPOSITORY}"
done

# Full HTML fallback is intentionally mutable and separate from the durable
# metrics archive. Never dispatch another Pages run from within this job.
if [[ "${ARCHIVE_CURRENT_FALLBACK:-true}" != 'false' ]]; then
  gh release upload coverage-latest "${snapshot}"/* --repo "${GITHUB_REPOSITORY}" --clobber
  gh release edit coverage-latest --repo "${GITHUB_REPOSITORY}" \
    --title 'Latest verified LibreSign coverage snapshot' \
    --notes "Fallback HTML from LibreSign/libresign main commit ${source_sha}, run ${source_run_id}."
fi
