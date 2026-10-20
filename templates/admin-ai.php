<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Admin → AI (Controller\AdminAiController): content only (phase 33): the
 * Assistant panel: the default server's state (its models when checked),
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
/** @var int|string|null $promptsMade */
/** @var int|string|null $promptsKept */
/** @var string $basePath */

$b = htmlspecialchars($basePath, ENT_QUOTES);
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$notice = static function (string $section) use ($saved, $error, $errorSection, $e): string {
    if ($errorSection === $section && $error !== null) {
        return '<div class="wk-notice wk-mb-3 wk-notice-error" role="alert"><i class="ph ph-warning-octagon"></i><div>' . $e($error) . '</div></div>';
    }

    return $saved === $section ? '<div class="wk-notice wk-mb-3 wk-notice-ok" role="status"><i class="ph ph-check"></i><div>' . $e(t('admin.settings.saved')) . '</div></div>' : '';
};
$models = $checked && $status['models'] !== [] ? $status['models'] : [];
?>
<div class="wk-doc">
<header class="wk-doc-head">
<?= \Reporion\Http\Breadcrumb::render([['label' => t('nav.admin')], ['label' => t('admin.ai.title')]]) ?>
<div class="wk-doc-titlerow"><h1 class="wk-doc-title"><?= $e(t('admin.ai.title')) ?></h1></div>
<div class="wk-badges"><span class="wk-mono wk-dim"><?= $e(t('admin.ai.help')) ?></span></div>
</header>
<?php include __DIR__ . '/admin-tabs.php'; ?>

