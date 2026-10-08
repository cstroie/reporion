<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Admin → AI (Controller\AdminAiController) — content only (phase 33): the
 * Assistant panel — the default server's state (its models when checked),
 * on or off, which server, which prompt profile serves where; the six
 * server cards, each with its parameters per alias; and a card per prompt
 * profile (Actions::overview()). API keys are never printed:
 * only whether one is stored.
 *
 * Variables in scope: Reporion\Service\Ai\AiConfig $ai;
 * list<array<string, mixed>> $servers (name, rawName, keySet, endpoint, model, …);
 * array<string, mixed> $status; bool $checked;
 * array<string, mixed> $profiles (names as keys); array<string, array<string, mixed>> $overview;
 * string $saved; ?string $error, $errorSection; string $adminTab, $basePath
 */

declare(strict_types=1);

/** @var Reporion\Service\Ai\AiConfig $ai */
/** @var list<array<string, mixed>> $servers */
/** @var array<string, mixed> $status */
/** @var bool $checked */
/** @var array<string, mixed> $profiles */
/** @var array<string, array<string, mixed>> $overview */
/** @var string $saved */
/** @var ?string $error */
/** @var ?string $errorSection */
/** @var string $basePath */

$b = htmlspecialchars($basePath, ENT_QUOTES);
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$notice = static function (string $section) use ($saved, $error, $errorSection, $e): string {
    if ($errorSection === $section && $error !== null) {
        return '<div class="wk-notice wk-mb-3" role="alert"><i class="ph ph-warning"></i><div>' . $e($error) . '</div></div>';
    }

    return $saved === $section ? '<div class="wk-notice wk-mb-3" role="status"><i class="ph ph-check"></i><div>' . $e(t('admin.settings.saved')) . '</div></div>' : '';
};
$models = $checked && $status['models'] !== [] ? $status['models'] : [];
?>
<div class="wk-doc">
<div class="wk-doc-head">
<?= \Reporion\Http\Breadcrumb::render([['label' => t('nav.admin')], ['label' => t('admin.ai.title')]]) ?>
<h1 class="wk-doc-title"><?= $e(t('admin.ai.title')) ?></h1>
<div class="wk-badges"><span class="wk-mono wk-dim"><?= $e(t('admin.ai.help')) ?></span></div>
</div>
<?php include __DIR__ . '/admin-tabs.php'; ?>

