<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /profile (Controller\ProfileController) — content only, in the app
 * shell. From design/mockup/WikiProfile.dc.html, what D35 keeps (no 2FA) and
 * phase 13 adds: the account's own signature details and API tokens.
 *
 * Variables in scope: Reporion\Auth\User $account; ?string $error; bool $saved;
 * ?string $notice, $signatureError, $tokenError, $newToken (shown once);
 * list<string> $scopes; int $minLength; string $basePath
 */

declare(strict_types=1);

/** @var Reporion\Auth\User $account */
/** @var ?string $error */
/** @var bool $saved */
/** @var int $minLength */
/** @var string $basePath */
/** @var ?string $notice */
/** @var ?string $signatureError */
/** @var ?string $tokenError */
/** @var ?string $newToken */
/** @var list<string> $scopes */
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$b = $e($basePath);
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
<p class="wk-dim" style="font-size:var(--text-sm);margin:var(--space-3) 0 0"><?= htmlspecialchars(t('profile.owner_edits'), ENT_QUOTES) ?></p>
</div>

<?php if ($notice !== null): ?>
<div class="wk-notice" role="status"><i class="ph ph-check"></i><div><?= $e($notice) ?></div></div>
<?php endif; ?>

<div class="wk-panel" id="signature">
<div class="wk-panel-h"><span class="wk-eyebrow"><?= $e(t('profile.signature')) ?></span></div>
<p class="wk-dim" style="font-size:var(--text-sm);margin:0 0 var(--space-3)"><?= $e(t('profile.signature_help')) ?></p>
<?php if ($signatureError !== null): ?><p role="alert"><?= $e($signatureError) ?></p><?php endif; ?>
<form class="wk-form" action="<?= $b ?>/profile/signature" method="post" style="max-width:463px">
<div class="field"><label for="display_name"><?= $e(t('profile.display_name')) ?></label><input class="input" type="text" id="display_name" name="display_name" value="<?= $e($account->displayName) ?>" maxlength="120" placeholder="<?= $e($account->username) ?>"></div>
<div class="field"><label for="title"><?= $e(t('profile.sig_title')) ?></label><input class="input" type="text" id="title" name="title" value="<?= $e($account->title) ?>" maxlength="120"></div>
<div><button class="btn btn-primary" type="submit"><?= $e(t('profile.signature_save')) ?></button></div>
</form>
</div>

<div class="wk-panel" id="tokens">
<div class="wk-panel-h"><span class="wk-eyebrow"><?= $e(t('profile.tokens')) ?></span><span class="wk-mono wk-dim" style="font-size:var(--text-sm)">Authorization: Bearer rpn_…</span></div>
<p class="wk-dim" style="font-size:var(--text-sm);margin:0 0 var(--space-3)"><?= $e(t('profile.tokens_help')) ?></p>
<?php if ($newToken !== null): ?>
<div class="wk-notice" role="status"><i class="ph ph-key"></i><div>
<b><?= $e(t('profile.token_new')) ?></b>
<div class="wk-mono" style="margin-top:var(--space-2);word-break:break-all;user-select:all" id="new-token"><?= $e($newToken) ?></div>
</div></div>
<?php endif; ?>
<?php if ($account->tokens !== []): ?>
<table class="table">
<thead><tr><th><?= $e(t('profile.token_name')) ?></th><th><?= $e(t('profile.token_scope')) ?></th><th><?= $e(t('profile.token_created')) ?></th><th><?= $e(t('profile.token_used')) ?></th><th></th></tr></thead>
<tbody>
<?php foreach ($account->tokens as $token): ?>
<tr>
<td><?= $e($token['name']) ?> <span class="wk-mono wk-dim"><?= $e($token['id']) ?></span></td>
<td><span class="tag <?= $token['scope'] === 'write' ? 'tag-accent' : 'tag-neutral' ?>"><?= $e(t('profile.scope.' . $token['scope'])) ?></span></td>
<td class="wk-dim"><?= $e(\Reporion\Support\MetaText::when($token['created'])) ?></td>
<td class="wk-dim"><?= $token['last_used'] !== null ? $e(\Reporion\Support\MetaText::date($token['last_used'], 'd M Y')) : '—' ?></td>
<td><form action="<?= $b ?>/profile/tokens/<?= $e($token['id']) ?>/revoke" method="post"><button class="btn btn-danger btn-sm" type="submit"><?= $e(t('profile.token_revoke')) ?></button></form></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php else: ?>
<p class="wk-dim" style="font-size:var(--text-sm)"><?= $e(t('profile.no_tokens')) ?></p>
<?php endif; ?>
<?php if ($tokenError !== null): ?><p role="alert"><?= $e($tokenError) ?></p><?php endif; ?>
<form class="wk-form" action="<?= $b ?>/profile/tokens" method="post" style="max-width:463px;margin-top:var(--space-4)">
<div class="field"><label for="token-name"><?= $e(t('profile.token_name')) ?></label><input class="input" type="text" id="token-name" name="name" maxlength="80" required placeholder="<?= $e(t('profile.token_name_placeholder')) ?>"></div>
<div class="field"><label for="token-scope"><?= $e(t('profile.token_scope')) ?></label><select class="input" id="token-scope" name="scope"><?php foreach ($scopes as $scope): ?><option value="<?= $e($scope) ?>"><?= $e(t('profile.scope.' . $scope)) ?></option><?php endforeach; ?></select></div>
<div><button class="btn btn-secondary" type="submit"><i class="ph ph-key"></i><?= $e(t('profile.token_create')) ?></button></div>
</form>
</div>

<div class="wk-panel">
<div class="wk-panel-h"><span class="wk-eyebrow"><?= htmlspecialchars(t('profile.password'), ENT_QUOTES) ?></span></div>
<?php if ($saved): ?>
<div class="wk-notice" role="status"><i class="ph ph-check"></i><div><?= htmlspecialchars(t('profile.saved'), ENT_QUOTES) ?></div></div>
<?php endif; ?>
<?php if ($error !== null): ?>
<p role="alert"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
<?php endif; ?>
<form class="wk-form" action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/profile/password" method="post" style="max-width:463px">
<div class="field"><label for="current"><?= htmlspecialchars(t('profile.current'), ENT_QUOTES) ?></label><input class="input" type="password" id="current" name="current" autocomplete="current-password" required></div>
<div class="field"><label for="new"><?= htmlspecialchars(t('profile.new', [$minLength]), ENT_QUOTES) ?></label><input class="input" type="password" id="new" name="new" autocomplete="new-password" minlength="<?= $minLength ?>" required></div>
<div class="field"><label for="repeat"><?= htmlspecialchars(t('profile.repeat'), ENT_QUOTES) ?></label><input class="input" type="password" id="repeat" name="repeat" autocomplete="new-password" minlength="<?= $minLength ?>" required></div>
<div><button class="btn btn-primary" type="submit"><?= htmlspecialchars(t('profile.change'), ENT_QUOTES) ?></button></div>
<p class="wk-dim" style="font-size:var(--text-sm);margin:0"><?= htmlspecialchars(t('profile.sessions_note'), ENT_QUOTES) ?></p>
</form>
</div>
</div>
