<?php
// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Coverage;

use DateTimeImmutable;
use RuntimeException;

final class Site
{
    public const THEME_MARKER = '<!-- LibreSign coverage navigation and theme -->';
    public const TREND_MARKER = '<!-- LibreSign trend chart -->';
    public const DASHBOARD_MARKER = '<!-- LibreSign coverage overview -->';

    public static function theme(string $public, string $metadata, string $root): int
    {
        $meta = Support::data($metadata);
        $commit = $meta['source_commit_sha'] ?? null;
        if (!Support::sha($commit)) {
            throw new RuntimeException('Coverage metadata must contain a valid commit SHA');
        }
        Support::required("$public/index.html");
        Support::required("$public/libresign/index.html");
        $assets = "$public/assets/coverage-theme";
        Support::mkdir($assets);
        foreach (['theme.css', 'theme.js'] as $name) {
            if (!copy("$root/site-theme/$name", "$assets/$name")) {
                throw new RuntimeException("Cannot publish asset: $name");
            }
        }
        $pages = Support::matchingFiles($public, '/\.html$/D');
        foreach ($pages as $page) {
            $content = (string)file_get_contents($page);
            if (str_contains($content, self::THEME_MARKER)) {
                continue;
            }
            if (!str_contains($content, '</head>')) {
                throw new RuntimeException("Missing </head> in generated page $page");
            }
            $parent = substr(dirname($page), strlen(rtrim($public, '/')));
            $depth = count(array_filter(explode('/', $parent), 'strlen'));
            $prefix = str_repeat('../', $depth) . 'assets/coverage-theme';
            $inject = "\n" . self::THEME_MARKER . "\n"
                . '<meta name="coverage-source-commit" content="' . Support::html($commit) . '">' . "\n"
                . '<link rel="stylesheet" href="' . $prefix . '/theme.css">' . "\n"
                . '<script src="' . $prefix . '/theme.js"></script>' . "\n";
            Support::put($page, str_replace('</head>', $inject . '</head>', $content));
        }
        return count($pages);
    }

    public static function trendPage(string $public, string $root): void
    {
        Support::required("$public/index.html");
        Support::required("$public/assets/coverage-history.json");
        $dest = "$public/assets/coverage-theme";
        Support::mkdir($dest);
        foreach (['trend.js', 'trend.css'] as $name) {
            if (!copy("$root/site-theme/$name", "$dest/$name")) {
                throw new RuntimeException("Cannot publish $name");
            }
        }
        $file = "$public/index.html";
        $content = (string)file_get_contents($file);
        if (str_contains($content, self::TREND_MARKER)) {
            return;
        }
        if (!str_contains($content, '<!-- coverage-trend-slot -->')) {
            throw new RuntimeException('Generated site lacks the coverage chart insertion slot');
        }
        $cssHash = substr(hash_file('sha256', "$root/site-theme/trend.css"), 0, 12);
        $jsHash = substr(hash_file('sha256', "$root/site-theme/trend.js"), 0, 12);
        $widget = (string)file_get_contents("$root/templates/trend.html");
        $markup = self::TREND_MARKER . "\n" . $widget . "\n"
            . "<link rel=\"stylesheet\" href=\"assets/coverage-theme/trend.css?v=$cssHash\">\n"
            . "<script src=\"assets/coverage-theme/trend.js?v=$jsHash\" defer></script>\n";
        Support::put($file, str_replace('<!-- coverage-trend-slot -->', $markup, $content));
    }

