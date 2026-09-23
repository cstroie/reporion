<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Minimal SSR page view (build order step 5: "one endpoint, one template,
 * real data on screen"). Full mockup fidelity (design/mockup/WikiPage.dc.html
 * — palette, page actions, namespace tree) is later, separate work; this is
 * deliberately plain.
 *
 * Variables in scope (see Controller\PageController::view()):
 * string $title, $path, $status, $visibility, $contentHtml
 * int $rev
 * array $toc, $warnings
 *
 * Deliberately NOT passed here: $frontmatter. It carries the full patient
 * block (name, born, sex, cnp) and this is the same template the public,
 * anonymous-facing layout renders (A4) — nothing needs it yet, and the day
 * something does, pass the specific fields it needs, never the whole block
 * (CLAUDE.md invariant 8).
 */

declare(strict_types=1);

/** @var string $title */
/** @var string $path */
/** @var int $rev */
/** @var string $status */
/** @var string $visibility */
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
<article data-path="<?= htmlspecialchars($path, ENT_QUOTES) ?>" data-rev="<?= $rev ?>">
<header>
<h1><?= htmlspecialchars($title, ENT_QUOTES) ?></h1>
<p>
<span><?= htmlspecialchars($path, ENT_QUOTES) ?></span>
&middot; rev <?= $rev ?>
&middot; <?= htmlspecialchars($status, ENT_QUOTES) ?>
&middot; <?= htmlspecialchars($visibility, ENT_QUOTES) ?>
</p>
</header>

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
</body>
</html>
