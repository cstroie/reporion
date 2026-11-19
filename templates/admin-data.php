<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Admin → Data (Controller\AdminDataController): content only. The pages
 * and the index's health, the storage tasks, the trash, the backups, the
 * cron lines and the recent runs of every task. Counts and drift only in the
 * health panel: no page path is listed there (trash and journal names carry
 * the patient-named segment, invariant 8); the trash lists its pages.
 *
 * Variables in scope: array $status (Service\IndexMaintenance::status()),
 * array{orphans: list<string>, missing: list<string>, drifted: list<string>} $drift, ?int $rebuilt;
 * list<array<string, mixed>> $entries (the trash), int $purgeDays; list<array{file: string, size: int, time: int}> $backups;
 * list<array{id: string, report: MaintenanceReport}> $recent; string $cronRoot, $cronUser;
 * TaskPanels::vars(); SettingsPanels::vars(); string $adminTab, $basePath
 */

declare(strict_types=1);

use Reporion\Controller\AdminMaintenanceController as Maint;
use Reporion\Support\MetaText;

/** @var array<string, mixed> $status */
/** @var array{orphans: list<string>, missing: list<string>, drifted: list<string>} $drift */
/** @var ?int $rebuilt */
/** @var list<array<string, mixed>> $entries */
/** @var int $purgeDays */
/** @var array<string, \Reporion\Service\Maintenance\MaintenanceTask> $tasks */
/** @var string $basePath */

include __DIR__ . '/partials/settings/helpers.php';
$clean = $drift['orphans'] === [] && $drift['missing'] === [] && $drift['drifted'] === [];
$card = static function (string $name) use ($tasks, $basePath): void {
    if (isset($tasks[$name])) {
        $task = $tasks[$name];
        include __DIR__ . '/partials/maint-task.php';
    }
};
$storageTasks = ['index:verify', 'integrity:verify', 'journal:replay', 'pages:repair-paths'];
$placed = [...$storageTasks, 'trash:purge', 'backup:rsync', 'backup:create'];
?>
<div class="wk-doc">
<header class="wk-doc-head">
<?= \Reporion\Http\Breadcrumb::render([['label' => t('nav.admin')], ['label' => t('admin.data.title')]]) ?>
<div class="wk-doc-titlerow"><h1 class="wk-doc-title"><?= $e(t('admin.data.title')) ?></h1></div>
<div class="wk-badges"><span class="wk-mono wk-dim"><?= $e(t('admin.data.explain')) ?></span></div>
</header>
<?php include __DIR__ . '/admin-tabs.php'; ?>
<?php include __DIR__ . '/partials/admin-run.php'; ?>
<?php if ($rebuilt !== null): ?>
<?php $noticeKind = 'ok'; $noticeText = t('admin.index.rebuilt', [$rebuilt]); include __DIR__ . '/partials/notice.php'; ?>
<?php endif; ?>

<div class="wk-panel" id="pages">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= htmlspecialchars(t('admin.index.pages'), ENT_QUOTES) ?></h2></hgroup><span class="wk-count"><?= (int) $status['total'] ?></span></header>
<div class="wk-stats">
<?php foreach (['draft', 'signed', 'archived'] as $key): ?>
<div class="wk-stat"><b><?= (int) ($status['byStatus'][$key] ?? 0) ?></b><span><?= htmlspecialchars($key, ENT_QUOTES) ?></span></div>
<?php endforeach; ?>
<?php foreach (['private', 'unlisted', 'public'] as $key): ?>
<div class="wk-stat"><b><?= (int) ($status['byVisibility'][$key] ?? 0) ?></b><span><?= htmlspecialchars($key, ENT_QUOTES) ?></span></div>
<?php endforeach; ?>
</div>
</div>

