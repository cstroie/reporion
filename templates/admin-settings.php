<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Admin → Settings (Controller\AdminSettingsController): content only.
 * One form per section, each saved on its own; values come from
 * data/settings.yaml, else from conf/local.php (marked as such).
 *
 * Variables in scope: array<string, mixed> $values; array<string, bool> $fromFile;
 * bool $hasFile; string $gateSuggestion; array $backupState (RsyncBackup::state()); string $saved; ?string $error, $errorSection; string $adminTab, $basePath, $mcpUrl
 */

declare(strict_types=1);

/** @var array<string, mixed> $values */
/** @var array<string, bool> $fromFile */
/** @var bool $hasFile */
/** @var string $gateSuggestion */
/** @var array{last_attempt: int, last_success: int, last_exit: int, snapshot: string, files: int, bytes: int} $backupState */
/** @var string $saved */
/** @var ?string $error */
/** @var ?string $errorSection */
/** @var string $basePath */
/** @var string $mcpUrl */

$b = htmlspecialchars($basePath, ENT_QUOTES);
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$source = static fn (string $key): string => $fromFile[$key] ?? false ? '' : ' <span class="wk-mono wk-dim wk-text-xs">' . htmlspecialchars(t('admin.settings.from_local'), ENT_QUOTES) . '</span>';
$notice = static function (string $section) use ($saved, $error, $errorSection, $e): string {
    if ($errorSection === $section && $error !== null) {
        return '<div class="wk-notice wk-mb-3 wk-notice-error" role="alert"><i class="ph ph-warning-octagon"></i><div>' . $e($error) . '</div></div>';
    }

    return $saved === $section ? '<div class="wk-notice wk-mb-3 wk-notice-ok" role="status"><i class="ph ph-check"></i><div>' . $e(t('admin.settings.saved')) . '</div></div>' : '';
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
<header class="wk-doc-head">
<?= \Reporion\Http\Breadcrumb::render([['label' => t('nav.admin')], ['label' => t('admin.settings.title')]]) ?>
<div class="wk-doc-titlerow"><h1 class="wk-doc-title"><?= $e(t('admin.settings.title')) ?></h1></div>
<div class="wk-badges"><span class="wk-mono wk-dim"><?= $e(t($hasFile ? 'admin.settings.explain' : 'admin.settings.explain_none')) ?></span></div>
</header>
<?php include __DIR__ . '/admin-tabs.php'; ?>

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

<div class="wk-panel" id="mcp">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.settings.mcp')) ?></h2></hgroup></header>
<?= $notice('mcp') ?>
<form action="<?= $b ?>/admin/settings/mcp" method="post">
<?php if ($mcpUrl !== ''): ?>
<label><?= $e(t('admin.settings.mcp_url')) ?><input class="input wk-mono" type="text" value="<?= $e($mcpUrl) ?>" readonly onfocus="this.select()"><small><?= $e(t('admin.settings.mcp_url_help')) ?></small></label>
<?php endif; ?>
<div class="wk-form-grid">
<label><?= $e(t('admin.settings.mcp_access')) ?><?= $source('mcp.access') ?><select class="input" name="<?= $field('mcp.access') ?>"><?php foreach (\Reporion\Controller\McpController::ACCESS as $option): ?><option value="<?= $e($option) ?>"<?= $option === (string) ($values['mcp.access'] ?? 'local') ? ' selected' : '' ?>><?= $e(t('admin.settings.mcp_access_' . $option)) ?></option><?php endforeach; ?></select><small><?= $e(t('admin.settings.mcp_access_help')) ?></small></label>
<label><?= $e(t('admin.settings.mcp_gate')) ?><?= $source('mcp.gate') ?><input class="input wk-mono" type="text" name="<?= $field('mcp.gate') ?>" value="<?= $text('mcp.gate') ?>" placeholder="<?= $e($gateSuggestion) ?>" maxlength="128" autocomplete="off" spellcheck="false"><small><?= $e(t('admin.settings.mcp_gate_help')) ?></small></label>
</div>
<p class="wk-opts">
<label class="radio"><input type="checkbox" name="<?= $field('mcp.write_anywhere') ?>" value="1"<?= $checked('mcp.write_anywhere') ?>><span class="dot"></span><span><?= $e(t('admin.settings.mcp_write_anywhere')) ?><?= $source('mcp.write_anywhere') ?></span></label>
<label class="radio"><input type="checkbox" name="<?= $field('mcp.reports') ?>" value="1"<?= $checked('mcp.reports') ?>><span class="dot"></span><span><?= $e(t('admin.settings.mcp_reports')) ?><?= $source('mcp.reports') ?></span></label>
</p>
<?php $gate = (string) ($values['mcp.gate'] ?? ''); if ($gate !== ''): ?>
<p class="wk-dim wk-text-xs"><?= $e(t('admin.settings.mcp_gate_rule')) ?></p>
<pre class="wk-mono wk-text-xs wk-install-fix">lighttpd:
$HTTP["url"] == "<?= $e($basePath) ?>/mcp" {
  $REQUEST_HEADER["X-Access-Token"] != "<?= $e($gate) ?>" {
    url.access-deny = ( "" )
  }
}

nginx:
location = <?= $e($basePath) ?>/mcp {
  if ($http_x_access_token != "<?= $e($gate) ?>") { return 403; }
  # ... as for the other locations
}

Apache:
&lt;Location "<?= $e($basePath) ?>/mcp"&gt;
  &lt;If "%{HTTP:X-Access-Token} != '<?= $e($gate) ?>'"&gt;Require all denied&lt;/If&gt;
&lt;/Location&gt;</pre>
<?php endif; ?>
<footer><button class="btn btn-primary" type="submit"><i class="ph ph-check"></i><?= $e(t('admin.settings.save')) ?></button></footer>
</form>
</div>

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

<div class="wk-panel" id="limits">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.settings.limits')) ?></h2></hgroup></header>
<?= $notice('limits') ?>
<form action="<?= $b ?>/admin/settings/limits" method="post">
<div class="wk-form-grid">
<label><?= $e(t('admin.settings.trash_days')) ?><?= $source('pages.trash_purge_days') ?><input class="input" type="number" min="1" max="3650" name="<?= $field('pages.trash_purge_days') ?>" value="<?= (int) ($values['pages.trash_purge_days'] ?? 30) ?>" required></label>
<label><?= $e(t('admin.settings.media_mb')) ?><?= $source('media.max_bytes') ?><input class="input" type="number" min="1" max="512" name="<?= $field('media.max_bytes') ?>" value="<?= max(1, intdiv((int) ($values['media.max_bytes'] ?? 8 * 1024 * 1024), 1024 * 1024)) ?>" required><small><?= $e(t('admin.settings.media_help')) ?></small></label>
</div>
<footer><button class="btn btn-primary" type="submit"><i class="ph ph-check"></i><?= $e(t('admin.settings.save')) ?></button></footer>
</form>
</div>

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
<pre class="wk-install-fix">7 * * * *  cd <?= $e(\dirname(__DIR__)) ?> &amp;&amp; bin/reporion backup:rsync --if-due</pre>
</div>

<div class="wk-panel" id="reports">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.settings.reports')) ?></h2></hgroup></header>
<?= $notice('reports') ?>
<form action="<?= $b ?>/admin/settings/reports" method="post">
<div class="wk-form-grid">
<label><?= $e(t('admin.settings.modality_namespaces')) ?><?= $source('reports.modality_namespaces') ?><textarea class="input wk-mono wk-text-sm" name="<?= $field('reports.modality_namespaces') ?>" rows="5"><?php foreach ($modalityMap as $modality => $ns): ?><?= $e((string) $modality) ?> = <?= $e((string) $ns) ?>&#10;<?php endforeach; ?></textarea><small><?= $e(t('admin.settings.modality_namespaces_help')) ?></small></label>
<label><?= $e(t('admin.settings.references')) ?><?= $source('references.namespaces') ?><input class="input wk-mono" type="text" name="<?= $field('references.namespaces') ?>" value="<?= $e(implode(', ', (array) ($values['references.namespaces'] ?? []))) ?>" placeholder="<?= $e(t('admin.settings.references_hint')) ?>"><small><?= $e(t('admin.settings.references_help')) ?></small></label>
</div>
<footer><button class="btn btn-primary" type="submit"><i class="ph ph-check"></i><?= $e(t('admin.settings.save')) ?></button></footer>
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
