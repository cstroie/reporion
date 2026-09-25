<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /search first-result-page (docs/architecture-api.md §1 Table 1).
 * Works with JS disabled; live facets are still later, separate work.
 * The palette (assets/js/palette.js) mounts on this page's .wk-search
 * form too, same as every other signed-in template.
 *
 * Structure/classes ported from design/mockup/WikiSearch.dc.html
 * (.wk-doc / .wk-res / .wk-resrow / .wk-facet / .wk-pathb /
 * .wk-doc-titlerow / .wk-pal-ai / .wk-score / .wk-count).
 * Deliberately NOT ported: the live facet sidebar, saved queries
 * and the AI-answer-over-results box — none of those exist yet
 * (facets are explicitly a later JS island per
 * docs/architecture-api.md, and AI ships disabled by default per D15).
 *
 * Variables in scope: string $term; list<array{pid,path,title,visibility,status,site,study_date,device,snippet,snippet_html,score}> $results; string $basePath
 */

declare(strict_types=1);

/** @var string $term */
/** @var list<array<string, mixed>> $results */
/** @var string $basePath */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(t('search.title'), ENT_QUOTES) ?> — <?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?></title>
<link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/css/tokens.css">
<link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/css/wiki.css">
</head>
<body class="wk">
<div class="wk-top">
<span class="wk-brand"><?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?></span>
<form class="wk-search" action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/search" method="get" data-island="palette" data-config-id="palette-config">
<input type="search" name="q" value="<?= htmlspecialchars($term, ENT_QUOTES) ?>" placeholder="<?= htmlspecialchars(t('nav.search'), ENT_QUOTES) ?>">
</form>
<script type="application/json" id="palette-config"><?= json_encode(['basePath' => $basePath], JSON_HEX_TAG) ?></script>
</div>
<main class="wk-pad">
<div class="wk-doc">
<div class="wk-doc-head">
<?php if ($term !== ''): ?>
<div class="wk-crumbs wk-mono"><i class="ph ph-magnifying-glass"></i><b><?= htmlspecialchars(t('search.title'), ENT_QUOTES) ?></b><i class="ph ph-caret-right"></i><span><?= htmlspecialchars($term, ENT_QUOTES) ?></span></div>
<div class="wk-doc-titlerow"><h1 class="wk-doc-title"><?= htmlspecialchars(t('search.match_count', [count($results)]), ENT_QUOTES) ?></h1><div class="wk-actions"><button class="btn btn-secondary"><?= htmlspecialchars(t('search.save_query'), ENT_QUOTES) ?></button><button class="btn btn-secondary"><?= htmlspecialchars(t('search.export_csv'), ENT_QUOTES) ?></button><button class="btn btn-primary"><?= htmlspecialchars(t('search.ask_over'), ENT_QUOTES) ?></button></div></div>
<div class="wk-pathb"><i class="ph ph-magnifying-glass"></i><span><?= htmlspecialchars($term, ENT_QUOTES) ?></span><span class="wk-dim">mod:MR region:neuro date:&gt;2026-01 -status:draft</span><span class="wk-tflex"></span><span class="wk-dim">fts5 · 34 ms</span></div>
<?php else: ?>
<h1 class="wk-doc-title"><?= htmlspecialchars(t('search.title'), ENT_QUOTES) ?></h1>
<?php endif; ?>
</div>

