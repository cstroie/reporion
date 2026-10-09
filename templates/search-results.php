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
 * .wk-score, .wk-two + .wk-facet). The facet sidebar and the pager are
 * real since phase 19: every count is the index's own, over the caller's
 * results (invariant 6), and a value is a plain link that filters by it.
 * Still not rendered: saved queries, CSV export and the AI answer box
 * (D15) — no decision behind them yet.
 *
 * Variables in scope: string $term, $sort ('relevance'|'recent'), $ns; array<string, string> $filters; array<string, list<array{value, n}>> $facets; int $total, $page, $perPage; list<array{pid,path,title,visibility,status,site,study_date,device,snippet,snippet_html,score}> $results; string $basePath
 */

declare(strict_types=1);

/** @var string $term */
/** @var string $sort */
/** @var string $ns */
/** @var list<array<string, mixed>> $results */
/** @var string $basePath */
/** @var array<string, string> $filters */
/** @var array<string, list<array{value: string, n: int}>> $facets */
/** @var int $total */
/** @var int $page */
/** @var int $perPage */
$filters ??= [];
$facets ??= [];
$total ??= \count($results);
$page ??= 1;
$perPage ??= max(1, \count($results));
$e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES);
// This search's URL with some parameters changed (null drops one); a filter change goes back to page 1
$searchUrl = static function (array $change) use ($basePath, $term, $sort, $ns, $filters, $page): string {
    $query = ['q' => $term, 'sort' => $sort !== 'relevance' ? $sort : null, 'ns' => $ns !== '' ? $ns : null] + $filters + ['page' => $page > 1 ? $page : null];
    if (array_diff_key($change, ['page' => true]) !== []) {
        $query['page'] = null;
    }
    $query = array_filter(array_replace($query, $change), static fn ($v): bool => $v !== null && $v !== '');

    return $basePath . '/search?' . http_build_query($query);
};
?>
<div class="wk-doc">
<div class="wk-doc-head">
<?php if ($term !== ''): ?>
<?= \Reporion\Http\Breadcrumb::render([['label' => t('search.title'), 'icon' => 'magnifying-glass', 'href' => $basePath . '/search'], ['label' => $term]]) ?>
<div class="wk-doc-titlerow"><h1 class="wk-doc-title"><?= htmlspecialchars(t('search.match_count', [$total]), ENT_QUOTES) ?></h1></div>
<div class="wk-pathb"><i class="ph ph-magnifying-glass"></i><span><?= htmlspecialchars($term, ENT_QUOTES) ?></span></div>
<?php else: ?>
<h1 class="wk-doc-title"><?= htmlspecialchars(t('search.title'), ENT_QUOTES) ?></h1>
<?php endif; ?>
</div>

<?php if ($term !== ''): ?>
<form class="wk-badges wk-mb-4" method="get" action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/search">
<input type="hidden" name="q" value="<?= htmlspecialchars($term, ENT_QUOTES) ?>">
<?php foreach ($filters as $facet => $value): ?><input type="hidden" name="<?= $e($facet) ?>" value="<?= $e($value) ?>"><?php endforeach; ?>
<label class="wk-dim wk-text-xs" for="search-sort"><?= htmlspecialchars(t('search.sort'), ENT_QUOTES) ?></label>
<select class="input" id="search-sort" name="sort" style="width:auto">
<option value="relevance"<?= $sort === 'relevance' ? ' selected' : '' ?>><?= htmlspecialchars(t('search.sort_relevance'), ENT_QUOTES) ?></option>
<option value="recent"<?= $sort === 'recent' ? ' selected' : '' ?>><?= htmlspecialchars(t('search.sort_recent'), ENT_QUOTES) ?></option>
</select>
<label class="wk-dim wk-text-xs" for="search-ns"><?= htmlspecialchars(t('search.ns'), ENT_QUOTES) ?></label>
<input class="input wk-mono" id="search-ns" type="text" name="ns" value="<?= htmlspecialchars($ns, ENT_QUOTES) ?>" placeholder="<?= htmlspecialchars(t('search.ns_placeholder'), ENT_QUOTES) ?>" style="width:auto">
<button class="btn btn-secondary" type="submit"><?= htmlspecialchars(t('search.apply'), ENT_QUOTES) ?></button>
<?php if ($ns !== ''): ?><a class="wk-dim" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/search?q=<?= rawurlencode($term) ?>&sort=<?= htmlspecialchars($sort, ENT_QUOTES) ?>"><?= htmlspecialchars(t('search.ns_clear'), ENT_QUOTES) ?></a><?php endif; ?>
</form>
<?php endif; ?>

