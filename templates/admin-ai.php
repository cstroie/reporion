<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Admin → AI (Controller\AdminAiController) — content only. The status of
 * the server in use (and, when asked, its models); what is in use — on or
 * off, which server, which prompt profile and where; the three servers;
 * and the prompt profiles with their pages. API keys are never printed:
 * only whether one is stored.
 *
 * Variables in scope: Reporion\Service\Ai\AiConfig $ai;
 * list<array<string, mixed>> $servers (name, rawName, keySet, endpoint, model, …);
 * array<string, mixed> $status; bool $checked;
 * array<string, list<array{id: string, label: string, enabled: bool, path: string}>> $profiles;
 * string $saved; ?string $error, $errorSection; string $adminTab, $basePath
 */

declare(strict_types=1);

/** @var Reporion\Service\Ai\AiConfig $ai */
/** @var list<array<string, mixed>> $servers */
/** @var array<string, mixed> $status */
/** @var bool $checked */
/** @var array<string, list<array{id: string, label: string, enabled: bool, path: string}>> $profiles */
/** @var string $saved */
/** @var ?string $error */
/** @var ?string $errorSection */
/** @var string $basePath */

$b = htmlspecialchars($basePath, ENT_QUOTES);
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$notice = static function (string $section) use ($saved, $error, $errorSection, $e): string {
    if ($errorSection === $section && $error !== null) {
        return '<div class="wk-notice" role="alert" style="margin-bottom:var(--space-3)"><i class="ph ph-warning"></i><div>' . $e($error) . '</div></div>';
    }

    return $saved === $section ? '<div class="wk-notice" role="status" style="margin-bottom:var(--space-3)"><i class="ph ph-check"></i><div>' . $e(t('admin.settings.saved')) . '</div></div>' : '';
};
$models = $checked && $status['models'] !== [] ? $status['models'] : [];
?>
<div class="wk-doc">
<div class="wk-doc-head">
<div class="wk-crumbs wk-mono"><b><?= $e(t('nav.admin')) ?></b><span>›</span><span><?= $e(t('admin.ai.title')) ?></span></div>
<h1 class="wk-doc-title"><?= $e(t('admin.ai.title')) ?></h1>
<div class="wk-badges"><span class="wk-mono wk-dim"><?= $e(t('admin.ai.help')) ?></span></div>
</div>
<?php include __DIR__ . '/admin-tabs.php'; ?>

<div class="wk-panel" id="status">
<div class="wk-panel-h"><span class="wk-eyebrow"><?= $e(t('admin.ai.status')) ?></span><span class="wk-mono wk-dim" style="font-size:13.5px">bin/reporion ai:check</span></div>
<div class="wk-kv">
<span><?= $e(t('admin.ai.state')) ?></span><b><?= $status['error'] === null ? '<i class="ph ph-check"></i>' . $e(t('admin.ai.ready')) : '<i class="ph ph-warning"></i>' . $e((string) $status['error']) ?></b>
<span><?= $e(t('admin.ai.server')) ?></span><b><?= $e($ai->serverName) ?> <span class="wk-mono wk-dim"><?= $e($status['endpoint'] !== '' ? $status['endpoint'] : '—') ?> · <?= $e($status['model'] !== '' ? $status['model'] : '—') ?><?= $status['external'] === true ? ' · ' . $e(t('admin.ai.external')) : '' ?></span></b>
<span><?= $e(t('admin.ai.key')) ?></span><b><?= $e(t($ai->apiKey !== '' ? 'admin.ai.key_set' : 'admin.ai.key_none')) ?></b>
<span><?= $e(t('admin.ai.prompt_profile')) ?></span><b class="wk-mono">ai:profiles:<?= $e($ai->promptProfile) ?> <span class="wk-dim">→ <?= $e(implode(', ', $ai->namespaces)) ?></span></b>
<?php if ($status['egress'] !== null): ?><span><?= $e(t('admin.ai.egress_status')) ?></span><b class="wk-mono"><?= $e((string) $status['egress']) ?></b><?php endif; ?>
<?php if ($models !== []): ?><span><?= $e(t('admin.ai.models')) ?></span><b class="wk-mono"><?= $e(\count($models) > 40 ? t('admin.ai.models_many', [\count($models)]) : implode(', ', $models)) ?></b><?php endif; ?>
</div>
<form action="<?= $b ?>/admin/ai/check" method="post" style="margin-top:var(--space-3);display:flex;gap:var(--space-3);align-items:center;flex-wrap:wrap">
<button class="btn btn-secondary btn-sm" type="submit"><i class="ph ph-plugs-connected"></i><?= $e(t('admin.ai.check')) ?></button>
<span class="wk-dim" style="font-size:15.5px"><?= $e(t('admin.ai.check_help')) ?></span>
</form>
</div>

