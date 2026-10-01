<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The signed-in app shell (A6, design/README.md §"Chosen direction"): the
 * mockup's Reading room layout without its floating dock. Rendered by
 * Http\View::page() around a screen's own content template.
 *
 * - Top nav, site-wide only: ☰ namespace drawer, search (⌘K palette),
 *   + New, namespace index, Admin, theme, palette, account — or Sign in
 *   for an anonymous caller on the screens they can reach (namespace
 *   index, search).
 * - One centred reading column (.wk-panes[data-pad="read"]).
 * - templates/page-header.php at the top of that column when the screen
 *   is one of a page's routes ($headerPath set) — page actions live there.
 * - Except the editor ($editorShell, Controller\EditorController): the
 *   mockup's full-bleed, full-height WikiEditor — no reading column, no
 *   page header (Cancel is the way back to the report).
 *
 * Every link and form action carries $basePath: the live instance is
 * served under a sub-path.
 *
 * Variables in scope: string $pageTitle, $content, $basePath, $username,
 * $nsHref, $drawerNs, $theme, $palette, $themeBodyClass, $currentUrl;
 * bool $isOwner, $canCreate; list $drawerSubnamespaces, $drawerRows;
 * array $quick (Http\QuickNav::links());
 * optional ?string $searchTerm and the page-header vars ($headerPath, …).
 */

declare(strict_types=1);

use Reporion\Http\Theme;

/** @var string $pageTitle */
/** @var string $content */
/** @var string $basePath */
/** @var string $username */
/** @var string $accountName */
/** @var string $accountTitle */
/** @var bool $isOwner */
/** @var bool $canCreate */
/** @var string $nsHref */
/** @var string $drawerNs */
/** @var string $theme */
/** @var string $palette */
/** @var string $themeBodyClass */
/** @var string $currentUrl */

$b = htmlspecialchars($basePath, ENT_QUOTES);
$editorShell ??= false;
$quick ??= ['fixed' => [], 'pinned' => [], 'related' => [], 'here' => '', 'herePinned' => false];
$searchPlaceholder = isset($headerPath) ? $headerPath : t('nav.search');
/* + New is context-sensitive (2026-09-30): on a report page, it starts a
 * new report for that same patient — the same ?after={pid} flow already
 * offered from the page's ⋯ menu and the patient timeline
 * (Http\ChromeVars::pageHeaderFromRow()'s canFollowUp/headerPid, only set
 * on report/revisions/timeline routes) — instead of a blank page in
 * whatever namespace the drawer happens to be showing. */
if (($canFollowUp ?? false) && ($headerPid ?? '') !== '') {
    $newHref = $b . '/new?after=' . rawurlencode($headerPid);
    $newTitle = t('page.new_exam');
} else {
    $newHref = $b . '/new?ns=' . urlencode($drawerNs);
    $newTitle = t('nav.new');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle, ENT_QUOTES) ?> — <?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?></title>
