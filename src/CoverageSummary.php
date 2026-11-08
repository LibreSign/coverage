<?php
// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Coverage;

use RuntimeException;

final class CoverageSummary
{
    public static function build(array $report, array $meta, string $htmlRoot): array
    {
        if (($report['repository'] ?? null) !== Support::SOURCE || ($meta['repository'] ?? null) !== Support::SOURCE) {
            throw new RuntimeException('Coverage repository provenance mismatch');
        }
        $sha = $meta['source_commit_sha'] ?? null;
        if (!Support::sha($sha) || ($report['commit'] ?? null) !== $sha) {
            throw new RuntimeException('Coverage report and run commit do not match');
        }
        if (!is_bool($meta['preview'] ?? null)) {
            throw new RuntimeException('Coverage preview status is required');
        }
        $branch = $meta['source_branch'] ?? null;
        if (!is_string($branch) || $branch === '' ||
            !in_array($report['ref'] ?? null, [$branch, 'refs/heads/' . $branch], true)) {
            throw new RuntimeException('Coverage report and run branch do not match');
        }
        if ($meta['preview'] === false && $branch !== 'main') {
            throw new RuntimeException('Only main can be a production coverage baseline');
        }
        $run = $meta['source_run_id'] ?? null;
        if (!Support::positive($run)) {
            throw new RuntimeException('Invalid source run ID');
        }
        $coverage = $report['coverage'] ?? null;
        if (!is_array($coverage) || ($coverage['type'] ?? null) !== 'loc') {
            throw new RuntimeException('Coverage explorer requires Octocov line coverage');
        }
        $covered = $coverage['covered'] ?? null;
        $total = $coverage['total'] ?? null;
        if (!is_int($covered) || !is_int($total) || $total <= 0 || $covered < 0 || $covered > $total) {
            throw new RuntimeException('Invalid coverage counters');
        }
        $date = Support::utc((string)($report['timestamp'] ?? ''));
        $rawFiles = $coverage['files'] ?? null;
        if (!is_array($rawFiles) || !array_is_list($rawFiles) || count($rawFiles) === 0) {
            throw new RuntimeException('Missing per-file coverage data');
        }
        $files = [];
        $folders = [];
        $seen = [];
        foreach ($rawFiles as $file) {
            if (!is_array($file) || !is_string($file['normalized_path'] ?? null)) {
                throw new RuntimeException('Invalid coverage file entry');
            }
            $normalized = $file['normalized_path'];
            $parts = explode('/', $normalized);
            if (count($parts) < 2 || $parts[0] !== 'lib' || !str_ends_with($normalized, '.php')
                || str_contains($normalized, '\\') ||
                array_filter($parts, static fn ($p): bool => $p === '' || $p === '.' || $p === '..' || preg_match('/[\x00-\x1f]/', $p) === 1)) {
                throw new RuntimeException("Unsafe or unexpected normalized PHP path: $normalized");
            }
            if (isset($seen[$normalized])) {
                throw new RuntimeException("Duplicate coverage file: $normalized");
            }
            $seen[$normalized] = true;
            $n = $file['total'] ?? null;
            $c = $file['covered'] ?? null;
            if (!is_int($n) || !is_int($c) || $n < 0 || $c < 0 || $c > $n) {
                throw new RuntimeException("Invalid counters in $normalized");
            }
            $pathParts = array_slice($parts, 1);
            $relative = implode('/', $pathParts);
            $quoted = implode('/', array_map('rawurlencode', $pathParts));
            $folder = count($parts) > 2 ? implode('/', array_slice($parts, 0, 2)) : 'lib';
            $files[] = [
                'path' => $normalized,
                'directory' => $folder,
                'covered' => $c,
                'total' => $n,
                'uncovered' => $n - $c,
                'percent' => $n ? round($c * 100 / $n, 2, PHP_ROUND_HALF_EVEN) : null,
                'report_url' => is_file("$htmlRoot/$relative.html") ? "libresign/$quoted.html" : null,
                'source_url' => 'https://github.com/LibreSign/libresign/blob/' . $sha . '/' .
                    implode('/', array_map('rawurlencode', $parts)),
            ];
            if (!isset($folders[$folder])) {
                $folders[$folder] = ['covered' => 0, 'total' => 0, 'files' => 0];
            }
            $folders[$folder]['covered'] += $c;
            $folders[$folder]['total'] += $n;
            $folders[$folder]['files']++;
        }
        if (array_sum(array_column($files, 'covered')) !== $covered ||
            array_sum(array_column($files, 'total')) !== $total) {
            throw new RuntimeException('Per-file coverage totals differ from the native report');
        }
        $directories = [];
        foreach ($folders as $name => $stats) {
            $path = array_slice(explode('/', $name), 1);
            $relative = implode('/', $path);
            $directories[] = [
                'name' => $name,
                'covered' => $stats['covered'],
                'total' => $stats['total'],
                'files' => $stats['files'],
                'uncovered' => $stats['total'] - $stats['covered'],
                'percent' => $stats['total'] ? round($stats['covered'] * 100 / $stats['total'], 2, PHP_ROUND_HALF_EVEN) : null,
                'report_url' => is_file("$htmlRoot/" . ($relative ? "$relative/" : '') . 'index.html')
                    ? 'libresign/' . implode('/', array_map('rawurlencode', $path)) . '/index.html'
                    : null,
            ];
        }
        $sort = static fn (array $a, array $b): int => ($b['uncovered'] <=> $a['uncovered'])
            ?: strcmp($a['path'] ?? $a['name'], $b['path'] ?? $b['name']);
        usort($directories, $sort);
        usort($files, $sort);
        return [
            'schema' => 1,
            'repository' => Support::SOURCE,
            'commit' => $sha,
            'source_branch' => $branch,
            'run_id' => $run,
            'preview' => $meta['preview'],
            'measured_at' => $date,
            'runtime' => is_string($meta['runtime'] ?? null) ? $meta['runtime'] : null,
            'covered' => $covered,
            'total' => $total,
            'uncovered' => $total - $covered,
            'percent' => round($covered * 100 / $total, 2, PHP_ROUND_HALF_EVEN),
            'files_count' => count($files),
            'directories' => $directories,
            'files' => $files,
        ];
    }

    public static function publish(string $root, array $opts = []): array
    {
        $native = $opts['native-report'] ?? "$root/downloads/native/report.json";
        $fallback = $opts['fallback-report'] ?? "$root/downloads/libresign-octocov.json.gz";
        $metadata = $opts['metadata'] ?? "$root/downloads/metadata.json";
        $html = $opts['html-root'] ?? "$root/site/libresign";
        $output = $opts['output'] ?? "$root/site/assets/coverage-summary.json";
        $isNative = is_file($native);
        $reportFile = $isNative ? $native : $fallback;
        Support::required($reportFile);
        $meta = Support::data($metadata);
        if ($isNative && ($meta['preview'] ?? null) !== false) {
            throw new RuntimeException('Native artifact must have verified production metadata');
        }
        $data = self::build(Support::readJsonOrGzip($reportFile), $meta, $html);
        Support::write($output, $data);
        return $data;
    }
}
