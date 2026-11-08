<?php
// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Coverage\Tests;

use LibreSign\Coverage\Site;
use LibreSign\Coverage\Support;
use RuntimeException;

final class SiteTest extends Fixtures
{
    private const REPOSITORY_TABLE = <<<'HTML'
<table><thead><tr><th>Repository</th><th>Coverage</th>
<th>Code to Test Ratio</th><th>Time Execution Time</th><th>Badges</th></tr></thead>
<tbody><tr><td><a href="https://github.com/LibreSign/libresign">LibreSign/libresign</a></td>
<td>76.0%</td><td>-</td><td>-</td>
<td><img src="badges/x.svg" alt="badge" /> <details>&lt;summary&gt;Copy status badge markdown
&lt;/summary&gt;<code>Copy</code></details></td></tr></tbody></table>
HTML;

    private function root(): string
    {
        return dirname(__DIR__);
    }

    private function themeSetup(): array
    {
        $this->json('metadata.json', ['source_commit_sha' => str_repeat('a', 40)]);
        $files = ['public/index.html', 'public/libresign/index.html',
            'public/libresign/dashboard.html', 'public/libresign/Service/Example.php.html'];
        foreach ($files as $file) {
            $this->file($file, '<html><head><title>Generated</title></head><body>Coverage</body></html>');
        }
        return $files;
    }

    public function testThemeAddsCorrectRelativeAssetsAtEveryDepth(): void
    {
        $files = $this->themeSetup();
        self::assertSame(4, Site::theme("$this->dir/public", "$this->dir/metadata.json", $this->root()));
        foreach ($files as $file) {
            $html = (string)file_get_contents("$this->dir/$file");
            self::assertStringContainsString(Site::THEME_MARKER, $html);
            self::assertStringContainsString('content="' . str_repeat('a', 40) . '"', $html);
            preg_match_all('/(?:href|src)="([^"]*theme\.(?:css|js))"/', $html, $matches);
            self::assertCount(2, $matches[1], $file);
            foreach ($matches[1] as $asset) {
                self::assertFileExists(dirname("$this->dir/$file") . '/' . $asset);
            }
        }
    }

    public function testThemeIsIdempotent(): void
    {
        $files = $this->themeSetup();
        Site::theme("$this->dir/public", "$this->dir/metadata.json", $this->root());
        $original = array_map(fn($f) => file_get_contents("$this->dir/$f"), $files);
        Site::theme("$this->dir/public", "$this->dir/metadata.json", $this->root());
        self::assertSame($original, array_map(fn($f) => file_get_contents("$this->dir/$f"), $files));
    }

    public function testThemeRejectsInvalidCommit(): void
    {
        $this->themeSetup();
        $this->json('metadata.json', ['source_commit_sha' => '../../etc']);
        $this->expectException(RuntimeException::class);
        Site::theme("$this->dir/public", "$this->dir/metadata.json", $this->root());
    }

    public function testThemeRejectsBrokenGeneratedPage(): void
    {
        $this->themeSetup();
        $this->file('public/libresign/Service/Example.php.html', '<html><body>Missing head</body></html>');
        $this->expectException(RuntimeException::class);
        Site::theme("$this->dir/public", "$this->dir/metadata.json", $this->root());
    }

    public function testThemeRequiresOriginalPhpunitReport(): void
    {
        $this->themeSetup();
        unlink("$this->dir/public/libresign/index.html");
        $this->expectException(RuntimeException::class);
        Site::theme("$this->dir/public", "$this->dir/metadata.json", $this->root());
    }

    private function trendSetup(): void
    {
        $this->file('public/index.html',
            '<html><body><!-- coverage-trend-slot --><h2 id="repositories">Repositories</h2></body></html>');
        $this->json('public/assets/coverage-history.json', ['schema'=>1,'repository'=>'LibreSign/libresign']);
    }

    public function testTrendInjectsChartWithoutChangingOctocov(): void
    {
        $this->trendSetup();
        Site::trendPage("$this->dir/public", $this->root());
        $html = (string)file_get_contents("$this->dir/public/index.html");
        self::assertStringContainsString(Site::TREND_MARKER, $html);
        self::assertStringContainsString('id="coverage-trend"', $html);
        self::assertSame(1, substr_count($html, '<h2 id="repositories">Repositories</h2>'));
        self::assertMatchesRegularExpression('~assets/coverage-theme/trend\.js\?v=[0-9a-f]{12}~', $html);
        self::assertMatchesRegularExpression('~assets/coverage-theme/trend\.css\?v=[0-9a-f]{12}~', $html);
        self::assertStringContainsString('Total coverage over time', $html);
        self::assertStringContainsString('id="trend-period"', $html);
        self::assertStringNotContainsString('id="trend-source"', $html);
        self::assertStringContainsString('>Report</th>', $html);
        self::assertFileExists("$this->dir/public/assets/coverage-theme/trend.js");
    }

