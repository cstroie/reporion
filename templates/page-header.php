<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The page header (A6), shared by every route of one page — view,
 * revisions, patient; not edit, which is the mockup's full-bleed
 * editor with its own crumbs line (templates/editor.php) — and included by templates/layout.php when
 * $headerPath is set (Http\ChromeVars::pageHeader()). Crumbs, title and
 * badges from design/mockup/WikiPage.dc.html's .wk-doc-head, then the
 * page-local tab row: each tab a plain link to its route (A5's rule: chrome
 * never swaps panes client-side). Page actions live here, never in the top
 * nav: Sign on a draft report the caller may write (Controller\SignController),
 * Export ▾ (print preview, PDF, ODT, markdown — plus the plugins' export actions on a signed report for signed-in readers) for every reader, ⋯ for writers — with
 * the loaded plugins' page actions on a report (reporion_plugin_ui()).
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
    'revisions' => ['/revisions', 'tabs.revisions'],
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
<span class="tag <?= \Reporion\Support\Badges::visibilityTag($headerVisibility) ?>"><?= htmlspecialchars($headerVisibility, ENT_QUOTES) ?></span>
<span class="tag <?= \Reporion\Support\Badges::statusTag($headerStatus) ?>"><?php if ($headerStatus === 'signed'): ?><i class="ph ph-seal-check"></i> <?php endif; ?><?= htmlspecialchars($headerStatus, ENT_QUOTES) ?> · rev <?= $headerRev ?></span>
<?php if ($headerDevice !== null && $headerDevice !== ''): ?>
<span class="tag tag-neutral"><?= htmlspecialchars($headerDevice, ENT_QUOTES) ?></span>
<?php endif; ?>
<?php if ($updatedAt !== null): ?>
<span class="wk-mono wk-dim"><?= htmlspecialchars(t('page.edited', [$updatedAt, $headerUpdatedBy !== null ? display_name($headerUpdatedBy) : '-']), ENT_QUOTES) ?></span>
<?php endif; ?>
</div>
<nav class="wk-tabs wk-pagetabs" aria-label="<?= htmlspecialchars(t('nav.page'), ENT_QUOTES) ?>">
<?php
// Every tab once: the full row on a wide screen, the "current ▾" menu on a narrow one (CSS picks)
$tabLinks = [];
foreach ($tabs as $key => [$suffix, $label]) {
    $tabLinks[] = ['href' => $p . $suffix, 'label' => t($label), 'on' => $key === $headerTab, 'busy' => false];
}
if ($canWrite && $headerPid !== '' && \Reporion\Support\ReportPath::isReport($headerPath)) {
    foreach (reporion_plugin_ui()['page_tab'] ?? [] as $slot) {
        $tabLinks[] = ['href' => $b . htmlspecialchars(str_replace('{pid}', rawurlencode($headerPid), $slot['href']), ENT_QUOTES), 'label' => t($slot['label']), 'on' => $headerTab === 'plugin:' . $slot['plugin'], 'busy' => true];
    }
}
$currentTab = array_values(array_filter($tabLinks, static fn (array $tab): bool => $tab['on']))[0]['label'] ?? $tabLinks[0]['label'];
?>
<div class="wk-pagetabs-scroll">
<?php foreach ($tabLinks as $tab): ?>
<a class="wk-tab"<?= $tab['busy'] ? ' data-busy' : '' ?> data-on="<?= $tab['on'] ? '1' : '' ?>"<?= $tab['on'] ? ' aria-current="page"' : '' ?> href="<?= $tab['href'] ?>"><?= htmlspecialchars($tab['label'], ENT_QUOTES) ?></a>
<?php endforeach; ?>
</div>
<details class="wk-menu-wrap wk-pagetabs-menu">
<summary class="wk-tab" data-on="1"><?= htmlspecialchars($currentTab, ENT_QUOTES) ?><i class="ph ph-caret-down"></i></summary>
<div class="wk-menu">
<?php foreach ($tabLinks as $tab): ?>
<a class="wk-mi"<?= $tab['busy'] ? ' data-busy' : '' ?><?= $tab['on'] ? ' aria-current="page"' : '' ?> href="<?= $tab['href'] ?>"><?= htmlspecialchars($tab['label'], ENT_QUOTES) ?><?php if ($tab['on']): ?><i class="ph ph-check wk-mi-end"></i><?php endif; ?></a>
<?php endforeach; ?>
</div>
</details>
<span class="wk-tflex"></span>
<?php if ($canSign ?? false): ?>
<a class="btn btn-primary btn-sm" href="<?= $p ?>/sign" title="<?= htmlspecialchars(t('page.sign'), ENT_QUOTES) ?>"><i class="ph ph-seal-check"></i><span class="wk-btn-label"><?= htmlspecialchars(t('page.sign'), ENT_QUOTES) ?></span></a>
<?php endif; ?>
<details class="wk-menu-wrap">
<summary class="wk-tbtn wk-tbtn-text" title="<?= htmlspecialchars(t('page.export'), ENT_QUOTES) ?>"><i class="ph ph-export"></i><span class="wk-btn-label"><?= htmlspecialchars(t('page.export'), ENT_QUOTES) ?></span><i class="ph ph-caret-down"></i></summary>
<div class="wk-menu wk-menu-r">
<a class="wk-mi" href="<?= $p ?>/print"><i class="ph ph-printer"></i><?= htmlspecialchars(t('page.print_preview'), ENT_QUOTES) ?></a>
<a class="wk-mi" href="<?= $b ?>/export/<?= htmlspecialchars($headerPath, ENT_QUOTES) ?>.pdf"><i class="ph ph-file-pdf"></i><?= htmlspecialchars(t('page.export_pdf'), ENT_QUOTES) ?></a>
<a class="wk-mi" href="<?= $b ?>/export/<?= htmlspecialchars($headerPath, ENT_QUOTES) ?>.odt"><i class="ph ph-file-doc"></i><?= htmlspecialchars(t('page.export_odt'), ENT_QUOTES) ?></a>
<a class="wk-mi" href="<?= $b ?>/export/<?= htmlspecialchars($headerPath, ENT_QUOTES) ?>.md"><i class="ph ph-file-md"></i><?= htmlspecialchars(t('page.export_md'), ENT_QUOTES) ?></a>
<?php if ($headerPid !== '' && $headerStatus === 'signed' && ($username ?? '') !== '' && \Reporion\Support\ReportPath::isReport($headerPath)): ?>
<?php foreach (reporion_plugin_ui()['export_action'] ?? [] as $slot): ?>
<a class="wk-mi" href="<?= $b . htmlspecialchars(str_replace('{pid}', rawurlencode($headerPid), $slot['href']), ENT_QUOTES) ?>"><i class="ph ph-<?= htmlspecialchars($slot['icon'], ENT_QUOTES) ?>"></i><?= htmlspecialchars(t($slot['label']), ENT_QUOTES) ?></a>
<?php endforeach; ?>
<?php endif; ?>
</div>
</details>
<?php if ($canWrite): ?>
<details class="wk-menu-wrap">
<summary class="wk-tbtn" title="<?= htmlspecialchars(t('page.more'), ENT_QUOTES) ?>" aria-haspopup="true"><i class="ph ph-dots-three-vertical"></i></summary>
<div class="wk-menu wk-menu-r">
<a class="wk-mi" href="<?= $p ?>/visibility"><i class="ph ph-eye"></i><?= htmlspecialchars(t('page.visibility_menu'), ENT_QUOTES) ?><span class="wk-mi-end wk-dim"><?= htmlspecialchars($headerVisibility, ENT_QUOTES) ?></span></a>
<a class="wk-mi" href="<?= $p ?>/move"><i class="ph ph-arrow-elbow-down-right"></i><?= htmlspecialchars(t('page.move'), ENT_QUOTES) ?></a>
<a class="wk-mi" href="<?= $p ?>/move?rename=1"><i class="ph ph-text-aa"></i><?= htmlspecialchars(t('page.rename'), ENT_QUOTES) ?></a>
<?php if ($canFollowUp ?? false): ?>
<a class="wk-mi" href="<?= $b ?>/new?after=<?= htmlspecialchars(rawurlencode($headerPid), ENT_QUOTES) ?>"><i class="ph ph-user-plus"></i><?= htmlspecialchars(t('page.new_exam'), ENT_QUOTES) ?></a>
<?php endif; ?>
<a class="wk-mi" href="<?= $b ?>/new?from=<?= htmlspecialchars(rawurlencode($headerPath), ENT_QUOTES) ?>"><i class="ph ph-copy-simple"></i><?= htmlspecialchars(t('page.duplicate'), ENT_QUOTES) ?></a>
<?php if ($headerPid !== '' && \Reporion\Support\ReportPath::isReport($headerPath)): ?>
<?php foreach (reporion_plugin_ui()['page_action'] ?? [] as $slot): ?>
<a class="wk-mi" href="<?= $b . htmlspecialchars(str_replace('{pid}', rawurlencode($headerPid), $slot['href']), ENT_QUOTES) ?>"><i class="ph ph-<?= htmlspecialchars($slot['icon'], ENT_QUOTES) ?>"></i><?= htmlspecialchars(t($slot['label']), ENT_QUOTES) ?></a>
<?php endforeach; ?>
<?php endif; ?>
<div class="wk-mi-sep"></div>
<a class="wk-mi wk-mi-danger" href="<?= $p ?>/delete"><i class="ph ph-trash"></i><?= htmlspecialchars(t('page.delete_menu'), ENT_QUOTES) ?></a>
</div>
</details>
<?php endif; ?>
</nav>
</header>
