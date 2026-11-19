<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Admin settings panel: backup (POST /admin/settings/backup). Needs partials/settings/helpers.php included first.
 */
?>
<?php
$bk = static fn (string $key, mixed $default = ''): mixed => $values[$key] ?? $default;
$keep = (string) $bk('backup.keep', 'week');
$transport = (string) $bk('backup.transport', 'ssh');
?>
<div class="wk-panel" id="backup">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.settings.backup')) ?></h2><p><?= $e(t('admin.settings.backup_note')) ?></p></hgroup></header>
<?= $notice('backup') ?>
<p class="wk-text-sm wk-mb-3">
<?php if ($backupState['last_success'] > 0): ?><?= $e(t('admin.settings.backup_status', [date('Y-m-d H:i', $backupState['last_success']), $backupState['files'], \Reporion\Support\MetaText::size($backupState['bytes'])])) ?>
<?php else: ?><?= $e(t('admin.settings.backup_status_none')) ?>
<?php endif; ?>
<?php if ($backupState['last_exit'] !== 0): ?> <i class="ph ph-warning"></i> <?= $e(t('admin.settings.backup_status_failed')) ?><?php endif; ?>
</p>
<form action="<?= $b ?>/admin/settings/backup" method="post">
<p class="wk-opts">
<label class="radio"><input type="checkbox" name="<?= $field('backup.enabled') ?>" value="1"<?= $checked('backup.enabled') ?>><span class="dot"></span><span><?= $e(t('admin.settings.backup_enabled')) ?><small><?= $e(t('admin.settings.backup_enabled_help')) ?></small></span></label>
</p>
<div class="wk-form-grid">
<label><?= $e(t('admin.settings.backup_transport')) ?><select class="input" name="<?= $field('backup.transport') ?>"><?php foreach (\Reporion\Service\Backup\RsyncConfig::TRANSPORTS as $option): ?><option value="<?= $e($option) ?>"<?= $option === $transport ? ' selected' : '' ?>><?= $e(t('admin.settings.backup_transport_' . $option)) ?></option><?php endforeach; ?></select><small><?= $e(t('admin.settings.backup_transport_help')) ?></small></label>
<label><?= $e(t('admin.settings.backup_host')) ?><input class="input wk-mono" type="text" name="<?= $field('backup.host') ?>" value="<?= $text('backup.host') ?>" maxlength="253" placeholder="<?= $e(t('admin.settings.backup_host_hint')) ?>" autocomplete="off"><small><?= $e(t('admin.settings.backup_host_help')) ?></small></label>
<label><?= $e(t('admin.settings.backup_port')) ?><input class="input wk-input-num" type="number" min="1" max="65535" name="<?= $field('backup.port') ?>" value="<?= (int) $bk('backup.port', $transport === 'daemon' ? 873 : 22) ?>"></label>
<label><?= $e(t('admin.settings.backup_module')) ?><input class="input wk-mono" type="text" name="<?= $field('backup.module') ?>" value="<?= $text('backup.module') ?>" maxlength="64" placeholder="<?= $e(t('admin.settings.backup_module_hint')) ?>" autocomplete="off"><small><?= $e(t('admin.settings.backup_module_help')) ?></small></label>
<label><?= $e(t('admin.settings.backup_user')) ?><input class="input wk-mono" type="text" name="<?= $field('backup.user') ?>" value="<?= $text('backup.user') ?>" maxlength="32" placeholder="<?= $e(t('admin.settings.backup_user_hint')) ?>" autocomplete="off"><small><?= $e(t('admin.settings.backup_user_help')) ?></small></label>
<label><?= $e(t('admin.settings.backup_remote_dir')) ?><input class="input wk-mono" type="text" name="<?= $field('backup.remote_dir') ?>" value="<?= $text('backup.remote_dir') ?>" maxlength="200" placeholder="<?= $e(t('admin.settings.backup_remote_dir_hint')) ?>"><small><?= $e(t('admin.settings.backup_remote_dir_help')) ?></small></label>
<label><?= $e(t('admin.settings.backup_ssh_key')) ?><input class="input wk-mono" type="text" name="<?= $field('backup.ssh_key') ?>" value="<?= $text('backup.ssh_key') ?>" maxlength="300" placeholder="<?= $e(t('admin.settings.backup_ssh_key_hint')) ?>"><small><?= $e(t('admin.settings.backup_ssh_key_help')) ?></small></label>
<label><?= $e(t('admin.settings.backup_password_file')) ?><input class="input wk-mono" type="text" name="<?= $field('backup.password_file') ?>" value="<?= $text('backup.password_file') ?>" maxlength="300" placeholder="<?= $e(t('admin.settings.backup_password_file_hint')) ?>"><small><?= $e(t('admin.settings.backup_password_file_help')) ?></small></label>
<label><?= $e(t('admin.settings.backup_interval')) ?><input class="input wk-input-num" type="number" min="1" max="720" name="<?= $field('backup.interval_hours') ?>" value="<?= (int) $bk('backup.interval_hours', 24) ?>" required><small><?= $e(t('admin.settings.backup_interval_help')) ?></small></label>
<label><?= $e(t('admin.settings.backup_keep')) ?><select class="input" name="<?= $field('backup.keep') ?>"><?php foreach (\Reporion\Service\Backup\RsyncConfig::KEEP as $option => $days): ?><option value="<?= $e($option) ?>"<?= $option === $keep ? ' selected' : '' ?>><?= $e(t('admin.settings.backup_keep_' . $option)) ?></option><?php endforeach; ?></select><small><?= $e(t('admin.settings.backup_keep_help')) ?></small></label>
<label><?= $e(t('admin.settings.backup_bwlimit')) ?><input class="input wk-input-num" type="number" min="0" max="1000000" name="<?= $field('backup.bwlimit') ?>" value="<?= (int) $bk('backup.bwlimit', 0) ?>"><small><?= $e(t('admin.settings.backup_bwlimit_help')) ?></small></label>
</div>
<p class="wk-opts">
<label class="radio"><input type="checkbox" name="<?= $field('backup.include_conf') ?>" value="1"<?= $checked('backup.include_conf') ?>><span class="dot"></span><span><?= $e(t('admin.settings.backup_include_conf')) ?><small><?= $e(t('admin.settings.backup_include_conf_help')) ?></small></span></label>
</p>
<footer><button class="btn btn-primary" type="submit"><i class="ph ph-check"></i><?= $e(t('admin.settings.save')) ?></button></footer>
</form>
<p class="wk-dim wk-text-sm wk-m0"><?= $e(t('admin.settings.backup_cron')) ?></p>
<pre class="wk-install-fix">7 * * * *  cd <?= $e(\dirname(__DIR__, 3)) ?> &amp;&amp; bin/reporion backup:rsync --if-due</pre>
</div>
