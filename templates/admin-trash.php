<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Admin → Trash (Controller\AdminTrashController) — content only.
 *
 * Variables in scope: list<array{pid, path, title, status, signed, deletedAt, deletedBy, daysLeft}> $entries;
 * int $purgeDays; string $adminTab, $basePath
 */

declare(strict_types=1);

/** @var list<array<string, mixed>> $entries */
/** @var int $purgeDays */
/** @var string $basePath */
?>
<div class="wk-doc">
<div class="wk-doc-head">
<div class="wk-crumbs wk-mono"><b><?= htmlspecialchars(t('nav.admin'), ENT_QUOTES) ?></b><span>›</span><span><?= htmlspecialchars(t('admin.trash.title'), ENT_QUOTES) ?></span></div>
<h1 class="wk-doc-title"><?= htmlspecialchars(t('admin.trash.title'), ENT_QUOTES) ?></h1>
</div>
<?php include __DIR__ . '/admin-tabs.php'; ?>
<div class="wk-panel">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= htmlspecialchars(t('admin.trash.panel'), ENT_QUOTES) ?></h2><p class="wk-dim"><?= htmlspecialchars(t('admin.trash.explain', [$purgeDays]), ENT_QUOTES) ?></p></hgroup>
<?php if ($entries !== []): ?>
<form class="wk-actions" action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/admin/maintenance/trash:purge" method="post" data-confirm="<?= htmlspecialchars(t('admin.trash.empty_prompt'), ENT_QUOTES) ?>" data-confirm-title="<?= htmlspecialchars(t('admin.trash.empty_title'), ENT_QUOTES) ?>" data-confirm-label="<?= htmlspecialchars(t('admin.trash.empty_action'), ENT_QUOTES) ?>">
<input type="hidden" name="mode" value="apply">
<input type="hidden" name="older_than" value="0">
<input type="hidden" name="confirm" value="1">
<button class="btn btn-danger btn-sm" type="submit"><i class="ph ph-trash"></i><?= htmlspecialchars(t('admin.trash.empty_action'), ENT_QUOTES) ?></button>
</form>
<?php endif; ?>
</header>
<?php if ($entries === []): ?>
<p class="wk-dim"><?= htmlspecialchars(t('admin.trash.empty'), ENT_QUOTES) ?></p>
<?php else: ?>
<table class="table">
<thead><tr>
<th><?= htmlspecialchars(t('admin.trash.col_page'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('admin.trash.col_deleted'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('admin.trash.col_purge'), ENT_QUOTES) ?></th>
<th></th>
</tr></thead>
<tbody>
<?php foreach ($entries as $entry): ?>
<tr>
<td><b><?= htmlspecialchars((string) $entry['title'], ENT_QUOTES) ?></b><br><span class="wk-mono wk-dim wk-text-sm"><?= htmlspecialchars((string) $entry['path'], ENT_QUOTES) ?></span>
<?php if ($entry['signed']): ?> <span class="tag tag-accent"><?= htmlspecialchars(t('admin.trash.signed'), ENT_QUOTES) ?></span><?php endif; ?></td>
<td class="wk-mono wk-text-sm"><?= htmlspecialchars($entry['deletedAt'] !== null ? \Reporion\Support\MetaText::when($entry['deletedAt']) : '—', ENT_QUOTES) ?><br><?= htmlspecialchars((string) ($entry['deletedBy'] ?? ''), ENT_QUOTES) ?></td>
<td class="wk-mono wk-text-sm"><?= $entry['signed'] ? htmlspecialchars(t('admin.trash.kept'), ENT_QUOTES) : ($entry['daysLeft'] === null ? '—' : htmlspecialchars(t('admin.trash.days', [$entry['daysLeft']]), ENT_QUOTES)) ?></td>
<td><form action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/admin/trash/<?= htmlspecialchars((string) $entry['pid'], ENT_QUOTES) ?>/restore" method="post"><button class="btn btn-secondary btn-sm" type="submit"><i class="ph ph-arrow-counter-clockwise"></i><?= htmlspecialchars(t('admin.trash.restore'), ENT_QUOTES) ?></button></form></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>
</div>
