<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * SSR page view, structure/classes ported from design/mockup/WikiPage.dc.html
 * (.wk-doc / .wk-crumbs / .wk-badges / .wk-meta / .wk-prose / .wk-doc-foot).
 * Deliberately NOT ported: the export button and the page-actions-menu
 * items with no route yet (rename, move, duplicate, save-as-template,
 * visibility, sign) — a button pointing nowhere is worse than no button.
 * History and Edit are in the tab strip. The kebab menu holds Revert
 * (a link to the history screen, where revert lives) and Delete.
 *
 * Frontmatter fields are passed here for signed-in users only
 * (layout-public.php is anonymous — invariant 8 patient data stays
 * out of that template).
 *
 * Variables in scope (see Controller\PageController::view()):
 * string $title, $path, $status, $visibility, $contentHtml
 * int $rev
 * array $toc, $warnings, $frontmatter, $backlinks, $latestRev
 * string $pid
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
/** @var bool $canCreate */
/** @var int $trashPurgeDays */
/** @var string $railActive */
/** @var ?string $railEditHref */
/** @var string $tabActive */
/** @var string $worklistNs */
/** @var list<array<string, mixed>> $worklistRows */
/** @var string $theme */
/** @var string $themeBodyClass */
/** @var string $currentUrl */
/** @var int $statusTotal */
/** @var int $statusDraft */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title, ENT_QUOTES) ?> — <?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?></title>
<link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/css/tokens.css">
<link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/css/wiki.css">
<link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/css/phosphor.css">
</head>
<body class="wk wk-shell<?= htmlspecialchars($themeBodyClass, ENT_QUOTES) ?>">
<div class="wk-body wk-body-worklist">
<?php include __DIR__ . '/rail.php'; ?>
<?php include __DIR__ . '/worklist.php'; ?>
<div class="wk-col">
<div class="wk-top">
<span class="wk-brand"><?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?></span>
<form class="wk-search" action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/search" method="get" data-island="palette" data-config-id="palette-config">
<input type="search" name="q" placeholder="<?= htmlspecialchars(t('nav.search'), ENT_QUOTES) ?>">
</form>
<script type="application/json" id="palette-config"><?= json_encode(['basePath' => $basePath], JSON_HEX_TAG) ?></script>
</div>
<?php include __DIR__ . '/tabs.php'; ?>
<main class="wk-pad">
<article class="wk-doc" data-path="<?= htmlspecialchars($path, ENT_QUOTES) ?>" data-rev="<?= $rev ?>">
<div class="wk-doc-head">
<div class="wk-crumbs wk-mono">
<?php $segments = explode(':', $path); $last = array_key_last($segments); $prefix = []; ?>
<?php foreach ($segments as $i => $segment): ?>
<?php if ($i === $last): ?><b><?= htmlspecialchars($segment, ENT_QUOTES) ?></b>
<?php else: ?><?php $prefix[] = $segment; ?><a href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars(implode(':', $prefix), ENT_QUOTES) ?>:"><?= htmlspecialchars($segment, ENT_QUOTES) ?></a><span>›</span>
<?php endif; ?>
<?php endforeach; ?>
<?php if (isset($pid)): ?>
<button class="wk-tbtn" title="<?= htmlspecialchars(t('page.copy_id'), ENT_QUOTES) ?>" data-copy-id="<?= htmlspecialchars($pid, ENT_QUOTES) ?>"><i class="ph ph-copy"></i></button>
<?php endif; ?>
</div>
<div class="wk-doc-titlerow">
<h1 class="wk-doc-title"><?= htmlspecialchars($title, ENT_QUOTES) ?></h1>
<div class="wk-actions">
<?php if ($canWrite): ?>
<details class="wk-menu-wrap">
<summary class="btn btn-secondary btn-sm btn-icon" aria-haspopup="true">&#8942;</summary>
<div class="wk-menu">
<a class="wk-mi" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($path, ENT_QUOTES) ?>/history"><i class="ph ph-arrow-counter-clockwise"></i><?= htmlspecialchars(t('page.revert'), ENT_QUOTES) ?></a>
<div class="wk-mi-sep"></div>
<a class="wk-mi wk-mi-danger" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($path, ENT_QUOTES) ?>/delete"><?= htmlspecialchars(t('page.delete', [$trashPurgeDays]), ENT_QUOTES) ?></a>
</div>
</details>
<?php endif; ?>
</div>
</div>
<div class="wk-badges">
<span class="tag tag-accent"><?= htmlspecialchars($visibility, ENT_QUOTES) ?></span>
<span class="tag tag-neutral"><?= htmlspecialchars($status, ENT_QUOTES) ?> · rev <?= $rev ?></span>
<?php if (isset($frontmatter['device'])): ?>
<span class="tag tag-neutral"><?= htmlspecialchars($frontmatter['device'], ENT_QUOTES) ?></span>
<?php endif; ?>
<?php if (isset($latestRev)): ?>
<span class="wk-mono wk-dim"><?= htmlspecialchars(t('page.edited', [(new DateTimeImmutable($latestRev['ts']))->format('d M Y H:i'), $latestRev['by']]), ENT_QUOTES) ?></span>
<?php endif; ?>
</div>
</div>

