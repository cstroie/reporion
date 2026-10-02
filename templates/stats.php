<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /stats (Controller\StatsController, roadmap phase 24b) — workload and
 * turnaround over the reports the caller can list (Service\Stats). The
 * filters are switches of plain links, as on the recent changes page
 * (templates/recent.php); each count table has its CSV. Bars are inline
 * SVG, no chart library.
 *
 * Variables in scope: array<string, mixed> $stats (Stats::compute());
 * array{months: int, site: string, modality: string} $filter;
 * list<int> $periods; array<string, string> $sites; list<string> $modalities;
 * string $basePath
 */

declare(strict_types=1);

use Reporion\Service\Stats;

/** @var array<string, mixed> $stats */
/** @var array{months: int, site: string, modality: string} $filter */
/** @var list<int> $periods */
/** @var array<string, string> $sites */
/** @var list<string> $modalities */
/** @var string $basePath */

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$b = $e($basePath);
// This page's URL with some filters changed ('' removes one)
$query = static function (array $change) use ($filter): string {
    $q = array_filter(['months' => $filter['months'] !== 12 ? (string) $filter['months'] : '', 'site' => $filter['site'], 'modality' => $filter['modality']]);
    foreach ($change as $key => $value) {
        if ($value === '') {
            unset($q[$key]);
        } else {
            $q[$key] = $value;
        }
    }

    return $q !== [] ? '?' . http_build_query($q) : '';
};
$opt = static fn (bool $on, string $href, string $label): string => '<a class="seg-opt' . ($on ? ' seg-on' : '') . '" href="' . htmlspecialchars($href, ENT_QUOTES) . '"' . ($on ? ' aria-current="true"' : '') . '>' . htmlspecialchars($label, ENT_QUOTES) . '</a>';
$csv = static fn (string $table): string => $b . '/stats.csv' . htmlspecialchars($query(['table' => $table]), ENT_QUOTES);
// A bar for $n out of $max: inline SVG, the width a percentage
$bar = static function (int $n, int $max, string $class = ''): string {
    $w = $max > 0 ? round(100 * $n / $max, 1) : 0;

    return '<svg class="wk-stat-bar' . ($class !== '' ? ' ' . $class : '') . '" viewBox="0 0 100 8" preserveAspectRatio="none" aria-hidden="true"><rect x="0" y="0" width="' . $w . '" height="8" rx="1"/></svg>';
};
$max = 0;
foreach ($stats['months'] as $row) {
    $max = max($max, $row['exams'], $row['signed']);
}
$groups = [
    'modality' => [t('stats.by_modality'), static fn (string $k): string => $k],
    'site' => [t('stats.by_site'), static fn (string $k): string => $sites[$k] ?? $k],
    'signer' => [t('stats.by_signer'), static fn (string $k): string => display_name($k)],
];
?>
<div class="wk-doc">
<div class="wk-doc-titlerow"><h1 class="wk-doc-title"><?= $e(t('stats.title')) ?></h1></div>
<p class="wk-dim"><?= $e(t('stats.lead')) ?></p>

<nav class="wk-filters" aria-label="<?= $e(t('dash.filters')) ?>">
<span class="seg seg-sm" role="group" aria-label="<?= $e(t('stats.period')) ?>">
<?php foreach ($periods as $months): ?>
<?= $opt($filter['months'] === $months, $basePath . '/stats' . $query(['months' => $months === 12 ? '' : (string) $months]), t('stats.months', [$months])) ?>
<?php endforeach; ?>
</span>
<?php if (count($sites) > 1): ?>
<span class="seg seg-sm" role="group" aria-label="<?= $e(t('stats.site')) ?>">
<?= $opt($filter['site'] === '', $basePath . '/stats' . $query(['site' => '']), t('stats.any_site')) ?>
<?php foreach ($sites as $key => $name): ?>
<?= $opt($filter['site'] === $key, $basePath . '/stats' . $query(['site' => (string) $key]), $name) ?>
<?php endforeach; ?>
</span>
<?php endif; ?>
<span class="seg seg-sm" role="group" aria-label="<?= $e(t('recent.col_modality')) ?>">
<?= $opt($filter['modality'] === '', $basePath . '/stats' . $query(['modality' => '']), t('recent.any_modality')) ?>
<?php foreach ($modalities as $modality): ?>
<?= $opt($filter['modality'] === $modality, $basePath . '/stats' . $query(['modality' => $modality]), $modality) ?>
<?php endforeach; ?>
</span>
</nav>

