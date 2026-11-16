<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The imaging guide (D62) for an anonymous visitor: the public frame of templates/layout-public.php
 * (the anonymous top bar, the reading column) around templates/guide.php's HTML in $content.
 *
 * Variables in scope: string $title, $content, $basePath, $themeBodyClass
 */

declare(strict_types=1);

/** @var string $title */
/** @var string $content */
/** @var string $basePath */
/** @var string $themeBodyClass */
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($title) ?>: <?= $e(t('app.name')) ?></title>
<?php include __DIR__ . '/partials/site-icon.php'; ?>
<?php include __DIR__ . '/partials/head-assets.php'; ?>
</head>
<body class="wk wk-read<?= $e($themeBodyClass) ?>">
<div class="wk-public">
<header class="wk-top wk-topnav wk-topnav-anon">
<a class="wk-brand" href="<?= $e($basePath) ?>/" title="<?= $e(t('nav.start')) ?>"><?php $markClass = 'wk-brand-icon'; include __DIR__ . '/partials/brand-mark.php'; ?><span class="wk-brand-text"><?= $e(t('app.name')) ?></span></a>
<span class="wk-tflex"></span>
<nav class="wk-topnav-actions" aria-label="<?= $e(t('nav.site')) ?>">
<div class="wk-topnav-more">
<?php include __DIR__ . '/partials/appearance-menu.php'; ?>
</div>
<a class="btn btn-secondary btn-sm" href="<?= $e($basePath) ?>/login"><i class="ph ph-sign-in" aria-hidden="true"></i><?= $e(t('nav.signin')) ?></a>
</nav>
</header>
<div class="wk-public-doc">
<?= $content ?>
</div>
</div>
<script src="<?= $e(\Reporion\Support\Asset::url($basePath, 'js/shell.js')) ?>" defer></script>
</body>
</html>
