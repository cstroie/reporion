<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /x/dicom/worklist — studies from each site's PACS, filtered by site
 * and date range (GET form, works without JavaScript). "Start" opens
 * the guided new-report form filled from the study; a study that already
 * has a report links to it.
 *
 * Variables in scope: array{rows: list<array<string, mixed>>, errors: array<string, string>} $list;
 * array<string, array> $servers; ?string $site; list<string> $modalities; ?string $modality; string $from, $to; bool $invalidRange, $queried, $isOwner; string $basePath
 */

declare(strict_types=1);

/** @var array{rows: list<array<string, mixed>>, errors: array<string, string>} $list */
/** @var array<string, array{host: string, port: int, aet: string, calling: string}> $servers */
/** @var ?string $site */
/** @var list<string> $modalities */
/** @var ?string $modality */
/** @var string $from */
/** @var string $to */
/** @var bool $invalidRange */
/** @var bool $queried */
/** @var bool $isOwner */
/** @var string $basePath */

$b = htmlspecialchars($basePath, ENT_QUOTES);
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
?>
<div class="wk-doc">
<div class="wk-doc-head">
<div class="wk-crumbs wk-mono"><i class="ph ph-monitor"></i><b><?= $e(t('dicom.name')) ?></b></div>
<div class="wk-doc-titlerow"><hgroup><h1 class="wk-doc-title"><?= $e(t('dicom.worklist.title')) ?></h1><p class="wk-dim"><?= $e(t('dicom.worklist.subtitle')) ?></p></hgroup><div class="wk-actions">
<?php if ($isOwner): ?><a class="btn btn-ghost" href="<?= $b ?>/x/dicom/echo"><i class="ph ph-plugs-connected"></i><?= $e(t('dicom.echo.title')) ?></a><?php endif; ?>
<a class="btn btn-secondary" href="<?= $b ?>/new"><i class="ph ph-pencil-simple-line"></i><?= $e(t('dicom.worklist.manual')) ?></a>
</div></div>
</div>
<?php if ($servers === []): ?>
<div class="wk-notice" role="alert"><i class="ph ph-warning"></i><div><?= $e(t('dicom.err.not-configured')) ?></div></div>
<?php else: ?>
<?php /* One joined bar: site, modality, from → to, Query (.group; one control per line on a narrow screen) */ ?>
<form class="group group-fill group-stack wk-mb-4" method="get" action="<?= $b ?>/x/dicom/worklist">
<span class="group-addon" aria-hidden="true"><i class="ph ph-hospital"></i></span>
<select class="input grow" name="site" aria-label="<?= $e(t('dicom.col.site')) ?>"><option value=""><?= $e(t('dicom.worklist.all_sites')) ?></option><?php foreach (array_keys($servers) as $code): ?><option value="<?= $e($code) ?>"<?= $code === $site ? ' selected' : '' ?>><?= $e($code) ?></option><?php endforeach; ?></select>
<span class="group-addon" aria-hidden="true"><i class="ph ph-scan"></i></span>
<select class="input grow" name="modality" aria-label="<?= $e(t('dicom.col.modality')) ?>"><option value=""><?= $e(t('dicom.worklist.all_modalities', [implode(', ', $modalities)])) ?></option><?php foreach ($modalities as $code): ?><option value="<?= $e($code) ?>"<?= $code === $modality ? ' selected' : '' ?>><?= $e($code) ?></option><?php endforeach; ?></select>
<span class="group-addon" aria-hidden="true"><i class="ph ph-calendar-blank"></i></span>
<input class="input wk-mono group-date" type="date" name="from" value="<?= $e($from) ?>" aria-label="<?= $e(t('dicom.worklist.from')) ?>">
<span class="group-addon" aria-hidden="true"><i class="ph ph-arrow-right"></i></span>
<input class="input wk-mono group-date" type="date" name="to" value="<?= $e($to) ?>" aria-label="<?= $e(t('dicom.worklist.to')) ?>">
<button class="btn" type="submit"><i class="ph ph-magnifying-glass"></i><?= $e(t('dicom.worklist.query')) ?></button>
</form>
<?php if (!$queried): ?>
<div class="wk-panel"><div class="wk-empty"><i class="ph ph-list-magnifying-glass"></i><p><?= $e(t('dicom.worklist.idle')) ?></p></div></div>
<?php else: ?>
<?php if ($invalidRange): ?>
<div class="wk-notice wk-mb-4" role="alert"><i class="ph ph-warning"></i><div><?= $e(t('dicom.worklist.bad_range')) ?></div></div>
<?php endif; ?>
<?php foreach ($list['errors'] as $code => $error): ?>
<div class="wk-notice wk-mb-4" role="alert"><i class="ph ph-warning"></i><div><b><?= $e((string) $code) ?></b> — <?= $e(t('dicom.err.' . $error)) ?></div></div>
<?php endforeach; ?>
<div class="wk-panel">
<header class="wk-panel-h"><div class="wk-panel-title"><h2 class="wk-eyebrow"><?= $e(t('dicom.worklist.results')) ?></h2><span class="wk-count"><?= \count($list['rows']) ?></span></div><span class="wk-mono wk-dim wk-text-sm"><?= $e($from === $to ? $from : $from . ' → ' . $to) ?></span></header>
<?php if ($list['rows'] === []): ?>
<div class="wk-empty"><i class="ph ph-magnifying-glass"></i><p><?= $e(t('dicom.worklist.empty')) ?></p></div>
<?php else: ?>
<table class="table table-cards">
<thead><tr><th><?= $e(t('dicom.col.when')) ?></th><th><?= $e(t('dicom.col.modality')) ?></th><th><?= $e(t('dicom.col.patient')) ?></th><th><?= $e(t('dicom.col.description')) ?></th><th><?= $e(t('dicom.col.site')) ?></th><th></th></tr></thead>
<tbody>
<?php foreach ($list['rows'] as $row): ?>
<tr>
<td class="wk-mono wk-nowrap" data-label="<?= $e(t('dicom.col.when')) ?>"><div class="wk-cell"><?= $e((string) $row['when']) ?></div></td>
<td data-label="<?= $e(t('dicom.col.modality')) ?>"><div class="wk-cell"><span class="tag tag-outline"><?= $e((string) $row['modality']) ?></span></div></td>
<td data-label="<?= $e(t('dicom.col.patient')) ?>"><div class="wk-cell"><?= $e((string) $row['patient']) ?><?php if ($row['cnp'] === ''): ?> <span class="tag tag-caution" title="<?= $e(t('dicom.worklist.no_cnp_help')) ?>"><?= $e(t('dicom.worklist.no_cnp')) ?></span><?php endif; ?>
<?php $meta = trim((string) ($row['born'] ?? '') . ' ' . (string) ($row['sex'] ?? '')); if ($meta !== ''): ?><div class="wk-mono wk-dim wk-text-sm"><?= $e($meta) ?></div><?php endif; ?></div></td>
<td class="wk-dim" data-label="<?= $e(t('dicom.col.description')) ?>"><div class="wk-cell"><?= $e((string) $row['description']) ?><?php if ((string) $row['accession'] !== ''): ?><div class="wk-mono wk-text-sm"><?= $e((string) $row['accession']) ?></div><?php endif; ?></div></td>
<td class="wk-mono wk-dim" data-label="<?= $e(t('dicom.col.site')) ?>"><div class="wk-cell"><?= $e((string) $row['site']) ?></div></td>
<td class="wk-right">
<?php if ($row['report'] !== null): ?>
<a class="btn btn-ghost btn-sm" href="<?= $b ?>/<?= $e((string) $row['report']) ?>"><i class="ph ph-file-text"></i><?= $e(t('dicom.worklist.open')) ?></a>
<?php else: ?>
<a class="btn btn-primary btn-sm" href="<?= $b ?>/new?prefill=dicom&amp;ref=<?= $e(rawurlencode((string) $row['ref'])) ?>"><i class="ph ph-plus"></i><?= $e(t('dicom.worklist.start')) ?></a>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>
</div>
