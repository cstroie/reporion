<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Admin → Settings (Controller\AdminSettingsController) — content only.
 * One form per section, each saved on its own; values come from
 * data/settings.yaml, else from conf/local.php (marked as such).
 *
 * Variables in scope: array<string, mixed> $values; array<string, bool> $fromFile;
 * bool $hasFile; string $saved; ?string $error, $errorSection; string $adminTab, $basePath
 */

declare(strict_types=1);

/** @var array<string, mixed> $values */
/** @var array<string, bool> $fromFile */
/** @var bool $hasFile */
/** @var string $saved */
/** @var ?string $error */
/** @var ?string $errorSection */
/** @var string $basePath */

$b = htmlspecialchars($basePath, ENT_QUOTES);
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$source = static fn (string $key): string => $fromFile[$key] ?? false ? '' : ' <span class="wk-mono wk-dim wk-text-xs">' . htmlspecialchars(t('admin.settings.from_local'), ENT_QUOTES) . '</span>';
$notice = static function (string $section) use ($saved, $error, $errorSection, $e): string {
    if ($errorSection === $section && $error !== null) {
        return '<div class="wk-notice wk-mb-3" role="alert"><i class="ph ph-warning"></i><div>' . $e($error) . '</div></div>';
    }

    return $saved === $section ? '<div class="wk-notice wk-mb-3" role="status"><i class="ph ph-check"></i><div>' . $e(t('admin.settings.saved')) . '</div></div>' : '';
};
$text = static fn (string $key): string => htmlspecialchars((string) ($values[$key] ?? ''), ENT_QUOTES);
$checked = static fn (string $key): string => ($values[$key] ?? false) ? ' checked' : '';
$field = static fn (string $key): string => str_replace('.', '_', $key);
$modalityMap = \is_array($values['reports.modality_namespaces'] ?? null) && $values['reports.modality_namespaces'] !== []
    ? $values['reports.modality_namespaces']
    : \Reporion\Service\NewReport::DEFAULT_MODALITY_NAMESPACES;
$icon = (string) ($values['site.icon'] ?? '');
?>
<div class="wk-doc">
<div class="wk-doc-head">
<?= \Reporion\Http\Breadcrumb::render([['label' => t('nav.admin')], ['label' => t('admin.settings.title')]]) ?>
<h1 class="wk-doc-title"><?= $e(t('admin.settings.title')) ?></h1>
<div class="wk-badges"><span class="wk-mono wk-dim"><?= $e(t($hasFile ? 'admin.settings.explain' : 'admin.settings.explain_none')) ?></span></div>
</div>
<?php include __DIR__ . '/admin-tabs.php'; ?>

<div class="wk-panel" id="site">
<header class="wk-panel-h"><h2 class="wk-eyebrow"><?= $e(t('admin.settings.site')) ?></h2></header>
<?= $notice('site') ?>
<form action="<?= $b ?>/admin/settings/site" method="post">
<div class="wk-form-grid">
<label><?= $e(t('admin.settings.site_title')) ?><?= $source('site.title') ?><input class="input" type="text" name="<?= $field('site.title') ?>" value="<?= $text('site.title') ?>" required maxlength="80"></label>
<label><?= $e(t('admin.settings.site_tagline')) ?><?= $source('site.tagline') ?><input class="input" type="text" name="<?= $field('site.tagline') ?>" value="<?= $text('site.tagline') ?>" maxlength="240"></label>
<label><?= $e(t('admin.settings.base_url')) ?><?= $source('site.base_url') ?><input class="input wk-mono" type="url" name="<?= $field('site.base_url') ?>" value="<?= $text('site.base_url') ?>" placeholder="https://reports.example.ro"><small class="wk-dim"><?= $e(t('admin.settings.base_url_help')) ?></small></label>
<label><?= $e(t('admin.settings.home_page')) ?><?= $source('site.home_page') ?><input class="input wk-mono" type="text" name="<?= $field('site.home_page') ?>" value="<?= $text('site.home_page') ?>" required><small class="wk-dim"><?= $e(t('admin.settings.home_page_help')) ?></small></label>
<label><?= $e(t('admin.settings.timezone')) ?><?= $source('site.timezone') ?><input class="input wk-mono" type="text" name="<?= $field('site.timezone') ?>" value="<?= $text('site.timezone') !== '' ? $text('site.timezone') : $e(date_default_timezone_get()) ?>" list="tz-list" required></label>
</div>
<datalist id="tz-list"><?php foreach (timezone_identifiers_list() as $tz): ?><option value="<?= $e($tz) ?>"><?php endforeach; ?></datalist>
<?php if ($icon !== ''): ?>
<p class="wk-text-sm wk-mt-flush"><label class="radio"><input type="checkbox" name="remove_icon" value="1"><span class="dot"></span><?= $e(t('admin.settings.remove_icon')) ?></label></p>
<?php endif; ?>
<p class="wk-mt-flush"><button class="btn btn-primary" type="submit"><i class="ph ph-check"></i><?= $e(t('admin.settings.save')) ?></button></p>
</form>
<div style="display:flex;gap:var(--space-3);align-items:center;margin-top:var(--space-4);padding-top:var(--space-3);border-top:1px solid var(--color-divider)">
<?php if ($icon !== ''): ?><img src="<?= $b ?>/site-icon/<?= $e($icon) ?>" alt="" width="32" height="32" style="border-radius:var(--radius-sm)"><?php endif; ?>
<label class="wk-text-sm"><?= $e(t('admin.settings.icon')) ?> <input type="file" id="site-icon-file" accept="image/png,image/x-icon,image/vnd.microsoft.icon,image/gif,image/webp"></label>
<span class="wk-mono wk-dim wk-text-sm" id="site-icon-status"><?= $e(t('admin.settings.icon_help')) ?></span>
</div>
</div>

