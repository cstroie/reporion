<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The top nav's appearance menu, shared by templates/layout.php and the anonymous
 * bar of templates/layout-public.php.
 *
 * Variables in scope: string $basePath, $theme, $palette, $currentUrl
 */

declare(strict_types=1);

use Reporion\Http\Theme;

/** @var string $basePath */
/** @var string $theme */
/** @var string $palette */
/** @var string $currentUrl */

$b = htmlspecialchars($basePath, ENT_QUOTES);
?>
<?php /* Appearance: light/dark (POST /theme), then the colour palette (POST /palette): two forms, one menu; assets/js/shell.js applies a choice in place so the menu stays open to try another */ ?>
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
