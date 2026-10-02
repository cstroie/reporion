<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The ☰ namespace drawer (A6), included by templates/layout.php. Without
 * JavaScript it is never shown — the ☰ button is a plain link to the
 * namespace index; assets/js/shell.js turns that link into this slide-over.
 * Both listings come from the existing visibility-filtered queries
 * (Index\Sqlite::listSubnamespaces() / listWorklist(), invariant 6), so the
 * drawer shows nothing the namespace index itself would not.
 *
 * Variables in scope: see templates/layout.php ($drawerNs,
 * $drawerSubnamespaces, $drawerRows, $nsHref, $basePath, $quick, $currentUrl) and optionally
 * $headerPath (the current page, highlighted).
 */

declare(strict_types=1);

/** @var string $drawerNs */
/** @var list<array{name: string, count: int}> $drawerSubnamespaces */
/** @var list<array<string, mixed>> $drawerRows */
/** @var string $nsHref */
/** @var string $basePath */
/** @var array<string, mixed> $quick */

$b = htmlspecialchars($basePath, ENT_QUOTES);
$parentSegments = $drawerNs === '' ? [] : explode(':', $drawerNs);
array_pop($parentSegments);
?>
<div class="wk-drawer-backdrop" data-drawer-close hidden></div>
<aside class="wk-drawer" id="wk-drawer" aria-label="<?= htmlspecialchars(t('nav.namespaces'), ENT_QUOTES) ?>" hidden>
<div class="wk-rail-head">
<span class="wk-eyebrow"><?= htmlspecialchars($drawerNs !== '' ? $drawerNs : t('ns.root_title'), ENT_QUOTES) ?></span>
<button type="button" class="wk-tbtn" data-drawer-close title="<?= htmlspecialchars(t('drawer.close'), ENT_QUOTES) ?>"><i class="ph ph-x"></i></button>
</div>
<?php if ($quick['fixed'] !== []): ?>
<div class="wk-tree wk-quick">
<?php $quickMode = 'drawer';
include __DIR__ . '/partials/quick-nav.php'; ?>
</div>
<div class="wk-quick-sep"></div>
<?php endif; ?>
<div class="wk-tree">
<?php if ($drawerNs !== ''): ?>
<?php if ($quick['fixed'] === []): /* signed in, the quick list above already offers it */ ?>
<a class="wk-tree-item" href="<?= $b ?>/:"><i class="ph ph-house"></i><?= htmlspecialchars(t('drawer.root'), ENT_QUOTES) ?></a>
<?php endif; ?>
<a class="wk-tree-item" href="<?= $b ?>/<?= htmlspecialchars(implode(':', $parentSegments), ENT_QUOTES) ?>:"><i class="ph ph-arrow-up"></i><?= htmlspecialchars(t('drawer.up'), ENT_QUOTES) ?></a>
<?php endif; ?>
<a class="wk-tree-item" href="<?= $b ?><?= htmlspecialchars($nsHref, ENT_QUOTES) ?>"><i class="ph ph-folder-open"></i><?= htmlspecialchars(t('drawer.open_index'), ENT_QUOTES) ?></a>
<?php foreach ($drawerSubnamespaces as $sub): ?>
<?php $subNs = $drawerNs === '' ? $sub['name'] : $drawerNs . ':' . $sub['name']; ?>
<a class="wk-tree-item wk-d1" href="<?= $b ?>/<?= htmlspecialchars($subNs, ENT_QUOTES) ?>:"><i class="ph ph-folder"></i><?= htmlspecialchars($sub['name'], ENT_QUOTES) ?><span class="wk-count"><?= (int) $sub['count'] ?></span></a>
<?php endforeach; ?>
</div>
<?php if ($drawerRows !== []): ?>
<div class="wk-list">
<div class="wk-rail-head"><span class="wk-eyebrow"><?= htmlspecialchars(t('drawer.recent'), ENT_QUOTES) ?></span></div>
<?php foreach ($drawerRows as $row): ?>
<?php $rowPath = (string) $row['path']; ?>
<a class="wk-row<?= $rowPath === ($headerPath ?? null) ? ' wk-sel' : '' ?>" href="<?= $b ?>/<?= htmlspecialchars($rowPath, ENT_QUOTES) ?>">
<div class="wk-row-t"><?= htmlspecialchars((string) ($row['title'] ?: $rowPath), ENT_QUOTES) ?><?php if ((string) $row['visibility'] !== 'private'): ?><?= \Reporion\Support\Visibility::badge((string) $row['visibility'], true) ?><?php endif; ?></div>
<div class="wk-row-m wk-mono"><?php if ((string) $row['status'] === 'signed'): ?><i class="ph ph-seal-check wk-signed-mark" title="<?= htmlspecialchars(t('page.signed'), ENT_QUOTES) ?>"></i> <?php endif; ?><?= htmlspecialchars(\Reporion\Support\MetaText::date($row['updated'], 'd M Y'), ENT_QUOTES) ?> · rev <?= (int) $row['rev'] ?> · <?= htmlspecialchars(($row['updated_by'] ?? null) !== null ? display_name((string) $row['updated_by']) : '-', ENT_QUOTES) ?></div>
</a>
<?php endforeach; ?>
</div>
<?php endif; ?>
</aside>
