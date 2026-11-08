# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Select one complete PHPUnit matrix artifact and verify its octocov report."""

import argparse
import json
import re
from datetime import datetime, timezone
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
    if not isinstance(coverage, dict):
        raise ValueError("missing Octocov coverage metrics")
    covered, total = coverage.get("covered"), coverage.get("total")
    if type(covered) is not int or type(total) is not int or total <= 0 or not 0 <= covered <= total:
        raise ValueError("invalid Octocov coverage metrics")


def metadata_for(report: dict, commit_sha: str, run_id: int, run_attempt: int) -> dict:
    """Extract official main-run provenance without substituting PR observations."""
    validate_report(report, commit_sha)
    if type(run_id) is not int or run_id <= 0 or type(run_attempt) is not int or run_attempt <= 0:
        raise ValueError("invalid GitHub Actions run identity")
    stamp = report.get("timestamp")
    if not isinstance(stamp, str):
        raise ValueError("missing native report timestamp")
    measured = datetime.fromisoformat(stamp.replace("Z", "+00:00"))
    if measured.utcoffset() is None:
        raise ValueError("native report timestamp must be timezone-aware")
    return {
        "repository": SOURCE_REPOSITORY,
        "source_branch": "main",
        "source_commit_sha": commit_sha,
        "source_run_id": run_id,
        "source_run_attempt": run_attempt,
        "recorded_at": measured.astimezone(timezone.utc).isoformat(timespec="seconds").replace("+00:00", "Z"),
        "covered": report["coverage"]["covered"],
        "total": report["coverage"]["total"],
        "preview": False,
    }


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    selection = sub.add_parser("select")
    selection.add_argument("artifacts_json", type=Path)
    check = sub.add_parser("verify")
    check.add_argument("report_json", type=Path)
    check.add_argument("source_sha")
    metadata = sub.add_parser("metadata")
    metadata.add_argument("report_json", type=Path)
    metadata.add_argument("source_sha")
    metadata.add_argument("source_run_id", type=int)
    metadata.add_argument("source_run_attempt", type=int)
    metadata.add_argument("output_path", type=Path)
    args = parser.parse_args()
    if args.command == "select":
        data = json.loads(args.artifacts_json.read_text())
        name = complete_source(data.get("artifacts", []))
        if name:
            print(name)
    elif args.command == "verify":
        validate_report(json.loads(args.report_json.read_text()), args.source_sha)
    elif args.command == "metadata":
        report = json.loads(args.report_json.read_text(encoding="utf-8"))
        result = metadata_for(report, args.source_sha, args.source_run_id, args.source_run_attempt)
        args.output_path.write_text(json.dumps(result, indent=2) + "\n", encoding="utf-8")


if __name__ == "__main__":
    main()
