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
 * Structure/classes ported from design/mockup/WikiPublic.dc.html
 * (.wk-public / .wk-public-bar / .wk-public-doc). The mockup's "unlisted
 * pages behave differently" notice and share-link button are not ported —
 * share tokens (D-share) aren't built yet.
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
/** @var string $basePath */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title, ENT_QUOTES) ?> — <?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?></title>
<link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/css/tokens.css">
<link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/css/wiki.css">
</head>
<body class="wk">
<div class="wk-public">
<div class="wk-public-bar">
<span class="wk-mono"><?= htmlspecialchars(t('public.label'), ENT_QUOTES) ?></span>
<span class="wk-tflex"></span>
<a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/login"><?= htmlspecialchars(t('nav.signin'), ENT_QUOTES) ?></a>
</div>
<div class="wk-public-doc">
<h1 class="wk-doc-title"><?= htmlspecialchars($title, ENT_QUOTES) ?></h1>

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

<div class="wk-prose">
<?= $contentHtml ?>
</div>
<div class="wk-public-foot"><span class="wk-mono wk-dim"><?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?></span></div>
</div>
</div>
</body>
</html>
