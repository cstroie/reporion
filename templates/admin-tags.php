<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Admin → Tags (Controller\AdminTagsController): content only. Markup
 * from design/mockup/WikiTags.dc.html: every tag with its group, page
 * count, synonyms and ICD-10 code (Service\TagDictionary, phase 20), the
 * suggested merges (links that prefill the merge form: never applied by
 * themselves), and one tag's entry open for editing (?edit=), with its
 * rename. The mockup's filter/group facets are not built.
 *
 * Variables in scope: list<array{tag: string, n: int, group: string, icd10: string, synonyms: list<string>}> $tags;
 * list<array{into: string, from: list<string>}> $suggestions; ?string $edit;
 * ?array{group: string, icd10: string, synonyms: list<string>} $editEntry; string $mergeInto;
 * list<string> $mergeFrom; bool $saved; ?int $changed; int $skippedSigned; int $conflicts; ?string $error;
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
/** @var int $conflicts */
/** @var ?string $error */
/** @var string $basePath */
/** @var array<string, \Reporion\Service\Maintenance\MaintenanceTask> $tasks the tag tasks (Admin → Maintenance's, moved here) */
/** @var bool $busy */
/** @var ?string $maintError */
/** @var ?string $runId */
/** @var ?\Reporion\Service\Maintenance\MaintenanceReport $report */
/** @var ?bool $running */
/** @var array<string, ?array{path: string, title: string}> $pages */
/** @var int $shownItems */
/** @var ?list<array{i: int, op: string, from: array<string, int>, to: string, reason: string, source: string, done: bool, rejected: bool}> $review a finished tags:review run's proposals */

$b = htmlspecialchars($basePath, ENT_QUOTES);
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$unused = \count(array_filter($tags, static fn (array $row): bool => $row['n'] === 0));
?>
<div class="wk-doc">
<header class="wk-doc-head">
<?= \Reporion\Http\Breadcrumb::render([['label' => t('nav.admin')], ['label' => t('admin.tags.title')]]) ?>
<div class="wk-doc-titlerow"><h1 class="wk-doc-title"><?= $e(t('admin.tags.title')) ?></h1></div>
<div class="wk-badges"><span class="tag tag-neutral"><?= $e(t('admin.tags.count', [\count($tags)])) ?></span><?php if ($unused > 0): ?><span class="tag tag-outline"><?= $e(t('admin.tags.unused', [$unused])) ?></span><?php endif; ?><?php if ($suggestions !== []): ?><span class="tag tag-accent"><?= $e(t('admin.tags.candidates', [\count($suggestions)])) ?></span><?php endif; ?><span class="wk-mono wk-dim"><?= $e(t('admin.tags.explain')) ?></span></div>
</header>
<?php include __DIR__ . '/admin-tabs.php'; ?>
<?php /* The contents' grid from the first byte, not from admin-toc.js: this screen streams a long table before its
   second panel, so wiki.css's first-paint width (two panels already parsed) comes too late; the script adds the contents here */ ?>