    /** Preserve upstream Octocov data, but omit empty columns and copy details. */
    public static function simplifyTable(string $markup, ?float $percent = null): string
    {
        preg_match_all('/<tr>\s*(.*?)\s*<\/tr>/s', $markup, $rows);
        if (count($rows[1]) < 2) {
            throw new RuntimeException('Missing Octocov repository rows');
        }
        preg_match_all('/<th[^>]*>(.*?)<\/th>/s', $rows[1][0], $header);
        $expected = ['Repository', 'Coverage', 'Code to Test Ratio', 'Time Execution Time', 'Badges'];
        $headings = array_map(static fn($v): string => trim(strip_tags($v)), $header[1]);
        if ($headings !== $expected) {
            throw new RuntimeException('Unexpected Octocov repository table format');
        }
        $body = [];
        foreach (array_slice($rows[1], 1) as $row) {
            preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $row, $cells);
            if (count($cells[1]) !== count($expected)) {
                throw new RuntimeException('Unexpected Octocov repository cell count');
            }
            $body[] = $cells[1];
        }
        $visible = [0, 1];
        foreach ([2, 3] as $n) {
            foreach ($body as $cells) {
                if (!in_array(trim(strip_tags($cells[$n])), ['-', '—', ''], true)) {
                    $visible[] = $n;
                    break;
                }
            }
        }
        $labels = [0 => 'Repository', 1 => 'Coverage', 2 => 'Code / test ratio', 3 => 'Test duration'];
        $out = '<table class="coverage-repository-table"><thead><tr>';
        foreach ($visible as $n) {
            $out .= '<th scope="col">' . $labels[$n] . '</th>';
        }
        $out .= '</tr></thead><tbody>';
        foreach ($body as $cells) {
            $out .= '<tr>';
            foreach ($visible as $n) {
                $value = trim($cells[$n]);
                if ($n === 1) {
                    $badge = trim(preg_replace('/\s*<details\b[^>]*>.*?<\/details>/s', '', $cells[4]));
                    if ($badge !== '') {
                        $value = $badge;
                    } elseif ($percent !== null && str_contains($cells[0], Support::SOURCE)) {
                        $value = number_format($percent, 1, '.', '') . '%';
                    }
                }
                if ($n === 0) {
                    $value = preg_replace(
                        '~<a href="https://github.com/LibreSign/libresign">([^<]+)</a>~',
                        '<a href="libresign/">$1</a>',
                        $value,
                        1
                    );
                }
                $out .= '<td>' . $value . '</td>';
            }
            $out .= '</tr>';
        }
        return $out . '</tbody></table>';
    }

    public static function overview(array $summary, string $root): string
    {
        if (($summary['repository'] ?? null) !== Support::SOURCE || ($summary['schema'] ?? null) !== 1 ||
            !Support::sha($summary['commit'] ?? null) || !Support::positive($summary['run_id'] ?? null) ||
            !is_bool($summary['preview'] ?? null) || !is_int($summary['covered'] ?? null) ||
            !is_int($summary['total'] ?? null) || !is_int($summary['uncovered'] ?? null) ||
            $summary['total'] <= 0 || $summary['covered'] < 0 || $summary['covered'] > $summary['total'] ||
            $summary['uncovered'] !== $summary['total'] - $summary['covered']) {
            throw new RuntimeException('Invalid or inconsistent summary for LibreSign homepage');
        }
        $date = (new DateTimeImmutable(Support::utc((string)$summary['measured_at'])))->format('M j, Y');
        $commit = $summary['commit'];
        $run = $summary['run_id'];
        $badge = $summary['preview'] ? '<span class="coverage-preview-badge">Preview</span>'
            : '<span class="coverage-main-badge">main</span>';
        $label = $summary['preview'] ? 'PR snapshot · not a main-branch baseline' : 'Verified main-branch report';
        ob_start();
        try {
            require "$root/templates/overview.php";
            return trim((string)ob_get_clean());
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }

    public static function dashboard(string $public, string $root): void
    {
        Support::required("$public/index.html");
        Support::required("$public/assets/coverage-summary.json");
        $summary = Support::data("$public/assets/coverage-summary.json");
        $file = "$public/index.html";
        $html = (string)file_get_contents($file);
        if (str_contains($html, self::DASHBOARD_MARKER)) {
            return;
        }
        if (substr_count($html, self::TREND_MARKER) !== 1 ||
            substr_count($html, '<h2 id="repositories">Repositories</h2>') !== 1) {
            throw new RuntimeException('Coverage trend insertion point or repository section missing or duplicated');
        }
        if (preg_match('~(<h2 id="repositories">Repositories</h2>\s*)(<table>.*?</table>)~s', $html, $match) !== 1) {
            throw new RuntimeException('Octocov repository table is missing');
        }
        $html = preg_replace_callback(
            '~(<h2 id="repositories">Repositories</h2>\s*)(<table>.*?</table>)~s',
            static fn (array $found): string => $found[1] .
                self::simplifyTable($found[2], $summary['covered'] * 100 / $summary['total']),
            $html,
            1
        );
        if (substr_count($html, '<body>') !== 1) {
            throw new RuntimeException('Expected one Jekyll homepage body');
        }
        $html = str_replace('<body>', '<body class="coverage-home">', $html);
        $html = str_replace(self::TREND_MARKER, self::DASHBOARD_MARKER . self::overview($summary, $root) . "\n" . self::TREND_MARKER, $html);
        $html = str_replace('<h2 id="repositories">Repositories</h2>',
            (string)file_get_contents("$root/templates/explorer.html") . "\n<h2 id=\"repositories\">Repositories</h2>", $html);
        $html = str_replace(
            ['https://github.com/LibreSign/coverage/edit/main/index.md', 'Improve this page'],
            ['https://github.com/LibreSign/coverage/blob/main/.github/workflows/pages.yml', 'Publishing workflow'],
            $html
        );
        $assets = "$public/assets/coverage-theme";
        Support::mkdir($assets);
        foreach (['overview.css','overview.js'] as $name) {
            if (!copy("$root/site-theme/$name", "$assets/$name")) {
                throw new RuntimeException("Cannot copy $name");
            }
        }
        $css = substr(hash_file('sha256', "$root/site-theme/overview.css"), 0, 12);
        $js = substr(hash_file('sha256', "$root/site-theme/overview.js"), 0, 12);
        $html = Support::replaceOnce($html, '</head>', "<link rel=\"stylesheet\" href=\"assets/coverage-theme/overview.css?v=$css\">\n</head>");
        $html = Support::replaceOnce($html, '</body>', "<script src=\"assets/coverage-theme/overview.js?v=$js\" defer></script>\n</body>");
        Support::put($file, $html);
    }
}
