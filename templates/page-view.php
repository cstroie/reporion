<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * SSR page view, structure/classes ported from design/mockup/WikiPage.dc.html
 * (.wk-doc / .wk-crumbs / .wk-badges / .wk-prose). Deliberately NOT ported:
 * the export button and the page-actions menu (rename, move, duplicate,
 * sign, delete) — none of those routes exist yet, and a button pointing
 * nowhere is worse than no button (see docs/BUILD_LOG.md). History and Edit
 * are back (both routes exist now) — add each remaining one when its route
 * lands, not before.
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
/** @var string $basePath */
/** @var bool $isOwner */
/** @var bool $canWrite */
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
<div class="wk-top">
<span class="wk-brand"><?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?></span>
<form class="wk-search" action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/search" method="get">
<input type="search" name="q" placeholder="<?= htmlspecialchars(t('nav.search'), ENT_QUOTES) ?>">
</form>
<?php if ($isOwner): ?>
<a href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/admin/users"><?= htmlspecialchars(t('nav.admin'), ENT_QUOTES) ?></a>
<?php endif; ?>
</div>
<main class="wk-pad">
<article class="wk-doc" data-path="<?= htmlspecialchars($path, ENT_QUOTES) ?>" data-rev="<?= $rev ?>">
<div class="wk-doc-head">
<div class="wk-crumbs wk-mono">
<?php $segments = explode(':', $path); $last = array_key_last($segments); ?>
<?php foreach ($segments as $i => $segment): ?>
<?php if ($i === $last): ?><b><?= htmlspecialchars($segment, ENT_QUOTES) ?></b>
<?php else: ?><span><?= htmlspecialchars($segment, ENT_QUOTES) ?></span><span>›</span>
<?php endif; ?>
<?php endforeach; ?>
</div>
<div class="wk-doc-titlerow">
<h1 class="wk-doc-title"><?= htmlspecialchars($title, ENT_QUOTES) ?></h1>
<div class="wk-actions">
<?php if ($canWrite): ?>
<a class="btn btn-primary btn-sm" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($path, ENT_QUOTES) ?>/edit"><?= htmlspecialchars(t('page.edit'), ENT_QUOTES) ?></a>
<?php endif; ?>
<a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($path, ENT_QUOTES) ?>/history"><?= htmlspecialchars(t('page.history'), ENT_QUOTES) ?></a>
</div>
</div>
<div class="wk-badges">
<span class="tag tag-accent"><?= htmlspecialchars($visibility, ENT_QUOTES) ?></span>
<span class="tag tag-neutral"><?= htmlspecialchars($status, ENT_QUOTES) ?> · rev <?= $rev ?></span>
</div>
</div>

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
</article>
</main>
</body>
</html>
