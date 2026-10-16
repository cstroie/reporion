<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The site icon from Admin → Settings, when one is set: included in every
 * layout's <head>. Variables in scope: string $basePath
 */

declare(strict_types=1);

/** @var string $basePath */

$siteIcon = reporion_instance()['icon'] ?? '';
$iconHref = $siteIcon !== ''
    ? $basePath . '/site-icon/' . $siteIcon
    : $basePath . '/assets/icon.svg';
?>
<?php if ($siteIcon === ''): ?>
<?php /* The shipped icon: the SVG for browsers that take one, an ICO and the touch icon for the rest. Versioned (Support\Asset): a browser keeps a favicon by its URL, so a changed icon needs a new one */ ?>
<link rel="icon" href="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'favicon.ico'), ENT_QUOTES) ?>" sizes="48x48">
<link rel="icon" href="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'icon.svg'), ENT_QUOTES) ?>" type="image/svg+xml" sizes="any">
<link rel="apple-touch-icon" href="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'apple-touch-icon.png'), ENT_QUOTES) ?>">
<?php else: ?>
<link rel="icon" href="<?= htmlspecialchars($iconHref, ENT_QUOTES) ?>">
<?php endif; ?>
