<?php
// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Coverage;

interface GitHub
{
    /** @param list<string> $args */
    public function call(array $args): string;
    /** @param list<string> $args */
    public function tryCall(array $args): ?string;
}
