<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET/POST /admin/users (Controller\AdminUsersController) — owner-only
 * account management. Structure/classes ported from the "Users & groups"
 * panel in design/mockup/WikiAdmin.dc.html (.wk-panel / .wk-panel-h /
 * table.table) — the "groups" column and the mockup's `@radiology:rw`
 * ACL-string column are replaced with real per-namespace grants, and the
 * "2FA" / "last seen" columns are dropped (no 2FA, D35; no login-tracking
 * yet). The mockup's "Invite" action is dropped too: D35 has no
 * self-service registration, only admin-created accounts.
 *
 * Variables in scope: list<Reporion\Auth\User> $accounts, ?string $error,
 * string $oldUsername, string $oldGrants, string $basePath
 */

declare(strict_types=1);

use Reporion\Auth\User;

/** @var list<User> $accounts */
/** @var ?string $error */
/** @var string $oldUsername */
/** @var string $oldGrants */
/** @var string $basePath */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(t('admin.users.title'), ENT_QUOTES) ?> — <?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?></title>
<link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/css/tokens.css">
<link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/css/wiki.css">
</head>
<body class="wk">
<div class="wk-top">
<span class="wk-brand"><?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?></span>
<form class="wk-search" action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/search" method="get">
<input type="search" name="q" placeholder="<?= htmlspecialchars(t('nav.search'), ENT_QUOTES) ?>">
</form>
</div>
<main class="wk-pad">
<div class="wk-doc">
<div class="wk-doc-head">
<div class="wk-crumbs wk-mono"><b><?= htmlspecialchars(t('nav.admin'), ENT_QUOTES) ?></b><span>›</span><span><?= htmlspecialchars(t('admin.users.title'), ENT_QUOTES) ?></span></div>
<h1 class="wk-doc-title"><?= htmlspecialchars(t('admin.users.title'), ENT_QUOTES) ?></h1>
</div>

<?php if ($error !== null): ?>
<p role="alert"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
<?php endif; ?>

<div class="wk-panel">
<div class="wk-panel-h"><span class="wk-eyebrow"><?= htmlspecialchars(t('admin.users.title'), ENT_QUOTES) ?></span></div>
<table class="table">
<thead><tr>
<th><?= htmlspecialchars(t('admin.users.col_user'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('admin.users.col_grants'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('admin.users.col_status'), ENT_QUOTES) ?></th>
<th></th>
</tr></thead>
<tbody>
<?php foreach ($accounts as $account): ?>
<tr>
<td class="wk-mono"><?= htmlspecialchars($account->username, ENT_QUOTES) ?></td>
<td class="wk-mono wk-dim">
<?php if ($account->isOwner): ?>
<span class="tag tag-accent"><?= htmlspecialchars(t('admin.users.owner'), ENT_QUOTES) ?></span>
<?php elseif ($account->grants === []): ?>
—
<?php else: ?>
<?php foreach ($account->grants as $grant): ?>
<span class="tag tag-neutral"><?= htmlspecialchars($grant->namespace, ENT_QUOTES) ?>:<?= htmlspecialchars($grant->role->value, ENT_QUOTES) ?></span>
<?php endforeach; ?>
<?php endif; ?>
</td>
<td>
<?php if ($account->active): ?>
<span class="tag tag-outline"><?= htmlspecialchars(t('admin.users.active'), ENT_QUOTES) ?></span>
<?php else: ?>
<span class="tag tag-neutral"><?= htmlspecialchars(t('admin.users.inactive'), ENT_QUOTES) ?></span>
<?php endif; ?>
</td>
<td>
<?php if ($account->active): ?>
<form action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/admin/users/<?= htmlspecialchars(rawurlencode($account->username), ENT_QUOTES) ?>/deactivate" method="post">
<button class="btn btn-secondary btn-sm" type="submit"><?= htmlspecialchars(t('admin.users.deactivate'), ENT_QUOTES) ?></button>
</form>
<?php else: ?>
<form action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/admin/users/<?= htmlspecialchars(rawurlencode($account->username), ENT_QUOTES) ?>/reactivate" method="post">
<button class="btn btn-secondary btn-sm" type="submit"><?= htmlspecialchars(t('admin.users.reactivate'), ENT_QUOTES) ?></button>
</form>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="wk-panel">
<div class="wk-panel-h"><span class="wk-eyebrow"><?= htmlspecialchars(t('admin.users.create_title'), ENT_QUOTES) ?></span></div>
<form class="card" action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/admin/users" method="post">
<div class="field">
<label for="username"><?= htmlspecialchars(t('auth.username'), ENT_QUOTES) ?></label>
<input class="input" type="text" id="username" name="username" value="<?= htmlspecialchars($oldUsername, ENT_QUOTES) ?>" autocomplete="off" required>
</div>
<div class="field">
<label for="password"><?= htmlspecialchars(t('auth.password'), ENT_QUOTES) ?></label>
<input class="input" type="password" id="password" name="password" autocomplete="new-password" required>
</div>
<div class="field">
<label><input type="checkbox" name="owner"> <?= htmlspecialchars(t('admin.users.make_owner'), ENT_QUOTES) ?></label>
</div>
<div class="field">
<label for="grants"><?= htmlspecialchars(t('admin.users.grants_label'), ENT_QUOTES) ?></label>
<textarea class="input wk-mono" id="grants" name="grants" rows="3" placeholder="reports:mri:editor"><?= htmlspecialchars($oldGrants, ENT_QUOTES) ?></textarea>
</div>
<button class="btn btn-primary" type="submit"><?= htmlspecialchars(t('admin.users.create_submit'), ENT_QUOTES) ?></button>
</form>
</div>
</div>
</main>
</body>
</html>
