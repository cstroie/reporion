<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /{ns}: (Controller\NamespaceController). See that controller's
 * docblock for what was deliberately left out of this port of
 * design/mockup/WikiNsIndex.dc.html.
 *
 * Variables in scope (Controller\NamespaceController::index()):
 * string $ns, $basePath; bool $canCreateHere
 * $ns === '' is the root namespace (GET /:) — every top-level namespace
 * in the tree is one of its "sub-namespaces" here.
 * list<array{name: string, count: int, title: ?string, summary: ?string, priority: ?string}> $subnamespaces; ?string $nsLabel
 * list<array<string, mixed>> $pages
 * ?array<string, mixed> $nsIndex, $nsTemplate — the `_index`/`_template`
 * reserved-page rows (docs/architecture-storage-index.md's segment-prefix
 * convention), null when absent or not visible to this caller
 * ?string $nsDescriptionHtml — $nsIndex's body, already rendered
 * list<array{year: string, count: int}> $years — distinct study_date years
 * among this namespace's direct pages, most recent first (empty when
 * there's nothing to filter, e.g. no study_date at all, or only one year)
 * string $yearFilter — the active year card: a "YYYY" year, or "all"
 * bool $isReports — under reports: the date column is the exam date
 * (study_date, date only), not the last update
 * bool $canSelect, $canBulkWrite — the selection column (any signed-in
 * caller: Export needs read access only) and Move/Tag (write access here);
 * ?array $bulkDone — what a bulk action just did; list $recent — the most
 * recently updated pages here (Index::listWorklist())
 */

declare(strict_types=1);

/** @var string $ns */
/** @var list<array{name: string, count: int, title: ?string, summary: ?string, priority: ?string}> $subnamespaces */
/** @var list<array<string, mixed>> $pages */
/** @var list<array{year: string, count: int}> $years */
/** @var string $yearFilter */
/** @var bool $isReports */
/** @var bool $canCreateHere */
/** @var string $basePath */
/** @var array<string, mixed>|null $nsIndex */
/** @var array<string, mixed>|null $nsTemplate */
/** @var ?string $nsDescriptionHtml */
/** @var ?string $descriptionPath */
/** @var list<string> $nsTags */
/** @var ?string $nsSummary */
/** @var string $nsVisibility */
/** @var bool $canSelect */
/** @var bool $canBulkWrite */
/** @var array{action: string, n: int, failed: int, signed: int}|null $bulkDone */
/** @var list<array<string, mixed>> $recent */
?>
<?php
// Joins a child page/namespace name onto $ns without producing a leading
// ":" at the root (where $ns === '' has no segment to prefix).
$childPath = static fn (string $name): string => $ns === '' ? $name : $ns . ':' . $name;
// The namespace's own URL (same shape as the crumbs' root/segment links),
// with the year filter set/cleared as its only query param — a plain link
// per card, same toggle-one-query-param mechanic as dashboard.php's $link().
$nsUrl = $basePath . '/' . ($ns === '' ? '' : $ns) . ':';
$yearLink = static fn (string $year): string => $nsUrl . '?year=' . urlencode($year);
// A page with no printable title (an imported stub, a page whose title
// field was left blank) is called by the last segment of its own path —
// "llm:skills:clinicgen" reads "clinicgen" — rather than the full path.
$pageLabel = static function (array $page): string {
    $title = trim((string) ($page['title'] ?? ''));
    if ($title !== '') {
        return $title;
    }
    $segments = explode(':', (string) $page['path']);

    return (string) end($segments);
};
// Called by its description's title when it has one ("MEDIC line"), else by its path
$nsTitle = ($nsLabel ?? null) ?? ($ns !== '' ? $ns : t('ns.root_title'));
?>
<div class="wk-doc">
<div class="wk-doc-head">
<?php
if ($ns === '') {
    $trail = [['label' => $nsTitle]];
} else {
    $parents = explode(':', $ns);
    $here = array_pop($parents);
    $trail = \Reporion\Http\Breadcrumb::namespaceTrail($basePath, implode(':', $parents));
    $trail[] = ['label' => $here];
}
echo \Reporion\Http\Breadcrumb::render($trail, '<span class="tag tag-neutral">' . htmlspecialchars(t('ns.badge'), ENT_QUOTES) . '</span>');
?>
<div class="wk-doc-titlerow">
<h1 class="wk-doc-title"><?= htmlspecialchars($nsTitle, ENT_QUOTES) ?></h1>
<?php if ($canCreateHere): ?>
<div class="wk-actions">
<?php if ($ns !== '' && ($nsDescriptionHtml === null || $descriptionPath === null)): ?>
<?php /* The namespace's description is a page of the same name */ ?>
<a class="btn btn-secondary" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($ns, ENT_QUOTES) ?>/edit" title="<?= htmlspecialchars(t('ns.description_add_help'), ENT_QUOTES) ?>"><i class="ph ph-note-pencil"></i><span class="wk-btn-label"><?= htmlspecialchars(t('ns.description_add'), ENT_QUOTES) ?></span></a>
<?php endif; ?>
<?php if ($ns !== '' && $nsDescriptionHtml !== null && $descriptionPath !== null): ?>
<a class="btn btn-secondary" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($descriptionPath, ENT_QUOTES) ?>/edit" title="<?= htmlspecialchars(t('ns.description_edit'), ENT_QUOTES) ?>"><i class="ph ph-pencil-simple"></i><span class="wk-btn-label"><?= htmlspecialchars(t('ns.description_edit'), ENT_QUOTES) ?></span></a>
<?php endif; ?>
<?php $newLabel = htmlspecialchars(t(($newIsReport ?? false) ? 'ns.new_report' : 'ns.new_page'), ENT_QUOTES); ?>
<a class="btn btn-primary" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?><?= $ns === '' ? '' : '/' . htmlspecialchars($ns, ENT_QUOTES) ?>/new" title="<?= $newLabel ?>"><i class="ph ph-plus"></i><span class="wk-btn-label"><?= $newLabel ?></span></a>
</div>
<?php endif; ?>
</div>
<?php if ($nsSummary !== null): ?>
<p class="wk-dim wk-help"><?= htmlspecialchars($nsSummary, ENT_QUOTES) ?></p>
<?php endif; ?>
<div class="wk-badges">
<span class="tag tag-neutral"><?= htmlspecialchars(t('ns.direct_page_count', [\count($pages)]), ENT_QUOTES) ?></span>
<?php if ($nsVisibility !== ''): ?>
<span class="tag <?= \Reporion\Support\Badges::visibilityTag($nsVisibility) ?>"><?= htmlspecialchars($nsVisibility, ENT_QUOTES) ?></span>
<?php endif; ?>
<?php foreach ($nsTags as $tag): ?>
<span class="tag tag-outline"><?= htmlspecialchars($tag, ENT_QUOTES) ?></span>
<?php endforeach; ?>
</div>
</div>

