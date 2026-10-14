<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /admin/users/{username} (Controller\AdminUsersController::show): one account, owner-only:
 * its identity and access in one form, what that access adds up to (own grants, its groups',
 * its own namespace, and the report grants that do not count for someone who is not a
 * radiologist, D47), its password, and whether it may sign in.
 *
 * Variables in scope: Reporion\Auth\User $account; list<Reporion\Auth\Group> $groups;
 * list<array{namespace: string, role: string, source: string, dropped: bool}> $access;
 * ?string $error, $passwordError; ?array<string, mixed> $old; bool $saved, $passwordSaved, $isSelf;
 * string $basePath
 */

declare(strict_types=1);

/** @var Reporion\Auth\User $account */
/** @var list<Reporion\Auth\Group> $groups */
/** @var list<array{namespace: string, role: string, source: string, dropped: bool}> $access */
/** @var ?string $error */
/** @var ?string $passwordError */
/** @var ?array<string, mixed> $old */
/** @var bool $saved */
/** @var bool $passwordSaved */
/** @var bool $isSelf */
/** @var string $basePath */

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$b = $e($basePath);
$u = $e(rawurlencode($account->username));
$val = static fn (string $k, string $current): string => $old !== null ? (\is_string($old[$k] ?? null) ? $old[$k] : '') : $current;
$groupNames = array_map(static fn (Reporion\Auth\Group $g): string => $g->name, $groups);
$name = $account->displayName !== '' ? $account->displayName : $account->username;
?>
<div class="wk-doc">
<div class="wk-doc-head">
<?= \Reporion\Http\Breadcrumb::render([['label' => t('nav.admin')], ['label' => t('admin.users.title'), 'href' => $basePath . '/admin/users'], ['label' => $account->username]]) ?>
<h1 class="wk-doc-title"><?= $e($name) ?></h1>
<div class="wk-badges">
<span class="wk-mono wk-dim"><?= $e($account->username) ?><?= $account->title !== '' ? ' · ' . $e($account->title) : '' ?></span>
<?php if ($account->isOwner): ?><span class="tag tag-accent" title="<?= $e(t('admin.users.owner')) ?>"><i class="ph ph-crown-simple" aria-hidden="true"></i><span class="tag-word"> <?= $e(t('admin.users.owner')) ?></span></span><?php endif; ?>
<?php if ($account->isRadiologist()): ?><span class="tag tag-signed" title="<?= $e(t('admin.users.radiologist_tag')) ?>"><i class="ph ph-radioactive" aria-hidden="true"></i><span class="tag-word"> <?= $e(t('admin.users.radiologist_tag')) ?></span></span><?php endif; ?>
<?php if (!$account->active): ?><span class="tag tag-neutral"><?= $e(t('admin.users.inactive')) ?></span><?php endif; ?>
<?php foreach ($account->groups as $g): ?><?php if (\in_array($g, $groupNames, true)): ?><a class="tag tag-neutral wk-mono" href="<?= $b ?>/admin/groups/<?= $e(rawurlencode($g)) ?>">@<?= $e($g) ?></a><?php else: ?><span class="tag tag-outline wk-mono" title="<?= $e(t('admin.users.group_missing')) ?>"><s>@<?= $e($g) ?></s></span><?php endif; ?><?php endforeach; ?>
</div>
</div>
<?php include __DIR__ . '/admin-tabs.php'; ?>

<?php if ($saved): ?><div class="wk-notice wk-mb-3 wk-notice-ok" role="status"><i class="ph ph-check"></i><div><?= $e(t('admin.users.saved')) ?></div></div><?php endif; ?>
<?php if ($error !== null): ?><div class="wk-notice wk-mb-3 wk-notice-error" role="alert"><i class="ph ph-warning-octagon"></i><div><?= $e($error) ?></div></div><?php endif; ?>

<form class="wk-form" action="<?= $b ?>/admin/users/<?= $u ?>" method="post">
<div class="wk-two wk-panels">
<div class="wk-panel" id="identity">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.users.identity')) ?></h2><p class="wk-dim"><?= $e(t('admin.users.identity_help')) ?></p></hgroup></header>
<div class="field"><label for="display_name"><?= $e(t('admin.users.display_name')) ?></label><input class="input" type="text" id="display_name" name="display_name" value="<?= $e($val('display_name', $account->displayName)) ?>" maxlength="120" placeholder="<?= $e($account->username) ?>"></div>
<div class="field"><label for="title"><?= $e(t('admin.users.title_field')) ?></label><input class="input" type="text" id="title" name="title" value="<?= $e($val('title', $account->title)) ?>" maxlength="120"></div>
<div class="wk-kv wk-mt-4">
<span><?= $e(t('admin.users.created')) ?></span><b><?= $e(\Reporion\Support\MetaText::when($account->createdAt)) ?></b>
<span><?= $e(t('admin.users.updated')) ?></span><b><?= $e(\Reporion\Support\MetaText::when($account->updatedAt)) ?></b>
<span><?= $e(t('profile.tokens')) ?></span><b><?= \count($account->tokens) ?></b>
</div>
</div>
<div class="wk-panel" id="access">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('profile.access')) ?></h2><p class="wk-dim"><?= $e(t('admin.users.access_help')) ?></p></hgroup></header>
<?php
$pickedGroups = $old !== null ? array_values(array_filter((array) ($old['groups'] ?? []), 'is_string')) : $account->groups;
$pickedOwner = $old !== null ? isset($old['owner']) : $account->isOwner;
$pickedRadiologist = $old !== null ? isset($old['radiologist']) : $account->radiologist;
$grantsText = $old !== null ? (\is_string($old['grants'] ?? null) ? $old['grants'] : '') : \Reporion\Auth\AccessSummary::lines($account->grants);
include __DIR__ . '/partials/account-access.php';
?>
<?php foreach (array_diff($account->groups, $groupNames) as $gone): ?>
<p class="wk-help wk-dim"><i class="ph ph-warning" aria-hidden="true"></i> <?= $e(t('admin.users.group_gone', ['@' . $gone])) ?></p>
<?php endforeach; ?>
</div>
</div>
<footer class="wk-actions wk-actions-end wk-mb-6"><button class="btn btn-primary" type="submit"><i class="ph ph-check"></i><?= $e(t('admin.users.save')) ?></button></footer>
</form>

