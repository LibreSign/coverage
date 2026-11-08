<?php
// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Coverage\Tests;

use LibreSign\Coverage\GitHub;
use LibreSign\Coverage\Support;
use PHPUnit\Framework\TestCase;
use RuntimeException;

abstract class Fixtures extends TestCase
{
    protected string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/libresign-coverage-php-' . bin2hex(random_bytes(8));
        Support::mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->dir);
    }

    protected static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($dir);
    }

    protected function file(string $path, string $contents): string
    {
        $absolute = $this->dir . '/' . $path;
        Support::put($absolute, $contents);
        return $absolute;
    }

    protected function json(string $path, array $data): string
    {
        Support::write($this->dir . '/' . $path, $data);
        return $this->dir . '/' . $path;
    }
}

final class FakeGithub implements GitHub
{
    /** @var list<array<int, string>> */
    public array $calls = [];
    /** @var callable(list<string>): string */
    public $handler;

    public function __construct(callable $handler)
    {
        $this->handler = $handler;
    }

    public function call(array $args): string
    {
        $this->calls[] = $args;
        return ($this->handler)($args);
    }

    public function tryCall(array $args): ?string
    {
        try {
            return $this->call($args);
        } catch (RuntimeException) {
            return null;
        }
    }
}
