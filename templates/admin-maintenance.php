<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Admin → Maintenance (Controller\AdminMaintenanceController) — content only.
 * One card per task (Service\Maintenance), each a single form whose two
 * submit buttons pick the mode; the selected run's report; recent runs.
 *
 * Variables in scope: array<string, MaintenanceTask> $tasks;
 * list<array{id: string, report: MaintenanceReport}> $recent; ?string $runId;
 * ?MaintenanceReport $report; array<string, ?array{path: string, title: string}> $pages;
 * int $shownItems; bool $busy; ?string $error; string $adminTab, $basePath
 */

declare(strict_types=1);

use Reporion\Controller\AdminMaintenanceController as Maint;
use Reporion\Service\Maintenance\MaintenanceTask;
use Reporion\Support\MetaText;

/** @var array<string, MaintenanceTask> $tasks */
/** @var list<array{id: string, report: \Reporion\Service\Maintenance\MaintenanceReport}> $recent */
/** @var ?string $runId */
/** @var ?\Reporion\Service\Maintenance\MaintenanceReport $report */
/** @var array<string, ?array{path: string, title: string}> $pages */
/** @var int $shownItems */
/** @var bool $busy */
/** @var ?string $error */
/** @var string $basePath */

$b = htmlspecialchars($basePath, ENT_QUOTES);
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
?>
<div class="wk-doc">
<div class="wk-doc-head">
<div class="wk-crumbs wk-mono"><b><?= $e(t('nav.admin')) ?></b><span>›</span><span><?= $e(t('admin.maint.title')) ?></span></div>
<h1 class="wk-doc-title"><?= $e(t('admin.maint.title')) ?></h1>
<div class="wk-badges"><span class="wk-mono wk-dim"><?= $e(t('admin.maint.explain')) ?></span></div>
</div>
<?php include __DIR__ . '/admin-tabs.php'; ?>

<?php if ($busy): ?>
<div class="wk-notice" role="alert"><i class="ph ph-warning"></i><div><?= $e(t('admin.maint.busy')) ?></div></div>
<?php endif; ?>
<?php if ($error !== null): ?>
<div class="wk-notice" role="alert"><i class="ph ph-warning"></i><div><?= $e($error) ?></div></div>
<?php endif; ?>

<?php if ($report !== null && $runId !== null): ?>
<div class="wk-panel" id="report">
<div class="wk-panel-h"><span class="wk-eyebrow"><?= $e(t('admin.maint.report')) ?> · <span class="wk-mono"><?= $e($report->task) ?></span> · <?= $e(t('admin.maint.mode_' . $report->mode)) ?></span>
<span class="tag <?= $report->exit() === 0 ? 'tag-neutral' : 'tag-accent' ?>"><?= $e(t($report->exit() === 0 ? 'admin.maint.ok' : 'admin.maint.attention')) ?></span></div>
<div class="wk-kv" style="margin-bottom:var(--space-3)">
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
<p class="wk-dim" style="font-size:12.5px;margin:0 0 var(--space-3)"><?= $e($note) ?></p>
<?php endforeach; ?>
<?php if ($report->items() === []): ?>
<p class="wk-dim" style="font-size:13px;margin:0"><?= $e(t('admin.maint.no_items')) ?></p>
<?php else: ?>
<table class="table">
<thead><tr><th><?= $e(t('admin.maint.col_page')) ?></th><th><?= $e(t('admin.maint.col_rev')) ?></th><th><?= $e(t('admin.maint.col_outcome')) ?></th><th><?= $e(t('admin.maint.col_detail')) ?></th></tr></thead>
<tbody>
<?php foreach (\array_slice($report->items(), 0, $shownItems) as $item): ?>
<?php $page = $item['pid'] !== null ? ($pages[$item['pid']] ?? null) : null; ?>
<tr>
<td><?php if ($page !== null): ?><a href="<?= $b ?>/<?= $e($page['path']) ?>"><b><?= $e($page['title'] !== '' ? $page['title'] : $page['path']) ?></b></a><br><?php endif; ?><span class="wk-mono wk-dim" style="font-size:11.5px"><?= $e((string) $item['pid']) ?></span></td>
<td class="wk-mono"><?= $item['rev'] !== null ? (int) $item['rev'] : '—' ?></td>
<td><span class="tag tag-outline wk-mono"><?= $e(str_replace('_', ' ', $item['outcome'])) ?></span></td>
<td class="wk-mono wk-dim" style="font-size:12px"><?= $e($item['detail']) ?><?php if (($item['data']['repaired_rev'] ?? null) !== null): ?> → rev <?= (int) $item['data']['repaired_rev'] ?><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php if (\count($report->items()) > $shownItems): ?>
<p class="wk-dim" style="font-size:12.5px"><?= $e(t('admin.maint.more_items', [\count($report->items()) - $shownItems])) ?></p>
<?php endif; ?>
<?php endif; ?>
</div>
<?php endif; ?>

