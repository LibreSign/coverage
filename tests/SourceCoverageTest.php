<?php
// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Coverage\Tests;

use LibreSign\Coverage\SourceCoverage;
use RuntimeException;

final class SourceCoverageTest extends Fixtures
{
    public function testSelectsMasterAndHighestPhpVersion(): void
    {
        $data = [];
        foreach (['8.3-stable35', '8.4-master', '8.3-master', '8.5-stable35'] as $name) {
            $data[] = ['name' => "phpunit-coverage-php-$name", 'expired' => false];
        }
        self::assertSame('phpunit-coverage-php-8.4-nextcloud-master',
            SourceCoverage::select([
                ['name' => 'phpunit-coverage-php-8.4-nextcloud-master', 'expired' => false],
                ['name' => 'phpunit-coverage-php-8.3-nextcloud-master', 'expired' => false],
                ['name' => 'phpunit-coverage-php-8.5-nextcloud-stable35', 'expired' => false],
            ]));
        self::assertSame('phpunit-coverage-php-8.10-nextcloud-master',
            SourceCoverage::select([
                ['name' => 'phpunit-coverage-php-8.9-nextcloud-master', 'expired' => false],
                ['name' => 'phpunit-coverage-php-8.10-nextcloud-master', 'expired' => false],
            ]));
    }

    public function testExpiredAndMissingArtifactsDoNotPublish(): void
    {
        $matrix = [['name' => 'phpunit-coverage-php-8.4-nextcloud-master', 'expired' => false]];
        self::assertNull(SourceCoverage::complete($matrix));
        self::assertNull(SourceCoverage::complete([...$matrix, ['name' => 'octocov-report', 'expired' => true]]));
        self::assertSame($matrix[0]['name'], SourceCoverage::complete([...$matrix,
            ['name' => 'octocov-report', 'expired' => false]]));
    }

    public function testStableFallbackWithoutMaster(): void
    {
        self::assertSame('phpunit-coverage-php-8.5-nextcloud-stable35',
            SourceCoverage::select([
                ['name' => 'phpunit-coverage-php-8.3-nextcloud-stable35', 'expired' => false],
                ['name' => 'phpunit-coverage-php-8.5-nextcloud-stable35', 'expired' => false],
            ]));
    }

    public function testValidatesSourceCommitAndRepository(): void
    {
        $sha = str_repeat('a', 40);
        $valid = ['repository' => 'LibreSign/libresign', 'ref' => 'refs/heads/main', 'commit' => $sha,
            'coverage' => ['covered' => 1, 'total' => 2]];
        SourceCoverage::verify($valid, $sha);
        self::assertTrue(true);
        foreach ([
            ['commit' => str_repeat('b', 40)],
            ['repository' => 'someone/else'],
            ['ref' => 'refs/pull/123/merge'],
            ['coverage' => ['covered' => 0, 'total' => 0]],
            ['coverage' => ['covered' => true, 'total' => 4]],
        ] as $patch) {
            try {
                SourceCoverage::verify(array_replace($valid, $patch), $sha);
                self::fail('Accepted malformed source metrics/provenance');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function testMetadataPreservesRunAttemptAndMeasuredCounters(): void
    {
        $sha = str_repeat('a', 40);
        $report = ['repository' => 'LibreSign/libresign', 'ref' => 'refs/heads/main',
            'commit' => $sha, 'timestamp' => '2026-11-07T23:51:03.341546702Z',
            'coverage' => ['covered' => 21718, 'total' => 28544]];
        $meta = SourceCoverage::metadata($report, $sha, 123, 2);
        self::assertSame('2026-11-07T23:51:03Z', $meta['recorded_at']);
        self::assertSame(2, $meta['source_run_attempt']);
        self::assertSame(21718, $meta['covered']);
        self::assertFalse($meta['preview']);
        foreach ([
            array_replace($report, ['timestamp' => '2026-11-07T23:51:03']),
            array_replace($report, ['ref' => 'refs/pull/123/merge']),
            array_replace($report, ['coverage' => ['covered' => true, 'total' => 10]]),
            array_replace($report, ['coverage' => ['covered' => 11, 'total' => 10]]),
        ] as $invalid) {
            try {
                SourceCoverage::metadata($invalid, $sha, 123, 1);
                self::fail('Accepted invalid metadata');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
        foreach ([0, -1] as $run) {
            try {
                SourceCoverage::metadata($report, $sha, $run, 1);
                self::fail('Accepted invalid run ID');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRejectsPathAsSha(): void
    {
        $this->expectException(RuntimeException::class);
        SourceCoverage::verify([], '../../main');
    }
}