<?php if ($term === ''): ?>
<p class="wk-dim"><?= $e(t('search.prompt')) ?></p>
<?php elseif ($total === 0 && $filters === []): ?>
<p class="wk-dim"><?= $e(t('search.noresults', [$term])) ?></p>
<?php else: ?>
<div class="wk-two wk-search-two">
<?php /* Phase 19a: facets over this caller's results; a value is a link that filters, the chosen one a link that removes it */ ?>
<aside class="wk-facets" aria-label="<?= $e(t('search.facets')) ?>">
<?php foreach ($facets as $facet => $values): ?>
<?php if ($values === [] && !isset($filters[$facet])) {
    continue;
} ?>
<div class="wk-facet"><span class="wk-eyebrow"><?= $e(t('search.facet.' . $facet)) ?></span>
<?php foreach ($values as $row): ?>
<?php $on = ($filters[$facet] ?? null) === $row['value']; ?>
<a class="wk-facet-v<?= $on ? ' wk-facet-on' : '' ?>" href="<?= $e($searchUrl([$facet => $on ? null : $row['value']])) ?>"<?= $on ? ' aria-current="true" title="' . $e(t('search.facet_off')) . '"' : '' ?>><span><?= $e($row['value']) ?></span><span class="wk-count"><?= (int) $row['n'] ?></span></a>
<?php endforeach; ?>
</div>
<?php endforeach; ?>
<?php if ($filters !== []): ?><a class="wk-dim wk-text-xs" href="<?= $e($searchUrl(array_fill_keys(array_keys($filters), null))) ?>"><?= $e(t('search.filters_clear')) ?></a><?php endif; ?>
</aside>
<div>
<?php if ($results === []): ?>
<p class="wk-dim"><?= $e(t('search.noresults', [$term])) ?></p>
<?php else: ?>
<div class="wk-res">
<?php foreach ($results as $result): ?>
<div class="wk-resrow">
<div class="wk-row-t"><a href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars((string) $result['path'], ENT_QUOTES) ?>"><?= htmlspecialchars((string) $result['title'], ENT_QUOTES) ?></a><?= \Reporion\Support\Visibility::badge((string) $result['visibility']) ?><span class="wk-score"><?= htmlspecialchars(number_format((float) ($result['score'] ?? 0), 2), ENT_QUOTES) ?></span></div>
<div class="wk-row-m wk-mono"><?= htmlspecialchars((string) $result['path'], ENT_QUOTES) ?> · <?= htmlspecialchars((string) ($result['modality'] ?? ''), ENT_QUOTES) ?> · <?= htmlspecialchars((string) ($result['device'] ?? ''), ENT_QUOTES) ?> · <?= htmlspecialchars(\Reporion\Support\MetaText::when($result['study_date'] ?? null), ENT_QUOTES) ?></div>
<div class="wk-row-s"><?= $result['snippet_html'] /* already escaped + <mark>-substituted by Sqlite::highlightSnippet(), see SearchController */ ?></div>
</div>
<?php endforeach; ?>
</div>
<?php /* Phase 19b: prev/next — a numbered pager only if this proves not enough */ ?>
<?php $first = ($page - 1) * $perPage + 1; $last = min($total, $page * $perPage); ?>
<nav class="wk-pager wk-mono wk-dim" aria-label="<?= $e(t('search.pager')) ?>">
<?php if ($page > 1): ?><a class="btn btn-secondary btn-sm" href="<?= $e($searchUrl(['page' => $page - 1 > 1 ? $page - 1 : null])) ?>" rel="prev"><i class="ph ph-caret-left"></i><?= $e(t('search.pager_prev')) ?></a><?php endif; ?>
<span><?= $e(t('search.pager_range', [$first, $last, $total])) ?></span>
<?php if ($last < $total): ?><a class="btn btn-secondary btn-sm" href="<?= $e($searchUrl(['page' => $page + 1])) ?>" rel="next"><?= $e(t('search.pager_next')) ?><i class="ph ph-caret-right"></i></a><?php endif; ?>
</nav>
<?php endif; ?>
</div>
</div>
<?php endif; ?>
</div>