<?php /* Phase 33d: one panel: the state of the default server, on/off, which server, and which prompts serve where */ ?>
<div class="wk-panel" id="use">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.ai.assistant')) ?></h2><p class="wk-dim"><?= $e(t('admin.ai.assistant_help')) ?></p></hgroup>
<span class="wk-actions">
<form action="<?= $b ?>/admin/ai/enabled" method="post" data-autosubmit>
<span class="seg seg-sm" role="radiogroup" aria-label="<?= $e(t('admin.ai.enabled')) ?>">
<label class="seg-opt"><input type="radio" name="ai_enabled" value="0"<?= $ai->enabled ? '' : ' checked' ?>><?= $e(t('admin.plugins.disabled')) ?></label>
<label class="seg-opt"><input type="radio" name="ai_enabled" value="1"<?= $ai->enabled ? ' checked' : '' ?>><?= $e(t('admin.plugins.enabled')) ?></label>
</span>
<noscript><button class="btn btn-secondary btn-sm" type="submit"><i class="ph ph-check" aria-hidden="true"></i><?= $e(t('admin.plugins.apply')) ?></button></noscript>
</form>
</span></header>
<div class="wk-ai-state" data-ok="<?= $status['error'] === null ? '1' : '0' ?>" role="status">
<i class="ph <?= $status['error'] === null ? 'ph-check-circle' : 'ph-warning' ?>" aria-hidden="true"></i>
<div><b><?= $e($status['error'] === null ? t('admin.ai.ready') : (string) $status['error']) ?></b>
<span class="wk-mono wk-dim"><?= $e((string) ($status['serverName'] ?? $ai->serverName)) ?> · <?= $e($status['endpoint'] !== '' ? (string) parse_url((string) $status['endpoint'], PHP_URL_HOST) : 'n/a') ?> · <?= $e($status['model'] !== '' ? (string) $status['model'] : 'n/a') ?> · <?= $e(t('admin.ai.key')) ?> <?= $e(t($ai->apiKey !== '' ? 'admin.ai.key_set' : 'admin.ai.key_none')) ?><?= $status['external'] === true ? ' · ' . $e(t('admin.ai.external')) : '' ?><?= $status['egress'] !== null ? ' · ' . $e(t('admin.ai.egress_status')) . ' ' . $e((string) $status['egress']) : '' ?></span>
<?php if ($models !== []): ?><span class="wk-mono wk-dim wk-text-xs"><?= $e(\count($models) > 40 ? t('admin.ai.models_many', [\count($models)]) : implode(', ', $models)) ?></span><?php endif; ?>
</div>
</div>
<?php /* The default server, one joined full-width bar (.group, like the PACS search): Save keeps the chosen server, Check asks the chosen one (saved or not) for its models */
$pickedServer = (int) ($status['serverSlot'] ?? $ai->server); ?>
<form class="group group-fill group-stack wk-ai-toolbar" data-busy action="<?= $b ?>/admin/ai/server" method="post" aria-label="<?= $e(t('admin.ai.server_in_use')) ?>">
<label class="group-addon" for="ai-server" title="<?= $e(t('admin.ai.server_in_use_help')) ?>"><i class="ph ph-hard-drives" aria-hidden="true"></i>&nbsp;<?= $e(t('admin.ai.server_in_use')) ?></label>
<select class="input grow" id="ai-server" name="ai_server">
<?php foreach ($servers as $i => $server): ?><option value="<?= $i + 1 ?>"<?= $pickedServer === $i + 1 ? ' selected' : '' ?>><?= $i + 1 ?> · <?= $e((string) $server['name']) ?><?= ($server['endpoint'] ?? '') === '' ? ': ' . $e(t('admin.ai.empty')) : '' ?></option><?php endforeach; ?>
</select>
<button class="btn btn-secondary" type="submit" formaction="<?= $b ?>/admin/ai/check" title="<?= $e(t('admin.ai.check_help')) ?>"><i class="ph ph-plugs-connected" aria-hidden="true"></i><?= $e(t('admin.ai.check')) ?></button>
<button class="btn btn-primary" type="submit"><i class="ph ph-check" aria-hidden="true"></i><?= $e(t('admin.settings.save')) ?></button>
</form>
<?= $notice('use') ?>
<form id="ai-use-form" data-busy action="<?= $b ?>/admin/ai/use" method="post">
<?php /* Two groups on one field grid: label, control, help, so the controls line up */ ?>
<fieldset class="wk-ai-group">
<legend class="wk-eyebrow"><?= $e(t('admin.ai.routes')) ?></legend>
<?php /* One table for "which prompts serve which pages": a row per namespace (the most specific wins), the last row for every other page. Saved as ai.namespaces, ai.prompt_profile, ai.profile_map and ai.fallback_profile (AdminAiController::routeChanges()). Two blank rows to add to without JavaScript */ ?>
<p class="wk-dim wk-text-sm wk-ai-routes-help"><?= $e(t('admin.ai.routes_help')) ?></p>
<table class="table table-cards table-cards-form wk-ai-routes">
<thead><tr><th scope="col"><?= $e(t('admin.ai.route_ns')) ?></th><th scope="col"><?= $e(t('admin.ai.route_profile')) ?></th></tr></thead>
<tbody>
<?php $routeRows = []; foreach ($routes as $ns => $profile) { $routeRows[] = [(string) $ns, (string) $profile]; } $routeRows[] = ['', $ai->promptProfile]; $routeRows[] = ['', $ai->promptProfile]; ?>
<?php foreach ($routeRows as $r => [$ns, $routeProfile]): ?>
<tr>
<td data-label="<?= $e(t('admin.ai.route_ns')) ?>"><input class="input wk-mono" type="text" name="route_ns[]" value="<?= $e($ns) ?>" placeholder="<?= $e($ns === '' ? t('admin.ai.route_ns_hint') : '') ?>" aria-label="<?= $e(t('admin.ai.route_ns')) ?>" autocomplete="off"></td>
<td data-label="<?= $e(t('admin.ai.route_profile')) ?>"><select class="input" name="route_profile[]" aria-label="<?= $e(t('admin.ai.route_profile')) ?>">
<?php foreach (array_keys($profiles) as $profile): ?><option value="<?= $e((string) $profile) ?>"<?= $routeProfile === (string) $profile ? ' selected' : '' ?>>ai:profiles:<?= $e((string) $profile) ?></option><?php endforeach; ?>
</select></td>
</tr>
<?php endforeach; ?>
<tr class="wk-ai-routes-else">
<td data-label="<?= $e(t('admin.ai.route_ns')) ?>"><b><?= $e(t('admin.ai.route_else')) ?></b></td>
<td data-label="<?= $e(t('admin.ai.route_profile')) ?>"><select class="input" name="route_else" aria-label="<?= $e(t('admin.ai.route_else')) ?>">
<option value=""<?= $ai->fallbackProfile === '' ? ' selected' : '' ?>><?= $e(t('admin.ai.fallback_none')) ?></option>
<?php foreach (array_keys($profiles) as $profile): ?><option value="<?= $e((string) $profile) ?>"<?= $ai->fallbackProfile === (string) $profile ? ' selected' : '' ?>>ai:profiles:<?= $e((string) $profile) ?></option><?php endforeach; ?>
</select></td>
</tr>
</tbody>
</table>
<small class="wk-dim wk-ai-routes-note"><?= $e(t('admin.ai.routes_note')) ?></small>
</fieldset>
<footer><button class="btn btn-primary" type="submit"><i class="ph ph-check"></i><?= $e(t('admin.settings.save')) ?></button></footer>
</form>
</div>

