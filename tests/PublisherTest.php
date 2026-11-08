<?php
// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Coverage\Tests;

use LibreSign\Coverage\Publisher;
use LibreSign\Coverage\Support;
use RuntimeException;

final class PublisherTest extends Fixtures
{
    private string $sha;
    private int $attempt = 1;
    private array $report;
    private array $releases = ['coverage-latest' => []];
    private array $env = [
        'GH_TOKEN' => 'unused-fake',
        'GITHUB_REPOSITORY' => 'LibreSign/coverage',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sha = str_repeat('a', 40);
        $this->report = [
            'repository' => 'LibreSign/libresign', 'ref' => 'refs/heads/main',
            'commit' => $this->sha, 'timestamp' => '2026-11-08T12:00:00Z',
            'coverage' => ['covered' => 2, 'total' => 3],
        ];
        $this->writeReport();
        $this->file('downloads/phpunit/clover.xml', '<coverage>actual clover</coverage>');
        $this->file('site/libresign/index.html', '<html>PHPUnit files</html>');
        $this->file('site/libresign/dashboard.html', '<html>Dashboard</html>');
    }

    private function writeReport(): void
    {
        $this->json('downloads/native/report.json', $this->report);
        $this->json('downloads/metadata.json', [
            'repository' => 'LibreSign/libresign',
            'source_branch' => 'main',
            'source_commit_sha' => $this->sha,
            'source_run_id' => 1028,
            'source_run_attempt' => $this->attempt,
            'recorded_at' => '2026-11-08T12:00:00Z',
            'covered' => $this->report['coverage']['covered'],
            'total' => $this->report['coverage']['total'],
            'preview' => false,
        ]);
    }

    private function fake(): FakeGithub
    {
        return new FakeGithub(function (array $args): string {
            if ($args[0] === 'release' && $args[1] === 'view') {
                if (!isset($this->releases[$args[2]])) {
                    throw new RuntimeException('404 release');
                }
                return '{}';
            }
            if ($args[0] === 'release' && $args[1] === 'create') {
                $this->releases[$args[2]] = [];
                return '';
            }
            if ($args[0] === 'release' && $args[1] === 'upload') {
                $tag = $args[2];
                if (!isset($this->releases[$tag])) {
                    throw new RuntimeException('missing release');
                }
                $canReplace = in_array('--clobber', $args, true);
                foreach (array_slice($args, 3) as $file) {
                    if (str_starts_with($file, '--')) {
                        break;
                    }
                    $name = basename($file);
                    $digest = 'sha256:' . hash_file('sha256', $file);
                    if (array_key_exists($name, $this->releases[$tag]) && !$canReplace) {
                        throw new RuntimeException('cannot overwrite existing asset');
                    }
                    $this->releases[$tag][$name] = $digest;
                }
                return '';
            }
            if ($args[0] === 'release' && $args[1] === 'edit') {
                return '';
            }
            if ($args[0] === 'api' && str_contains($args[1], '/releases/tags/')) {
                return '{"id":7}';
            }
            if ($args[0] === 'api' && in_array('--paginate', $args, true)) {
                $result = [];
                foreach ($this->releases as $tag => $files) {
                    if (str_starts_with($tag, 'coverage-history-')) {
                        foreach ($files as $name => $digest) {
                            $result[] = "$name\t$digest";
                        }
                    }
                }
                return implode("\n", $result);
            }
            throw new RuntimeException('Unexpected GitHub command: ' . implode(' ', $args));
        });
    }

    private function archive(): void
    {
        Publisher::archive($this->fake(), $this->dir, $this->env);
    }

    private function historyAssets(): array
    {
        $history = array_filter(array_keys($this->releases),
            static fn($tag) => str_starts_with($tag, 'coverage-history-'));
        self::assertCount(1, $history);
        return $this->releases[array_values($history)[0]];
    }

