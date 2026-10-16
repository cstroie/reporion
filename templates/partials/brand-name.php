<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The site's name for a brand block (hero, sign-in, installer): the
 * hand-written "Reporion" while the site is still called that, any other
 * name as plain text. The text is always in the markup. Variables in scope:
 * string $scriptClass
 */

declare(strict_types=1);

/** @var string $scriptClass */
if (t('app.name') === 'Reporion'): ?>
<span class="wk-sr"><?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?></span><?php include __DIR__ . '/brand-script.php'; ?>
<?php else: ?>
<?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?>
<?php endif; ?>
