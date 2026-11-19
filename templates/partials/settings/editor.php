<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Admin settings panel: editor (POST /admin/settings/editor). Needs partials/settings/helpers.php included first.
 */
?>
<div class="wk-panel" id="editor">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.settings.editor')) ?></h2></hgroup></header>
<?= $notice('editor') ?>
<form action="<?= $b ?>/admin/settings/editor" method="post">
<p class="wk-opts">
<label class="radio"><input type="checkbox" name="<?= $field('editor.save_stays_open') ?>" value="1"<?= $checked('editor.save_stays_open') ?>><span class="dot"></span><span><?= $e(t('admin.settings.save_stays_open')) ?><?= $source('editor.save_stays_open') ?><small><?= $e(t('admin.settings.save_stays_open_help')) ?></small></span></label>
</p>
<footer><button class="btn btn-primary" type="submit"><i class="ph ph-check"></i><?= $e(t('admin.settings.save')) ?></button></footer>
</form>
</div>
