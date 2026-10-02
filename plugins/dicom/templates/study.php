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
 * string $dayShown; ?string $error; ?bool $done; string $basePath;
 * array $sr (SrSender::state()); ?array{outcome: string, log: string, sent: int, of: int} $srSent; bool $srLog
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
/** @var array{why: ?string, site: string, targets: list<array{uid: string, accession: string, exam: string}>, deliveries: list<array<string, mixed>>, sentRev: ?int, signed: bool} $sr */
/** @var ?array{outcome: string, log: string, sent: int, of: int} $srSent */
/** @var bool $srLog */

$b = htmlspecialchars($basePath, ENT_QUOTES);
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$self = $b . '/x/dicom/study/' . $e(rawurlencode($page->pid));
$site = $lookup['site'] ?? null;
$day = $lookup['day'] ?? $dayShown;
?>
<div class="wk-doc">
<div class="wk-doc-titlerow wk-sec"><hgroup><h2 class="wk-sec-title"><i class="ph ph-monitor"></i> <?= $e(t('dicom.study.title')) ?></h2><p class="wk-dim"><?= $e(t('dicom.study.subtitle')) ?></p></hgroup></div>
<?php /* Phase 22b: the signed report's SR to this site's PACS (SrSender) — a button, never automatic */ ?>
<div class="wk-panel wk-mb-4" id="sr">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><i class="ph ph-paper-plane-tilt"></i> <?= $e(t('dicom.sr.title')) ?></h2>
<p class="wk-dim"><?php if ($sr['sentRev'] !== null): ?><?= $e(t('dicom.sr.sent_rev', [$sr['sentRev']])) ?><?php if ($sr['signed'] && $page->rev > $sr['sentRev']): ?> · <strong><?= $e(t('dicom.sr.not_sent_rev', [$page->rev])) ?></strong><?php endif; ?><?php else: ?><?= $e(t('dicom.sr.never')) ?><?php endif; ?></p></hgroup>
<?php if ($sr['why'] === null): ?>
<form method="post" action="<?= $b ?>/x/dicom/send/<?= $e(rawurlencode($page->pid)) ?>" data-busy><button class="btn btn-primary btn-sm" type="submit"><i class="ph ph-paper-plane-tilt"></i><?= $e(t('dicom.sr.send')) ?></button></form>
<?php endif; ?>
</header>
<?php if ($srSent !== null && $srSent['outcome'] === 'ok'): ?>
<div class="wk-notice" role="status"><i class="ph ph-check"></i><div><?= $e(t('dicom.sr.done')) ?></div></div>
<?php elseif ($srSent !== null): ?>
<div class="wk-notice" role="alert"><i class="ph ph-warning"></i><div><?= $e(t('dicom.err.' . $srSent['outcome'])) ?><?php if ($srSent['of'] > 1): ?> <?= $e(t('dicom.sr.partial', [$srSent['sent'], $srSent['of']])) ?><?php endif; ?>
<?php if ($srLog && $srSent['log'] !== ''): ?><details><summary><?= $e(t('dicom.sr.log')) ?></summary><pre class="wk-mono wk-text-sm"><?= $e($srSent['log']) ?></pre></details><?php endif; ?></div></div>
<?php endif; ?>
<?php if ($sr['why'] !== null): ?>
<p class="wk-dim wk-text-sm"><?= $e(t('dicom.sr.why.' . $sr['why'], [$sr['site']])) ?></p>
<?php else: ?>
<p class="wk-dim wk-text-sm"><?= $e(t('dicom.sr.into', [$sr['site'], implode(', ', array_map(static fn (array $s): string => $s['exam'] !== '' ? $s['exam'] : $s['uid'], $sr['targets']))])) ?></p>
<?php endif; ?>
<?php if ($sr['deliveries'] !== []): ?>
<table class="table wk-text-sm">
<tbody>
<?php foreach (array_slice($sr['deliveries'], 0, 5) as $d): ?>
<tr><td class="wk-mono"><?= $e(\Reporion\Support\MetaText::when($d['at'] ?? null)) ?></td><td class="wk-mono">rev <?= (int) ($d['rev'] ?? 0) ?></td><td><?= $e(display_name((string) ($d['by'] ?? ''))) ?></td><td><?php if (($d['outcome'] ?? '') === 'ok'): ?><span class="tag tag-signed"><?= $e(t('dicom.sr.ok')) ?></span><?php else: ?><span class="tag tag-caution"><?= $e((string) ($d['outcome'] ?? '')) ?></span><?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>
<?php if ($done !== null): ?>
<div class="wk-notice wk-mb-4" role="status"><i class="ph ph-check"></i><div><?= $e(t($done ? 'dicom.study.done' : 'dicom.study.nothing')) ?></div></div>
<?php endif; ?>
<?php if ($error !== null): ?>
<div class="wk-notice wk-mb-4" role="alert"><i class="ph ph-warning"></i><div><?= $e(t('dicom.err.' . $error)) ?></div></div>
<?php endif; ?>
<?php if ($servers === []): ?>
<div class="wk-notice" role="alert"><i class="ph ph-warning"></i><div><?= $e(t('dicom.err.not-configured')) ?></div></div>
<?php else: ?>
<?php /* One joined bar (.group). A POST: the name and CNP are the patient's and never go in a URL (D1). Both empty = every study of the day */ ?>
<form method="post" action="<?= $self ?>" class="group group-fill group-stack wk-mb-4" role="search" data-busy>
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
<div class="wk-panel"><div class="wk-empty"><i class="ph ph-calendar-blank"></i><p><?= $e(t('dicom.study.no_day')) ?></p></div></div>
<?php elseif ($lookup !== null && $lookup['rows'] === []): ?>
<div class="wk-panel"><div class="wk-empty"><i class="ph ph-magnifying-glass"></i><p><?= $e(t($lookup['byPatient'] ? 'dicom.study.none_patient' : 'dicom.study.none', $lookup['byPatient'] ? [$lookup['window']] : [])) ?></p></div></div>
<?php elseif ($lookup !== null): ?>
<div class="wk-panel">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('dicom.worklist.results')) ?><span class="wk-count"><?= \count($lookup['rows']) ?></span></h2>
<?php if (!$lookup['byPatient']): ?><p class="wk-dim"><?= $e(t('dicom.study.explain')) ?></p>
<?php elseif ($lookup['window'] > 0): ?><p class="wk-dim"><?= $e(t('dicom.study.window', [$day, $lookup['window']])) ?></p>
<?php endif; ?></hgroup></header>
<table class="table table-cards">
<thead><tr><th><?= $e(t('dicom.col.when')) ?></th><th><?= $e(t('dicom.col.modality')) ?></th><th><?= $e(t('dicom.col.patient')) ?></th><th><?= $e(t('dicom.col.description')) ?></th><th></th></tr></thead>
<tbody>
<?php foreach ($lookup['rows'] as $row): ?>
<tr<?= $row['match'] !== '' ? ' class="wk-sel"' : '' ?>>
<td class="wk-mono wk-nowrap" data-label="<?= $e(t('dicom.col.when')) ?>"><div class="wk-cell"><?= $e((string) $row['when']) ?><?php if ($dayShown !== '' && substr((string) $row['when'], 0, 10) === $dayShown): ?> <i class="ph ph-check-circle wk-signed-mark" title="<?= $e(t('dicom.match.date')) ?>" aria-label="<?= $e(t('dicom.match.date')) ?>"></i><?php endif; ?></div></td>
<td data-label="<?= $e(t('dicom.col.modality')) ?>"><div class="wk-cell"><span class="tag tag-outline"><?= $e((string) $row['modality']) ?></span></div></td>
<td data-label="<?= $e(t('dicom.col.patient')) ?>"><div class="wk-cell"><?= $e((string) $row['patient']) ?><?php if ($row['match'] !== ''): ?> <i class="ph <?= $e(['uid' => 'ph-link', 'cnp' => 'ph-seal-check', 'name' => 'ph-check-circle'][$row['match']]) ?> wk-signed-mark" title="<?= $e(t('dicom.match.' . $row['match'])) ?>" aria-label="<?= $e(t('dicom.match.' . $row['match'])) ?>"></i><?php endif; ?>
<?php $meta = trim((string) ($row['born'] ?? '') . ' ' . (string) ($row['sex'] ?? '')); if ($meta !== ''): ?><div class="wk-mono wk-dim wk-text-sm"><?= $e($meta) ?></div><?php endif; ?></div></td>
<td class="wk-dim" data-label="<?= $e(t('dicom.col.description')) ?>"><div class="wk-cell"><?= $e((string) $row['description']) ?><?php if ((string) $row['accession'] !== ''): ?><div class="wk-mono wk-text-sm"><?= $e((string) $row['accession']) ?></div><?php endif; ?></div></td>
<td class="wk-right">
<form method="post" action="<?= $self ?>" data-busy><input type="hidden" name="site" value="<?= $e((string) $row['site']) ?>"><input type="hidden" name="uid" value="<?= $e((string) $row['uid']) ?>"><input type="hidden" name="day" value="<?= $e($day) ?>">
<button class="btn <?= $row['match'] !== '' ? 'btn-primary' : 'btn-secondary' ?> btn-sm" type="submit"><i class="ph ph-link"></i><?= $e(t('dicom.study.link')) ?></button></form>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
<?php endif; ?>
</div>
