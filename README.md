<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# LibreSign Coverage

The source code for **this publishing website** is in this repository:
[the GitHub Actions publisher](.github/workflows/pages.yml),
[Octocov configuration](.octocov.yml), and the
[theme/navigation layer](site-theme/). The site at
[libresign.github.io/coverage](https://libresign.github.io/coverage/)
contains **generated HTML**, not a checkout of this repository. PHP coverage
refers to the [LibreSign application source](https://github.com/LibreSign/libresign).

**License:** GNU Affero General Public License v3 or later (AGPL-3.0-or-later),
see [LICENSE](LICENSE). Octocov and PHPUnit are external tools and retain their
own upstream licenses.

Static code coverage reports for repositories owned by [LibreSign](https://github.com/LibreSign).

The public site is **https://libresign.github.io/coverage/** (temporary GitHub Pages URL). The project uses upstream [octocov](https://github.com/k1LoW/octocov) central mode to render the repository index and badges, and the existing PHPUnit HTML report to display line-by-line coverage.

## Data flow

1. `LibreSign/libresign` runs PHPUnit **once**, generating Clover and HTML.
2. The source CI uploads the PHPUnit reports as GitHub Actions artifacts and
   publishes a native Octocov artifact (`artifact://LibreSign/libresign`).
3. This repository reads the public native artifact **directly** using the
   `GITHUB_TOKEN` of the Pages workflow, verified against the upstream
   implementation. It downloads the HTML and Clover from the **same successful
   main run** and checks that the native Octocov JSON records the same SHA.
4. Octocov Central Mode generates repository badges and metrics, and GitHub
   Pages publishes the unchanged PHPUnit HTML details and the shared theme.
5. A separate GitHub Release archive stores compressed native Octocov metrics
   and Clover reports by SHA, independently of GitHub Actions artifact expiry.

The GitHub Actions workflow selects `main` reports only; it never presents
coverage from a pull request as a production baseline. While the source
[LibreSign PR #9387](https://github.com/LibreSign/libresign/pull/9387) awaits
its required maintainer review, a clearly labeled homologation preview is
loaded from `coverage-latest`. This **fallback is not the permanent transport
or the source of truth**: when a successful source main run produces native
artifacts, the site automatically switches to them.

The archive is not an Octocov history dashboard. Native Octocov Central Mode
displays the latest report for each repository, not a historical trend of every
commit. The distinct [Coveralls historical export](https://github.com/LibreSign/coverage/releases/tag/coveralls-archive-2026-11-07)
contains 3,735 historic build **metadata** records and a single per-file
summary export, not line-by-line historic reports. Historic reports are never
fabricated.

## Preservation and retention

GitHub Actions artifacts for public repositories expire after at most 90 days,
so they are **operational data**, not the permanent archive. Source CI preserves
compressed Clover/Octocov JSON in a SHA-addressed monthly release separately.
The latest full HTML report is published in Pages; a synthetic history of full
HTML pages is intentionally not stored.

The repository's `github://` Octocov datastore is deliberately not used:
Octocov's native Git commits do not satisfy the LibreSign signed-commit and
DCO rules. The archive remains off the main Git branch, and Octocov
`central.push` stays disabled.

## Repository organization

The root Pages index lists LibreSign repositories with published data. Each repository's detailed HTML resides under its short name, e.g. `/libresign/`. Projects in other GitHub organizations do not belong in this repository.

The current source reporter is `LibreSign/libresign/.github/workflows/phpunit-sqlite.yml`. Add more LibreSign repositories by publishing native Octocov artifacts and suitable HTML reports; do not duplicate the coverage parser or create a standalone coverage service.

## Maintenance and security

- The Pages job uses its normal `GITHUB_TOKEN` to read **public** source Actions artifacts; no additional GitHub App Actions permissions are required.
- The source CI uses a narrowly scoped GitHub App with `contents:write` only to preserve historical release assets and trigger Pages refreshes, not to read coverage.
- Keep `central.push` disabled; all source commits require DCO and signed commits.
- Coveralls remains active during validation; do not remove it before comparing coverage and replacing historic reports/badges.
- The custom DNS name is deliberately not configured yet.

## Theme and source navigation

The static site has an accessible light/dark theme toggle, initially following
the operating system setting and remembering an explicit preference for
subsequent pages. The source links lead to this publisher and the LibreSign
application; PHP source-line pages also link to the matching source file at
the **exact commit** recorded in the coverage snapshot.

This is a small navigation layer over the original Octocov and PHPUnit
outputs. The upstream coverage data and rendered line-level reports remain
unchanged in their source artifacts; the theme is inserted only into the
published copies. Run the local checks with
`python3 -m unittest discover -s tests -v`.

## Coverage trend chart

The homepage can display historical **total coverage percentage** with source
automatic source transition, time-range filtering, build provenance and an accessible data table.
Its legacy Coveralls series is rebuilt from the separately archived 2021–2026
export, considering only the `main` branch and one observation per SHA.
Periods without fresh observations are **not assigned fabricated coverage
values**. The chart shows the last known value as a horizontal **dashed** line,
with hover text that explicitly identifies the date and commit of the previous
measurement. This distinguishes "last observed" from "measured on this date."
A new measurement changes the value only on its actual timestamp. Significant
scope changes (>30%) also use dashed segments rather than implying comparable
intermediate measurements. The dotted bridge between levels is a visual
transition, not an interpolated data point.

New native Octocov data from actual successful `main` executions are drawn
automatically after the last eligible historical period. The visible UI does
not require a source selector. A method change is **not connected** with a
solid or vertical transition, because Coveralls and Octocov denominators differ.
Each measurement links to its specific report provider and run.
Archival metadata must contain the source SHA, run ID, UTC collection timestamp,
and total/covered Octocov counters. The preview PR coverage is **not** treated
as a production baseline.

The chart is a presentation layer, not a coverage parser. Upstream Octocov and
PHPUnit remain responsible for computing coverage; no additional PHPUnit run
or Git commit of generated HTML is required. The chart's static JSON is
regenerated at Pages build time from durable release data.
