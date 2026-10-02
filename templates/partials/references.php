<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * A report's reference pages (roadmap phase 25, Service\References): the
 * pages its exams' templates list, each opening in a new tab so the report
 * stays where it is. Grouped by exam only when more than one exam brings
 * any. Included by the editor's rail and the report view's side column.
 *
 * Variables in scope: list<array{exam: int, title: string, template: string,
 * pages: list<array{path: string, title: string, summary: string}>}> $references;
 * string $basePath
 */

declare(strict_types=1);

/** @var list<array{exam: int, title: string, template: string, pages: list<array{path: string, title: string, summary: string}>}> $references */
/** @var string $basePath */

if ($references === []) {
    return;
}
$re = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$grouped = \count($references) > 1;
?>
<section class="wk-refs" id="references" aria-label="<?= $re(t('refs.title')) ?>">
<span class="wk-eyebrow"><i class="ph ph-books"></i> <?= $re(t('refs.title')) ?></span>
<?php foreach ($references as $group): ?>
<?php if ($grouped): ?><span class="wk-refs-exam"><?= $re($group['title'] !== '' ? $group['title'] : t('editor.check.exam', [$group['exam'] + 1])) ?></span><?php endif; ?>
<ul>
<?php foreach ($group['pages'] as $page): ?>
<li><a href="<?= $re($basePath) ?>/<?= $re($page['path']) ?>" target="_blank" rel="noopener" title="<?= $re($page['path']) ?>"><?= $re($page['title']) ?><i class="ph ph-arrow-square-out" aria-hidden="true"></i></a><?php if ($page['summary'] !== ''): ?><span class="wk-refs-sum"><?= $re($page['summary']) ?></span><?php endif; ?></li>
<?php endforeach; ?>
</ul>
<?php endforeach; ?>
</section>
