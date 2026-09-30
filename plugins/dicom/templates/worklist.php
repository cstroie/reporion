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
<div class="wk-doc-titlerow"><h1 class="wk-doc-title"><?= $e(t('dicom.worklist.title')) ?></h1><div class="wk-actions">
<?php if ($isOwner): ?><a class="btn btn-secondary" href="<?= $b ?>/x/dicom/echo"><i class="ph ph-plugs-connected"></i><?= $e(t('dicom.echo.title')) ?></a><?php endif; ?>
<a class="btn btn-secondary" href="<?= $b ?>/new"><i class="ph ph-pencil-simple-line"></i><?= $e(t('dicom.worklist.manual')) ?></a>
</div></div>
<?php if ($queried): ?><div class="wk-badges"><span class="tag tag-neutral"><?= $e(t('dicom.worklist.count', [\count($list['rows'])])) ?></span></div><?php endif; ?>
</div>
<?php if ($servers === []): ?>
<div class="wk-notice" role="alert"><i class="ph ph-warning"></i><div><?= $e(t('dicom.err.not-configured')) ?></div></div>
<?php else: ?>
<?php /* The revisions page's toolbar: site, modality, from → to, Query at the end; it wraps only on a narrow screen */ ?>
<form class="wk-rev-bar" method="get" action="<?= $b ?>/x/dicom/worklist">
<span class="wk-bar-group"><i class="ph ph-hospital wk-dim" aria-hidden="true"></i>
<select class="input" name="site" aria-label="<?= $e(t('dicom.col.site')) ?>"><option value=""><?= $e(t('dicom.worklist.all_sites')) ?></option><?php foreach (array_keys($servers) as $code): ?><option value="<?= $e($code) ?>"<?= $code === $site ? ' selected' : '' ?>><?= $e($code) ?></option><?php endforeach; ?></select></span>
<span class="wk-bar-group"><i class="ph ph-scan wk-dim" aria-hidden="true"></i>
<select class="input" name="modality" aria-label="<?= $e(t('dicom.col.modality')) ?>"><option value=""><?= $e(t('dicom.worklist.all_modalities', [implode(', ', $modalities)])) ?></option><?php foreach ($modalities as $code): ?><option value="<?= $e($code) ?>"<?= $code === $modality ? ' selected' : '' ?>><?= $e($code) ?></option><?php endforeach; ?></select></span>
<span class="wk-bar-group"><i class="ph ph-calendar-blank wk-dim" aria-hidden="true"></i>
<input class="input wk-mono" type="date" name="from" value="<?= $e($from) ?>" aria-label="<?= $e(t('dicom.worklist.from')) ?>">
<i class="ph ph-arrow-right wk-dim" aria-hidden="true"></i>
<input class="input wk-mono" type="date" name="to" value="<?= $e($to) ?>" aria-label="<?= $e(t('dicom.worklist.to')) ?>"></span>
<button class="btn btn-secondary wk-bar-end" type="submit"><i class="ph ph-magnifying-glass"></i><?= $e(t('dicom.worklist.query')) ?></button>
</form>
<?php if (!$queried): ?>
<p class="wk-dim"><?= $e(t('dicom.worklist.idle')) ?></p>
<?php else: ?>
<?php if ($invalidRange): ?>
<div class="wk-notice" role="alert"><i class="ph ph-warning"></i><div><?= $e(t('dicom.worklist.bad_range')) ?></div></div>
<?php endif; ?>
<?php foreach ($list['errors'] as $code => $error): ?>
<div class="wk-notice" role="alert"><i class="ph ph-warning"></i><div><b><?= $e((string) $code) ?></b> — <?= $e(t('dicom.err.' . $error)) ?></div></div>
<?php endforeach; ?>
<?php if ($list['rows'] === []): ?>
<p class="wk-dim"><?= $e(t('dicom.worklist.empty')) ?></p>
<?php else: ?>
<table class="table">
<thead><tr><th><?= $e(t('dicom.col.when')) ?></th><th><?= $e(t('dicom.col.site')) ?></th><th><?= $e(t('dicom.col.modality')) ?></th><th><?= $e(t('dicom.col.patient')) ?></th><th><?= $e(t('dicom.col.description')) ?></th><th><?= $e(t('dicom.col.accession')) ?></th><th></th></tr></thead>
<tbody>
<?php foreach ($list['rows'] as $row): ?>
<tr>
<td class="wk-mono"><?= $e((string) $row['when']) ?></td>
<td class="wk-mono"><?= $e((string) $row['site']) ?></td>
<td class="wk-mono"><?= $e((string) $row['modality']) ?></td>
<td><?= $e((string) $row['patient']) ?><?php if ($row['cnp'] === ''): ?> <span class="tag tag-caution" title="<?= $e(t('dicom.worklist.no_cnp_help')) ?>"><?= $e(t('dicom.worklist.no_cnp')) ?></span><?php endif; ?></td>
<td class="wk-dim"><?= $e((string) $row['description']) ?></td>
<td class="wk-mono wk-dim"><?= $e((string) $row['accession']) ?></td>
<td style="text-align:right">
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
<?php endif; ?>
<?php endif; ?>
</div>
