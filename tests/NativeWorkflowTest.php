<?php
// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Coverage\Tests;

use LibreSign\Coverage\SourceCoverage;
use LibreSign\Coverage\Support;
use RuntimeException;

final class NativeWorkflowTest extends Fixtures
{
    private string $sha;
    private array $source;
    private array $report;
    private array $artifactList;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sha = str_repeat('a', 40);
        $this->source = [
            'id' => 1028, 'run_attempt' => 1, 'head_sha' => $this->sha,
            'head_repository' => ['full_name' => 'LibreSign/libresign'],
            'head_branch' => 'main', 'event' => 'push', 'status' => 'completed',
            'conclusion' => 'success', 'path' => '.github/workflows/phpunit-sqlite.yml',
        ];
        $this->report = ['repository' => 'LibreSign/libresign', 'ref' => 'refs/heads/main',
            'commit' => $this->sha, 'timestamp' => '2026-11-08T12:00:00Z',
            'coverage' => ['covered' => 2, 'total' => 3]];
        $this->artifactList = [
            ['name' => 'octocov-report', 'expired' => false],
            ['name' => 'phpunit-coverage-php-8.5-nextcloud-master', 'expired' => false],
        ];
    }

    private function gh(): FakeGithub
    {
        return new FakeGithub(function (array $args): string {
            if ($args[0] === 'api' && str_contains($args[1], '/runs/1028/artifacts')) {
                return json_encode(['artifacts' => $this->artifactList]);
            }
            if ($args[0] === 'api' && str_contains($args[1], '/runs/1028')) {
                return json_encode($this->source);
            }
            if ($args[0] === 'api' && str_contains($args[1], '/runs?')) {
                return json_encode(['workflow_runs' => [$this->source]]);
            }
            if ($args[0] === 'run' && $args[1] === 'download') {
                $name = $args[array_search('--name', $args) + 1];
                $dir = $args[array_search('--dir', $args) + 1];
                if ($name === 'octocov-report') {
                    Support::write("$dir/report.json", $this->report);
                } else {
                    Support::put("$dir/clover.xml", '<coverage/>');
                    Support::put("$dir/html-coverage/index.html", '<html>PHPUnit coverage</html>');
                    Support::put("$dir/html-coverage/dashboard.html", '<html>Dashboard</html>');
                }
                return '';
            }
            throw new RuntimeException('Unexpected fake command: ' . implode(' ', $args));
        });
    }

    private function env(bool $pinned = false): array
    {
        $result = ['GITHUB_OUTPUT' => "$this->dir/output"];
        if ($pinned) {
            $result += [
                'COVERAGE_SOURCE_REPOSITORY' => 'LibreSign/libresign',
                'COVERAGE_SOURCE_RUN_ID' => '1028',
                'COVERAGE_SOURCE_SHA' => $this->sha,
            ];
        }
        return $result;
    }

    public function testVerifiedMainRunPreservesProvenanceAndPhpunitFiles(): void
    {
        $gh = $this->gh();
        self::assertTrue(SourceCoverage::prepare($gh, $this->dir, $this->env()));
        $metadata = Support::data("$this->dir/downloads/metadata.json");
        self::assertSame($this->sha, $metadata['source_commit_sha']);
        self::assertSame(1028, $metadata['source_run_id']);
        self::assertFalse($metadata['preview']);
        self::assertSame(2, $metadata['covered']);
        self::assertFileExists("$this->dir/site/libresign/index.html");
        self::assertFileExists("$this->dir/site/libresign/clover.xml");
        self::assertStringContainsString('available=true', file_get_contents("$this->dir/output"));
        self::assertCount(2, array_filter($gh->calls, static fn ($a) => $a[0] === 'run'));
    }

    public function testNoNativeArtifactKeepsFallbackAndDoesNotDownload(): void
    {
        $this->artifactList = array_slice($this->artifactList, 1);
        $gh = $this->gh();
        self::assertFalse(SourceCoverage::prepare($gh, $this->dir, $this->env()));
        self::assertStringContainsString('available=false', file_get_contents("$this->dir/output"));
        self::assertFileDoesNotExist("$this->dir/downloads/metadata.json");
        self::assertCount(0, array_filter($gh->calls, static fn ($a) => $a[0] === 'run'));
    }

    public function testExplicitDispatchPinsExactVerifiedSourceRun(): void
    {
        self::assertTrue(SourceCoverage::prepare($this->gh(), $this->dir, $this->env(true)));
        self::assertSame(1028, Support::data("$this->dir/downloads/metadata.json")['source_run_id']);
    }

    public function testInvalidDispatchIdentityFailsBeforeAnyDownload(): void
    {
        foreach ([
            ['head_sha' => str_repeat('b', 40)],
            ['head_branch' => 'feature'],
            ['event' => 'pull_request'],
            ['conclusion' => 'failure'],
            ['status' => 'in_progress'],
            ['path' => '.github/workflows/another.yml'],
            ['head_repository' => ['full_name' => 'attacker/fork']],
        ] as $patch) {
            $this->source = array_replace($this->source, $patch);
            $gh = $this->gh();
            try {
                SourceCoverage::prepare($gh, $this->dir, $this->env(true));
                self::fail('Accepted malicious or unverified source');
            } catch (RuntimeException) {
                self::assertCount(0, array_filter($gh->calls, static fn ($a) => $a[0] === 'run'));
            }
            $this->source = array_replace($this->source, [
                'id' => 1028, 'run_attempt' => 1, 'head_sha' => $this->sha,
                'head_repository' => ['full_name' => 'LibreSign/libresign'],
                'head_branch' => 'main', 'event' => 'push', 'status' => 'completed',
                'conclusion' => 'success', 'path' => '.github/workflows/phpunit-sqlite.yml',
            ]);
        }
    }

    public function testExplicitDispatchWithoutArtifactsFailsClosed(): void
    {
        $this->artifactList = [];
        $this->expectExceptionMessage('no complete native coverage');
        SourceCoverage::prepare($this->gh(), $this->dir, $this->env(true));
    }

    public function testMismatchedOctocovCommitNeverPublishes(): void
    {
        $this->report['commit'] = str_repeat('b', 40);
        $this->expectException(RuntimeException::class);
        SourceCoverage::prepare($this->gh(), $this->dir, $this->env());
    }

    public function testArtifactApiFailureIsNotConfusedWithNoReport(): void
    {
        $gh = new FakeGithub(static fn(array $args) => throw new RuntimeException('GitHub API 500'));
        $this->expectException(RuntimeException::class);
        SourceCoverage::prepare($gh, $this->dir, $this->env());
    }

    public function testDispatchRepositoryMustMatchExpectedSource(): void
    {
        $env = $this->env(true);
        $env['COVERAGE_SOURCE_REPOSITORY'] = 'attacker/repo';
        $this->expectException(RuntimeException::class);
        SourceCoverage::prepare($this->gh(), $this->dir, $env);
    }
}
