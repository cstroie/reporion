<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The guided new-report form (Controller\NewPageController → Service\NewReport),
 * design/mockup/WikiCreate.dc.html: path, template and metadata panels, the
 * metadata entered here instead of the mockup's HL7 prefill. One POST form;
 * "Preview" recomputes on the server, "Create & open editor" creates. Works
 * without JavaScript; the script at the end only previews the path and what
 * the CNP says as you type, and narrows devices and templates to the choice.
 *
 * Variables in scope: array $draft (NewReport::draft()); array<string, string> $errors;
 * array $options (NewReport::options()); bool $needsConfirm; string $basePath
 */

declare(strict_types=1);

/** @var array<string, mixed> $draft */
/** @var array<string, string> $errors */
/** @var array{modalities: array<string, string>, sites: array<string, array{name: string, devices: array<string, string>}>, regions: list<string>, templates: array<string, list<array{path: string, title: string}>>} $options */
/** @var bool $needsConfirm */
/** @var string $basePath */

$b = htmlspecialchars($basePath, ENT_QUOTES);
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$v = $draft['values'];
$val = static fn (string $key): string => htmlspecialchars((string) ($v[$key] ?? ''), ENT_QUOTES);
$err = static fn (string $key): string => isset($errors[$key]) ? '<span class="wk-field-err" role="alert"><i class="ph ph-warning"></i> ' . htmlspecialchars($errors[$key], ENT_QUOTES) . '</span>' : '';
$derived = $draft['derived'];
?>
<div class="wk-doc">
<form id="new-report-form" action="<?= $b ?>/new" method="post" data-island="new-report" data-config-id="new-report-config" class="wk-doc">
<input type="hidden" name="guided" value="1">
<?php /* Enter in a field submits through the first submit button: keep that Create, not + exam (the visible Create is at the end) */ ?>
<button type="submit" name="action" value="create" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden"></button>
<?php foreach (['order_ref', 'study_uid', 'pacs_accession'] as $ref): ?>
<?php if (($v[$ref] ?? '') !== ''): ?>
<input type="hidden" name="<?= $ref ?>" value="<?= $val($ref) ?>">
<?php endif; ?>
<?php endforeach; ?>
<div class="wk-doc-head">
<?= \Reporion\Http\Breadcrumb::render($crumbs) ?>
<div class="wk-doc-titlerow"><h1 class="wk-doc-title"><?= $e(t('newr.title')) ?></h1><div class="wk-actions">
<a class="btn btn-secondary" href="<?= $b ?>/new?mode=path" title="<?= $e(t('newr.advanced')) ?>"><i class="ph ph-file-code"></i><span class="wk-btn-label"><?= $e(t('newr.advanced')) ?></span></a>
<?php foreach (reporion_plugin_ui()['new_report'] ?? [] as $slot): ?>
<a class="btn btn-secondary" data-busy href="<?= $b . $e($slot['href']) ?>" title="<?= $e(t($slot['label'])) ?>"><i class="ph ph-<?= $e($slot['icon']) ?>"></i><span class="wk-btn-label"><?= $e(t($slot['label'])) ?></span></a>
<?php endforeach; ?>
</div></div>
</div>

<?php if ($errors !== []): ?>
<div class="wk-notice" role="alert"><i class="ph ph-warning"></i><div><?= $e(t('newr.fix_errors')) ?></div></div>
<?php endif; ?>
<?php if ($draft['sameDay'] !== []): ?>
<div class="wk-notice" role="<?= $needsConfirm ? 'alert' : 'status' ?>"><i class="ph ph-copy-simple"></i><div>
<b><?= $e(t('newr.same_day')) ?></b>
<ul style="margin:var(--space-2) 0;padding-left:23px">
<?php foreach ($draft['sameDay'] as $row): ?>
<li><a href="<?= $b ?>/<?= $e((string) $row['path']) ?>"><?= $e((string) ($row['title'] ?: $row['path'])) ?></a><?= ($row['exam_title'] ?? '') !== '' ? ' — ' . $e((string) $row['exam_title']) : '' ?> <span class="wk-mono wk-dim"><?= $e((string) ($row['modality'] ?? '')) ?> · <?= $e(\Reporion\Support\MetaText::when($row['study_date'] ?? '')) ?></span></li>
<?php endforeach; ?>
</ul>
<label class="radio"><input type="checkbox" name="confirm_same_day" value="1"><span class="dot"></span><?= $e(t('newr.same_day_confirm')) ?></label>
</div></div>
<?php endif; ?>

