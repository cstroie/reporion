<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /admin/groups/{name} (Controller\AdminGroupsController::show): one group, owner-only (D47):
 * what it gives, who is in it (membership is set on each account), and deleting it once empty.
 *
 * Variables in scope: Reporion\Auth\Group $group; list<Reporion\Auth\User> $members;
 * ?string $error; ?array<string, mixed> $old; bool $saved; string $basePath
 */

declare(strict_types=1);

/** @var Reporion\Auth\Group $group */
/** @var list<Reporion\Auth\User> $members */
/** @var ?string $error */
/** @var ?array<string, mixed> $old */
/** @var bool $saved */
/** @var string $basePath */

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$b = $e($basePath);
$g = $e(rawurlencode($group->name));
?>
<div class="wk-doc">
<div class="wk-doc-head">
<?= \Reporion\Http\Breadcrumb::render([['label' => t('nav.admin')], ['label' => t('admin.groups.title'), 'href' => $basePath . '/admin/groups'], ['label' => '@' . $group->name]]) ?>
<h1 class="wk-doc-title"><span class="wk-mono">@<?= $e($group->name) ?></span></h1>
<div class="wk-badges">
<?php if ($group->displayName !== ''): ?><span class="wk-dim"><?= $e($group->displayName) ?></span><?php endif; ?>
<?php if ($group->radiologist): ?><span class="tag tag-signed"><i class="ph ph-radioactive" aria-hidden="true"></i> <?= $e(t('admin.groups.radiologist_tag')) ?></span><?php endif; ?>
<span class="wk-mono wk-dim"><?= $e(t('admin.groups.member_count', [\count($members)])) ?></span>
</div>
</div>
<?php include __DIR__ . '/admin-tabs.php'; ?>

<?php if ($saved): ?><div class="wk-notice wk-mb-3" role="status"><i class="ph ph-check"></i><div><?= $e(t('admin.groups.saved')) ?></div></div><?php endif; ?>
<?php if ($error !== null): ?><div class="wk-notice wk-mb-3" role="alert"><i class="ph ph-warning"></i><div><?= $e($error) ?></div></div><?php endif; ?>

<div class="wk-two wk-panels">
<div class="wk-panel" id="gives">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.groups.gives')) ?></h2><p class="wk-dim"><?= $e(t('admin.groups.gives_help')) ?></p></hgroup></header>
<form class="wk-form" action="<?= $b ?>/admin/groups/<?= $g ?>" method="post">
<div class="field"><label for="display_name"><?= $e(t('admin.groups.display_name')) ?></label><input class="input" type="text" id="display_name" name="display_name" value="<?= $e($old !== null ? (\is_string($old['display_name'] ?? null) ? $old['display_name'] : '') : $group->displayName) ?>" autocomplete="off"></div>
<?php
$radiologist = $old !== null ? isset($old['radiologist']) : $group->radiologist;
$grantsText = $old !== null ? (\is_string($old['grants'] ?? null) ? $old['grants'] : '') : \Reporion\Auth\AccessSummary::lines($group->grants);
include __DIR__ . '/partials/group-access.php';
?>
<footer><button class="btn btn-primary" type="submit"><i class="ph ph-check"></i><?= $e(t('admin.groups.save')) ?></button></footer>
</form>
</div>
<div class="wk-panel" id="members">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.groups.col_members')) ?></h2><p class="wk-dim"><?= $e(t('admin.groups.members_help')) ?></p></hgroup></header>
<?php if ($members === []): ?>
<div class="wk-empty"><i class="ph ph-user" aria-hidden="true"></i><p><?= $e(t('admin.groups.no_members')) ?></p></div>
<form class="wk-form" action="<?= $b ?>/admin/groups/<?= $g ?>/delete" method="post" data-confirm="<?= $e(t('admin.groups.delete_prompt')) ?>" data-confirm-label="<?= $e(t('admin.groups.delete')) ?>">
<footer><button class="btn btn-secondary btn-danger" type="submit"><i class="ph ph-trash" aria-hidden="true"></i><?= $e(t('admin.groups.delete')) ?></button></footer>
</form>
<?php else: ?>
<table class="table">
<tbody>
<?php foreach ($members as $m): ?>
<tr<?= $m->active ? '' : ' class="wk-dim"' ?>><td><a href="<?= $b ?>/admin/users/<?= $e(rawurlencode($m->username)) ?>"><?= $e($m->displayName !== '' ? $m->displayName : $m->username) ?></a> <span class="wk-mono wk-dim"><?= $e($m->username) ?></span></td><td class="wk-right"><?php if (!$m->active): ?><span class="tag tag-neutral"><?= $e(t('admin.users.inactive')) ?></span><?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
<p class="wk-help wk-dim"><?= $e(t('admin.groups.delete_blocked')) ?></p>
<?php endif; ?>
</div>
</div>
</div>
