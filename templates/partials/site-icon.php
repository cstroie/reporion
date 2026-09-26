<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The site icon from Admin → Settings, when one is set — included in every
 * layout's <head>. Variables in scope: string $basePath
 */

declare(strict_types=1);

/** @var string $basePath */

$siteIcon = reporion_instance()['icon'] ?? '';
if ($siteIcon !== ''): ?>
<link rel="icon" href="<?= htmlspecialchars($basePath . '/site-icon/' . $siteIcon, ENT_QUOTES) ?>">
<?php endif; ?>
