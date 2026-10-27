<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /oauth/authorize (Controller\OAuthController, D56): an MCP client (Claude) asks to read
 * this wiki as the signed-in account. Approve gives it a read-only API token, limited to the
 * namespaces typed (none: everything the account reaches), revocable on /profile. Without
 * $params the request was not a valid one and only $error is shown.
 *
 * Variables in scope: ?array $params (OAuthController::validated()); string $error;
 * list<string> $reach (the account's grant namespaces); bool $isOwner; string $basePath
 */

declare(strict_types=1);

/** @var ?array{client_id: string, client: array{name: string, redirect_uris: list<string>}, redirect_uri: string, state: string, code_challenge: string} $params */
/** @var string $error */
/** @var list<string> $reach */
/** @var bool $isOwner */
/** @var string $basePath */

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
?>
<div class="wk-doc wk-narrow">
<h2 class="wk-sec-title"><?= $e(t('oauth.title')) ?></h2>
<?php if ($error !== ''): ?>
<?php $noticeKind = 'error'; $noticeText = $error; include __DIR__ . '/partials/notice.php'; ?>
<?php endif; ?>
<?php if ($params !== null): ?>
<p><?= $e(t('oauth.intro', [$params['client']['name'] !== '' ? $params['client']['name'] : t('oauth.unnamed')])) ?></p>
<p class="wk-dim wk-text-sm"><?= $e(t('oauth.redirect')) ?> <span class="wk-mono"><?= $e((string) parse_url($params['redirect_uri'], PHP_URL_HOST)) ?></span></p>
<form class="wk-form" action="<?= $e($basePath) ?>/oauth/authorize" method="post">
<?php foreach (['client_id' => $params['client_id'], 'redirect_uri' => $params['redirect_uri'], 'state' => $params['state'], 'code_challenge' => $params['code_challenge'], 'response_type' => 'code', 'code_challenge_method' => 'S256'] as $name => $value): ?>
<input type="hidden" name="<?= $e($name) ?>" value="<?= $e($value) ?>">
<?php endforeach; ?>
<div class="field"><label for="oauth-namespaces"><?= $e(t('profile.token_namespaces')) ?>
<input class="input wk-mono" type="text" id="oauth-namespaces" name="namespaces" maxlength="1000" placeholder="<?= $e(t('profile.token_namespaces_hint')) ?>">
<small><?= $e($isOwner ? t('oauth.namespaces_help_owner') : t('oauth.namespaces_help', [implode(', ', $reach)])) ?></small></label></div>
<?php $noticeKind = 'info'; $noticeText = t('oauth.readonly'); include __DIR__ . '/partials/notice.php'; ?>
<footer>
<button class="btn btn-primary" type="submit" name="decision" value="approve"><i class="ph ph-check"></i><?= $e(t('oauth.approve')) ?></button>
<button class="btn btn-secondary" type="submit" name="decision" value="deny"><?= $e(t('oauth.deny')) ?></button>
</footer>
</form>
<?php endif; ?>
</div>
