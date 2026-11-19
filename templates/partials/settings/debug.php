<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Admin settings panel: debug (POST /admin/settings/debug). Needs partials/settings/helpers.php included first.
 */
?>
<div class="wk-panel" id="debug">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.settings.debug')) ?></h2></hgroup></header>
<?= $notice('debug') ?>
<form action="<?= $b ?>/admin/settings/debug" method="post">
<p class="wk-opts">
<label class="radio"><input type="checkbox" name="<?= $field('debug.panels') ?>" value="1"<?= $checked('debug.panels') ?>><span class="dot"></span><span><?= $e(t('admin.settings.debug_panels')) ?><?= $source('debug.panels') ?><small><?= $e(t('admin.settings.debug_panels_help')) ?></small></span></label>
</p>
<footer><button class="btn btn-primary" type="submit"><i class="ph ph-check"></i><?= $e(t('admin.settings.save')) ?></button></footer>
</form>
</div>
