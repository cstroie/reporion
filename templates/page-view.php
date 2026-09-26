<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /{path} for a signed-in reader — content only; Http\View::page()
 * wraps it in templates/layout.php, whose page header (templates/
 * page-header.php) carries crumbs, title, badges, the page tabs and the
 * page actions. Structure/classes from design/mockup/WikiPage.dc.html
 * (.wk-meta / .wk-prose / .wk-doc-foot). Anonymous readers get
 * templates/layout-public.php instead (invariant 9).
 *
 * The stub home page (no site:home yet) has no page header — there is no
 * page to act on — so its title is printed here instead.
 *
 * Variables in scope (Http\PageTemplateRenderer::render()):
 * string $title, $path, $contentHtml, $basePath; int $rev;
 * array $toc, $warnings, $frontmatter, $backlinks, ?array $latestRev
 */

declare(strict_types=1);

use Reporion\Support\MetaText;

/** @var string $title */
/** @var string $path */
/** @var int $rev */
/** @var string $contentHtml */
/** @var list<array{level: int, text: string, slug: string}> $toc */
/** @var list<string> $warnings */
/** @var string $basePath */
?>
<article class="wk-doc" data-path="<?= htmlspecialchars($path, ENT_QUOTES) ?>" data-rev="<?= $rev ?>">
<?php if (!isset($headerPath)): ?>
<h1 class="wk-doc-title"><?= htmlspecialchars($title, ENT_QUOTES) ?></h1>
<?php endif; ?>
<?php if (isset($currentRev) && $currentRev !== $rev): ?>
<div class="wk-notice" role="status"><i class="ph ph-clock-counter-clockwise"></i><div><?= htmlspecialchars(t('rev.viewing', [$rev, $currentRev]), ENT_QUOTES) ?> <a href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($path, ENT_QUOTES) ?>"><?= htmlspecialchars(t('rev.view_current'), ENT_QUOTES) ?></a></div></div>
<?php endif; ?>
<?php if (isset($signature)): ?>
<div class="wk-notice wk-sigcheck" role="status"><i class="ph <?= $signature['matches'] ? 'ph-seal-check' : 'ph-warning' ?>"></i><div><?= htmlspecialchars(t('rev.signed_by', [$rev, $signature['by'], \Reporion\Support\MetaText::when($signature['ts'])]), ENT_QUOTES) ?><?= $signature['parafa'] !== null ? ' · ' . htmlspecialchars(t('rev.parafa', [$signature['parafa']]), ENT_QUOTES) : '' ?><br><span class="wk-mono wk-dim"><?= htmlspecialchars($signature['alg'], ENT_QUOTES) ?> <?= htmlspecialchars($signature['digest'], ENT_QUOTES) ?></span><br><?= htmlspecialchars(t($signature['matches'] ? 'rev.digest_matches' : 'rev.digest_differs'), ENT_QUOTES) ?></div></div>
<?php endif; ?>

