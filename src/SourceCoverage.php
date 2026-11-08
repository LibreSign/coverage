<?php
// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Coverage;

use RuntimeException;

final class SourceCoverage
{
    /** Select the same canonical PHPUnit matrix as LibreSign: master, highest PHP version. */
    public static function select(array $artifacts): ?string
    {
        $candidates = [];
        foreach ($artifacts as $artifact) {
            if (!is_array($artifact) || ($artifact['expired'] ?? false) !== false) {
                continue;
            }
            $name = $artifact['name'] ?? null;
            if (!is_string($name) || preg_match('/^phpunit-coverage-php-([0-9]+(?:\.[0-9]+)*)-nextcloud-(master|[A-Za-z0-9._-]+)$/D', $name, $match) !== 1) {
                continue;
            }
            $candidates[] = ['name' => $name, 'version' => $match[1], 'master' => $match[2] === 'master'];
        }
        usort($candidates, static fn (array $a, array $b): int =>
            ($b['master'] <=> $a['master']) ?: version_compare($b['version'], $a['version']) ?: strcmp($b['name'], $a['name'])
        );
        return $candidates[0]['name'] ?? null;
    }

    public static function complete(array $artifacts): ?string
    {
        foreach ($artifacts as $artifact) {
            if (($artifact['name'] ?? '') === 'octocov-report' && ($artifact['expired'] ?? true) === false) {
                return self::select($artifacts);
            }
        }
        return null;
    }

    public static function verify(array $report, string $commit): void
    {
        if (!Support::sha($commit)) {
            throw new RuntimeException('Invalid source SHA');
        }
        if (($report['repository'] ?? null) !== Support::SOURCE) {
            throw new RuntimeException('Unexpected Octocov repository');
        }
        if (!in_array($report['ref'] ?? null, ['main', 'refs/heads/main'], true)) {
            throw new RuntimeException('Unexpected Octocov ref');
        }
        if (($report['commit'] ?? null) !== $commit) {
            throw new RuntimeException('Octocov commit differs from the source report run');
        }
        $coverage = $report['coverage'] ?? null;
        if (!is_array($coverage) || !is_int($coverage['covered'] ?? null) || !is_int($coverage['total'] ?? null)
            || $coverage['total'] <= 0 || $coverage['covered'] < 0 || $coverage['covered'] > $coverage['total']) {
            throw new RuntimeException('Invalid Octocov coverage metrics');
        }
    }

    public static function metadata(array $report, string $commit, int $run, int $attempt): array
    {
        self::verify($report, $commit);
        if ($run <= 0 || $attempt <= 0) {
            throw new RuntimeException('Invalid GitHub Actions run identity');
        }
        if (!is_string($report['timestamp'] ?? null)) {
            throw new RuntimeException('Missing native report timestamp');
        }
        return [
            'repository' => Support::SOURCE,
            'source_branch' => 'main',
            'source_commit_sha' => $commit,
            'source_run_id' => $run,
            'source_run_attempt' => $attempt,
            'recorded_at' => Support::utc($report['timestamp']),
            'covered' => $report['coverage']['covered'],
            'total' => $report['coverage']['total'],
            'preview' => false,
        ];
    }

    /**
     * Returns whether an eligible native main report was downloaded. A pinned
     * dispatch is checked against the GitHub API; payload identity is untrusted.
     * @param array<string, string> $env
     */
    public static function prepare(GitHub $gh, string $root, array $env): bool
    {
        $repo = Support::SOURCE;
        $runId = $env['COVERAGE_SOURCE_RUN_ID'] ?? null;
        $pinned = $runId !== null && $runId !== '';
        Support::mkdir("$root/downloads");
        Support::mkdir("$root/site/libresign");
        Support::mkdir("$root/site/badges");
        if ($pinned) {
            if (($env['COVERAGE_SOURCE_REPOSITORY'] ?? '') !== $repo ||
                preg_match('/^[1-9][0-9]*$/D', $runId) !== 1 ||
                !Support::sha($env['COVERAGE_SOURCE_SHA'] ?? null)) {
                throw new RuntimeException('Invalid dispatched coverage source');
            }
            $run = Support::decode($gh->call(['api', "repos/$repo/actions/runs/$runId"]));
            if (($run['id'] ?? null) !== (int)$runId ||
                ($run['head_sha'] ?? null) !== $env['COVERAGE_SOURCE_SHA'] ||
                ($run['head_repository']['full_name'] ?? null) !== $repo ||
                ($run['head_branch'] ?? null) !== 'main' ||
                ($run['event'] ?? null) !== 'push' ||
                ($run['status'] ?? null) !== 'completed' ||
                ($run['conclusion'] ?? null) !== 'success' ||
                ($run['path'] ?? null) !== '.github/workflows/phpunit-sqlite.yml' ||
                !Support::positive($run['run_attempt'] ?? null)) {
                throw new RuntimeException('Dispatched run is not a verified successful main PHPUnit run');
            }
            $runs = [$run];
        } else {
            $list = Support::decode($gh->call(['api', "repos/$repo/actions/workflows/phpunit-sqlite.yml/runs?branch=main&status=success&per_page=30"]));
            $runs = array_values(array_filter($list['workflow_runs'] ?? [], static fn ($run) =>
                is_array($run) && ($run['event'] ?? null) === 'push'
            ));
        }
        foreach ($runs as $run) {
            $id = $run['id'] ?? null;
            $sha = $run['head_sha'] ?? null;
            $attempt = $run['run_attempt'] ?? null;
            if (!Support::positive($id) || !Support::positive($attempt) || !Support::sha($sha)) {
                throw new RuntimeException('Invalid PHPUnit run identity');
            }
            $response = Support::decode($gh->call(['api', "repos/$repo/actions/runs/$id/artifacts"]));
            $name = self::complete($response['artifacts'] ?? []);
            if ($name === null) {
                if ($pinned) {
                    throw new RuntimeException('The dispatched main run has no complete native coverage artifacts');
                }
                continue;
            }

            Support::mkdir("$root/downloads/native");
            Support::mkdir("$root/downloads/phpunit");
            $gh->call(['run', 'download', (string)$id, '--repo', $repo, '--name', 'octocov-report', '--dir', "$root/downloads/native"]);
            $gh->call(['run', 'download', (string)$id, '--repo', $repo, '--name', $name, '--dir', "$root/downloads/phpunit"]);
            foreach (['downloads/native/report.json', 'downloads/phpunit/clover.xml', 'downloads/phpunit/html-coverage/index.html'] as $file) {
                Support::required("$root/$file");
            }
            $report = Support::data("$root/downloads/native/report.json");
            self::verify($report, $sha);
            Support::copyTree("$root/downloads/phpunit/html-coverage", "$root/site/libresign");
            if (!copy("$root/downloads/phpunit/clover.xml", "$root/site/libresign/clover.xml")) {
                throw new RuntimeException('Cannot copy Clover XML');
            }
            Support::write("$root/downloads/metadata.json", self::metadata($report, $sha, $id, $attempt), true);
            if (isset($env['GITHUB_OUTPUT']) && $env['GITHUB_OUTPUT'] !== '') {
                file_put_contents($env['GITHUB_OUTPUT'], "available=true\nsource_run_id=$id\n", FILE_APPEND);
            }
            return true;
        }
        if (isset($env['GITHUB_OUTPUT']) && $env['GITHUB_OUTPUT'] !== '') {
            file_put_contents($env['GITHUB_OUTPUT'], "available=false\n", FILE_APPEND);
        }
        return false;
    }
}