<?php if ($subnamespaces !== [] || $nsIndex !== null || $nsTemplate !== null): ?>
<div class="wk-cards">
<?php foreach ($subnamespaces as $sub): ?>
<a class="wk-nscard" data-priority="<?= htmlspecialchars((string) ($sub['priority'] ?? ''), ENT_QUOTES) ?>" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($childPath($sub['name']), ENT_QUOTES) ?>:">
<span class="wk-eyebrow"><?= htmlspecialchars(t('ns.subnamespace'), ENT_QUOTES) ?></span>
<?php if (($sub['title'] ?? null) !== null): ?>
<b><?= htmlspecialchars($sub['title'], ENT_QUOTES) ?></b>
<span class="wk-dim wk-mono"><?= htmlspecialchars($sub['name'], ENT_QUOTES) ?></span>
<?php else: ?>
<b class="wk-mono"><?= htmlspecialchars($sub['name'], ENT_QUOTES) ?></b>
<?php endif; ?>
<?php if (($sub['summary'] ?? null) !== null): ?>
<span class="wk-row-s"><?= htmlspecialchars($sub['summary'], ENT_QUOTES) ?></span>
<?php endif; ?>
<span class="wk-dim wk-mono"><?= htmlspecialchars(t('ns.page_count', [$sub['count']]), ENT_QUOTES) ?></span>
</a>
<?php endforeach; ?>
<?php if ($nsIndex !== null): ?>
<a class="wk-nscard" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($childPath('_index'), ENT_QUOTES) ?>">
<span class="wk-eyebrow"><?= htmlspecialchars(t('ns.reserved_page'), ENT_QUOTES) ?></span>
<b class="wk-mono">_index</b>
<span class="wk-dim wk-mono"><?= htmlspecialchars(t('ns.index_card_note', [(string) $nsIndex['visibility']]), ENT_QUOTES) ?></span>
</a>
<?php endif; ?>
<?php if ($nsTemplate !== null): ?>
<a class="wk-nscard" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($childPath('_template'), ENT_QUOTES) ?>">
<span class="wk-eyebrow"><?= htmlspecialchars(t('ns.reserved_page'), ENT_QUOTES) ?></span>
<b class="wk-mono">_template</b>
<span class="wk-dim wk-mono"><?= htmlspecialchars(t('ns.template_card_note'), ENT_QUOTES) ?></span>
</a>
<?php endif; ?>
</div>
<?php endif; ?>

