<?php
// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Coverage\Tests;

use LibreSign\Coverage\CoverageHistory;
use LibreSign\Coverage\Support;
use RuntimeException;

final class CoverageHistoryTest extends Fixtures
{
    private function legacy(array $changes = []): array
    {
        return array_replace([
            'repo_name' => 'LibreSign/libresign', 'branch' => 'main', 'commit_sha' => str_repeat('a', 40),
            'calculated_at' => '2023-01-01T00:00:00Z', 'covered_percent' => 75.0,
            'covered_lines' => 3, 'relevant_lines' => 4, 'id' => 1,
        ], $changes);
    }

    public function testOnlyMainAndUniqueCommits(): void
    {
        $points = CoverageHistory::legacy([
            $this->legacy(),
            $this->legacy(['branch' => 'feature']),
            $this->legacy(['calculated_at' => '2023-01-02T00:00:00Z']),
            $this->legacy(['commit_sha' => str_repeat('b', 40), 'calculated_at' => '2023-01-03T00:00:00Z']),
        ]);
        self::assertCount(2, $points);
        self::assertSame('2023-01-02T00:00:00Z', $points[0]['date']);
    }

    public function testRejectsMismatchedHistoricalCounters(): void
    {
        self::assertSame([], CoverageHistory::legacy([$this->legacy(['covered_percent' => 99])]));
    }

    public function testPreviewAndNonMainAreExcluded(): void
    {
        $record = ['repository' => 'LibreSign/libresign', 'source_branch' => 'main', 'preview' => false,
            'source_commit_sha' => str_repeat('a', 40), 'source_run_id' => 123,
            'covered' => 9, 'total' => 10, 'recorded_at' => '2026-11-08T00:00:00Z'];
        self::assertCount(1, CoverageHistory::native([$record]));
        self::assertSame([], CoverageHistory::native([array_replace($record, ['preview' => true])]));
        self::assertSame([], CoverageHistory::native([array_replace($record, ['source_branch' => 'dev'])]));
        $this->expectException(RuntimeException::class);
        CoverageHistory::native([array_replace($record, ['covered' => 11])]);
    }

    public function testKeepsHistoricalAndOctocovDataSeparate(): void
    {
        $archive = $this->dir . '/archive.gz';
        Support::gzipWrite($archive, json_encode([
            'report' => ['complete' => true], 'builds' => [$this->legacy()],
        ], JSON_THROW_ON_ERROR));
        $result = CoverageHistory::build($archive);
        self::assertCount(1, $result['series']['legacy_coveralls']['points']);
        self::assertSame([], $result['series']['octocov_main']['points']);
    }

    public function testInvalidTimezonesFail(): void
    {
        $this->expectException(RuntimeException::class);
        CoverageHistory::legacy([$this->legacy(['calculated_at' => '2023-01-01'])]);
    }
}
