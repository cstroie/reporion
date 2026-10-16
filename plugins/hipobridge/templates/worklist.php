<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /x/hipobridge/worklist: recent performed exams from the HIS
 * schedule, through HippoBridge. "Start" opens the guided new-report form filled from
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
<header class="wk-doc-head">
<?= \Reporion\Http\Breadcrumb::render([['label' => t('hipobridge.name'), 'icon' => 'hospital']]) ?>
<div class="wk-doc-titlerow"><hgroup><h1 class="wk-doc-title"><?= $e(t('hipobridge.worklist.title')) ?></h1><p class="wk-dim"><?= $e(t('hipobridge.worklist.subtitle')) ?></p></hgroup><div class="wk-actions">
<a class="btn btn-secondary" href="<?= $b ?>/new" title="<?= $e(t('hipobridge.worklist.manual')) ?>"><i class="ph ph-pencil-simple-line"></i><span class="wk-btn-label"><?= $e(t('hipobridge.worklist.manual')) ?></span></a>
</div></div>
</header>
<?php if ($error !== null): ?>
<?php $noticeKind = 'error'; $noticeText = t('hipobridge.err.' . $error); $noticeClass = 'wk-mb-4'; include \dirname(__DIR__, 3) . '/templates/partials/notice.php'; ?>
<?php else: ?>
<div class="wk-panel">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('hipobridge.worklist.results')) ?><span class="wk-count"><?= \count($list['rows']) ?></span></h2></hgroup><?php if (($modality ?? null) !== null): ?><a class="tag tag-outline" href="<?= $b ?>/x/hipobridge/worklist" title="<?= $e(t('hipobridge.worklist.all_modalities')) ?>"><?= $e($modality) ?> <i class="ph ph-x" aria-hidden="true"></i></a><?php endif; ?><?php if ($list['from'] !== ''): ?><span class="wk-mono wk-dim wk-text-sm"><?= $e(t('hipobridge.worklist.range', [\Reporion\Support\MetaText::when($list['from']), \Reporion\Support\MetaText::when($list['to'])])) ?></span><?php endif; ?></header>
<?php if ($list['rows'] === []): ?>
<div class="wk-empty"><i class="ph ph-magnifying-glass"></i><p><?= $e(t('hipobridge.worklist.empty')) ?></p></div>
<?php else: ?>
<table class="table table-cards">
<thead><tr><th><?= $e(t('hipobridge.col.patient')) ?></th><th><?= $e(t('hipobridge.col.modality')) ?></th><th><?= $e(t('hipobridge.col.date')) ?></th><th><?= $e(t('hipobridge.col.status')) ?></th><th><?= $e(t('hipobridge.col.ward')) ?></th><th class="wk-hide-md"><?= $e(t('hipobridge.col.requester')) ?></th><th></th></tr></thead>
<tbody>
<?php foreach ($list['rows'] as $row): ?>
<tr>
<?php [$nameLast, $nameFirst] = array_pad(explode(' ', (string) $row['patient'], 2), 2, ''); ?>
<td data-label="<?= $e(t('hipobridge.col.patient')) ?>"><div class="wk-cell"><span class="wk-name-last"><?= $e($nameLast) ?></span><?= $nameFirst !== '' ? ' ' . $e($nameFirst) : '' ?></div></td>
<td class="wk-nowrap" data-label="<?= $e(t('hipobridge.col.modality')) ?>"><div class="wk-cell"><span class="tag tag-outline"><?= $e(\Reporion\Plugin\Hipobridge\Fhir::MODALITIES[$row['modality']] ?? (string) $row['modality']) ?></span></div></td>
<?php [$whenDate, $whenTime] = array_pad(explode(' ', $row['performed'] !== '' ? \Reporion\Support\MetaText::when($row['performed']) : '', 2), 2, ''); ?>
<td class="wk-mono" data-label="<?= $e(t('hipobridge.col.date')) ?>"><div class="wk-cell"><?php if ($whenDate === ''): ?><span class="wk-dim">n/a</span><?php else: ?><span class="wk-nowrap"><?= $e($whenDate) ?></span><?= $whenTime !== '' ? ' <span class="wk-nowrap">' . $e($whenTime) . '</span>' : '' ?><?php endif; ?></div></td>
<td data-label="<?= $e(t('hipobridge.col.status')) ?>"><div class="wk-cell"><span class="tag tag-st-<?= $e((string) $row['status']) ?>"><?= $e(t('hipobridge.status.' . $row['status'])) ?></span></div></td>
<td class="wk-dim" data-label="<?= $e(t('hipobridge.col.ward')) ?>"><div class="wk-cell"><?= $e((string) $row['ward']) ?></div></td>
<td class="wk-dim wk-hide-md" data-label="<?= $e(t('hipobridge.col.requester')) ?>"><div class="wk-cell"><?= $e((string) $row['requester']) ?><?php if ($row['indication'] !== ''): ?><div class="wk-text-sm"><?= $e((string) $row['indication']) ?></div><?php endif; ?></div></td>
<td class="wk-right">
<?php if ($row['report'] !== null): ?>
<a class="btn btn-secondary btn-sm" href="<?= $b ?>/<?= $e((string) $row['report']) ?>"><i class="ph ph-file-text"></i><?= $e(t('hipobridge.worklist.open')) ?></a>
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
<?php endif; ?>
</div>
