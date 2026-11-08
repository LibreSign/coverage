<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Agent instructions — LibreSign Coverage

This repository publishes [coverage.libresign.coop](https://coverage.libresign.coop/)
to help improve LibreSign's tests and reliability, **not** just its coverage
percentage. Read [README.md](README.md) for the contributor journey,
[docs/maintainers.md](docs/maintainers.md) for publishing details, and
[docs/understanding-coverage.md](docs/understanding-coverage.md) before
interpreting measurements.

## Keep responsibilities separate

- [LibreSign/libresign](https://github.com/LibreSign/libresign) owns the PHP
  application, PHPUnit tests, and Clover/HTML coverage generation.
- This repository owns report verification, archives, dashboards, and Pages.
  Do not duplicate PHP test runs or move archiving into the application.
- Keep the README short and contributor-focused; put operational details in
  `docs/`. Do not repeat the repository's license information in the README.

## Protect coverage integrity

- Only verified, successful `main` runs may become official baseline data.
  Never present a PR preview as the `main` baseline.
- Verify repository, workflow, commit SHA, run identity, and matching artifacts
  before accepting reports; a dispatch payload alone is not trusted evidence.
- Never fabricate historical measurements, mix Coveralls and Octocov series
  without distinguishing their methods, or overwrite conflicting archives.
- Do not commit generated reports to the source tree. Preserve source links
  to the exact report and commit.

## Preserve the user experience

- Keep backend reporting, archive validation, and tests in PHP. The
  JavaScript browser interface and upstream Jekyll/Octocov actions remain separate.
  Do not add a second implementation in another language.
- Reuse existing scripts and keep Octocov/PHPUnit measurements unchanged.
- Keep the homepage responsive, accessible, keyboard-usable, and compatible
  with light/dark themes. Do not regress detailed PHPUnit report pages.
- Treat coverage as a diagnostic signal; prefer meaningful assertions and
  regression protection over superficial percentage increases.

## Validate changes

- PHP 8.3+: `composer install && composer test`.
- Modified PHP: `php -l <file>`.
- Modified JavaScript: `node --check <file>`.
- Keep GitHub Actions shell snippets limited to simple orchestration.
- Modified GitHub Actions: `actionlint .github/workflows/*.yml`
- For UI changes, inspect mobile, laptop, wide desktop, and browser zoom.
- All commits require DCO and cryptographic signatures. Do not bypass
  required reviews or merge without explicit authorization.