<div class="wk-panel" id="health">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= htmlspecialchars(t('admin.index.health'), ENT_QUOTES) ?></h2></hgroup></header>
<div class="wk-kv">
<span><?= htmlspecialchars(t('admin.index.drift'), ENT_QUOTES) ?></span><b><?= $clean ? htmlspecialchars(t('admin.index.clean'), ENT_QUOTES) : htmlspecialchars(t('admin.index.drift_counts', [\count($drift['missing']), \count($drift['orphans']), \count($drift['drifted'])]), ENT_QUOTES) ?></b>
<span><?= htmlspecialchars(t('admin.index.intents'), ENT_QUOTES) ?></span><b><?= (int) $status['openIntents'] ?><?php if ((int) $status['openIntents'] > 0): ?> · <a href="<?= $b ?>/admin/data#journal-replay"><?= htmlspecialchars(t('admin.index.to_replay'), ENT_QUOTES) ?></a><?php endif; ?></b>
<span><?= htmlspecialchars(t('admin.index.trash'), ENT_QUOTES) ?></span><b><?= (int) $status['trashEntries'] ?><?php if ((int) $status['trashEntries'] > 0): ?> · <a href="<?= $b ?>/admin/data#trash"><?= htmlspecialchars(t('admin.index.to_purge'), ENT_QUOTES) ?></a><?php endif; ?></b>
<span><?= htmlspecialchars(t('admin.index.audit'), ENT_QUOTES) ?></span><b class="wk-mono"><?php if ($status['audit'] === []): ?>n/a<?php else: ?><?php foreach ($status['audit'] as $file): ?><?= htmlspecialchars($file['file'], ENT_QUOTES) ?> (<?= number_format($file['bytes'] / 1024, 1) ?> KB) <?php endforeach; ?><?php endif; ?></b>
</div>
</div>

<div class="wk-panel" id="rebuild">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= htmlspecialchars(t('admin.index.rebuild'), ENT_QUOTES) ?></h2><p><?= htmlspecialchars(t('admin.index.rebuild_note'), ENT_QUOTES) ?></p></hgroup></header>
<form data-busy action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/admin/index/rebuild" method="post">
<footer><button class="btn btn-secondary" type="submit"><i class="ph ph-arrows-clockwise"></i><?= htmlspecialchars(t('admin.index.rebuild'), ENT_QUOTES) ?></button></footer>
</form>
</div>

<?php foreach ($storageTasks as $name) { $card($name); } ?>
<?php foreach (array_diff(array_keys($tasks), $placed) as $name) { $card($name); } ?>