<?php if (\count($years) > 1): ?>
<div class="wk-yearcards" aria-label="<?= htmlspecialchars(t('ns.year_filter'), ENT_QUOTES) ?>">
<a class="wk-yearcard<?= $yearFilter === 'all' ? ' wk-yearcard-on' : '' ?>" href="<?= htmlspecialchars($yearLink('all'), ENT_QUOTES) ?>">
<b><?= htmlspecialchars(t('ns.year_all'), ENT_QUOTES) ?></b>
<span><?= array_sum(array_column($years, 'count')) ?></span>
</a>
<?php foreach ($years as $y): ?>
<a class="wk-yearcard<?= $yearFilter === $y['year'] ? ' wk-yearcard-on' : '' ?>" href="<?= htmlspecialchars($yearLink($y['year']), ENT_QUOTES) ?>">
<b><?= htmlspecialchars($y['year'], ENT_QUOTES) ?></b>
<span><?= $y['count'] ?></span>
</a>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($bulkDone !== null): ?>
<div class="wk-notice" role="status"><i class="ph ph-check"></i><div>
<?= htmlspecialchars(t('ns.bulk_done_' . $bulkDone['action'], [$bulkDone['n']]), ENT_QUOTES) ?>
<?php if ($bulkDone['failed'] > 0): ?> <?= htmlspecialchars(t('ns.bulk_done_failed', [$bulkDone['failed']]), ENT_QUOTES) ?><?php endif; ?>
<?php if ($bulkDone['signed'] > 0): ?> <?= htmlspecialchars(t('ns.bulk_done_signed', [$bulkDone['signed']]), ENT_QUOTES) ?><?php endif; ?>
</div></div>
<?php endif; ?>

