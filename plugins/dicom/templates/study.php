<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET/POST /x/dicom/study/{pid} — the report's PACS tab: the report's patient
 * (name and CNP, editable in the form) at its site, the likeliest first — or
 * every study of a day when both are empty; linking one fills only what the
 * report is missing. Content only; the page header is
 * the report's.
 *
 * Variables in scope: \Reporion\Storage\PageRecord $page; array{name: string, cnp: string} $own;
 * ?array{site: ?string, day: string, rows: list<array<string, mixed>>, byPatient: bool, window: int} $lookup; array $servers;
 * string $dayShown; ?string $error; ?bool $done; string $basePath
 */

declare(strict_types=1);

/** @var \Reporion\Storage\PageRecord $page */
/** @var array{name: string, cnp: string} $own */
/** @var ?array{site: ?string, day: string, rows: list<array<string, mixed>>, byPatient: bool, window: int} $lookup */
/** @var array<string, array{host: string, port: int, aet: string, calling: string}> $servers */
/** @var string $dayShown */
/** @var ?string $error */
/** @var ?bool $done */
/** @var string $basePath */

$b = htmlspecialchars($basePath, ENT_QUOTES);
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$self = $b . '/x/dicom/study/' . $e(rawurlencode($page->pid));
$site = $lookup['site'] ?? null;
$day = $lookup['day'] ?? $dayShown;
?>
<div class="wk-doc">
<div class="wk-doc-titlerow wk-sec"><hgroup><h2 class="wk-sec-title"><i class="ph ph-monitor"></i> <?= $e(t('dicom.study.title')) ?></h2><p class="wk-dim"><?= $e(t('dicom.study.subtitle')) ?></p></hgroup></div>
<?php if ($done !== null): ?>
<div class="wk-notice" role="status"><i class="ph ph-check"></i><div><?= $e(t($done ? 'dicom.study.done' : 'dicom.study.nothing')) ?></div></div>
<?php endif; ?>
<?php if ($error !== null): ?>
<div class="wk-notice" role="alert"><i class="ph ph-warning"></i><div><?= $e(t('dicom.err.' . $error)) ?></div></div>
<?php endif; ?>
<?php if ($servers === []): ?>
<div class="wk-notice" role="alert"><i class="ph ph-warning"></i><div><?= $e(t('dicom.err.not-configured')) ?></div></div>
<?php else: ?>
<?php /* One joined bar (.group). A POST: the name and CNP are the patient's and never go in a URL (D1). Both empty = every study of the day */ ?>
<form method="post" action="<?= $self ?>" class="group group-fill group-stack wk-mb-4" role="search">
<span class="group-addon" aria-hidden="true"><i class="ph ph-hospital"></i></span>
<select class="input" name="site" aria-label="<?= $e(t('dicom.col.site')) ?>"><?php foreach (array_keys($servers) as $code): ?><option value="<?= $e($code) ?>"<?= $code === $site ? ' selected' : '' ?>><?= $e($code) ?></option><?php endforeach; ?></select>
<span class="group-addon" aria-hidden="true"><i class="ph ph-calendar-blank"></i></span>
<input class="input group-date" type="date" name="day" value="<?= $e($day) ?>" aria-label="<?= $e(t('dicom.study.day')) ?>">
<span class="group-addon" aria-hidden="true"><?= $e(t('dicom.study.name')) ?></span>
<input class="input grow" type="text" name="name" value="<?= $e($own['name']) ?>" placeholder="<?= $e(t('dicom.study.name')) ?>" aria-label="<?= $e(t('dicom.study.name')) ?>" autocomplete="off" maxlength="120">
<span class="group-addon" aria-hidden="true"><?= $e(t('dicom.study.cnp')) ?></span>
<input class="input wk-mono grow" type="text" name="cnp" value="<?= $e($own['cnp']) ?>" placeholder="<?= $e(t('dicom.study.cnp')) ?>" aria-label="<?= $e(t('dicom.study.cnp')) ?>" inputmode="numeric" autocomplete="off" maxlength="32">
<button class="btn" type="submit"><i class="ph ph-magnifying-glass"></i><?= $e(t('dicom.worklist.query')) ?></button>
</form>
<?php if ($lookup !== null && $day === '' && !$lookup['byPatient']): ?>
<p class="wk-dim"><?= $e(t('dicom.study.no_day')) ?></p>
<?php elseif ($lookup !== null && $lookup['rows'] === []): ?>
<p class="wk-dim"><?= $e(t($lookup['byPatient'] ? 'dicom.study.none_patient' : 'dicom.study.none', $lookup['byPatient'] ? [$lookup['window']] : [])) ?></p>
<?php elseif ($lookup !== null): ?>
<?php if ($lookup['byPatient']): ?>
<?php if ($lookup['window'] > 0): ?><p class="wk-dim"><?= $e(t('dicom.study.window', [$day, $lookup['window']])) ?></p><?php endif; ?>
<?php else: ?>
<p class="wk-dim"><?= $e(t('dicom.study.explain')) ?></p>
<?php endif; ?>
<table class="table">
<thead><tr><th><?= $e(t('dicom.col.when')) ?></th><th><?= $e(t('dicom.col.modality')) ?></th><th><?= $e(t('dicom.col.patient')) ?></th><th><?= $e(t('dicom.col.born')) ?></th><th><?= $e(t('dicom.col.description')) ?></th><th><?= $e(t('dicom.col.accession')) ?></th><th></th></tr></thead>
<tbody>
<?php foreach ($lookup['rows'] as $row): ?>
<tr<?= $row['match'] !== '' ? ' class="wk-sel"' : '' ?>>
<td class="wk-mono"><?= $e((string) $row['when']) ?></td>
<td class="wk-mono"><?= $e((string) $row['modality']) ?></td>
<td><?= $e((string) $row['patient']) ?><?php if ($row['match'] !== ''): ?> <span class="tag tag-signed"><?= $e(t('dicom.match.' . $row['match'])) ?></span><?php endif; ?></td>
<td class="wk-mono"><?= $e(trim((string) ($row['born'] ?? '') . ' ' . (string) ($row['sex'] ?? ''))) ?></td>
<td class="wk-dim"><?= $e((string) $row['description']) ?></td>
<td class="wk-mono wk-dim"><?= $e((string) $row['accession']) ?></td>
<td class="wk-right">
<form method="post" action="<?= $self ?>"><input type="hidden" name="site" value="<?= $e((string) $row['site']) ?>"><input type="hidden" name="uid" value="<?= $e((string) $row['uid']) ?>"><input type="hidden" name="day" value="<?= $e($day) ?>">
<button class="btn <?= $row['match'] !== '' ? 'btn-primary' : 'btn-secondary' ?> btn-sm" type="submit"><i class="ph ph-link"></i><?= $e(t('dicom.study.link')) ?></button></form>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
<?php endif; ?>
</div>
