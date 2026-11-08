<?php
// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Coverage\Tests;

use LibreSign\Coverage\CoverageSummary;
use LibreSign\Coverage\Support;
use RuntimeException;

final class CoverageSummaryTest extends Fixtures
{
    private function report(): array
    {
        return [
            'repository' => 'LibreSign/libresign', 'ref' => 'refs/heads/main',
            'commit' => str_repeat('a', 40), 'timestamp' => '2026-11-08T12:00:00Z',
            'coverage' => ['type' => 'loc', 'covered' => 4, 'total' => 6, 'files' => [
                ['normalized_path' => 'lib/Service/Test.php', 'covered' => 3, 'total' => 4],
                ['normalized_path' => 'lib/Service/Other.php', 'covered' => 1, 'total' => 2],
            ]],
        ];
    }

    private function meta(): array
    {
        return ['repository' => 'LibreSign/libresign', 'source_branch' => 'main',
            'source_commit_sha' => str_repeat('a', 40), 'source_run_id' => 153,
            'preview' => false];
    }

    public function testSummaryIncludesSourceLinksAndExistingPhpunitPages(): void
    {
        $this->file('html/Service/Test.php.html', '<html>Test</html>');
        $this->file('html/Service/index.html', '<html>Service</html>');
        $result = CoverageSummary::build($this->report(), $this->meta(), "$this->dir/html");
        self::assertSame(6, $result['total']);
        self::assertSame(4, $result['covered']);
        self::assertSame(2, $result['uncovered']);
        self::assertSame(2, $result['files_count']);
        $files = array_column($result['files'], null, 'path');
        self::assertSame('libresign/Service/Test.php.html', $files['lib/Service/Test.php']['report_url']);
        self::assertNull($files['lib/Service/Other.php']['report_url']);
        self::assertSame('libresign/Service/index.html', $result['directories'][0]['report_url']);
        self::assertSame('https://github.com/LibreSign/libresign/blob/' . str_repeat('a', 40) . '/lib/Service/Test.php',
            $files['lib/Service/Test.php']['source_url']);
        self::assertFalse($result['preview']);
    }

    public function testRejectsProductionWithoutMatchingMainProvenance(): void
    {
        foreach ([
            [$this->report(), array_replace($this->meta(), ['source_branch' => 'feature'])],
            [array_replace($this->report(), ['commit' => str_repeat('b', 40)]), $this->meta()],
            [$this->report(), array_replace($this->meta(), ['preview' => 'false'])],
        ] as [$report, $metadata]) {
            try {
                CoverageSummary::build($report, $metadata, "$this->dir/html");
                self::fail('Accepted invalid production provenance');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRejectsMalformedAndTraversalPaths(): void
    {
        foreach (['../../etc/passwd.php', 'lib/../Private.php', 'lib//Broken.php', 'lib\\Admin.php', 'src/Other.php'] as $path) {
            $report = $this->report();
            $report['coverage']['files'][0]['normalized_path'] = $path;
            try {
                CoverageSummary::build($report, $this->meta(), "$this->dir/html");
                self::fail("Accepted unsafe path: $path");
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRejectsCountersThatDifferFromNativeReport(): void
    {
        $report = $this->report();
        $report['coverage']['total'] = 7;
        $this->expectException(RuntimeException::class);
        CoverageSummary::build($report, $this->meta(), "$this->dir/html");
    }

    public function testReportsNeverLeakAbsoluteRunnerPaths(): void
    {
        $result = CoverageSummary::build($this->report(), $this->meta(), "$this->dir/html");
        self::assertStringNotContainsString($this->dir, json_encode($result));
    }

    public function testNativeInputRequiresVerifiedProductionMetadata(): void
    {
        $this->json('downloads/native/report.json', $this->report());
        $this->json('downloads/metadata.json', array_replace($this->meta(), ['preview' => true]));
        $this->expectException(RuntimeException::class);
        CoverageSummary::publish($this->dir);
    }
}
