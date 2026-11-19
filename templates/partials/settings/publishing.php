<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Admin settings panel: publishing (POST /admin/settings/publishing). Needs partials/settings/helpers.php included first.
 */
?>
<div class="wk-panel" id="publishing">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.settings.publishing')) ?></h2></hgroup></header>
<?= $notice('publishing') ?>
<form action="<?= $b ?>/admin/settings/publishing" method="post">
<div class="wk-form-grid">
<label><?= $e(t('admin.settings.feeds')) ?><?= $source('feeds.namespaces') ?><input class="input wk-mono" type="text" name="<?= $field('feeds.namespaces') ?>" value="<?= $e(implode(', ', (array) ($values['feeds.namespaces'] ?? []))) ?>" placeholder="<?= $e(t('admin.settings.feeds_hint')) ?>"><small><?= $e(t('admin.settings.feeds_help')) ?></small></label>
</div>
<p class="wk-opts wk-opts-top">
<label class="radio"><input type="checkbox" name="<?= $field('export.allow_public_export') ?>" value="1"<?= $checked('export.allow_public_export') ?>><span class="dot"></span><span><?= $e(t('admin.settings.allow_public_export')) ?><?= $source('export.allow_public_export') ?></span></label>
<label class="radio"><input type="checkbox" name="<?= $field('export.pseudonymise_public') ?>" value="1"<?= $checked('export.pseudonymise_public') ?>><span class="dot"></span><span><?= $e(t('admin.settings.pseudonymise_public')) ?><?= $source('export.pseudonymise_public') ?></span></label>
<label class="radio"><input type="checkbox" name="<?= $field('export.allow_draft_export') ?>" value="1"<?= $checked('export.allow_draft_export') ?>><span class="dot"></span><span><?= $e(t('admin.settings.allow_draft_export')) ?><?= $source('export.allow_draft_export') ?></span></label>
</p>
<footer><button class="btn btn-primary" type="submit"><i class="ph ph-check"></i><?= $e(t('admin.settings.save')) ?></button></footer>
</form>
</div>
