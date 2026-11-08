# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Select one complete PHPUnit matrix artifact and verify its octocov report."""

import argparse
import json
import re
from pathlib import Path

SOURCE_REPOSITORY = "LibreSign/libresign"
ARTIFACT_NAME = re.compile(
    r"^phpunit-coverage-php-([0-9]+(?:\.[0-9]+)*)-nextcloud-(master|[A-Za-z0-9._-]+)$"
)
COMMIT = re.compile(r"^[0-9a-f]{40}$")


def choose_reference(artifacts: list[dict]) -> str | None:
    """Prefer Nextcloud master at the highest PHP version, as the source CI does."""
    candidates = []
    for artifact in artifacts:
        if artifact.get("expired"):
            continue
        name = artifact.get("name", "")
        match = ARTIFACT_NAME.fullmatch(name)
        if match:
            version = tuple(int(n) for n in match.group(1).split("."))
            candidates.append((match.group(2) == "master", version, name))
    return max(candidates)[2] if candidates else None


def complete_source(artifacts: list[dict]) -> str | None:
    names = {a.get("name") for a in artifacts if not a.get("expired")}
    if "octocov-report" not in names:
        return None
    return choose_reference(artifacts)


def validate_report(report: dict, commit_sha: str) -> None:
    if not COMMIT.fullmatch(commit_sha):
        raise ValueError("invalid source SHA")
    if report.get("repository") != SOURCE_REPOSITORY:
        raise ValueError("unexpected Octocov repository")
    if report.get("ref") not in ("main", "refs/heads/main"):
        raise ValueError("unexpected Octocov ref")
    if report.get("commit") != commit_sha:
        raise ValueError("Octocov commit differs from the source report run")
    coverage = report.get("coverage")
    if not isinstance(coverage, dict) or coverage.get("total", 0) <= 0:
        raise ValueError("missing Octocov coverage metrics")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    selection = sub.add_parser("select")
    selection.add_argument("artifacts_json", type=Path)
    check = sub.add_parser("verify")
    check.add_argument("report_json", type=Path)
    check.add_argument("source_sha")
    args = parser.parse_args()
    if args.command == "select":
        data = json.loads(args.artifacts_json.read_text())
        name = complete_source(data.get("artifacts", []))
        if name:
            print(name)
    elif args.command == "verify":
        validate_report(json.loads(args.report_json.read_text()), args.source_sha)


if __name__ == "__main__":
    main()