<?php if (isset($frontmatter)): ?>
<div class="wk-meta">
<div class="wk-meta-h"><span class="wk-eyebrow"><?= htmlspecialchars(t('meta.title'), ENT_QUOTES) ?></span><span class="wk-mono wk-dim"><?= htmlspecialchars(t('page.frontmatter'), ENT_QUOTES) ?> · <?= htmlspecialchars(t('page.indexed'), ENT_QUOTES) ?></span></div>
<div class="wk-kv">
<?php if (isset($frontmatter['patient'])): ?>
<span><?= htmlspecialchars(t('meta.patient'), ENT_QUOTES) ?></span><b class="wk-mono"><?= htmlspecialchars($frontmatter['patient']['name'] ?? '', ENT_QUOTES) ?> · <?= htmlspecialchars($frontmatter['patient']['born'] ?? '', ENT_QUOTES) ?> · <?= htmlspecialchars($frontmatter['patient']['sex'] ?? '', ENT_QUOTES) ?></b>
<?php endif; ?>
<?php if (isset($frontmatter['accession'])): ?>
<span><?= htmlspecialchars(t('meta.accession'), ENT_QUOTES) ?></span><b class="wk-mono"><?= htmlspecialchars($frontmatter['accession'], ENT_QUOTES) ?></b>
<?php endif; ?>
<?php if (isset($frontmatter['study_date'])): ?>
<span><?= htmlspecialchars(t('meta.study_date'), ENT_QUOTES) ?></span><b><?= htmlspecialchars((new DateTimeImmutable($frontmatter['study_date']))->format('d M Y, H:i'), ENT_QUOTES) ?></b>
<?php endif; ?>
<?php if (isset($frontmatter['modality'])): ?>
<span><?= htmlspecialchars(t('meta.modality'), ENT_QUOTES) ?></span><b><?= htmlspecialchars(implode(', ', (array) $frontmatter['modality']), ENT_QUOTES) ?></b>
<?php endif; ?>
<?php if (isset($frontmatter['region'])): ?>
<span><?= htmlspecialchars(t('meta.region'), ENT_QUOTES) ?></span><b><?= htmlspecialchars(implode(', ', (array) $frontmatter['region']), ENT_QUOTES) ?></b>
<?php endif; ?>
<?php if (isset($frontmatter['device'])): ?>
<span><?= htmlspecialchars(t('meta.device'), ENT_QUOTES) ?></span><b><?= htmlspecialchars($frontmatter['device'], ENT_QUOTES) ?></b>
<?php endif; ?>
<?php if (isset($frontmatter['site'])): ?>
<span><?= htmlspecialchars(t('meta.site'), ENT_QUOTES) ?></span><b><?= htmlspecialchars($frontmatter['site'], ENT_QUOTES) ?></b>
<?php endif; ?>
<?php if (isset($frontmatter['referrer'])): ?>
<span><?= htmlspecialchars(t('meta.referrer'), ENT_QUOTES) ?></span><b><?= htmlspecialchars($frontmatter['referrer'], ENT_QUOTES) ?></b>
<?php endif; ?>
<?php if (isset($frontmatter['protocol'])): ?>
<span><?= htmlspecialchars(t('meta.protocol'), ENT_QUOTES) ?></span><b><?= htmlspecialchars($frontmatter['protocol'], ENT_QUOTES) ?></b>
<?php endif; ?>
<?php if (isset($frontmatter['template'])): ?>
<span><?= htmlspecialchars(t('meta.template'), ENT_QUOTES) ?></span><b><?= htmlspecialchars($frontmatter['template'], ENT_QUOTES) ?></b>
<?php endif; ?>
<?php if (isset($frontmatter['summary'])): ?>
<span><?= htmlspecialchars(t('meta.summary'), ENT_QUOTES) ?></span><b class="wk-dim"><?= htmlspecialchars($frontmatter['summary'], ENT_QUOTES) ?></b>
<?php endif; ?>
<?php if (isset($frontmatter['tags'])): ?>
<span><?= htmlspecialchars(t('meta.tags'), ENT_QUOTES) ?></span><b><?php foreach ((array) $frontmatter['tags'] as $tag): ?><span class="wk-chip"><?= htmlspecialchars($tag, ENT_QUOTES) ?></span><?php endforeach; ?></b>
<?php endif; ?>
<?php if (isset($frontmatter['priors'])): ?>
<span><?= htmlspecialchars(t('meta.priors'), ENT_QUOTES) ?></span><b><?= htmlspecialchars(implode(', ', (array) $frontmatter['priors']), ENT_QUOTES) ?></b>
<?php endif; ?>
</div>
</div>
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
<div class="wk-doc-foot">
<div><span class="wk-eyebrow"><?= htmlspecialchars(t('page.backlinks'), ENT_QUOTES) ?></span><div class="wk-links">
<?php if ($backlinks !== []): ?>
<?php foreach ($backlinks as $link): ?>
<a href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($link['path'], ENT_QUOTES) ?>" class="wk-mono"><?= htmlspecialchars($link['path'], ENT_QUOTES) ?></a>
<?php endforeach; ?>
<?php else: ?>
<span class="wk-mono wk-dim"><?= htmlspecialchars(t('page.no_backlinks'), ENT_QUOTES) ?></span>
<?php endif; ?>
</div></div>
<?php if (isset($latestRev)): ?>
<div><span class="wk-eyebrow"><?= htmlspecialchars(t('page.revision'), ENT_QUOTES) ?></span><p class="wk-mono wk-dim">rev <?= $rev ?> · <?= htmlspecialchars((new DateTimeImmutable($latestRev['ts']))->format('d M Y H:i'), ENT_QUOTES) ?> · <?= htmlspecialchars($latestRev['by'], ENT_QUOTES) ?><?= $latestRev['note'] !== null ? ' · "'.htmlspecialchars($latestRev['note'], ENT_QUOTES).'"' : '' ?></p></div>
<?php endif; ?>
</div>
</article>
</main>
</div>
</div>
<?php include __DIR__ . '/status.php'; ?>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/js/palette.js" defer></script>
<script>
document.querySelectorAll('[data-copy-id]').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var id = btn.getAttribute('data-copy-id');
    var ok = function() { btn.setAttribute('title', 'Copied!'); setTimeout(function() { btn.setAttribute('title', '<?= htmlspecialchars(t('page.copy_id'), ENT_QUOTES) ?>'); }, 2000); };
    if (navigator.clipboard) { navigator.clipboard.writeText(id).then(ok); }
    else { var ta = document.createElement('textarea'); ta.value = id; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta); ok(); }
  });
});
</script>
</body>
</html>
