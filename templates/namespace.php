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
 * list<array{name: string, count: int}> $subnamespaces
 * list<array<string, mixed>> $pages
 * ?array<string, mixed> $nsIndex, $nsTemplate — the `_index`/`_template`
 * reserved-page rows (docs/architecture-storage-index.md's segment-prefix
 * convention), null when absent or not visible to this caller
 * ?string $nsDescriptionHtml — $nsIndex's body, already rendered
 */

declare(strict_types=1);

/** @var string $ns */
/** @var list<array{name: string, count: int}> $subnamespaces */
/** @var list<array<string, mixed>> $pages */
/** @var bool $canCreateHere */
/** @var string $basePath */
/** @var array<string, mixed>|null $nsIndex */
/** @var array<string, mixed>|null $nsTemplate */
/** @var ?string $nsDescriptionHtml */
?>
<?php
// Joins a child page/namespace name onto $ns without producing a leading
// ":" at the root (where $ns === '' has no segment to prefix).
$childPath = static fn (string $name): string => $ns === '' ? $name : $ns . ':' . $name;
$nsTitle = $ns !== '' ? $ns : t('ns.root_title');
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
<a class="btn btn-primary" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/new?ns=<?= urlencode($ns) ?>"><?= htmlspecialchars(t('ns.new_page'), ENT_QUOTES) ?></a>
</div>
<?php endif; ?>
</div>
<div class="wk-badges">
<span class="tag tag-neutral"><?= htmlspecialchars(t('ns.direct_page_count', [\count($pages)]), ENT_QUOTES) ?></span>
</div>
</div>

<?php if ($subnamespaces !== [] || $nsIndex !== null || $nsTemplate !== null): ?>
<div class="wk-cards">
<?php foreach ($subnamespaces as $sub): ?>
<a class="wk-nscard" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($childPath($sub['name']), ENT_QUOTES) ?>:">
<span class="wk-eyebrow"><?= htmlspecialchars(t('ns.subnamespace'), ENT_QUOTES) ?></span>
<b class="wk-mono"><?= htmlspecialchars($sub['name'], ENT_QUOTES) ?></b>
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
<th><?= htmlspecialchars(t('ns.col_page'), ENT_QUOTES) ?></th>
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
<td class="wk-mono"><a href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars((string) $page['path'], ENT_QUOTES) ?>"><?= htmlspecialchars((string) $page['path'], ENT_QUOTES) ?></a></td>
<td><?= htmlspecialchars((string) ($page['title'] ?? ''), ENT_QUOTES) ?></td>
<td><?= htmlspecialchars((string) ($page['region'] ?? ''), ENT_QUOTES) ?></td>
<td><span class="tag tag-neutral"><?= htmlspecialchars((string) $page['status'], ENT_QUOTES) ?></span></td>
<td><span class="tag tag-outline"><?= htmlspecialchars((string) $page['visibility'], ENT_QUOTES) ?></span></td>
<td class="wk-mono"><?= htmlspecialchars(\Reporion\Support\MetaText::when($page['updated'] ?? null), ENT_QUOTES) ?></td>
<td class="wk-mono"><?= htmlspecialchars((string) ($page['updated_by'] ?? ''), ENT_QUOTES) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

<?php if ($nsDescriptionHtml !== null): ?>
<div class="wk-two">
<div class="wk-panel">
<div class="wk-panel-h">
<span class="wk-eyebrow"><?= htmlspecialchars(t('ns.description'), ENT_QUOTES) ?></span>
<?php if ($canCreateHere): ?>
<a class="btn btn-ghost btn-sm" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($childPath('_index'), ENT_QUOTES) ?>/edit"><?= htmlspecialchars(t('ns.description_edit'), ENT_QUOTES) ?></a>
<?php endif; ?>
</div>
<div class="wk-prose" style="font-size:13px">
<?= $nsDescriptionHtml ?>
</div>
</div>
</div>
<?php endif; ?>
</div>
