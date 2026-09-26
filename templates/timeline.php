<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /{path}/timeline (Controller\TimelineController).
 * Patient timeline — all reports for the same patient,
 * ordered by study date desc (design/mockup/WikiTimeline.dc.html: .wk-stats,
 * .wk-tl). Content only: Http\View::page() wraps it in templates/layout.php,
 * whose page header shows the page and its tabs (A6). The stats are counts
 * of the visible studies, never inferred findings; the mockup's AI course
 * summary, "compare two" and "export dossier" are not built.
 *
 * Variables in scope (see Controller\TimelineController::timeline()):
 * string $path, $patientLabel; list<array<string,mixed>> $pages; array $stats;
 * string $patientKey; ?string $patientKeyWeak
 * bool $canWrite; string $basePath
 */

declare(strict_types=1);

/** @var string $path */
/** @var list<array<string, mixed>> $pages */
/** @var string $patientKey */
/** @var ?string $patientKeyWeak */
/** @var bool $canWrite */
/** @var string $basePath */
?>
<div class="wk-doc">
<?php if ($patientKey === '' && $patientKeyWeak === ''): ?>
<p><?= htmlspecialchars(t('timeline.no_patient'), ENT_QUOTES) ?></p>
<?php else: ?>
<div class="wk-doc-titlerow wk-sec"><h2 class="wk-sec-title"><?= htmlspecialchars($patientLabel !== '' ? $patientLabel : t('tabs.patient'), ENT_QUOTES) ?></h2>
<?php if (($newExamPid ?? null) !== null): ?>
<div class="wk-actions"><a class="btn btn-primary btn-sm" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/new?after=<?= htmlspecialchars(rawurlencode($newExamPid), ENT_QUOTES) ?>"><i class="ph ph-user-plus"></i><?= htmlspecialchars(t('timeline.new_exam'), ENT_QUOTES) ?></a></div>
<?php endif; ?>
</div>
<div class="wk-stats">
<div class="wk-stat"><b><?= (int) $stats['studies'] ?></b><span><?= htmlspecialchars(t('timeline.studies'), ENT_QUOTES) ?></span></div>
<div class="wk-stat"><b><?= (int) $stats['modalities'] ?></b><span><?= htmlspecialchars(t('timeline.modalities'), ENT_QUOTES) ?></span></div>
<div class="wk-stat"><b><?= (int) $stats['sites'] ?></b><span><?= htmlspecialchars(t('timeline.sites'), ENT_QUOTES) ?></span></div>
<?php if ($stats['first'] !== ''): ?>
<div class="wk-stat"><b class="wk-stat-date"><?= htmlspecialchars($stats['first'], ENT_QUOTES) ?></b><span><?= htmlspecialchars(t('timeline.first'), ENT_QUOTES) ?></span></div>
<div class="wk-stat"><b class="wk-stat-date"><?= htmlspecialchars($stats['last'], ENT_QUOTES) ?></b><span><?= htmlspecialchars(t('timeline.last'), ENT_QUOTES) ?></span></div>
<?php endif; ?>
</div>
<div class="wk-tl">
<?php foreach ($pages as $page): ?>
<?php $pagePath = (string) $page['path']; ?>
<div class="wk-tl-i<?= $pagePath === $path ? ' wk-sel' : '' ?>">
<div class="wk-mono wk-dim"><?= htmlspecialchars(\Reporion\Support\MetaText::date($page['study_date'] ?? null, 'd M Y'), ENT_QUOTES) ?></div>
<div class="wk-tl-dot"></div>
<div>
<div class="wk-row-t"><a href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($pagePath, ENT_QUOTES) ?>"><?= htmlspecialchars((string) ($page['title'] ?: $pagePath), ENT_QUOTES) ?></a><span class="tag <?= $page['status'] === 'signed' ? 'tag-accent' : 'tag-neutral' ?>"><?= htmlspecialchars((string) $page['status'], ENT_QUOTES) ?></span></div>
<div class="wk-row-m wk-mono"><?= htmlspecialchars(implode(' · ', array_filter([(string) ($page['modality'] ?? ''), (string) ($page['site'] ?? ''), (string) ($page['region'] ?? ''), (string) ($page['device'] ?? ''), (string) ($page['accession'] ?? '')])), ENT_QUOTES) ?></div>
<?php if (($page['summary'] ?? '') !== ''): ?>
<div class="wk-row-s"><?= htmlspecialchars((string) $page['summary'], ENT_QUOTES) ?></div>
<?php endif; ?>
</div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
