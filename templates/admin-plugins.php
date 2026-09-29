<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Admin → Plugins (Controller\AdminPluginsController) — content only. One
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
/** @var array<string, array<string, mixed>> $values */
/** @var ?string $saved */
/** @var ?string $error */
/** @var ?string $errorPlugin */
/** @var string $basePath */

$b = htmlspecialchars($basePath, ENT_QUOTES);
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
?>
<div class="wk-doc">
<div class="wk-doc-head">
<div class="wk-crumbs wk-mono"><b><?= $e(t('nav.admin')) ?></b><span>›</span><span><?= $e(t('admin.plugins.title')) ?></span></div>
<div class="wk-doc-titlerow"><h1 class="wk-doc-title"><?= $e(t('admin.plugins.title')) ?></h1></div>
<div class="wk-badges"><span class="tag tag-neutral"><?= $e(t('admin.plugins.count', [\count($manifests)])) ?></span><span class="wk-mono wk-dim"><?= $e(t('admin.plugins.explain')) ?></span></div>
</div>
<?php include __DIR__ . '/admin-tabs.php'; ?>
<?php if ($error !== null): ?>
<div class="wk-notice" role="alert"><i class="ph ph-warning"></i><div><?= $e($error) ?></div></div>
<?php elseif ($saved !== null): ?>
<div class="wk-notice" role="status"><i class="ph ph-check"></i><div><?= $e(t('admin.plugins.saved', [$saved])) ?></div></div>
<?php endif; ?>
<?php if ($manifests === [] && $invalid === []): ?>
<p class="wk-dim"><?= $e(t('admin.plugins.empty')) ?></p>
<?php endif; ?>
<?php foreach ($manifests as $id => $manifest): ?>
<?php $on = \in_array($id, $enabled, true); ?>
<section class="wk-panel" id="plugin-<?= $e($id) ?>" style="margin-bottom:var(--space-6)">
<div class="wk-panel-h"><span class="wk-eyebrow"><?= $e($manifest->name) ?> <span class="wk-mono wk-dim"><?= $e($id) ?> <?= $e($manifest->version) ?></span></span>
<span style="display:flex;gap:var(--space-2);align-items:center">
<?php if (isset($failed[$id])): ?>
<span class="tag tag-caution"><?= $e(t('admin.plugins.failed', [$failed[$id]])) ?></span>
<?php elseif (\in_array($id, $loaded, true)): ?>
<span class="tag tag-signed"><?= $e(t('admin.plugins.loaded')) ?></span>
<?php else: ?>
<span class="tag tag-neutral"><?= $e(t('admin.plugins.disabled')) ?></span>
<?php endif; ?>
<form action="<?= $b ?>/admin/plugins/<?= $e(rawurlencode($id)) ?>/toggle" method="post"><button class="btn <?= $on ? 'btn-ghost' : 'btn-secondary' ?> btn-sm" type="submit"><?= $e(t($on ? 'admin.plugins.disable' : 'admin.plugins.enable')) ?></button></form>
</span></div>
<?php if ($manifest->description !== ''): ?>
<p class="wk-dim" style="margin:var(--space-3) 0"><?= $e($manifest->description) ?></p>
<?php endif; ?>
<?php if ($errorPlugin === $id && $error !== null): ?>
<div class="wk-notice" role="alert"><i class="ph ph-warning"></i><div><?= $e($error) ?></div></div>
<?php endif; ?>
<?php if ($manifest->settings !== []): ?>
<form action="<?= $b ?>/admin/plugins/<?= $e(rawurlencode($id)) ?>/settings" method="post" class="wk-form-grid" style="display:grid;gap:var(--space-3);grid-template-columns:repeat(auto-fit,minmax(240px,1fr))">
<?php foreach ($manifest->settings as $key => $spec): ?>
<?php $value = $values[$id][$key] ?? null; $label = \is_string($spec['label'] ?? null) ? $spec['label'] : $key; $help = \is_string($spec['help'] ?? null) ? $spec['help'] : ''; ?>
<label><?= $e($label) ?>
<?php if ($spec['type'] === 'bool'): ?>
<input type="checkbox" name="<?= $e($key) ?>" value="1"<?= $value === true ? ' checked' : '' ?>>
<?php elseif ($spec['type'] === 'enum'): ?>
<select class="input" name="<?= $e($key) ?>"><?php foreach ((array) $spec['values'] as $choice): ?><option value="<?= $e((string) $choice) ?>"<?= (string) $value === (string) $choice ? ' selected' : '' ?>><?= $e((string) $choice) ?></option><?php endforeach; ?></select>
<?php elseif ($spec['type'] === 'secret'): ?>
<input class="input" type="password" name="<?= $e($key) ?>" value="" autocomplete="new-password" placeholder="<?= $e(($value ?? '') !== '' ? t('admin.plugins.secret_set') : t('admin.plugins.secret_unset')) ?>">
<?php else: ?>
<input class="input" type="<?= $spec['type'] === 'int' ? 'number' : ($spec['type'] === 'url' ? 'url' : 'text') ?>" name="<?= $e($key) ?>" value="<?= $e(\is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value) ?>">
<?php endif; ?>
<?php if ($help !== ''): ?><span class="wk-dim" style="font-size:12px"><?= $e($help) ?></span><?php endif; ?>
</label>
<?php endforeach; ?>
<div style="grid-column:1/-1"><button class="btn btn-primary btn-sm" type="submit"><i class="ph ph-floppy-disk"></i><?= $e(t('admin.plugins.save')) ?></button></div>
</form>
<?php endif; ?>
</section>
<?php endforeach; ?>
<?php foreach ($invalid as $dir => $why): ?>
<section class="wk-panel" style="margin-bottom:var(--space-6)">
<div class="wk-panel-h"><span class="wk-eyebrow wk-mono"><?= $e($dir) ?></span><span class="tag tag-caution"><?= $e(t('admin.plugins.invalid')) ?></span></div>
<p class="wk-dim" style="margin:var(--space-3) 0"><?= $e($why) ?></p>
</section>
<?php endforeach; ?>
</div>