<div class="wk-panel" id="use">
<div class="wk-panel-h"><span class="wk-eyebrow"><?= $e(t('admin.ai.in_use')) ?></span></div>
<?= $notice('use') ?>
<form action="<?= $b ?>/admin/ai/use" method="post">
<p style="font-size:16.5px;margin:0 0 var(--space-3)"><label><input type="checkbox" name="ai_enabled" value="1"<?= $ai->enabled ? ' checked' : '' ?>> <?= $e(t('admin.ai.enabled')) ?></label></p>
<div class="wk-form-grid">
<label><?= $e(t('admin.ai.server_in_use')) ?><select class="input" name="ai_server">
<?php foreach ($servers as $i => $server): ?><option value="<?= $i + 1 ?>"<?= $ai->server === $i + 1 ? ' selected' : '' ?>><?= $i + 1 ?> · <?= $e((string) $server['name']) ?><?= ($server['endpoint'] ?? '') === '' ? ' — ' . $e(t('admin.ai.empty')) : '' ?></option><?php endforeach; ?>
</select></label>
<label><?= $e(t('admin.ai.prompt_profile_in_use')) ?><select class="input wk-mono" name="ai_prompt_profile">
<?php foreach (array_keys($profiles) as $profile): ?><option value="<?= $e((string) $profile) ?>"<?= $ai->promptProfile === (string) $profile ? ' selected' : '' ?>>ai:profiles:<?= $e((string) $profile) ?></option><?php endforeach; ?>
</select><small class="wk-dim"><?= $e(t('admin.ai.prompt_profile_help')) ?></small></label>
<label><?= $e(t('admin.ai.namespaces')) ?><input class="input wk-mono" type="text" name="ai_namespaces" value="<?= $e(implode(', ', $ai->namespaces)) ?>" required placeholder="reports"><small class="wk-dim"><?= $e(t('admin.ai.namespaces_help')) ?></small></label>
</div>
<p style="margin:var(--space-3) 0 0"><button class="btn btn-primary btn-sm" type="submit"><i class="ph ph-check"></i><?= $e(t('admin.settings.save')) ?></button></p>
</form>
</div>

