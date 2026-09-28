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
 * list<array{name: string, count: int, title: ?string, summary: ?string}> $subnamespaces; ?string $nsLabel
 * list<array<string, mixed>> $pages
 * ?array<string, mixed> $nsIndex, $nsTemplate — the `_index`/`_template`
 * reserved-page rows (docs/architecture-storage-index.md's segment-prefix
 * convention), null when absent or not visible to this caller
 * ?string $nsDescriptionHtml — $nsIndex's body, already rendered
 */

declare(strict_types=1);

/** @var string $ns */
/** @var list<array{name: string, count: int, title: ?string, summary: ?string}> $subnamespaces */
/** @var list<array<string, mixed>> $pages */
/** @var bool $canCreateHere */
/** @var string $basePath */
/** @var array<string, mixed>|null $nsIndex */
/** @var array<string, mixed>|null $nsTemplate */
/** @var ?string $nsDescriptionHtml */
/** @var ?string $descriptionPath */
/** @var list<string> $nsTags */
/** @var ?string $nsSummary */
/** @var string $nsVisibility */
?>
<?php
// Joins a child page/namespace name onto $ns without producing a leading
// ":" at the root (where $ns === '' has no segment to prefix).
$childPath = static fn (string $name): string => $ns === '' ? $name : $ns . ':' . $name;
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
<div class="wk-crumbs wk-mono">
<?php if ($ns === ''): ?>
<b><?= htmlspecialchars($nsTitle, ENT_QUOTES) ?></b>
<?php else: ?>
<a href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/:"><?= htmlspecialchars(t('ns.root_title'), ENT_QUOTES) ?></a><span>&rsaquo;</span>
<?php $segments = explode(':', $ns); $last = array_key_last($segments); $prefix = []; ?>
<?php foreach ($segments as $i => $segment): ?>
<?php $prefix[] = $segment; ?>
<?php if ($i === $last): ?><b><?= htmlspecialchars($segment, ENT_QUOTES) ?></b>
<?php else: ?><a href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars(implode(':', $prefix), ENT_QUOTES) ?>:"><?= htmlspecialchars($segment, ENT_QUOTES) ?></a><span>&rsaquo;</span>
<?php endif; ?>
<?php endforeach; ?>
<?php endif; ?>
<span class="tag tag-neutral"><?= htmlspecialchars(t('ns.badge'), ENT_QUOTES) ?></span>
</div>
<div class="wk-doc-titlerow">
<h1 class="wk-doc-title"><?= htmlspecialchars($nsTitle, ENT_QUOTES) ?></h1>
<?php if ($canCreateHere): ?>
<div class="wk-actions">
<?php if ($ns !== '' && ($nsDescriptionHtml === null || $descriptionPath === null)): ?>
<?php /* The namespace's description is a page of the same name */ ?>
<a class="btn btn-secondary" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($ns, ENT_QUOTES) ?>/edit" title="<?= htmlspecialchars(t('ns.description_add_help'), ENT_QUOTES) ?>"><i class="ph ph-note-pencil"></i><?= htmlspecialchars(t('ns.description_add'), ENT_QUOTES) ?></a>
<?php endif; ?>
<?php if ($ns !== '' && $nsDescriptionHtml !== null && $descriptionPath !== null): ?>
<a class="btn btn-secondary" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($descriptionPath, ENT_QUOTES) ?>/edit"><i class="ph ph-pencil-simple"></i><?= htmlspecialchars(t('ns.description_edit'), ENT_QUOTES) ?></a>
<?php endif; ?>
<a class="btn btn-primary" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/new?ns=<?= urlencode($ns) ?>"><?= htmlspecialchars(t('ns.new_page'), ENT_QUOTES) ?></a>
</div>
<?php endif; ?>
</div>
<?php if ($nsSummary !== null): ?>
<p class="wk-dim" style="font-size:16.5px;margin:0 0 var(--space-3)"><?= htmlspecialchars($nsSummary, ENT_QUOTES) ?></p>
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
<a class="wk-nscard" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($childPath($sub['name']), ENT_QUOTES) ?>:">
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

<?php if ($pages !== []): ?>
<div class="wk-panel">
<div class="wk-panel-h"><span class="wk-eyebrow"><?= htmlspecialchars(t('ns.pages_here'), ENT_QUOTES) ?></span></div>
<table class="table">
<thead><tr>
<th><?= htmlspecialchars(t('ns.col_title'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('ns.col_region'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('ns.col_status'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('ns.col_visibility'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('ns.col_updated'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('ns.col_by'), ENT_QUOTES) ?></th>
</tr></thead>
<tbody>
<?php foreach ($pages as $page): ?>
<tr>
<td><a href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars((string) $page['path'], ENT_QUOTES) ?>"><?= htmlspecialchars($pageLabel($page), ENT_QUOTES) ?></a><?php if (trim((string) ($page['summary'] ?? '')) !== ''): ?><br><span class="wk-row-s"><?= htmlspecialchars(\Reporion\Support\Snippet::words((string) $page['summary'], 40), ENT_QUOTES) ?></span><?php endif; ?></td>
<td><?= htmlspecialchars((string) ($page['region'] ?? ''), ENT_QUOTES) ?></td>
<td><span class="tag <?= \Reporion\Support\Badges::statusTag((string) $page['status']) ?>"><?= htmlspecialchars((string) $page['status'], ENT_QUOTES) ?></span></td>
<td><span class="tag <?= \Reporion\Support\Badges::visibilityTag((string) $page['visibility']) ?>"><?= htmlspecialchars((string) $page['visibility'], ENT_QUOTES) ?></span></td>
<td class="wk-mono"><?= htmlspecialchars(\Reporion\Support\MetaText::when($page['updated'] ?? null), ENT_QUOTES) ?></td>
<td class="wk-mono"><?= htmlspecialchars(display_name((string) ($page['updated_by'] ?? '')), ENT_QUOTES) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

<?php if ($nsDescriptionHtml !== null && $descriptionPath !== null): ?>
<div class="wk-prose" style="font-size:16.5px">
<?= $nsDescriptionHtml ?>
</div>
<?php endif; ?>
</div>
