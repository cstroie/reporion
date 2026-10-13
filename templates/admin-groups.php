<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /admin/groups (Controller\AdminGroupsController::index): owner-only (D47). Each group with
 * what it gives (its grants, radiologist or not) and who is in it, opening onto its own page
 * (templates/admin-group.php); then the create form.
 *
 * Variables in scope: list<Reporion\Auth\Group> $groups; array<string, list<Reporion\Auth\User>> $members;
 * ?string $error; array<string, mixed> $old; string $basePath
 */

declare(strict_types=1);

/** @var list<Reporion\Auth\Group> $groups */
/** @var array<string, list<Reporion\Auth\User>> $members */
/** @var ?string $error */
/** @var array<string, mixed> $old */
/** @var string $basePath */

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$b = $e($basePath);
$oldStr = static fn (string $k): string => \is_string($old[$k] ?? null) ? $old[$k] : '';
?>
<div class="wk-doc">
<div class="wk-doc-head">
<?= \Reporion\Http\Breadcrumb::render([['label' => t('nav.admin')], ['label' => t('admin.groups.title')]]) ?>
<h1 class="wk-doc-title"><?= $e(t('admin.groups.title')) ?></h1>
</div>
<?php include __DIR__ . '/admin-tabs.php'; ?>

<?php if ($error !== null): ?>
<div class="wk-notice wk-mb-3" role="alert"><i class="ph ph-warning"></i><div><?= $e($error) ?></div></div>
<?php endif; ?>

<div class="wk-panel" id="groups">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.groups.title')) ?></h2><p class="wk-dim"><?= $e(t('admin.groups.help')) ?></p></hgroup></header>
<?php if ($groups === []): ?>
<div class="wk-empty"><i class="ph ph-users-three" aria-hidden="true"></i><p><?= $e(t('admin.groups.none')) ?></p></div>
<?php else: ?>
<table class="table table-cards">
<thead><tr><th><?= $e(t('admin.groups.col_group')) ?></th><th><?= $e(t('admin.groups.col_gives')) ?></th><th><?= $e(t('admin.groups.col_members')) ?></th><th></th></tr></thead>
<tbody>
<?php foreach ($groups as $group): $href = $b . '/admin/groups/' . $e(rawurlencode($group->name)); $in = $members[$group->name] ?? []; ?>
<tr>
<td class="wk-card-head"><a href="<?= $href ?>"><b class="wk-mono">@<?= $e($group->name) ?></b></a><?php if ($group->displayName !== ''): ?><div class="wk-dim wk-text-sm"><?= $e($group->displayName) ?></div><?php endif; ?></td>
<td data-label="<?= $e(t('admin.groups.col_gives')) ?>"><span class="wk-badges">
<?php if ($group->radiologist): ?><span class="tag tag-signed"><i class="ph ph-radioactive" aria-hidden="true"></i> <?= $e(t('admin.groups.radiologist_tag')) ?></span><?php endif; ?>
<?php foreach ($group->grants as $grant): ?><span class="tag tag-neutral wk-mono"><?= $e($grant->namespace) ?>:<?= $e($grant->role->value) ?></span><?php endforeach; ?>
<?php if (!$group->radiologist && $group->grants === []): ?><span class="wk-dim">n/a</span><?php endif; ?>
</span></td>
<td data-label="<?= $e(t('admin.groups.col_members')) ?>"><span class="wk-badges">
<?php if ($in === []): ?><span class="wk-dim">n/a</span><?php endif; ?>
<?php foreach ($in as $m): ?><a class="tag tag-outline" href="<?= $b ?>/admin/users/<?= $e(rawurlencode($m->username)) ?>"><?= $e($m->displayName !== '' ? $m->displayName : $m->username) ?></a><?php endforeach; ?>
</span></td>
<td class="wk-right"><a class="btn btn-secondary btn-sm" href="<?= $href ?>"><i class="ph ph-pencil-simple" aria-hidden="true"></i><?= $e(t('admin.users.open')) ?></a></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>

<div class="wk-panel" id="create">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.groups.create_title')) ?></h2><p class="wk-dim"><?= $e(t('admin.groups.create_help')) ?></p></hgroup></header>
<form class="wk-form" action="<?= $b ?>/admin/groups" method="post">
<div class="wk-form-grid wk-mt-flush">
<div class="field"><label for="name"><?= $e(t('admin.groups.name')) ?></label><input class="input wk-mono" type="text" id="name" name="name" value="<?= $e($oldStr('name')) ?>" autocomplete="off" pattern="[a-z0-9][a-z0-9_.\-]*" placeholder="radiologists" required></div>
<div class="field"><label for="display_name"><?= $e(t('admin.groups.display_name')) ?></label><input class="input" type="text" id="display_name" name="display_name" value="<?= $e($oldStr('display_name')) ?>" autocomplete="off"></div>
</div>
<?php $radiologist = isset($old['radiologist']); $grantsText = $oldStr('grants'); include __DIR__ . '/partials/group-access.php'; ?>
<footer><button class="btn btn-primary" type="submit"><i class="ph ph-plus"></i><?= $e(t('admin.groups.create_submit')) ?></button></footer>
</form>
</div>
</div>
