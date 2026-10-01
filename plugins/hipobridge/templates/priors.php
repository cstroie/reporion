<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET/POST /x/hipobridge/priors/{pid} — the report's patient in the HIS,
 * what the report is missing that the HIS knows, and the patient's other
 * exams: tick the ones to bring into the wiki (archived, docs/FORMATS.md
 * §3e) and pick the order this report answers. Content only; the page
 * header is the report's.
 *
 * Variables in scope: \Reporion\Storage\PageRecord $page; array{name: string, cnp: string} $own;
 * ?array $lookup (His::lookup()); ?string $error; ?array{created: int, empty: int, updated: bool, linked: int} $done;
 * string $basePath
 */

declare(strict_types=1);

/** @var \Reporion\Storage\PageRecord $page */
/** @var array{name: string, cnp: string} $own */
/** @var ?array{candidates: list<array<string, mixed>>, patient: ?array<string, mixed>, exams: list<array<string, mixed>>, match: ?string, mismatch: bool} $lookup */
/** @var ?string $error */
/** @var ?array{created: int, empty: int, updated: bool, linked: int} $done */
/** @var string $basePath */

$b = htmlspecialchars($basePath, ENT_QUOTES);
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$self = $b . '/x/hipobridge/priors/' . $e(rawurlencode($page->pid));
$ownPatient = is_array($page->frontmatter['patient'] ?? null) ? $page->frontmatter['patient'] : [];
$box = static fn (string $type, string $name, string $value, bool $checked, string $label): string
    => '<label class="radio"><input type="' . $type . '" name="' . $name . '" value="' . $value . '"' . ($checked ? ' checked' : '') . ' aria-label="' . $label . '"><span class="dot"></span></label>';
