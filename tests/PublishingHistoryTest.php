<?php
// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Coverage\Tests;

use LibreSign\Coverage\Publisher;
use LibreSign\Coverage\Support;
use RuntimeException;

final class PublishingHistoryTest extends Fixtures
{
    private array $env = ['GH_TOKEN' => 'test-only', 'GITHUB_REPOSITORY' => 'LibreSign/coverage'];

    private function github(bool $corrupt = false, bool $badTag = false): FakeGithub
    {
        return new FakeGithub(function (array $args) use ($corrupt, $badTag): string {
            if ($args[0] !== 'release') {
                throw new RuntimeException('Unexpected request');
            }
            if ($args[1] === 'download' && $args[2] === 'coveralls-archive-2026-11-07') {
                $dir = $args[array_search('--dir', $args) + 1];
                $data = [
                    'report' => ['complete' => true],
                    'builds' => [['repo_name' => 'LibreSign/libresign', 'branch' => 'main',
                        'commit_sha' => str_repeat('a', 40), 'calculated_at' => '2023-01-01T00:00:00Z',
                        'covered_percent' => 75.0, 'covered_lines' => 3, 'relevant_lines' => 4, 'id' => 1]],
                ];
                Support::gzipWrite("$dir/coveralls-history.json.gz", json_encode($data, JSON_THROW_ON_ERROR));
                $digest = $corrupt ? str_repeat('0', 64) : hash_file('sha256', "$dir/coveralls-history.json.gz");
                Support::put("$dir/coveralls-archive.sha256", "$digest  coveralls-history.json.gz\n");
                return '';
            }
            if ($args[1] === 'list') {
                return json_encode([['tagName' => $badTag ? 'coverage-history-2026-../../etc' : 'coverage-history-2026-11']]);
            }
            if ($args[1] === 'view') {
                return json_encode(['assets' => [['name' => 'libresign-' . str_repeat('b',40) .
                    '-run-7-attempt-1-metadata.json']]]);
            }
            if ($args[1] === 'download' && $args[2] === 'coverage-history-2026-11') {
                $dir = $args[array_search('--dir', $args) + 1];
                Support::write("$dir/libresign-" . str_repeat('b',40) . '-run-7-attempt-1-metadata.json', [
                    'repository' => 'LibreSign/libresign', 'source_branch' => 'main', 'preview' => false,
                    'source_commit_sha' => str_repeat('b', 40), 'source_run_id' => 7,
                    'covered' => 90, 'total' => 100, 'recorded_at' => '2026-11-08T12:00:00Z',
                ]);
                return '';
            }
            throw new RuntimeException('Unexpected GitHub operation');
        });
    }

    public function testHistoryDownloadsAndCombinesSeparateVerifiedSeries(): void
    {
        Publisher::history($this->github(), $this->dir, $this->env);
        $out = Support::data("$this->dir/site/assets/coverage-history.json");
        self::assertSame(1, $out['schema']);
        self::assertCount(1, $out['series']['legacy_coveralls']['points']);
        self::assertCount(1, $out['series']['octocov_main']['points']);
        self::assertSame(75.0, $out['series']['legacy_coveralls']['points'][0]['pct']);
        self::assertSame(90.0, $out['series']['octocov_main']['points'][0]['pct']);
    }

    public function testHistoryRejectsTamperedCoverallsArchive(): void
    {
        $this->expectExceptionMessage('Checksum mismatch');
        Publisher::history($this->github(corrupt: true), $this->dir, $this->env);
    }

    public function testHistoryRejectsUnexpectedReleaseTag(): void
    {
        $this->expectExceptionMessage('Unexpected coverage history release tag');
        Publisher::history($this->github(badTag: true), $this->dir, $this->env);
    }

    public function testHistoryRequiresPublisherIdentityAndToken(): void
    {
        $this->expectException(RuntimeException::class);
        Publisher::history($this->github(), $this->dir, ['GITHUB_REPOSITORY' => 'LibreSign/coverage']);
    }
}