<?php /* Phase 33d: one panel — the state of the default server, on/off, which server, and which prompts serve where */ ?>
<div class="wk-panel" id="use">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.ai.assistant')) ?></h2><p class="wk-dim"><?= $e(t('admin.ai.assistant_help')) ?></p></hgroup>
<form action="<?= $b ?>/admin/ai/check" method="post"><button class="btn btn-secondary btn-sm" type="submit" title="<?= $e(t('admin.ai.check_help')) ?>"><i class="ph ph-plugs-connected" aria-hidden="true"></i><?= $e(t('admin.ai.check')) ?></button></form></header>
<div class="wk-ai-state" data-ok="<?= $status['error'] === null ? '1' : '0' ?>" role="status">
<i class="ph <?= $status['error'] === null ? 'ph-check-circle' : 'ph-warning' ?>" aria-hidden="true"></i>
<div><b><?= $e($status['error'] === null ? t('admin.ai.ready') : (string) $status['error']) ?></b>
<span class="wk-mono wk-dim"><?= $e($ai->serverName) ?> · <?= $e($status['endpoint'] !== '' ? (string) parse_url((string) $status['endpoint'], PHP_URL_HOST) : '—') ?> · <?= $e($status['model'] !== '' ? (string) $status['model'] : '—') ?> · <?= $e(t('admin.ai.key')) ?> <?= $e(t($ai->apiKey !== '' ? 'admin.ai.key_set' : 'admin.ai.key_none')) ?><?= $status['external'] === true ? ' · ' . $e(t('admin.ai.external')) : '' ?><?= $status['egress'] !== null ? ' · ' . $e(t('admin.ai.egress_status')) . ' ' . $e((string) $status['egress']) : '' ?></span>
<?php if ($models !== []): ?><span class="wk-mono wk-dim wk-text-xs"><?= $e(\count($models) > 40 ? t('admin.ai.models_many', [\count($models)]) : implode(', ', $models)) ?></span><?php endif; ?>
</div>
</div>
<?= $notice('use') ?>
<form action="<?= $b ?>/admin/ai/use" method="post">
<div class="wk-ai-use">
<label class="radio"><input type="checkbox" name="ai_enabled" value="1"<?= $ai->enabled ? ' checked' : '' ?>><span class="dot"></span><?= $e(t('admin.ai.enabled')) ?></label>
<label class="wk-ai-use-server"><?= $e(t('admin.ai.server_in_use')) ?><select class="input" name="ai_server">
<?php foreach ($servers as $i => $server): ?><option value="<?= $i + 1 ?>"<?= $ai->server === $i + 1 ? ' selected' : '' ?>><?= $i + 1 ?> · <?= $e((string) $server['name']) ?><?= ($server['endpoint'] ?? '') === '' ? ' — ' . $e(t('admin.ai.empty')) : '' ?></option><?php endforeach; ?>
</select></label>
</div>
<table class="table wk-ai-routes">
<caption class="wk-dim"><?= $e(t('admin.ai.routes')) ?></caption>
<thead><tr><th scope="col"><?= $e(t('admin.ai.routes_pages')) ?></th><th scope="col"><?= $e(t('admin.ai.prompt_profile_in_use')) ?></th></tr></thead>
<tbody>
<tr><td><input class="input wk-mono" type="text" name="ai_namespaces" value="<?= $e(implode(', ', $ai->namespaces)) ?>" required placeholder="reports" aria-label="<?= $e(t('admin.ai.namespaces')) ?>"><small class="wk-dim"><?= $e(t('admin.ai.namespaces_help')) ?></small></td>
<td><select class="input wk-mono" name="ai_prompt_profile" aria-label="<?= $e(t('admin.ai.prompt_profile_in_use')) ?>">
<?php foreach (array_keys($profiles) as $profile): ?><option value="<?= $e((string) $profile) ?>"<?= $ai->promptProfile === (string) $profile ? ' selected' : '' ?>>ai:profiles:<?= $e((string) $profile) ?></option><?php endforeach; ?>
</select></td></tr>
<tr><td class="wk-dim"><?= $e(t('admin.ai.fallback_profile')) ?><small class="wk-dim"><?= $e(t('admin.ai.fallback_profile_help')) ?></small></td>
<td><select class="input wk-mono" name="ai_fallback_profile" aria-label="<?= $e(t('admin.ai.fallback_profile')) ?>">
<option value=""<?= $ai->fallbackProfile === '' ? ' selected' : '' ?>><?= $e(t('admin.ai.fallback_none')) ?></option>
<?php foreach (array_keys($profiles) as $profile): ?><option value="<?= $e((string) $profile) ?>"<?= $ai->fallbackProfile === (string) $profile ? ' selected' : '' ?>>ai:profiles:<?= $e((string) $profile) ?></option><?php endforeach; ?>
</select></td></tr>
</tbody>
</table>
<footer><button class="btn btn-primary" type="submit"><i class="ph ph-check"></i><?= $e(t('admin.settings.save')) ?></button></footer>
</form>
</div>

