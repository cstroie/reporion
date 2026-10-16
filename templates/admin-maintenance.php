<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Admin → Maintenance (Controller\AdminMaintenanceController): content only.
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
/** @var ?bool $running the shown run unfinished: still running (true) or stopped without its report (false) */
/** @var ?string $error */
/** @var string $basePath */
/** @var string $cronRoot install directory; @var string $cronUser the account PHP runs as */

$b = htmlspecialchars($basePath, ENT_QUOTES);
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
?>
<div class="wk-doc">
<div class="wk-doc-head">
<?= \Reporion\Http\Breadcrumb::render([['label' => t('nav.admin')], ['label' => t('admin.maint.title')]]) ?>
<h1 class="wk-doc-title"><?= $e(t('admin.maint.title')) ?></h1>
<div class="wk-badges"><span class="wk-mono wk-dim"><?= $e(t('admin.maint.explain')) ?></span></div>
</div>
<?php include __DIR__ . '/admin-tabs.php'; ?>

<?php if ($busy): ?>
<div class="wk-notice wk-notice-warn" role="alert"><i class="ph ph-warning"></i><div><?= $e(t('admin.maint.busy')) ?></div></div>
<?php endif; ?>
<?php if ($error !== null): ?>
<div class="wk-notice wk-notice-error" role="alert"><i class="ph ph-warning-octagon"></i><div><?= $e($error) ?></div></div>
<?php endif; ?>

<?php include __DIR__ . '/partials/maint-report.php'; ?>

<?php foreach ($tasks as $name => $task): ?>
<?php $return = ''; include __DIR__ . '/partials/maint-task.php'; ?>
<?php endforeach; ?>

<?php if ($backups !== []): ?>
<div class="wk-panel" id="backups">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.maint.backups')) ?></h2><p><?= $e(t('admin.maint.backups_note')) ?></p></hgroup></header>
<table class="table table-cards">
<thead><tr><th><?= $e(t('admin.maint.backup_file')) ?></th><th><?= $e(t('admin.maint.backup_size')) ?></th><th><?= $e(t('admin.maint.when')) ?></th></tr></thead>
<tbody>
<?php foreach ($backups as $backup): ?>
<tr>
<td class="wk-mono wk-text-sm wk-card-head"><a href="<?= $b ?>/admin/maintenance/backups/<?= $e($backup['file']) ?>" download><i class="ph ph-download-simple"></i> <?= $e($backup['file']) ?></a></td>
<td class="wk-mono wk-text-sm" data-label="<?= $e(t('admin.maint.backup_size')) ?>"><?= $e(Reporion\Support\MetaText::size($backup['size'])) ?></td>
<td class="wk-mono wk-text-sm" data-label="<?= $e(t('admin.maint.when')) ?>"><?= $e(date('Y-m-d H:i', $backup['time'])) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

<div class="wk-panel">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.maint.cron')) ?></h2><p><?= $e(t('admin.maint.cron_note')) ?></p></hgroup></header>
<p class="wk-text-sm"><?= $e(t('admin.maint.cron_open')) ?></p>
<pre class="wk-install-fix">sudo crontab -u <?= $e($cronUser) ?> -e</pre>
<p class="wk-text-sm"><?= $e(t('admin.maint.cron_add')) ?></p>
<pre class="wk-install-fix"># Reporion tasks
37 4 * * *  cd <?= $e($cronRoot) ?> &amp;&amp; bin/reporion index:verify --json &gt; /dev/null
17 5 * * *  cd <?= $e($cronRoot) ?> &amp;&amp; bin/reporion trash:purge
47 5 * * 0  cd <?= $e($cronRoot) ?> &amp;&amp; bin/reporion integrity:verify --json &gt; /dev/null</pre>
<p class="wk-dim wk-text-sm wk-m0"><?= $e(t('admin.maint.cron_after')) ?></p>
</div>

<div class="wk-panel">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.maint.recent')) ?></h2></hgroup></header>
<?php if ($recent === []): ?>
<p class="wk-empty"><?= $e(t('admin.maint.no_runs')) ?></p>
<?php else: ?>
<table class="table table-cards">
<thead><tr><th><?= $e(t('admin.maint.when')) ?></th><th><?= $e(t('admin.maint.col_task')) ?></th><th><?= $e(t('admin.maint.by')) ?></th><th><?= $e(t('admin.maint.col_result')) ?></th></tr></thead>
<tbody>
<?php foreach ($recent as $run): ?>
<tr<?= $run['id'] === $runId ? ' class="wk-sel"' : '' ?>>
<td class="wk-mono wk-text-sm wk-card-head"><a href="<?= $b ?><?= \in_array($run['report']->task, Maint::TAG_TASKS, true) ? '/admin/tags' : '/admin/maintenance' ?>?run=<?= $e($run['id']) ?>#report"><?= $e(MetaText::when($run['report']->started)) ?></a></td>
<td class="wk-mono wk-text-sm" data-label="<?= $e(t('admin.maint.col_task')) ?>"><?= $e($run['report']->task) ?> · <?= $e(t('admin.maint.mode_' . $run['report']->mode)) ?></td>
<td class="wk-mono wk-text-sm" data-label="<?= $e(t('admin.maint.by')) ?>"><?= $e($run['report']->actor) ?></td>
<td class="wk-text-sm" data-label="<?= $e(t('admin.maint.col_result')) ?>"><?php if ($run['report']->finishedAt() === ''): ?><?= $e(t(Maint::stillRunning($run['report']) ? 'admin.maint.running' : 'admin.maint.unfinished_short')) ?><?php else: ?><?= $run['report']->exit() !== 0 ? '<i class="ph ph-warning"></i> ' : '' ?><?= $e(Maint::summaryLine($run['report'])) ?><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>
</div>
