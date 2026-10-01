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
 * string $patientKey; ?string $patientKeyWeak; list<array<string,mixed>> $possibleMatches
 * (each with a bool 'canAllocate'); ?string $mergeStatus ('ok'|'nokey'|'conflict')
 * bool $canWrite; string $basePath
 */

declare(strict_types=1);

/** @var string $path */
/** @var list<array<string, mixed>> $pages */
/** @var string $patientKey */
/** @var ?string $patientKeyWeak */
/** @var list<array<string, mixed>> $possibleMatches */
/** @var ?string $mergeStatus */
/** @var bool $canWrite */
/** @var string $basePath */
?>
<div class="wk-doc">
<?php if ($patientKey === '' && $patientKeyWeak === ''): ?>
<p><?= htmlspecialchars(t('timeline.no_patient'), ENT_QUOTES) ?></p>
<?php else: ?>
<?php if (($mergeStatus ?? null) !== null): ?>
<p role="alert"><?= htmlspecialchars(t('timeline.merge_' . $mergeStatus), ENT_QUOTES) ?></p>
<?php endif; ?>
<div class="wk-doc-titlerow wk-sec"><hgroup>
<h2 class="wk-sec-title"><?= htmlspecialchars((int) $stats['studies'] === 1 ? t('timeline.heading_one') : t('timeline.heading', [(int) $stats['studies']]), ENT_QUOTES) ?></h2>
<p class="wk-dim"><?= htmlspecialchars(t('timeline.subtitle'), ENT_QUOTES) ?></p>
</hgroup></div>
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
<div class="wk-row-t"><a href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($pagePath, ENT_QUOTES) ?>"><?= htmlspecialchars((string) (($page['exam_title'] ?? '') ?: $page['title'] ?: $pagePath), ENT_QUOTES) ?></a><span class="tag <?= $page['status'] === 'signed' ? 'tag-accent' : 'tag-neutral' ?>"><?= htmlspecialchars((string) $page['status'], ENT_QUOTES) ?></span></div>
<div class="wk-row-m wk-mono"><?= htmlspecialchars(implode(' · ', array_filter([(string) ($page['modality'] ?? ''), (string) ($page['site'] ?? ''), (string) ($page['region'] ?? ''), (string) ($page['device'] ?? ''), (string) (($page['exam_accessions'] ?? '') ?: ($page['accession'] ?? ''))])), ENT_QUOTES) ?></div>
<?php if (($page['summary'] ?? '') !== ''): ?>
<div class="wk-row-s"><?= htmlspecialchars((string) $page['summary'], ENT_QUOTES) ?></div>
<?php endif; ?>
</div>
</div>
<?php endforeach; ?>
</div>
<?php if ($possibleMatches !== []): ?>
<?php /* TODO 13: name-matched, not key-matched — a suggestion to preview; "Confirm same patient" writes patient.key on the target (Service\PatientMerge), never automatic. "Not the same patient" only hides the row here, nothing persists */ ?>
<div class="wk-panel" style="margin-top:var(--space-5)">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= htmlspecialchars(t('timeline.possible_matches'), ENT_QUOTES) ?></h2><p class="wk-dim"><?= htmlspecialchars(t('timeline.possible_matches_help'), ENT_QUOTES) ?></p></hgroup></header>
<div class="wk-res">
<?php foreach ($possibleMatches as $match): ?>
<div class="wk-resrow" data-match-row>
<div class="wk-row-t"><a href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars((string) $match['path'], ENT_QUOTES) ?>" target="_blank" rel="noopener"><?= htmlspecialchars((string) $match['title'], ENT_QUOTES) ?></a></div>
<div class="wk-row-m wk-mono"><?= htmlspecialchars(implode(' · ', array_filter([(string) ($match['modality'] ?? ''), \Reporion\Support\MetaText::date($match['study_date'] ?? null, 'd M Y')])), ENT_QUOTES) ?></div>
<div class="wk-actions">
<?php if ($match['canAllocate'] ?? false): ?>
<form method="post" action="<?= htmlspecialchars($basePath . '/' . $path . '/patient-merge', ENT_QUOTES) ?>" data-confirm="<?= htmlspecialchars(t('timeline.confirm_match_prompt'), ENT_QUOTES) ?>" data-confirm-label="<?= htmlspecialchars(t('timeline.confirm_match'), ENT_QUOTES) ?>" data-confirm-tone="primary">
<input type="hidden" name="target" value="<?= htmlspecialchars((string) $match['path'], ENT_QUOTES) ?>">
<button type="submit" class="btn btn-primary btn-sm"><?= htmlspecialchars(t('timeline.confirm_match'), ENT_QUOTES) ?></button>
</form>
<?php endif; ?>
<button type="button" class="btn btn-sm btn-ghost" data-dismiss-match><?= htmlspecialchars(t('timeline.dismiss_match'), ENT_QUOTES) ?></button>
</div>
</div>
<?php endforeach; ?>
</div>
</div>
<script>
(function() {
  document.querySelectorAll('[data-dismiss-match]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      btn.closest('[data-match-row]').remove();
    });
  });
})();
</script>
<?php endif; ?>
<?php endif; ?>
</div>
