<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Admin → Tags (Controller\AdminTagsController) — content only. Markup
 * from design/mockup/WikiTags.dc.html: every tag with its group, page
 * count, synonyms and ICD-10 code (Service\TagDictionary, phase 20), the
 * suggested merges (links that prefill the merge form — never applied by
 * themselves), and one tag's entry open for editing (?edit=), with its
 * rename. The mockup's filter/group facets are not built.
 *
 * Variables in scope: list<array{tag: string, n: int, group: string, icd10: string, synonyms: list<string>}> $tags;
 * list<array{into: string, from: list<string>}> $suggestions; ?string $edit;
 * ?array{group: string, icd10: string, synonyms: list<string>} $editEntry; string $mergeInto;
 * list<string> $mergeFrom; bool $saved; ?int $changed; int $skippedSigned; ?string $error;
 * string $adminTab, $basePath
 */

declare(strict_types=1);

/** @var list<array{tag: string, n: int, group: string, icd10: string, synonyms: list<string>}> $tags */
/** @var list<array{into: string, from: list<string>}> $suggestions */
/** @var ?string $edit */
/** @var ?array{group: string, icd10: string, synonyms: list<string>} $editEntry */
/** @var string $mergeInto */
/** @var list<string> $mergeFrom */
/** @var bool $saved */
/** @var ?int $changed */
/** @var int $skippedSigned */
/** @var ?string $error */
/** @var string $basePath */

