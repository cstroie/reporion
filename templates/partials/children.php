<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Head pages (D61): under a page's text, the pages of the namespace of its
 * name, in its `children` mode (Support\HeadPage): `list` cards, a `toc`
 * outline, `chapters` rendered in turn, or `none` (a link only). Built by
 * Service\HeadChildren under the listing predicate (invariant 6).
 *
 * Variables in scope: ?array $headChildren; string $basePath
 */

declare(strict_types=1);

/** @var ?array{mode: string, ns: string, nodes: list<array<string, mixed>>, chapters: list<array<string, mixed>>, toc: list<array<string, mixed>>} $headChildren */
/** @var string $basePath */

if (($headChildren ?? null) === null) {
    return;
}
$base = htmlspecialchars($basePath, ENT_QUOTES);
$href = static fn (array $node): string => $base . '/' . htmlspecialchars((string) $node['path'], ENT_QUOTES) . ($node['page'] ? '' : ':');
$outline = static function (array $nodes) use (&$outline, $href): string {
    $html = '<ul>';
    foreach ($nodes as $node) {
        $html .= '<li><a href="' . $href($node) . '">' . htmlspecialchars((string) $node['title'], ENT_QUOTES) . '</a>'
            . ($node['children'] !== [] ? $outline($node['children']) : '') . '</li>';
    }

    return $html . '</ul>';
};
$browse = '<p class="wk-mono wk-dim"><a href="' . $base . '/' . htmlspecialchars($headChildren['ns'], ENT_QUOTES) . ':">'
    . htmlspecialchars(t('page.children.browse', [$headChildren['ns']]), ENT_QUOTES) . '</a></p>';
?>
<?php if ($headChildren['mode'] === 'chapters'): ?>
<?php foreach ($headChildren['chapters'] as $chapter): $h = 'h' . (int) $chapter['level']; ?>
<section class="wk-chapter wk-prose" data-path="<?= htmlspecialchars((string) $chapter['path'], ENT_QUOTES) ?>">
<<?= $h ?> id="<?= htmlspecialchars((string) $chapter['id'], ENT_QUOTES) ?>"><a href="<?= $href($chapter) ?>"><?= htmlspecialchars((string) $chapter['title'], ENT_QUOTES) ?></a></<?= $h ?>>
<?= $chapter['html'] ?>
</section>
<?php endforeach; ?>
<?= $browse ?>
<?php else: ?>
<section class="wk-children" aria-labelledby="children-title">
<h2 class="wk-eyebrow" id="children-title"><?= htmlspecialchars(t('page.children'), ENT_QUOTES) ?> <span class="wk-dim">(<?= \count($headChildren['nodes']) ?>)</span></h2>
<?php if ($headChildren['mode'] === 'list'): ?>
<div class="wk-cards">
<?php foreach ($headChildren['nodes'] as $node): ?>
<a class="wk-nscard" data-priority="<?= htmlspecialchars((string) ($node['priority'] ?? ''), ENT_QUOTES) ?>" href="<?= $href($node) ?>"><?= htmlspecialchars((string) $node['title'], ENT_QUOTES) ?><?php if ($node['summary'] !== null): ?><span class="wk-dim"><?= htmlspecialchars((string) $node['summary'], ENT_QUOTES) ?></span><?php endif; ?></a>
<?php endforeach; ?>
</div>
<?php elseif ($headChildren['mode'] === 'toc'): ?>
<nav class="wk-children-toc"><?= $outline($headChildren['nodes']) ?></nav>
<?php else: ?>
<p class="wk-mono wk-dim"><?= htmlspecialchars(t('page.children.count', [\count($headChildren['nodes'])]), ENT_QUOTES) ?></p>
<?php endif; ?>
<?= $browse ?>
</section>
<?php endif; ?>
