<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET/POST /x/dicom/study/{pid} — the report's PACS tab: the studies of its
 * site and day (either can be changed), the likeliest first; linking one
 * fills only what the report is missing. Content only; the page header is
 * the report's.
 *
 * Variables in scope: \Reporion\Storage\PageRecord $page; array{name: string, cnp: string} $own;
 * ?array{site: ?string, day: string, rows: list<array<string, mixed>>} $lookup; array $servers;
 * ?string $error; ?bool $done; string $basePath
 */

declare(strict_types=1);

/** @var \Reporion\Storage\PageRecord $page */
/** @var array{name: string, cnp: string} $own */
/** @var ?array{site: ?string, day: string, rows: list<array<string, mixed>>} $lookup */
/** @var array<string, array{host: string, port: int, aet: string}> $servers */
/** @var ?string $error */
/** @var ?bool $done */
/** @var string $basePath */

$b = htmlspecialchars($basePath, ENT_QUOTES);
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$self = $b . '/x/dicom/study/' . $e(rawurlencode($page->pid));
$site = $lookup['site'] ?? null;
$day = $lookup['day'] ?? '';
?>
<div class="wk-doc">
<div class="wk-doc-titlerow wk-sec"><h2 class="wk-sec-title"><i class="ph ph-monitor"></i> <?= $e(t('dicom.study.title')) ?></h2></div>
<?php if ($done !== null): ?>
<div class="wk-notice" role="status"><i class="ph ph-check"></i><div><?= $e(t($done ? 'dicom.study.done' : 'dicom.study.nothing')) ?></div></div>
<?php endif; ?>
<?php if ($error !== null): ?>
<div class="wk-notice" role="alert"><i class="ph ph-warning"></i><div><?= $e(t('dicom.err.' . $error)) ?></div></div>
<?php endif; ?>
<?php if ($servers === []): ?>
<div class="wk-notice" role="alert"><i class="ph ph-warning"></i><div><?= $e(t('dicom.err.not-configured')) ?></div></div>
<?php else: ?>
<form method="get" action="<?= $self ?>" class="wk-form-grid" style="margin-bottom:var(--space-4)">
<label><?= $e(t('dicom.col.site')) ?><select class="input" name="site"><?php foreach (array_keys($servers) as $code): ?><option value="<?= $e($code) ?>"<?= $code === $site ? ' selected' : '' ?>><?= $e($code) ?></option><?php endforeach; ?></select></label>
<label><?= $e(t('dicom.study.day')) ?><input class="input" type="date" name="day" value="<?= $e($day) ?>"></label>
<p style="align-self:end;margin:0"><button class="btn btn-secondary" type="submit"><i class="ph ph-magnifying-glass"></i><?= $e(t('dicom.worklist.query')) ?></button></p>
</form>
<?php if ($lookup !== null && $day === ''): ?>
<p class="wk-dim"><?= $e(t('dicom.study.no_day')) ?></p>
<?php elseif ($lookup !== null && $lookup['rows'] === []): ?>
<p class="wk-dim"><?= $e(t('dicom.study.none')) ?></p>
<?php elseif ($lookup !== null): ?>
<p class="wk-dim"><?= $e(t('dicom.study.explain')) ?></p>
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
<td style="text-align:right">
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
