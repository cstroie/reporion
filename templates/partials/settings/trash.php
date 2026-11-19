<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Admin settings form: trash (POST /admin/settings/trash), inside Data → Trash. Needs partials/settings/helpers.php included first.
 */
?>
<?= $notice('trash') ?>
<form action="<?= $b ?>/admin/settings/trash" method="post">
<div class="wk-form-grid">
<label><?= $e(t('admin.settings.trash_days')) ?><?= $source('pages.trash_purge_days') ?><input class="input" type="number" min="1" max="3650" name="<?= $field('pages.trash_purge_days') ?>" value="<?= (int) ($values['pages.trash_purge_days'] ?? 30) ?>" required></label>
</div>
<footer><button class="btn btn-primary" type="submit"><i class="ph ph-check"></i><?= $e(t('admin.settings.save')) ?></button></footer>
</form>
