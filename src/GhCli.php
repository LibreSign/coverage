<?php
// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Coverage;

use RuntimeException;

final class GhCli implements GitHub
{
    public function call(array $args): string
    {
        $cmd = array_merge(['gh'], $args);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to execute GitHub CLI');
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) {
            throw new RuntimeException('GitHub CLI failed (' . implode(' ', array_map('escapeshellarg', $cmd)) . '): ' . trim($error));
        }
        return $output;
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
