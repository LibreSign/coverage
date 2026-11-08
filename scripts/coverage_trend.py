# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Build honest, separated time series from stored coverage metrics."""

import argparse
import gzip
import json
import math
import re
from datetime import datetime
from pathlib import Path

SHA = re.compile(r"^[0-9a-f]{40}$")
REPOSITORY = "LibreSign/libresign"


def timestamp(value: str) -> str:
    """Accept actual ISO UTC dates; fail rather than using a fabricated date."""
    stamp = datetime.fromisoformat(value.replace("Z", "+00:00"))
    if stamp.utcoffset() is None:
        raise ValueError("Timezone is required")
    return stamp.isoformat().replace("+00:00", "Z")


def legacy_series(builds: list[dict]) -> list[dict]:
    """Only main, with a valid percentage and auditable numerator/denominator."""
    by_commit: dict[str, dict] = {}
    for build in builds:
        if build.get("repo_name") != REPOSITORY or build.get("branch") != "main":
            continue
        sha = build.get("commit_sha")
        percent = build.get("covered_percent")
        covered, total = build.get("covered_lines"), build.get("relevant_lines")
        if not isinstance(sha, str) or not SHA.fullmatch(sha):
            continue
        if not isinstance(percent, (int, float)) or not math.isfinite(percent) or not 0 <= percent <= 100:
            continue
        if not isinstance(covered, int) or not isinstance(total, int) or total <= 0 or not 0 <= covered <= total:
            continue
        if abs(percent - covered * 100 / total) > 0.05:
            continue
        date = timestamp(build["calculated_at"])
        candidate = {"date": date, "pct": round(percent, 4), "covered": covered,
                     "total": total, "sha": sha, "run": build.get("id")}
        previous = by_commit.get(sha)
        if previous is None or candidate["date"] > previous["date"]:
            by_commit[sha] = candidate
    return sorted(by_commit.values(), key=lambda p: (p["date"], p["sha"]))


def native_series(metadata: list[dict]) -> list[dict]:
    """Metadata published by the future main-branch Octocov archival job."""
    by_commit: dict[str, dict] = {}
    for record in metadata:
        if record.get("repository") != REPOSITORY or record.get("source_branch") != "main":
            continue
        if record.get("preview") is not False:
            continue
        sha = record.get("source_commit_sha", "")
        if not isinstance(sha, str) or not SHA.fullmatch(sha):
            raise ValueError("Invalid source SHA in archived Octocov metadata")
        covered, total = record.get("covered"), record.get("total")
        if type(covered) is not int or type(total) is not int or total <= 0 or not 0 <= covered <= total:
            raise ValueError("Invalid Octocov coverage counters in archived metadata")
        run = record.get("source_run_id")
        if type(run) is not int or run <= 0:
            raise ValueError("Invalid source run id")
        date = timestamp(record["recorded_at"])
        candidate = {"date": date, "pct": round(covered * 100 / total, 4),
                     "covered": covered, "total": total, "sha": sha, "run": run}
        previous = by_commit.get(sha)
        if previous is None or candidate["date"] > previous["date"]:
            by_commit[sha] = candidate
    return sorted(by_commit.values(), key=lambda p: (p["date"], p["sha"]))


def build(legacy_archive: Path, native_metadata_dir: Path | None = None) -> dict:
    with gzip.open(legacy_archive, "rt", encoding="utf-8") as source:
        archived = json.load(source)
    if archived.get("report", {}).get("complete") is not True:
        raise ValueError("Legacy archive is incomplete")
    raw = archived.get("builds")
    if not isinstance(raw, list):
        raise ValueError("Legacy Coveralls archive is malformed")
    meta = []
    if native_metadata_dir and native_metadata_dir.exists():
        for path in sorted(native_metadata_dir.rglob("libresign-*-metadata.json")):
            meta.append(json.loads(path.read_text(encoding="utf-8")))
    return {
        "schema": 1,
        "repository": REPOSITORY,
        "series": {
            "legacy_coveralls": {
                "label": "Coveralls (histórico)",
                "metric": "covered lines / relevant lines",
                "points": legacy_series(raw),
            },
            "octocov_main": {
                "label": "Octocov (main)",
                "metric": "instrumented statements covered / total",
                "points": native_series(meta),
            },
        },
    }


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--legacy-archive", type=Path, required=True)
    p.add_argument("--native-metadata-dir", type=Path)
    p.add_argument("--output", type=Path, required=True)
    a = p.parse_args()
    data = build(a.legacy_archive, a.native_metadata_dir)
    a.output.parent.mkdir(parents=True, exist_ok=True)
    a.output.write_text(json.dumps(data, separators=(",", ":"), ensure_ascii=False), encoding="utf-8")
    print("Coverage history: legacy_main=", len(data["series"]["legacy_coveralls"]["points"]),
          "native_main=", len(data["series"]["octocov_main"]["points"]))


if __name__ == "__main__":
    main()
