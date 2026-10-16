<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The access fields of an account form (Admin → Users: create, and one account's page): owner,
 * radiologist, groups and own grants (D35, D47). Inside the caller's <form>.
 *
 * Variables in scope: list<Reporion\Auth\Group> $groups; list<string> $pickedGroups;
 * bool $pickedOwner, $pickedRadiologist; string $grantsText; string $basePath
 */

declare(strict_types=1);

/** @var list<Reporion\Auth\Group> $groups */
/** @var list<string> $pickedGroups */
/** @var bool $pickedOwner */
/** @var bool $pickedRadiologist */
/** @var string $grantsText */
/** @var string $basePath */

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
?>
<fieldset class="wk-access">
<legend class="wk-eyebrow"><?= $e(t('admin.users.kind')) ?></legend>
<label class="radio"><input type="checkbox" name="radiologist" value="1"<?= $pickedRadiologist ? ' checked' : '' ?>><span class="dot"></span><span><b><?= $e(t('admin.users.radiologist')) ?></b><br><span class="wk-dim wk-text-sm"><?= $e(t('admin.users.radiologist_help')) ?></span></span></label>
<label class="radio"><input type="checkbox" name="owner" value="1"<?= $pickedOwner ? ' checked' : '' ?>><span class="dot"></span><span><b><?= $e(t('admin.users.make_owner')) ?></b><br><span class="wk-dim wk-text-sm"><?= $e(t('admin.users.owner_help')) ?></span></span></label>
</fieldset>
<fieldset class="wk-access">
<legend class="wk-eyebrow"><?= $e(t('admin.users.col_groups')) ?></legend>
<?php if ($groups === []): ?>
<p class="wk-dim wk-text-sm wk-m0"><?= $e(t('admin.users.no_groups')) ?> <a href="<?= $e($basePath) ?>/admin/groups#create"><?= $e(t('admin.groups.create_title')) ?></a></p>
<?php endif; ?>
<?php foreach ($groups as $group): ?>
<label class="radio"><input type="checkbox" name="groups[]" value="<?= $e($group->name) ?>"<?= \in_array($group->name, $pickedGroups, true) ? ' checked' : '' ?>><span class="dot"></span><span><b class="wk-mono">@<?= $e($group->name) ?></b><?= $group->displayName !== '' ? ' ' . $e($group->displayName) : '' ?><?php if ($group->radiologist): ?> <i class="ph ph-radioactive" title="<?= $e(t('admin.groups.radiologist_tag')) ?>" aria-label="<?= $e(t('admin.groups.radiologist_tag')) ?>"></i><?php endif; ?><br><span class="wk-mono wk-dim wk-text-sm"><?= $group->grants === [] ? 'n/a' : $e(str_replace("\n", " · ", \Reporion\Auth\AccessSummary::lines($group->grants))) ?></span></span></label>
<?php endforeach; ?>
</fieldset>
<div class="field">
<label for="grants"><?= $e(t('admin.users.grants_label')) ?>
<textarea class="input wk-mono" id="grants" name="grants" rows="3" placeholder="<?= $e(t('admin.users.grants_hint')) ?>" aria-describedby="grants-help"><?= $e($grantsText) ?></textarea>
<small id="grants-help"><?= $e(t('admin.users.grants_help')) ?></small></label>
</div>