<div class="wk-docbody wk-admintoc"><div class="wk-docgrid wk-has-toc"><div class="wk-docmain">
<?php if ($busy): ?>
<?php $noticeKind = 'warn'; $noticeText = t('admin.maint.busy'); include __DIR__ . '/partials/notice.php'; ?>
<?php endif; ?>
<?php if ($maintError !== null): ?>
<?php $noticeKind = 'error'; $noticeText = $maintError; include __DIR__ . '/partials/notice.php'; ?>
<?php endif; ?>
<?php include __DIR__ . '/partials/maint-report.php'; ?>
<?php if ($error !== null): ?>
<?php $noticeKind = 'error'; $noticeText = $error; include __DIR__ . '/partials/notice.php'; ?>
<?php elseif ($changed !== null): ?>
<div class="wk-notice wk-notice-ok" role="status"><i class="ph ph-check"></i><div><?= $e(t('admin.tags.done', [$changed])) ?><?= $skippedSigned > 0 ? ' ' . $e(t('admin.tags.signed_left', [$skippedSigned])) : '' ?><?= $conflicts > 0 ? ' ' . $e(t('admin.tags.conflicts_left', [$conflicts])) : '' ?></div></div>
<?php elseif ($saved): ?>
<?php $noticeKind = 'ok'; $noticeText = t('admin.tags.saved'); include __DIR__ . '/partials/notice.php'; ?>
<?php endif; ?>
<?php if ($review !== null && $runId !== null): ?>
<?php include __DIR__ . '/partials/tags-review.php'; ?>
<?php endif; ?>
<?php if ($edit !== null && $editEntry !== null): ?>
<div class="wk-panel" id="tag-entry">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.tags.edit_title', [$edit])) ?></h2></hgroup><a class="btn btn-secondary btn-sm" href="<?= $b ?>/admin/tags"><i class="ph ph-x" aria-hidden="true"></i><?= $e(t('editor.cancel')) ?></a></header>
<form data-busy action="<?= $b ?>/admin/tags/dictionary" method="post">
<input type="hidden" name="tag" value="<?= $e($edit) ?>">
<div class="wk-form-grid">
<label><?= $e(t('admin.tags.col_group')) ?><input class="input" type="text" name="group" maxlength="32" value="<?= $e($editEntry['group']) ?>" placeholder="<?= $e(t('admin.tags.group_hint')) ?>"></label>
<label><?= $e(t('admin.tags.col_icd10')) ?><input class="input wk-mono" type="text" name="icd10" maxlength="16" value="<?= $e($editEntry['icd10']) ?>" placeholder="<?= $e(t('admin.tags.icd10_hint')) ?>"></label>
<label><?= $e(t('admin.tags.col_synonyms')) ?><input class="input" type="text" name="synonyms" value="<?= $e(implode(', ', $editEntry['synonyms'])) ?>" placeholder="<?= $e(t('admin.tags.synonyms_hint')) ?>"><small><?= $e(t('admin.tags.synonyms_help')) ?></small></label>
</div>
<footer><button class="btn btn-primary" type="submit"><i class="ph ph-check"></i><?= $e(t('admin.settings.save')) ?></button></footer>
</form>
</div>
<div class="wk-panel" id="tag-rename">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.tags.rename')) ?></h2></hgroup></header>
<form data-busy action="<?= $b ?>/admin/tags/rename" method="post">
<input type="hidden" name="from" value="<?= $e($edit) ?>">
<div class="field"><label for="tag-rename-to"><?= $e(t('admin.tags.new_name', [$edit])) ?>
<input class="input" type="text" id="tag-rename-to" name="to" required value="<?= $e($edit) ?>"></label></div>
<footer><button class="btn btn-secondary" type="submit"><i class="ph ph-pencil-simple"></i><?= $e(t('admin.tags.rename')) ?></button></footer>
</form>
</div>
<?php endif; ?>
<?php if ($suggestions !== []): ?>
<div class="wk-panel" id="suggest-merge">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><i class="ph ph-sparkle"></i> <?= $e(t('admin.tags.suggested')) ?></h2></hgroup></header>
<p class="wk-mono wk-chiplist">
<?php foreach ($suggestions as $s): ?>
<a class="wk-chip" href="<?= $b ?>/admin/tags?<?= $e(http_build_query(['into' => $s['into'], 'from' => implode(',', [$s['into'], ...$s['from']])])) ?>#merge" title="<?= $e(t('admin.tags.suggest_apply')) ?>"><?= $e($s['into']) ?></a> ← <?= implode(', ', array_map(static fn (string $t): string => '<span class="wk-chip">' . htmlspecialchars($t, ENT_QUOTES) . '</span>', $s['from'])) ?><br>
<?php endforeach; ?>
</p>
</div>
<?php endif; ?>
<?php if ($tags === []): ?>
<p class="wk-dim"><?= $e(t('admin.tags.empty')) ?></p>
<?php else: ?>
<div class="wk-panel" id="merge">
<form data-busy action="<?= $b ?>/admin/tags/merge" method="post" id="merge-form">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.tags.merge_title')) ?></h2></hgroup>
<span class="wk-actions"><span class="group"><input class="input wk-inline-input" type="text" name="into" required value="<?= $e($mergeInto) ?>" placeholder="<?= $e(t('admin.tags.into_hint')) ?>"><button class="btn btn-secondary btn-sm" type="submit" data-needs-selection><i class="ph ph-git-merge"></i><?= $e(t('admin.tags.merge')) ?></button></span></span></header>
<table class="table table-cards">
<thead><tr><th class="wk-selcol"><label class="radio" id="tags-selall" hidden><input type="checkbox" aria-label="<?= $e(t('admin.tags.select_all')) ?>"><span class="dot"></span></label></th><th><?= $e(t('admin.tags.col_tag')) ?></th><th><?= $e(t('admin.tags.col_group')) ?></th><th><?= $e(t('admin.tags.col_pages')) ?></th><th><?= $e(t('admin.tags.col_synonyms')) ?></th><th><?= $e(t('admin.tags.col_icd10')) ?></th><th></th></tr></thead>
<tbody>
<?php foreach ($tags as $row): ?>
<tr<?= $row['tag'] === $edit ? ' class="wk-sel"' : '' ?>>
<td class="wk-selcol"><?php if ($row['n'] > 0): ?><label class="radio"><input type="checkbox" name="from[]" value="<?= $e($row['tag']) ?>"<?= \in_array($row['tag'], $mergeFrom, true) ? ' checked' : '' ?> aria-label="<?= $e(t('admin.tags.select', [$row['tag']])) ?>"><span class="dot"></span></label><?php endif; ?></td>
<td class="wk-card-head wk-mono<?= $row['n'] === 0 ? ' wk-dim' : '' ?>"><a href="<?= $b ?>/search?q=<?= rawurlencode($row['tag']) ?>"><?= $e($row['tag']) ?></a></td>
<td data-label="<?= $e(t('admin.tags.col_group')) ?>"<?= $row['group'] === '' ? ' class="wk-nocard"' : '' ?>><?= $row['group'] !== '' ? $e($row['group']) : '<span class="wk-dim">n/a</span>' ?></td>
<td class="wk-mono<?= $row['n'] === 0 ? ' wk-dim' : '' ?>" data-label="<?= $e(t('admin.tags.col_pages')) ?>"><?= $row['n'] ?></td>
<td class="wk-mono wk-dim<?= $row['synonyms'] === [] ? ' wk-nocard' : '' ?>" data-label="<?= $e(t('admin.tags.col_synonyms')) ?>"><?= $row['synonyms'] !== [] ? $e(implode(', ', $row['synonyms'])) : 'n/a' ?></td>
<td class="<?= $row['icd10'] !== '' ? 'wk-mono' : 'wk-dim wk-nocard' ?>" data-label="<?= $e(t('admin.tags.col_icd10')) ?>"><?= $row['icd10'] !== '' ? $e($row['icd10']) : 'n/a' ?></td>
<td class="wk-right"><a class="btn btn-ghost btn-sm" href="<?= $b ?>/admin/tags?edit=<?= rawurlencode($row['tag']) ?>#tag-entry"><i class="ph ph-pencil-simple" aria-hidden="true"></i><?= $e(t('admin.tags.edit')) ?></a></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</form>
</div>
<script>
(function() {
  // As the namespace table: select all, the row tint, and Merge enabled once something is picked
  var form = document.getElementById('merge-form');
  var boxes = Array.prototype.slice.call(form.querySelectorAll('input[name="from[]"]'));
  var all = document.getElementById('tags-selall');
  var buttons = Array.prototype.slice.call(form.querySelectorAll('[data-needs-selection]'));
  if (boxes.length === 0) return;
  all.hidden = false;
  var allBox = all.querySelector('input');
  function sync() {
    var n = boxes.filter(function(b) { return b.checked; }).length;
    boxes.forEach(function(b) { b.closest('tr').classList.toggle('wk-picked', b.checked); });
    allBox.checked = n > 0 && n === boxes.length;
    allBox.indeterminate = n > 0 && n < boxes.length;
    buttons.forEach(function(b) { b.disabled = n === 0; });
  }
  boxes.forEach(function(b) { b.addEventListener('change', sync); });
  allBox.addEventListener('change', function() {
    boxes.forEach(function(b) { b.checked = allBox.checked; });
    sync();
  });
  sync();
})();
</script>
<?php endif; ?>
<?php /* The tag tasks of Admin → Maintenance: they post to the same route and come back here with their report */ ?>
<?php foreach ($tasks as $name => $task): ?>
<?php $return = 'tags'; include __DIR__ . '/partials/maint-task.php'; ?>
<?php endforeach; ?>
</div></div></div>
</div>
