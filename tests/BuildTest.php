<?php
// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Coverage\Tests;

use LibreSign\Coverage\Build;
use LibreSign\Coverage\Support;
use RuntimeException;
use Phar;
use PharData;

final class BuildTest extends Fixtures
{
    public function testSiteIndexPreservesNativeBadgesAndCanonicalLinks(): void
    {
        $this->file('site/index.md', 'Badge: https://raw.githubusercontent.com/LibreSign/coverage/main/site/badges/a.svg');
        $this->json('downloads/metadata.json', ['source_commit_sha' => str_repeat('a',40), 'source_run_id' => 123]);
        Build::siteIndex($this->dir);
        $html = file_get_contents("$this->dir/site/index.md");
        self::assertStringContainsString('Badge: badges/a.svg', $html);
        self::assertStringContainsString('<!-- coverage-trend-slot -->', $html);
        self::assertStringContainsString('Browse coverage by file', $html);
        self::assertFileDoesNotExist("$this->dir/site/octocov-index.md");
    }

    public function testSiteIndexRejectsInvalidSourceRun(): void
    {
        $this->file('site/index.md', '# Octocov');
        $this->json('downloads/metadata.json', ['source_commit_sha' => str_repeat('a',40), 'source_run_id' => 0]);
        $this->expectException(RuntimeException::class);
        Build::siteIndex($this->dir);
    }

    public function testJekyllSiteUsesCustomDomainRoot(): void
    {
        Build::jekyllConfig($this->dir);
        self::assertSame("url: \"https://coverage.libresign.coop\"\nbaseurl: \"\"\n",
            file_get_contents("$this->dir/site/_config.yml"));
        $this->expectException(RuntimeException::class);
        Build::jekyllConfig($this->dir);
    }

    public function testPublicAssetsAreCopiedAndValidated(): void
    {
        $this->file('_site/index.html',
            '<html><link rel="canonical" href="https://coverage.libresign.coop/"></html>');
        $this->file('_site/libresign/index.html', '<html>PHPUnit</html>');
        $this->file('_site/libresign/dashboard.html', '<html>Dashboard</html>');
        $this->file('_site/assets/css/style.css', 'body{}');
        foreach ([
            '_css/bootstrap.min.css', '_css/style.css',
            '_js/jquery.min.js', '_icons/file-directory.svg',
        ] as $file) {
            $this->file("site/libresign/$file", 'fixture');
        }
        Build::stagePublic($this->dir);
        self::assertFileExists("$this->dir/public/libresign/_css/style.css");
        self::assertStringContainsString('coverage.libresign.coop',
            file_get_contents("$this->dir/public/index.html"));
    }

    public function testPublicAssetValidationRejectsOldProjectBaseUrl(): void
    {
        $this->file('_site/index.html',
            '<html><link href="/coverage/assets/theme.css">https://coverage.libresign.coop/</html>');
        $this->file('_site/libresign/index.html', 'report');
        $this->file('_site/libresign/dashboard.html', 'dashboard');
        $this->file('_site/assets/css/style.css', 'css');
        foreach (['_css/bootstrap.min.css','_css/style.css','_js/jquery.min.js','_icons/file-directory.svg'] as $asset) {
            $this->file("site/libresign/$asset", 'test');
        }
        $this->expectException(RuntimeException::class);
        Build::stagePublic($this->dir);
    }

    public function testFallbackArchiveRestorationVerifiesChecksums(): void
    {
        $this->file('downloads/metadata.json', '{"source_commit_sha":"'.str_repeat('a',40).'"}');
        Support::gzipWrite("$this->dir/downloads/libresign-octocov.json.gz", '{"repository":"LibreSign/libresign"}');
        Support::gzipWrite("$this->dir/downloads/libresign-clover.xml.gz", '<clover/>');
        $tarFile = "$this->dir/downloads/libresign-html.tar";
        $tar = new PharData($tarFile);
        $this->file('origin/index.html', '<html>Restored PHPUnit</html>');
        $tar->addFile("$this->dir/origin/index.html", 'index.html');
        $tar->compress(Phar::GZ);
        unset($tar);
        unlink($tarFile);
        $checksums = [];
        foreach (['metadata.json', 'libresign-octocov.json.gz',
                 'libresign-clover.xml.gz', 'libresign-html.tar.gz'] as $file) {
            $checksums[] = hash_file('sha256', "$this->dir/downloads/$file") . "  $file";
        }
        $this->file('downloads/checksums.sha256', implode("\n", $checksums)."\n");
        // Phar caches freshly compressed archives within one PHP process.
        // CI restoration runs in a separate step, so test that actual boundary.
        $script = 'require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true)
            . '; LibreSign\\Coverage\\Build::restoreFallback($argv[1]);';
        $cmd = [PHP_BINARY, '-r', $script, $this->dir];
        $process = proc_open($cmd, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stdout . $stderr);
        self::assertSame('<html>Restored PHPUnit</html>',
            file_get_contents("$this->dir/site/libresign/index.html"));
        self::assertSame('<clover/>', file_get_contents("$this->dir/site/libresign/clover.xml"));
        self::assertStringContainsString('LibreSign/libresign', file_get_contents(
            "$this->dir/reports/LibreSign/libresign/report.json"));
    }

    public function testFallbackRejectsUntrustedChecksum(): void
    {
        $this->file('downloads/checksums.sha256', hash('sha256', 'never') . '  file.gz' . "\n");
        $this->file('downloads/file.gz', 'wrong');
        $this->expectException(RuntimeException::class);
        Build::restoreFallback($this->dir);
    }

    public function testMaintainerGuideDeclaresCurrentDomain(): void
    {
        $docs = file_get_contents(dirname(__DIR__) . '/docs/maintainers.md');
        $readme = file_get_contents(dirname(__DIR__) . '/README.md');
        self::assertStringContainsString('https://coverage.libresign.coop/', $docs);
        self::assertStringContainsString('empty `baseurl`', $docs);
        self::assertStringContainsString('https://coverage.libresign.coop/', $readme);
        self::assertStringNotContainsString('https://libresign.github.io/coverage/', $readme);
    }
}