<?php /* Phase 34e: Similar reports: one embedding model for the instance, on one of the servers below. Its own panel and form (2026-10-14): it saves ai.embed_* alone */ ?>
<div class="wk-panel" id="similar">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.ai.embed')) ?></h2><p class="wk-dim"><?= $e(t('admin.ai.similar_help')) ?></p></hgroup></header>
<?= $notice('similar') ?>
<form data-busy action="<?= $b ?>/admin/ai/similar" method="post">
<div class="wk-ai-grid">
<div class="field"><label for="ai-embed-server"><?= $e(t('admin.ai.embed_server')) ?>
<select class="input" id="ai-embed-server" name="ai_embed_server">
<option value=""<?= ($embedServer ?? 0) === 0 ? ' selected' : '' ?>><?= $e(t('admin.ai.embed_none')) ?></option>
<?php foreach ($servers as $i => $server): ?><option value="<?= $i + 1 ?>"<?= ($embedServer ?? 0) === $i + 1 ? ' selected' : '' ?>><?= $i + 1 ?> · <?= $e((string) $server['name']) ?><?= ($server['endpoint'] ?? '') === '' ? ': ' . $e(t('admin.ai.empty')) : '' ?></option><?php endforeach; ?>
</select><small><?= $e(t('admin.ai.embed_help')) ?></small></label></div>
<div class="field"><label for="ai-embed-model"><?= $e(t('admin.ai.embed_model')) ?>
<input class="input" id="ai-embed-model" type="text" name="ai_embed_model" value="<?= $e((string) ($embedModel ?? '')) ?>" placeholder="<?= $e(t('admin.ai.embed_model_hint')) ?>" list="ai-embed-models" autocomplete="off" data-ai-embed-model><datalist id="ai-embed-models"></datalist><small data-ai-embed-out><?= $e(t('admin.ai.embed_model_help')) ?></small></label></div>
<div class="field"><label for="ai-embed-min-score"><?= $e(t('admin.ai.embed_min_score')) ?>
<input class="input" id="ai-embed-min-score" type="number" name="ai_embed_min_score" value="<?= $e((string) ($embedMinScore ?? '')) ?>" min="0" max="1" step="0.01" placeholder="<?= $e((string) \Reporion\Service\Ai\Embedder::DEFAULT_MIN_SCORE) ?>" inputmode="decimal"><small><?= $e(t('admin.ai.embed_min_score_help')) ?></small></label></div>
<div class="field"><label for="ai-embed-common-min"><?= $e(t('admin.ai.embed_common_min')) ?>
<input class="input" id="ai-embed-common-min" type="number" name="ai_embed_common_min" value="<?= $e((string) ($embedCommonMin ?? '')) ?>" min="0" max="10000" step="1" placeholder="<?= $e((string) \Reporion\Service\Ai\Embedder::DEFAULT_COMMON_MIN) ?>" inputmode="numeric"><small><?= $e(t('admin.ai.embed_common_min_help')) ?></small></label></div>
</div>
<footer><button class="btn btn-primary" type="submit"><i class="ph ph-check"></i><?= $e(t('admin.settings.save')) ?></button></footer>
</form>
</div>