<?php foreach ($tasks as $name => $task): ?>
<div class="wk-panel" id="<?= $e(Maint::anchor($name)) ?>">
<div class="wk-panel-h"><span class="wk-eyebrow"><?= $e(t('admin.maint.task.' . $name . '.title')) ?></span><span class="wk-mono wk-dim" style="font-size:11.5px">bin/reporion <?= $e($name) ?></span></div>
<p style="font-size:13px;margin:0 0 var(--space-3)"><?= $e(t('admin.maint.task.' . $name . '.desc')) ?></p>
<form action="<?= $b ?>/admin/maintenance/<?= $e($name) ?>" method="post">
<?php if ($name === 'journal:replay'): ?>
<p style="font-size:13px;margin:0 0 var(--space-3)"><label><?= $e(t('admin.maint.opt.min_age')) ?> <input class="input wk-inline-input" type="number" name="min_age" min="0" value="60" style="max-width:90px"></label></p>
<?php elseif ($name === 'trash:purge'): ?>
<p style="font-size:13px;margin:0 0 var(--space-3);display:flex;gap:var(--space-4);flex-wrap:wrap;align-items:center">
<label><?= $e(t('admin.maint.opt.older_than')) ?> <input class="input wk-inline-input" type="number" name="older_than" min="0" value="<?= (int) $task->options([])['older_than'] ?>" style="max-width:90px"></label>
<label><input type="checkbox" name="include_signed" value="1"> <?= $e(t('admin.maint.opt.include_signed')) ?></label>
</p>
<?php endif; ?>
<div style="display:flex;gap:var(--space-3);align-items:center;flex-wrap:wrap">
<button class="btn btn-secondary btn-sm" type="submit" name="mode" value="check"><i class="ph ph-magnifying-glass"></i><?= $e(t('admin.maint.task.' . $name . '.check')) ?></button>
<?php if (\in_array(MaintenanceTask::APPLY, $task->modes(), true)): ?>
<label style="font-size:12.5px"><input type="checkbox" name="confirm" value="1"> <?= $e(t('admin.maint.task.' . $name . '.confirm')) ?></label>
<button class="btn btn-primary btn-sm" type="submit" name="mode" value="apply"><i class="ph ph-play"></i><?= $e(t('admin.maint.task.' . $name . '.apply')) ?></button>
<?php endif; ?>
</div>
</form>
</div>
<?php endforeach; ?>

<div class="wk-panel">
<div class="wk-panel-h"><span class="wk-eyebrow"><?= $e(t('admin.maint.recent')) ?></span></div>
<?php if ($recent === []): ?>
<p class="wk-dim" style="font-size:13px;margin:0"><?= $e(t('admin.maint.no_runs')) ?></p>
<?php else: ?>
<table class="table">
<thead><tr><th><?= $e(t('admin.maint.when')) ?></th><th><?= $e(t('admin.maint.col_task')) ?></th><th><?= $e(t('admin.maint.by')) ?></th><th><?= $e(t('admin.maint.col_result')) ?></th></tr></thead>
<tbody>
<?php foreach ($recent as $run): ?>
<tr<?= $run['id'] === $runId ? ' class="wk-sel"' : '' ?>>
<td class="wk-mono" style="font-size:12px"><a href="<?= $b ?>/admin/maintenance?run=<?= $e($run['id']) ?>#report"><?= $e(MetaText::when($run['report']->started)) ?></a></td>
<td class="wk-mono" style="font-size:12px"><?= $e($run['report']->task) ?> · <?= $e(t('admin.maint.mode_' . $run['report']->mode)) ?></td>
<td class="wk-mono" style="font-size:12px"><?= $e($run['report']->actor) ?></td>
<td style="font-size:12.5px"><?= $run['report']->exit() !== 0 ? '<i class="ph ph-warning"></i> ' : '' ?><?= $e(Maint::summaryLine($run['report'])) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>
</div>
