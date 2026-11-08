<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# LibreSign Coverage

**Better tests. More reliable document-signing workflows.**

[Explore coverage](https://coverage.libresign.coop/) ·
[Contribute to LibreSign](https://github.com/LibreSign/libresign) ·
[Find a good first issue](https://github.com/LibreSign/libresign/labels/good%20first%20issue)

[LibreSign](https://github.com/LibreSign/libresign) is an open-source document-signing application built for Nextcloud. Its signing workflows support organizations that need control, traceability, and dependable processes.

**LibreSign Coverage** makes test coverage visible so developers can find gaps, investigate risks, and strengthen the application. The goal is **not to maximize a percentage**. It is to build useful tests that catch regressions, clarify expected behavior, and make changes safer to review.

## Coverage is a starting point, not a quality score

A line executed by a test is not necessarily a line **correctly tested**. Even high coverage can miss broken business rules, weak assertions, permission mistakes, and failure paths.

Good contributions prioritize:

- **Observable behavior:** verify what users and integrations should experience, not incidental implementation details.
- **Meaningful assertions:** make the test fail when the expected behavior is broken.
- **Risk and edge cases:** cover authorization, signing order, invalid input, failures, and regressions where relevant.
- **Maintainability:** keep tests focused, deterministic, readable, and appropriate to their test level.
- **Evidence:** explain the defect or behavior being protected, not just the change in coverage percentage.

Use coverage to decide **where to investigate**, then use engineering judgment to decide **what deserves a test**.

## Help improve LibreSign

The PHP application and its tests live in **[LibreSign/libresign](https://github.com/LibreSign/libresign)**, not in this dashboard repository.

1. **Find a meaningful task.** Explore [open issues](https://github.com/LibreSign/libresign/issues), [good first issues](https://github.com/LibreSign/libresign/labels/good%20first%20issue), or a behavior that lacks regression tests.
2. **Investigate the evidence.** Use the [coverage dashboard](https://coverage.libresign.coop/) to explore directories, files, and uncovered lines. Low coverage is a clue, not proof of a defect.
3. **Write a focused test or fix.** Follow the [LibreSign contributing guide](https://github.com/LibreSign/libresign/blob/main/CONTRIBUTING.md) and [developer testing manual](https://docs.libresign.coop/developer_manual/getting-started/tests.html).
4. **Open a pull request in LibreSign.** Explain the behavior, risk, tests run, and why the assertions matter. A higher coverage number alone is not a sufficient rationale.

You can contribute through bug reproduction, test cases, documentation, review, or code. New to the project? Start with the [LibreSign development guide](https://docs.libresign.coop/developer_manual/).

## What the dashboard shows

- **Latest report:** instrumented lines covered and uncovered, file counts, and links to the source commit and CI run. A report labeled **Preview** is *not* an official `main` baseline.
- **Explore coverage:** search files, filter directories, and find candidates for investigation. Color bands help scan results; they are **not** quality gates.
- **Historical trend:** inspect recorded measurements and open their underlying reports. Older Coveralls data and newer Octocov data are identified separately because their methods may differ.
- **Detailed reports:** follow links to the PHPUnit source-line coverage produced by the LibreSign project.

For interpretation, limitations, and historical provenance, see [Understanding coverage data](docs/understanding-coverage.md).

## Improve this website

This repository publishes the dashboard at **[coverage.libresign.coop](https://coverage.libresign.coop/)**. Contributions here should improve the **accuracy, accessibility, usability, and reliability of coverage reporting**—not change LibreSign's PHP tests.

[Report a dashboard issue](https://github.com/LibreSign/coverage/issues) or propose a change in this repository. Run the existing checks before submitting:

```bash
python3 -m unittest discover -s tests -v
```

For publishing workflows, data integrity, and the repository layout, see the [Maintainer guide](docs/maintainers.md).

## For organizations using LibreSign

Quality-focused tests help teams detect regressions in workflows they rely on. Organizations can contribute reproducible problems, sponsor test improvements, and fund ongoing development.

[Support LibreSign](https://github.com/sponsors/LibreSign) · [Project documentation](https://docs.libresign.coop/)

---

Licensed under [AGPL-3.0-or-later](LICENSE).