<div class="wk-panel" id="servers">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.ai.servers')) ?></h2><p class="wk-dim"><?= $e(t('admin.ai.servers_help')) ?></p></hgroup></header>
<?= $notice('servers') ?>
<form action="<?= $b ?>/admin/ai/servers" method="post" autocomplete="off">
<div class="wk-ai-servers">
<?php foreach ($servers as $i => $server): $n = 'servers[' . $i . ']'; $active = $ai->server === $i + 1; ?>
<?php /* Phase 33e: a card folds to one line: slot, name, host, normal model, state; the default server's is open */ ?>
<details class="wk-ai-server" name="ai-server"<?= $active ? ' data-on="1" open' : '' ?>>
<summary class="wk-ai-server-h"><span class="wk-ai-slot"><?= $i + 1 ?></span><b><?= $e((string) $server['name']) ?></b>
<span class="wk-mono wk-dim"><?= ($server['endpoint'] ?? '') !== '' ? $e((string) parse_url((string) $server['endpoint'], PHP_URL_HOST)) . ' · ' . $e((string) (($server['tiers']['normal']['model'] ?? '') !== '' ? $server['tiers']['normal']['model'] : 'n/a')) : $e(t('admin.ai.empty')) ?></span>
<?php if ($active): ?><span class="wk-chip wk-chip-on"><?= $e(t('admin.ai.in_use_short')) ?></span><?php endif; ?><?php if (($server['external_ack'] ?? false) === true): ?><span class="wk-chip"><?= $e(t('admin.ai.external')) ?></span><?php endif; ?></summary>
<div class="wk-ai-server-body">
<label><?= $e(t('admin.ai.server_name')) ?><input class="input" type="text" name="<?= $n ?>[name]" value="<?= $e((string) $server['rawName']) ?>" maxlength="40" placeholder="<?= $e('Server ' . ($i + 1)) ?>"></label>
<label><?= $e(t('admin.ai.endpoint')) ?><input class="input wk-mono" type="url" name="<?= $n ?>[endpoint]" value="<?= $e((string) ($server['endpoint'] ?? '')) ?>" placeholder="<?= $e(t('admin.ai.endpoint_hint')) ?>"><small><?= $e(t('admin.ai.endpoint_help')) ?></small></label>
<label><?= $e(t('admin.ai.model_filter')) ?><input class="input wk-mono" type="text" name="<?= $n ?>[model_filter]" value="<?= $e((string) ($server['model_filter'] ?? '')) ?>" maxlength="200" placeholder="<?= $e(t('admin.ai.model_filter_hint')) ?>"><small><?= $e(t('admin.ai.model_filter_help')) ?></small></label>
<?php /* Phase 33a: one row per parameter, one column per alias; blank is not sent */ ?>
<p class="wk-dim wk-ai-params-help"><?= $e(t('admin.ai.params')) ?></p>
<div class="wk-ai-params-wrap">
<table class="table wk-ai-params table-cards table-cards-form">
<thead><tr><th scope="col"><?= $e(t('admin.ai.param')) ?></th><?php foreach (\Reporion\Service\Ai\AiConfig::TIERS as $tier): ?><th scope="col"><?= $e(t('admin.ai.tier.' . $tier)) ?></th><?php endforeach; ?></tr></thead>
<tbody>
<?php foreach (\Reporion\Service\Ai\AiConfig::TIER_FIELDS as $field): ?>
<tr><th scope="row"><?= $e(t('admin.ai.param.' . $field)) ?></th>
<?php foreach (\Reporion\Service\Ai\AiConfig::TIERS as $tier):
    $cell = $server['tiers'][$tier][$field] ?? '';
    $name = $n . '[tiers][' . $tier . '][' . $field . ']';
    $label = t('admin.ai.tier.' . $tier) . ' · ' . t('admin.ai.param.' . $field);
