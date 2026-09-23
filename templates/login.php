<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET/POST /login (docs/architecture-api.md §2): "a form. Nothing else."
 * D13: one owner account, no 2FA, no username field.
 *
 * Variables in scope: bool $error
 */

declare(strict_types=1);

/** @var bool $error */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(t('nav.signin'), ENT_QUOTES) ?> — <?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?></title>
<link rel="stylesheet" href="/assets/css/tokens.css">
</head>
<body>
<main>
<form action="/login" method="post">
<h1><?= htmlspecialchars(t('nav.signin'), ENT_QUOTES) ?></h1>
<?php if ($error): ?>
<p role="alert"><?= htmlspecialchars(t('auth.invalid'), ENT_QUOTES) ?></p>
<?php endif; ?>
<label for="password"><?= htmlspecialchars(t('auth.password'), ENT_QUOTES) ?></label>
<input type="password" id="password" name="password" autocomplete="current-password" required>
<button type="submit"><?= htmlspecialchars(t('nav.signin'), ENT_QUOTES) ?></button>
</form>
</main>
</body>
</html>
