<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# LibreSign Coverage

Static public coverage reports for repositories owned by [LibreSign](https://github.com/LibreSign).

This repository uses **[octocov](https://github.com/k1LoW/octocov) central mode** for the repository list and badges. It does not implement its own coverage engine or viewer. The original LibreSign PHPUnit job generates a native HTML line-by-line report and Clover XML, both reused without rerunning tests.

## Pages

Once Pages is enabled, reports will be available at **https://libresign.github.io/coverage/**:

- `/`: repositories and current octocov metrics/badges.
- `/libresign/`: the existing PHPUnit HTML report for the latest successful main CI run.
- `/libresign/clover.xml`: Clover source report for that selected run.

The [Coveralls historical export](https://github.com/LibreSign/coverage/releases/tag/coveralls-archive-2026-11-07) preserves 3,735 public build metadata records (starting in May 2021) and per-file summaries for one build. **It does not contain line-by-line HTML for every historical build.** The Octocov timeline starts with newly collected data; no synthetic history is created.

## Architecture

1. `LibreSign/libresign` runs the existing SQLite PHPUnit coverage job and uploads Clover/HTML as a GitHub Actions artifact.
2. The same source CI run executes the upstream `k1LoW/octocov-action` once to preserve an octocov report in its native artifact format.
3. This repository's Pages workflow reads public source artifacts via a scoped GitHub App token provided through organization secrets.
4. The Octocov central mode creates the project index and badges; the PHPUnit HTML is copied unchanged into the static site.
5. GitHub Pages is deployed using the official Pages Actions. No generated HTML or metrics are committed by CI.

The workflow uses a single reference matrix artifact. With multiple future PHP/Nextcloud variants, it deterministically prefers Nextcloud master and the latest available PHP version. It must not aggregate identical coverage from multiple runtimes as if they were independent tests.

## Maintenance

- Data is public. Only `LibreSign/*` repositories should be added to the central configuration.
- Changes must follow DCO and signed-commit rules.
- Octocov central `push` mode remains disabled to avoid unsigned automated commits.
- Coveralls remains in place until independent reports, history, badges and publishing have been verified.
