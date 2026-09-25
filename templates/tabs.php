<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Document tab strip (Workbench chrome, design/mockup/Wiki.dc.html's
 * "bench" variant) — included (not View::render()'d) by page-view.php,
 * editor.php and history.php, sharing whichever's already-extracted scope.
 * Report/Edit/History & diff are real links to real routes — no
 * client-side tab state (docs/architecture-api.md's A5). Compare, Patient
 * and Print have no route at all (Compare/timeline are unbuilt; print/PDF
 * was explicitly deferred) — rendered .wk-tab-inert, same "show it, don't
 * fake it" rule as templates/rail.php.
 *
 * Required in scope (set by whichever controller built the vars for the
 * including template — Http\ChromeVars::forPath() computes railEditHref,
 * the same value this partial reuses for the Edit tab):
 *   string $basePath, $path
 *   string $tabActive     — one of: view, edit, hist
 *   ?string $railEditHref — Edit tab target, or null when the caller
 *                           cannot write here (Http\ChromeVars::forPath())
 */

declare(strict_types=1);

/** @var string $basePath */
/** @var string $path */
/** @var string $tabActive */
/** @var ?string $railEditHref */
?>
<div class="wk-dtabs">
<a class="wk-tab" data-on="<?= $tabActive === 'view' ? '1' : '' ?>" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($path, ENT_QUOTES) ?>"><?= htmlspecialchars(t('tabs.report'), ENT_QUOTES) ?></a>
<?php if ($railEditHref !== null): ?>
<a class="wk-tab" data-on="<?= $tabActive === 'edit' ? '1' : '' ?>" href="<?= htmlspecialchars($basePath . $railEditHref, ENT_QUOTES) ?>"><?= htmlspecialchars(t('tabs.edit'), ENT_QUOTES) ?></a>
<?php else: ?>
<a class="wk-tab wk-tab-inert" title="<?= htmlspecialchars(t('tabs.edit_inert'), ENT_QUOTES) ?>"><?= htmlspecialchars(t('tabs.edit'), ENT_QUOTES) ?></a>
<?php endif; ?>
<a class="wk-tab" data-on="<?= $tabActive === 'hist' ? '1' : '' ?>" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($path, ENT_QUOTES) ?>/history"><?= htmlspecialchars(t('tabs.history'), ENT_QUOTES) ?></a>
<a class="wk-tab" data-on="<?= $tabActive === 'hist' ? '1' : '' ?>" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($path, ENT_QUOTES) ?>/history"><?= htmlspecialchars(t('tabs.history'), ENT_QUOTES) ?></a>
<?php if ($tabActive === 'compare'): ?>
<a class="wk-tab" data-on="1" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($path, ENT_QUOTES) ?>/compare"><?= htmlspecialchars(t('tabs.compare'), ENT_QUOTES) ?></a>
<?php else: ?>
<a class="wk-tab" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($path, ENT_QUOTES) ?>/compare"><?= htmlspecialchars(t('tabs.compare'), ENT_QUOTES) ?></a>
<?php endif; ?>
<?php if ($tabActive === 'patient'): ?>
<a class="wk-tab" data-on="1" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($path, ENT_QUOTES) ?>/timeline"><?= htmlspecialchars(t('tabs.patient'), ENT_QUOTES) ?></a>
<?php else: ?>
<a class="wk-tab" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($path, ENT_QUOTES) ?>/timeline"><?= htmlspecialchars(t('tabs.patient'), ENT_QUOTES) ?></a>
<?php endif; ?>
<a class="wk-tab wk-tab-inert" title="<?= htmlspecialchars(t('tabs.print_inert'), ENT_QUOTES) ?>"><?= htmlspecialchars(t('tabs.print'), ENT_QUOTES) ?></a>
</div>
