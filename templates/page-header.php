<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The page header (A6), shared by every route of one page — view, edit,
 * history, compare, patient — and included by templates/layout.php when
 * $headerPath is set (Http\ChromeVars::pageHeader()). Crumbs, title and
 * badges from design/mockup/WikiPage.dc.html's .wk-doc-head, then the
 * page-local tab row: each tab a plain link to its route (A5's rule: chrome
 * never swaps panes client-side). Page actions live here, never in the top
 * nav: Export ▾ (print preview, PDF) for every reader, ⋯ for writers.
 * Assistant joins the row once an AI provider exists (D15).
 *
 * Variables in scope: string $headerPath, $headerTab, $headerTitle,
 * $headerVisibility, $headerStatus, $headerPid, $basePath; int $headerRev;
 * ?string $headerDevice, $headerUpdated, $headerUpdatedBy; bool $canWrite.
 */

declare(strict_types=1);

/** @var string $headerPath */
/** @var string $headerTab */
/** @var string $headerTitle */
/** @var string $headerVisibility */
/** @var string $headerStatus */
/** @var int $headerRev */
/** @var string $headerPid */
/** @var ?string $headerDevice */
/** @var ?string $headerUpdated */
/** @var ?string $headerUpdatedBy */
/** @var bool $canWrite */
/** @var string $basePath */

$b = htmlspecialchars($basePath, ENT_QUOTES);
$p = $b . '/' . htmlspecialchars($headerPath, ENT_QUOTES);
$crumbs = explode(':', $headerPath);
$leaf = array_pop($crumbs);
$tabs = ['view' => ['', 'tabs.report']];
if ($canWrite) {
    $tabs['edit'] = ['/edit', 'tabs.edit'];
}
$tabs += [
    'history' => ['/history', 'tabs.history'],
    'compare' => ['/compare', 'tabs.compare'],
    'patient' => ['/timeline', 'tabs.patient'],
];
$updatedAt = $headerUpdated !== null ? \Reporion\Support\MetaText::when($headerUpdated) : null;
?>
<header class="wk-doc-head wk-pagehead">
<div class="wk-crumbs wk-mono">
<?php $prefix = []; foreach ($crumbs as $segment): $prefix[] = $segment; ?>
<a href="<?= $b ?>/<?= htmlspecialchars(implode(':', $prefix), ENT_QUOTES) ?>:"><?= htmlspecialchars($segment, ENT_QUOTES) ?></a><span>›</span>
<?php endforeach; ?>
<b><?= htmlspecialchars($leaf, ENT_QUOTES) ?></b>
<?php if ($headerPid !== ''): ?>
<button type="button" class="wk-tbtn" title="<?= htmlspecialchars(t('page.copy_id'), ENT_QUOTES) ?>" data-copy-id="<?= htmlspecialchars($headerPid, ENT_QUOTES) ?>" data-copied="<?= htmlspecialchars(t('page.copied'), ENT_QUOTES) ?>"><i class="ph ph-copy"></i></button>
<?php endif; ?>
</div>
<div class="wk-doc-titlerow"><h1 class="wk-doc-title"><?= htmlspecialchars($headerTitle, ENT_QUOTES) ?></h1></div>
<div class="wk-badges">
<span class="tag tag-accent"><?= htmlspecialchars($headerVisibility, ENT_QUOTES) ?></span>
<span class="tag tag-neutral"><?= htmlspecialchars($headerStatus, ENT_QUOTES) ?> · rev <?= $headerRev ?></span>
<?php if ($headerDevice !== null && $headerDevice !== ''): ?>
<span class="tag tag-neutral"><?= htmlspecialchars($headerDevice, ENT_QUOTES) ?></span>
<?php endif; ?>
<?php if ($updatedAt !== null): ?>
<span class="wk-mono wk-dim"><?= htmlspecialchars(t('page.edited', [$updatedAt, $headerUpdatedBy ?? '-']), ENT_QUOTES) ?></span>
<?php endif; ?>
</div>
<nav class="wk-tabs wk-pagetabs" aria-label="<?= htmlspecialchars(t('nav.page'), ENT_QUOTES) ?>">
<?php foreach ($tabs as $key => [$suffix, $label]): ?>
<a class="wk-tab" data-on="<?= $key === $headerTab ? '1' : '' ?>"<?= $key === $headerTab ? ' aria-current="page"' : '' ?> href="<?= $p ?><?= $suffix ?>"><?= htmlspecialchars(t($label), ENT_QUOTES) ?></a>
<?php endforeach; ?>
<span class="wk-tflex"></span>
<details class="wk-menu-wrap">
<summary class="wk-tbtn wk-tbtn-text" title="<?= htmlspecialchars(t('page.export'), ENT_QUOTES) ?>"><i class="ph ph-export"></i><?= htmlspecialchars(t('page.export'), ENT_QUOTES) ?><i class="ph ph-caret-down"></i></summary>
<div class="wk-menu wk-menu-r">
<a class="wk-mi" href="<?= $p ?>/print"><i class="ph ph-printer"></i><?= htmlspecialchars(t('page.print_preview'), ENT_QUOTES) ?></a>
<a class="wk-mi" href="<?= $b ?>/export/<?= htmlspecialchars($headerPath, ENT_QUOTES) ?>.pdf"><i class="ph ph-file-pdf"></i><?= htmlspecialchars(t('page.export_pdf'), ENT_QUOTES) ?></a>
<a class="wk-mi" href="<?= $b ?>/export/<?= htmlspecialchars($headerPath, ENT_QUOTES) ?>.odt"><i class="ph ph-file-doc"></i><?= htmlspecialchars(t('page.export_odt'), ENT_QUOTES) ?></a>
</div>
</details>
<?php if ($canWrite): ?>
<details class="wk-menu-wrap">
<summary class="wk-tbtn" title="<?= htmlspecialchars(t('page.more'), ENT_QUOTES) ?>" aria-haspopup="true"><i class="ph ph-dots-three-vertical"></i></summary>
<div class="wk-menu wk-menu-r">
<a class="wk-mi" href="<?= $p ?>/history"><i class="ph ph-arrow-counter-clockwise"></i><?= htmlspecialchars(t('page.revert'), ENT_QUOTES) ?></a>
<a class="wk-mi" href="<?= $p ?>/visibility"><i class="ph ph-eye"></i><?= htmlspecialchars(t('page.visibility_menu'), ENT_QUOTES) ?></a>
<a class="wk-mi" href="<?= $p ?>/move"><i class="ph ph-arrow-elbow-down-right"></i><?= htmlspecialchars(t('page.move'), ENT_QUOTES) ?></a>
<a class="wk-mi" href="<?= $b ?>/new?from=<?= htmlspecialchars(rawurlencode($headerPath), ENT_QUOTES) ?>"><i class="ph ph-copy-simple"></i><?= htmlspecialchars(t('page.duplicate'), ENT_QUOTES) ?></a>
<div class="wk-mi-sep"></div>
<a class="wk-mi wk-mi-danger" href="<?= $p ?>/delete"><i class="ph ph-trash"></i><?= htmlspecialchars(t('page.delete_menu'), ENT_QUOTES) ?></a>
</div>
</details>
<?php endif; ?>
</nav>
</header>
