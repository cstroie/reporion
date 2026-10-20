<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Admin → Plugins (Controller\AdminPluginsController): content only. One
 * panel per plugin found under plugins/: its manifest, enable/disable, and
 * its settings as a form built from the manifest's types. A `secret` is
 * never printed back. Setting labels and help come from the manifest.
 *
 * Variables in scope: array<string, \Reporion\Plugin\Manifest> $manifests;
 * array<string, string> $invalid, $failed; list<string> $enabled, $loaded;
 * array<string, array<string, mixed>> $values; ?string $saved, $error,
 * $errorPlugin; string $adminTab, $basePath
 */

declare(strict_types=1);

/** @var array<string, \Reporion\Plugin\Manifest> $manifests */
/** @var array<string, string> $invalid */
/** @var array<string, string> $failed */
/** @var list<string> $enabled */
/** @var list<string> $loaded */
/** @var array<string, string> $sites */
/** @var array<string, array<string, mixed>> $values */
/** @var ?string $saved */
/** @var ?string $error */
/** @var ?string $errorPlugin */
/** @var string $basePath */

$b = htmlspecialchars($basePath, ENT_QUOTES);
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
?>
<div class="wk-doc">
<header class="wk-doc-head">
<?= \Reporion\Http\Breadcrumb::render([['label' => t('nav.admin')], ['label' => t('admin.plugins.title')]]) ?>
<div class="wk-doc-titlerow"><h1 class="wk-doc-title"><?= $e(t('admin.plugins.title')) ?></h1></div>
<div class="wk-badges"><span class="tag tag-neutral"><?= $e(t('admin.plugins.count', [\count($manifests)])) ?></span><span class="wk-mono wk-dim"><?= $e(t('admin.plugins.explain')) ?></span></div>
</header>
<?php include __DIR__ . '/admin-tabs.php'; ?>
<?php if ($error !== null): ?>
<?php $noticeKind = 'error'; $noticeText = $error; include __DIR__ . '/partials/notice.php'; ?>
<?php elseif ($saved !== null): ?>
<?php $noticeKind = 'ok'; $noticeText = t('admin.plugins.saved', [$saved]); include __DIR__ . '/partials/notice.php'; ?>
<?php endif; ?>
<?php if ($manifests === [] && $invalid === []): ?>
<p class="wk-dim"><?= $e(t('admin.plugins.empty')) ?></p>
<?php endif; ?>
<?php foreach ($manifests as $id => $manifest): ?>
<?php $on = \in_array($id, $enabled, true); ?>
<section class="wk-panel wk-mb-6" id="plugin-<?= $e($id) ?>">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e($manifest->name) ?> <span class="wk-mono wk-dim"><?= $e($id) ?> <?= $e($manifest->version) ?></span></h2>
<?php if ($manifest->description !== ''): ?>
<p class="wk-dim"><?= $e($manifest->description) ?></p>
<?php endif; ?>
</hgroup>
<span class="wk-actions">
<?php if (isset($failed[$id])): ?>
<span class="tag tag-caution"><?= $e(t('admin.plugins.failed', [$failed[$id]])) ?></span>
<?php endif; ?>
<form action="<?= $b ?>/admin/plugins/<?= $e(rawurlencode($id)) ?>/toggle" method="post" data-autosubmit>
<span class="seg seg-sm" role="radiogroup" aria-label="<?= $e(t('admin.plugins.state')) ?>">
<label class="seg-opt"><input type="radio" name="enabled" value="0"<?= $on ? '' : ' checked' ?>><?= $e(t('admin.plugins.disabled')) ?></label>
<label class="seg-opt"><input type="radio" name="enabled" value="1"<?= $on ? ' checked' : '' ?>><?= $e(t('admin.plugins.enabled')) ?></label>
</span>
<noscript><button class="btn btn-secondary btn-sm" type="submit"><i class="ph ph-check" aria-hidden="true"></i><?= $e(t('admin.plugins.apply')) ?></button></noscript>
</form>
</span></header>
<?php if ($errorPlugin === $id && $error !== null): ?>
<?php $noticeKind = 'error'; $noticeText = $error; include __DIR__ . '/partials/notice.php'; ?>
<?php endif; ?>
<?php if ($manifest->settings !== []): ?>
<?php
// A setting with a `group` (a label) is shown in that group's fieldset, the groups in the order they first appear
$loose = array_filter($manifest->settings, static fn (array $spec): bool => !\is_string($spec['group'] ?? null));
$fields = array_filter($loose, static fn (array $spec): bool => !\in_array($spec['type'], ['bool', 'sites'], true));
$tables = array_filter($manifest->settings, static fn (array $spec): bool => $spec['type'] === 'sites');
$flags = array_filter($loose, static fn (array $spec): bool => $spec['type'] === 'bool');
$groups = [];
foreach ($manifest->settings as $key => $spec) {
    if (\is_string($spec['group'] ?? null) && $spec['type'] !== 'sites') {
        $groups[$spec['group']][$key] = $spec;
    }
}
$labelOf = static fn (string $key, array $spec): string => \is_string($spec['label'] ?? null) ? $spec['label'] : $key;
/** A tick box with its help */
$flag = static function (string $key, array $spec) use ($e, $values, $id, $labelOf): void {
?>
<label class="radio"><input type="checkbox" name="<?= $e($key) ?>" value="1"<?= ($values[$id][$key] ?? null) === true ? ' checked' : '' ?>><span class="dot"></span><?= $e($labelOf($key, $spec)) ?><?php if (\is_string($spec['help'] ?? null)): ?> <small class="wk-dim"><?= $e($spec['help']) ?></small><?php endif; ?></label>
<?php
};
/** One field: its label, the input its type calls for, its help */
$field = static function (string $key, array $spec) use ($e, $values, $id, $labelOf): void {
?>
<?php $value = $values[$id][$key] ?? null; $help = \is_string($spec['help'] ?? null) ? $spec['help'] : ''; ?>
<label><?= $e($labelOf($key, $spec)) ?>
<?php if ($spec['type'] === 'enum'): ?>
<select class="input" name="<?= $e($key) ?>"><?php foreach ((array) $spec['values'] as $choice): ?><option value="<?= $e((string) $choice) ?>"<?= (string) $value === (string) $choice ? ' selected' : '' ?>><?= $e((string) $choice) ?></option><?php endforeach; ?></select>
<?php elseif ($spec['type'] === 'secret'): ?>
<input class="input" type="password" name="<?= $e($key) ?>" value="" autocomplete="new-password" placeholder="<?= $e(($value ?? '') !== '' ? t('admin.plugins.secret_set_hint') : t('admin.plugins.secret_unset_hint')) ?>">
<?php elseif ($spec['type'] === 'textarea'): ?>
<textarea class="input wk-mono wk-text-sm" name="<?= $e($key) ?>" rows="<?= min(16, max(4, substr_count((string) $value, "\n") + 2)) ?>" spellcheck="false"><?= $e((string) $value) ?></textarea>
<?php elseif ($spec['type'] === 'int'): ?>
<input class="input" type="number" name="<?= $e($key) ?>" value="<?= $e((string) $value) ?>"<?= isset($spec['min']) ? ' min="' . (int) $spec['min'] . '"' : '' ?><?= isset($spec['max']) ? ' max="' . (int) $spec['max'] . '"' : '' ?>>
<?php else: ?>
<input class="input<?= \in_array($spec['type'], ['url', 'list'], true) ? ' wk-mono' : '' ?>" type="<?= $spec['type'] === 'url' ? 'url' : 'text' ?>" name="<?= $e($key) ?>" value="<?= $e(\is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value) ?>">
<?php endif; ?>
<?php if ($help !== ''): ?><small class="wk-dim"><?= $e($help) ?></small><?php endif; ?>
</label>
<?php
};
?>
<form action="<?= $b ?>/admin/plugins/<?= $e(rawurlencode($id)) ?>/settings" method="post">
<?php if ($fields !== []): ?>
<div class="wk-form-grid">
<?php foreach ($fields as $key => $spec): ?>
<?php $field($key, $spec); ?>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php foreach ($groups as $groupLabel => $members): ?>
<fieldset class="wk-set-group">
<legend class="wk-eyebrow"><?= $e($groupLabel) ?></legend>
<?php $groupFields = array_filter($members, static fn (array $spec): bool => $spec['type'] !== 'bool'); $groupFlags = array_filter($members, static fn (array $spec): bool => $spec['type'] === 'bool'); ?>
<?php if ($groupFields !== []): ?>
<div class="wk-set-row">
<?php foreach ($groupFields as $key => $spec): ?>
<?php $field($key, $spec); ?>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php if ($groupFlags !== []): ?>
<p class="wk-set-flags">
<?php foreach ($groupFlags as $key => $spec): ?>
<?php $flag($key, $spec); ?>
<?php endforeach; ?>
</p>
<?php endif; ?>
</fieldset>
<?php endforeach; ?>
<?php foreach ($tables as $key => $spec): ?>
<?php $rows = \is_array($values[$id][$key] ?? null) ? $values[$id][$key] : []; ?>
<?php if ($groups !== []): ?>
<fieldset class="wk-set-group">
<legend class="wk-eyebrow"><?= $e($labelOf($key, $spec)) ?></legend>
<?php if (\is_string($spec['help'] ?? null)): ?><p class="wk-dim wk-set-help"><?= $e($spec['help']) ?></p><?php endif; ?>
<?php else: ?>
<p class="wk-field-head"><b><?= $e($labelOf($key, $spec)) ?></b><?php if (\is_string($spec['help'] ?? null)): ?> <small class="wk-dim"><?= $e($spec['help']) ?></small><?php endif; ?></p>
<?php endif; ?>
<?php if ($sites === []): ?>
<p class="wk-dim"><?= $e(t('admin.plugins.no_sites')) ?> <a href="<?= $b ?>/admin/settings"><?= $e(t('admin.settings.title')) ?></a></p>
<?php else: ?>
<?php /* On a phone each site is a card, each column's label above its field (table.table-cards-form) */ ?>
<table class="table table-cards table-cards-form">
<?php $rowLink = \in_array($id, $loaded, true) && \is_array($spec['row_link'] ?? null) ? $spec['row_link'] : null; ?>
<thead><tr><th><?= $e(t('admin.plugins.site')) ?></th><?php foreach ((array) $spec['columns'] as $column => $col): ?><th><?= $e(\is_string($col['label'] ?? null) ? $col['label'] : $column) ?></th><?php endforeach; ?><?php if ($rowLink !== null): ?><th></th><?php endif; ?></tr></thead>
<tbody>
<?php foreach ($sites as $code => $siteName): ?>
<tr><td class="wk-card-head"><?= $e($siteName) ?> <span class="wk-mono wk-dim"><?= $e($code) ?></span></td>
<?php foreach ((array) $spec['columns'] as $column => $col): ?>
<?php $cell = $rows[$code][$column] ?? ($col['default'] ?? ''); $name = $key . '[' . $code . '][' . $column . ']'; ?>
<td data-label="<?= $e(\is_string($col['label'] ?? null) ? $col['label'] : $column) ?>"<?= $col['type'] === 'bool' ? ' class="wk-card-bool"' : '' ?>><?php if ($col['type'] === 'bool'): ?><label class="radio"><input type="checkbox" name="<?= $e($name) ?>" value="1"<?= $cell === true ? ' checked' : '' ?> aria-label="<?= $e($code . ' ' . $column) ?>"><span class="dot"></span></label><?php elseif ($col['type'] === 'enum'): ?><select class="input wk-inline-input" name="<?= $e($name) ?>" aria-label="<?= $e($code . ' ' . $column) ?>"><?php foreach ((array) $col['values'] as $choice): ?><option value="<?= $e((string) $choice) ?>"<?= (string) $cell === (string) $choice ? ' selected' : '' ?>><?= $e((string) $choice) ?></option><?php endforeach; ?></select><?php else: ?><input class="input wk-inline-input wk-mono" type="<?= $col['type'] === 'int' ? 'number' : 'text' ?>" name="<?= $e($name) ?>" value="<?= $e((string) $cell) ?>"<?= \is_string($col['placeholder'] ?? null) ? ' placeholder="' . $e($col['placeholder']) . '"' : '' ?> aria-label="<?= $e($code . ' ' . $column) ?>"><?php endif; ?></td>
<?php endforeach; ?>
<?php if ($rowLink !== null): ?>
<td class="wk-right"><a class="btn btn-ghost btn-sm" href="<?= $b . $e(str_replace('{site}', rawurlencode($code), (string) $rowLink['href'])) ?>" title="<?= $e(t('admin.plugins.row_link_saved')) ?>"><i class="ph ph-arrow-square-out" aria-hidden="true"></i><?= $e((string) $rowLink['label']) ?></a></td>
<?php endif; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
<?php if ($groups !== []): ?></fieldset><?php endif; ?>
<?php endforeach; ?>
<?php if ($flags !== []): ?>
<p class="wk-opts wk-opts-top">
<?php foreach ($flags as $key => $spec): ?>
<?php $flag($key, $spec); ?>
<?php endforeach; ?>
</p>
<?php endif; ?>
<footer><button class="btn btn-primary" type="submit"><i class="ph ph-floppy-disk"></i><?= $e(t('admin.plugins.save')) ?></button></footer>
</form>
<?php endif; ?>
</section>
<?php endforeach; ?>
<?php foreach ($invalid as $dir => $why): ?>
<section class="wk-panel wk-mb-6">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow wk-mono"><?= $e($dir) ?></h2><p class="wk-dim"><?= $e($why) ?></p></hgroup><span class="tag tag-caution"><?= $e(t('admin.plugins.invalid')) ?></span></header>
</section>
<?php endforeach; ?>
</div>
