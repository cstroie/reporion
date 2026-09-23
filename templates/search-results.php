<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /search first-result-page (docs/architecture-api.md §1 Table 1).
 * Works with JS disabled; the palette and live facets are later, separate
 * work — see Controller\SearchController.
 *
 * Structure/classes ported from design/mockup/WikiSearch.dc.html
 * (.wk-doc / .wk-res / .wk-resrow). Deliberately NOT ported: the facet
 * sidebar, saved queries and the AI-answer-over-results box — none of
 * those exist yet (facets are explicitly a later JS island per
 * docs/architecture-api.md, and AI ships disabled by default per D15).
 *
 * Variables in scope: string $term; list<array{pid,path,title,visibility,snippet}> $results; string $basePath
 */

declare(strict_types=1);

/** @var string $term */
/** @var list<array<string, mixed>> $results */
/** @var string $basePath */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(t('search.title'), ENT_QUOTES) ?> — <?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?></title>
<link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/css/tokens.css">
<link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/css/wiki.css">
</head>
<body class="wk">
<div class="wk-top">
<span class="wk-brand"><?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?></span>
<form class="wk-search" action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/search" method="get">
<input type="search" name="q" value="<?= htmlspecialchars($term, ENT_QUOTES) ?>" placeholder="<?= htmlspecialchars(t('nav.search'), ENT_QUOTES) ?>">
</form>
</div>
<main class="wk-pad">
<div class="wk-doc">
<div class="wk-doc-head">
<?php if ($term !== ''): ?>
<div class="wk-crumbs wk-mono"><b><?= htmlspecialchars(t('search.title'), ENT_QUOTES) ?></b><span>›</span><span><?= htmlspecialchars($term, ENT_QUOTES) ?></span></div>
<h1 class="wk-doc-title"><?= htmlspecialchars(t('search.match_count', [count($results)]), ENT_QUOTES) ?></h1>
<?php else: ?>
<h1 class="wk-doc-title"><?= htmlspecialchars(t('search.title'), ENT_QUOTES) ?></h1>
<?php endif; ?>
</div>

<?php if ($term === ''): ?>
<p class="wk-dim"><?= htmlspecialchars(t('search.prompt'), ENT_QUOTES) ?></p>
<?php elseif ($results === []): ?>
<p class="wk-dim"><?= htmlspecialchars(t('search.noresults', [$term]), ENT_QUOTES) ?></p>
<?php else: ?>
<div class="wk-res">
<?php foreach ($results as $result): ?>
<div class="wk-resrow">
<div class="wk-row-t"><a href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars((string) $result['path'], ENT_QUOTES) ?>"><?= htmlspecialchars((string) $result['title'], ENT_QUOTES) ?></a></div>
<div class="wk-row-m wk-mono"><?= htmlspecialchars((string) $result['path'], ENT_QUOTES) ?></div>
<div class="wk-row-s"><?= $result['snippet_html'] /* already escaped + <mark>-substituted by Sqlite::highlightSnippet(), see SearchController */ ?></div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
</main>
</body>
</html>