<?php if ($term === ''): ?>
<p class="wk-dim"><?= htmlspecialchars(t('search.prompt'), ENT_QUOTES) ?></p>
<?php elseif ($results === []): ?>
<p class="wk-dim"><?= htmlspecialchars(t('search.noresults', [$term]), ENT_QUOTES) ?></p>
<?php else: ?>
<div class="wk-two">
<div>
<div class="wk-facet"><span class="wk-eyebrow"><?= htmlspecialchars(t('search.facet_modality'), ENT_QUOTES) ?></span><label class="radio"><input type="checkbox" checked="checked" /><span class="dot"></span>MR<span class="wk-count">41</span></label><label class="radio"><input type="checkbox" /><span class="dot"></span>CT<span class="wk-count">6</span></label><label class="radio"><input type="checkbox" /><span class="dot"></span>US<span class="wk-count">1</span></label></div>
<div class="wk-facet"><span class="wk-eyebrow"><?= htmlspecialchars(t('search.facet_region'), ENT_QUOTES) ?></span><label class="radio"><input type="checkbox" checked="checked" /><span class="dot"></span>neuro<span class="wk-count">44</span></label><label class="radio"><input type="checkbox" /><span class="dot"></span>spine<span class="wk-count">3</span></label><label class="radio"><input type="checkbox" /><span class="dot"></span>orbits<span class="wk-count">1</span></label></div>
<div class="wk-facet"><span class="wk-eyebrow"><?= htmlspecialchars(t('search.facet_site'), ENT_QUOTES) ?></span><label class="radio"><input type="checkbox" /><span class="dot"></span>mioveni<span class="wk-count">18</span></label><label class="radio"><input type="checkbox" /><span class="dot"></span>pitesti<span class="wk-count">21</span></label><label class="radio"><input type="checkbox" /><span class="dot"></span>colentina<span class="wk-count">9</span></label></div>
<div class="wk-facet"><span class="wk-eyebrow"><?= htmlspecialchars(t('search.facet_device'), ENT_QUOTES) ?></span><label class="radio"><input type="checkbox" /><span class="dot"></span>Aera 1.5 T<span class="wk-count">26</span></label><label class="radio"><input type="checkbox" /><span class="dot"></span>Skyra 3 T<span class="wk-count">15</span></label></div>
<div class="wk-facet"><span class="wk-eyebrow"><?= htmlspecialchars(t('search.facet_status'), ENT_QUOTES) ?></span><label class="radio"><input type="checkbox" checked="checked" /><span class="dot"></span>signed<span class="wk-count">44</span></label><label class="radio"><input type="checkbox" /><span class="dot"></span>amended<span class="wk-count">4</span></label><label class="radio"><input type="checkbox" /><span class="dot"></span>draft<span class="wk-count">2</span></label></div>
<div class="wk-facet"><span class="wk-eyebrow"><?= htmlspecialchars(t('search.facet_tag'), ENT_QUOTES) ?></span><span class="wk-filters"><span class="wk-chip wk-chip-on">SM</span><span class="wk-chip">follow-up</span><span class="wk-chip">activ</span><span class="wk-chip">McDonald</span></span></div>
<div class="wk-facet"><span class="wk-eyebrow"><?= htmlspecialchars(t('search.facet_saved'), ENT_QUOTES) ?></span><a href="#" class="wk-mono" style="font-size:12.5px"><?= htmlspecialchars(t('search.saved_query_1'), ENT_QUOTES) ?></a><a href="#" class="wk-mono" style="font-size:12.5px"><?= htmlspecialchars(t('search.saved_query_2'), ENT_QUOTES) ?></a><a href="#" class="wk-mono" style="font-size:12.5px"><?= htmlspecialchars(t('search.saved_query_3'), ENT_QUOTES) ?></a></div>
</div>
<div>
<div class="wk-pal-ai"><div class="wk-pal-ai-h"><i class="ph ph-sparkle"></i><span class="wk-eyebrow"><?= htmlspecialchars(t('search.ai_answer'), ENT_QUOTES) ?></span><span class="wk-mono wk-dim">grounded · 6 citations</span></div><p><?= htmlspecialchars(t('search.ai_answer_body'), ENT_QUOTES) ?></p></div>
<div class="wk-res">
<?php foreach ($results as $result): ?>
<div class="wk-resrow">
<div class="wk-row-t"><a href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars((string) $result['path'], ENT_QUOTES) ?>"><?= htmlspecialchars((string) $result['title'], ENT_QUOTES) ?></a><span class="tag tag-<?= htmlspecialchars((string) ($result['visibility'] === 'public' ? 'outline' : ($result['visibility'] === 'unlisted' ? 'accent' : 'neutral')), ENT_QUOTES) ?>"><?= htmlspecialchars((string) $result['visibility'], ENT_QUOTES) ?></span><span class="wk-score"><?= htmlspecialchars(number_format((float) ($result['score'] ?? 0), 2), ENT_QUOTES) ?></span></div>
<div class="wk-row-m wk-mono"><?= htmlspecialchars((string) $result['path'], ENT_QUOTES) ?> · <?= htmlspecialchars((string) ($result['modality'] ?? ''), ENT_QUOTES) ?> · <?= htmlspecialchars((string) ($result['device'] ?? ''), ENT_QUOTES) ?> · <?= htmlspecialchars((string) ($result['study_date'] ?? ''), ENT_QUOTES) ?></div>
<div class="wk-row-s"><?= $result['snippet_html'] /* already escaped + <mark>-substituted by Sqlite::highlightSnippet(), see SearchController */ ?></div>
</div>
<?php endforeach; ?>
</div>
<p class="wk-mono wk-dim" style="margin-top:var(--space-4)"><?= htmlspecialchars(t('search.shown_count', [min(6, count($results)), count($results)]), ENT_QUOTES) ?> · <a href="#"><?= htmlspecialchars(t('search.load_more'), ENT_QUOTES) ?></a> · ranked by bm25 + cosine, 0.6/0.4</p>
</div>
</div>
<?php endif; ?>
</div>
</main>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/js/palette.js" defer></script>
</body>
</html>