?>
<td data-label="<?= $e(t('admin.ai.tier.' . $tier)) ?>"><?php if ($field === 'model'): ?><input class="input wk-mono" type="text" name="<?= $e($name) ?>" value="<?= $e((string) $cell) ?>" aria-label="<?= $e($label) ?>" placeholder="<?= $e($tier === 'normal' ? 'qwen2.5:32b' : t('admin.ai.model_hint')) ?>" data-ai-model="<?= $i + 1 ?>" list="<?= $active && $models !== [] ? 'ai-models' : 'ai-models-' . ($i + 1) ?>">
<?php elseif ($field === 'extra'): ?><textarea class="input wk-mono" rows="2" name="<?= $e($name) ?>" aria-label="<?= $e($label) ?>" placeholder="<?= $e(t('admin.ai.extra_hint')) ?>"><?= $e(\is_array($cell) && $cell !== [] ? (string) json_encode($cell, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (\is_string($cell) ? $cell : '')) ?></textarea>
<?php else: [$step, $min, $max] = ['temperature' => ['0.05', '0', '2'], 'top_p' => ['0.01', '0', '1'], 'min_p' => ['0.01', '0', '1'], 'top_k' => ['1', '1', '1000'], 'max_tokens' => ['1', '0', '200000']][$field]; ?><input class="input" type="number" step="<?= $step ?>" min="<?= $min ?>" max="<?= $max ?>" name="<?= $e($name) ?>" value="<?= $e((string) $cell) ?>" aria-label="<?= $e($label) ?>" placeholder="n/a"><?php endif; ?></td>
<?php endforeach; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<small class="wk-dim"><?= $e(t('admin.ai.models_help')) ?></small>
<label><?= $e(t('admin.ai.api_key')) ?><input class="input wk-mono" type="password" name="<?= $n ?>[api_key]" value="" autocomplete="new-password" placeholder="<?= $server['keySet'] ? $e(t('admin.ai.api_key_hint')) : '' ?>"></label>
<div class="wk-ai-server-row">
<label><?= $e(t('admin.ai.timeout')) ?><input class="input" type="number" min="5" max="600" name="<?= $n ?>[timeout]" value="<?= $e((string) ($server['timeout'] ?? 120)) ?>"></label>
<?php /* Phase 34f: another server when this one cannot answer: unreachable, timeout, 5xx, before any text */ ?>
<label><?= $e(t('admin.ai.fallback')) ?><select class="input" name="<?= $n ?>[fallback]">
<option value=""><?= $e(t('admin.ai.fallback_none_server')) ?></option>
<?php foreach ($servers as $j => $other): if ($j === $i) { continue; } ?><option value="<?= $j + 1 ?>"<?= (string) ($server['fallback'] ?? '') === (string) ($j + 1) ? ' selected' : '' ?>><?= $j + 1 ?> · <?= $e((string) $other['name']) ?><?= ($other['endpoint'] ?? '') === '' ? ': ' . $e(t('admin.ai.empty')) : '' ?></option><?php endforeach; ?>
</select></label>
</div>
<small class="wk-dim"><?= $e(t('admin.ai.fallback_help')) ?></small>
<label class="wk-ai-server-check"><span><input type="checkbox" name="<?= $n ?>[external_ack]" value="1"<?= ($server['external_ack'] ?? false) === true ? ' checked' : '' ?>> <?= $e(t('admin.ai.external_ack')) ?></span></label>
<?php if ($server['keySet']): ?><label class="wk-ai-server-check"><span><input type="checkbox" name="<?= $n ?>[remove_api_key]" value="1"> <?= $e(t('admin.ai.remove_api_key')) ?></span></label><?php endif; ?>
<?php /* Phase 33c: from the card's *saved* settings, no report text: assets/js/admin-ai.js; hidden without JavaScript */ ?>
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
        'embedModels' => t('admin.ai.embed_models_found'),
    ],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= $e(\Reporion\Support\Asset::url($basePath, 'js/admin-ai.js')) ?>" defer></script>
<?php if ($models !== []): ?><datalist id="ai-models"><?php foreach ($models as $model): ?><option value="<?= $e((string) $model) ?>"><?php endforeach; ?></datalist><?php endif; ?>
<footer><button class="btn btn-primary" type="submit"><i class="ph ph-check"></i><?= $e(t('admin.settings.save')) ?></button></footer>
</form>
</div>

<?php /* Phase 33e: a card per prompt profile, where it serves, its rail, its special prompts, its system prompt (Actions::overview()) */ ?>
<div class="wk-panel" id="prompts">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.ai.prompts')) ?></h2><p class="wk-dim"><?= $e(t('admin.ai.prompts_help')) ?></p></hgroup></header>
<?php if ($promptsMade !== null): ?><div class="wk-notice wk-mb-3 wk-notice-ok" role="status"><i class="ph ph-check"></i><div><?= $e(t('admin.ai.prompts_made', [(int) $promptsMade, (int) $promptsKept])) ?></div></div><?php endif; ?>
<?= $notice('prompts') ?>
<div class="wk-ai-profiles">
<?php foreach ($overview as $profile => $o): $p = (string) $profile; ?>
<?php $mappedHere = array_keys($ai->profileMap, $p, true); $on = array_values(array_filter($o['reserved'], static fn (array $r): bool => $r['present'])); $off = array_values(array_filter($o['reserved'], static fn (array $r): bool => !$r['present'] && \in_array($o['target'], \Reporion\Service\Ai\DefaultPrompts::CATALOGUE[$r['id']]['targets'] ?? [], true))); /* a feature made for the other target (Recall in a generic profile) is not offered; one already set up still shows */ ?>
<section class="wk-ai-profile"<?= $o['serves'] !== '' ? ' data-on="1"' : '' ?>>
<header class="wk-ai-profile-h">
<h3><a href="<?= $b ?>/<?= $e($o['path']) ?>"><?= $e($p) ?></a></h3>
<a class="btn btn-secondary btn-sm" href="<?= $b ?>/<?= $e($o['path']) ?>/edit"><i class="ph ph-pencil-simple" aria-hidden="true"></i><?= $e(t('admin.ai.edit_table')) ?></a>
<p class="wk-ai-serves wk-dim"><?php if ($o['serves'] === ''): ?><?= $e(t('admin.ai.unused')) ?><?php else: ?><?= $e(t('admin.ai.serves')) ?>
<?php if ($o['serves'] === 'main'): foreach ($ai->namespaces as $ns): ?><span class="wk-chip wk-chip-on"><?= $e((string) $ns) ?></span> <?php endforeach; endif; ?>
<?php foreach ($mappedHere as $ns): ?><span class="wk-chip wk-chip-on"><?= $e((string) $ns) ?></span> <?php endforeach; ?>
<?php if ($o['serves'] === 'fallback' || $o['fallbackToo']): ?><span class="wk-chip wk-chip-on"><?= $e(t('admin.ai.fallback_profile')) ?></span><?php endif; ?>
<?php endif; ?></p>
</header>

