# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Build a verified, small, read-only coverage explorer from native Octocov JSON.

The generated JSON carries no absolute build-machine paths. It never runs tests
or recomputes coverage; it aggregates already reported covered/total line counts.
"""

import argparse
import gzip
import json
import re
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import quote

COMMIT = re.compile(r"^[0-9a-f]{40}$")
REPOSITORY = "LibreSign/libresign"


def load_report(path: Path) -> dict:
    opener = gzip.open if path.suffix == ".gz" else open
    with opener(path, "rt", encoding="utf-8") as source:
        return json.load(source)


def make_summary(report: dict, metadata: dict, html_root: Path) -> dict:
    if report.get("repository") != REPOSITORY or metadata.get("repository") != REPOSITORY:
        raise ValueError("Coverage repository provenance mismatch")
    sha = metadata.get("source_commit_sha")
    if not isinstance(sha, str) or not COMMIT.fullmatch(sha) or report.get("commit") != sha:
        raise ValueError("Coverage report and run commit do not match")
    if type(metadata.get("preview")) is not bool:
        raise ValueError("Coverage preview status is required")
    branch = metadata.get("source_branch")
    if not isinstance(branch, str) or not branch:
        raise ValueError("Missing coverage source branch")
    if report.get("ref") not in (branch, "refs/heads/" + branch):
        raise ValueError("Coverage report and run branch do not match")
    if not metadata["preview"] and branch != "main":
        raise ValueError("Only main can be a production coverage baseline")
    run = metadata.get("source_run_id")
    if type(run) is not int or run <= 0:
        raise ValueError("Invalid source run ID")
    coverage = report.get("coverage")
    if not isinstance(coverage, dict) or coverage.get("type") != "loc":
        raise ValueError("The coverage explorer requires Octocov line coverage")
    covered, total = coverage.get("covered"), coverage.get("total")
    if (type(covered) is not int or type(total) is not int
            or total <= 0 or covered < 0 or covered > total):
        raise ValueError("Invalid coverage counters")

    stamp = report.get("timestamp")
    if not isinstance(stamp, str):
        raise ValueError("Missing coverage measurement timestamp")
    measured = datetime.fromisoformat(stamp.replace("Z", "+00:00"))
    if measured.utcoffset() is None:
        raise ValueError("Measurement timestamp must include timezone")
    date = measured.astimezone(timezone.utc).isoformat(timespec="seconds").replace("+00:00", "Z")

    raw_files = coverage.get("files")
    if not isinstance(raw_files, list) or not raw_files:
        raise ValueError("Missing per-file coverage data")
    files = []
    folders = {}
    seen = set()
    for source in raw_files:
        if not isinstance(source, dict):
            raise ValueError("Invalid coverage file entry")
        normalized = source.get("normalized_path")
        if not isinstance(normalized, str):
            raise ValueError("Missing normalized file path")
        parts = normalized.split("/")
        if (len(parts) < 2 or parts[0] != "lib"
                or any(not p or p in (".", "..") or any(ord(c) < 32 for c in p) for p in parts)
                or not normalized.endswith(".php") or "\\" in normalized):
            raise ValueError(f"Unsafe or unexpected normalized PHP path: {normalized!r}")
        if normalized in seen:
            raise ValueError(f"Duplicate coverage file: {normalized}")
        seen.add(normalized)
        n, c = source.get("total"), source.get("covered")
        if type(n) is not int or type(c) is not int or n < 0 or not 0 <= c <= n:
            raise ValueError(f"Invalid counters in {normalized}")

        rel = Path(*parts[1:])
        rendered = html_root / (str(rel) + ".html")
        quoted = "/".join(quote(x, safe="") for x in parts[1:])
        report_href = "libresign/" + quoted + ".html" if rendered.is_file() else None
        source_href = "https://github.com/LibreSign/libresign/blob/" + sha + "/" + "/".join(
            quote(x, safe="") for x in parts
        )
        folder = "/".join(parts[:2]) if len(parts) > 2 else "lib"
        files.append({
            "path": normalized,
            "directory": folder,
            "covered": c,
            "total": n,
            "uncovered": n - c,
            "percent": round(c * 100 / n, 2) if n else None,
            "report_url": report_href,
            "source_url": source_href,
        })
        bucket = folders.setdefault(folder, {"covered": 0, "total": 0, "files": 0})
        bucket["covered"] += c
        bucket["total"] += n
        bucket["files"] += 1

    if sum(f["total"] for f in files) != total or sum(f["covered"] for f in files) != covered:
        raise ValueError("Per-file coverage totals differ from the native report")

    directories = []
    for name, stats in folders.items():
        rel = name.split("/")[1:]
        index = html_root.joinpath(*rel, "index.html")
        directories.append({
            "name": name,
            **stats,
            "uncovered": stats["total"] - stats["covered"],
            "percent": round(stats["covered"] * 100 / stats["total"], 2) if stats["total"] else None,
            "report_url": "libresign/" + "/".join(quote(x, safe="") for x in rel)
                          + "/index.html" if index.is_file() else None,
        })
    ordering = lambda row: (-row["uncovered"], row["path"] if "path" in row else row["name"])
    return {
        "schema": 1,
        "repository": REPOSITORY,
        "commit": sha,
        "source_branch": branch,
        "run_id": run,
        "preview": metadata["preview"],
        "measured_at": date,
        "runtime": metadata.get("runtime") if isinstance(metadata.get("runtime"), str) else None,
        "covered": covered,
        "total": total,
        "uncovered": total - covered,
        "percent": round(covered * 100 / total, 2),
        "files_count": len(files),
        "directories": sorted(directories, key=ordering),
        "files": sorted(files, key=ordering),
    }


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--native-report", type=Path, default=Path("downloads/native/report.json"))
    parser.add_argument("--fallback-report", type=Path, default=Path("downloads/libresign-octocov.json.gz"))
    parser.add_argument("--metadata", type=Path, default=Path("downloads/metadata.json"))
    parser.add_argument("--html-root", type=Path, default=Path("site/libresign"))
    parser.add_argument("--output", type=Path, default=Path("site/assets/coverage-summary.json"))
    args = parser.parse_args()
    native = args.native_report.is_file()
    selected = args.native_report if native else args.fallback_report
    if not selected.is_file():
        raise FileNotFoundError("No source Octocov report available")
    metadata = json.loads(args.metadata.read_text(encoding="utf-8"))
    if native and metadata.get("preview") is not False:
        raise ValueError("Native artifact must have verified production metadata")
    summary = make_summary(load_report(selected), metadata, args.html_root)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(summary, separators=(",", ":"), ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"Coverage summary: {summary['percent']}%, {summary['files_count']} files, "
          f"{len(summary['directories'])} directories, preview={summary['preview']}")


if __name__ == "__main__":
    main()
