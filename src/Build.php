<?php
// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Coverage;

use RuntimeException;

final class Build
{
    public static function restoreFallback(string $root): void
    {
        $downloads = "$root/downloads";
        Publisher::verifyChecksums("$downloads/checksums.sha256", $downloads);
        Support::required("$downloads/metadata.json");
        $json = Support::gzipRead("$downloads/libresign-octocov.json.gz");
        Support::put("$root/reports/LibreSign/libresign/report.json", $json);
        Support::put("$root/site/libresign/clover.xml", Support::gzipRead("$downloads/libresign-clover.xml.gz"));
        Support::required("$downloads/libresign-html.tar.gz");
        $archive = "$downloads/libresign-html.tar.gz";
        $target = "$root/site/libresign";
        Support::mkdir($target);
        // Reject traversal and links even when the archive comes from a
        // checksummed release. GNU tar streams the full report at bounded RAM.
        $paths = Support::command(['tar', '-tzf', $archive]);
        foreach (explode("\n", trim($paths)) as $entry) {
            $relative = preg_replace('~^(\./)+~', '', $entry);
            if ($relative === '' || $relative === '.' || $relative === './') {
                continue;
            }
            if (str_starts_with($relative, '/') || str_contains($relative, '\\') ||
                in_array('..', explode('/', $relative), true)) {
                throw new RuntimeException('Unsafe coverage HTML archive path');
            }
        }
        $verbose = Support::command(['tar', '-tvzf', $archive]);
        foreach (explode("\n", trim($verbose)) as $entry) {
            if ($entry !== '' && in_array($entry[0], ['l', 'h'], true)) {
                throw new RuntimeException('Refusing link in PHPUnit archive');
            }
        }
        Support::command(['tar', '-xzf', $archive, '-C', $target,
            '--no-same-owner', '--no-same-permissions']);
        Support::required("$root/site/libresign/index.html");
    }

    public static function siteIndex(string $root): void
    {
        $site = "$root/site";
        Support::required("$site/index.md");
        if (!rename("$site/index.md", "$site/octocov-index.md")) {
            throw new RuntimeException('Cannot stage Octocov index');
        }
        $index = (string)file_get_contents("$site/octocov-index.md");
        $index = str_replace(
            'https://raw.githubusercontent.com/LibreSign/coverage/main/site/badges/',
            'badges/',
            $index
        );
        $meta = Support::data("$root/downloads/metadata.json");
        if (!Support::sha($meta['source_commit_sha'] ?? null) || !Support::positive($meta['source_run_id'] ?? null)) {
            throw new RuntimeException('Coverage snapshot has invalid source identity');
        }
        $prefix = implode("\n", [
            '---',
            'title: LibreSign Coverage',
            '---',
            '# LibreSign code coverage',
            '',
            '[Browse coverage by file](./libresign/) · [Application code](https://github.com/LibreSign/libresign) · [Website code](https://github.com/LibreSign/coverage)',
            '',
            '<!-- coverage-trend-slot -->',
            '',
        ]);
        Support::put("$site/index.md", $prefix . "\n" . $index);
        unlink("$site/octocov-index.md");
    }

    public static function jekyllConfig(string $root): void
    {
        $config = "$root/site/_config.yml";
        if (file_exists($config)) {
            throw new RuntimeException('Unexpected upstream Jekyll config; review before replacing');
        }
        Support::put($config, "url: \"https://coverage.libresign.coop\"\nbaseurl: \"\"\n");
    }

    public static function stagePublic(string $root): void
    {
        $rendered = "$root/_site";
        Support::required("$rendered/index.html");
        $public = "$root/public";
        Support::copyTree($rendered, $public);
        foreach (['_css', '_js', '_icons'] as $dir) {
            $source = "$root/site/libresign/$dir";
            if (!is_dir($source)) {
                throw new RuntimeException("Missing PHPUnit asset directory: $dir");
            }
            Support::copyTree($source, "$public/libresign/$dir");
        }
        foreach ([
            'libresign/_css/bootstrap.min.css',
            'libresign/_css/style.css',
            'libresign/_js/jquery.min.js',
            'libresign/_icons/file-directory.svg',
            'index.html', 'libresign/index.html', 'libresign/dashboard.html',
            'assets/css/style.css',
        ] as $file) {
            Support::required("$public/$file");
        }
        $html = (string)file_get_contents("$public/index.html");
        if (!str_contains($html, 'https://coverage.libresign.coop/') || str_contains($html, '/coverage/assets/')) {
            throw new RuntimeException('Generated Jekyll site has wrong canonical domain or obsolete asset paths');
        }
    }
}