<h4><?= $e(t('admin.ai.rail')) ?></h4>
<?php if (!$o['table']): ?><p class="wk-dim wk-text-sm"><?= $e(t('admin.ai.no_table', [$p])) ?></p>
<?php elseif ($o['rail'] === []): ?><p class="wk-dim wk-text-sm"><?= $e(t('admin.ai.rail_empty')) ?></p>
<?php else: ?><ul class="wk-ai-rail">
<?php foreach ($o['rail'] as $row): ?>
<?php if ($row['break']): ?><li class="wk-ai-rail-break"<?= $row['label'] === '' ? ' aria-hidden="true"' : '' ?>><?= $e($row['label']) ?></li><?php continue; endif; ?>
<?php $tip = $row['id'] . ' · ' . $row['result'] . ($row['model'] !== '' ? ' · ' . $row['model'] : '') . ($row['ownSystem'] ? ' · +system' : '') . ($row['present'] ? '' : ' · ' . t('admin.ai.no_prompt_page')); ?>
<li><a class="wk-ai-act"<?= $row['present'] ? '' : ' data-missing="1"' ?> href="<?= $b ?>/<?= $e($row['path']) ?><?= $row['present'] ? '' : '/edit' ?>" title="<?= $e($tip) ?>"><?= $row['present'] ? '' : '<i class="ph ph-warning" aria-hidden="true"></i>' ?><?= $e($row['label']) ?><small><?= $e($row['result']) ?></small></a></li>
<?php endforeach; ?>
</ul><?php endif; ?>

<h4><?= $e(t('admin.ai.reserved')) ?> <span class="wk-dim"><?= $e(t('admin.ai.features_on', [\count($on), \count($on) + \count($off)])) ?></span></h4>
<?php if ($on !== []): ?><ul class="wk-ai-features">
<?php foreach ($on as $r): ?>
<li data-on="1"><i class="ph ph-check-circle" aria-hidden="true"></i><a href="<?= $b ?>/<?= $e($r['path']) ?>"><?= $e(t('admin.ai.feature.' . $r['id'])) ?></a><small class="wk-dim"><?= $e(t('admin.ai.reserved.' . $r['id'])) ?></small></li>
<?php endforeach; ?>
</ul><?php endif; ?>
<?php if ($off !== []): ?><details class="wk-ai-off">
<summary><?= $e(t('admin.ai.features_off', [\count($off)])) ?></summary>
<ul class="wk-ai-features">
<?php foreach ($off as $r): ?>
<li><i class="ph ph-circle-dashed" aria-hidden="true"></i><span><?= $e(t('admin.ai.feature.' . $r['id'])) ?></span><small class="wk-dim"><?= $e(t('admin.ai.reserved.' . $r['id'])) ?></small>
<span class="wk-ai-feature-do"><?php if (($o['shipped'][$r['id']] ?? '') === 'missing'): ?><form class="wk-inline" data-busy action="<?= $b ?>/admin/ai/prompts" method="post"><input type="hidden" name="profile" value="<?= $e($p) ?>"><input type="hidden" name="target" value="<?= $e($o['target']) ?>"><input type="hidden" name="only[]" value="<?= $e($r['id']) ?>"><button class="btn btn-secondary btn-sm" type="submit"><?= $e(t('admin.ai.prompts_from_example')) ?></button></form><?php endif; ?>
<a class="btn btn-secondary btn-sm" href="<?= $b ?>/<?= $e($r['path']) ?>/edit"><?= $e(t('admin.ai.reserved_create')) ?></a></span></li>
<?php endforeach; ?>
</ul></details><?php endif; ?>

