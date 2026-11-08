<?php
// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/** @var array<string, mixed> $summary */
/** @var string $commit */
/** @var int $run */
/** @var string $date */
/** @var string $badge */
/** @var string $label */
use LibreSign\Coverage\Support;

$covered = $summary['covered'];
$total = $summary['total'];
$missing = $summary['uncovered'];
$percent = $covered * 100 / $total;
$runtime = $summary['runtime'] ?? null;
?>
<section id="coverage-overview" aria-labelledby="coverage-overview-title">
      <div class="coverage-overview-heading">
        <h2 id="coverage-overview-title">Latest report</h2><?= $badge ?>
      </div>
      <div class="coverage-kpis">
        <div class="coverage-kpi coverage-kpi-primary">
          <span class="coverage-kpi-label">Line coverage</span>
          <strong class="coverage-kpi-value"><?= number_format($percent, 1, '.', '') ?>%</strong>
          <span class="coverage-kpi-caption"><?= Support::html($label) ?></span>
          <span class="coverage-kpi-track" aria-hidden="true">
            <span style="width:<?= number_format($percent, 2, '.', '') ?>%"></span>
          </span>
        </div>
        <div class="coverage-kpi">
          <span class="coverage-kpi-label">Covered lines</span>
          <strong class="coverage-kpi-value"><?= number_format($covered) ?></strong>
        </div>
        <div class="coverage-kpi">
          <span class="coverage-kpi-label">Uncovered lines</span>
          <strong class="coverage-kpi-value"><?= number_format($missing) ?></strong>
        </div>
        <div class="coverage-kpi">
          <span class="coverage-kpi-label">Files analyzed</span>
          <strong class="coverage-kpi-value"><?= number_format($summary['files_count']) ?></strong>
        </div>
      </div>
      <p class="coverage-overview-meta">
        <time datetime="<?= Support::html($summary['measured_at']) ?>"><?= Support::html($date) ?></time>
        <?= $runtime !== null && $runtime !== '' ? '<span>' . Support::html($runtime) . '</span>' : '' ?>
        <a href="https://github.com/LibreSign/libresign/commit/<?= $commit ?>">Commit <?= substr($commit, 0, 9) ?></a>
        <a href="https://github.com/LibreSign/libresign/actions/runs/<?= $run ?>">CI run #<?= $run ?></a>
      </p>
    </section>