?>
<div class="wk-doc">
<div class="wk-doc-titlerow wk-sec"><hgroup><h2 class="wk-sec-title"><i class="ph ph-hospital"></i> <?= $e(t('hipobridge.priors.title')) ?></h2><p class="wk-dim"><?= $e(t('hipobridge.priors.subtitle')) ?></p></hgroup></div>
<?php if ($done !== null): ?>
<?php
$said = array_filter([
    $done['created'] > 0 ? t($done['created'] === 1 ? 'hipobridge.priors.done_one' : 'hipobridge.priors.done', [$done['created']]) : '',
    $done['linked'] > 0 ? t($done['linked'] === 1 ? 'hipobridge.priors.done_linked_one' : 'hipobridge.priors.done_linked', [$done['linked']]) : '',
    $done['updated'] ? t('hipobridge.priors.done_updated') : '',
    $done['empty'] > 0 ? t('hipobridge.priors.done_empty', [$done['empty']]) : '',
]);
?>
<div class="wk-notice wk-mb-4" role="status"><i class="ph ph-check"></i><div><?= $e($said !== [] ? implode(' ', $said) : t('hipobridge.priors.done_nothing')) ?></div></div>
<?php endif; ?>
<?php if ($error !== null): ?>
<div class="wk-notice wk-mb-4" role="alert"><i class="ph ph-warning"></i><div><?= $e(t('hipobridge.err.' . $error)) ?></div></div>
<?php endif; ?>
<?php if ($lookup !== null && $lookup['candidates'] !== []): ?>
<div class="wk-panel">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('hipobridge.priors.candidates')) ?><span class="wk-count"><?= \count($lookup['candidates']) ?></span></h2><p class="wk-dim"><?= $e(t('hipobridge.priors.choose')) ?></p></hgroup></header>
<table class="table table-cards">
<thead><tr><th><?= $e(t('hipobridge.col.patient')) ?></th><th><?= $e(t('hipobridge.col.born')) ?></th><th><?= $e(t('hipobridge.col.sex')) ?></th><th><?= $e(t('hipobridge.col.his_id')) ?></th><th></th></tr></thead>
<tbody>
<?php foreach ($lookup['candidates'] as $c): ?>
<tr><td data-label="<?= $e(t('hipobridge.col.patient')) ?>"><div class="wk-cell"><?= $e((string) $c['name']) ?></div></td><td class="wk-mono" data-label="<?= $e(t('hipobridge.col.born')) ?>"><div class="wk-cell"><?= $e((string) ($c['born'] ?? '')) ?></div></td><td class="wk-mono" data-label="<?= $e(t('hipobridge.col.sex')) ?>"><div class="wk-cell"><?= $e((string) ($c['sex'] ?? '')) ?></div></td><td class="wk-mono wk-dim" data-label="<?= $e(t('hipobridge.col.his_id')) ?>"><div class="wk-cell"><?= $e((string) $c['id']) ?></div></td>
<td class="wk-right"><a class="btn btn-secondary btn-sm" data-busy href="<?= $self ?>?patient=<?= $e(rawurlencode((string) $c['id'])) ?>"><?= $e(t('hipobridge.priors.this_patient')) ?></a></td></tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php elseif ($lookup !== null && $lookup['patient'] === null): ?>
<div class="wk-panel"><div class="wk-empty"><i class="ph ph-user-circle-dashed"></i><p><?= $e(t('hipobridge.priors.not_found')) ?></p></div></div>
<?php elseif ($lookup !== null && $lookup['patient'] !== null): ?>
<?php $p = $lookup['patient']; ?>
<div class="wk-panel wk-mb-4">
<header class="wk-panel-h"><h2 class="wk-eyebrow"><?= $e(t('hipobridge.priors.in_his')) ?></h2><span class="wk-actions"><span class="wk-mono wk-dim wk-text-sm"><?= $e(t('hipobridge.col.his_id')) ?> <?= $e((string) $p['id']) ?></span><a class="btn btn-secondary btn-sm" data-busy href="<?= $self ?>" title="<?= $e(t('hipobridge.priors.search_again')) ?>"><i class="ph ph-arrow-clockwise"></i><span class="wk-btn-label"><?= $e(t('hipobridge.priors.search_again')) ?></span></a></span></header>
<table class="table">
<thead><tr><th></th><th><?= $e(t('hipobridge.priors.col_report')) ?></th><th><?= $e(t('hipobridge.priors.col_his')) ?></th></tr></thead>
<tbody>
<tr><td><?= $e(t('hipobridge.col.patient')) ?></td><td><?= $e($own['name']) ?></td><td><?= $e((string) $p['name']) ?></td></tr>
<tr><td>CNP</td><td class="wk-mono"><?= $e($own['cnp'] !== '' ? t('hipobridge.priors.present') : '—') ?></td><td class="wk-mono"><?= $e((string) $p['cnp'] !== '' ? t('hipobridge.priors.present') : '—') ?></td></tr>
<tr><td><?= $e(t('hipobridge.col.sex')) ?></td><td class="wk-mono"><?= $e((string) ($ownPatient['sex'] ?? '—')) ?></td><td class="wk-mono"><?= $e((string) ($p['sex'] ?? '—')) ?></td></tr>
<tr><td><?= $e(t('hipobridge.col.born')) ?></td><td class="wk-mono"><?= $e((string) ($ownPatient['born'] ?? '—')) ?></td><td class="wk-mono"><?= $e((string) ($p['born'] ?? '—')) ?></td></tr>
</tbody>
</table>
</div>
<?php if ($lookup['mismatch']): ?>
<div class="wk-notice" role="alert"><i class="ph ph-warning"></i><div><?= $e(t('hipobridge.err.mismatch')) ?></div></div>
<?php elseif ($lookup['exams'] === []): ?>
<?php /* The patient is identified even with no exam to import: the report's blanks can still be filled */ ?>
<form action="<?= $self ?>" method="post" data-busy>
<input type="hidden" name="patient" value="<?= $e((string) $p['id']) ?>">
<div class="wk-panel"><div class="wk-empty"><i class="ph ph-files"></i><p><?= $e(t('hipobridge.priors.no_exams')) ?></p>
<button class="btn btn-primary" type="submit"><i class="ph ph-download-simple"></i><?= $e(t('hipobridge.priors.update_only')) ?></button></div></div>
</form>
<?php else: ?>
<form action="<?= $self ?>" method="post" data-busy>
<input type="hidden" name="patient" value="<?= $e((string) $p['id']) ?>">
<div class="wk-panel">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('hipobridge.priors.exams')) ?><span class="wk-count"><?= \count($lookup['exams']) ?></span></h2><p class="wk-dim"><?= $e(t('hipobridge.priors.explain')) ?></p></hgroup></header>
<table class="table table-cards">
<thead><tr><th><?= $e(t('hipobridge.priors.col_import')) ?></th><th><?= $e(t('hipobridge.priors.col_this')) ?></th><th><?= $e(t('hipobridge.col.when')) ?></th><th><?= $e(t('hipobridge.col.modality')) ?></th><th><?= $e(t('hipobridge.col.region')) ?></th><th><?= $e(t('hipobridge.col.requester')) ?></th><th></th></tr></thead>
<tbody>
<?php foreach ($lookup['exams'] as $exam): ?>
<?php $isThis = $exam['ref'] === $lookup['match']; ?>
<tr<?= $isThis ? ' class="wk-sel"' : '' ?>>
<td data-label="<?= $e(t('hipobridge.priors.col_import')) ?>"><div class="wk-cell"><?= $box('checkbox', 'import[]', $e((string) $exam['ref']), $exam['report'] !== null || !$isThis, $e(t($exam['report'] === null ? 'hipobridge.priors.col_import' : 'hipobridge.priors.link'))) ?></div></td>
<td data-label="<?= $e(t('hipobridge.priors.col_this')) ?>"><div class="wk-cell"><?= $box('radio', 'this', $e((string) $exam['ref']), $isThis, $e(t('hipobridge.priors.col_this'))) ?></div></td>
<td class="wk-mono wk-nowrap" data-label="<?= $e(t('hipobridge.col.when')) ?>"><div class="wk-cell"><?= $e(\Reporion\Support\MetaText::when($exam['when'])) ?></div></td>
<td class="wk-mono" data-label="<?= $e(t('hipobridge.col.modality')) ?>"><div class="wk-cell"><?= $e(\Reporion\Plugin\Hipobridge\Fhir::MODALITIES[$exam['type']] ?? (string) $exam['type']) ?></div></td>
<td data-label="<?= $e(t('hipobridge.col.region')) ?>"><div class="wk-cell"><?= $e(implode(', ', $exam['regions'])) ?></div></td>
<td class="wk-dim" data-label="<?= $e(t('hipobridge.col.requester')) ?>"><div class="wk-cell"><?= $e((string) $exam['requester']) ?><?php if ($exam['indication'] !== ''): ?><br><small><?= $e((string) $exam['indication']) ?></small><?php endif; ?></div></td>
<td class="wk-right wk-nowrap"><?php if ($exam['report'] !== null): ?><a href="<?= $b ?>/<?= $e((string) $exam['report']) ?>"><?= $e(t('hipobridge.priors.in_wiki')) ?></a><?php endif; ?></td>
</tr>
<?php endforeach; ?>
<tr><td data-label="<?= $e(t('hipobridge.priors.col_import')) ?>"><label class="radio" hidden><input type="checkbox" data-check-all="import[]" aria-label="<?= $e(t('hipobridge.priors.all')) ?>"><span class="dot"></span></label></td><td data-label="<?= $e(t('hipobridge.priors.col_this')) ?>"><div class="wk-cell"><?= $box('radio', 'this', '', $lookup['match'] === null, $e(t('hipobridge.priors.none'))) ?></div></td><td colspan="5" class="wk-dim"><?= $e(t('hipobridge.priors.none')) ?></td></tr>
</tbody>
</table>
<footer><button class="btn btn-primary" type="submit"><i class="ph ph-download-simple"></i><?= $e(t('hipobridge.priors.submit')) ?></button></footer>
</div>
</form>
<?php endif; ?>
<?php endif; ?>
</div>
