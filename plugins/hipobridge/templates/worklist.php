<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /x/hipobridge/worklist — recent performed exams from the HIS
 * schedule, through HippoBridge. "Start report" opens the guided new-report form filled from
 * the exam's order (/new?prefill=hipobridge&ref=…); an exam that already
 * has a report links to it instead.
 *
 * Variables in scope: array{from: string, to: string, rows: list<array<string, mixed>>} $list;
 * ?string $error; string $basePath
 */

declare(strict_types=1);

/** @var array{from: string, to: string, rows: list<array<string, mixed>>} $list */
/** @var ?string $error */
/** @var string $basePath */

$b = htmlspecialchars($basePath, ENT_QUOTES);
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
?>
<div class="wk-doc">
<div class="wk-doc-head">
<div class="wk-crumbs wk-mono"><i class="ph ph-hospital"></i><b><?= $e(t('hipobridge.name')) ?></b></div>
<div class="wk-doc-titlerow"><h1 class="wk-doc-title"><?= $e(t('hipobridge.worklist.title')) ?></h1><div class="wk-actions">
<a class="btn btn-secondary" href="<?= $b ?>/new"><i class="ph ph-pencil-simple-line"></i><?= $e(t('hipobridge.worklist.manual')) ?></a>
</div></div>
<?php if ($list['from'] !== ''): ?>
<div class="wk-badges"><span class="tag tag-neutral"><?= $e(t('hipobridge.worklist.count', [\count($list['rows'])])) ?></span><span class="wk-mono wk-dim"><?= $e(t('hipobridge.worklist.range', [\Reporion\Support\MetaText::when($list['from']), \Reporion\Support\MetaText::when($list['to'])])) ?></span></div>
<?php endif; ?>
</div>
<?php if ($error !== null): ?>
<div class="wk-notice" role="alert"><i class="ph ph-warning"></i><div><?= $e(t('hipobridge.err.' . $error)) ?></div></div>
<?php elseif ($list['rows'] === []): ?>
<p class="wk-dim"><?= $e(t('hipobridge.worklist.empty')) ?></p>
<?php else: ?>
<table class="table">
<thead><tr><th><?= $e(t('hipobridge.col.when')) ?></th><th><?= $e(t('hipobridge.col.modality')) ?></th><th><?= $e(t('hipobridge.col.patient')) ?></th><th><?= $e(t('hipobridge.col.ward')) ?></th><th><?= $e(t('hipobridge.col.requester')) ?></th><th></th></tr></thead>
<tbody>
<?php foreach ($list['rows'] as $row): ?>
<tr>
<td class="wk-mono" style="white-space:nowrap"><?= $e(\Reporion\Support\MetaText::when($row['when'])) ?></td>
<td class="wk-mono"><?= $e(\Reporion\Plugin\Hipobridge\Fhir::MODALITIES[$row['modality']] ?? (string) $row['modality']) ?></td>
<td><?= $e((string) $row['patient']) ?></td>
<td class="wk-dim"><?= $e((string) $row['ward']) ?></td>
<td class="wk-dim"><?= $e((string) $row['requester']) ?><?php if ($row['indication'] !== ''): ?><br><small><?= $e((string) $row['indication']) ?></small><?php endif; ?></td>
<td style="text-align:right">
<?php if ($row['report'] !== null): ?>
<a class="btn btn-ghost btn-sm" href="<?= $b ?>/<?= $e((string) $row['report']) ?>"><i class="ph ph-file-text"></i><?= $e(t('hipobridge.worklist.open')) ?></a>
<?php else: ?>
<a class="btn btn-primary btn-sm" data-busy href="<?= $b ?>/new?prefill=hipobridge&amp;ref=<?= $e(rawurlencode((string) $row['ref'])) ?>"><i class="ph ph-plus"></i><?= $e(t('hipobridge.worklist.start')) ?></a>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>
