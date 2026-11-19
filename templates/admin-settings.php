<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Admin → General (Controller\AdminSettingsController): content only.
 * The site, editor, publishing, limits and debug panels
 * (partials/settings/*.php), each saved on its own; values come from
 * data/settings.yaml, else from conf/local.php (marked as such).
 *
 * Variables in scope: SettingsPanels::vars(); string $adminTab, $basePath
 */

declare(strict_types=1);

/** @var bool $hasFile */
/** @var string $basePath */

include __DIR__ . '/partials/settings/helpers.php';
?>
<div class="wk-doc">
<header class="wk-doc-head">
<?= \Reporion\Http\Breadcrumb::render([['label' => t('nav.admin')], ['label' => t('admin.general.title')]]) ?>
<div class="wk-doc-titlerow"><h1 class="wk-doc-title"><?= $e(t('admin.general.title')) ?></h1></div>
<div class="wk-badges"><span class="wk-mono wk-dim"><?= $e(t($hasFile ? 'admin.settings.explain' : 'admin.settings.explain_none')) ?></span></div>
</header>
<?php include __DIR__ . '/admin-tabs.php'; ?>

<?php foreach (['site', 'editor', 'publishing', 'limits', 'debug'] as $panel): ?>
<?php include __DIR__ . '/partials/settings/' . $panel . '.php'; ?>

<?php endforeach; ?>
</div>