<?php if (isset($frontmatter)): ?>
<div class="wk-meta">
<div class="wk-meta-h"><span class="wk-eyebrow"><?= htmlspecialchars(t('meta.title'), ENT_QUOTES) ?></span><span class="wk-mono wk-dim"><?= htmlspecialchars(t('page.frontmatter'), ENT_QUOTES) ?> · <?= htmlspecialchars(t('page.indexed'), ENT_QUOTES) ?></span></div>
<div class="wk-kv">
<?php if (isset($frontmatter['patient'])): ?>
<span><?= htmlspecialchars(t('meta.patient'), ENT_QUOTES) ?></span><b class="wk-mono"><?= htmlspecialchars(MetaText::text($frontmatter['patient']['name'] ?? null), ENT_QUOTES) ?> · <?= htmlspecialchars(MetaText::text($frontmatter['patient']['born'] ?? null), ENT_QUOTES) ?> · <?= htmlspecialchars(MetaText::text($frontmatter['patient']['sex'] ?? null), ENT_QUOTES) ?></b>
<?php endif; ?>
<?php if (isset($frontmatter['accession'])): ?>
<span><?= htmlspecialchars(t('meta.accession'), ENT_QUOTES) ?></span><b class="wk-mono"><?= htmlspecialchars(MetaText::text($frontmatter['accession']), ENT_QUOTES) ?></b>
<?php endif; ?>
<?php if (isset($frontmatter['study_date'])): ?>
<span><?= htmlspecialchars(t('meta.study_date'), ENT_QUOTES) ?></span><b><?= htmlspecialchars(MetaText::dateTime($frontmatter['study_date'], 'd M Y', ', H:i'), ENT_QUOTES) ?></b>
<?php endif; ?>
<?php if (isset($frontmatter['modality'])): ?>
<span><?= htmlspecialchars(t('meta.modality'), ENT_QUOTES) ?></span><b><?= htmlspecialchars(MetaText::text($frontmatter['modality']), ENT_QUOTES) ?></b>
<?php endif; ?>
<?php if (isset($frontmatter['region'])): ?>
<span><?= htmlspecialchars(t('meta.region'), ENT_QUOTES) ?></span><b><?= htmlspecialchars(MetaText::text($frontmatter['region']), ENT_QUOTES) ?></b>
<?php endif; ?>
<?php if (isset($frontmatter['device'])): ?>
<span><?= htmlspecialchars(t('meta.device'), ENT_QUOTES) ?></span><b><?= htmlspecialchars(MetaText::text($frontmatter['device']), ENT_QUOTES) ?></b>
<?php endif; ?>
<?php if (isset($frontmatter['site'])): ?>
<span><?= htmlspecialchars(t('meta.site'), ENT_QUOTES) ?></span><b><?= htmlspecialchars(MetaText::text($frontmatter['site']), ENT_QUOTES) ?></b>
<?php endif; ?>
<?php if (isset($frontmatter['referrer'])): ?>
<span><?= htmlspecialchars(t('meta.referrer'), ENT_QUOTES) ?></span><b><?= htmlspecialchars(MetaText::text($frontmatter['referrer']), ENT_QUOTES) ?></b>
<?php endif; ?>
<?php if (isset($frontmatter['indication'])): ?>
<span><?= htmlspecialchars(t('meta.indication'), ENT_QUOTES) ?></span><b><?= htmlspecialchars(MetaText::text($frontmatter['indication']), ENT_QUOTES) ?></b>
<?php endif; ?>
<?php if (isset($frontmatter['protocol'])): ?>
<span><?= htmlspecialchars(t('meta.protocol'), ENT_QUOTES) ?></span><b><?= htmlspecialchars(MetaText::text($frontmatter['protocol']), ENT_QUOTES) ?></b>
<?php endif; ?>
<?php if (isset($frontmatter['template'])): ?>
<span><?= htmlspecialchars(t('meta.template'), ENT_QUOTES) ?></span><b><?= htmlspecialchars(MetaText::text($frontmatter['template']), ENT_QUOTES) ?></b>
<?php endif; ?>
<?php if (isset($frontmatter['summary'])): ?>
<span><?= htmlspecialchars(t('meta.summary'), ENT_QUOTES) ?></span><b class="wk-dim"><?= htmlspecialchars(MetaText::text($frontmatter['summary']), ENT_QUOTES) ?></b>
<?php endif; ?>
<?php if (isset($frontmatter['tags'])): ?>
<span><?= htmlspecialchars(t('meta.tags'), ENT_QUOTES) ?></span><b><?php foreach ((array) $frontmatter['tags'] as $tag): ?><span class="wk-chip"><?= htmlspecialchars(MetaText::text($tag), ENT_QUOTES) ?></span><?php endforeach; ?></b>
<?php endif; ?>
<?php if (isset($frontmatter['priors'])): ?>
<span><?= htmlspecialchars(t('meta.priors'), ENT_QUOTES) ?></span><b><?= htmlspecialchars(MetaText::text($frontmatter['priors']), ENT_QUOTES) ?></b>
<?php endif; ?>
</div>
</div>
<?php endif; ?>

<div class="wk-docbody">
<div class="wk-docgrid<?= \count($toc) >= 2 ? ' wk-has-toc' : '' ?>">
<?php include __DIR__ . '/partials/toc.php'; ?>
<div class="wk-docmain">
<?php foreach ($warnings as $warning): ?>
<p role="alert"><?= htmlspecialchars($warning, ENT_QUOTES) ?></p>
<?php endforeach; ?>

<div class="wk-prose">
<?= $contentHtml ?>
</div>
</div>
</div>
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
<div><span class="wk-eyebrow"><?= htmlspecialchars(t('page.revision'), ENT_QUOTES) ?></span><p class="wk-mono wk-dim">rev <?= $rev ?> · <?= htmlspecialchars(\Reporion\Support\MetaText::when($latestRev['ts']), ENT_QUOTES) ?> · <?= htmlspecialchars($latestRev['by'], ENT_QUOTES) ?><?= $latestRev['note'] !== null ? ' · "'.htmlspecialchars($latestRev['note'], ENT_QUOTES).'"' : '' ?></p></div>
<?php endif; ?>
</div>
</article>
