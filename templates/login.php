<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET/POST /login (docs/architecture-api.md §2): "a form. Nothing else."
 * D35: multiple accounts, username + password, still no 2FA. Adapted from
 * design/mockup/WikiAuth.dc.html — that mockup also shows an
 * authenticator-code field and an SSO/guest button; those two stay
 * dropped, not omitted by oversight (see docs/BUILD_LOG.md). The username
 * field, dropped in the earlier single-owner port, is back.
 *
 * Variables in scope: bool $error, string $basePath
 */

declare(strict_types=1);

/** @var bool $error */
/** @var string $basePath */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(t('nav.signin'), ENT_QUOTES) ?> — <?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?></title>
<?php include __DIR__ . '/partials/site-icon.php'; ?>
<?php include __DIR__ . '/partials/head-assets.php'; ?>
</head>
<body class="wk">
<div class="wk-auth">
<div class="wk-auth-brand">
<div class="wk-auth-mark"><?= htmlspecialchars(mb_substr(t('app.name'), 0, 1), ENT_QUOTES) ?></div>
<h1 class="wk-auth-h"><?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?></h1>
<p class="wk-auth-p"><?= htmlspecialchars(t('auth.tagline'), ENT_QUOTES) ?></p>
</div>
<div class="wk-auth-form">
<form class="card elev-md wk-auth-card" action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/login" method="post">
<span class="card-kicker"><?= htmlspecialchars(t('nav.signin'), ENT_QUOTES) ?></span>
<?php if ($error): ?>
<p role="alert"><?= htmlspecialchars(t('auth.invalid'), ENT_QUOTES) ?></p>
<?php endif; ?>
<div class="field">
<label for="username"><?= htmlspecialchars(t('auth.username'), ENT_QUOTES) ?></label>
<input class="input" type="text" id="username" name="username" autocomplete="username" required autofocus>
</div>
<div class="field">
<label for="password"><?= htmlspecialchars(t('auth.password'), ENT_QUOTES) ?></label>
<input class="input" type="password" id="password" name="password" autocomplete="current-password" required>
</div>
<button class="btn btn-primary btn-block" type="submit"><?= htmlspecialchars(t('auth.submit'), ENT_QUOTES) ?></button>
</form>
</div>
</div>
</body>
</html>
