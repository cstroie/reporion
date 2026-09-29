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
 * ?array $lookup (His::lookup()); ?string $error; ?array{created: int, empty: int, updated: bool} $done;
 * string $basePath
 */

declare(strict_types=1);

/** @var \Reporion\Storage\PageRecord $page */
/** @var array{name: string, cnp: string} $own */
/** @var ?array{candidates: list<array<string, mixed>>, patient: ?array<string, mixed>, exams: list<array<string, mixed>>, match: ?string, mismatch: bool} $lookup */
/** @var ?string $error */
/** @var ?array{created: int, empty: int, updated: bool} $done */
/** @var string $basePath */

$b = htmlspecialchars($basePath, ENT_QUOTES);
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$self = $b . '/x/hipobridge/priors/' . $e(rawurlencode($page->pid));
$ownPatient = is_array($page->frontmatter['patient'] ?? null) ? $page->frontmatter['patient'] : [];
?>
<div class="wk-doc">
<div class="wk-doc-titlerow wk-sec"><h2 class="wk-sec-title"><i class="ph ph-hospital"></i> <?= $e(t('hipobridge.priors.title')) ?></h2></div>
<?php if ($done !== null): ?>
<div class="wk-notice" role="status"><i class="ph ph-check"></i><div><?= $e(t('hipobridge.priors.done', [$done['created']])) ?><?= $done['updated'] ? ' ' . $e(t('hipobridge.priors.done_updated')) : '' ?><?= $done['empty'] > 0 ? ' ' . $e(t('hipobridge.priors.done_empty', [$done['empty']])) : '' ?></div></div>
<?php endif; ?>
<?php if ($error !== null): ?>
<div class="wk-notice" role="alert"><i class="ph ph-warning"></i><div><?= $e(t('hipobridge.err.' . $error)) ?></div></div>
<?php endif; ?>
<?php if ($lookup !== null && $lookup['candidates'] !== []): ?>
<p><?= $e(t('hipobridge.priors.choose')) ?></p>
<table class="table">
<thead><tr><th><?= $e(t('hipobridge.col.patient')) ?></th><th><?= $e(t('hipobridge.col.born')) ?></th><th><?= $e(t('hipobridge.col.sex')) ?></th><th><?= $e(t('hipobridge.col.his_id')) ?></th><th></th></tr></thead>
<tbody>
<?php foreach ($lookup['candidates'] as $c): ?>
<tr><td><?= $e((string) $c['name']) ?></td><td class="wk-mono"><?= $e((string) ($c['born'] ?? '')) ?></td><td class="wk-mono"><?= $e((string) ($c['sex'] ?? '')) ?></td><td class="wk-mono wk-dim"><?= $e((string) $c['id']) ?></td>
<td style="text-align:right"><a class="btn btn-secondary btn-sm" href="<?= $self ?>?patient=<?= $e(rawurlencode((string) $c['id'])) ?>"><?= $e(t('hipobridge.priors.this_patient')) ?></a></td></tr>
<?php endforeach; ?>
</tbody>
</table>
<?php elseif ($lookup !== null && $lookup['patient'] === null): ?>
<p class="wk-dim"><?= $e(t('hipobridge.priors.not_found')) ?></p>
<?php elseif ($lookup !== null && $lookup['patient'] !== null): ?>
<?php $p = $lookup['patient']; ?>
<div class="wk-panel" style="margin-bottom:var(--space-4)">
<div class="wk-panel-h"><span class="wk-eyebrow"><?= $e(t('hipobridge.priors.in_his')) ?></span><span class="wk-mono wk-dim"><?= $e(t('hipobridge.col.his_id')) ?> <?= $e((string) $p['id']) ?> · <a href="<?= $self ?>"><?= $e(t('hipobridge.priors.search_again')) ?></a></span></div>
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
<p class="wk-dim"><?= $e(t('hipobridge.priors.no_exams')) ?></p>
<?php else: ?>
<form action="<?= $self ?>" method="post">
<input type="hidden" name="patient" value="<?= $e((string) $p['id']) ?>">
<p class="wk-dim"><?= $e(t('hipobridge.priors.explain')) ?></p>
<table class="table">
<thead><tr><th><?= $e(t('hipobridge.priors.col_import')) ?></th><th><?= $e(t('hipobridge.priors.col_this')) ?></th><th><?= $e(t('hipobridge.col.when')) ?></th><th><?= $e(t('hipobridge.col.modality')) ?></th><th><?= $e(t('hipobridge.col.region')) ?></th><th><?= $e(t('hipobridge.col.requester')) ?></th><th></th></tr></thead>
<tbody>
<?php foreach ($lookup['exams'] as $exam): ?>
<?php $isThis = $exam['ref'] === $lookup['match']; ?>
<tr<?= $isThis ? ' class="wk-sel"' : '' ?>>
<td><?php if ($exam['report'] === null): ?><input type="checkbox" name="import[]" value="<?= $e((string) $exam['ref']) ?>"<?= $isThis ? '' : ' checked' ?> aria-label="<?= $e(t('hipobridge.priors.col_import')) ?>"><?php else: ?><input type="checkbox" name="import[]" value="<?= $e((string) $exam['ref']) ?>" checked aria-label="<?= $e(t('hipobridge.priors.link')) ?>"><?php endif; ?></td>
<td><input type="radio" name="this" value="<?= $e((string) $exam['ref']) ?>"<?= $isThis ? ' checked' : '' ?> aria-label="<?= $e(t('hipobridge.priors.col_this')) ?>"></td>
<td class="wk-mono"><?= $e((string) $exam['when']) ?></td>
<td class="wk-mono"><?= $e(\Reporion\Plugin\Hipobridge\Fhir::MODALITIES[$exam['type']] ?? (string) $exam['type']) ?></td>
<td><?= $e(implode(', ', $exam['regions'])) ?></td>
<td class="wk-dim"><?= $e((string) $exam['requester']) ?></td>
<td><?php if ($exam['report'] !== null): ?><a href="<?= $b ?>/<?= $e((string) $exam['report']) ?>"><?= $e(t('hipobridge.priors.in_wiki')) ?></a><?php endif; ?></td>
</tr>
<?php endforeach; ?>
<tr><td></td><td><input type="radio" name="this" value=""<?= $lookup['match'] === null ? ' checked' : '' ?> aria-label="<?= $e(t('hipobridge.priors.none')) ?>"></td><td colspan="5" class="wk-dim"><?= $e(t('hipobridge.priors.none')) ?></td></tr>
</tbody>
</table>
<div class="wk-actions" style="margin-top:var(--space-4)"><button class="btn btn-primary" type="submit"><i class="ph ph-download-simple"></i><?= $e(t('hipobridge.priors.submit')) ?></button></div>
</form>
<?php endif; ?>
<?php endif; ?>
</div>