<div class="wk-panel" id="servers">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.ai.servers')) ?></h2><p class="wk-dim"><?= $e(t('admin.ai.servers_help')) ?></p></hgroup></header>
<?= $notice('servers') ?>
<form action="<?= $b ?>/admin/ai/servers" method="post" autocomplete="off">
<div class="wk-ai-servers">
<?php foreach ($servers as $i => $server): $n = 'servers[' . $i . ']'; $active = $ai->server === $i + 1; ?>
<?php /* Phase 33e: a card folds to one line — slot, name, host, normal model, state; the default server's is open */ ?>
<details class="wk-ai-server"<?= $active ? ' data-on="1" open' : '' ?>>
<summary class="wk-ai-server-h"><span class="wk-ai-slot"><?= $i + 1 ?></span><b><?= $e((string) $server['name']) ?></b>
<span class="wk-mono wk-dim"><?= ($server['endpoint'] ?? '') !== '' ? $e((string) parse_url((string) $server['endpoint'], PHP_URL_HOST)) . ' · ' . $e((string) (($server['tiers']['normal']['model'] ?? '') !== '' ? $server['tiers']['normal']['model'] : '—')) : $e(t('admin.ai.empty')) ?></span>
<?php if ($active): ?><span class="wk-chip wk-chip-on"><?= $e(t('admin.ai.in_use_short')) ?></span><?php endif; ?><?php if (($server['external_ack'] ?? false) === true): ?><span class="wk-chip"><?= $e(t('admin.ai.external')) ?></span><?php endif; ?></summary>
<div class="wk-ai-server-body">
<label><?= $e(t('admin.ai.server_name')) ?><input class="input" type="text" name="<?= $n ?>[name]" value="<?= $e((string) $server['rawName']) ?>" maxlength="40" placeholder="<?= $e('Server ' . ($i + 1)) ?>"></label>
<label><?= $e(t('admin.ai.endpoint')) ?><input class="input wk-mono" type="url" name="<?= $n ?>[endpoint]" value="<?= $e((string) ($server['endpoint'] ?? '')) ?>" placeholder="http://127.0.0.1:8080/v1"></label>
<label><?= $e(t('admin.ai.model_filter')) ?><input class="input wk-mono" type="text" name="<?= $n ?>[model_filter]" value="<?= $e((string) ($server['model_filter'] ?? '')) ?>" maxlength="200" placeholder="free"><small class="wk-dim"><?= $e(t('admin.ai.model_filter_help')) ?></small></label>
<?php /* Phase 33a: one row per parameter, one column per alias; blank is not sent */ ?>
<p class="wk-dim wk-ai-params-help"><?= $e(t('admin.ai.params')) ?></p>
<div class="wk-ai-params-wrap">
<table class="table wk-ai-params">
<thead><tr><th scope="col"><?= $e(t('admin.ai.param')) ?></th><?php foreach (\Reporion\Service\Ai\AiConfig::TIERS as $tier): ?><th scope="col"><?= $e(t('admin.ai.tier.' . $tier)) ?></th><?php endforeach; ?></tr></thead>
<tbody>
<?php foreach (\Reporion\Service\Ai\AiConfig::TIER_FIELDS as $field): ?>
<tr><th scope="row"><?= $e(t('admin.ai.param.' . $field)) ?></th>
<?php foreach (\Reporion\Service\Ai\AiConfig::TIERS as $tier):
    $cell = $server['tiers'][$tier][$field] ?? '';
    $name = $n . '[tiers][' . $tier . '][' . $field . ']';
    $label = t('admin.ai.tier.' . $tier) . ' · ' . t('admin.ai.param.' . $field);
