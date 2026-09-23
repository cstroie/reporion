<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /{ns}: (Controller\NamespaceController). See that controller's
 * docblock for what was deliberately left out of this port of
 * design/mockup/WikiNsIndex.dc.html.
 *
 * Variables in scope (Controller\NamespaceController::index()):
 * string $ns, $basePath; bool $canCreate
 * list<array{name: string, count: int}> $subnamespaces
 * list<array<string, mixed>> $pages
 */

declare(strict_types=1);

/** @var string $ns */
/** @var list<array{name: string, count: int}> $subnamespaces */
/** @var list<array<string, mixed>> $pages */
/** @var bool $canCreate */
/** @var string $basePath */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($ns, ENT_QUOTES) ?> — <?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?></title>
<link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/css/tokens.css">
<link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/css/wiki.css">
</head>
<body class="wk">
<div class="wk-top">
<span class="wk-brand"><?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?></span>
<form class="wk-search" action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/search" method="get" data-island="palette" data-config-id="palette-config">
<input type="search" name="q" placeholder="<?= htmlspecialchars(t('nav.search'), ENT_QUOTES) ?>">
</form>
<script type="application/json" id="palette-config"><?= json_encode(['basePath' => $basePath], JSON_HEX_TAG) ?></script>
</div>
<main class="wk-pad">
<div class="wk-doc">
<div class="wk-doc-head">
<div class="wk-crumbs wk-mono">
<?php $segments = explode(':', $ns); $last = array_key_last($segments); $prefix = []; ?>
<?php foreach ($segments as $i => $segment): ?>
<?php $prefix[] = $segment; ?>
<?php if ($i === $last): ?><b><?= htmlspecialchars($segment, ENT_QUOTES) ?></b>
<?php else: ?><a href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars(implode(':', $prefix), ENT_QUOTES) ?>:"><?= htmlspecialchars($segment, ENT_QUOTES) ?></a><span>&rsaquo;</span>
<?php endif; ?>
<?php endforeach; ?>
<span class="tag tag-neutral"><?= htmlspecialchars(t('ns.badge'), ENT_QUOTES) ?></span>
</div>
<div class="wk-doc-titlerow">
<h1 class="wk-doc-title"><?= htmlspecialchars($ns, ENT_QUOTES) ?></h1>
<?php if ($canCreate): ?>
<div class="wk-actions">
<a class="btn btn-primary" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/new?ns=<?= urlencode($ns) ?>"><?= htmlspecialchars(t('ns.new_page'), ENT_QUOTES) ?></a>
</div>
<?php endif; ?>
</div>
<div class="wk-badges">
<span class="tag tag-neutral"><?= htmlspecialchars(t('ns.direct_page_count', [\count($pages)]), ENT_QUOTES) ?></span>
</div>
</div>

<?php if ($subnamespaces !== []): ?>
<div class="wk-cards">
<?php foreach ($subnamespaces as $sub): ?>
<a class="wk-nscard" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($ns, ENT_QUOTES) ?>:<?= htmlspecialchars($sub['name'], ENT_QUOTES) ?>:">
<span class="wk-eyebrow"><?= htmlspecialchars(t('ns.subnamespace'), ENT_QUOTES) ?></span>
<b class="wk-mono"><?= htmlspecialchars($sub['name'], ENT_QUOTES) ?></b>
<span class="wk-dim wk-mono"><?= htmlspecialchars(t('ns.page_count', [$sub['count']]), ENT_QUOTES) ?></span>
</a>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($pages !== []): ?>
<div class="wk-panel">
<div class="wk-panel-h"><span class="wk-eyebrow"><?= htmlspecialchars(t('ns.pages_here'), ENT_QUOTES) ?></span></div>
<table class="table">
<thead><tr>
<th><?= htmlspecialchars(t('ns.col_page'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('ns.col_title'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('ns.col_status'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('ns.col_visibility'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('ns.col_updated'), ENT_QUOTES) ?></th>
</tr></thead>
<tbody>
<?php foreach ($pages as $page): ?>
<tr>
<td class="wk-mono"><a href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars((string) $page['path'], ENT_QUOTES) ?>"><?= htmlspecialchars((string) $page['path'], ENT_QUOTES) ?></a></td>
<td><?= htmlspecialchars((string) ($page['title'] ?? ''), ENT_QUOTES) ?></td>
<td><span class="tag tag-neutral"><?= htmlspecialchars((string) $page['status'], ENT_QUOTES) ?></span></td>
<td><span class="tag tag-outline"><?= htmlspecialchars((string) $page['visibility'], ENT_QUOTES) ?></span></td>
<td class="wk-mono"><?= htmlspecialchars((string) ($page['updated'] ?? ''), ENT_QUOTES) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</div>
</main>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/js/palette.js" defer></script>
</body>
</html>