<div class="wk-panel" id="servers">
<div class="wk-panel-h"><span class="wk-eyebrow"><?= $e(t('admin.ai.servers')) ?></span></div>
<?= $notice('servers') ?>
<form action="<?= $b ?>/admin/ai/servers" method="post" autocomplete="off">
<div class="wk-ai-servers">
<?php foreach ($servers as $i => $server): $n = 'servers[' . $i . ']'; $active = $ai->server === $i + 1; ?>
<fieldset class="wk-ai-server"<?= $active ? ' data-on="1"' : '' ?>>
<legend class="wk-mono"><?= $i + 1 ?> · <?= $e((string) $server['name']) ?><?= $active ? ' · ' . $e(t('admin.ai.in_use_short')) : '' ?></legend>
<label><?= $e(t('admin.ai.server_name')) ?><input class="input" type="text" name="<?= $n ?>[name]" value="<?= $e((string) $server['rawName']) ?>" maxlength="40" placeholder="<?= $e('Server ' . ($i + 1)) ?>"></label>
<label><?= $e(t('admin.ai.endpoint')) ?><input class="input wk-mono" type="url" name="<?= $n ?>[endpoint]" value="<?= $e((string) ($server['endpoint'] ?? '')) ?>" placeholder="http://127.0.0.1:8080/v1"></label>
<label><?= $e(t('admin.ai.model')) ?><input class="input wk-mono" type="text" name="<?= $n ?>[model]" value="<?= $e((string) ($server['model'] ?? '')) ?>" placeholder="qwen2.5:32b"<?= $active && $models !== [] ? ' list="ai-models"' : '' ?>></label>
<label><?= $e(t('admin.ai.api_key')) ?><input class="input wk-mono" type="password" name="<?= $n ?>[api_key]" value="" autocomplete="new-password" placeholder="<?= $server['keySet'] ? $e(t('admin.ai.api_key_placeholder_set')) : '' ?>"></label>
<div class="wk-ai-server-row">
<label><?= $e(t('admin.ai.temperature')) ?><input class="input" type="number" step="0.05" min="0" max="2" name="<?= $n ?>[temperature]" value="<?= $e((string) ($server['temperature'] ?? 0.3)) ?>"></label>
<label><?= $e(t('admin.ai.top_p')) ?><input class="input" type="number" step="0.05" min="0" max="1" name="<?= $n ?>[top_p]" value="<?= $e((string) ($server['top_p'] ?? 0.8)) ?>"></label>
</div>
<div class="wk-ai-server-row">
<label><?= $e(t('admin.ai.max_tokens')) ?><input class="input" type="number" min="0" max="65536" name="<?= $n ?>[max_tokens]" value="<?= $e((string) ($server['max_tokens'] ?? 0)) ?>"></label>
<label><?= $e(t('admin.ai.timeout')) ?><input class="input" type="number" min="5" max="600" name="<?= $n ?>[timeout]" value="<?= $e((string) ($server['timeout'] ?? 120)) ?>"></label>
</div>
<label class="wk-ai-server-check"><span><input type="checkbox" name="<?= $n ?>[external_ack]" value="1"<?= ($server['external_ack'] ?? false) === true ? ' checked' : '' ?>> <?= $e(t('admin.ai.external_ack')) ?></span></label>
<?php if ($server['keySet']): ?><label class="wk-ai-server-check"><span><input type="checkbox" name="<?= $n ?>[remove_api_key]" value="1"> <?= $e(t('admin.ai.remove_api_key')) ?></span></label><?php endif; ?>
</fieldset>
<?php endforeach; ?>
</div>
<?php if ($models !== []): ?><datalist id="ai-models"><?php foreach ($models as $model): ?><option value="<?= $e((string) $model) ?>"><?php endforeach; ?></datalist><?php endif; ?>
<p class="wk-dim" style="font-size:15.5px;margin:var(--space-3) 0 0"><?= $e(t('admin.ai.servers_help')) ?></p>
<p style="margin:var(--space-3) 0 0"><button class="btn btn-primary btn-sm" type="submit"><i class="ph ph-check"></i><?= $e(t('admin.settings.save')) ?></button></p>
</form>
</div>

<div class="wk-panel" id="prompts">
<div class="wk-panel-h"><span class="wk-eyebrow"><?= $e(t('admin.ai.prompts')) ?></span></div>
<?php foreach ($profiles as $profile => $pages): ?>
<p class="wk-mono" style="font-size:15.5px;margin:0 0 var(--space-2)"><a href="<?= $b ?>/ai:profiles:<?= $e((string) $profile) ?>:">ai:profiles:<?= $e((string) $profile) ?></a><?php if ($ai->promptProfile === (string) $profile): ?> <span class="wk-chip wk-chip-on"><?= $e(t('admin.ai.in_use_short')) ?></span><?php endif; ?></p>
<?php if ($pages === []): ?>
<p class="wk-dim" style="font-size:15.5px;margin:0 0 var(--space-4)"><?= $e(t('admin.ai.prompts_empty', [(string) $profile])) ?></p>
<?php else: ?>
<table class="table" style="margin-bottom:var(--space-4)">
<tbody>
<?php foreach ($pages as $page): ?>
<tr>
<td class="wk-mono"><a href="<?= $b ?>/<?= $e($page['path']) ?>"><?= $e($page['id']) ?></a></td>
<td><?= $e($page['id'] === 'system' ? t('admin.ai.system') : $page['label']) ?></td>
<td class="wk-mono wk-dim"><?= $page['enabled'] ? '' : $e(t('admin.ai.off')) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
<?php endforeach; ?>
</div>
</div>