<?php if ($pages !== []): ?>
<?php if ($canSelect): ?>
<form class="wk-panel" id="ns-bulk" method="post" action="<?= htmlspecialchars($nsUrl, ENT_QUOTES) ?>">
<input type="hidden" name="year" value="<?= htmlspecialchars($yearFilter, ENT_QUOTES) ?>">
<input type="hidden" name="ns" value="<?= htmlspecialchars($ns, ENT_QUOTES) ?>">
<header class="wk-panel-h"><h2 class="wk-eyebrow"><?= htmlspecialchars(t('ns.pages_here'), ENT_QUOTES) ?></h2><div class="wk-actions">
<span class="wk-mono wk-dim" id="ns-selcount" data-template="<?= htmlspecialchars(t('ns.selected'), ENT_QUOTES) ?>" hidden></span>
<?php if ($canBulkWrite): ?>
<button type="submit" class="btn btn-secondary" name="action" value="move" data-needs-selection title="<?= htmlspecialchars(t('ns.bulk_move'), ENT_QUOTES) ?>"><i class="ph ph-arrow-elbow-down-right"></i><span class="wk-btn-label"><?= htmlspecialchars(t('ns.bulk_move'), ENT_QUOTES) ?></span></button>
<button type="submit" class="btn btn-secondary" name="action" value="tag" data-needs-selection title="<?= htmlspecialchars(t('ns.bulk_tag'), ENT_QUOTES) ?>"><i class="ph ph-tag"></i><span class="wk-btn-label"><?= htmlspecialchars(t('ns.bulk_tag'), ENT_QUOTES) ?></span></button>
<?php endif; ?>
<button type="submit" class="btn btn-secondary" formaction="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/export/bundle.zip" title="<?= htmlspecialchars(t('ns.bulk_export_help', [\Reporion\Controller\ExportController::BUNDLE_MAX]), ENT_QUOTES) ?>" data-needs-selection><i class="ph ph-export"></i><span class="wk-btn-label"><?= htmlspecialchars(t('ns.bulk_export'), ENT_QUOTES) ?></span></button>
</div></header>
<?php else: ?>
<div class="wk-panel">
<header class="wk-panel-h"><h2 class="wk-eyebrow"><?= htmlspecialchars(t('ns.pages_here'), ENT_QUOTES) ?></h2></header>
<?php endif; ?>
<table class="table">
<thead><tr>
<?php if ($canSelect): ?>
<th class="wk-selcol"><label class="radio" id="ns-selall" hidden><input type="checkbox" aria-label="<?= htmlspecialchars(t('ns.select_all'), ENT_QUOTES) ?>"><span class="dot"></span></label></th>
<?php endif; ?>
<th><?= htmlspecialchars(t('ns.col_title'), ENT_QUOTES) ?></th>
<?php if ($isReports): ?>
<th><?= htmlspecialchars(t('ns.col_pacs'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('ns.col_region'), ENT_QUOTES) ?></th>
<?php endif; ?>
<th><?= htmlspecialchars(t('ns.col_status'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('ns.col_visibility'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t($isReports ? 'ns.col_exam_date' : 'ns.col_updated'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('ns.col_by'), ENT_QUOTES) ?></th>
</tr></thead>
<tbody>
<?php foreach ($pages as $page): ?>
<tr>
<?php if ($canSelect): ?>
<td class="wk-selcol"><label class="radio"><input type="checkbox" name="paths[]" value="<?= htmlspecialchars((string) $page['path'], ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('ns.col_select') . ': ' . $pageLabel($page), ENT_QUOTES) ?>"><span class="dot"></span></label></td>
<?php endif; ?>
<td><a href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars((string) $page['path'], ENT_QUOTES) ?>"><?= htmlspecialchars($pageLabel($page), ENT_QUOTES) ?></a><?php if (trim((string) ($page['summary'] ?? '')) !== ''): ?><br><span class="wk-row-s"><?= htmlspecialchars(\Reporion\Support\Snippet::words((string) $page['summary'], 40), ENT_QUOTES) ?></span><?php endif; ?></td>
<?php if ($isReports): ?>
<td><?php if (trim((string) ($page['study_uid'] ?? '')) !== ''): ?><i class="ph ph-link wk-signed-mark" title="<?= htmlspecialchars(t('ns.pacs_linked'), ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('ns.pacs_linked'), ENT_QUOTES) ?>"></i><?php endif; ?></td>
<td><?= htmlspecialchars((string) ($page['region'] ?? ''), ENT_QUOTES) ?></td>
<?php endif; ?>
<td><span class="tag <?= \Reporion\Support\Badges::statusTag((string) $page['status']) ?>"><?= htmlspecialchars((string) $page['status'], ENT_QUOTES) ?></span></td>
<td><span class="tag <?= \Reporion\Support\Badges::visibilityTag((string) $page['visibility']) ?>"><?= htmlspecialchars((string) $page['visibility'], ENT_QUOTES) ?></span></td>
<td class="wk-mono"><?= htmlspecialchars($isReports ? \Reporion\Support\MetaText::date($page['study_date'] ?? null, 'd M Y') : \Reporion\Support\MetaText::when($page['updated'] ?? null), ENT_QUOTES) ?></td>
<td class="wk-mono"><?= htmlspecialchars(display_name((string) ($page['updated_by'] ?? '')), ENT_QUOTES) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?= $canSelect ? '</form>' : '</div>' ?>
<?php endif; ?>

<?php if ($recent !== []): ?>
<div class="wk-panel">
<header class="wk-panel-h"><h2 class="wk-eyebrow"><?= htmlspecialchars(t('ns.recent'), ENT_QUOTES) ?></h2></header>
<p class="wk-mono wk-dim wk-activity">
<?php foreach ($recent as $i => $row): ?>
<?php $segments = explode(':', (string) $row['path']); ?>
<?= $i > 0 ? '<br>' : '' ?><?= htmlspecialchars(\Reporion\Support\MetaText::when($row['updated'] ?? null), ENT_QUOTES) ?> · <?= htmlspecialchars(display_name((string) ($row['updated_by'] ?? '')), ENT_QUOTES) ?> · <?= htmlspecialchars((string) $row['status'] === 'signed' ? t('ns.recent_signed') : t('ns.recent_rev', [(int) $row['rev']]), ENT_QUOTES) ?> · <a href="<?= htmlspecialchars($basePath . '/' . $row['path'], ENT_QUOTES) ?>"><?= htmlspecialchars((string) end($segments), ENT_QUOTES) ?></a>
<?php endforeach; ?>
</p>
</div>
<?php endif; ?>

<?php if ($nsDescriptionHtml !== null && $descriptionPath !== null): ?>
<div class="wk-prose">
<?= $nsDescriptionHtml ?>
</div>
<?php endif; ?>
</div>
<?php if ($canSelect && $pages !== []): ?>
<script>
(function() {
  var form = document.getElementById('ns-bulk');
  if (!form) return;
  var boxes = Array.prototype.slice.call(form.querySelectorAll('tbody input[name="paths[]"]'));
  var all = document.getElementById('ns-selall');
  var count = document.getElementById('ns-selcount');
  var buttons = Array.prototype.slice.call(form.querySelectorAll('[data-needs-selection]'));
  all.hidden = false;
  var allBox = all.querySelector('input');
  function sync() {
    var n = boxes.filter(function(b) { return b.checked; }).length;
    boxes.forEach(function(b) { b.closest('tr').classList.toggle('wk-sel', b.checked); });
    allBox.checked = n > 0 && n === boxes.length;
    allBox.indeterminate = n > 0 && n < boxes.length;
    count.hidden = n === 0;
    count.textContent = count.dataset.template.replace('%d', n);
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
