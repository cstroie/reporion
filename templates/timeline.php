<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /{path}/timeline (Controller\TimelineController).
 * Patient timeline — all reports for the same patient,
 * ordered by study date desc (design/mockup/WikiTimeline.dc.html: .wk-stats,
 * .wk-tl). Content only: Http\View::page() wraps it in templates/layout.php,
 * whose page header shows the page and its tabs (A6). The stats are counts
 * of the visible studies, never inferred findings. The mockup's AI course
 * panel is the Evolution panel, shown when the `evolution` prompt exists.
 * The studies' checkboxes serve Join (POST /join, writers) and Compare
 * (GET /{path}/compare with the two ticked, any reader — phase 17a).
 * "Export dossier" was dropped (17c).
 *
 * Variables in scope (see Controller\TimelineController::timeline()):
 * string $path, $patientLabel; list<array<string,mixed>> $pages; array $stats;
 * string $patientKey; ?string $patientKeyWeak; list<array<string,mixed>> $possibleMatches
 * (each with a bool 'canAllocate'); ?string $mergeStatus ('ok'|'nokey'|'conflict');
 * bool $canPick, $canJoin; bool $comparePick (a Compare pick that was not two studies)
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
<?php if ($comparePick ?? false): ?>
<p role="alert"><?= htmlspecialchars(t('timeline.compare_pick'), ENT_QUOTES) ?></p>
<?php endif; ?>
<div class="wk-doc-titlerow wk-sec"><hgroup>
<h2 class="wk-sec-title"><?= htmlspecialchars((int) $stats['studies'] === 1 ? t('timeline.heading_one') : t('timeline.heading', [(int) $stats['studies']]), ENT_QUOTES) ?></h2>
<p class="wk-dim"><?= htmlspecialchars(t('timeline.subtitle'), ENT_QUOTES) ?></p>
</hgroup></div>
<?php /* A <dl>: each figure its label (dt) and value (dd); .wk-stat shows the value above */ ?>
<dl class="wk-stats">
<div class="wk-stat"><dt><?= htmlspecialchars(t('timeline.studies'), ENT_QUOTES) ?></dt><dd><?= (int) $stats['studies'] ?></dd></div>
<div class="wk-stat"><dt><?= htmlspecialchars(t('timeline.modalities'), ENT_QUOTES) ?></dt><dd><?= (int) $stats['modalities'] ?></dd></div>
<div class="wk-stat"><dt><?= htmlspecialchars(t('timeline.sites'), ENT_QUOTES) ?></dt><dd><?= (int) $stats['sites'] ?></dd></div>
<?php if ($stats['first'] !== ''): ?>
<div class="wk-stat"><dt><?= htmlspecialchars(t('timeline.first'), ENT_QUOTES) ?></dt><dd class="wk-stat-date"><?= htmlspecialchars($stats['first'], ENT_QUOTES) ?></dd></div>
<div class="wk-stat"><dt><?= htmlspecialchars(t('timeline.last'), ENT_QUOTES) ?></dt><dd class="wk-stat-date"><?= htmlspecialchars($stats['last'], ENT_QUOTES) ?></dd></div>
<?php endif; ?>
</dl>
<?php if ($aiEvolution ?? false): ?>
<?php /* Evolution (the reserved `evolution` prompt, design/mockup/WikiTimeline.dc.html's AI panel): the
 * patient's reports, de-identified, asked how the findings changed; shown, never written — assets/js/ai-evolution.js */ ?>
<section class="wk-panel wk-ai-evo" aria-labelledby="tl-evo-h" aria-live="polite">
<header class="wk-panel-h"><h2 class="wk-eyebrow" id="tl-evo-h"><i class="ph ph-sparkle" aria-hidden="true"></i> <?= htmlspecialchars(t('ai.evolution.title'), ENT_QUOTES) ?></h2><span class="wk-mono wk-dim" data-ai-evo-meta></span></header>
<div class="wk-ai-evo-body" data-ai-evo-body><p class="wk-dim"><?= htmlspecialchars(t('ai.evolution.help'), ENT_QUOTES) ?></p></div>
<div class="wk-ai-row"><button type="button" class="btn btn-primary btn-sm" data-ai-evo><i class="ph ph-sparkle" aria-hidden="true"></i><?= htmlspecialchars(t('ai.evolution.button'), ENT_QUOTES) ?></button><button type="button" class="btn btn-secondary btn-sm" data-ai-evo-copy hidden><?= htmlspecialchars(t('editor.ai.copy'), ENT_QUOTES) ?></button></div>
</section>
<script type="application/json" id="ai-evo-config"><?= json_encode([
    'basePath' => $basePath,
    'path' => $path,
    'strings' => ['working' => t('editor.ai.working'), 'failed' => t('editor.ai.failed'), 'copied' => t('editor.tb.copied')],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'marked.js'), ENT_QUOTES) ?>" defer></script>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/markdown-preview.js'), ENT_QUOTES) ?>" defer></script>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/ai-evolution.js'), ENT_QUOTES) ?>" defer></script>
<?php endif; ?>
<?php /* Join (phase 29): tick the reports of one visit, Join shows the check screen at /join.
 * Compare (phase 17a): tick two, the same form sent as a GET to /{path}/compare */ ?>
