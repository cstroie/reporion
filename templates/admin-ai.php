<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Admin → AI (Controller\AdminAiController) — content only. The status
 * (and, when asked, the server's models), one form for the assistant's
 * settings, and the prompt profiles with their action pages. The API key is
 * never printed: only whether one is stored.
 *
 * Variables in scope: array<string, mixed> $values; array<string, bool> $fromFile;
 * bool $keySet, $checked, $saved; array<string, mixed> $status;
 * array<string, list<array{id: string, label: string, enabled: bool, path: string}>> $profiles;
 * ?string $error; string $adminTab, $basePath
 */

declare(strict_types=1);

/** @var array<string, mixed> $values */
/** @var array<string, bool> $fromFile */
/** @var bool $keySet */
/** @var bool $checked */
/** @var bool $saved */
/** @var array<string, mixed> $status */
/** @var array<string, list<array{id: string, label: string, enabled: bool, path: string}>> $profiles */
/** @var ?string $error */
/** @var string $basePath */

$b = htmlspecialchars($basePath, ENT_QUOTES);
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$source = static fn (string $key): string => $fromFile[$key] ?? false ? '' : ' <span class="wk-mono wk-dim" style="font-size:13.5px">' . htmlspecialchars(t('admin.settings.from_local'), ENT_QUOTES) . '</span>';
$text = static fn (string $key): string => htmlspecialchars((string) ($values[$key] ?? ''), ENT_QUOTES);
$num = static fn (string $key, float|int $default): string => htmlspecialchars((string) ($values[$key] ?? $default), ENT_QUOTES);
$checkedBox = static fn (string $key): string => ($values[$key] ?? false) ? ' checked' : '';
$field = static fn (string $key): string => str_replace('.', '_', $key);
$aiProfiles = \is_array($values['ai.profiles'] ?? null) && $values['ai.profiles'] !== [] ? $values['ai.profiles'] : ['reports' => 'reports', '*' => 'default'];
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
<span><?= $e(t('admin.ai.ready')) ?></span><b><?= $status['error'] === null ? '<i class="ph ph-check"></i>' . $e(t('admin.ai.ready')) : '<i class="ph ph-warning"></i>' . $e((string) $status['error']) ?></b>
<span><?= $e(t('admin.ai.server')) ?></span><b class="wk-mono"><?= $e($status['endpoint'] !== '' ? $status['endpoint'] : '—') ?> · <?= $e($status['model'] !== '' ? $status['model'] : '—') ?><?= $status['external'] === true ? ' · ' . $e(t('admin.ai.external')) : '' ?></b>
<span><?= $e(t('admin.ai.key')) ?></span><b><?= $e(t($keySet ? 'admin.ai.key_set' : 'admin.ai.key_none')) ?></b>
<?php if ($status['egress'] !== null): ?><span><?= $e(t('admin.ai.egress_status')) ?></span><b class="wk-mono"><?= $e((string) $status['egress']) ?></b><?php endif; ?>
<?php if ($checked && $status['models'] !== []): ?><span><?= $e(t('admin.ai.models')) ?></span><b class="wk-mono"><?= $e(implode(', ', $status['models'])) ?></b><?php endif; ?>
</div>
<form action="<?= $b ?>/admin/ai/check" method="post" style="margin-top:var(--space-3);display:flex;gap:var(--space-3);align-items:center;flex-wrap:wrap">
<button class="btn btn-secondary btn-sm" type="submit"><i class="ph ph-plugs-connected"></i><?= $e(t('admin.ai.check')) ?></button>
<span class="wk-dim" style="font-size:15.5px"><?= $e(t('admin.ai.check_help')) ?></span>
</form>
</div>

<div class="wk-panel" id="settings">
<div class="wk-panel-h"><span class="wk-eyebrow"><?= $e(t('admin.ai.connection')) ?></span></div>
<?php if ($error !== null): ?><div class="wk-notice" role="alert" style="margin-bottom:var(--space-3)"><i class="ph ph-warning"></i><div><?= $e($error) ?></div></div><?php elseif ($saved): ?><div class="wk-notice" role="status" style="margin-bottom:var(--space-3)"><i class="ph ph-check"></i><div><?= $e(t('admin.settings.saved')) ?></div></div><?php endif; ?>
<form action="<?= $b ?>/admin/ai" method="post" autocomplete="off">
<p style="font-size:16.5px;margin:0 0 var(--space-3)"><label><input type="checkbox" name="<?= $field('ai.enabled') ?>" value="1"<?= $checkedBox('ai.enabled') ?>> <?= $e(t('admin.ai.enabled')) ?><?= $source('ai.enabled') ?></label></p>
<div class="wk-form-grid">
<label><?= $e(t('admin.ai.endpoint')) ?><?= $source('ai.endpoint') ?><input class="input wk-mono" type="url" name="<?= $field('ai.endpoint') ?>" value="<?= $text('ai.endpoint') ?>" placeholder="http://127.0.0.1:8080/v1"><small class="wk-dim"><?= $e(t('admin.ai.endpoint_help')) ?></small></label>
<label><?= $e(t('admin.ai.model')) ?><?= $source('ai.model') ?><input class="input wk-mono" type="text" name="<?= $field('ai.model') ?>" value="<?= $text('ai.model') ?>" placeholder="qwen2.5:32b"<?php if ($checked && $status['models'] !== []): ?> list="ai-models"<?php endif; ?>></label>
<label><?= $e(t('admin.ai.api_key')) ?><input class="input wk-mono" type="password" name="ai_api_key" value="" autocomplete="new-password" placeholder="<?= $keySet ? $e(t('admin.ai.api_key_placeholder_set')) : '' ?>"><small class="wk-dim"><?= $e(t('admin.ai.api_key_help')) ?></small></label>
<label><?= $e(t('admin.ai.temperature')) ?><?= $source('ai.temperature') ?><input class="input" type="number" step="0.05" min="0" max="2" name="<?= $field('ai.temperature') ?>" value="<?= $num('ai.temperature', 0.3) ?>"></label>
<label><?= $e(t('admin.ai.top_p')) ?><?= $source('ai.top_p') ?><input class="input" type="number" step="0.05" min="0" max="1" name="<?= $field('ai.top_p') ?>" value="<?= $num('ai.top_p', 0.8) ?>"></label>
<label><?= $e(t('admin.ai.max_tokens')) ?><?= $source('ai.max_tokens') ?><input class="input" type="number" min="0" max="65536" name="<?= $field('ai.max_tokens') ?>" value="<?= $num('ai.max_tokens', 0) ?>"><small class="wk-dim"><?= $e(t('admin.ai.max_tokens_help')) ?></small></label>
<label><?= $e(t('admin.ai.timeout')) ?><?= $source('ai.timeout') ?><input class="input" type="number" min="5" max="600" name="<?= $field('ai.timeout') ?>" value="<?= $num('ai.timeout', 120) ?>"></label>
<label><?= $e(t('admin.ai.profiles')) ?><?= $source('ai.profiles') ?><textarea class="input wk-mono" name="<?= $field('ai.profiles') ?>" rows="3" style="font-size:15.5px"><?php foreach ($aiProfiles as $ns => $profile): ?><?= $e((string) $ns) ?> = <?= $e((string) $profile) ?>&#10;<?php endforeach; ?></textarea><small class="wk-dim"><?= $e(t('admin.ai.profiles_help')) ?></small></label>
<label><?= $e(t('admin.ai.egress')) ?><?= $source('ai.allow_egress_to') ?><input class="input wk-mono" type="text" name="<?= $field('ai.allow_egress_to') ?>" value="<?= $e(implode(', ', (array) ($values['ai.allow_egress_to'] ?? []))) ?>" placeholder="api.example.com"><small class="wk-dim"><?= $e(t('admin.ai.egress_help')) ?></small></label>
</div>
<?php if ($checked && $status['models'] !== []): ?><datalist id="ai-models"><?php foreach ($status['models'] as $model): ?><option value="<?= $e((string) $model) ?>"><?php endforeach; ?></datalist><?php endif; ?>
<p style="font-size:16.5px;margin:var(--space-3) 0 0;display:flex;flex-direction:column;gap:var(--space-2)">
<label><input type="checkbox" name="<?= $field('ai.external_ack') ?>" value="1"<?= $checkedBox('ai.external_ack') ?>> <?= $e(t('admin.ai.external_ack')) ?><?= $source('ai.external_ack') ?></label>
<?php if ($keySet): ?><label><input type="checkbox" name="remove_api_key" value="1"> <?= $e(t('admin.ai.remove_api_key')) ?></label><?php endif; ?>
</p>
<p style="margin:var(--space-3) 0 0"><button class="btn btn-primary btn-sm" type="submit"><i class="ph ph-check"></i><?= $e(t('admin.settings.save')) ?></button></p>
</form>
</div>

<div class="wk-panel" id="prompts">
<div class="wk-panel-h"><span class="wk-eyebrow"><?= $e(t('admin.ai.prompts')) ?></span></div>
<?php foreach ($profiles as $profile => $pages): ?>
<p class="wk-mono" style="font-size:15.5px;margin:0 0 var(--space-2)"><a href="<?= $b ?>/ai:profiles:<?= $e((string) $profile) ?>:">ai:profiles:<?= $e((string) $profile) ?></a></p>
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
