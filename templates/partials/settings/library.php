<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Admin settings panel: library (POST /admin/settings/library). Needs partials/settings/helpers.php included first.
 */
?>
<div class="wk-panel" id="library">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.settings.library')) ?></h2></hgroup></header>
<?= $notice('library') ?>
<form action="<?= $b ?>/admin/settings/library" method="post">
<p class="wk-opts">
<label class="radio"><input type="checkbox" name="<?= $field('library.lookup') ?>" value="1"<?= $checked('library.lookup') ?>><span class="dot"></span><span><?= $e(t('admin.settings.library_lookup')) ?><?= $source('library.lookup') ?><small><?= $e(t('admin.settings.library_lookup_help')) ?></small></span></label>
<label class="radio"><input type="checkbox" name="<?= $field('library.web_clip') ?>" value="1"<?= $checked('library.web_clip') ?>><span class="dot"></span><span><?= $e(t('admin.settings.library_web_clip')) ?><?= $source('library.web_clip') ?><small><?= $e(t('admin.settings.library_web_clip_help')) ?></small></span></label>
<label class="radio"><input type="checkbox" name="<?= $field('library.search') ?>" value="1"<?= $checked('library.search') ?>><span class="dot"></span><span><?= $e(t('admin.settings.library_search')) ?><?= $source('library.search') ?><small><?= $e(t('admin.settings.library_search_help')) ?></small></span></label>
</p>
<div class="wk-form-grid">
<label><?= $e(t('admin.settings.library_unpaywall')) ?><?= $source('library.unpaywall_email') ?><input class="input" type="email" name="<?= $field('library.unpaywall_email') ?>" value="<?= $e((string) ($values['library.unpaywall_email'] ?? '')) ?>" maxlength="120" placeholder="<?= $e(t('admin.settings.library_unpaywall_hint')) ?>" autocomplete="off" spellcheck="false"><small><?= $e(t('admin.settings.library_unpaywall_help')) ?></small></label>
<label><?= $e(t('admin.settings.library_openalex')) ?><?= $source('library.openalex_key') ?><input class="input wk-mono" type="text" name="<?= $field('library.openalex_key') ?>" value="<?= $e((string) ($values['library.openalex_key'] ?? '')) ?>" maxlength="128" placeholder="<?= $e(t('admin.settings.library_openalex_hint')) ?>" autocomplete="off" spellcheck="false"><small><?= $e(t('admin.settings.library_openalex_help')) ?></small></label>
<label><?= $e(t('admin.settings.library_language')) ?><?= $source('library.language') ?><select class="input" name="<?= $field('library.language') ?>">
<?php foreach (\Reporion\Service\Ai\Context::LANGUAGES as $code => $name): ?><option value="<?= $e($code) ?>"<?= ($values['library.language'] ?? 'ro') === $code ? ' selected' : '' ?>><?= $e($name) ?></option><?php endforeach; ?>
</select><small><?= $e(t('admin.settings.library_language_help')) ?></small></label>
<label><?= $e(t('admin.settings.library_tokens')) ?><?= $source('library.context_tokens') ?><input class="input" type="number" min="4000" max="2000000" step="1000" name="<?= $field('library.context_tokens') ?>" value="<?= (int) ($values['library.context_tokens'] ?? 32000) ?>" placeholder="<?= $e(t('admin.settings.library_tokens_hint')) ?>"><small><?= $e(t('admin.settings.library_tokens_help')) ?></small></label>
</div>
<footer><button class="btn btn-primary" type="submit"><i class="ph ph-check"></i><?= $e(t('admin.settings.save')) ?></button></footer>
</form>
</div>
