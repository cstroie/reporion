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
/** @var list<array{path: string, title: string, exams: list<string>, html: string}> $references */
/** @var list<string> $warnings */
/** @var string $basePath */
?>
<article class="wk-doc" data-path="<?= htmlspecialchars($path, ENT_QUOTES) ?>" data-rev="<?= $rev ?>">
<?php if (!isset($headerPath)): ?>
<h1 class="wk-doc-title"><?= htmlspecialchars($title, ENT_QUOTES) ?></h1>
<?php endif; ?>
<?php if (isset($currentRev) && $currentRev !== $rev): ?>
<div class="wk-notice" role="status"><i class="ph ph-clock-counter-clockwise" aria-hidden="true"></i><div><?= htmlspecialchars(t('rev.viewing', [$rev, $currentRev]), ENT_QUOTES) ?> <a href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($path, ENT_QUOTES) ?>"><?= htmlspecialchars(t('rev.view_current'), ENT_QUOTES) ?></a></div></div>
<?php endif; ?>
<?php if (isset($signature)): ?>
<div class="wk-notice wk-sigcheck" role="status"><i class="ph <?= $signature['matches'] ? 'ph-seal-check' : 'ph-warning' ?>" aria-hidden="true"></i><div><?= htmlspecialchars(t('rev.signed_by', [$rev, $signature['by'], \Reporion\Support\MetaText::when($signature['ts'])]), ENT_QUOTES) ?><?= $signature['parafa'] !== null ? ' · ' . htmlspecialchars(t('rev.parafa', [$signature['parafa']]), ENT_QUOTES) : '' ?><br><span class="wk-mono wk-dim"><?= htmlspecialchars($signature['alg'], ENT_QUOTES) ?> <?= htmlspecialchars($signature['digest'], ENT_QUOTES) ?></span><br><?= htmlspecialchars(t($signature['matches'] ? 'rev.digest_matches' : 'rev.digest_differs'), ENT_QUOTES) ?></div></div>
<?php endif; ?>

<?php /* What is wrong with the page (rendering, exams that block signing): a notice above it, never in the report's text */ ?>
<?php foreach ($warnings as $warning): ?>
<div class="wk-notice wk-notice-warn" role="alert"><i class="ph ph-warning" aria-hidden="true"></i><div><?= htmlspecialchars($warning, ENT_QUOTES) ?></div></div>
<?php endforeach; ?>