    public function testFirstArchiveAndRetryAreImmutable(): void
    {
        $this->archive();
        $original = $this->historyAssets();
        self::assertCount(4, $original);
        self::assertArrayHasKey('libresign-html.tar.gz', $this->releases['coverage-latest']);
        self::assertFileExists("$this->dir/coverage-snapshot/checksums.sha256");
        $this->archive();
        self::assertSame($original, $this->historyAssets());
    }

    public function testConflictingSameRunNeverOverwritesHistory(): void
    {
        $this->archive();
        $original = $this->historyAssets();
        $this->report['coverage']['covered'] = 1;
        $this->writeReport();
        try {
            $this->archive();
            self::fail('Conflicting archive was accepted');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Conflicting archived coverage asset', $e->getMessage());
            self::assertSame($original, $this->historyAssets());
        }
    }

    public function testNewAttemptIsASeparateHistoricalMeasurement(): void
    {
        $this->archive();
        $this->attempt = 2;
        $this->writeReport();
        $this->archive();
        self::assertCount(8, $this->historyAssets());
    }

    public function testRejectsInvalidProvenanceBeforeReleaseWrite(): void
    {
        $this->report['ref'] = 'refs/pull/99/merge';
        $this->writeReport();
        try {
            $this->archive();
            self::fail('Accepted invalid source');
        } catch (RuntimeException) {
            self::assertSame([], $this->historyAssetsWhenMissing());
        }
    }

    private function historyAssetsWhenMissing(): array
    {
        return array_filter(array_keys($this->releases),
            static fn($tag) => str_starts_with($tag, 'coverage-history-'));
    }

    public function testPinnedOldRunCannotOverwriteCurrentFallback(): void
    {
        $this->env['ARCHIVE_CURRENT_FALLBACK'] = 'false';
        $this->archive();
        self::assertCount(4, $this->historyAssets());
        self::assertSame([], $this->releases['coverage-latest']);
    }

    public function testRejectsMismatchBeforeAttemptingRemoteWrite(): void
    {
        $this->archive();
        $original = $this->historyAssets();
        $this->report['coverage']['covered'] = 1;
        $this->json('downloads/native/report.json', $this->report);
        try {
            $this->archive();
            self::fail('Accepted mismatched metadata');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('metadata changed', $e->getMessage());
            self::assertSame($original, $this->historyAssets());
        }
    }

    public function testPhpGeneratedFallbackTarRestoresInIndependentProcess(): void
    {
        $this->archive();
        foreach ([
            'libresign-html.tar.gz', 'libresign-clover.xml.gz',
            'libresign-octocov.json.gz', 'checksums.sha256', 'metadata.json',
        ] as $asset) {
            self::assertTrue(copy("$this->dir/coverage-snapshot/$asset", "$this->dir/downloads/$asset"));
        }
        unlink("$this->dir/site/libresign/index.html");
        unlink("$this->dir/site/libresign/dashboard.html");
        $bootstrap = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);
        $command = [PHP_BINARY, '-r',
            "require $bootstrap; LibreSign\\Coverage\\Build::restoreFallback(\$argv[1]);",
            $this->dir,
        ];
        $process = proc_open($command,
            [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stdout . $stderr);
        self::assertSame('<html>PHPUnit files</html>',
            file_get_contents("$this->dir/site/libresign/index.html"));
        self::assertSame('<coverage>actual clover</coverage>',
            file_get_contents("$this->dir/site/libresign/clover.xml"));
    }

    public function testRequiresScopedTokenAndCorrectPublisher(): void
    {
        unset($this->env['GH_TOKEN']);
        $this->expectException(RuntimeException::class);
        $this->archive();
    }

    public function testChecksumMismatchFailsClosed(): void
    {
        $this->file('files/item.gz', 'original');
        $manifest = $this->file('files/checksums.sha256',
            hash('sha256', 'different') . '  item.gz' . "\n");
        $this->expectExceptionMessage('Checksum mismatch');
        Publisher::verifyChecksums($manifest, "$this->dir/files");
    }
}
