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
 * (.wk-doc / .wk-res / .wk-resrow / .wk-pathb / .wk-doc-titlerow /
 * .wk-score). Deliberately NOT rendered until they are real: the facet
 * sidebar (the index has no facet query yet), saved queries, CSV export,
 * pagination and the AI answer box (AI ships disabled, D15). Every number
 * on this page comes from the query that produced it — all matches are
 * shown, ranked by FTS5 bm25.
 *
 * Variables in scope: string $term; list<array{pid,path,title,visibility,status,site,study_date,device,snippet,snippet_html,score}> $results; string $basePath
 */

declare(strict_types=1);

/** @var string $term */
/** @var list<array<string, mixed>> $results */
/** @var string $basePath */
?>
<div class="wk-doc">
<div class="wk-doc-head">
<?php if ($term !== ''): ?>
<div class="wk-crumbs wk-mono"><i class="ph ph-magnifying-glass"></i><b><?= htmlspecialchars(t('search.title'), ENT_QUOTES) ?></b><i class="ph ph-caret-right"></i><span><?= htmlspecialchars($term, ENT_QUOTES) ?></span></div>
<div class="wk-doc-titlerow"><h1 class="wk-doc-title"><?= htmlspecialchars(t('search.match_count', [count($results)]), ENT_QUOTES) ?></h1></div>
<div class="wk-pathb"><i class="ph ph-magnifying-glass"></i><span><?= htmlspecialchars($term, ENT_QUOTES) ?></span></div>
<?php else: ?>
<h1 class="wk-doc-title"><?= htmlspecialchars(t('search.title'), ENT_QUOTES) ?></h1>
<?php endif; ?>
</div>

<?php if ($term === ''): ?>
<p class="wk-dim"><?= htmlspecialchars(t('search.prompt'), ENT_QUOTES) ?></p>
<?php elseif ($results === []): ?>
<p class="wk-dim"><?= htmlspecialchars(t('search.noresults', [$term]), ENT_QUOTES) ?></p>
<?php else: ?>
<div class="wk-res">
<?php foreach ($results as $result): ?>
<div class="wk-resrow">
<div class="wk-row-t"><a href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars((string) $result['path'], ENT_QUOTES) ?>"><?= htmlspecialchars((string) $result['title'], ENT_QUOTES) ?></a><span class="tag tag-<?= htmlspecialchars((string) ($result['visibility'] === 'public' ? 'outline' : ($result['visibility'] === 'unlisted' ? 'accent' : 'neutral')), ENT_QUOTES) ?>"><?= htmlspecialchars((string) $result['visibility'], ENT_QUOTES) ?></span><span class="wk-score"><?= htmlspecialchars(number_format((float) ($result['score'] ?? 0), 2), ENT_QUOTES) ?></span></div>
<div class="wk-row-m wk-mono"><?= htmlspecialchars((string) $result['path'], ENT_QUOTES) ?> · <?= htmlspecialchars((string) ($result['modality'] ?? ''), ENT_QUOTES) ?> · <?= htmlspecialchars((string) ($result['device'] ?? ''), ENT_QUOTES) ?> · <?= htmlspecialchars((string) ($result['study_date'] ?? ''), ENT_QUOTES) ?></div>
<div class="wk-row-s"><?= $result['snippet_html'] /* already escaped + <mark>-substituted by Sqlite::highlightSnippet(), see SearchController */ ?></div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