$b = htmlspecialchars($basePath, ENT_QUOTES);
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$unused = \count(array_filter($tags, static fn (array $row): bool => $row['n'] === 0));
?>
<div class="wk-doc">
<div class="wk-doc-head">
<div class="wk-crumbs wk-mono"><b><?= $e(t('nav.admin')) ?></b><span>›</span><span><?= $e(t('admin.tags.title')) ?></span></div>
<div class="wk-doc-titlerow"><h1 class="wk-doc-title"><?= $e(t('admin.tags.title')) ?></h1></div>
<div class="wk-badges"><span class="tag tag-neutral"><?= $e(t('admin.tags.count', [\count($tags)])) ?></span><?php if ($unused > 0): ?><span class="tag tag-outline"><?= $e(t('admin.tags.unused', [$unused])) ?></span><?php endif; ?><?php if ($suggestions !== []): ?><span class="tag tag-accent"><?= $e(t('admin.tags.candidates', [\count($suggestions)])) ?></span><?php endif; ?><span class="wk-mono wk-dim"><?= $e(t('admin.tags.explain')) ?></span></div>
</div>
<?php include __DIR__ . '/admin-tabs.php'; ?>
<?php if ($error !== null): ?>
<div class="wk-notice" role="alert"><i class="ph ph-warning"></i><div><?= $e($error) ?></div></div>
<?php elseif ($changed !== null): ?>
<div class="wk-notice" role="status"><i class="ph ph-check"></i><div><?= $e(t('admin.tags.done', [$changed])) ?><?= $skippedSigned > 0 ? ' ' . $e(t('admin.tags.signed_left', [$skippedSigned])) : '' ?></div></div>
<?php elseif ($saved): ?>
<div class="wk-notice" role="status"><i class="ph ph-check"></i><div><?= $e(t('admin.tags.saved')) ?></div></div>
<?php endif; ?>
<?php if ($edit !== null && $editEntry !== null): ?>
<div class="wk-panel" id="tag-entry">
<div class="wk-panel-h"><span class="wk-eyebrow"><?= $e(t('admin.tags.edit_title', [$edit])) ?></span><a class="btn btn-secondary btn-sm" href="<?= $b ?>/admin/tags"><?= $e(t('editor.cancel')) ?></a></div>
<form action="<?= $b ?>/admin/tags/dictionary" method="post">
<input type="hidden" name="tag" value="<?= $e($edit) ?>">
<div class="wk-form-grid">
<label><?= $e(t('admin.tags.col_group')) ?><input class="input" type="text" name="group" maxlength="32" value="<?= $e($editEntry['group']) ?>" placeholder="<?= $e(t('admin.tags.group_hint')) ?>"></label>
<label><?= $e(t('admin.tags.col_icd10')) ?><input class="input wk-mono" type="text" name="icd10" maxlength="16" value="<?= $e($editEntry['icd10']) ?>" placeholder="G35"></label>
<label><?= $e(t('admin.tags.col_synonyms')) ?><input class="input" type="text" name="synonyms" value="<?= $e(implode(', ', $editEntry['synonyms'])) ?>" placeholder="<?= $e(t('admin.tags.synonyms_hint')) ?>"><small class="wk-dim"><?= $e(t('admin.tags.synonyms_help')) ?></small></label>
</div>
<p style="margin:var(--space-3) 0 0"><button class="btn btn-primary" type="submit"><i class="ph ph-check"></i><?= $e(t('admin.settings.save')) ?></button></p>
</form>
<form action="<?= $b ?>/admin/tags/rename" method="post" style="display:flex;gap:var(--space-2);align-items:center;flex-wrap:wrap;margin-top:var(--space-4);padding-top:var(--space-3);border-top:1px solid var(--color-divider)">
<input type="hidden" name="from" value="<?= $e($edit) ?>">
<span class="wk-dim" style="font-size:var(--text-sm)"><?= $e(t('admin.tags.col_rename')) ?></span>
<input class="input" style="max-width:244.5px" type="text" name="to" required value="<?= $e($edit) ?>" aria-label="<?= $e(t('admin.tags.new_name', [$edit])) ?>">
<button class="btn btn-secondary" type="submit"><i class="ph ph-pencil-simple"></i><?= $e(t('admin.tags.rename')) ?></button>
</form>
</div>
<?php endif; ?>
<?php if ($suggestions !== []): ?>
<div class="wk-panel">
<div class="wk-panel-h"><span class="wk-eyebrow"><i class="ph ph-sparkle"></i> <?= $e(t('admin.tags.suggested')) ?></span></div>
<p class="wk-mono" style="font-size:11.5px;line-height:1.8;margin:0">
<?php foreach ($suggestions as $s): ?>
<a class="wk-chip" href="<?= $b ?>/admin/tags?<?= $e(http_build_query(['into' => $s['into'], 'from' => implode(',', [$s['into'], ...$s['from']])])) ?>#merge" title="<?= $e(t('admin.tags.suggest_apply')) ?>"><?= $e($s['into']) ?></a> ← <?= implode(', ', array_map(static fn (string $t): string => '<span class="wk-chip">' . htmlspecialchars($t, ENT_QUOTES) . '</span>', $s['from'])) ?><br>
<?php endforeach; ?>
</p>
</div>
<?php endif; ?>
<?php if ($tags === []): ?>
<p class="wk-dim"><?= $e(t('admin.tags.empty')) ?></p>
<?php else: ?>
<form action="<?= $b ?>/admin/tags/merge" method="post" id="merge">
<div class="wk-panel">
<div class="wk-panel-h"><span class="wk-eyebrow"><?= $e(t('admin.tags.merge_title')) ?></span>
<span style="display:flex;gap:var(--space-2);align-items:center"><input class="input wk-inline-input" type="text" name="into" required value="<?= $e($mergeInto) ?>" placeholder="<?= $e(t('admin.tags.into')) ?>"><button class="btn btn-secondary btn-sm" type="submit"><i class="ph ph-git-merge"></i><?= $e(t('admin.tags.merge')) ?></button></span></div>
<table class="table">
<thead><tr><th></th><th><?= $e(t('admin.tags.col_tag')) ?></th><th><?= $e(t('admin.tags.col_group')) ?></th><th><?= $e(t('admin.tags.col_pages')) ?></th><th><?= $e(t('admin.tags.col_synonyms')) ?></th><th><?= $e(t('admin.tags.col_icd10')) ?></th><th></th></tr></thead>
<tbody>
<?php foreach ($tags as $row): ?>
<tr<?= $row['tag'] === $edit ? ' class="wk-sel"' : '' ?>>
<td><?php if ($row['n'] > 0): ?><input type="checkbox" name="from[]" value="<?= $e($row['tag']) ?>"<?= \in_array($row['tag'], $mergeFrom, true) ? ' checked' : '' ?> aria-label="<?= $e(t('admin.tags.select', [$row['tag']])) ?>"><?php endif; ?></td>
<td class="wk-mono<?= $row['n'] === 0 ? ' wk-dim' : '' ?>"><a href="<?= $b ?>/search?q=<?= rawurlencode($row['tag']) ?>"><?= $e($row['tag']) ?></a></td>
<td><?= $row['group'] !== '' ? $e($row['group']) : '<span class="wk-dim">—</span>' ?></td>
<td class="wk-mono<?= $row['n'] === 0 ? ' wk-dim' : '' ?>"><?= $row['n'] ?></td>
<td class="wk-mono wk-dim"><?= $row['synonyms'] !== [] ? $e(implode(', ', $row['synonyms'])) : '—' ?></td>
<td class="<?= $row['icd10'] !== '' ? 'wk-mono' : 'wk-dim' ?>"><?= $row['icd10'] !== '' ? $e($row['icd10']) : '—' ?></td>
<td style="text-align:right"><a class="btn btn-ghost btn-sm" href="<?= $b ?>/admin/tags?edit=<?= rawurlencode($row['tag']) ?>#tag-entry"><?= $e(t('admin.tags.edit')) ?></a></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</form>
<?php endif; ?>
</div>
