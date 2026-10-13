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
 * list<string> $scopes; int $minLength; string $basePath; list<array{…}> $access (AccessSummary::rows(),
 * what counts only)
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
/** @var list<array{namespace: string, role: string, source: string, dropped: bool}> $access */
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$b = $e($basePath);
?>
<div class="wk-doc">
<div class="wk-doc-head">
<?= \Reporion\Http\Breadcrumb::render([['label' => t('profile.title'), 'icon' => 'user-circle']]) ?>
<h1 class="wk-doc-title"><?= $e($account->signatureName()) ?></h1>
<div class="wk-badges">
<span class="wk-mono wk-dim"><?= $e($account->username) ?><?= $account->title !== '' ? ' · ' . $e($account->title) : '' ?></span>
<?php if ($account->isOwner): ?><span class="tag tag-accent"><i class="ph ph-crown-simple" aria-hidden="true"></i> <?= $e(t('admin.users.owner')) ?></span><?php endif; ?>
<?php if ($account->isRadiologist()): ?><span class="tag tag-signed"><i class="ph ph-radioactive" aria-hidden="true"></i> <?= $e(t('admin.users.radiologist_tag')) ?></span><?php elseif (!$account->isOwner): ?><span class="tag tag-outline"><?= $e(t('admin.users.writer_tag')) ?></span><?php endif; ?>
<?php foreach ($account->groups as $g): ?><span class="tag tag-neutral wk-mono">@<?= $e($g) ?></span><?php endforeach; ?>
</div>
</div>

<div class="wk-panel" id="access">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('profile.access')) ?></h2><p class="wk-dim"><?= $e(t('profile.owner_edits')) ?></p></hgroup></header>
<div class="wk-kv wk-mb-3">
<span><?= $e(t('profile.signs_as')) ?></span><b><?= $e($account->signatureName()) ?><?= $account->title !== '' ? ' · ' . $e($account->title) : '' ?></b>
<span><?= $e(t('profile.may_sign')) ?></span><b><?= $e(t($account->isRadiologist() ? 'profile.may_sign_yes' : 'profile.may_sign_no')) ?></b>
<span><?= $e(t('profile.reports')) ?></span><b><?= $e(t($account->isRadiologist() ? 'profile.reports_yes' : ($account->isOwner ? 'profile.reports_read' : 'profile.reports_no'))) ?></b>
</div>
<?php if ($account->isOwner): ?>
<p class="wk-dim wk-text-sm wk-m0"><?= $e(t('profile.reach_owner')) ?></p>
<?php elseif ($access !== []): ?>
<table class="table">
<thead><tr><th><?= $e(t('admin.users.col_ns')) ?></th><th><?= $e(t('admin.users.col_role')) ?></th><th><?= $e(t('admin.users.col_via')) ?></th></tr></thead>
<tbody>
<?php foreach ($access as $row): ?>
<tr>
<td class="wk-mono"><a href="<?= $b ?>/<?= $e($row['namespace']) ?>:"><?= $e($row['namespace']) ?></a></td>
<td><span class="tag <?= $row['role'] === 'editor' ? 'tag-accent' : 'tag-neutral' ?>"><?= $e(t('admin.users.role.' . $row['role'])) ?></span></td>
<td class="wk-mono wk-dim"><?= $e(str_starts_with($row['source'], '@') ? $row['source'] : t('admin.users.via.' . $row['source'])) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>

<?php if ($notice !== null): ?>
<div class="wk-notice" role="status"><i class="ph ph-check"></i><div><?= $e($notice) ?></div></div>
<?php endif; ?>

<div class="wk-two wk-panels">
<div class="wk-panel" id="signature">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('profile.signature')) ?></h2><p class="wk-dim"><?= $e(t('profile.signature_help')) ?></p></hgroup></header>
<?php if ($signatureError !== null): ?><p role="alert"><?= $e($signatureError) ?></p><?php endif; ?>
<form class="wk-form" action="<?= $b ?>/profile/signature" method="post">
<div class="field"><label for="display_name"><?= $e(t('profile.display_name')) ?></label><input class="input" type="text" id="display_name" name="display_name" value="<?= $e($account->displayName) ?>" maxlength="120" placeholder="<?= $e($account->username) ?>"></div>
<div class="field"><label for="title"><?= $e(t('profile.sig_title')) ?></label><input class="input" type="text" id="title" name="title" value="<?= $e($account->title) ?>" maxlength="120"></div>
<footer><button class="btn btn-primary" type="submit"><i class="ph ph-check"></i><?= $e(t('profile.signature_save')) ?></button></footer>
</form>
</div>