<?php if ($draft['priorRows'] !== []): ?>
<div class="wk-notice" role="status"><i class="ph ph-clock-counter-clockwise"></i><div>
<b><?= $e(t('newr.after')) ?></b>
<?php foreach ($draft['priorRows'] as $prior): ?>
<?php /* Unticking removes it: an unticked box is not submitted */ ?>
<label class="radio" style="margin-top:var(--space-2)"><input type="checkbox" name="priors[]" value="<?= $e((string) $prior['path']) ?>" checked><span class="dot"></span><a href="<?= $b ?>/<?= $e((string) $prior['path']) ?>" target="_blank" rel="noopener"><?= $e((string) ($prior['title'] ?: $prior['path'])) ?></a> <span class="wk-mono wk-dim"><?= $e(implode(' · ', array_filter([(string) ($prior['modality'] ?? ''), \Reporion\Support\MetaText::when($prior['study_date'] ?? '')]))) ?></span></label>
<?php endforeach; ?>
<small class="wk-dim"><?= $e(t('newr.after_help')) ?></small>
</div></div>
<?php endif; ?>

<div class="wk-panel">
<header class="wk-panel-h"><h2 class="wk-eyebrow"><?= $e(t('new.path')) ?></h2><span class="wk-mono wk-dim">reports:{modality}:{site}:{yymmdd}-{name}</span></header>
<div class="wk-pathb wk-mono" id="nr-path"><?= $draft['path'] !== null ? $e($draft['path']) : '<span class="wk-dim">' . $e(t('newr.path_pending')) . '</span>' ?></div>
<p class="wk-mono wk-dim wk-text-sm wk-mt-flush">
<?= $e(t('newr.next_accession')) ?> <b id="nr-accession"><?= $e((string) ($draft['accession'] ?? '—')) ?></b><?= $v['more'] !== [] ? ' · ' . $e(t('newr.accession_each', [\count($v['more']) + 1])) : '' ?> · <?= $e(t('newr.accession_note')) ?><br>
<i class="ph ph-info"></i> <?= $e(t('newr.path_private')) ?>
</p>
</div>

<div class="wk-two">
<div>
<div class="wk-panel">
<header class="wk-panel-h"><h2 class="wk-eyebrow"><?= $e(t('newr.patient')) ?></h2></header>
<div class="wk-form-grid">
<label class="wk-span-all"><?= $e(t('newr.name')) ?><input class="input" type="text" id="nr-name" name="name" value="<?= $val('name') ?>" placeholder="POPESCU Ana Maria" autocomplete="off" required><?= $err('name') ?></label>
<div class="wk-name-matches wk-span-all" id="nr-name-matches" hidden><span class="wk-dim"><?= $e(t('newr.name_matches')) ?></span><ul></ul></div>
</div>
<?php /* CNP | Sex | Birth year | Age, 4 flex columns (TODO 13) — Age is read only, computed client-side from the CNP or the birth year */ ?>
<div class="wk-patient-row">
<label><?= $e(t('newr.cnp')) ?><input class="input wk-mono" type="text" name="cnp" value="<?= $val('cnp') ?>" inputmode="numeric" maxlength="13" autocomplete="off"><small class="wk-dim" id="nr-cnp-info"><?= $derived['sex'] !== null || $derived['born'] !== null ? $e(t('newr.derived', [$derived['sex'] ?? '—', $derived['born'] ?? '—', $derived['age'] ?? '—'])) : $e(t('newr.cnp_help')) ?></small><?= $err('cnp') ?></label>
<label><?= $e(t('newr.sex')) ?><select class="input" name="sex"><option value=""></option><?php foreach (['M', 'F'] as $sex): ?><option value="<?= $sex ?>"<?= ($v['sex'] ?? '') === $sex ? ' selected' : '' ?>><?= $sex ?></option><?php endforeach; ?></select><?= $err('sex') ?></label>
<label><?= $e(t('newr.born')) ?><input class="input wk-mono" type="number" name="born" value="<?= $val('born') ?>" min="1880" max="<?= date('Y') ?>"><?= $err('born') ?></label>
<label><?= $e(t('newr.age')) ?><input class="input wk-mono" type="text" id="nr-age" value="<?= $derived['age'] ?? '' ?>" readonly tabindex="-1"></label>
</div>
</div>