?>
<td><?php if ($field === 'model'): ?><input class="input wk-mono" type="text" name="<?= $e($name) ?>" value="<?= $e((string) $cell) ?>" aria-label="<?= $e($label) ?>" placeholder="<?= $e($tier === 'normal' ? 'qwen2.5:32b' : t('admin.ai.model_as_normal')) ?>" data-ai-model="<?= $i + 1 ?>" list="<?= $active && $models !== [] ? 'ai-models' : 'ai-models-' . ($i + 1) ?>">
<?php elseif ($field === 'extra'): ?><textarea class="input wk-mono" rows="2" name="<?= $e($name) ?>" aria-label="<?= $e($label) ?>" placeholder="<?= $e(t('admin.ai.extra_placeholder')) ?>"><?= $e(\is_array($cell) && $cell !== [] ? (string) json_encode($cell, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (\is_string($cell) ? $cell : '')) ?></textarea>
<?php else: [$step, $min, $max] = ['temperature' => ['0.05', '0', '2'], 'top_p' => ['0.01', '0', '1'], 'min_p' => ['0.01', '0', '1'], 'top_k' => ['1', '1', '1000'], 'max_tokens' => ['1', '0', '200000']][$field]; ?><input class="input" type="number" step="<?= $step ?>" min="<?= $min ?>" max="<?= $max ?>" name="<?= $e($name) ?>" value="<?= $e((string) $cell) ?>" aria-label="<?= $e($label) ?>" placeholder="—"><?php endif; ?></td>
<?php endforeach; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<small class="wk-dim"><?= $e(t('admin.ai.models_help')) ?></small>
<label><?= $e(t('admin.ai.api_key')) ?><input class="input wk-mono" type="password" name="<?= $n ?>[api_key]" value="" autocomplete="new-password" placeholder="<?= $server['keySet'] ? $e(t('admin.ai.api_key_placeholder_set')) : '' ?>"></label>
<label><?= $e(t('admin.ai.timeout')) ?><input class="input" type="number" min="5" max="600" name="<?= $n ?>[timeout]" value="<?= $e((string) ($server['timeout'] ?? 120)) ?>"></label>
<label class="wk-ai-server-check"><span><input type="checkbox" name="<?= $n ?>[external_ack]" value="1"<?= ($server['external_ack'] ?? false) === true ? ' checked' : '' ?>> <?= $e(t('admin.ai.external_ack')) ?></span></label>
<?php if ($server['keySet']): ?><label class="wk-ai-server-check"><span><input type="checkbox" name="<?= $n ?>[remove_api_key]" value="1"> <?= $e(t('admin.ai.remove_api_key')) ?></span></label><?php endif; ?>
<?php /* Phase 33c: from the card's *saved* settings, no report text — assets/js/admin-ai.js; hidden without JavaScript */ ?>
<div class="wk-ai-server-actions" data-ai-card="<?= $i + 1 ?>" hidden>
<button type="button" class="btn btn-secondary btn-sm" data-ai-get-models><i class="ph ph-list-magnifying-glass" aria-hidden="true"></i><?= $e(t('admin.ai.get_models')) ?></button>
<button type="button" class="btn btn-secondary btn-sm" data-ai-test><i class="ph ph-plugs-connected" aria-hidden="true"></i><?= $e(t('admin.ai.test')) ?></button>
<span class="wk-mono wk-dim wk-text-xs" data-ai-out aria-live="polite"></span>
</div>
<div class="wk-ai-test-results" data-ai-results="<?= $i + 1 ?>" hidden></div>
<datalist id="ai-models-<?= $i + 1 ?>"></datalist>
</div>
</details>
<?php endforeach; ?>
</div>
<script type="application/json" id="admin-ai-config"><?= json_encode([
    'basePath' => $basePath,
    'strings' => [
        'working' => t('editor.ai.working'),
        'models' => t('admin.ai.models_found'),
        'failed' => t('admin.ai.request_failed'),
        'listed' => t('admin.ai.test_listed'),
        'unlisted' => t('admin.ai.test_unlisted'),
        'ok' => t('admin.ai.test_ok'),
        'saveFirst' => t('admin.ai.save_first'),
    ],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= $e(\Reporion\Support\Asset::url($basePath, 'js/admin-ai.js')) ?>" defer></script>
<?php if ($models !== []): ?><datalist id="ai-models"><?php foreach ($models as $model): ?><option value="<?= $e((string) $model) ?>"><?php endforeach; ?></datalist><?php endif; ?>
<footer><button class="btn btn-primary" type="submit"><i class="ph ph-check"></i><?= $e(t('admin.settings.save')) ?></button></footer>
</form>
</div>

<?php /* Phase 33e: a card per prompt profile — where it serves, its rail, its reserved prompts, its system prompt (Actions::overview()) */ ?>
<div class="wk-panel" id="prompts">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.ai.prompts')) ?></h2><p class="wk-dim"><?= $e(t('admin.ai.prompts_help')) ?></p></hgroup></header>
<div class="wk-ai-profiles">
<?php foreach ($overview as $profile => $o): $p = (string) $profile; ?>
<section class="wk-ai-profile"<?= $o['serves'] !== '' ? ' data-on="1"' : '' ?>>
<header class="wk-ai-profile-h"><a class="wk-mono" href="<?= $b ?>/<?= $e($o['path']) ?>">ai:profiles:<?= $e($p) ?></a>
<?php if ($o['serves'] === 'main'): ?><span class="wk-chip wk-chip-on"><?= $e(implode(', ', $ai->namespaces)) ?></span><?php endif; ?>
<?php if ($o['serves'] === 'fallback' || $o['fallbackToo']): ?><span class="wk-chip wk-chip-on"><?= $e(t('admin.ai.fallback_profile')) ?></span><?php endif; ?>
<?php if ($o['serves'] === ''): ?><span class="wk-chip"><?= $e(t('admin.ai.unused')) ?></span><?php endif; ?>
<a class="btn btn-secondary btn-sm" href="<?= $b ?>/<?= $e($o['path']) ?>/edit"><i class="ph ph-pencil-simple" aria-hidden="true"></i><?= $e(t('admin.ai.edit_table')) ?></a></header>
<h3 class="wk-eyebrow"><?= $e(t('admin.ai.rail')) ?></h3>
<?php if (!$o['table']): ?><p class="wk-dim wk-text-sm"><?= $e(t('admin.ai.no_table', [$p])) ?></p>
<?php elseif ($o['rail'] === []): ?><p class="wk-dim wk-text-sm"><?= $e(t('admin.ai.rail_empty')) ?></p>
<?php else: ?><ol class="wk-ai-rail">
<?php foreach ($o['rail'] as $row): ?>
<?php if ($row['break']): ?><li class="wk-ai-rail-break" aria-hidden="true"></li><?php continue; endif; ?>
<li<?= $row['present'] ? '' : ' data-missing="1"' ?>><a href="<?= $b ?>/<?= $e($row['path']) ?><?= $row['present'] ? '' : '/edit' ?>"><?= $e($row['label']) ?></a>
<span class="wk-mono wk-dim"><?= $e($row['id']) ?> · <?= $e($row['result']) ?><?= $row['model'] !== '' ? ' · ' . $e($row['model']) : '' ?><?= $row['ownSystem'] ? ' · +system' : '' ?></span>
<?php if (!$row['present']): ?><span class="wk-ai-missing"><i class="ph ph-warning" aria-hidden="true"></i><?= $e(t('admin.ai.no_prompt_page')) ?></span><?php endif; ?></li>
<?php endforeach; ?>
</ol><?php endif; ?>
<h3 class="wk-eyebrow"><?= $e(t('admin.ai.reserved')) ?></h3>
<ul class="wk-ai-reserved">
<?php foreach ($o['reserved'] as $r): ?>
<li<?= $r['present'] ? ' data-on="1"' : '' ?>><i class="ph <?= $r['present'] ? 'ph-check-circle' : 'ph-circle-dashed' ?>" aria-hidden="true"></i><a class="wk-mono" href="<?= $b ?>/<?= $e($r['path']) ?><?= $r['present'] ? '' : '/edit' ?>"><?= $e($r['id']) ?></a>
<span class="wk-dim"><?= $e(t('admin.ai.reserved.' . $r['id'])) ?><?= $r['present'] ? '' : ' — ' . $e(t('admin.ai.reserved_create')) ?></span></li>
<?php endforeach; ?>
</ul>
<h3 class="wk-eyebrow"><?= $e(t('admin.ai.system')) ?></h3>
<p class="wk-text-sm"><?php if ($o['system']['present']): ?><a class="wk-mono" href="<?= $b ?>/<?= $e($o['system']['path']) ?>"><?= $e($o['system']['path']) ?></a><?= $o['system']['fallback'] ? ' <span class="wk-dim">' . $e(t('admin.ai.system_default')) . '</span>' : '' ?><?php else: ?><span class="wk-dim"><?= $e(t('admin.ai.system_none')) ?></span> <a href="<?= $b ?>/<?= $e($o['path']) ?>:system/edit"><?= $e(t('admin.ai.reserved_create')) ?></a><?php endif; ?></p>
<?php if ($o['other'] !== []): ?>
<h3 class="wk-eyebrow"><?= $e(t('admin.ai.other_pages')) ?></h3>
<p class="wk-text-sm"><?php foreach ($o['other'] as $i => $page): ?><?= $i > 0 ? ' · ' : '' ?><a href="<?= $b ?>/<?= $e($page['path']) ?>" title="<?= $e($page['id']) ?>"><?= $e($page['label']) ?></a><?php endforeach; ?></p>
<?php endif; ?>
</section>
<?php endforeach; ?>
</div>
<?php if ($overview === []): ?><p class="wk-dim"><?= $e(t('admin.ai.prompts_empty', [$ai->promptProfile])) ?></p><?php endif; ?>
</div>
</div>