<div class="wk-panel">
<header class="wk-panel-h"><h2 class="wk-eyebrow"><?= htmlspecialchars(t('profile.password'), ENT_QUOTES) ?></h2></header>
<?php if ($saved): ?>
<div class="wk-notice" role="status"><i class="ph ph-check"></i><div><?= htmlspecialchars(t('profile.saved'), ENT_QUOTES) ?></div></div>
<?php endif; ?>
<form class="wk-form" action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/profile/password" method="post">
<div class="field"><label for="current"><?= htmlspecialchars(t('profile.current'), ENT_QUOTES) ?></label><input class="input" type="password" id="current" name="current" autocomplete="current-password" required<?= ($errorField ?? null) === 'current' ? ' aria-invalid="true" aria-describedby="pw-error"' : '' ?>><?php if (($errorField ?? null) === 'current' && $error !== null): ?><small id="pw-error" class="wk-field-error"><?= htmlspecialchars($error, ENT_QUOTES) ?></small><?php endif; ?></div>
<div class="field"><label for="new"><?= htmlspecialchars(t('profile.new', [$minLength]), ENT_QUOTES) ?></label><input class="input" type="password" id="new" name="new" autocomplete="new-password" minlength="<?= $minLength ?>" required<?= ($errorField ?? null) === 'new' ? ' aria-invalid="true" aria-describedby="pw-error"' : '' ?>><?php if (($errorField ?? null) === 'new' && $error !== null): ?><small id="pw-error" class="wk-field-error"><?= htmlspecialchars($error, ENT_QUOTES) ?></small><?php endif; ?></div>
<div class="field"><label for="repeat"><?= htmlspecialchars(t('profile.repeat'), ENT_QUOTES) ?></label><input class="input" type="password" id="repeat" name="repeat" autocomplete="new-password" minlength="<?= $minLength ?>" required<?= ($errorField ?? null) === 'repeat' ? ' aria-invalid="true" aria-describedby="pw-error"' : '' ?>><?php if (($errorField ?? null) === 'repeat' && $error !== null): ?><small id="pw-error" class="wk-field-error"><?= htmlspecialchars($error, ENT_QUOTES) ?></small><?php endif; ?></div>
<footer><p><?= htmlspecialchars(t('profile.sessions_note'), ENT_QUOTES) ?></p><button class="btn btn-primary" type="submit"><i class="ph ph-lock-key"></i><?= htmlspecialchars(t('profile.change'), ENT_QUOTES) ?></button></footer>
</form>
</div>
</div>

<div class="wk-panel" id="tokens">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('profile.tokens')) ?></h2><p class="wk-dim"><?= $e(t('profile.tokens_help')) ?></p></hgroup><span class="wk-mono wk-dim wk-text-sm">Authorization: Bearer rpn_…</span></header>
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
<td class="wk-dim"><?= $token['last_used'] !== null ? $e(\Reporion\Support\MetaText::date($token['last_used'], \Reporion\Support\MetaText::DATE)) : '—' ?></td>
<td><form action="<?= $b ?>/profile/tokens/<?= $e($token['id']) ?>/revoke" method="post" data-confirm="<?= $e(t('profile.token_revoke_prompt')) ?>" data-confirm-label="<?= $e(t('profile.token_revoke')) ?>"><button class="btn btn-secondary btn-danger btn-sm" type="submit"><i class="ph ph-prohibit" aria-hidden="true"></i><?= $e(t('profile.token_revoke')) ?></button></form></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php else: ?>
<p class="wk-dim wk-text-sm"><?= $e(t('profile.no_tokens')) ?></p>
<?php endif; ?>
<form class="wk-form" action="<?= $b ?>/profile/tokens" method="post" style="margin-top:var(--space-4)">
<div class="wk-form-grid wk-mt-flush">
<div class="field"><label for="token-name"><?= $e(t('profile.token_name')) ?></label><input class="input" type="text" id="token-name" name="name" maxlength="80" required placeholder="<?= $e(t('profile.token_name_placeholder')) ?>"<?= $tokenError !== null ? ' aria-invalid="true" aria-describedby="token-error"' : '' ?>><?php if ($tokenError !== null): ?><small id="token-error" class="wk-field-error"><?= $e($tokenError) ?></small><?php endif; ?></div>
<div class="field"><label for="token-scope"><?= $e(t('profile.token_scope')) ?></label><select class="input" id="token-scope" name="scope"><?php foreach ($scopes as $scope): ?><option value="<?= $e($scope) ?>"><?= $e(t('profile.scope.' . $scope)) ?></option><?php endforeach; ?></select></div>
</div>
<footer><button class="btn btn-secondary" type="submit"><i class="ph ph-key"></i><?= $e(t('profile.token_create')) ?></button></footer>
</form>
</div>

</div>
