<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The signed-in app shell (A6, design/README.md §"Chosen direction"): the
 * mockup's Reading room layout without its floating dock. Rendered by
 * Http\View::page() around a screen's own content template.
 *
 * - Top nav, site-wide only (under 576px the actions wrap onto a second row, and the
 *   brand becomes an icon under 925px): ☰ namespace drawer, search (⌘K palette),
 *   + New, 📌 quick navigation (Http\QuickNav), namespace index,
 *   appearance (light/dark + palette), Admin, account — or Sign in
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
 * optional ?string $searchTerm and the page-header vars ($headerPath, …);
 * optional ?string $pageSubject, $pageFunction for the title.
 *
 * The browser tab's title (2026-10-09): who, then what, then the app — the
 * page's subject (its title: a report's is the patient's name, D30; or
 * $pageSubject), the function (the active tab's label, else $pageFunction,
 * else $pageTitle), the app name (Admin → Settings). A page with no subject
 * keeps "$pageTitle — app".
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
 * new report for that same patient — the same /{path}/new flow already
 * offered from the page's ⋯ menu and the patient timeline
 * (Http\ChromeVars::pageHeaderFromRow()'s canFollowUp/headerPid, only set
 * on report/revisions/timeline routes) — instead of a blank page in
 * whatever namespace the drawer happens to be showing. */
