<?php
// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use LibreSign\Coverage\Build;
use LibreSign\Coverage\CoverageHistory;
use LibreSign\Coverage\CoverageSummary;
use LibreSign\Coverage\GhCli;
use LibreSign\Coverage\Publisher;
use LibreSign\Coverage\Site;
use LibreSign\Coverage\SourceCoverage;
use LibreSign\Coverage\Support;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$command = $argv[1] ?? null;
$args = array_slice($argv, 2);
$options = [];
foreach (['native-report', 'fallback-report', 'metadata', 'html-root', 'output', 'legacy-archive', 'native-metadata-dir', 'public'] as $option) {
    $value = Support::option($args, $option);
    if ($value !== null) {
        $options[$option] = $value;
    }
}
$env = array_filter($_SERVER, static fn($x) => is_string($x));
foreach (['GH_TOKEN', 'GITHUB_REPOSITORY', 'GITHUB_OUTPUT', 'COVERAGE_SOURCE_RUN_ID',
          'COVERAGE_SOURCE_REPOSITORY', 'COVERAGE_SOURCE_SHA', 'ARCHIVE_CURRENT_FALLBACK'] as $key) {
    $value = getenv($key);
    if ($value !== false) {
        $env[$key] = $value;
    }
}
try {
    switch ($command) {
        case 'select':
            $data = Support::data($args[0] ?? '');
            echo SourceCoverage::complete($data['artifacts'] ?? []) ?? '';
            echo "\n";
            break;
        case 'verify':
            SourceCoverage::verify(Support::data($args[0] ?? ''), $args[1] ?? '');
            break;
        case 'metadata':
            if (count($args) !== 5 || !ctype_digit($args[2]) || !ctype_digit($args[3])) {
                throw new RuntimeException('Usage: metadata report sha run_id run_attempt output_path');
            }
            $result = SourceCoverage::metadata(Support::data($args[0]), $args[1], (int)$args[2], (int)$args[3]);
            Support::write($args[4], $result, true);
            break;
        case 'native':
            echo SourceCoverage::prepare(new GhCli(), $root, $env) ? "Native PHPUnit and Octocov data available\n" : "No complete native main report yet\n";
            break;
        case 'archive':
            Publisher::archive(new GhCli(), $root, $env);
            break;
        case 'history':
            Publisher::history(new GhCli(), $root, $env);
            break;
        case 'summary':
            $payload = CoverageSummary::publish($root, $options);
            echo "Coverage summary: {$payload['percent']}%, {$payload['files_count']} files, " .
                count($payload['directories']) . " directories, preview=" . ($payload['preview'] ? 'true' : 'false') . "\n";
            break;
        case 'trend':
            $legacy = $options['legacy-archive'] ?? "$root/downloads/history/coveralls/coveralls-history.json.gz";
            $native = $options['native-metadata-dir'] ?? "$root/downloads/history/octocov";
            $output = $options['output'] ?? "$root/site/assets/coverage-history.json";
            $history = CoverageHistory::build($legacy, $native);
            Support::write($output, $history);
            echo 'Coverage history: legacy_main=' . count($history['series']['legacy_coveralls']['points'])
                . ' native_main=' . count($history['series']['octocov_main']['points']) . "\n";
            break;
        case 'site-index':
            Build::siteIndex($root);
            break;
        case 'restore-fallback':
            Build::restoreFallback($root);
            break;
        case 'jekyll-config':
            Build::jekyllConfig($root);
            break;
        case 'stage-public':
            Build::stagePublic($root);
            break;
        case 'theme':
            echo 'Theme installed on ' . Site::theme($options['public'] ?? '', $options['metadata'] ?? '', $root) . " pages\n";
            break;
        case 'trend-page':
            Site::trendPage($options['public'] ?? '', $root);
            break;
        case 'dashboard':
            Site::dashboard($options['public'] ?? '', $root);
            break;
        default:
            throw new RuntimeException('Unknown command: ' . ($command ?? '(none)'));
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "::error::" . $e->getMessage() . "\n");
    exit(1);
}