    public function testTrendIsIdempotent(): void
    {
        $this->trendSetup();
        Site::trendPage("$this->dir/public", $this->root());
        $before = file_get_contents("$this->dir/public/index.html");
        Site::trendPage("$this->dir/public", $this->root());
        self::assertSame($before, file_get_contents("$this->dir/public/index.html"));
    }

    public function testTrendRequiresDatasetAndInsertionPoint(): void
    {
        $this->trendSetup();
        $this->file('public/index.html', '<html>No slot</html>');
        $this->expectException(RuntimeException::class);
        Site::trendPage("$this->dir/public", $this->root());
    }

    public function testTrendCssHasNoHeightCapAndDotsAreKeyboardLinks(): void
    {
        $css = file_get_contents($this->root() . '/site-theme/trend.css');
        $js = file_get_contents($this->root() . '/site-theme/trend.js');
        self::assertMatchesRegularExpression('/\.coverage-chart\s*\{[^}]*\bwidth:\s*100%[^}]*height:\s*auto/s', $css);
        self::assertStringNotContainsString('max-height: 340px', $css);
        self::assertStringContainsString("node('a', {href: report.href", $js);
        self::assertStringContainsString("chart.setAttribute('role', 'group')", $js);
    }

    private function dashboardSetup(): void
    {
        $this->json('public/assets/coverage-summary.json', [
            'schema'=>1, 'repository'=>'LibreSign/libresign',
            'commit'=>str_repeat('a',40), 'source_branch'=>'feature/pr',
            'run_id'=>1234, 'preview'=>true, 'measured_at'=>'2026-11-08T00:07:10Z',
            'runtime'=>'PHP 8.5 / Nextcloud master',
            'covered'=>21, 'total'=>30, 'uncovered'=>9, 'files_count'=>11,
        ]);
        $this->file('public/index.html', '<html><head></head><body><h1>Home</h1>' .
            Site::TREND_MARKER . '<section id="coverage-trend">Example</section>' .
            '<h2 id="repositories">Repositories</h2>' . self::REPOSITORY_TABLE .
            '<footer><a href="https://github.com/LibreSign/coverage/edit/main/index.md">Improve this page.</a></footer>' .
            '</body></html>');
    }

    public function testDashboardRendersProvenanceAndKeepsBadges(): void
    {
        $this->dashboardSetup();
        Site::dashboard("$this->dir/public", $this->root());
        $page = file_get_contents("$this->dir/public/index.html");
        foreach (['<body class="coverage-home">','70.0%','Uncovered lines',
            'Files to review', 'Coverage color legend', 'High (80% or more)',
            'PR snapshot · not a main-branch baseline', 'CI run #1234',
            'assets/coverage-theme/overview.js?v=', 'assets/coverage-theme/overview.css?v=',
            '<th scope="col">Coverage</th>', 'Publishing workflow', 'badges/x.svg',
            'href="libresign/"'] as $fragment) {
            self::assertStringContainsString($fragment, $page);
        }
        foreach (['Time Execution Time','Code to Test Ratio','Copy status badge markdown',
            'edit/main/index.md'] as $fragment) {
            self::assertStringNotContainsString($fragment, $page);
        }
    }

    public function testDashboardPreservesNonEmptyOctocovColumns(): void
    {
        $markup = str_replace('<td>-</td><td>-</td>', '<td>1:0.5</td><td>4m12s</td>', self::REPOSITORY_TABLE);
        $html = Site::simplifyTable($markup);
        self::assertStringContainsString('Code / test ratio', $html);
        self::assertStringContainsString('Test duration', $html);
        self::assertStringContainsString('4m12s', $html);
    }

    public function testDashboardIsIdempotent(): void
    {
        $this->dashboardSetup();
        Site::dashboard("$this->dir/public", $this->root());
        $original = file_get_contents("$this->dir/public/index.html");
        Site::dashboard("$this->dir/public", $this->root());
        self::assertSame($original, file_get_contents("$this->dir/public/index.html"));
    }

    public function testDashboardRejectsMissingOctocovTable(): void
    {
        $this->dashboardSetup();
        $this->file('public/index.html', '<html>' . Site::TREND_MARKER .
            '<h2 id="repositories">Repositories</h2></html>');
        $this->expectException(RuntimeException::class);
        Site::dashboard("$this->dir/public", $this->root());
    }

    public function testDashboardRejectsMissingSummary(): void
    {
        $this->dashboardSetup();
        unlink("$this->dir/public/assets/coverage-summary.json");
        $this->expectException(RuntimeException::class);
        Site::dashboard("$this->dir/public", $this->root());
    }
}
