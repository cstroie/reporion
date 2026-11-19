<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Admin settings panel: mcp (POST /admin/settings/mcp). Needs partials/settings/helpers.php included first.
 */
?>
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