<?php if (isset($frontmatter)): ?>
<?php $frontmatter = \Reporion\Support\Exams::flat($frontmatter); /* phase 27: a single-exam report's exam fields shown with the rest */ ?>
<?php /* Open on reports, closed on every other page, where it carries little (TODO idea 6) */ ?>
<details class="wk-meta"<?= \Reporion\Support\ReportPath::isReport($path) ? ' open' : '' ?>>
<summary class="wk-meta-h"><span class="wk-eyebrow"><?= htmlspecialchars(t('meta.title'), ENT_QUOTES) ?></span><span class="wk-mono wk-dim"><?= htmlspecialchars(t('page.frontmatter'), ENT_QUOTES) ?></span></summary>
<dl class="wk-kv">
<?php if (isset($frontmatter['patient'])): ?>
<dt><?= htmlspecialchars(t('meta.patient'), ENT_QUOTES) ?></dt><dd class="wk-mono"><?= htmlspecialchars(MetaText::text($frontmatter['patient']['name'] ?? null), ENT_QUOTES) ?> · <?= htmlspecialchars(MetaText::text($frontmatter['patient']['born'] ?? null), ENT_QUOTES) ?> · <?= htmlspecialchars(MetaText::text($frontmatter['patient']['sex'] ?? null), ENT_QUOTES) ?></dd>
<?php endif; ?>
<?php if (isset($frontmatter['accession'])): ?>
<dt><?= htmlspecialchars(t('meta.accession'), ENT_QUOTES) ?></dt><dd class="wk-mono"><?= htmlspecialchars(MetaText::text($frontmatter['accession']), ENT_QUOTES) ?></dd>
<?php endif; ?>
<?php $exams = \Reporion\Support\Exams::declared($frontmatter); ?>
<?php if ($exams !== []): ?>
<?php /* A multi-exam report (phase 12): each exam, its number, a jump to it and — for a writer — straight into its tab */ ?>
<dt><?= htmlspecialchars(t('meta.exams'), ENT_QUOTES) ?></dt><dd class="wk-exams"><?php foreach ($exams as $i => $exam): ?><span class="wk-exam"><a href="#exam-<?= $i + 1 ?>"><?= $i + 1 ?>. <?= htmlspecialchars($exam['title'] !== '' ? $exam['title'] : t('meta.exam_untitled'), ENT_QUOTES) ?></a><?php if ($exam['accession'] !== ''): ?> <span class="wk-mono wk-dim"><?= htmlspecialchars($exam['accession'], ENT_QUOTES) ?></span><?php endif; ?><?php if (($canWrite ?? false) && !isset($currentRev)): ?> <a class="wk-exam-edit" href="<?= htmlspecialchars($basePath . '/' . $path, ENT_QUOTES) ?>/edit?exam=<?= $i + 1 ?>" title="<?= htmlspecialchars(t('meta.exam_edit'), ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('meta.exam_edit'), ENT_QUOTES) ?>"><i class="ph ph-pencil-simple" aria-hidden="true"></i></a><?php endif; ?></span><?php endforeach; ?></dd>
<?php endif; ?>
<?php if (isset($frontmatter['study_date'])): ?>
<dt><?= htmlspecialchars(t('meta.study_date'), ENT_QUOTES) ?></dt><dd><?= htmlspecialchars(MetaText::dateTime($frontmatter['study_date'], \Reporion\Support\MetaText::DATE, ' H:i'), ENT_QUOTES) ?></dd>
<?php endif; ?>
<?php if (isset($frontmatter['modality'])): ?>
<dt><?= htmlspecialchars(t('meta.modality'), ENT_QUOTES) ?></dt><dd><?= htmlspecialchars(MetaText::text($frontmatter['modality']), ENT_QUOTES) ?></dd>
<?php endif; ?>
<?php if (isset($frontmatter['region'])): ?>
<dt><?= htmlspecialchars(t('meta.region'), ENT_QUOTES) ?></dt><dd><?= htmlspecialchars(MetaText::text($frontmatter['region']), ENT_QUOTES) ?></dd>
<?php endif; ?>
<?php if (isset($frontmatter['device'])): ?>
<dt><?= htmlspecialchars(t('meta.device'), ENT_QUOTES) ?></dt><dd><?= htmlspecialchars(MetaText::text($frontmatter['device']), ENT_QUOTES) ?></dd>
<?php endif; ?>
<?php if (isset($frontmatter['site'])): ?>
<dt><?= htmlspecialchars(t('meta.site'), ENT_QUOTES) ?></dt><dd><?= htmlspecialchars(MetaText::text($frontmatter['site']), ENT_QUOTES) ?></dd>
<?php endif; ?>
<?php if (isset($frontmatter['referrer'])): ?>
<dt><?= htmlspecialchars(t('meta.referrer'), ENT_QUOTES) ?></dt><dd><?= htmlspecialchars(MetaText::text($frontmatter['referrer']), ENT_QUOTES) ?></dd>
<?php endif; ?>
<?php if (isset($frontmatter['radiologist'])): ?>
<dt><?= htmlspecialchars(t('meta.radiologist'), ENT_QUOTES) ?></dt><dd><?= htmlspecialchars(MetaText::text($frontmatter['radiologist']), ENT_QUOTES) ?></dd>
<?php endif; ?>
<?php if (isset($frontmatter['indication'])): ?>
<dt><?= htmlspecialchars(t('meta.indication'), ENT_QUOTES) ?></dt><dd><?= htmlspecialchars(MetaText::text($frontmatter['indication']), ENT_QUOTES) ?></dd>
<?php endif; ?>
<?php if (isset($frontmatter['protocol'])): ?>
<dt><?= htmlspecialchars(t('meta.protocol'), ENT_QUOTES) ?></dt><dd><?= htmlspecialchars(MetaText::text($frontmatter['protocol']), ENT_QUOTES) ?></dd>
<?php endif; ?>
<?php if (isset($frontmatter['template'])): ?>
<dt><?= htmlspecialchars(t('meta.template'), ENT_QUOTES) ?></dt><dd><?= htmlspecialchars(MetaText::text($frontmatter['template']), ENT_QUOTES) ?></dd>
<?php endif; ?>
<?php if (isset($frontmatter['summary']) || ($aiSummary ?? false)): ?>
<?php /* Summarize (the reserved `summary` prompt): asks the assistant, then the writer saves or drops it — assets/js/ai-summary.js */ ?>
<dt><?= htmlspecialchars(t('meta.summary'), ENT_QUOTES) ?></dt><dd class="wk-dim"><?= htmlspecialchars(MetaText::text($frontmatter['summary'] ?? null), ENT_QUOTES) ?><?php if ($aiSummary ?? false): ?> <button type="button" class="btn btn-secondary btn-sm wk-ai-summarize" data-ai-summary><i class="ph ph-sparkle" aria-hidden="true"></i><?= htmlspecialchars(t('ai.summary.button'), ENT_QUOTES) ?></button><?php endif; ?></dd>
<?php endif; ?>
<?php if (isset($frontmatter['tags']) || ($aiTags ?? false)): ?>
<?php /* Suggest tags (the reserved `tags` prompt): asks the assistant, then the writer saves or drops them — assets/js/ai-tags.js */ ?>
<dt><?= htmlspecialchars(t('meta.tags'), ENT_QUOTES) ?></dt><dd><?php foreach ((array) ($frontmatter['tags'] ?? []) as $tag): ?><span class="wk-chip"><?= htmlspecialchars(MetaText::text($tag), ENT_QUOTES) ?></span><?php endforeach; ?><?php if ($aiTags ?? false): ?> <button type="button" class="btn btn-secondary btn-sm wk-ai-summarize" data-ai-tags><i class="ph ph-sparkle" aria-hidden="true"></i><?= htmlspecialchars(t('ai.tags.button'), ENT_QUOTES) ?></button><?php endif; ?></dd>
<?php endif; ?>
<?php if (isset($frontmatter['priors'])): ?>
<dt><?= htmlspecialchars(t('meta.priors'), ENT_QUOTES) ?></dt><dd><?= htmlspecialchars(MetaText::text($frontmatter['priors']), ENT_QUOTES) ?></dd>
<?php endif; ?>
</dl>
</details>
<?php endif; ?>