if (($canFollowUp ?? false) && ($headerPath ?? '') !== '') {
    $newHref = $b . '/' . $headerPath . '/new';
    $newTitle = t('page.new_exam');
} else {
    $newHref = $b . ($drawerNs === '' ? '' : '/' . $drawerNs) . '/new';
    $newTitle = t('nav.new');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php
$titleSubject = ($pageSubject ?? null) ?: ($headerTitle ?? null);
$titleTabs = ['view' => ($headerIsReport ?? false) ? 'tabs.report' : 'tabs.view', 'edit' => 'tabs.edit', 'revisions' => 'tabs.revisions', 'timeline' => 'tabs.timeline'];
$titleFunction = ($pageFunction ?? null) ?: (isset($headerTab, $titleTabs[$headerTab]) ? t($titleTabs[$headerTab]) : $pageTitle);
$titleParts = $titleSubject !== null && $titleSubject !== '' && $titleSubject !== $titleFunction ? [$titleSubject, $titleFunction] : [$pageTitle];
?>
<title><?= htmlspecialchars(implode(' — ', [...$titleParts, t('app.name')]), ENT_QUOTES) ?></title>
<?php include __DIR__ . '/partials/site-icon.php'; ?>
<?php include __DIR__ . '/partials/head-assets.php'; ?>
</head>
<body class="wk wk-read<?= $editorShell ? ' wk-editing' : '' ?><?= htmlspecialchars($themeBodyClass, ENT_QUOTES) ?>">
<header class="wk-top wk-topnav">
<a class="wk-tbtn" href="<?= $b ?><?= htmlspecialchars($nsHref, ENT_QUOTES) ?>" data-drawer-open aria-controls="wk-drawer" title="<?= htmlspecialchars(t('nav.namespaces'), ENT_QUOTES) ?>"><i class="ph ph-list"></i></a>
<?php /* The brand goes to the start page — and from the start page itself, to the site home page, so a second click toggles between the two */ ?>
<?php $onStart = ($isStartPage ?? false) && ($homePageHref = reporion_instance()['home_page_path'] ?? '') !== ''; ?>
<a class="wk-brand" href="<?= $b ?>/<?= $onStart ? htmlspecialchars($homePageHref, ENT_QUOTES) : '' ?>" title="<?= htmlspecialchars(t($onStart ? 'dash.home_page' : 'nav.start'), ENT_QUOTES) ?>"><i class="ph ph-house wk-brand-icon" aria-hidden="true"></i><span class="wk-brand-text"><?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?></span></a>
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
<?php /* The actions as one group, so a narrow screen wraps them onto a second row under ☰ · brand · search */ ?>
<div class="wk-topnav-more">
<?php if ($canCreate): ?>
<?php /* Otherwise scoped to the namespace being viewed (TODO 13): outside reports:, NewPageController::guided() then offers the plain page form, not the report-only one */ ?>
<a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($newHref, ENT_QUOTES) ?>" title="<?= htmlspecialchars($newTitle, ENT_QUOTES) ?>"><i class="ph ph-plus"></i><span class="wk-btn-label"><?= htmlspecialchars(t('nav.new'), ENT_QUOTES) ?></span></a>
<?php endif; ?>
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
<a class="wk-tbtn" href="<?= $b ?><?= htmlspecialchars($nsHref, ENT_QUOTES) ?>" title="<?= htmlspecialchars(t('nav.ns_index'), ENT_QUOTES) ?>"><i class="ph ph-folder-open"></i></a>
<?php /* Appearance: light/dark (POST /theme), then the colour palette (POST /palette) — two forms, one menu; assets/js/shell.js applies a choice in place so the menu stays open to try another */ ?>
<details class="wk-menu-wrap">
<summary class="wk-tbtn" title="<?= htmlspecialchars(t('nav.appearance'), ENT_QUOTES) ?>"><i class="ph ph-palette"></i></summary>
<div class="wk-menu wk-menu-r">
<form class="wk-theme-row" action="<?= $b ?>/theme" method="post" data-appearance="theme">
<input type="hidden" name="return_to" value="<?= htmlspecialchars($currentUrl, ENT_QUOTES) ?>">
<i class="ph ph-circle-half"></i><?= htmlspecialchars(t('nav.theme'), ENT_QUOTES) ?>
<span class="seg seg-sm" role="group" aria-label="<?= htmlspecialchars(t('nav.theme'), ENT_QUOTES) ?>">
<?php foreach (['dark' => 'moon', 'light' => 'sun'] as $option => $icon): ?>
<button type="submit" class="seg-opt" name="theme" value="<?= $option ?>" aria-pressed="<?= $option === $theme ? 'true' : 'false' ?>"><i class="ph ph-<?= $icon ?>"></i><?= htmlspecialchars(t('theme.' . $option), ENT_QUOTES) ?></button>
<?php endforeach; ?>
</span>
</form>
<div class="wk-mi-sep"></div>
<form action="<?= $b ?>/palette" method="post" data-appearance="palette" data-default="<?= Theme::DEFAULT_PALETTE ?>">
<input type="hidden" name="return_to" value="<?= htmlspecialchars($currentUrl, ENT_QUOTES) ?>">
<?php foreach (Theme::PALETTES as $option): ?>
<button type="submit" class="wk-mi" name="palette" value="<?= $option ?>"><span class="wk-swatch wk-swatch-<?= $option ?>"></span><?= htmlspecialchars(t('palette.' . $option), ENT_QUOTES) ?><?php if ($option === $palette): ?><i class="ph ph-check wk-mi-end"></i><?php endif; ?></button>
<?php endforeach; ?>
</form>
</div>
</details>
<?php if ($isOwner): ?>
<a class="wk-tbtn" href="<?= $b ?>/admin/users" title="<?= htmlspecialchars(t('nav.admin'), ENT_QUOTES) ?>"><i class="ph ph-sliders-horizontal"></i></a>
<?php endif; ?>
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
</div>
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
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/modal.js'), ENT_QUOTES) ?>" defer></script>
<?php /* The modals built on demand — the .wk-modal markup, cloned on first use: the confirmation (assets/js/confirm.js; its text holds the defaults), an error and the busy modal (assets/js/modal.js) */ ?>
<template id="wk-modal-confirm"><dialog class="wk-modal" aria-labelledby="wk-modal-title"><header><h2 class="wk-eyebrow" id="wk-modal-title" data-modal-title><?= htmlspecialchars(t('confirm.title'), ENT_QUOTES) ?></h2><button type="button" class="wk-tbtn" data-modal-close title="<?= htmlspecialchars(t('drawer.close'), ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('drawer.close'), ENT_QUOTES) ?>"><i class="ph ph-x"></i></button></header><div class="wk-modal-main"><p data-modal-body></p></div><footer><button type="button" class="btn btn-secondary" data-modal-close><?= htmlspecialchars(t('editor.cancel'), ENT_QUOTES) ?></button><button type="button" class="btn btn-danger" data-modal-ok><?= htmlspecialchars(t('confirm.ok'), ENT_QUOTES) ?></button></footer></dialog></template>
<template id="wk-modal-alert"><dialog class="wk-modal" data-state="error" aria-labelledby="wk-modal-alert-title"><header><h2 class="wk-eyebrow" id="wk-modal-alert-title"></h2><button type="button" class="wk-tbtn" data-modal-close title="<?= htmlspecialchars(t('drawer.close'), ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('drawer.close'), ENT_QUOTES) ?>"><i class="ph ph-x"></i></button></header><div class="wk-modal-main"><p></p></div><footer><button type="button" class="btn btn-secondary" data-modal-close autofocus><?= htmlspecialchars(t('drawer.close'), ENT_QUOTES) ?></button></footer></dialog></template>
<template id="wk-modal-busy"><dialog class="wk-modal wk-modal-busy" aria-busy="true" aria-labelledby="wk-modal-busy-caption"><div class="wk-modal-main"><span class="wk-spinner" aria-hidden="true"></span><p class="wk-modal-caption" id="wk-modal-busy-caption" role="status"></p></div></dialog></template>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/confirm.js'), ENT_QUOTES) ?>" defer></script>
<?php /* Fenced code in a report/docs/protocol page — a fixed set of languages (assets/css/wiki.css's .hljs-* theme); copy-code.js fetches highlight.js only when the page has a code block */ ?>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/copy-code.js'), ENT_QUOTES) ?>" data-hljs="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/highlight.min.js'), ENT_QUOTES) ?>" defer></script>
</body>
</html>
