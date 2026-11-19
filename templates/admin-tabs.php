<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The admin screens' tab row (design/mockup/WikiAdmin.dc.html's tabs, each
 * a plain link to its own route; Support\AdminTabs), and under it the
 * screens of a tab that has several (People: Users, Groups). Included by
 * every templates/admin-*.php. Variables in scope: string $adminTab (a tab
 * or a sub-tab key), $basePath
 */

declare(strict_types=1);

use Reporion\Support\AdminTabs;

/** @var string $adminTab */
/** @var string $basePath */

$activeTab = AdminTabs::parentOf($adminTab);
?>
<nav class="wk-tabs wk-pagetabs wk-pagetabs-wrap <?= isset(AdminTabs::SUBTABS[$activeTab]) ? 'wk-mb-4' : 'wk-mb-6' ?>" aria-label="<?= htmlspecialchars(t('nav.admin'), ENT_QUOTES) ?>">
<?php foreach (AdminTabs::TABS as $key => [$href, $label]): ?>
<a class="wk-tab" data-on="<?= $key === $activeTab ? '1' : '' ?>"<?= $key === $activeTab ? ' aria-current="page"' : '' ?> href="<?= htmlspecialchars($basePath . $href, ENT_QUOTES) ?>"><?= htmlspecialchars(t($label), ENT_QUOTES) ?></a>
<?php endforeach; ?>
</nav>
<?php if (isset(AdminTabs::SUBTABS[$activeTab])): ?>
<nav class="seg seg-sm wk-mb-6" aria-label="<?= htmlspecialchars(t(AdminTabs::TABS[$activeTab][1]), ENT_QUOTES) ?>">
<?php foreach (AdminTabs::SUBTABS[$activeTab] as $key => [$href, $label]): ?>
<a class="seg-opt<?= $key === $adminTab ? ' seg-on' : '' ?>"<?= $key === $adminTab ? ' aria-current="page"' : '' ?> href="<?= htmlspecialchars($basePath . $href, ENT_QUOTES) ?>"><?= htmlspecialchars(t($label), ENT_QUOTES) ?></a>
<?php endforeach; ?>
</nav>
<?php endif; ?>
<?php /* Two or more panels: their titles as a table of contents beside them (assets/js/admin-toc.js builds it, toc.js marks the section in view) */ ?>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/admin-toc.js'), ENT_QUOTES) ?>" data-label="<?= htmlspecialchars(t('page.toc'), ENT_QUOTES) ?>" defer></script>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/toc.js'), ENT_QUOTES) ?>" defer></script>
