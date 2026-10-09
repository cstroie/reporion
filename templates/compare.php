<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /{path}/compare[?with={pid}] (Controller\CompareController): a report
 * beside another study of the same patient, newer on the left
 * (design/mockup/WikiCompare.dc.html, its report-vs-prior half). Content
 * only: Http\View::page() wraps it in templates/layout.php, under the
 * Patient tab. "Sync sections" lines the two up by exam and section, by
 * name (Support\CompareSections) — one grid row per section, so a section's
 * two sides start level, the older report's moved to the newer one's order
 * (said above the grid when that moved anything); off, the two pages run whole. The Delta panel is the
 * Evolution panel (assets/js/ai-evolution.js) asked about these two only.
 *
 * Variables in scope: string $path, $withPid, $interval, $basePath;
 * array{path: string, pid: string, title: string, date: string, status: string, rev: int, html: string} $newer, $older;
 * bool $sync, $aiEvolution; array{rows: list<array{0: string, 1: string}>, reordered: bool} $aligned
 */

declare(strict_types=1);

/** @var string $path */
/** @var string $withPid */
/** @var string $interval */
/** @var string $basePath */
/** @var array{path: string, pid: string, title: string, date: string, status: string, rev: int, html: string} $newer */
/** @var array{path: string, pid: string, title: string, date: string, status: string, rev: int, html: string} $older */
/** @var bool $sync */
/** @var bool $aiEvolution */
/** @var array{rows: list<array{0: string, 1: string}>, reordered: bool} $aligned */

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$b = $e($basePath);
/** One side's caption: its date, exam, status and revision; the exam links to the report */
$caption = static function (array $side) use ($e, $b): string {
    return '<div class="wk-crumbs wk-mono">'
        . ($side['date'] !== '' ? '<b><time datetime="' . $e($side['date']) . '">' . $e($side['date']) . '</time></b>' : '')
        . '<span class="tag ' . \Reporion\Support\Badges::statusTag($side['status']) . '">' . $e($side['status'] . ' · ' . t('revisions.rev_label', [$side['rev']])) . '</span>'
        . '<a href="' . $b . '/' . $e($side['path']) . '">' . $e($side['title']) . '</a>'
        . '</div>';
};
?>
<div class="wk-doc">
<div class="wk-doc-titlerow wk-sec"><hgroup>
<h2 class="wk-sec-title"><?= $e(t('compare.heading')) ?></h2>
<?php if ($interval !== ''): ?><p class="wk-badges"><span class="tag tag-neutral"><?= $e(t('compare.interval', [$interval])) ?></span></p><?php endif; ?>
</hgroup>
<div class="wk-actions">
<form method="get" action="<?= $b ?>/<?= $e($path) ?>/compare" data-autosubmit>
<input type="hidden" name="with" value="<?= $e($withPid) ?>">
<input type="hidden" name="sync" value="0">
<label class="radio"><input type="checkbox" name="sync" value="1"<?= $sync ? ' checked' : '' ?>><span class="dot"></span><?= $e(t('compare.sync')) ?></label>
<noscript><button class="btn btn-secondary btn-sm" type="submit"><?= $e(t('compare.apply')) ?></button></noscript>
</form>
<a class="btn btn-secondary" href="<?= $b ?>/<?= $e($path) ?>/timeline"><i class="ph ph-arrow-u-up-left" aria-hidden="true"></i><?= $e(t('compare.back')) ?></a>
</div>
</div>
<?php if ($aiEvolution): ?>
<?php /* Delta: the Evolution panel (the reserved `evolution` prompt) with `with` narrowing its history to the other study — shown, never written */ ?>
<section class="wk-panel wk-ai-evo" aria-labelledby="cmp-evo-h" aria-live="polite">
<header class="wk-panel-h"><h2 class="wk-eyebrow" id="cmp-evo-h"><i class="ph ph-sparkle" aria-hidden="true"></i> <?= $e(t('compare.delta')) ?></h2><span class="wk-mono wk-dim" data-ai-evo-meta></span></header>
<div class="wk-ai-evo-body" data-ai-evo-body><p class="wk-dim"><?= $e(t('compare.delta_help')) ?></p></div>
<div class="wk-ai-row"><button type="button" class="btn btn-primary btn-sm" data-ai-evo><i class="ph ph-sparkle" aria-hidden="true"></i><?= $e(t('compare.delta_button')) ?></button><button type="button" class="btn btn-secondary btn-sm" data-ai-evo-copy hidden><?= $e(t('editor.ai.copy')) ?></button></div>
</section>
<script type="application/json" id="ai-evo-config"><?= json_encode([
    'basePath' => $basePath,
    'path' => $newer['path'],
    'with' => $older['pid'],
    'strings' => ['working' => t('editor.ai.working'), 'failed' => t('editor.ai.failed'), 'copied' => t('editor.tb.copied')],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= $e(\Reporion\Support\Asset::url($basePath, 'marked.js')) ?>" defer></script>
<script src="<?= $e(\Reporion\Support\Asset::url($basePath, 'js/markdown-preview.js')) ?>" defer></script>
<script src="<?= $e(\Reporion\Support\Asset::url($basePath, 'js/ai-evolution.js')) ?>" defer></script>
<?php endif; ?>
<?php if ($sync): ?>
<?php if ($aligned['reordered']): ?>
<p class="wk-dim wk-cmp-note"><i class="ph ph-arrows-down-up" aria-hidden="true"></i> <?= $e(t('compare.reordered', [$older['date']])) ?></p>
<?php endif; ?>
<?php /* One grid, two columns: each section's two cells share a row. Under 1024px it is one column, newer then older per section — each cell names its date there */ ?>
<div class="wk-cmp wk-cmp-sync">
<div class="wk-cmp-cap"><?= $caption($newer) ?></div>
<div class="wk-cmp-cap"><?= $caption($older) ?></div>
<?php foreach ($aligned['rows'] as [$left, $right]): ?>
<div class="wk-prose wk-cmp-cell<?= $left === '' ? ' wk-cmp-none' : '' ?>"><span class="wk-cmp-when wk-mono wk-dim"><?= $e($newer['date']) ?></span><?= $left /* Render::toHtml() output, split at its headings */ ?></div>
<div class="wk-prose wk-cmp-cell<?= $right === '' ? ' wk-cmp-none' : '' ?>"><span class="wk-cmp-when wk-mono wk-dim"><?= $e($older['date']) ?></span><?= $right ?></div>
<?php endforeach; ?>
</div>
<?php else: ?>
<div class="wk-cmp">
<?php foreach ([$newer, $older] as $side): ?>
<div>
<?= $caption($side) ?>
<div class="wk-prose"><?= $side['html'] /* Render::toHtml() output, the same canonical HTML the page view prints */ ?></div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