<footer class="wk-ai-profile-f">
<p class="wk-text-sm"><span class="wk-dim"><?= $e(t('admin.ai.system')) ?>:</span>
<?php if ($o['system']['present']): ?><a href="<?= $b ?>/<?= $e($o['system']['path']) ?>"><?= $e(t($o['system']['fallback'] ? 'admin.ai.system_default' : 'admin.ai.system_own')) ?></a><?php else: ?><span class="wk-dim"><?= $e(t('admin.ai.system_none')) ?></span> <a href="<?= $b ?>/<?= $e($o['path']) ?>:system/edit"><?= $e(t('admin.ai.reserved_create')) ?></a><?php endif; ?></p>
<?php if ($o['other'] !== []): ?><p class="wk-text-sm"><span class="wk-dim"><?= $e(t('admin.ai.other_pages')) ?>:</span> <?php foreach ($o['other'] as $i => $page): ?><?= $i > 0 ? ' · ' : '' ?><a href="<?= $b ?>/<?= $e($page['path']) ?>" title="<?= $e($page['id']) ?>"><?= $e($page['label']) ?></a><?php endforeach; ?></p><?php endif; ?>
<?php if ($o['shippedMissing'] > 0): ?>
<form class="wk-ai-install" data-busy action="<?= $b ?>/admin/ai/prompts" method="post"><input type="hidden" name="profile" value="<?= $e($p) ?>">
<select class="input input-sm" name="target" aria-label="<?= $e(t('admin.ai.prompts_target')) ?>"><?php foreach (\Reporion\Service\Ai\DefaultPrompts::TARGETS as $tg): ?><option value="<?= $e($tg) ?>"<?= $tg === $o['target'] ? ' selected' : '' ?>><?= $e(t('admin.ai.prompts_target.' . $tg)) ?></option><?php endforeach; ?></select>
<button class="btn btn-secondary btn-sm" type="submit"><i class="ph ph-download-simple" aria-hidden="true"></i><?= $e(t('admin.ai.prompts_install', [$o['shippedMissing']])) ?></button></form>
<?php endif; ?>
</footer>
</section>
<?php endforeach; ?>
</div>
<?php if ($overview === []): ?><p class="wk-dim"><?= $e(t('admin.ai.prompts_empty', [$ai->promptProfile])) ?></p><?php endif; ?>
<details class="wk-ai-new">
<summary><i class="ph ph-plus" aria-hidden="true"></i><?= $e(t('admin.ai.prompts_new')) ?></summary>
<form class="wk-ai-install" data-busy action="<?= $b ?>/admin/ai/prompts" method="post">
<label><?= $e(t('admin.ai.prompts_new_name')) ?>
<input class="input" type="text" name="profile" required pattern="[a-z0-9][a-z0-9_\-]{0,31}" maxlength="32" placeholder="<?= $e(t('admin.ai.prompts_new_hint')) ?>">
<small><?= $e(t('admin.ai.prompts_new_help')) ?></small></label>
<label><?= $e(t('admin.ai.prompts_target')) ?>
<select class="input" name="target"><?php foreach (\Reporion\Service\Ai\DefaultPrompts::TARGETS as $tg): ?><option value="<?= $e($tg) ?>"><?= $e(t('admin.ai.prompts_target.' . $tg)) ?></option><?php endforeach; ?></select>
<small><?= $e(t('admin.ai.prompts_target_help')) ?></small></label>
<button class="btn btn-primary btn-sm" type="submit"><?= $e(t('admin.ai.prompts_new_button')) ?></button></form>
</details>
</div>

