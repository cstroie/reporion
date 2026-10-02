<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * A report's reference page(s), rendered (roadmap phase 25,
 * Service\References): one per distinct page its exams' templates name,
 * a switch at the top when there are several (assets/js/reference-panel.js
 * shows one at a time). Shared by the report view's slide-in panel
 * (partials/reference-panel.php) and the editor rail's Reference section.
 *
 * Variables in scope: list<array{path: string, title: string, exams: list<string>, html: string}> $references;
 * string $basePath
 */

declare(strict_types=1);

/** @var list<array{path: string, title: string, exams: list<string>, html: string}> $references */
/** @var string $basePath */

$re = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
?>
<div class="wk-ref-pages" data-ref-pages>
<?php if (\count($references) > 1): ?>
<div class="seg seg-sm wk-ref-tabs" role="tablist">
<?php foreach ($references as $i => $ref): ?>
<button type="button" class="seg-opt<?= $i === 0 ? ' seg-on' : '' ?>" role="tab" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>" data-ref-tab="<?= (int) $i ?>" title="<?= $re(implode(', ', $ref['exams'])) ?>"><?= $re($ref['title']) ?></button>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php foreach ($references as $i => $ref): ?>
<article class="wk-ref-page" data-ref-page="<?= (int) $i ?>"<?= $i > 0 ? ' hidden' : '' ?>>
<h2 class="wk-ref-title"><?= $re($ref['title']) ?><a class="wk-tbtn" href="<?= $re($basePath) ?>/<?= $re($ref['path']) ?>" target="_blank" rel="noopener" title="<?= $re(t('refs.new_tab')) ?>" aria-label="<?= $re(t('refs.new_tab')) ?>"><i class="ph ph-arrow-square-out"></i></a></h2>
<p class="wk-mono wk-dim wk-ref-for"><?= $re(t('refs.for', [implode(', ', $ref['exams'])])) ?></p>
<div class="wk-prose"><?= $ref['html'] ?></div>
</article>
<?php endforeach; ?>
</div>
