<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The admin screens' tab row (design/mockup/WikiAdmin.dc.html's tabs, each
 * a plain link to its own route). Included by templates/admin-users.php and
 * templates/admin-index.php. Variables in scope: string $adminTab, $basePath
 */

declare(strict_types=1);

/** @var string $adminTab */
/** @var string $basePath */

$tabs = ['users' => ['/admin/users', 'admin.users.title'], 'index' => ['/admin/index', 'admin.index.title'], 'trash' => ['/admin/trash', 'admin.trash.title'], 'tags' => ['/admin/tags', 'admin.tags.title'], 'maintenance' => ['/admin/maintenance', 'admin.maint.title'], 'settings' => ['/admin/settings', 'admin.settings.title']];
?>
<nav class="wk-tabs wk-pagetabs" aria-label="<?= htmlspecialchars(t('nav.admin'), ENT_QUOTES) ?>" style="margin-bottom:var(--space-6)">
<?php foreach ($tabs as $key => [$href, $label]): ?>
<a class="wk-tab" data-on="<?= $key === $adminTab ? '1' : '' ?>"<?= $key === $adminTab ? ' aria-current="page"' : '' ?> href="<?= htmlspecialchars($basePath . $href, ENT_QUOTES) ?>"><?= htmlspecialchars(t($label), ENT_QUOTES) ?></a>
<?php endforeach; ?>
</nav>
