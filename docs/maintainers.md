<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# LibreSign Coverage maintainer guide

[Back to README](../README.md) · [Interpreting measurements](understanding-coverage.md)

This document covers the **publisher**. LibreSign application development and PHP tests belong in [LibreSign/libresign](https://github.com/LibreSign/libresign).

## Ownership and data flow

| Component | Responsibility |
| --- | --- |
| [LibreSign/libresign](https://github.com/LibreSign/libresign) | Runs PHPUnit, produces Clover and HTML once, and uploads GitHub Actions artifacts |
| [Reusable Octocov collector](../.github/workflows/source-octocov.yml) | Uses the source workflow's PHPUnit artifacts to create an Octocov report and PR comparison; does not rerun PHPUnit |
| [LibreSign source notifier (PR #9387)](https://github.com/LibreSign/libresign/pull/9387) | After a successful full `main` workflow, requests central publication with the source SHA and run ID |
| [Coverage Pages workflow](../.github/workflows/pages.yml) | Verifies the source, archives metrics, builds the site, and deploys GitHub Pages |

The source integration is tracked in [LibreSign/libresign#9387](https://github.com/LibreSign/libresign/pull/9387). The source notifier is operational only once that workflow is present on the application's `main` branch. Until a verified main-branch result is available, the dashboard may display a clearly labeled preview.

The site is **not** a second test runner. It must not commit generated PHPUnit HTML or invent coverage measurements. The original Octocov index and PHPUnit line-by-line reports remain the source of the published presentation.

## Verification and provenance

The publisher must accept only artifacts from a completed, successful `main` source workflow. It verifies the repository, workflow identity, branch, event, source SHA, run ID/attempt, and matching Octocov and PHPUnit data.

A `repository_dispatch` event is a **hint**, not proof: the receiver verifies its payload against GitHub Actions. Requests arriving out of order must not move the live dashboard backward; the exact valid run may still be preserved in the archive.

Key implementation files:

- [`scripts/prepare_native_coverage.sh`](../scripts/prepare_native_coverage.sh): selects and validates native source artifacts.
- [`scripts/coverage_source.py`](../scripts/coverage_source.py): verifies report provenance and metadata.
- [`scripts/archive_coverage.sh`](../scripts/archive_coverage.sh): preserves verified measurements and checksums.
- [`scripts/build_coverage_summary.py`](../scripts/build_coverage_summary.py): derives directory and file summaries from verified data.

## Retention and history

GitHub Actions artifacts expire, so they are not the permanent archive. This repository stores **compressed Octocov and Clover measurements** in its own GitHub Releases, addressed by source commit, run, and attempt. Existing archived assets must not be silently overwritten with different content. The newest detailed HTML report is published through Pages; a fabricated history of full HTML reports is not maintained.

The older [Coveralls archive](https://github.com/LibreSign/coverage/releases/tag/coveralls-archive-2026-11-07) is historical evidence, not a live reporting dependency. Keep it separate from native Octocov measurements and do not interpolate data across a method change. See [Understanding coverage data](understanding-coverage.md) for chart semantics.

Octocov's `github://` commit-backed storage and `central.push` are not used: generated Git commits would conflict with the repository's signed-commit and DCO requirements.

## Publishing and permissions

- Canonical site: **https://coverage.libresign.coop/**.
- Cloudflare CNAME: `coverage.libresign.coop` → `libresign.github.io`; the domain is registered under [GitHub Pages settings](https://github.com/LibreSign/coverage/settings/pages).
- Jekyll uses that domain as `url` with an empty `baseurl`; Pages serves the site from `/`, not `/coverage/`.
- The publisher's `GITHUB_TOKEN` reads public source artifacts and has narrowly scoped write access to this repository's release archive and Pages deployment.
- The LibreSign notifier uses a GitHub App token scoped to this repository to request dispatch. Do not embed credentials, accept unverified dispatch fields, or give the application repository permission to manage the archive.
- All repository commits require DCO and cryptographic signatures.

## Website layout and source links

- [`scripts/prepare_dashboard.py`](../scripts/prepare_dashboard.py) builds the homepage summary and explorer.
- [`scripts/prepare_trend.py`](../scripts/prepare_trend.py) and [`scripts/coverage_trend.py`](../scripts/coverage_trend.py) build the history presentation and data.
- [`scripts/prepare_site.py`](../scripts/prepare_site.py) adds the shared theme to the published reports.
- [`site-theme/`](../site-theme/) contains the browser assets. Changes to the homepage must not unexpectedly change the PHPUnit report layout.

Source-line links must use the verified commit SHA. The publisher copies/references the report content instead of recomputing PHP line coverage. Site assets use content-hashed URLs to prevent stale scripts after deployments.

## Validation before a PR

The [PR validation workflow](../.github/workflows/validate.yml) checks scripts and static assets without requiring release-writing permissions. Local equivalent checks:

```bash
python3 -m unittest discover -s tests -v
node --check site-theme/theme.js
node --check site-theme/overview.js
node --check site-theme/trend.js
bash -n scripts/build_coverage_history.sh scripts/prepare_native_coverage.sh scripts/archive_coverage.sh
```

For changes to layouts, interactions, or theme, also check the actual site in a browser at mobile, laptop, and wide-screen widths. Validate that color, labels, keyboard navigation, and source URLs remain usable.

## Diagnosing a failed publication

1. Check the [Pages workflow](https://github.com/LibreSign/coverage/actions/workflows/pages.yml) and its test, artifact selection, archive, Jekyll build, and deploy steps.
2. Check the corresponding [LibreSign PHPUnit workflow](https://github.com/LibreSign/libresign/actions/workflows/phpunit-sqlite.yml) and whether its source run completed successfully with Octocov and PHPUnit artifacts.
3. Compare the source commit, run ID, branch, and metadata before investigating missing HTML or charts.
4. Keep an unverified report labeled as a preview. Never publish PR measurements as the `main` baseline to make a build succeed.
