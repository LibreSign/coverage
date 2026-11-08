<?php
// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Coverage;

use DateTimeImmutable;
use DateTimeZone;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class Support
{
    public const SOURCE = 'LibreSign/libresign';
    public const PUBLISHER = 'LibreSign/coverage';

    public static function data(string $file): array
    {
        $raw = @file_get_contents($file);
        if ($raw === false) {
            throw new RuntimeException("Cannot read file: $file");
        }
        return self::decode($raw);
    }

    public static function decode(string $json): array
    {
        $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new RuntimeException('Expected a JSON object or array');
        }
        return $value;
    }

    public static function write(string $file, array $value, bool $pretty = false): void
    {
        self::mkdir(dirname($file));
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $encoded = json_encode($value, $flags);
        if ($pretty) {
            // Match the previous native metadata format (Python indent=2).
            // This keeps archived content hashes stable across the migration.
            $encoded = preg_replace_callback('/^ +/m',
                static fn(array $match): string => str_repeat(' ', intdiv(strlen($match[0]), 2)),
                $encoded
            );
        }
        self::put($file, $encoded . "\n");
    }

    public static function put(string $file, string $body): void
    {
        self::mkdir(dirname($file));
        if (file_put_contents($file, $body) === false) {
            throw new RuntimeException("Cannot write file: $file");
        }
    }

    public static function mkdir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create directory: $dir");
        }
    }

    public static function required(string $file): void
    {
        if (!is_file($file) || filesize($file) === 0) {
            throw new RuntimeException("Missing or empty file: $file");
        }
    }

    public static function sha(mixed $sha): bool
    {
        return is_string($sha) && preg_match('/^[0-9a-f]{40}$/D', $sha) === 1;
    }

    public static function positive(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }

    public static function utc(string $value, bool $withTime = true): string
    {
        if (preg_match('/(?:Z|[+-][0-9]{2}:[0-9]{2})$/D', $value) !== 1) {
            throw new RuntimeException('Coverage timestamp must contain timezone');
        }
        try {
            $date = new DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new RuntimeException('Invalid coverage timestamp', 0, $e);
        }
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    public static function html(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    public static function copyTree(string $source, string $target): void
    {
        if (!is_dir($source)) {
            throw new RuntimeException("Missing directory: $source");
        }
        self::mkdir($target);
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        ) as $entry) {
            if ($entry->isLink()) {
                throw new RuntimeException("Refusing symlink in report: " . $entry->getPathname());
            }
            $relative = substr($entry->getPathname(), strlen($source) + 1);
            $to = $target . '/' . $relative;
            if ($entry->isDir()) {
                self::mkdir($to);
            } elseif (!copy($entry->getPathname(), $to)) {
                throw new RuntimeException("Cannot copy report: $relative");
            }
        }
    }

    /** @return list<string> */
    public static function matchingFiles(string $root, string $regex): array
    {
        if (!is_dir($root)) {
            return [];
        }
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $entry) {
            if ($entry->isFile() && preg_match($regex, basename($entry->getPathname()))) {
                $files[] = $entry->getPathname();
            }
        }
        sort($files, SORT_STRING);
        return $files;
    }

    public static function replaceOnce(string $content, string $needle, string $replacement): string
    {
        if (substr_count($content, $needle) !== 1) {
            throw new RuntimeException("Unexpected count for generated HTML marker: $needle");
        }
        return str_replace($needle, $replacement, $content);
    }

    public static function option(array $args, string $key, ?string $default = null): ?string
    {
        $index = array_search('--' . $key, $args, true);
        if ($index === false) {
            return $default;
        }
        if (!isset($args[$index + 1])) {
            throw new RuntimeException("Missing --$key value");
        }
        return $args[$index + 1];
    }

    public static function gzipRead(string $file): string
    {
        self::required($file);
        $text = @gzdecode((string) file_get_contents($file));
        if ($text === false) {
            throw new RuntimeException("Invalid gzip: $file");
        }
        return $text;
    }

    public static function gzipWrite(string $file, string $text): void
    {
        $compressed = gzencode($text, 9, FORCE_GZIP);
        if ($compressed === false) {
            throw new RuntimeException("Cannot compress: $file");
        }
        self::put($file, $compressed);
    }

    /**
     * Execute a trusted executable with argv, never through a shell.
     * Long-running archive operations can stream in the operating system.
     * @param list<string> $argv
     */
    public static function command(array $argv): string
    {
        $process = proc_open($argv, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start external tool: ' . ($argv[0] ?? ''));
        }
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('External command failed: ' .
                implode(' ', array_map('escapeshellarg', $argv)) . ': ' . trim($err));
        }
        return (string)$out;
    }

    public static function readJsonOrGzip(string $file): array
    {
        return self::decode(str_ends_with($file, '.gz') ? self::gzipRead($file) : (string)file_get_contents($file));
    }
}
