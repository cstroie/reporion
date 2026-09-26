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
 * $frontmatter here holds only Http\PageTemplateRenderer::PUBLIC_FIELDS
 * (device): the patient block is never in this template's scope
 * (invariant 8).
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
<?php include __DIR__ . '/partials/site-icon.php'; ?>
<?php include __DIR__ . '/partials/head-assets.php'; ?>
</head>
<body class="wk">
<div class="wk-public">
<div class="wk-public-bar">
<span class="wk-mono"><i class="ph ph-globe"></i> <?= htmlspecialchars(t('public.label'), ENT_QUOTES) ?></span>
<span class="wk-tflex"></span>
<a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/login"><i class="ph ph-sign-in"></i><?= htmlspecialchars(t('nav.signin'), ENT_QUOTES) ?></a>
</div>
<div class="wk-public-doc">
<div class="wk-crumbs wk-mono">
<?php $segments = explode(':', $path); $last = array_key_last($segments); $prefix = []; ?>
<?php foreach ($segments as $i => $segment): ?>
<?php if ($i === $last): ?><b><?= htmlspecialchars($segment, ENT_QUOTES) ?></b>
<?php else: ?><?php $prefix[] = $segment; ?><a href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars(implode(':', $prefix), ENT_QUOTES) ?>"><?= htmlspecialchars($segment, ENT_QUOTES) ?></a><i class="ph ph-caret-right"></i>
<?php endif; ?>
<?php endforeach; ?>
</div>
<h1 class="wk-doc-title"><?= htmlspecialchars($title, ENT_QUOTES) ?></h1>
<div class="wk-badges">
<span class="tag tag-outline"><i class="ph ph-globe"></i> <?= htmlspecialchars(t('vis.' . $visibility), ENT_QUOTES) ?></span>
<span class="tag tag-neutral">rev <?= (int) $rev ?> · <?= htmlspecialchars((string) $status, ENT_QUOTES) ?></span>
<?php if (isset($frontmatter['device'])): ?>
<span class="tag tag-neutral"><?= htmlspecialchars(\Reporion\Support\MetaText::text($frontmatter['device']), ENT_QUOTES) ?></span>
<?php endif; ?>
</div>
<?php if (isset($currentRev) && $currentRev !== $rev): ?>
<div class="wk-notice" role="status"><i class="ph ph-clock-counter-clockwise"></i><div><?= htmlspecialchars(t('rev.viewing', [$rev, $currentRev]), ENT_QUOTES) ?> <a href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($path, ENT_QUOTES) ?>"><?= htmlspecialchars(t('rev.view_current'), ENT_QUOTES) ?></a></div></div>
<?php endif; ?>
<?php if (isset($signature)): ?>
<div class="wk-notice wk-sigcheck" role="status"><i class="ph <?= $signature['matches'] ? 'ph-seal-check' : 'ph-warning' ?>"></i><div><?= htmlspecialchars(t('rev.signed_by', [$rev, $signature['by'], \Reporion\Support\MetaText::when($signature['ts'])]), ENT_QUOTES) ?><?= $signature['parafa'] !== null ? ' · ' . htmlspecialchars(t('rev.parafa', [$signature['parafa']]), ENT_QUOTES) : '' ?><br><span class="wk-mono wk-dim"><?= htmlspecialchars($signature['alg'], ENT_QUOTES) ?> <?= htmlspecialchars($signature['digest'], ENT_QUOTES) ?></span><br><?= htmlspecialchars(t($signature['matches'] ? 'rev.digest_matches' : 'rev.digest_differs'), ENT_QUOTES) ?></div></div>
<?php endif; ?>
<?php if (($visibility ?? '') === 'unlisted'): ?>
<div class="wk-notice"><i class="ph ph-link-simple"></i><div><b><?= htmlspecialchars(t('public.unlisted_title'), ENT_QUOTES) ?></b> <?= htmlspecialchars(t('public.unlisted_body'), ENT_QUOTES) ?></div></div>
<?php endif; ?>

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
<div class="wk-public-foot"><span class="wk-mono wk-dim"><?= htmlspecialchars(sprintf(t('public.citable'), $rev), ENT_QUOTES) ?></span></div>
</div>
</div>
</body>
</html>
