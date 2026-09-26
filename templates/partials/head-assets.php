<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Every layout's stylesheets, versioned (Support\Asset) so they can be
 * cached for a year (docs/deploy-lighttpd.md). The fonts are deliberately
 * not preloaded: measured over a 4 Mbit/s link, preloading them delayed
 * the first paint by half a second (they compete with the CSS), and with
 * font-display: optional a font that arrives late is not used on that page
 * anyway — it is cached for the next one.
 *
 * Variables in scope: string $basePath
 */

declare(strict_types=1);

use Reporion\Support\Asset;

/** @var string $basePath */

foreach (['css/tokens.css', 'css/wiki.css', 'css/phosphor.css'] as $css): ?>
<link rel="stylesheet" href="<?= htmlspecialchars(Asset::url($basePath, $css), ENT_QUOTES) ?>">
<?php endforeach; ?>
