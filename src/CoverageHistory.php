<?php
// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Coverage;

use RuntimeException;

final class CoverageHistory
{
    public static function legacy(array $builds): array
    {
        $unique = [];
        foreach ($builds as $build) {
            if (!is_array($build) || ($build['repo_name'] ?? null) !== Support::SOURCE || ($build['branch'] ?? null) !== 'main') {
                continue;
            }
            $sha = $build['commit_sha'] ?? null;
            $percent = $build['covered_percent'] ?? null;
            $covered = $build['covered_lines'] ?? null;
            $total = $build['relevant_lines'] ?? null;
            if (!Support::sha($sha) || !(is_float($percent) || is_int($percent)) || !is_finite((float)$percent)
                || $percent < 0 || $percent > 100 || !is_int($covered) || !is_int($total)
                || $total <= 0 || $covered < 0 || $covered > $total || abs($percent - $covered * 100 / $total) > .05) {
                continue;
            }
            $date = Support::utc((string)($build['calculated_at'] ?? ''));
            $point = [
                'date' => $date,
                'pct' => round($percent, 4, PHP_ROUND_HALF_EVEN),
                'covered' => $covered,
                'total' => $total,
                'sha' => $sha,
                'run' => $build['id'] ?? null,
            ];
            if (!isset($unique[$sha]) || $point['date'] > $unique[$sha]['date']) {
                $unique[$sha] = $point;
            }
        }
        return self::order(array_values($unique));
    }

    public static function native(array $metadata): array
    {
        $unique = [];
        foreach ($metadata as $item) {
            if (!is_array($item) || ($item['repository'] ?? null) !== Support::SOURCE ||
                ($item['source_branch'] ?? null) !== 'main' || ($item['preview'] ?? null) !== false) {
                continue;
            }
            $sha = $item['source_commit_sha'] ?? null;
            if (!Support::sha($sha)) {
                throw new RuntimeException('Invalid source SHA in archived Octocov metadata');
            }
            $covered = $item['covered'] ?? null;
            $total = $item['total'] ?? null;
            $run = $item['source_run_id'] ?? null;
            if (!is_int($covered) || !is_int($total) || $total <= 0 || $covered < 0 || $covered > $total) {
                throw new RuntimeException('Invalid Octocov coverage counters in archived metadata');
            }
            if (!Support::positive($run)) {
                throw new RuntimeException('Invalid source run id');
            }
            $date = Support::utc((string)($item['recorded_at'] ?? ''));
            $point = [
                'date' => $date,
                'pct' => round($covered * 100 / $total, 4, PHP_ROUND_HALF_EVEN),
                'covered' => $covered,
                'total' => $total,
                'sha' => $sha,
                'run' => $run,
            ];
            if (!isset($unique[$sha]) || $point['date'] > $unique[$sha]['date']) {
                $unique[$sha] = $point;
            }
        }
        return self::order(array_values($unique));
    }

    private static function order(array $points): array
    {
        usort($points, static fn(array $a, array $b): int =>
            strcmp($a['date'], $b['date']) ?: strcmp($a['sha'], $b['sha'])
        );
        return $points;
    }

    public static function build(string $archive, ?string $metadataDirectory = null): array
    {
        $raw = Support::decode(Support::gzipRead($archive));
        if (($raw['report']['complete'] ?? null) !== true || !is_array($raw['builds'] ?? null) || !array_is_list($raw['builds'])) {
            throw new RuntimeException('Coveralls archive is incomplete or malformed');
        }
        $metadata = [];
        if ($metadataDirectory !== null) {
            foreach (Support::matchingFiles($metadataDirectory, '/^libresign-.*-metadata\.json$/D') as $file) {
                $metadata[] = Support::data($file);
            }
        }
        return [
            'schema' => 1,
            'repository' => Support::SOURCE,
            'series' => [
                'legacy_coveralls' => [
                    'label' => 'Coveralls (historical)',
                    'metric' => 'covered lines / relevant lines',
                    'points' => self::legacy($raw['builds']),
                ],
                'octocov_main' => [
                    'label' => 'Octocov (main)',
                    'metric' => 'instrumented statements covered / total',
                    'points' => self::native($metadata),
                ],
            ],
        ];
    }
}
