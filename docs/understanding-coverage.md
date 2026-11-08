<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Understanding coverage data

[Back to LibreSign Coverage](../README.md) · [Open the dashboard](https://coverage.libresign.coop/)

The dashboard is **evidence to guide testing**, not a certificate of software correctness or a target that developers should optimize in isolation.

## What the numbers mean

- **Line coverage** measures how many instrumented source lines were executed by the collected tests. It does not prove that assertions are meaningful or that every relevant behavior was checked.
- **Uncovered lines** and **files analyzed** come from the same latest report. They point to areas worth investigating; they do not rank severity, security risk, or test quality.
- The latest report links to its source **commit** and **CI run**. Confirm that the run and branch are relevant before comparing changes.
- A **Preview** label indicates a PR snapshot or fallback, **not a verified main-branch baseline**. Do not use it to claim the project's current production coverage.
- A high percentage can coexist with missing negative tests, incorrect permissions, or regressions. Do not add shallow tests merely to improve the number.

For guidance on choosing and writing tests, see [LibreSign's testing manual](https://docs.libresign.coop/developer_manual/getting-started/tests.html).

## Reading the history correctly

The chart presents recorded **total coverage percentages**, not a complete history of every source line.

- The [historical Coveralls export](https://github.com/LibreSign/coverage/releases/tag/coveralls-archive-2026-11-07) includes build metadata and a per-file summary, **not individual historic line-by-line HTML reports**.
- Historical data is restricted to the source project's `main` branch, with duplicate observations for a commit deduplicated.
- New Octocov measurements are taken from verified, successful `main` runs. The chart does not join Coveralls and Octocov measurements into a continuous line: their coverage denominators and methods may differ.
- **Solid lines** connect measurements only when their methodology and scope are comparable.
- **Dashed segments** show a previously observed value carried forward during a period with no fresh measurement. They do **not** imply that a new test ran each day.
- Major changes in measured scope are shown as discontinuities rather than made to look like smooth progress.

Select a time range and click a point to open its source report. If many points overlap, use **View measurements** for individual dates and links.

## Explore coverage

The **Explore coverage** section groups file counters by directory, shows uncovered-line totals, and offers search, filtering, and sorting. Links lead to the generated PHPUnit source-line report when available, or to the matching file in the LibreSign repository at the recorded commit.

Progress bars use *descriptive* colors: **low** (below 50%), **moderate** (50% to below 80%), and **high** (80% or more). Percentages and counts remain visible; color is never the only indicator. These bands do **not** determine whether a change should be merged.

The most useful next question is not “How do we make this bar green?” but **“Which behavior could fail here without any test noticing?”**

## What good coverage improvements look like

When contributing to [LibreSign/libresign](https://github.com/LibreSign/libresign), prefer tests that:

1. Reproduce a real regression or express an observable business rule.
2. Exercise valid and invalid cases, including permission and error boundaries when relevant.
3. Make a clearly defined incorrect outcome fail.
4. Remain understandable, repeatable, and appropriate to the unit, integration, API, or end-to-end test level.

Treat higher coverage as supporting evidence **after** these criteria are met.
