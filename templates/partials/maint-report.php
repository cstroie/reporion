<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * A maintenance run's report panel (id "report"), shared by Admin →
 * Maintenance and Admin → Tags (the tag tasks run from there).
 *
 * Variables in scope: ?string $runId; ?MaintenanceReport $report; ?bool $running;
 * array<string, ?array{path: string, title: string}> $pages; int $shownItems; string $basePath
 */

declare(strict_types=1);

use Reporion\Support\MetaText;

$b = htmlspecialchars($basePath, ENT_QUOTES);
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
?>
<?php if ($report !== null && $runId !== null): ?>
<div class="wk-panel" id="report">
<header class="wk-panel-h"><h2 class="wk-eyebrow"><?= $e(t('admin.maint.report')) ?> · <span class="wk-mono"><?= $e($report->task) ?></span> · <?= $e(t('admin.maint.mode_' . $report->mode)) ?></h2>
<?php if ($running === true): ?>
<span class="tag tag-neutral"><?= $e(t('admin.maint.running')) ?></span></header>
<?php elseif ($running === false): ?>
<span class="tag tag-accent"><?= $e(t('admin.maint.attention')) ?></span></header>
<?php else: ?>
<span class="tag <?= $report->exit() === 0 ? 'tag-neutral' : 'tag-accent' ?>"><?= $e(t($report->exit() === 0 ? 'admin.maint.ok' : 'admin.maint.attention')) ?></span></header>
<?php endif; ?>
<?php if ($running === true): ?>
<meta http-equiv="refresh" content="5">
<div class="wk-notice" role="status"><i class="ph ph-hourglass"></i><div><?= $e(t('admin.maint.running_note')) ?></div></div>
<?php elseif ($running === false): ?>
<div class="wk-notice" role="alert"><i class="ph ph-warning"></i><div><?= $e(t('admin.maint.unfinished')) ?></div></div>
<?php endif; ?>
<div class="wk-kv wk-mb-3">
<span><?= $e(t('admin.maint.by')) ?></span><b class="wk-mono"><?= $e($report->actor) ?></b>
<span><?= $e(t('admin.maint.when')) ?></span><b class="wk-mono"><?= $e(MetaText::when($report->started)) ?></b>
<?php if ($report->options !== []): ?>
<span><?= $e(t('admin.maint.options')) ?></span><b class="wk-mono"><?php foreach ($report->options as $key => $value): ?><?= $e($key) ?>=<?= $e(\is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value) ?> <?php endforeach; ?></b>
<?php endif; ?>
<span><?= $e(t('admin.maint.json')) ?></span><b class="wk-mono"><a href="<?= $b ?>/admin/maintenance/runs/<?= $e($runId) ?>.json"><?= $e($runId) ?>.json</a></b>
</div>
<div class="wk-stats">
<?php foreach ($report->summary() as $key => $n): ?>
<div class="wk-stat"><b><?= (int) $n ?></b><span><?= $e(str_replace('_', ' ', $key)) ?></span></div>
<?php endforeach; ?>
</div>
<?php foreach ($report->notes() as $note): ?>
<p class="wk-dim wk-help"><?= $e($note) ?></p>
<?php endforeach; ?>
<?php if ($report->items() === []): ?>
<p class="wk-dim wk-text-sm wk-m0"><?= $e(t('admin.maint.no_items')) ?></p>
<?php else: ?>
<table class="table">
<thead><tr><th><?= $e(t('admin.maint.col_page')) ?></th><th><?= $e(t('admin.maint.col_rev')) ?></th><th><?= $e(t('admin.maint.col_outcome')) ?></th><th><?= $e(t('admin.maint.col_detail')) ?></th></tr></thead>
<tbody>
<?php foreach (\array_slice($report->items(), 0, $shownItems) as $item): ?>
<?php $page = $item['pid'] !== null ? ($pages[$item['pid']] ?? null) : null; ?>
<tr>
<td><?php if ($page !== null): ?><a href="<?= $b ?>/<?= $e($page['path']) ?>"><b><?= $e($page['title'] !== '' ? $page['title'] : $page['path']) ?></b></a><br><?php endif; ?><span class="wk-mono wk-dim wk-text-sm"><?= $e((string) $item['pid']) ?></span></td>
<td class="wk-mono"><?= $item['rev'] !== null ? (int) $item['rev'] : '—' ?></td>
<td><span class="tag tag-outline wk-mono"><?= $e(str_replace('_', ' ', $item['outcome'])) ?></span></td>
<td class="wk-mono wk-dim wk-text-sm"><?= $e($item['detail']) ?><?php if (($item['data']['repaired_rev'] ?? null) !== null): ?> → rev <?= (int) $item['data']['repaired_rev'] ?><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php if (\count($report->items()) > $shownItems): ?>
<p class="wk-dim wk-text-sm"><?= $e(t('admin.maint.more_items', [\count($report->items()) - $shownItems])) ?></p>
<?php endif; ?>
<?php endif; ?>
</div>
<?php endif; ?>