<div class="wk-panel" id="reach">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.users.reach')) ?></h2><p class="wk-dim"><?= $e(t($account->isOwner ? ($account->isRadiologist() ? 'admin.users.reach_owner' : 'admin.users.reach_owner_reader') : 'admin.users.reach_help')) ?></p></hgroup></header>
<?php if ($access !== []): ?>
<table class="table table-cards">
<thead><tr><th><?= $e(t('admin.users.col_ns')) ?></th><th><?= $e(t('admin.users.col_role')) ?></th><th><?= $e(t('admin.users.col_via')) ?></th></tr></thead>
<tbody>
<?php foreach ($access as $row): ?>
<tr<?= $row['dropped'] ? ' class="wk-dim"' : '' ?>>
<td class="wk-mono wk-card-head"><?= $row['dropped'] ? '<s>' . $e($row['namespace']) . '</s>' : '<a href="' . $b . '/' . $e($row['namespace']) . ':">' . $e($row['namespace']) . '</a>' ?></td>
<td data-label="<?= $e(t('admin.users.col_role')) ?>"><?= $row['dropped'] ? '<span class="tag tag-outline" title="' . $e(t('admin.users.dropped_help')) . '">' . $e(t('admin.users.dropped')) . '</span>' : '<span class="tag ' . ($row['role'] === 'editor' ? 'tag-accent' : 'tag-neutral') . '">' . $e(t('admin.users.role.' . $row['role'])) . '</span>' ?></td>
<td class="wk-mono wk-dim" data-label="<?= $e(t('admin.users.col_via')) ?>"><?= $e(str_starts_with($row['source'], '@') ? $row['source'] : t('admin.users.via.' . $row['source'])) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>

<div class="wk-two wk-panels">
<div class="wk-panel" id="password">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('profile.password')) ?></h2><p class="wk-dim"><?= $e(t('admin.users.password_help')) ?></p></hgroup></header>
<?php if ($passwordSaved): ?><div class="wk-notice wk-mb-3 wk-notice-ok" role="status"><i class="ph ph-check"></i><div><?= $e(t('profile.saved')) ?></div></div><?php endif; ?>
<form class="wk-form" action="<?= $b ?>/admin/users/<?= $u ?>/password" method="post">
<div class="field"><label for="new"><?= $e(t('admin.users.new_password')) ?></label><input class="input" type="password" id="new" name="new" autocomplete="new-password" required<?= ($passwordField ?? null) === 'new' ? ' aria-invalid="true" aria-describedby="pw-error"' : '' ?>><?php if (($passwordField ?? null) === 'new' && $passwordError !== null): ?><small id="pw-error" class="wk-field-error"><?= $e($passwordError) ?></small><?php endif; ?></div>
<div class="field"><label for="repeat"><?= $e(t('profile.repeat')) ?></label><input class="input" type="password" id="repeat" name="repeat" autocomplete="new-password" required<?= ($passwordField ?? null) === 'repeat' ? ' aria-invalid="true" aria-describedby="pw-error"' : '' ?>><?php if (($passwordField ?? null) === 'repeat' && $passwordError !== null): ?><small id="pw-error" class="wk-field-error"><?= $e($passwordError) ?></small><?php endif; ?></div>
<footer><button class="btn btn-secondary" type="submit"><i class="ph ph-lock-key"></i><?= $e(t('admin.users.set_password')) ?></button></footer>
</form>
</div>
<div class="wk-panel" id="status">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.users.col_status')) ?></h2><p class="wk-dim"><?= $e(t($account->active ? 'admin.users.active_help' : 'admin.users.inactive_help')) ?></p></hgroup><span class="tag <?= $account->active ? 'tag-outline' : 'tag-neutral' ?>"><?= $e(t($account->active ? 'admin.users.active' : 'admin.users.inactive')) ?></span></header>
<?php if ($account->active): ?>
<form class="wk-form" action="<?= $b ?>/admin/users/<?= $u ?>/deactivate" method="post" data-confirm="<?= $e(t('admin.users.deactivate_prompt')) ?>" data-confirm-label="<?= $e(t('admin.users.deactivate')) ?>">
<?php if ($isSelf): ?><p class="wk-dim wk-text-sm"><?= $e(t('admin.users.self_note')) ?></p><?php endif; ?>
<footer><button class="btn btn-secondary btn-danger" type="submit"><i class="ph ph-user-minus" aria-hidden="true"></i><?= $e(t('admin.users.deactivate')) ?></button></footer>
</form>
<?php else: ?>
<form class="wk-form" action="<?= $b ?>/admin/users/<?= $u ?>/reactivate" method="post">
<footer><button class="btn btn-secondary" type="submit"><i class="ph ph-user-check" aria-hidden="true"></i><?= $e(t('admin.users.reactivate')) ?></button></footer>
</form>
<?php endif; ?>
</div>
</div>
</div>
