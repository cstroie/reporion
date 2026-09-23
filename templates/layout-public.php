<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * A4 (docs/architecture-api.md §6): "A public request renders the same
 * page HTML inside templates/layout-public.php: no palette, no worklist,
 * no page actions, no namespace tree — a title, the document, a footer.
 * The document body markup is identical [to templates/page-view.php], so
 * the reader view cannot drift from the report view."
 *
 * Variables in scope (see Controller\PageController::view()):
 * string $title, $contentHtml
 * array $toc, $warnings
 *
 * Deliberately NOT in scope, same reasoning as page-view.php: $frontmatter
 * (the patient block) and internal bookkeeping (rev/status) an anonymous
 * reader has no reason to see.
 */

declare(strict_types=1);

/** @var string $title */
/** @var string $contentHtml */
/** @var list<array{level: int, text: string, slug: string}> $toc */
/** @var list<string> $warnings */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title, ENT_QUOTES) ?> — <?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?></title>
<link rel="stylesheet" href="/assets/css/tokens.css">
</head>
<body>
<main>
<article>
<h1><?= htmlspecialchars($title, ENT_QUOTES) ?></h1>

<?php if ($toc !== []): ?>
<nav aria-label="<?= htmlspecialchars(t('page.toc'), ENT_QUOTES) ?>">
<ul>
<?php foreach ($toc as $entry): ?>
<li style="margin-left: <?= ($entry['level'] - 1) * 1 ?>em">
<a href="#<?= htmlspecialchars($entry['slug'], ENT_QUOTES) ?>"><?= htmlspecialchars($entry['text'], ENT_QUOTES) ?></a>
</li>
<?php endforeach; ?>
</ul>
</nav>
<?php endif; ?>

<?php foreach ($warnings as $warning): ?>
<p role="alert"><?= htmlspecialchars($warning, ENT_QUOTES) ?></p>
<?php endforeach; ?>

<div class="document-body">
<?= $contentHtml ?>
</div>
</article>
</main>
<footer>
<p><?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?></p>
</footer>
</body>
</html>
