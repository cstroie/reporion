<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * A report's reference page (roadmap phase 25, Service\References): the
 * page its exam's template names (`reference:`, FORMATS §3i) — the knee
 * page, the brain page — rendered in a panel that slides in from the right
 * when the Reference button (page header, editor crumbs line) asks for it,
 * so the report stays in view beside it. Several only when the exams'
 * templates name different pages: a switch at the top. Closed by its ×,
 * Escape or, on a narrow screen, the backdrop (assets/js/reference-panel.js).
 * Without JavaScript the button is a plain link to the page.
 *
 * Variables in scope: list<array{path: string, title: string, exams: list<string>, html: string}> $references;
 * string $basePath
 */

declare(strict_types=1);

/** @var list<array{path: string, title: string, exams: list<string>, html: string}> $references */
/** @var string $basePath */

if ($references === []) {
    return;
}
$re = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$rb = $re($basePath);
?>
<div class="wk-ref-backdrop" data-ref-close hidden></div>
<aside class="wk-refpanel" id="reference-panel" aria-label="<?= $re(t('refs.title')) ?>" hidden>
<div class="wk-rail-head">
<span class="wk-eyebrow"><i class="ph ph-book-open"></i> <?= $re(t('refs.title')) ?></span>
<span class="wk-refpanel-acts">
<a class="wk-tbtn" id="reference-new-tab" href="<?= $rb ?>/<?= $re($references[0]['path']) ?>" target="_blank" rel="noopener" title="<?= $re(t('refs.new_tab')) ?>"><i class="ph ph-arrow-square-out"></i></a>
<button type="button" class="wk-tbtn" data-ref-close title="<?= $re(t('drawer.close')) ?>"><i class="ph ph-x"></i></button>
</span>
</div>
<?php if (\count($references) > 1): ?>
<div class="seg seg-sm wk-ref-tabs" role="tablist">
<?php foreach ($references as $i => $ref): ?>
<button type="button" class="seg-opt<?= $i === 0 ? ' seg-on' : '' ?>" role="tab" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>" data-ref-tab="<?= (int) $i ?>" data-ref-href="<?= $rb ?>/<?= $re($ref['path']) ?>" title="<?= $re(implode(', ', $ref['exams'])) ?>"><?= $re($ref['title']) ?></button>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php foreach ($references as $i => $ref): ?>
<article class="wk-ref-page" data-ref-page="<?= (int) $i ?>"<?= $i > 0 ? ' hidden' : '' ?>>
<h2 class="wk-ref-title"><?= $re($ref['title']) ?></h2>
<p class="wk-mono wk-dim wk-ref-for"><?= $re(t('refs.for', [implode(', ', $ref['exams'])])) ?></p>
<div class="wk-prose"><?= $ref['html'] ?></div>
</article>
<?php endforeach; ?>
</aside>
<script src="<?= $re(\Reporion\Support\Asset::url($basePath, 'js/reference-panel.js')) ?>" defer></script>
