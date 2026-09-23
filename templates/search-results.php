<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /search first-result-page (docs/architecture-api.md §1 Table 1).
 * Works with JS disabled; the palette and live facets are later, separate
 * work — see Controller\SearchController.
 *
 * Variables in scope: string $term; list<array{pid,path,title,visibility,snippet}> $results
 */

declare(strict_types=1);

/** @var string $term */
/** @var list<array<string, mixed>> $results */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(t('search.title'), ENT_QUOTES) ?> — <?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?></title>
<link rel="stylesheet" href="/assets/css/tokens.css">
</head>
<body>
<main>
<form action="/search" method="get">
<label for="q"><?= htmlspecialchars(t('search.title'), ENT_QUOTES) ?></label>
<input type="search" id="q" name="q" value="<?= htmlspecialchars($term, ENT_QUOTES) ?>">
<button type="submit"><?= htmlspecialchars(t('search.submit'), ENT_QUOTES) ?></button>
</form>

<?php if ($term === ''): ?>
<p><?= htmlspecialchars(t('search.prompt'), ENT_QUOTES) ?></p>
<?php elseif ($results === []): ?>
<p><?= htmlspecialchars(t('search.noresults', [$term]), ENT_QUOTES) ?></p>
<?php else: ?>
<p><?= htmlspecialchars(t('search.results_count', [count($results), $term]), ENT_QUOTES) ?></p>
<ul>
<?php foreach ($results as $result): ?>
<li>
<a href="/<?= htmlspecialchars((string) $result['path'], ENT_QUOTES) ?>"><?= htmlspecialchars((string) $result['title'], ENT_QUOTES) ?></a>
<p><?= $result['snippet_html'] /* already escaped + <mark>-substituted by Sqlite::highlightSnippet(), see SearchController */ ?></p>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</main>
</body>
</html>
