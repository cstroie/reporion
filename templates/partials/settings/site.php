<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Admin settings panel: site (POST /admin/settings/site). Needs partials/settings/helpers.php included first.
 */
?>
<div class="wk-panel" id="site">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.settings.site')) ?></h2></hgroup></header>
<?= $notice('site') ?>
<form action="<?= $b ?>/admin/settings/site" method="post">
<div class="wk-form-grid">
<label><?= $e(t('admin.settings.site_title')) ?><?= $source('site.title') ?><input class="input" type="text" name="<?= $field('site.title') ?>" value="<?= $text('site.title') ?>" required maxlength="80"></label>
<label><?= $e(t('admin.settings.site_tagline')) ?><?= $source('site.tagline') ?><input class="input" type="text" name="<?= $field('site.tagline') ?>" value="<?= $text('site.tagline') ?>" maxlength="240"><small><?= $e(t('admin.settings.site_tagline_help')) ?></small></label>
<label><?= $e(t('admin.settings.base_url')) ?><?= $source('site.base_url') ?><input class="input wk-mono" type="url" name="<?= $field('site.base_url') ?>" value="<?= $text('site.base_url') ?>" placeholder="<?= $e(t('admin.settings.base_url_hint')) ?>"><small><?= $e(t('admin.settings.base_url_help')) ?></small></label>
<label><?= $e(t('admin.settings.home_page')) ?><?= $source('site.home_page') ?><input class="input wk-mono" type="text" name="<?= $field('site.home_page') ?>" value="<?= $text('site.home_page') ?>" required><small><?= $e(t('admin.settings.home_page_help')) ?></small></label>
<label><?= $e(t('admin.settings.timezone')) ?><?= $source('site.timezone') ?><input class="input wk-mono" type="text" name="<?= $field('site.timezone') ?>" value="<?= $text('site.timezone') !== '' ? $text('site.timezone') : $e(date_default_timezone_get()) ?>" list="tz-list" required></label>
</div>
<datalist id="tz-list"><?php foreach (timezone_identifiers_list() as $tz): ?><option value="<?= $e($tz) ?>"><?php endforeach; ?></datalist>
<?php if ($icon !== ''): ?>
<p class="wk-text-sm wk-mt-flush"><label class="radio"><input type="checkbox" name="remove_icon" value="1"><span class="dot"></span><?= $e(t('admin.settings.remove_icon')) ?></label></p>
<?php endif; ?>
<?php /* The icon uploads on its own (the script below, by id); the file input has no name, so Save never sends it */ ?>
<div class="wk-icon-row">
<?php if ($icon !== ''): ?><img src="<?= $b ?>/site-icon/<?= $e($icon) ?>" alt="" width="32" height="32"><?php endif; ?>
<label class="wk-text-sm"><?= $e(t('admin.settings.icon')) ?> <input type="file" id="site-icon-file" accept="image/png,image/x-icon,image/vnd.microsoft.icon,image/gif,image/webp"></label>
<span class="wk-mono wk-dim wk-text-sm" id="site-icon-status"><?= $e(t('admin.settings.icon_help')) ?></span>
</div>
<footer><button class="btn btn-primary" type="submit"><i class="ph ph-check"></i><?= $e(t('admin.settings.save')) ?></button></footer>
</form>
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