<div class="wk-panel" id="publishing">
<header class="wk-panel-h"><h2 class="wk-eyebrow"><?= $e(t('admin.settings.publishing')) ?></h2></header>
<?= $notice('publishing') ?>
<form action="<?= $b ?>/admin/settings/publishing" method="post">
<div class="wk-form-grid">
<label><?= $e(t('admin.settings.feeds')) ?><?= $source('feeds.namespaces') ?><input class="input wk-mono" type="text" name="<?= $field('feeds.namespaces') ?>" value="<?= $e(implode(', ', (array) ($values['feeds.namespaces'] ?? []))) ?>" placeholder="docs, teaching"><small class="wk-dim"><?= $e(t('admin.settings.feeds_help')) ?></small></label>
</div>
<p style="font-size:var(--text-sm);margin:var(--space-3) 0 0;display:flex;flex-direction:column;gap:var(--space-2)">
<label class="radio"><input type="checkbox" name="<?= $field('export.allow_public_export') ?>" value="1"<?= $checked('export.allow_public_export') ?>><span class="dot"></span><?= $e(t('admin.settings.allow_public_export')) ?><?= $source('export.allow_public_export') ?></label>
<label class="radio"><input type="checkbox" name="<?= $field('export.pseudonymise_public') ?>" value="1"<?= $checked('export.pseudonymise_public') ?>><span class="dot"></span><?= $e(t('admin.settings.pseudonymise_public')) ?><?= $source('export.pseudonymise_public') ?></label>
<label class="radio"><input type="checkbox" name="<?= $field('export.allow_draft_export') ?>" value="1"<?= $checked('export.allow_draft_export') ?>><span class="dot"></span><?= $e(t('admin.settings.allow_draft_export')) ?><?= $source('export.allow_draft_export') ?></label>
</p>
<p class="wk-mt-flush"><button class="btn btn-primary" type="submit"><i class="ph ph-check"></i><?= $e(t('admin.settings.save')) ?></button></p>
</form>
</div>

<div class="wk-panel" id="limits">
<header class="wk-panel-h"><h2 class="wk-eyebrow"><?= $e(t('admin.settings.limits')) ?></h2></header>
<?= $notice('limits') ?>
<form action="<?= $b ?>/admin/settings/limits" method="post">
<div class="wk-form-grid">
<label><?= $e(t('admin.settings.trash_days')) ?><?= $source('pages.trash_purge_days') ?><input class="input" type="number" min="1" max="3650" name="<?= $field('pages.trash_purge_days') ?>" value="<?= (int) ($values['pages.trash_purge_days'] ?? 30) ?>" required></label>
<label><?= $e(t('admin.settings.media_mb')) ?><?= $source('media.max_bytes') ?><input class="input" type="number" min="1" max="512" name="<?= $field('media.max_bytes') ?>" value="<?= max(1, intdiv((int) ($values['media.max_bytes'] ?? 8 * 1024 * 1024), 1024 * 1024)) ?>" required><small class="wk-dim"><?= $e(t('admin.settings.media_help')) ?></small></label>
</div>
<p class="wk-mt-flush"><button class="btn btn-primary" type="submit"><i class="ph ph-check"></i><?= $e(t('admin.settings.save')) ?></button></p>
</form>
</div>

<div class="wk-panel" id="reports">
<header class="wk-panel-h"><h2 class="wk-eyebrow"><?= $e(t('admin.settings.reports')) ?></h2></header>
<?= $notice('reports') ?>
<form action="<?= $b ?>/admin/settings/reports" method="post">
<div class="wk-form-grid">
<label><?= $e(t('admin.settings.modality_namespaces')) ?><?= $source('reports.modality_namespaces') ?><textarea class="input wk-mono wk-text-sm" name="<?= $field('reports.modality_namespaces') ?>" rows="5"><?php foreach ($modalityMap as $modality => $ns): ?><?= $e((string) $modality) ?> = <?= $e((string) $ns) ?>&#10;<?php endforeach; ?></textarea><small class="wk-dim"><?= $e(t('admin.settings.modality_namespaces_help')) ?></small></label>
</div>
<p class="wk-mt-flush"><button class="btn btn-primary" type="submit"><i class="ph ph-check"></i><?= $e(t('admin.settings.save')) ?></button></p>
</form>
</div>
</div>
<script>
(function () {
  var input = document.getElementById('site-icon-file');
  var status = document.getElementById('site-icon-status');
  if (!input) return;
  input.addEventListener('change', function () {
    var file = input.files && input.files[0];
    if (!file) return;
    status.textContent = <?= json_encode(t('admin.settings.icon_uploading'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    fetch(<?= json_encode($basePath . '/admin/settings/icon', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, { method: 'POST', body: file, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); })
      .then(function (res) {
        if (res.ok) { window.location.reload(); return; }
        status.textContent = res.json && res.json.error ? res.json.error.message : 'Upload failed';
      })
      .catch(function () { status.textContent = 'Upload failed'; });
  });
}());
</script>
