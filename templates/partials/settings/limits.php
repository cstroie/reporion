<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Admin settings panel: limits (POST /admin/settings/limits). Needs partials/settings/helpers.php included first.
 */
?>
<div class="wk-panel" id="limits">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.settings.limits')) ?></h2></hgroup></header>
<?= $notice('limits') ?>
<form action="<?= $b ?>/admin/settings/limits" method="post">
<div class="wk-form-grid">
<label><?= $e(t('admin.settings.media_mb')) ?><?= $source('media.max_bytes') ?><input class="input" type="number" min="1" max="512" name="<?= $field('media.max_bytes') ?>" value="<?= max(1, intdiv((int) ($values['media.max_bytes'] ?? 8 * 1024 * 1024), 1024 * 1024)) ?>" required><small><?= $e(t('admin.settings.media_help')) ?></small></label>
</div>
<footer><button class="btn btn-primary" type="submit"><i class="ph ph-check"></i><?= $e(t('admin.settings.save')) ?></button></footer>
</form>
</div>
