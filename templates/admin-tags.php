<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Admin → Tags (Controller\AdminTagsController) — content only. Markup
 * from design/mockup/WikiTags.dc.html; its groups, synonyms, ICD-10 codes
 * and suggested merges have nothing behind them yet and are left out.
 *
 * Variables in scope: list<array{tag: string, n: int}> $tags; ?int $changed;
 * int $skippedSigned; ?string $error; string $adminTab, $basePath
 */

declare(strict_types=1);

/** @var list<array{tag: string, n: int}> $tags */
/** @var ?int $changed */
/** @var int $skippedSigned */
/** @var ?string $error */
/** @var string $basePath */

$b = htmlspecialchars($basePath, ENT_QUOTES);
?>
<div class="wk-doc">
<div class="wk-doc-head">
<div class="wk-crumbs wk-mono"><b><?= htmlspecialchars(t('nav.admin'), ENT_QUOTES) ?></b><span>›</span><span><?= htmlspecialchars(t('admin.tags.title'), ENT_QUOTES) ?></span></div>
<div class="wk-doc-titlerow"><h1 class="wk-doc-title"><?= htmlspecialchars(t('admin.tags.title'), ENT_QUOTES) ?></h1></div>
<div class="wk-badges"><span class="tag tag-neutral"><?= htmlspecialchars(t('admin.tags.count', [\count($tags)]), ENT_QUOTES) ?></span><span class="wk-mono wk-dim"><?= htmlspecialchars(t('admin.tags.explain'), ENT_QUOTES) ?></span></div>
</div>
<?php include __DIR__ . '/admin-tabs.php'; ?>
<?php if ($error !== null): ?>
<div class="wk-notice" role="alert"><i class="ph ph-warning"></i><div><?= htmlspecialchars($error, ENT_QUOTES) ?></div></div>
<?php elseif ($changed !== null): ?>
<div class="wk-notice" role="status"><i class="ph ph-check"></i><div><?= htmlspecialchars(t('admin.tags.done', [$changed]), ENT_QUOTES) ?><?= $skippedSigned > 0 ? ' ' . htmlspecialchars(t('admin.tags.signed_left', [$skippedSigned]), ENT_QUOTES) : '' ?></div></div>
<?php endif; ?>
<?php if ($tags === []): ?>
<p class="wk-dim"><?= htmlspecialchars(t('admin.tags.empty'), ENT_QUOTES) ?></p>
<?php else: ?>
<form action="<?= $b ?>/admin/tags/merge" method="post">
<div class="wk-panel">
<div class="wk-panel-h"><span class="wk-eyebrow"><?= htmlspecialchars(t('admin.tags.merge_title'), ENT_QUOTES) ?></span>
<span style="display:flex;gap:var(--space-2);align-items:center"><input class="input wk-inline-input" type="text" name="into" required placeholder="<?= htmlspecialchars(t('admin.tags.into'), ENT_QUOTES) ?>"><button class="btn btn-secondary btn-sm" type="submit"><i class="ph ph-git-merge"></i><?= htmlspecialchars(t('admin.tags.merge'), ENT_QUOTES) ?></button></span></div>
<table class="table">
<thead><tr><th></th><th><?= htmlspecialchars(t('admin.tags.col_tag'), ENT_QUOTES) ?></th><th><?= htmlspecialchars(t('admin.tags.col_pages'), ENT_QUOTES) ?></th><th><?= htmlspecialchars(t('admin.tags.col_rename'), ENT_QUOTES) ?></th></tr></thead>
<tbody>
<?php foreach ($tags as $i => $row): ?>
<tr>
<td><input type="checkbox" name="from[]" value="<?= htmlspecialchars($row['tag'], ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('admin.tags.select', [$row['tag']]), ENT_QUOTES) ?>"></td>
<td class="wk-mono"><a href="<?= $b ?>/search?q=<?= rawurlencode($row['tag']) ?>"><?= htmlspecialchars($row['tag'], ENT_QUOTES) ?></a></td>
<td class="wk-mono"><?= $row['n'] ?></td>
<td><span style="display:flex;gap:var(--space-2);align-items:center"><input class="input wk-inline-input" type="text" name="to" form="rename-<?= $i ?>" required value="<?= htmlspecialchars($row['tag'], ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('admin.tags.new_name', [$row['tag']]), ENT_QUOTES) ?>"><button class="btn btn-ghost btn-sm" type="submit" form="rename-<?= $i ?>"><?= htmlspecialchars(t('admin.tags.rename'), ENT_QUOTES) ?></button></span></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</form>
<?php /* One rename form per tag, outside the merge form (forms cannot nest); the row's input and button join it through form="…" */ ?>
<?php foreach ($tags as $i => $row): ?>
<form id="rename-<?= $i ?>" action="<?= $b ?>/admin/tags/rename" method="post" hidden><input type="hidden" name="from" value="<?= htmlspecialchars($row['tag'], ENT_QUOTES) ?>"></form>
<?php endforeach; ?>
<?php endif; ?>
</div>
