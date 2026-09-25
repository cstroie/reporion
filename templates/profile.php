<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /profile (Controller\ProfileController) — content only, in the app
 * shell. From design/mockup/WikiProfile.dc.html, only what D35 keeps: no
 * 2FA, no API tokens, no self-service profile editing (an owner sets
 * signature details in /admin/users).
 *
 * Variables in scope: Reporion\Auth\User $account; ?string $error; bool $saved;
 * int $minLength; string $basePath
 */

declare(strict_types=1);

/** @var Reporion\Auth\User $account */
/** @var ?string $error */
/** @var bool $saved */
/** @var int $minLength */
/** @var string $basePath */
?>
<div class="wk-doc">
<div class="wk-doc-titlerow"><h1 class="wk-doc-title"><?= htmlspecialchars(t('profile.title'), ENT_QUOTES) ?></h1></div>

<div class="wk-panel">
<div class="wk-panel-h"><span class="wk-eyebrow"><?= htmlspecialchars(t('profile.account'), ENT_QUOTES) ?></span></div>
<div class="wk-kv">
<span><?= htmlspecialchars(t('auth.username'), ENT_QUOTES) ?></span><b class="wk-mono"><?= htmlspecialchars($account->username, ENT_QUOTES) ?></b>
<span><?= htmlspecialchars(t('profile.access'), ENT_QUOTES) ?></span><b>
<?php if ($account->isOwner): ?>
<span class="tag tag-accent"><?= htmlspecialchars(t('admin.users.owner'), ENT_QUOTES) ?></span>
<?php elseif ($account->grants === []): ?>
—
<?php else: ?>
<?php foreach ($account->grants as $grant): ?><span class="tag tag-neutral"><?= htmlspecialchars($grant->namespace . ':' . $grant->role->value, ENT_QUOTES) ?></span> <?php endforeach; ?>
<?php endif; ?>
</b>
<span><?= htmlspecialchars(t('profile.signs_as'), ENT_QUOTES) ?></span><b><?= htmlspecialchars($account->signatureName(), ENT_QUOTES) ?><?= $account->title !== '' ? ' · ' . htmlspecialchars($account->title, ENT_QUOTES) : '' ?></b>
</div>
<p class="wk-dim" style="font-size:12px;margin:var(--space-3) 0 0"><?= htmlspecialchars(t('profile.owner_edits'), ENT_QUOTES) ?></p>
</div>

<div class="wk-panel">
<div class="wk-panel-h"><span class="wk-eyebrow"><?= htmlspecialchars(t('profile.password'), ENT_QUOTES) ?></span></div>
<?php if ($saved): ?>
<div class="wk-notice" role="status"><i class="ph ph-check"></i><div><?= htmlspecialchars(t('profile.saved'), ENT_QUOTES) ?></div></div>
<?php endif; ?>
<?php if ($error !== null): ?>
<p role="alert"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
<?php endif; ?>
<form class="wk-form" action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/profile/password" method="post" style="max-width:360px">
<div class="field"><label for="current"><?= htmlspecialchars(t('profile.current'), ENT_QUOTES) ?></label><input class="input" type="password" id="current" name="current" autocomplete="current-password" required></div>
<div class="field"><label for="new"><?= htmlspecialchars(t('profile.new', [$minLength]), ENT_QUOTES) ?></label><input class="input" type="password" id="new" name="new" autocomplete="new-password" minlength="<?= $minLength ?>" required></div>
<div class="field"><label for="repeat"><?= htmlspecialchars(t('profile.repeat'), ENT_QUOTES) ?></label><input class="input" type="password" id="repeat" name="repeat" autocomplete="new-password" minlength="<?= $minLength ?>" required></div>
<div><button class="btn btn-primary" type="submit"><?= htmlspecialchars(t('profile.change'), ENT_QUOTES) ?></button></div>
<p class="wk-dim" style="font-size:12px;margin:0"><?= htmlspecialchars(t('profile.sessions_note'), ENT_QUOTES) ?></p>
</form>
</div>
</div>
