<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The quick-navigation list (Http\QuickNav): the top nav's 📌 menu. Ends
 * with the Pin / Unpin toggle for the namespace on screen (POST /profile/pins).
 *
 * Variables in scope: array $quick (QuickNav::links()), string $basePath,
 * $currentUrl.
 */

declare(strict_types=1);

use Reporion\Auth\Pins;

/** @var array{fixed: list<array{href: string, label: string, icon: string}>, pinned: list<array{href: string, label: string, icon: string, priority?: string}>, views: list<array{href: string, label: string, icon: string}>, related: list<array{href: string, label: string, icon: string, priority?: string}>, here: string, herePinned: bool} $quick */
/** @var string $basePath */
/** @var string $currentUrl */

$qb = htmlspecialchars($basePath, ENT_QUOTES);
$groups = [
    'fixed' => null,
    'pinned' => t('quick.pinned'),
    'views' => t('quick.views'),
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
<?php if ($group === 'pinned'): /* its own ×: a pin whose namespace is gone (moved, emptied, grant revoked) has no page left to unpin it from */ ?>
<div class="wk-quick-row">
<a class="wk-mi wk-mono"<?= ($link['priority'] ?? '') !== '' ? ' data-priority="' . htmlspecialchars($link['priority'], ENT_QUOTES) . '"' : '' ?> href="<?= $qb ?><?= htmlspecialchars($link['href'], ENT_QUOTES) ?>"><i class="ph ph-<?= htmlspecialchars($link['icon'], ENT_QUOTES) ?>"></i><?= htmlspecialchars($link['label'], ENT_QUOTES) ?></a>
<form action="<?= $qb ?>/profile/pins" method="post">
<input type="hidden" name="ns" value="<?= htmlspecialchars($link['label'], ENT_QUOTES) ?>">
<input type="hidden" name="pin" value="0">
<input type="hidden" name="return_to" value="<?= htmlspecialchars($currentUrl, ENT_QUOTES) ?>">
<button type="submit" class="wk-tbtn wk-quick-x" title="<?= htmlspecialchars(t('quick.unpin', [$link['label']]), ENT_QUOTES) ?>"><i class="ph ph-x"></i></button>
</form>
</div>
<?php elseif ($group === 'views'): /* a saved view, its own × as a pin has */ ?>
<div class="wk-quick-row">
<a class="wk-mi" href="<?= $qb ?><?= htmlspecialchars($link['href'], ENT_QUOTES) ?>"><i class="ph ph-<?= htmlspecialchars($link['icon'], ENT_QUOTES) ?>"></i><?= htmlspecialchars($link['label'], ENT_QUOTES) ?></a>
<form action="<?= $qb ?>/profile/views" method="post">
<input type="hidden" name="name" value="<?= htmlspecialchars($link['label'], ENT_QUOTES) ?>">
<input type="hidden" name="remove" value="1">
<input type="hidden" name="return_to" value="<?= htmlspecialchars($currentUrl, ENT_QUOTES) ?>">
<button type="submit" class="wk-tbtn wk-quick-x" title="<?= htmlspecialchars(t('quick.view_remove', [$link['label']]), ENT_QUOTES) ?>"><i class="ph ph-x"></i></button>
</form>
</div>
<?php else: ?>
<a class="wk-mi<?= $group === 'fixed' ? '' : ' wk-mono' ?>"<?= ($link['priority'] ?? '') !== '' ? ' data-priority="' . htmlspecialchars($link['priority'], ENT_QUOTES) . '"' : '' ?> href="<?= $qb ?><?= htmlspecialchars($link['href'], ENT_QUOTES) ?>"><i class="ph ph-<?= htmlspecialchars($link['icon'], ENT_QUOTES) ?>"></i><?= htmlspecialchars($link['label'], ENT_QUOTES) ?></a>
<?php endif; ?>
<?php endforeach; ?>
<?php endforeach; ?>
<?php if ($canToggle): ?>
<div class="wk-mi-sep"></div>
<form action="<?= $qb ?>/profile/pins" method="post">
<input type="hidden" name="ns" value="<?= htmlspecialchars($quick['here'], ENT_QUOTES) ?>">
<input type="hidden" name="pin" value="<?= $quick['herePinned'] ? '0' : '1' ?>">
<input type="hidden" name="return_to" value="<?= htmlspecialchars($currentUrl, ENT_QUOTES) ?>">
<button type="submit" class="wk-mi"><i class="ph ph-push-pin<?= $quick['herePinned'] ? '-slash' : '' ?>"></i><?= htmlspecialchars(t($quick['herePinned'] ? 'quick.unpin' : 'quick.pin', [$quick['here']]), ENT_QUOTES) ?></button>
</form>
<?php endif; ?>
