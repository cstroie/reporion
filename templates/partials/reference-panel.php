<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The report view's reference panel (roadmap phase 25): the page(s) its
 * exams' templates name (`reference:`, FORMATS §3i), sliding in from the
 * right when the header's Reference button asks, so the report stays in
 * view beside it. Closed by its ×, Escape or, on a narrow screen, the
 * backdrop (assets/js/reference-panel.js). Without JavaScript the button is
 * a plain link to the page. The editor has no panel: there the reference is
 * a section of its rail, beside the Assistant (templates/editor.php).
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
?>
<div class="wk-ref-backdrop" data-ref-close hidden></div>
<aside class="wk-refpanel" id="reference-panel" aria-label="<?= htmlspecialchars(t('refs.title'), ENT_QUOTES) ?>" hidden>
<div class="wk-rail-head">
<span class="wk-eyebrow"><i class="ph ph-book-open"></i> <?= htmlspecialchars(t('refs.title'), ENT_QUOTES) ?></span>
<button type="button" class="wk-tbtn" data-ref-close title="<?= htmlspecialchars(t('drawer.close'), ENT_QUOTES) ?>"><i class="ph ph-x"></i></button>
</div>
<?php include __DIR__ . '/reference-pages.php'; ?>
</aside>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/reference-panel.js'), ENT_QUOTES) ?>" defer></script>