<?php include __DIR__ . '/partials/site-icon.php'; ?>
<?php include __DIR__ . '/partials/head-assets.php'; ?>
</head>
<body class="wk wk-read<?= $editorShell ? ' wk-editing' : '' ?><?= htmlspecialchars($themeBodyClass, ENT_QUOTES) ?>">
<header class="wk-top wk-topnav">
<a class="wk-tbtn" href="<?= $b ?><?= htmlspecialchars($nsHref, ENT_QUOTES) ?>" data-drawer-open aria-controls="wk-drawer" title="<?= htmlspecialchars(t('nav.namespaces'), ENT_QUOTES) ?>"><i class="ph ph-list"></i></a>
<a class="wk-brand" href="<?= $b ?>/"><?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?></a>
<form class="wk-search" action="<?= $b ?>/search" method="get" role="search" data-island="palette" data-config-id="palette-config">
<i class="ph ph-magnifying-glass"></i>
<input type="search" name="q" value="<?= htmlspecialchars($searchTerm ?? '', ENT_QUOTES) ?>" placeholder="<?= htmlspecialchars($searchPlaceholder, ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('search.title'), ENT_QUOTES) ?>">
<span class="wk-kbd" hidden>⌘K</span>
</form>
<?php /* The palette's empty state is the quick-navigation list (Http\QuickNav) */ ?>
<script type="application/json" id="palette-config"><?= json_encode(['basePath' => $basePath, 'quick' => array_map(
    static fn (array $link): array => ['path' => ltrim($link['href'], '/'), 'title' => $link['label']],
    [...$quick['fixed'], ...$quick['pinned'], ...$quick['related']],
)], JSON_HEX_TAG) ?></script>
<nav class="wk-topnav-actions" aria-label="<?= htmlspecialchars(t('nav.site'), ENT_QUOTES) ?>">
<?php if ($canCreate): ?>
<?php /* Otherwise scoped to the namespace being viewed (TODO 13): outside reports:, NewPageController::guided() then offers the plain page form, not the report-only one */ ?>
<a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($newHref, ENT_QUOTES) ?>" title="<?= htmlspecialchars($newTitle, ENT_QUOTES) ?>"><i class="ph ph-plus"></i><span class="wk-btn-label"><?= htmlspecialchars(t('nav.new'), ENT_QUOTES) ?></span></a>
<?php endif; ?>
<a class="wk-tbtn" href="<?= $b ?><?= htmlspecialchars($nsHref, ENT_QUOTES) ?>" title="<?= htmlspecialchars(t('nav.ns_index'), ENT_QUOTES) ?>"><i class="ph ph-folder-open"></i></a>
<?php if ($username !== ''): ?>
<?php /* Quick navigation (Http\QuickNav): home, root, the account's pins, the modality's templates/snippets/reports */ ?>
<details class="wk-menu-wrap">
<summary class="wk-tbtn" title="<?= htmlspecialchars(t('quick.title'), ENT_QUOTES) ?>"><i class="ph ph-push-pin"></i></summary>
<div class="wk-menu wk-menu-r wk-menu-quick">
<?php $quickMode = 'menu';
include __DIR__ . '/partials/quick-nav.php'; ?>
</div>
</details>
<?php endif; ?>
<?php if ($isOwner): ?>
<a class="wk-tbtn" href="<?= $b ?>/admin/users" title="<?= htmlspecialchars(t('nav.admin'), ENT_QUOTES) ?>"><i class="ph ph-sliders-horizontal"></i></a>
<?php endif; ?>
<form class="wk-inline" action="<?= $b ?>/theme" method="post">
<input type="hidden" name="theme" value="<?= $theme === 'light' ? 'dark' : 'light' ?>">
<input type="hidden" name="return_to" value="<?= htmlspecialchars($currentUrl, ENT_QUOTES) ?>">
<button type="submit" class="wk-tbtn" title="<?= htmlspecialchars(t('nav.theme'), ENT_QUOTES) ?>"><i class="ph ph-circle-half"></i></button>
</form>
<details class="wk-menu-wrap">
<summary class="wk-tbtn" title="<?= htmlspecialchars(t('nav.palette'), ENT_QUOTES) ?>"><i class="ph ph-palette"></i></summary>
<form class="wk-menu wk-menu-r" action="<?= $b ?>/palette" method="post">
<input type="hidden" name="return_to" value="<?= htmlspecialchars($currentUrl, ENT_QUOTES) ?>">
<?php foreach (Theme::PALETTES as $option): ?>
<button type="submit" class="wk-mi" name="palette" value="<?= $option ?>"><span class="wk-swatch wk-swatch-<?= $option ?>"></span><?= htmlspecialchars(t('palette.' . $option), ENT_QUOTES) ?><?php if ($option === $palette): ?><i class="ph ph-check wk-mi-end"></i><?php endif; ?></button>
<?php endforeach; ?>
</form>
</details>
<?php if ($username === ''): ?>
<a class="btn btn-secondary btn-sm" href="<?= $b ?>/login"><?= htmlspecialchars(t('nav.signin'), ENT_QUOTES) ?></a>
<?php else: ?>
<details class="wk-menu-wrap">
<summary class="wk-who" title="<?= htmlspecialchars(t('nav.account'), ENT_QUOTES) ?>"><span class="wk-av"><?= htmlspecialchars(\Reporion\Support\Initials::of($accountName), ENT_QUOTES) ?></span></summary>
<div class="wk-menu wk-menu-r">
<?php /* The account's name and title (TODO 13), not "Signed in as {username}" */ ?>
<div class="wk-mi wk-mi-static wk-account-info"><i class="ph ph-user-circle"></i><div><b><?= htmlspecialchars($accountName, ENT_QUOTES) ?></b><?php if ($accountTitle !== ''): ?><span class="wk-dim"><?= htmlspecialchars($accountTitle, ENT_QUOTES) ?></span><?php endif; ?></div></div>
<a class="wk-mi" href="<?= $b ?>/profile"><i class="ph ph-key"></i><?= htmlspecialchars(t('profile.title'), ENT_QUOTES) ?></a>
<div class="wk-mi-sep"></div>
<form action="<?= $b ?>/logout" method="post">
<button type="submit" class="wk-mi"><i class="ph ph-sign-out"></i><?= htmlspecialchars(t('nav.signout'), ENT_QUOTES) ?></button>
</form>
</div>
</details>
<?php endif; ?>
</nav>
</header>
<?php include __DIR__ . '/drawer.php'; ?>
<?php if ($editorShell): ?>
<main class="wk-panes" data-pad="edit">
<?= $content ?>
</main>
<?php else: ?>
<main class="wk-panes" data-pad="read">
<div class="wk-panebox">
<?php if (isset($headerPath)) {
    include __DIR__ . '/page-header.php';
} ?>
<?= $content ?>
</div>
</main>
<?php endif; ?>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/palette.js'), ENT_QUOTES) ?>" defer></script>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/shell.js'), ENT_QUOTES) ?>" defer></script>
<script type="application/json" id="confirm-config"><?= json_encode(['title' => t('confirm.title'), 'ok' => t('confirm.ok'), 'cancel' => t('editor.cancel')], JSON_HEX_TAG) ?></script>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/confirm.js'), ENT_QUOTES) ?>" defer></script>
<?php /* Fenced code in a report/docs/protocol page — a fixed set of languages (assets/css/wiki.css's .hljs-* theme) */ ?>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/highlight.min.js'), ENT_QUOTES) ?>" defer></script>
<script>document.addEventListener('DOMContentLoaded', function () { if (window.hljs) hljs.highlightAll(); });</script>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/copy-code.js'), ENT_QUOTES) ?>" defer></script>
</body>
</html>