<div class="wk-docbody">
<div class="wk-docgrid<?= \count($toc) >= 2 ? ' wk-has-toc' : '' ?>">
<?php include __DIR__ . '/partials/toc.php'; ?>
<div class="wk-docmain">

<?php /* assets/js/copy-code.js puts a Copy button on each ## heading (that section); the whole text is the tab row's Copy */ ?>
<div class="wk-prosebox" data-copy-section="<?= htmlspecialchars(t('page.copy_section'), ENT_QUOTES) ?>">
<div class="wk-prose">
<?= $contentHtml ?>
</div>
</div>
</div>
</div>
</div>
<footer class="wk-doc-foot">
<section><h2 class="wk-eyebrow"><?= htmlspecialchars(t('page.backlinks'), ENT_QUOTES) ?></h2><div class="wk-links">
<?php if ($backlinks !== []): ?>
<?php foreach ($backlinks as $link): ?>
<a href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($link['path'], ENT_QUOTES) ?>" class="wk-mono"><?= htmlspecialchars($link['path'], ENT_QUOTES) ?></a>
<?php endforeach; ?>
<?php else: ?>
<span class="wk-mono wk-dim"><?= htmlspecialchars(t('page.no_backlinks'), ENT_QUOTES) ?></span>
<?php endif; ?>
</div></section>
<?php if (isset($latestRev)): ?>
<?php /* The author by their display name, as the page header names them */ ?>
<section><h2 class="wk-eyebrow"><?= htmlspecialchars(t('page.revision'), ENT_QUOTES) ?></h2><p class="wk-mono wk-dim">rev <?= $rev ?> · <time datetime="<?= htmlspecialchars((string) $latestRev['ts'], ENT_QUOTES) ?>"><?= htmlspecialchars(\Reporion\Support\MetaText::when($latestRev['ts']), ENT_QUOTES) ?></time> · <?= htmlspecialchars(display_name((string) $latestRev['by']), ENT_QUOTES) ?><?= $latestRev['note'] !== null ? ' · "'.htmlspecialchars($latestRev['note'], ENT_QUOTES).'"' : '' ?></p></section>
<?php endif; ?>
</footer>
</article>
<?php $references ??= [];
include __DIR__ . '/partials/reference-panel.php'; ?>
<?php if ($aiSummary ?? false): ?>
<dialog class="wk-modal wk-modal-wide" id="ai-summary-modal" aria-labelledby="ai-summary-modal-title">
<header><h2 class="wk-eyebrow" id="ai-summary-modal-title"><?= htmlspecialchars(t('ai.summary.title'), ENT_QUOTES) ?></h2><span class="wk-mono wk-dim" data-ai-summary-meta></span><button type="button" class="wk-tbtn" data-modal-close title="<?= htmlspecialchars(t('drawer.close'), ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('drawer.close'), ENT_QUOTES) ?>"><i class="ph ph-x"></i></button></header>
<div class="wk-modal-main" tabindex="-1" autofocus>
<p class="wk-ai-text" data-ai-summary-error hidden></p>
<label class="wk-ai-summary-field" data-ai-summary-field hidden><input class="input" type="text" maxlength="160" data-ai-summary-input></label>
</div>
<footer><button type="button" class="btn btn-secondary" data-ai-summary-close><?= htmlspecialchars(t('editor.ai.close'), ENT_QUOTES) ?></button><button type="button" class="btn btn-primary" data-ai-summary-apply hidden><?= htmlspecialchars(t('ai.summary.apply'), ENT_QUOTES) ?></button></footer>
</dialog>
<script type="application/json" id="ai-summary-config"><?= json_encode([
    'basePath' => $basePath,
    'path' => $path,
    'rev' => $rev,
    'strings' => [
        'busy' => t('ai.summary.busy'),
        'failed' => t('editor.ai.failed'),
        'timeout' => t('editor.ai.timeout'),
        'empty' => t('ai.summary.empty'),
        'saveFailed' => t('ai.summary.save_failed'),
        'conflict' => t('ai.summary.conflict'),
    ],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/ai-summary.js'), ENT_QUOTES) ?>" defer></script>
<?php endif; ?>
<?php if ($aiTags ?? false): ?>
<dialog class="wk-modal wk-modal-wide" id="ai-tags-modal" aria-labelledby="ai-tags-modal-title">
<header><h2 class="wk-eyebrow" id="ai-tags-modal-title"><?= htmlspecialchars(t('ai.tags.title'), ENT_QUOTES) ?></h2><span class="wk-mono wk-dim" data-ai-tags-meta></span><button type="button" class="wk-tbtn" data-modal-close title="<?= htmlspecialchars(t('drawer.close'), ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('drawer.close'), ENT_QUOTES) ?>"><i class="ph ph-x"></i></button></header>
<div class="wk-modal-main" tabindex="-1" autofocus>
<p class="wk-ai-text" data-ai-tags-error hidden></p>
<label class="wk-ai-summary-field" data-ai-tags-field hidden><input class="input" type="text" maxlength="240" data-ai-tags-input><small class="wk-dim"><?= htmlspecialchars(t('ai.tags.help'), ENT_QUOTES) ?></small></label>
</div>
<footer><button type="button" class="btn btn-secondary" data-ai-tags-close><?= htmlspecialchars(t('editor.ai.close'), ENT_QUOTES) ?></button><button type="button" class="btn btn-primary" data-ai-tags-apply hidden><?= htmlspecialchars(t('ai.tags.apply'), ENT_QUOTES) ?></button></footer>
</dialog>
<script type="application/json" id="ai-tags-config"><?= json_encode([
    'basePath' => $basePath,
    'path' => $path,
    'rev' => $rev,
    'tags' => array_values(array_filter(array_map(static fn (mixed $t): string => MetaText::text($t), (array) ($frontmatter['tags'] ?? [])), static fn (string $t): bool => $t !== '')),
    'strings' => [
        'busy' => t('ai.tags.busy'),
        'failed' => t('editor.ai.failed'),
        'timeout' => t('editor.ai.timeout'),
        'empty' => t('ai.tags.empty'),
        'saveFailed' => t('ai.tags.save_failed'),
        'conflict' => t('ai.tags.conflict'),
    ],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/ai-tags.js'), ENT_QUOTES) ?>" defer></script>
<?php endif; ?>