<?php /* Phase 34b: the assistant's use over a period, from the audit lines (ai.call, ai.refused): counts, times, tokens; never a prompt or an answer */ ?>
<div class="wk-panel" id="usage">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.ai.usage')) ?></h2><p class="wk-dim"><?= $e(t('admin.ai.usage_help')) ?></p></hgroup>
<nav class="wk-ai-period" aria-label="<?= $e(t('admin.ai.usage_period')) ?>"><?php foreach (\Reporion\Service\Ai\Usage::PERIODS as $days): ?><a class="btn btn-sm <?= $usage['days'] === $days ? 'btn-primary' : 'btn-secondary' ?>" href="<?= $b ?>/admin/ai?days=<?= $days ?>#usage"<?= $usage['days'] === $days ? ' aria-current="true"' : '' ?>><?= $e(t('admin.ai.usage_days', [$days])) ?></a><?php endforeach; ?></nav></header>
<?php if ($usage['calls'] === 0 && $usage['refused'] === 0): ?>
<p class="wk-dim"><?= $e(t('admin.ai.usage_none', [$usage['days']])) ?></p>
<?php else: ?>
<?php $sec = static fn (?int $ms): string => $ms === null ? 'n/a' : number_format($ms / 1000, 1) . ' s'; ?>
<div class="wk-start-stats">
<div class="wk-start-stat"><b><?= (int) $usage['calls'] ?></b><span><?= $e(t('admin.ai.usage_calls')) ?></span></div>
<div class="wk-start-stat<?= $usage['errors'] + $usage['refused'] > 0 ? ' wk-start-stat-warn' : '' ?>"><b><?= (int) ($usage['errors'] + $usage['refused']) ?></b><span><?= $e(t('admin.ai.usage_failed', [$usage['errors'], $usage['refused']])) ?></span></div>
<div class="wk-start-stat"><b><?= $e($sec($usage['p50'])) ?></b><span><?= $e(t('admin.ai.usage_time', [$sec($usage['p90'])])) ?></span></div>
<div class="wk-start-stat"><b><?= $e(number_format((int) $usage['tokensOut'])) ?></b><span><?= $e(t('admin.ai.usage_tokens', [number_format((int) $usage['tokensIn'])])) ?></span></div>
</div>
<?php foreach (['actions' => 'admin.ai.usage_by_action', 'models' => 'admin.ai.usage_by_model'] as $group => $heading): ?>
<?php if ($usage[$group] !== []): ?>
<h3 class="wk-eyebrow wk-ai-usage-h"><?= $e(t($heading)) ?></h3>
<div class="wk-ai-params-wrap">
<table class="table wk-ai-usage table-cards">
<thead><tr><th scope="col"></th><th scope="col"><?= $e(t('admin.ai.usage_calls')) ?></th><th scope="col"><?= $e(t('admin.ai.usage_errors')) ?></th><th scope="col"><?= $e(t('admin.ai.usage_median')) ?></th><th scope="col">p90</th><th scope="col"><?= $e(t('admin.ai.usage_tokens_out')) ?></th></tr></thead>
<tbody>
<?php foreach ($usage[$group] as $key => $row): ?>
<tr><th scope="row" class="wk-mono"><?= $e((string) $key) ?></th><td data-label="<?= $e(t('admin.ai.usage_calls')) ?>"><?= (int) $row['calls'] ?></td><td data-label="<?= $e(t('admin.ai.usage_errors')) ?>"<?= $row['errors'] > 0 ? ' class="wk-ai-usage-bad"' : '' ?>><?= (int) $row['errors'] ?></td><td data-label="<?= $e(t('admin.ai.usage_median')) ?>"><?= $e($sec($row['p50'])) ?></td><td data-label="p90"><?= $e($sec($row['p90'])) ?></td><td data-label="<?= $e(t('admin.ai.usage_tokens_out')) ?>"><?= $e(number_format((int) $row['tokensOut'])) ?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
<?php endforeach; ?>
<div class="wk-ai-usage-foot">
<?php if ($usage['reasons'] !== []): ?><p class="wk-text-sm"><b><?= $e(t('admin.ai.usage_reasons')) ?></b> <?php foreach ($usage['reasons'] as $reason => $n): ?><span class="wk-chip"><?= $e((string) $reason) ?> · <?= (int) $n ?></span> <?php endforeach; ?></p><?php endif; ?>
<p class="wk-text-sm"><b><?= $e(t('admin.ai.usage_users')) ?></b> <?php foreach ($usage['users'] as $user => $n): ?><span class="wk-chip"><?= $e(display_name((string) $user)) ?> · <?= (int) $n ?></span> <?php endforeach; ?></p>
</div>
<?php endif; ?>
</div>
</div>
