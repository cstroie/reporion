<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * A message in a card with an icon: the mockup's .wk-notice, coloured by what it means.
 * Include it with the variables below; they are unset afterwards.
 *
 * Variables in scope: string $noticeKind (info | ok | warn | error: error and warn are
 * announced as alerts, the others as status); string $noticeText (plain text, escaped here);
 * optional ?array{0: string, 1: string} $noticeLink (href, label); optional list<string>
 * $noticeItems (a list under the text, each escaped); optional string $noticeId (the card's id,
 * for a script that removes or moves it)
 */

declare(strict_types=1);

/** @var string $noticeKind */
/** @var string $noticeText */
/** @var ?array{0: string, 1: string} $noticeLink */
/** @var list<string> $noticeItems */
/** @var string $noticeId */

$noticeIcon = ['info' => 'info', 'ok' => 'check-circle', 'warn' => 'warning', 'error' => 'warning-octagon'][$noticeKind] ?? 'info';
$noticeRole = $noticeKind === 'warn' || $noticeKind === 'error' ? 'alert' : 'status';
$noticeItems ??= [];
$noticeLink ??= null;
$noticeId ??= '';
?>
<div class="wk-notice wk-notice-<?= htmlspecialchars($noticeKind, ENT_QUOTES) ?><?= $noticeItems !== [] ? ' wk-notice-list' : '' ?>" role="<?= $noticeRole ?>"<?= $noticeId !== '' ? ' id="' . htmlspecialchars($noticeId, ENT_QUOTES) . '"' : '' ?>><i class="ph ph-<?= $noticeIcon ?>" aria-hidden="true"></i><div>
<?php if ($noticeItems !== []): ?><p><?= htmlspecialchars($noticeText, ENT_QUOTES) ?></p>
<ul>
<?php foreach ($noticeItems as $noticeItem): ?><li><?= htmlspecialchars($noticeItem, ENT_QUOTES) ?></li>
<?php endforeach; ?></ul>
<?php else: ?><?= htmlspecialchars($noticeText, ENT_QUOTES) ?><?php if ($noticeLink !== null): ?> <a href="<?= htmlspecialchars($noticeLink[0], ENT_QUOTES) ?>"><?= htmlspecialchars($noticeLink[1], ENT_QUOTES) ?></a><?php endif; ?>
<?php endif; ?></div></div>
<?php unset($noticeKind, $noticeText, $noticeLink, $noticeItems, $noticeId, $noticeIcon, $noticeRole, $noticeItem); ?>