<div class="wk-panel">
<header class="wk-panel-h"><h2 class="wk-eyebrow"><?= $e(t('newr.exam')) ?></h2></header>
<div class="wk-form-grid">
<label class="wk-span-all"><?= $e(t('newr.indication')) ?><textarea class="input" name="indication" rows="2"><?= $val('indication') ?></textarea><small class="wk-dim"><?= $e(t('newr.indication_help')) ?></small></label>
<label><?= $e(t('newr.date')) ?><input class="input" type="date" name="date" value="<?= $val('date') ?>" required><?= $err('date') ?></label>
<label><?= $e(t('newr.time')) ?><input class="input" type="time" name="time" value="<?= $val('time') ?>"><?= $err('time') ?></label>
<label><?= $e(t('newr.modality')) ?><select class="input" name="modality" required><option value=""></option><?php foreach ($options['modalities'] as $code => $ns): ?><option value="<?= $e($code) ?>" data-ns="<?= $e($ns) ?>"<?= ($v['modality'] ?? '') === $code ? ' selected' : '' ?>><?= $e($code) ?></option><?php endforeach; ?></select><?= $err('modality') ?></label>
<label><?= $e(t('newr.site')) ?><?php if ($options['sites'] !== []): ?><select class="input" name="site" required><option value=""></option><?php foreach ($options['sites'] as $code => $site): ?><option value="<?= $e($code) ?>"<?= ($v['site'] ?? '') === $code ? ' selected' : '' ?>><?= $e($site['name']) ?></option><?php endforeach; ?></select><?php else: ?><input class="input wk-mono" type="text" name="site" value="<?= $val('site') ?>" required><small class="wk-dim"><?= $e(t('newr.no_sites')) ?></small><?php endif; ?><?= $err('site') ?></label>
<label><?= $e(t('newr.device')) ?><select class="input" name="device"><option value=""></option><?php foreach ($options['sites'] as $code => $site): ?><?php foreach ($site['devices'] as $device => $deviceName): ?><option value="<?= $e($device) ?>" data-site="<?= $e($code) ?>"<?= ($v['site'] ?? '') !== '' && $v['site'] !== $code ? ' hidden' : '' ?><?= ($v['device'] ?? '') === $device ? ' selected' : '' ?>><?= $e($deviceName !== '' ? $device . ' — ' . $deviceName : $device) ?></option><?php endforeach; ?><?php endforeach; ?></select><?= $err('device') ?></label>
<div class="wk-span-all wk-text-sm"><span class="wk-dim"><?= $e(t('newr.regions')) ?></span>
<div style="display:flex;flex-wrap:wrap;gap:7.5px 18px;margin-top:7.5px">
<?php foreach ($options['regions'] as $region): ?>
<label class="radio" style="flex-direction:row;font-size:var(--text-sm)"><input type="checkbox" name="regions[]" value="<?= $e($region) ?>"<?= \in_array($region, (array) ($v['regions'] ?? []), true) ? ' checked' : '' ?>><span class="dot"></span><?= $e($region) ?></label>
<?php endforeach; ?>
</div><?= $err('regions') ?></div>
<label><?= $e(t('newr.referrer')) ?><input class="input" type="text" name="referrer" value="<?= $val('referrer') ?>"></label>
</div>
</div>
</div>

<div class="wk-panel">
<header class="wk-panel-h"><h2 class="wk-eyebrow"><?= $e(t('newr.template')) ?></h2></header>
<input class="input wk-tpl-search" type="search" id="nr-template-search" placeholder="<?= $e(t('newr.template_search')) ?>" aria-label="<?= $e(t('newr.template_search')) ?>" autocomplete="off" hidden>
<div class="wk-tpl-list" id="nr-templates">
<label class="wk-tpl-i"><input type="radio" name="template" value=""<?= ($v['template'] ?? '') === '' ? ' checked' : '' ?>><b><?= $e(t('newr.empty')) ?></b><span class="wk-mono wk-dim"><?= $e(t('newr.empty_note')) ?></span></label>
<?php foreach ($options['templates'] as $modality => $templates): ?>
<?php foreach ($templates as $template): ?>
<label class="wk-tpl-i" data-modality="<?= $e($modality) ?>" data-ns="<?= $e($modality !== '' ? $options['modalities'][$modality] ?? '' : '') ?>"<?= ($v['modality'] ?? '') !== '' && $v['modality'] !== $modality ? ' hidden' : '' ?>><input type="radio" name="template" value="<?= $e($template['path']) ?>"<?= ($v['template'] ?? '') === $template['path'] ? ' checked' : '' ?>><b><?= $e($template['title']) ?></b><span class="wk-mono wk-dim"><?= $e($template['path']) ?></span></label>
<?php endforeach; ?>
<?php endforeach; ?>
</div>
<?= $err('template') ?>
<label class="field wk-mt-flush"><span class="wk-dim wk-text-sm"><?= $e(t('newr.title_field')) ?></span><input class="input" type="text" name="title" value="<?= $val('title') ?>" placeholder="<?= $e(t('newr.title_placeholder')) ?>"></label>
</div>
</div>

