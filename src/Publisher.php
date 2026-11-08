<?php
// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Coverage;

use RuntimeException;

final class Publisher
{
    public static function archive(GitHub $gh, string $root, array $env): void
    {
        if (empty($env['GH_TOKEN']) || ($env['GITHUB_REPOSITORY'] ?? null) !== Support::PUBLISHER) {
            throw new RuntimeException('Archiving requires a token and the LibreSign/coverage repository');
        }
        foreach ([
            'downloads/native/report.json',
            'downloads/phpunit/clover.xml',
            'downloads/metadata.json',
            'site/libresign/index.html',
            'site/libresign/dashboard.html',
        ] as $file) {
            Support::required("$root/$file");
        }
        $metadata = Support::data("$root/downloads/metadata.json");
        $sha = $metadata['source_commit_sha'] ?? null;
        $run = $metadata['source_run_id'] ?? null;
        $attempt = $metadata['source_run_attempt'] ?? null;
        if (!Support::sha($sha) || !Support::positive($run) || !Support::positive($attempt)) {
            throw new RuntimeException('Invalid main-run identity');
        }
        // Never trust a dispatch payload or historical file without rebuilding
        // metadata from the actual native Octocov report.
        $reported = Support::data("$root/downloads/native/report.json");
        $verified = SourceCoverage::metadata($reported, $sha, $run, $attempt);
        if ($verified !== $metadata) {
            throw new RuntimeException('Coverage report metadata changed between preparation and archive');
        }
        $snapshot = "$root/coverage-snapshot";
        Support::mkdir($snapshot);
        Support::write("$snapshot/metadata.json", $verified, true);
        Support::gzipWrite("$snapshot/libresign-clover.xml.gz", (string)file_get_contents("$root/downloads/phpunit/clover.xml"));
        Support::gzipWrite("$snapshot/libresign-octocov.json.gz", (string)file_get_contents("$root/downloads/native/report.json"));
        self::tarReport("$root/site/libresign", "$snapshot/libresign-html.tar.gz");
        $assets = [
            'libresign-html.tar.gz',
            'libresign-clover.xml.gz',
            'libresign-octocov.json.gz',
            'metadata.json',
        ];
        self::checksums($snapshot, $assets, "$snapshot/checksums.sha256");
        self::verifyChecksums("$snapshot/checksums.sha256", $snapshot);

        $month = gmdate('Y-m');
        $tag = "coverage-history-$month";
        $key = "$sha-run-$run-attempt-$attempt";
        $history = "$root/coverage-history";
        Support::mkdir($history);
        $stored = [
            "libresign-$key-octocov.json.gz" => "$snapshot/libresign-octocov.json.gz",
            "libresign-$key-clover.xml.gz" => "$snapshot/libresign-clover.xml.gz",
            "libresign-$key-metadata.json" => "$snapshot/metadata.json",
        ];
        foreach ($stored as $name => $file) {
            if (!copy($file, "$history/$name")) {
                throw new RuntimeException("Cannot archive $name");
            }
        }
        self::checksums($history, array_keys($stored), "$history/libresign-$key-checksums.sha256");

        if ($gh->tryCall(['release', 'view', $tag, '--repo', Support::PUBLISHER]) === null) {
            if ($gh->tryCall([
                'release', 'create', $tag, '--repo', Support::PUBLISHER, '--target', 'main',
                '--title', "LibreSign coverage archive $month",
                '--notes', 'Immutable-by-policy, per-run Octocov and Clover measurements. HTML remains available from the latest publication.',
            ]) === null && $gh->tryCall(['release', 'view', $tag, '--repo', Support::PUBLISHER]) === null) {
                throw new RuntimeException('Unable to create or locate monthly coverage archive release');
            }
        }
        $release = Support::decode($gh->call(['api', 'repos/' . Support::PUBLISHER . "/releases/tags/$tag"]));
        $releaseId = $release['id'] ?? null;
        if (!Support::positive($releaseId)) {
            throw new RuntimeException('Invalid coverage release ID');
        }
        $listing = $gh->call([
            'api', '--paginate', 'repos/' . Support::PUBLISHER . "/releases/$releaseId/assets?per_page=100",
            '--jq', '.[] | [.name, (.digest // "")] | @tsv',
        ]);
        $existing = [];
        foreach (explode("\n", trim($listing)) as $line) {
            if ($line !== '') {
                [$name, $digest] = array_pad(explode("\t", $line, 2), 2, '');
                if (array_key_exists($name, $existing)) {
                    throw new RuntimeException("Duplicate release asset: $name");
                }
                $existing[$name] = $digest;
            }
        }
        foreach (glob("$history/*") ?: [] as $file) {
            $name = basename($file);
            $expected = 'sha256:' . hash_file('sha256', $file);
            if (array_key_exists($name, $existing)) {
                if ($existing[$name] !== $expected) {
                    throw new RuntimeException("Conflicting archived coverage asset: $name");
                }
                continue;
            }
            $gh->call(['release', 'upload', $tag, $file, '--repo', Support::PUBLISHER]);
        }
        if (($env['ARCHIVE_CURRENT_FALLBACK'] ?? 'true') !== 'false') {
            $args = ['release', 'upload', 'coverage-latest'];
            foreach (array_merge($assets, ['checksums.sha256']) as $name) {
                $args[] = "$snapshot/$name";
            }
            $gh->call(array_merge($args, ['--repo', Support::PUBLISHER, '--clobber']));
            $gh->call(['release', 'edit', 'coverage-latest', '--repo', Support::PUBLISHER,
                '--title', 'Latest verified LibreSign coverage snapshot',
                '--notes', "Fallback HTML from LibreSign/libresign main commit $sha, run $run."]);
        }
    }

