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
<p class="wk-dim" style="font-size:13px"><?= htmlspecialchars(t('admin.trash.explain', [$purgeDays]), ENT_QUOTES) ?></p>
<?php if ($entries === []): ?>
<p class="wk-dim"><?= htmlspecialchars(t('admin.trash.empty'), ENT_QUOTES) ?></p>
<?php else: ?>
<div class="wk-panel">
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
<td><b><?= htmlspecialchars((string) $entry['title'], ENT_QUOTES) ?></b><br><span class="wk-mono wk-dim" style="font-size:11.5px"><?= htmlspecialchars((string) $entry['path'], ENT_QUOTES) ?></span>
<?php if ($entry['signed']): ?> <span class="tag tag-accent"><?= htmlspecialchars(t('admin.trash.signed'), ENT_QUOTES) ?></span><?php endif; ?></td>
<td class="wk-mono" style="font-size:12px"><?= htmlspecialchars($entry['deletedAt'] !== null ? \Reporion\Support\MetaText::when($entry['deletedAt']) : '—', ENT_QUOTES) ?><br><?= htmlspecialchars((string) ($entry['deletedBy'] ?? ''), ENT_QUOTES) ?></td>
<td class="wk-mono" style="font-size:12px"><?= $entry['signed'] ? htmlspecialchars(t('admin.trash.kept'), ENT_QUOTES) : ($entry['daysLeft'] === null ? '—' : htmlspecialchars(t('admin.trash.days', [$entry['daysLeft']]), ENT_QUOTES)) ?></td>
<td><form action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/admin/trash/<?= htmlspecialchars((string) $entry['pid'], ENT_QUOTES) ?>/restore" method="post"><button class="btn btn-secondary btn-sm" type="submit"><i class="ph ph-arrow-counter-clockwise"></i><?= htmlspecialchars(t('admin.trash.restore'), ENT_QUOTES) ?></button></form></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</div>