<div class="wk-panel" id="trash">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= htmlspecialchars(t('admin.trash.panel'), ENT_QUOTES) ?></h2><p class="wk-dim"><?= htmlspecialchars(t('admin.trash.explain', [$purgeDays]), ENT_QUOTES) ?></p></hgroup>
<?php if ($entries !== []): ?>
<span class="wk-actions">
<form data-busy action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/admin/maintenance/trash:purge" method="post" data-confirm="<?= htmlspecialchars(t('admin.trash.empty_prompt'), ENT_QUOTES) ?>" data-confirm-title="<?= htmlspecialchars(t('admin.trash.empty_title'), ENT_QUOTES) ?>" data-confirm-label="<?= htmlspecialchars(t('admin.trash.empty_action'), ENT_QUOTES) ?>">
<input type="hidden" name="mode" value="apply">
<input type="hidden" name="older_than" value="0">
<input type="hidden" name="confirm" value="1">
<button class="btn btn-danger btn-sm" type="submit"><i class="ph ph-trash"></i><?= htmlspecialchars(t('admin.trash.empty_action'), ENT_QUOTES) ?></button>
</form>
</span>
<?php endif; ?>
</header>
<?php if ($entries === []): ?>
<p class="wk-empty"><?= htmlspecialchars(t('admin.trash.empty'), ENT_QUOTES) ?></p>
<?php else: ?>
<table class="table table-cards">
<thead><tr>
<th><?= htmlspecialchars(t('admin.trash.col_page'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('admin.trash.col_deleted'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('admin.trash.col_purge'), ENT_QUOTES) ?></th>
<th></th>
</tr></thead>
<tbody>
<?php foreach ($entries as $entry): ?>
<tr>
<td class="wk-card-head"><b><?= htmlspecialchars((string) $entry['title'], ENT_QUOTES) ?></b><br><span class="wk-mono wk-dim wk-text-sm"><?= htmlspecialchars((string) $entry['path'], ENT_QUOTES) ?></span>
<?php if ($entry['signed']): ?> <span class="tag tag-accent"><?= htmlspecialchars(t('admin.trash.signed'), ENT_QUOTES) ?></span><?php endif; ?></td>
<td class="wk-mono wk-text-sm" data-label="<?= htmlspecialchars(t('admin.trash.col_deleted'), ENT_QUOTES) ?>"><?= htmlspecialchars($entry['deletedAt'] !== null ? \Reporion\Support\MetaText::when($entry['deletedAt']) : 'n/a', ENT_QUOTES) ?><br><?= htmlspecialchars((string) ($entry['deletedBy'] ?? ''), ENT_QUOTES) ?></td>
<td class="wk-mono wk-text-sm" data-label="<?= htmlspecialchars(t('admin.trash.col_purge'), ENT_QUOTES) ?>"><?= $entry['signed'] ? htmlspecialchars(t('admin.trash.kept'), ENT_QUOTES) : ($entry['daysLeft'] === null ? 'n/a' : htmlspecialchars(t('admin.trash.days', [$entry['daysLeft']]), ENT_QUOTES)) ?></td>
<td class="wk-right"><form action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/admin/trash/<?= htmlspecialchars((string) $entry['pid'], ENT_QUOTES) ?>/restore" method="post"><button class="btn btn-secondary btn-sm" type="submit"><i class="ph ph-arrow-counter-clockwise"></i><?= htmlspecialchars(t('admin.trash.restore'), ENT_QUOTES) ?></button></form></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
<?php include __DIR__ . '/partials/settings/trash.php'; ?>
</div>
<?php $card('trash:purge'); ?>

<?php include __DIR__ . '/partials/settings/backup.php'; ?>
<?php $card('backup:rsync'); ?>
<?php $card('backup:create'); ?>
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

<div class="wk-panel" id="cron">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.maint.cron')) ?></h2><p><?= $e(t('admin.maint.cron_note')) ?></p></hgroup></header>
<p class="wk-text-sm"><?= $e(t('admin.maint.cron_open')) ?></p>
<pre class="wk-install-fix">sudo crontab -u <?= $e($cronUser) ?> -e</pre>
<p class="wk-text-sm"><?= $e(t('admin.maint.cron_add')) ?></p>
<pre class="wk-install-fix"># Reporion tasks
37 4 * * *  cd <?= $e($cronRoot) ?> &amp;&amp; bin/reporion index:verify --json &gt; /dev/null
17 5 * * *  cd <?= $e($cronRoot) ?> &amp;&amp; bin/reporion trash:purge
47 5 * * 0  cd <?= $e($cronRoot) ?> &amp;&amp; bin/reporion integrity:verify --json &gt; /dev/null
7 * * * *   cd <?= $e($cronRoot) ?> &amp;&amp; bin/reporion backup:rsync --if-due</pre>
<p class="wk-dim wk-text-sm wk-m0"><?= $e(t('admin.maint.cron_after')) ?></p>
</div>

<div class="wk-panel" id="runs">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.maint.recent')) ?></h2></hgroup></header>
<?php if ($recent === []): ?>
<p class="wk-empty"><?= $e(t('admin.maint.no_runs')) ?></p>
<?php else: ?>
<table class="table table-cards">
<thead><tr><th><?= $e(t('admin.maint.when')) ?></th><th><?= $e(t('admin.maint.col_task')) ?></th><th><?= $e(t('admin.maint.by')) ?></th><th><?= $e(t('admin.maint.col_result')) ?></th></tr></thead>
<tbody>
<?php foreach ($recent as $run): ?>
<tr<?= $run['id'] === $runId ? ' class="wk-sel"' : '' ?>>
<td class="wk-mono wk-text-sm wk-card-head"><a href="<?= $b ?><?= $e(\Reporion\Support\AdminTabs::taskRoute($run['report']->task)) ?>?run=<?= $e($run['id']) ?>#report"><?= $e(MetaText::when($run['report']->started)) ?></a></td>
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
