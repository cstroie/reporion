<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Admin → Library (Controller\AdminLibraryController): content only. The
 * library's settings (partials/settings/library.php), then its tasks' cards.
 *
 * Variables in scope: SettingsPanels::vars(); TaskPanels::vars(); string $adminTab, $basePath
 */

declare(strict_types=1);

/** @var array<string, \Reporion\Service\Maintenance\MaintenanceTask> $tasks */
/** @var string $basePath */

include __DIR__ . '/partials/settings/helpers.php';
?>
<div class="wk-doc">
<header class="wk-doc-head">
<?= \Reporion\Http\Breadcrumb::render([['label' => t('nav.admin')], ['label' => t('admin.library.title')]]) ?>
<div class="wk-doc-titlerow"><h1 class="wk-doc-title"><?= $e(t('admin.library.title')) ?></h1></div>
<div class="wk-badges"><span class="wk-mono wk-dim"><?= $e(t('admin.library.explain')) ?></span></div>
</header>
<?php include __DIR__ . '/admin-tabs.php'; ?>
<?php include __DIR__ . '/partials/admin-run.php'; ?>
<?php include __DIR__ . '/partials/settings/library.php'; ?>

<?php foreach ($tasks as $name => $task): ?>
<?php include __DIR__ . '/partials/maint-task.php'; ?>
<?php endforeach; ?>
</div>
