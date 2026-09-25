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
<div class="wk-doc">
<div class="wk-doc-head">
<div class="wk-crumbs wk-mono"><b><?= htmlspecialchars(t('nav.admin'), ENT_QUOTES) ?></b><span>›</span><span><?= htmlspecialchars(t('admin.users.title'), ENT_QUOTES) ?></span></div>
<h1 class="wk-doc-title"><?= htmlspecialchars(t('admin.users.title'), ENT_QUOTES) ?></h1>
</div>
<?php $adminTab = 'users'; include __DIR__ . '/admin-tabs.php'; ?>

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
<td>
<div class="wk-mono"><?= htmlspecialchars($account->username, ENT_QUOTES) ?></div>
<?php if ($account->displayName !== '' || $account->title !== ''): ?>
<div class="wk-dim" style="font-size:12px"><?= htmlspecialchars(trim($account->displayName . ($account->title !== '' ? ' · ' . $account->title : ''), ' ·'), ENT_QUOTES) ?></div>
<?php endif; ?>
<details class="wk-profile">
<summary class="wk-dim"><?= htmlspecialchars(t('admin.users.edit_profile'), ENT_QUOTES) ?></summary>
<form action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/admin/users/<?= htmlspecialchars(rawurlencode($account->username), ENT_QUOTES) ?>/profile" method="post" class="wk-form">
<input class="input" type="text" name="display_name" value="<?= htmlspecialchars($account->displayName, ENT_QUOTES) ?>" placeholder="<?= htmlspecialchars(t('admin.users.display_name'), ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('admin.users.display_name'), ENT_QUOTES) ?>">
<input class="input" type="text" name="title" value="<?= htmlspecialchars($account->title, ENT_QUOTES) ?>" placeholder="<?= htmlspecialchars(t('admin.users.title_field'), ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('admin.users.title_field'), ENT_QUOTES) ?>">
<button class="btn btn-secondary btn-sm" type="submit"><?= htmlspecialchars(t('admin.users.save_profile'), ENT_QUOTES) ?></button>
</form>
<form action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/admin/users/<?= htmlspecialchars(rawurlencode($account->username), ENT_QUOTES) ?>/password" method="post" class="wk-form">
<input class="input" type="password" name="new" autocomplete="new-password" placeholder="<?= htmlspecialchars(t('admin.users.new_password'), ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('admin.users.new_password'), ENT_QUOTES) ?>">
<input class="input" type="password" name="repeat" autocomplete="new-password" placeholder="<?= htmlspecialchars(t('profile.repeat'), ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('profile.repeat'), ENT_QUOTES) ?>">
<button class="btn btn-secondary btn-sm" type="submit"><?= htmlspecialchars(t('admin.users.set_password'), ENT_QUOTES) ?></button>
</form>
</details>
</td>
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
<label for="display_name"><?= htmlspecialchars(t('admin.users.display_name'), ENT_QUOTES) ?></label>
<input class="input" type="text" id="display_name" name="display_name" autocomplete="off">
</div>
<div class="field">
<label for="title"><?= htmlspecialchars(t('admin.users.title_field'), ENT_QUOTES) ?></label>
<input class="input" type="text" id="title" name="title" autocomplete="off">
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
