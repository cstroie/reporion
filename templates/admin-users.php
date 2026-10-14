<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /admin/users (Controller\AdminUsersController::index): owner-only. The accounts, each
 * with what it is (owner, radiologist, inactive), its groups and its own grants, opening onto
 * its own page (templates/admin-user.php); then the create form. Ported from the "Users &
 * groups" panel of design/mockup/WikiAdmin.dc.html: no 2FA or "last seen" (D35), no "Invite"
 * (admin-created accounts only).
 *
 * Variables in scope: list<Reporion\Auth\User> $accounts; list<Reporion\Auth\Group> $groups;
 * ?string $error; array<string, mixed> $old (the create form as posted); string $basePath
 */

declare(strict_types=1);

use Reporion\Auth\Group;
use Reporion\Auth\User;

/** @var list<User> $accounts */
/** @var list<Group> $groups */
/** @var ?string $error */
/** @var array<string, mixed> $old */
/** @var string $basePath */

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$b = $e($basePath);
$oldStr = static fn (string $k): string => \is_string($old[$k] ?? null) ? $old[$k] : '';
$oldGroups = array_values(array_filter((array) ($old['groups'] ?? []), 'is_string'));
$groupNames = array_map(static fn (Group $g): string => $g->name, $groups);
$radiologists = \count(array_filter($accounts, static fn (User $u): bool => $u->active && $u->isRadiologist()));
$active = \count(array_filter($accounts, static fn (User $u): bool => $u->active));
?>
<div class="wk-doc">
<div class="wk-doc-head">
<?= \Reporion\Http\Breadcrumb::render([['label' => t('nav.admin')], ['label' => t('admin.users.title')]]) ?>
<h1 class="wk-doc-title"><?= $e(t('admin.users.title')) ?></h1>
</div>
<?php include __DIR__ . '/admin-tabs.php'; ?>

<?php if ($error !== null): ?>
<div class="wk-notice wk-mb-3 wk-notice-error" role="alert"><i class="ph ph-warning-octagon"></i><div><?= $e($error) ?></div></div>
<?php endif; ?>

<div class="wk-panel" id="accounts">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.users.accounts')) ?></h2><p class="wk-dim"><?= $e(t('admin.users.accounts_help')) ?></p></hgroup><span class="wk-mono wk-dim wk-text-sm"><?= $e(t('admin.users.counts', [$active, $radiologists])) ?></span></header>
<table class="table table-cards wk-users">
<thead><tr>
<th><?= $e(t('admin.users.col_user')) ?></th>
<th><?= $e(t('admin.users.col_kind')) ?></th>
<th><?= $e(t('admin.users.col_groups')) ?></th>
<th><?= $e(t('admin.users.col_grants')) ?></th>
<th></th>
</tr></thead>
<tbody>
<?php foreach ($accounts as $account): $href = $b . '/admin/users/' . $e(rawurlencode($account->username)); ?>
<tr<?= $account->active ? '' : ' class="wk-dim"' ?>>
<td class="wk-card-head">
<a href="<?= $href ?>"><b><?= $e($account->displayName !== '' ? $account->displayName : $account->username) ?></b></a>
<div class="wk-mono wk-dim wk-text-sm"><?= $e($account->username) ?><?= $account->title !== '' ? ' · ' . $e($account->title) : '' ?></div>
</td>
<td data-label="<?= $e(t('admin.users.col_kind')) ?>"><span class="wk-badges">
<?php if ($account->isOwner): ?><span class="tag tag-accent" title="<?= $e(t('admin.users.owner')) ?>"><i class="ph ph-crown-simple" aria-hidden="true"></i><span class="tag-word"> <?= $e(t('admin.users.owner')) ?></span></span><?php endif; ?>
<?php if ($account->isRadiologist()): ?><span class="tag tag-signed" title="<?= $e($account->radiologist ? t('admin.users.radiologist_own') : t('admin.users.radiologist_group')) ?>"><i class="ph ph-radioactive" aria-hidden="true"></i> <?= $e(t('admin.users.radiologist_tag')) ?></span><?php endif; ?>
<?php if (!$account->isOwner && !$account->isRadiologist()): ?><span class="tag tag-outline" title="<?= $e(t('admin.users.writer_tag')) ?>"><i class="ph ph-pencil-simple" aria-hidden="true"></i><span class="tag-word"> <?= $e(t('admin.users.writer_tag')) ?></span></span><?php endif; ?>
<?php if (!$account->active): ?><span class="tag tag-neutral"><?= $e(t('admin.users.inactive')) ?></span><?php endif; ?>
</span></td>
<td class="wk-mono" data-label="<?= $e(t('admin.users.col_groups')) ?>"><span class="wk-badges">
<?php if ($account->groups === []): ?><span class="wk-dim">n/a</span><?php endif; ?>
<?php foreach ($account->groups as $g): ?>
<?php if (\in_array($g, $groupNames, true)): ?><a class="tag tag-neutral" href="<?= $b ?>/admin/groups/<?= $e(rawurlencode($g)) ?>">@<?= $e($g) ?></a>
<?php else: ?><span class="tag tag-outline" title="<?= $e(t('admin.users.group_missing')) ?>"><s>@<?= $e($g) ?></s></span><?php endif; ?>
<?php endforeach; ?>
</span></td>
<td class="wk-mono wk-dim" data-label="<?= $e(t('admin.users.col_grants')) ?>"><span class="wk-badges">
<?php if ($account->isOwner): ?><?= $e(t('admin.users.everywhere')) ?>
<?php elseif ($account->grants === []): ?>, 
<?php else: foreach ($account->grants as $grant): ?><span class="tag tag-neutral"><?= $e($grant->namespace) ?>:<?= $e($grant->role->value) ?></span><?php endforeach; endif; ?>
</span></td>
<td class="wk-right"><a class="btn btn-secondary btn-sm" href="<?= $href ?>"><i class="ph ph-pencil-simple" aria-hidden="true"></i><?= $e(t('admin.users.open')) ?></a></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="wk-panel" id="create">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.users.create_title')) ?></h2><p class="wk-dim"><?= $e(t('admin.users.create_help')) ?></p></hgroup></header>
<form class="wk-form" action="<?= $b ?>/admin/users" method="post">
<div class="wk-form-grid wk-mt-flush">
<div class="field"><label for="username"><?= $e(t('auth.username')) ?></label><input class="input wk-mono" type="text" id="username" name="username" value="<?= $e($oldStr('username')) ?>" autocomplete="off" pattern="[a-z0-9][a-z0-9_.\-]*" required></div>
<div class="field"><label for="password"><?= $e(t('auth.password')) ?></label><input class="input" type="password" id="password" name="password" autocomplete="new-password" required></div>
<div class="field"><label for="display_name"><?= $e(t('admin.users.display_name')) ?></label><input class="input" type="text" id="display_name" name="display_name" value="<?= $e($oldStr('display_name')) ?>" autocomplete="off"></div>
<div class="field"><label for="title"><?= $e(t('admin.users.title_field')) ?></label><input class="input" type="text" id="title" name="title" value="<?= $e($oldStr('title')) ?>" autocomplete="off"></div>
</div>
<?php $pickedGroups = $oldGroups; $pickedOwner = isset($old['owner']); $pickedRadiologist = isset($old['radiologist']); $grantsText = $oldStr('grants'); include __DIR__ . '/partials/account-access.php'; ?>
<footer><button class="btn btn-primary" type="submit"><i class="ph ph-user-plus"></i><?= $e(t('admin.users.create_submit')) ?></button></footer>
</form>
</div>
</div>