<?php if ($canPick ?? false): ?><form method="post" action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/join" class="wk-tl-join" data-tl-pick><input type="hidden" name="back" value="<?= htmlspecialchars('/' . $path . '/timeline', ENT_QUOTES) ?>"><?php endif; ?>
<?php /* Newest first: an ordered list */ ?>
<ol class="wk-tl">
<?php foreach ($pages as $page): ?>
<?php
$pagePath = (string) $page['path'];
$isThis = $pagePath === $path;
$examName = (string) (($page['exam_title'] ?? '') ?: $page['title'] ?: $pagePath);
$day = \Reporion\Support\MetaText::date($page['study_date'] ?? null, \Reporion\Support\MetaText::DATE);
$dayIso = \Reporion\Support\MetaText::date($page['study_date'] ?? null, 'Y-m-d');
?>
<li class="wk-tl-i<?= $isThis ? ' wk-sel' : '' ?>">
<div class="wk-mono wk-dim"><?php if ($canPick ?? false): ?><label class="radio wk-tl-pick"><input type="checkbox" name="paths[]" value="<?= htmlspecialchars($pagePath, ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('timeline.pick', [$examName, $day]), ENT_QUOTES) ?>"><span class="dot"></span></label><?php endif; ?><?php if ($dayIso !== ''): ?><time datetime="<?= htmlspecialchars($dayIso, ENT_QUOTES) ?>"><?= htmlspecialchars($day, ENT_QUOTES) ?></time><?php endif; ?></div>
<div class="wk-tl-dot" aria-hidden="true"></div>
<div>
<div class="wk-row-t"><a href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($pagePath, ENT_QUOTES) ?>"<?= $isThis ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($examName, ENT_QUOTES) ?></a><span class="tag <?= \Reporion\Support\Badges::statusTag((string) $page['status']) ?>"><?= htmlspecialchars((string) $page['status'], ENT_QUOTES) ?></span></div>
<div class="wk-row-m wk-mono"><?= htmlspecialchars(implode(' · ', array_filter([(string) ($page['modality'] ?? ''), (string) ($page['site'] ?? ''), (string) ($page['region'] ?? ''), (string) ($page['device'] ?? ''), (string) (($page['exam_accessions'] ?? '') ?: ($page['accession'] ?? ''))])), ENT_QUOTES) ?></div>
<?php if (($page['summary'] ?? '') !== ''): ?>
<div class="wk-row-s"><?= htmlspecialchars((string) $page['summary'], ENT_QUOTES) ?></div>
<?php endif; ?>
</div>
</li>
<?php endforeach; ?>
</ol>
<?php if ($canPick ?? false): ?><div class="wk-actions">
<button class="btn btn-secondary btn-sm" type="submit" formmethod="get" formaction="<?= htmlspecialchars($basePath . '/' . $path . '/compare', ENT_QUOTES) ?>" title="<?= htmlspecialchars(t('timeline.compare_help'), ENT_QUOTES) ?>" data-tl-compare><i class="ph ph-columns" aria-hidden="true"></i><?= htmlspecialchars(t('timeline.compare'), ENT_QUOTES) ?></button>
<?php if ($canJoin ?? false): ?><button class="btn btn-secondary btn-sm" type="submit" title="<?= htmlspecialchars(t('ns.bulk_join_help'), ENT_QUOTES) ?>"><i class="ph ph-stack" aria-hidden="true"></i><?= htmlspecialchars(t('ns.bulk_join'), ENT_QUOTES) ?></button><?php endif; ?>
</div></form>
<script>
(function () {
  /* Compare takes exactly two: enabled only then (the server checks again) */
  var form = document.querySelector('[data-tl-pick]');
  var button = form && form.querySelector('[data-tl-compare]');
  if (!button) return;
  function sync() { button.disabled = form.querySelectorAll('input[name="paths[]"]:checked').length !== 2; }
  form.addEventListener('change', sync);
  sync();
})();
</script>
<?php endif; ?>
<?php if ($possibleMatches !== []): ?>
<?php /* TODO 13: name-matched, not key-matched — a suggestion to preview; "Confirm same patient" writes patient.key on the target (Service\PatientMerge), never automatic. "Not the same patient" only hides the row here, nothing persists */ ?>
<section class="wk-panel wk-mt-5" aria-labelledby="tl-matches-h">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow" id="tl-matches-h"><?= htmlspecialchars(t('timeline.possible_matches'), ENT_QUOTES) ?></h2><p class="wk-dim"><?= htmlspecialchars(t('timeline.possible_matches_help'), ENT_QUOTES) ?></p></hgroup></header>
<div class="wk-res">
<?php foreach ($possibleMatches as $match): ?>
<div class="wk-resrow" data-match-row>
<div class="wk-row-t"><a href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars((string) $match['path'], ENT_QUOTES) ?>" target="_blank" rel="noopener"><?= htmlspecialchars((string) $match['title'], ENT_QUOTES) ?></a></div>
<div class="wk-row-m wk-mono"><?= htmlspecialchars(implode(' · ', array_filter([(string) ($match['modality'] ?? ''), \Reporion\Support\MetaText::date($match['study_date'] ?? null, \Reporion\Support\MetaText::DATE)])), ENT_QUOTES) ?></div>
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
</section>
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