<?php /* More exams in the same report — both knees, three spine regions (phase 12). Each gets its own ## and accession */ ?>
<div class="wk-panel" id="nr-more">
<header class="wk-panel-h"><h2 class="wk-eyebrow"><?= $e(t('newr.more')) ?></h2><span class="wk-mono wk-dim"><?= $e(t('newr.more_note')) ?></span></header>
<?php foreach ($v['more'] as $i => $row): ?>
<div class="wk-form-grid wk-more-exam">
<label><?= $e(t('newr.exam_n', [$i + 2])) ?><input class="input" type="text" name="more[<?= $i ?>][title]" value="<?= $e($row['title']) ?>" placeholder="<?= $e(t('newr.title_placeholder')) ?>"><?= $err('more.' . $i) ?></label>
<label><?= $e(t('newr.template')) ?><select class="input" name="more[<?= $i ?>][template]"><option value=""><?= $e(t('newr.empty')) ?></option><?php foreach ($options['templates'] as $modality => $templates): ?><?php foreach ($templates as $template): ?><option value="<?= $e($template['path']) ?>" data-modality="<?= $e($modality) ?>"<?= ($v['modality'] ?? '') !== '' && $v['modality'] !== $modality ? ' hidden' : '' ?><?= $row['template'] === $template['path'] ? ' selected' : '' ?>><?= $e($template['title']) ?></option><?php endforeach; ?><?php endforeach; ?></select></label>
<div class="wk-span-all wk-text-sm"><span class="wk-dim"><?= $e(t('newr.regions')) ?></span>
<div style="display:flex;flex-wrap:wrap;gap:7.5px 18px;margin-top:7.5px">
<?php foreach ($options['regions'] as $region): ?>
<label class="radio" style="flex-direction:row;font-size:var(--text-sm)"><input type="checkbox" name="more[<?= $i ?>][regions][]" value="<?= $e($region) ?>"<?= \in_array($region, $row['regions'], true) ? ' checked' : '' ?>><span class="dot"></span><?= $e($region) ?></label>
<?php endforeach; ?>
</div></div>
<div class="wk-span-all"><button class="btn btn-ghost" type="submit" name="action" value="remove_exam:<?= $i ?>" formnovalidate><i class="ph ph-trash"></i><?= $e(t('newr.remove_exam', [$i + 2])) ?></button></div>
</div>
<?php endforeach; ?>
<button class="btn btn-secondary" type="submit" name="action" value="add_exam" formnovalidate><i class="ph ph-plus"></i><?= $e(t('newr.add_exam')) ?></button>
</div>

<?php /* The flow ends here: where the data comes from (top), the data, then Create */ ?>
<div class="wk-actions wk-actions-end">
<a class="btn btn-secondary" href="<?= $b ?>/"><?= $e(t('editor.cancel')) ?></a>
<button class="btn btn-primary" type="submit" name="action" value="create"><i class="ph ph-arrow-right"></i><?= $e(t('new.create_open')) ?></button>
</div>
</form>
</div>
<script type="application/json" id="new-report-config"><?= json_encode([
    'modalities' => $options['modalities'],
    'basePath' => $basePath,
    'strings' => [
        'derived' => t('newr.derived'),
        'cnpInvalid' => t('newr.err.cnp'),
        'cnpHelp' => t('newr.cnp_help'),
        'pathPending' => t('newr.path_pending'),
        'accessionStale' => t('newr.accession_stale'),
    ],
], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/new-report.js'), ENT_QUOTES) ?>" defer></script>