<div class="wk-start-stats wk-stats-head">
<div class="wk-start-stat"><b><?= (int) $stats['turnaround']['n'] ?></b><span><?= $e(t('stats.signed_in_period')) ?></span></div>
<div class="wk-start-stat"><b><?= $e(Stats::formatDays($stats['turnaround']['median'])) ?></b><span><?= $e(t('stats.median')) ?></span></div>
<div class="wk-start-stat"><b><?= $e(Stats::formatDays($stats['turnaround']['p90'])) ?></b><span><?= $e(t('stats.p90')) ?></span></div>
<div class="wk-start-stat"><b><?= (int) $stats['status']['draft'] ?></b><span><?= $e(t('stats.status_line', [$stats['status']['signed'], $stats['status']['archived']])) ?></span></div>
</div>

<div class="wk-panel" id="months">
<header class="wk-panel-h"><h2 class="wk-eyebrow"><?= $e(t('stats.per_month')) ?></h2><a class="wk-start-more" href="<?= $csv('months') ?>">CSV</a></header>
<div class="wk-stats-scroll"><table class="table wk-stats-table">
<thead><tr><th><?= $e(t('stats.month')) ?></th><th class="wk-right"><?= $e(t('stats.exams')) ?></th><th class="wk-right"><?= $e(t('stats.signed')) ?></th><th class="wk-stat-bars"></th><th class="wk-right"><?= $e(t('stats.median')) ?></th></tr></thead>
<tbody>
<?php foreach (array_reverse($stats['months']) as $row): ?>
<tr>
<td class="wk-mono"><?= $e($row['month']) ?></td>
<td class="wk-right wk-mono"><?= (int) $row['exams'] ?></td>
<td class="wk-right wk-mono"><?= (int) $row['signed'] ?></td>
<td class="wk-stat-bars"><?= $bar((int) $row['exams'], $max) ?><?= $bar((int) $row['signed'], $max, 'wk-stat-bar-signed') ?></td>
<td class="wk-right wk-mono"><?= $e(Stats::formatDays($row['median'])) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table></div>
</div>

<?php foreach ($groups as $group => [$heading, $label]): ?>
<div class="wk-panel" id="<?= $e($group) ?>">
<header class="wk-panel-h"><h2 class="wk-eyebrow"><?= $e($heading) ?></h2><?php if ($stats[$group] !== []): ?><a class="wk-start-more" href="<?= $csv($group) ?>">CSV</a><?php endif; ?></header>
<?php if ($stats[$group] === []): ?>
<p class="wk-dim"><?= $e(t('dash.empty')) ?></p>
<?php else: ?>
<div class="wk-stats-scroll"><table class="table wk-stats-table">
<thead><tr><th></th><?php if ($group !== 'signer'): ?><th class="wk-right"><?= $e(t('stats.exams')) ?></th><?php endif; ?><th class="wk-right"><?= $e(t('stats.signed')) ?></th><th class="wk-right"><?= $e(t('stats.median')) ?></th><th class="wk-right"><?= $e(t('stats.p90')) ?></th></tr></thead>
<tbody>
<?php foreach ($stats[$group] as $row): ?>
<tr>
<td><?= $e($label($row['key'])) ?></td>
<?php if ($group !== 'signer'): ?><td class="wk-right wk-mono"><?= (int) $row['exams'] ?></td><?php endif; ?>
<td class="wk-right wk-mono"><?= (int) $row['signed'] ?></td>
<td class="wk-right wk-mono"><?= $e(Stats::formatDays($row['median'])) ?></td>
<td class="wk-right wk-mono"><?= $e(Stats::formatDays($row['p90'])) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table></div>
<?php endif; ?>
</div>
<?php endforeach; ?>

<div class="wk-panel" id="stale">
<header class="wk-panel-h"><h2 class="wk-eyebrow"><?= $e(t('stats.stale')) ?><span class="wk-count"><?= (int) $stats['staleTotal'] ?></span></h2></header>
<?php if ($stats['stale'] === []): ?>
<p class="wk-dim"><?= $e(t('start.drafts_none')) ?></p>
<?php else: ?>
<table class="table">
<thead><tr><th><?= $e(t('ns.col_title')) ?></th><th><?= $e(t('stats.exam_date')) ?></th><th><?= $e(t('ns.col_updated')) ?></th></tr></thead>
<tbody>
<?php foreach ($stats['stale'] as $row): ?>
<tr>
<td><a href="<?= $b ?>/<?= $e($row['path']) ?>"><?= $e($row['title'] !== '' ? $row['title'] : $row['path']) ?></a></td>
<td class="wk-mono"><?= $e((string) ($row['study_date'] ?? '')) ?></td>
<td class="wk-mono wk-start-stale"><?= $e(\Reporion\Support\MetaText::ago($row['updated'])) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php if ($stats['staleTotal'] > count($stats['stale'])): ?><p class="wk-dim"><?= $e(t('start.oldest_of', [count($stats['stale']), (string) $stats['staleTotal']])) ?></p><?php endif; ?>
<?php endif; ?>
</div>

<p class="wk-dim wk-text-sm"><?= $e(t('stats.note')) ?></p>
</div>
