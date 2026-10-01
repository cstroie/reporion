<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The quick-navigation list (Http\QuickNav) — the top nav's pin menu
 * ($quickMode 'menu') and the top of the ☰ drawer ($quickMode 'drawer')
 * render this one partial, so both show the same links. Ends with the
 * Pin / Unpin toggle for the namespace on screen (POST /profile/pins).
 *
 * Variables in scope: array $quick (QuickNav::links()), string $basePath,
 * $currentUrl, $quickMode.
 */

declare(strict_types=1);

use Reporion\Auth\Pins;

/** @var array{fixed: list<array{href: string, label: string, icon: string}>, pinned: list<array{href: string, label: string, icon: string}>, related: list<array{href: string, label: string, icon: string}>, here: string, herePinned: bool} $quick */
/** @var string $basePath */
/** @var string $currentUrl */
/** @var string $quickMode */

$qb = htmlspecialchars($basePath, ENT_QUOTES);
$itemClass = $quickMode === 'menu' ? 'wk-mi' : 'wk-tree-item';
$groups = [
    'fixed' => null,
    'pinned' => t('quick.pinned'),
    'related' => t('quick.related'),
];
$canToggle = $quick['here'] !== '' && Pins::normalize($quick['here']) === $quick['here']
    && ($quick['herePinned'] || count($quick['pinned']) < Pins::MAX);
?>
<?php foreach ($groups as $group => $heading): ?>
<?php if ($quick[$group] === []) {
    continue;
} ?>
<?php if ($heading !== null): ?>
<div class="wk-quick-h"><span class="wk-eyebrow"><?= htmlspecialchars($heading, ENT_QUOTES) ?></span></div>
<?php endif; ?>
<?php foreach ($quick[$group] as $link): ?>
<a class="<?= $itemClass ?><?= $group === 'fixed' ? '' : ' wk-mono' ?>" href="<?= $qb ?><?= htmlspecialchars($link['href'], ENT_QUOTES) ?>"><i class="ph ph-<?= htmlspecialchars($link['icon'], ENT_QUOTES) ?>"></i><?= htmlspecialchars($link['label'], ENT_QUOTES) ?></a>
<?php endforeach; ?>
<?php endforeach; ?>
<?php if ($canToggle): ?>
<?php if ($quickMode === 'menu'): ?><div class="wk-mi-sep"></div><?php endif; ?>
<form action="<?= $qb ?>/profile/pins" method="post">
<input type="hidden" name="ns" value="<?= htmlspecialchars($quick['here'], ENT_QUOTES) ?>">
<input type="hidden" name="pin" value="<?= $quick['herePinned'] ? '0' : '1' ?>">
<input type="hidden" name="return_to" value="<?= htmlspecialchars($currentUrl, ENT_QUOTES) ?>">
<button type="submit" class="<?= $quickMode === 'menu' ? 'wk-mi' : 'wk-tree-item wk-quick-toggle' ?>"><i class="ph ph-push-pin<?= $quick['herePinned'] ? '-slash' : '' ?>"></i><?= htmlspecialchars(t($quick['herePinned'] ? 'quick.unpin' : 'quick.pin', [$quick['here']]), ENT_QUOTES) ?></button>
</form>
<?php endif; ?>