    public static function history(GitHub $gh, string $root, array $env): void
    {
        if (empty($env['GH_TOKEN']) || ($env['GITHUB_REPOSITORY'] ?? null) !== Support::PUBLISHER) {
            throw new RuntimeException('History requires a token and the LibreSign/coverage repository');
        }
        $legacy = "$root/downloads/history/coveralls";
        $octocov = "$root/downloads/history/octocov";
        Support::mkdir($legacy);
        Support::mkdir($octocov);
        $gh->call(['release', 'download', 'coveralls-archive-2026-11-07', '--repo', Support::PUBLISHER, '--dir', $legacy]);
        self::verifyChecksums("$legacy/coveralls-archive.sha256", $legacy);
        $tags = Support::decode($gh->call([
            'release', 'list', '--repo', Support::PUBLISHER, '--limit', '500', '--json', 'tagName',
        ]));
        foreach ($tags as $item) {
            $tag = $item['tagName'] ?? '';
            if (!str_starts_with($tag, 'coverage-history-')) {
                continue;
            }
            if (preg_match('/^coverage-history-[0-9]{4}-[0-9]{2}$/D', $tag) !== 1) {
                throw new RuntimeException('Unexpected coverage history release tag');
            }
            $details = Support::decode($gh->call(['release', 'view', $tag, '--repo', Support::PUBLISHER, '--json', 'assets']));
            $names = array_column($details['assets'] ?? [], 'name');
            if (count(array_filter($names, static fn ($name) =>
                preg_match('/^libresign-[0-9a-f]{40}-run-[0-9]+-attempt-[0-9]+-metadata\.json$/D', $name) === 1
            )) > 0) {
                $dir = "$octocov/$tag";
                Support::mkdir($dir);
                $gh->call(['release', 'download', $tag, '--repo', Support::PUBLISHER,
                    '--pattern', 'libresign-*-metadata.json', '--dir', $dir]);
            }
        }
        $payload = CoverageHistory::build("$legacy/coveralls-history.json.gz", $octocov);
        Support::write("$root/site/assets/coverage-history.json", $payload);
    }

    private static function tarReport(string $source, string $out): void
    {
        if (!is_dir($source)) {
            throw new RuntimeException('Missing PHPUnit report for archiving');
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        ) as $file) {
            if ($file->isLink()) {
                throw new RuntimeException('Refusing symlink in PHPUnit report');
            }
        }
        if (is_file($out)) {
            unlink($out);
        }
        // GNU tar streams large PHPUnit HTML reports without exhausting
        // PHP's memory limit. The orchestration, checksums and policy remain PHP.
        Support::command(['tar', '-C', $source, '-czf', $out, '.']);
        Support::required($out);
    }

    /** @param list<string> $files */
    private static function checksums(string $base, array $files, string $out): void
    {
        $lines = [];
        foreach ($files as $name) {
            $file = "$base/$name";
            Support::required($file);
            $lines[] = hash_file('sha256', $file) . "  $name";
        }
        Support::put($out, implode("\n", $lines) . "\n");
    }

    public static function verifyChecksums(string $checksums, string $root): void
    {
        Support::required($checksums);
        foreach (explode("\n", trim((string)file_get_contents($checksums))) as $line) {
            if (!preg_match('/^([0-9a-f]{64})  ([A-Za-z0-9._-]+)$/D', $line, $match)) {
                throw new RuntimeException('Invalid checksum manifest');
            }
            $file = "$root/$match[2]";
            Support::required($file);
            if (!hash_equals($match[1], hash_file('sha256', $file))) {
                throw new RuntimeException("Checksum mismatch: {$match[2]}");
            }
        }
    }
}
