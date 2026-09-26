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
 *
 * Every link and form action carries $basePath: the live instance is
 * served under a sub-path.
 *
 * Variables in scope: string $pageTitle, $content, $basePath, $username,
 * $nsHref, $drawerNs, $theme, $palette, $themeBodyClass, $currentUrl;
 * bool $isOwner, $canCreate; list $drawerSubnamespaces, $drawerRows;
 * optional ?string $searchTerm and the page-header vars ($headerPath, …).
 */

declare(strict_types=1);

use Reporion\Http\Theme;

/** @var string $pageTitle */
/** @var string $content */
/** @var string $basePath */
/** @var string $username */
/** @var bool $isOwner */
/** @var bool $canCreate */
/** @var string $nsHref */
/** @var string $theme */
/** @var string $palette */
/** @var string $themeBodyClass */
/** @var string $currentUrl */

$b = htmlspecialchars($basePath, ENT_QUOTES);
$searchPlaceholder = isset($headerPath) ? $headerPath : t('nav.search');
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
<body class="wk wk-read<?= htmlspecialchars($themeBodyClass, ENT_QUOTES) ?>">
<header class="wk-top wk-topnav">
<a class="wk-tbtn" href="<?= $b ?><?= htmlspecialchars($nsHref, ENT_QUOTES) ?>" data-drawer-open aria-controls="wk-drawer" title="<?= htmlspecialchars(t('nav.namespaces'), ENT_QUOTES) ?>"><i class="ph ph-list"></i></a>
<a class="wk-brand" href="<?= $b ?>/"><?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?></a>
<form class="wk-search" action="<?= $b ?>/search" method="get" role="search" data-island="palette" data-config-id="palette-config">
<i class="ph ph-magnifying-glass"></i>
<input type="search" name="q" value="<?= htmlspecialchars($searchTerm ?? '', ENT_QUOTES) ?>" placeholder="<?= htmlspecialchars($searchPlaceholder, ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('search.title'), ENT_QUOTES) ?>">
<span class="wk-kbd" hidden>⌘K</span>
</form>
<script type="application/json" id="palette-config"><?= json_encode(['basePath' => $basePath], JSON_HEX_TAG) ?></script>
<nav class="wk-topnav-actions" aria-label="<?= htmlspecialchars(t('nav.site'), ENT_QUOTES) ?>">
<?php if ($canCreate): ?>
<a class="btn btn-secondary btn-sm" href="<?= $b ?>/new"><i class="ph ph-plus"></i><span class="wk-btn-label"><?= htmlspecialchars(t('nav.new'), ENT_QUOTES) ?></span></a>
<?php endif; ?>
<a class="wk-tbtn" href="<?= $b ?><?= htmlspecialchars($nsHref, ENT_QUOTES) ?>" title="<?= htmlspecialchars(t('nav.ns_index'), ENT_QUOTES) ?>"><i class="ph ph-folder-open"></i></a>
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
<summary class="wk-who" title="<?= htmlspecialchars(t('nav.account'), ENT_QUOTES) ?>"><span class="wk-av"><?= htmlspecialchars(mb_strtoupper(mb_substr($username, 0, 2)), ENT_QUOTES) ?></span></summary>
<div class="wk-menu wk-menu-r">
<div class="wk-mi wk-mi-static"><i class="ph ph-user-circle"></i><?= htmlspecialchars(t('nav.signed_in_as', [$username]), ENT_QUOTES) ?></div>
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
<main class="wk-panes" data-pad="read">
<div class="wk-panebox">
<?php if (isset($headerPath)) {
    include __DIR__ . '/page-header.php';
} ?>
<?= $content ?>
</div>
</main>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/palette.js'), ENT_QUOTES) ?>" defer></script>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/shell.js'), ENT_QUOTES) ?>" defer></script>
</body>
</html>
