<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The page's table of contents (roadmap phase 8), for the staff page view
 * and the public layout. Only with two or more headings. Rendered twice on
 * purpose: a <nav> the stylesheet sets beside the text where there is room
 * (sticky, the section in view marked by assets/js/toc.js), and a closed
 * <details> above the text where there is not — only one of them is ever
 * displayed, so assistive technology meets only one. Both come before the
 * text in the source, so keyboard order matches reading order.
 *
 * Variables in scope: list<array{level: int, text: string, slug: string}> $toc; string $basePath
 */

declare(strict_types=1);

/** @var list<array{level: int, text: string, slug: string}> $toc */
/** @var string $basePath */

if (\count($toc) < 2) {
    return;
}
$minLevel = min(array_column($toc, 'level'));
$items = static function () use ($toc, $minLevel): string {
    $html = '';
    foreach ($toc as $entry) {
        $html .= '<li style="padding-left:' . ($entry['level'] - $minLevel) . 'em"><a href="#' . htmlspecialchars($entry['slug'], ENT_QUOTES) . '">'
            . htmlspecialchars($entry['text'], ENT_QUOTES) . '</a></li>';
    }

    return $html;
};
?>
<nav class="wk-toc" data-island="toc" aria-label="<?= htmlspecialchars(t('page.toc'), ENT_QUOTES) ?>">
<span class="wk-eyebrow"><?= htmlspecialchars(t('page.toc'), ENT_QUOTES) ?></span>
<ul><?= $items() ?></ul>
</nav>
<details class="wk-toc-narrow">
<summary><?= htmlspecialchars(t('page.toc_narrow'), ENT_QUOTES) ?></summary>
<ul><?= $items() ?></ul>
</details>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/toc.js'), ENT_QUOTES) ?>" defer></script>
