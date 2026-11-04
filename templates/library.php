<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /library (Controller\LibraryController::index(), docs/library.md phase 2): the articles the
 * caller may see, filtered by a tag and sorted by author, year or title (links: works without
 * JavaScript), a text filter over the rows, and for an editor the upload: files dropped or chosen,
 * each read on the server and shown as its proposed record to correct, then added
 * (assets/js/library.js, POST /api/v1/library/uploads, POST /api/v1/library).
 *
 * Variables in scope: list<array{pid, path, key, title, authors, year, journal, tags, ext, doi}> $entries;
 * array<string, int> $tagCounts; string $tag, $sort, $namespace, $basePath; bool $canAdd; int $maxMb;
 * list<string> $extensions; bool $webClip (the Add panel takes an address, phase 7)
 */

declare(strict_types=1);

/** @var list<array{pid: string, path: string, key: string, title: string, authors: string, year: ?int, journal: string, tags: list<string>, cited: int, ext: string, doi: string}> $entries */
/** @var array<string, int> $tagCounts */
/** @var string $tag */
/** @var string $sort */
/** @var string $namespace */
/** @var string $basePath */
/** @var bool $canAdd */
/** @var int $maxMb */
/** @var list<string> $extensions */
$webClip = ($webClip ?? false) === true;
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$filter = $filter ?? [];
$collections = $collections ?? [];
$link = static function (array $query) use ($basePath, $tag, $sort, $filter): string {
    $query += ['tag' => $tag, 'sort' => $sort] + $filter;
    $query = array_filter($query, static fn (string $v, string $k): bool => $v !== '' && !($k === 'sort' && $v === \Reporion\Controller\LibraryController::SORTS[0]), ARRAY_FILTER_USE_BOTH);

    return $basePath . '/library' . ($query !== [] ? '?' . http_build_query($query) : '');
};
$total = \count($entries);
?>
<div class="wk-doc wk-lib">
<header class="wk-doc-head">
<?= \Reporion\Http\Breadcrumb::render([['label' => t('library.title'), 'icon' => 'books']]) ?>
<div class="wk-doc-titlerow"><hgroup><h1 class="wk-doc-title"><?= $e(t('library.title')) ?></h1><p class="wk-dim"><?= $e(t('library.lede')) ?></p></hgroup>
<?php if ($canAdd): ?>
<div class="wk-actions"><button class="btn btn-primary" type="button" data-lib-open><i class="ph ph-plus" aria-hidden="true"></i><span class="wk-btn-label"><?= $e(t('library.add')) ?></span></button></div>
<?php endif; ?>
</div>
</header>

<?php if ($canAdd): ?>
<section class="wk-panel wk-lib-add" data-island="library-add" hidden>
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><i class="ph ph-upload-simple" aria-hidden="true"></i> <?= $e(t('library.add')) ?></h2><p class="wk-dim"><?= $e(t('library.add_lede')) ?></p></hgroup></header>
<label class="wk-lib-drop" data-lib-drop>
<i class="ph ph-file-arrow-up" aria-hidden="true"></i>
<span><?= $e(t('library.drop')) ?></span>
<small><?= $e(t('library.drop_help', [(string) $maxMb])) ?></small>
<input type="file" multiple accept="<?= $e(implode(',', array_map(static fn (string $x): string => '.' . $x, [...$extensions, ...\Reporion\Controller\LibraryController::IMPORT_EXTENSIONS]))) ?>" data-lib-files>
</label>
<?php if ($webClip): ?>
<form class="wk-lib-clip" data-lib-clip>
<div class="field"><label><?= $e(t('library.clip')) ?>
<span class="group"><input class="input" type="url" name="url" maxlength="2000" required placeholder="<?= $e(t('library.clip_hint')) ?>" autocapitalize="off" spellcheck="false"><button class="btn btn-secondary" type="submit"><i class="ph ph-globe" aria-hidden="true"></i><?= $e(t('library.clip_go')) ?></button></span>
<small><?= $e(t('library.clip_help')) ?></small></label></div>
<p class="wk-dim wk-text-sm wk-lib-bookmarklet"><i class="ph ph-bookmark-simple" aria-hidden="true"></i> <a class="wk-chip" href="#" data-lib-bookmarklet draggable="true"><?= $e(t('library.bookmarklet')) ?></a> <?= $e(t('library.bookmarklet_help')) ?></p>
</form>
<?php endif; ?>
<div class="wk-lib-queue" data-lib-queue></div>
<template data-lib-form-template><?php $libraryFormMode = 'add'; include __DIR__ . '/partials/library-record-form.php'; ?></template>
<script type="application/json"><?= json_encode([
    'basePath' => $basePath,
    'maxBytes' => $maxMb * 1024 * 1024,
    'extensions' => $extensions,
    'importExtensions' => \Reporion\Controller\LibraryController::IMPORT_EXTENSIONS,
    'strings' => [
        'reading' => t('library.reading'), 'adding' => t('library.adding'), 'added' => t('library.added'), 'open' => t('library.open'),
        'failed' => t('library.failed'), 'too_large' => t('library.err_too_large', [(string) $maxMb]), 'unsupported' => t('library.err_unsupported'),
        'lookup_done' => t('library.lookup_done'), 'lookup_none' => t('library.lookup_none'), 'lookup_failed' => t('library.lookup_failed'), 'importing' => t('library.importing'), 'imported' => t('library.imported'), 'attach' => t('library.attach'),
        'duplicate' => t('library.duplicate'), 'duplicate_title' => t('library.duplicate_title'), 'discarded' => t('library.discarded'),
        'pages' => t('library.pages'), 'title_needed' => t('library.title_needed'), 'clipping' => t('library.clipping'),
    ],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
</section>
<?php endif; ?>

<?php $query = (string) ($query ?? ''); $searched = ($searched ?? false) === true; ?>
<div class="wk-tools">
<?php if (($onlineSearch ?? false) === true): /* Add by search (D60): PubMed asked by the words typed */ ?>
<details class="wk-panel wk-tool wk-lib-online" data-island="library-tools" data-tool="online">
<summary class="wk-eyebrow"><i class="ph ph-magnifying-glass-plus" aria-hidden="true"></i> <?= $e(t('library.online.title')) ?></summary>
<p class="wk-dim wk-text-sm"><?= $e(t('library.online.lede')) ?></p>
<form class="wk-lib-online-form" data-online-form>
<div class="field"><label><?= $e(t('library.online.q')) ?>
<span class="group"><input class="input" type="search" name="q" maxlength="200" required placeholder="<?= $e(t('library.online.q_hint')) ?>" autocomplete="off" spellcheck="false"><button class="btn btn-secondary" type="submit"><i class="ph ph-magnifying-glass" aria-hidden="true"></i><?= $e(t('library.online.go')) ?></button></span>
<small><?= $e(t('library.online.q_help')) ?></small></label></div>
</form>
<div data-online-status aria-live="polite"></div>
<ul class="wk-lib-online-list" data-online-list></ul>
<script type="application/json"><?= json_encode([
    'basePath' => $basePath,
    'strings' => [
        'searching' => t('library.online.searching'), 'none' => t('library.online.none'), 'failed' => t('library.failed'),
        'add' => t('library.online.add'), 'adding' => t('library.online.adding'), 'added' => t('library.online.added'), 'skipped' => t('library.online.skipped'),
        'have' => t('library.online.have'), 'by_title' => t('library.online.by_title'),
    ],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
</details>
<?php endif; ?>
<?php if (($libraryAsk ?? false) === true): /* Ask the library (2026-11-02): every source, never reports; assets/js/notebook-ask.js */ ?>
<details class="wk-panel wk-tool wk-notebook-ask" data-island="notebook-ask">
<summary class="wk-eyebrow"><i class="ph ph-chats-circle" aria-hidden="true"></i> <?= $e(t('library.ask.title')) ?></summary>
<p class="wk-dim wk-text-sm"><?= $e(t('library.ask.lede')) ?></p>
<form class="wk-notebook-form" data-notebook-form>
<div class="field"><label><?= $e(t('library.ask.question')) ?>
<textarea class="input" name="question" rows="2" maxlength="1000" required placeholder="<?= $e(t('library.ask.hint')) ?>"></textarea>
</label></div>
<footer class="wk-actions"><button class="btn btn-primary" type="submit" data-notebook-ask><i class="ph ph-sparkle" aria-hidden="true"></i><?= $e(t('notebook.ask')) ?></button><button class="btn btn-ghost" type="button" data-notebook-clear hidden><i class="ph ph-arrow-counter-clockwise" aria-hidden="true"></i><?= $e(t('library.ask.clear')) ?></button></footer>
</form>
<div class="wk-notebook-out" data-notebook-out aria-live="polite" hidden></div>
<script type="application/json"><?= json_encode([
    'basePath' => $basePath,
    'endpoint' => '/api/v1/library/ask',
    'history' => true,
    'citationStyle' => \Reporion\Support\Citation::style(null),
    'strings' => [
        'asking' => t('notebook.asking'), 'failed' => t('notebook.failed'), 'sources' => t('notebook.sources'),
        'whole' => t('notebook.whole'), 'passages' => t('library.ask.passages'), 'understood' => t('notebook.understood'),
        'dropped' => t('notebook.dropped'), 'note' => t('notebook.note'),
        'step_terms' => t('notebook.step_terms'), 'step_searching' => t('notebook.step_searching'), 'step_answering' => t('notebook.step_answering'),
    ],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
</details>
<?php endif; ?>
<?php if (($duplicates ?? []) !== []): /* Sources that look like one article (D60): keep one, fold the others into it */ ?>
<details class="wk-panel wk-tool wk-lib-dups" data-island="library-tools" data-tool="duplicates">
<summary class="wk-eyebrow"><i class="ph ph-copy" aria-hidden="true"></i> <?= $e(t('library.dups.title')) ?><span class="wk-count"><?= \count($duplicates) ?></span></summary>
<p class="wk-dim wk-text-sm"><?= $e(t('library.dups.lede')) ?></p>
<?php foreach ($duplicates as $n => $group): ?>
<fieldset class="wk-lib-dup" data-dup-group>
<legend class="wk-dim wk-text-sm"><?= $e(t('library.dups.same_' . ($group['by'][0] ?? 'title'))) ?></legend>
<?php foreach ($group['sources'] as $i => $source): ?>
<label class="radio wk-lib-dup-row"><input type="radio" name="keep-<?= $n ?>" value="<?= $e($source['pid']) ?>"<?= $i === 0 ? ' checked' : '' ?>><span class="dot"></span>
<span><a href="<?= $e($basePath . '/' . $source['path']) ?>"><?= $e($source['title']) ?></a> <span class="wk-dim wk-text-sm"><?= $e(trim($source['authors'] . ' ' . ($source['year'] ?? ''))) ?> · <code>[@<?= $e($source['key']) ?>]</code><?= $source['cited'] > 0 ? ' · ' . $e(t('library.dups.cited', [(string) $source['cited']])) : '' ?><?= $source['has_original'] ? ' · ' . $e(t('library.dups.file')) : '' ?></span></span></label>
<?php endforeach; ?>
<footer class="wk-actions"><button class="btn btn-secondary btn-sm" type="button" data-dup-merge><i class="ph ph-git-merge" aria-hidden="true"></i><?= $e(t('library.dups.merge')) ?></button></footer>
</fieldset>
<?php endforeach; ?>
<div data-dup-status aria-live="polite"></div>
<script type="application/json"><?= json_encode([
    'basePath' => $basePath,
    'strings' => ['confirm' => t('library.dups.confirm'), 'merging' => t('library.dups.merging'), 'merged' => t('library.dups.merged'), 'failed' => t('library.failed')],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
</details>
<?php endif; ?>
</div>
<?php if (($libraryAsk ?? false) === true): ?>
<script src="<?= $e(\Reporion\Support\Asset::url($basePath, 'js/render-client.js')) ?>" defer></script>
<script src="<?= $e(\Reporion\Support\Asset::url($basePath, 'js/notebook-ask.js')) ?>" defer></script>
<?php endif; ?>
<?php if ($total === 0 && $tag === '' && !$searched): ?>
<?php $noticeKind = 'info'; $noticeText = t($canAdd ? 'library.empty_add' : 'library.empty'); include __DIR__ . '/partials/notice.php'; ?>
<?php else: ?>
<section class="wk-panel wk-lib-list" data-island="library-list">
<header class="wk-panel-h">
<hgroup><h2 class="wk-eyebrow"><i class="ph ph-books" aria-hidden="true"></i> <?= $e(t('library.articles')) ?><span class="wk-count" data-lib-count><?= $total ?></span></h2></hgroup>
<nav class="wk-lib-sort" aria-label="<?= $e(t('library.export')) ?>"><span class="wk-dim wk-text-xs"><?= $e(t('library.export')) ?></span>
<?php foreach (['bibtex', 'ris', 'csl', 'text'] as $exportFormat): ?>
<a class="wk-chip" href="<?= $e($basePath . '/library/export?' . http_build_query(['format' => $exportFormat] + ($tag !== '' ? ['tag' => $tag] : []))) ?>" title="<?= $e(t('library.export_title', [t('library.export_' . $exportFormat)])) ?>"><?= $e(t('library.export_' . $exportFormat)) ?></a>
<?php endforeach; ?>
</nav>
<nav class="wk-lib-sort" aria-label="<?= $e(t('library.sort')) ?>"><span class="wk-dim wk-text-xs"><?= $e(t('library.sort')) ?></span>
<?php foreach (\Reporion\Controller\LibraryController::SORTS as $order): ?>
<a class="wk-chip<?= $order === $sort ? ' wk-chip-on' : '' ?>" href="<?= $e($link(['sort' => $order])) ?>"<?= $order === $sort ? ' aria-current="true"' : '' ?>><?= $e(t('library.sort_' . $order)) ?></a>
<?php endforeach; ?>
</nav>
</header>
<div class="wk-lib-bar">
<form class="wk-lib-search" method="get" action="<?= $e($basePath . '/library') ?>" role="search">
<label class="group"><span class="group-addon"><i class="ph ph-text-aa" aria-hidden="true"></i></span><input class="input" type="search" name="q" value="<?= $e($query) ?>" maxlength="200" placeholder="<?= $e(t('library.search_hint')) ?>" aria-label="<?= $e(t('library.search')) ?>"></label>
<?php if ($tag !== ''): ?><input type="hidden" name="tag" value="<?= $e($tag) ?>"><?php endif; ?>
<button class="btn btn-secondary" type="submit"><?= $e(t('library.search')) ?></button>
<?php if ($searched): ?><a class="btn btn-ghost" href="<?= $e($link([])) ?>"><i class="ph ph-x" aria-hidden="true"></i><?= $e(t('library.search_clear')) ?></a><?php endif; ?>
</form>
<?php if ($collections !== []): /* Collections: saved views of the library (D60), opened here */ ?>
<nav class="wk-lib-collections" aria-label="<?= $e(t('library.coll.title')) ?>"><span class="wk-dim wk-text-xs"><?= $e(t('library.coll.title')) ?></span>
<?php foreach ($collections as $collection): ?>
<span class="wk-chip wk-chip-row"><a href="<?= $e($basePath . '/library?' . $collection['query']) ?>"><i class="ph ph-books" aria-hidden="true"></i> <?= $e($collection['name']) ?></a>
<form method="post" action="<?= $e($basePath) ?>/profile/views"><input type="hidden" name="name" value="<?= $e($collection['name']) ?>"><input type="hidden" name="remove" value="1"><input type="hidden" name="return_to" value="/library"><button type="submit" class="wk-tbtn wk-quick-x" title="<?= $e(t('library.coll.remove', [$collection['name']])) ?>"><i class="ph ph-x" aria-hidden="true"></i></button></form></span>
<?php endforeach; ?>
</nav>
<?php endif; ?>
<details class="wk-lib-filters"<?= $filter !== [] ? ' open' : '' ?>>
<summary class="wk-dim wk-text-sm"><i class="ph ph-funnel" aria-hidden="true"></i> <?= $e(t('library.filters.title')) ?><?= $filter !== [] ? ' (' . \count($filter) . ')' : '' ?></summary>
<form class="wk-lib-filter-form" method="get" action="<?= $e($basePath . '/library') ?>">
<?php foreach (['q' => $query, 'tag' => $tag, 'sort' => $sort === \Reporion\Controller\LibraryController::SORTS[0] ? '' : $sort] as $keep => $value): ?><?php if ($value !== ''): ?><input type="hidden" name="<?= $keep ?>" value="<?= $e($value) ?>"><?php endif; ?><?php endforeach; ?>
<div class="field"><label><?= $e(t('library.filters.year')) ?><input class="input wk-mono" type="text" name="year" value="<?= $e($filter['year'] ?? '') ?>" maxlength="9" placeholder="<?= $e(t('library.filters.year_hint')) ?>" inputmode="numeric"><small><?= $e(t('library.filters.year_help')) ?></small></label></div>
<div class="field"><label><?= $e(t('library.filters.journal')) ?><input class="input" type="text" name="journal" value="<?= $e($filter['journal'] ?? '') ?>" maxlength="100" placeholder="<?= $e(t('library.filters.journal_hint')) ?>"></label></div>
<div class="field"><label><?= $e(t('library.filters.file')) ?><select class="input" name="file"><?php foreach (['' => t('library.filters.any'), 'yes' => t('library.filters.file_yes'), 'no' => t('library.filters.file_no')] as $value => $label): ?><option value="<?= $e((string) $value) ?>"<?= ($filter['file'] ?? '') === (string) $value ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select></label></div>
<div class="field"><label><?= $e(t('library.filters.notice')) ?><select class="input" name="notice"><?php foreach (['' => t('library.filters.any'), 'any' => t('library.filters.notice_any'), 'retracted' => t('library.notice.retracted'), 'concern' => t('library.notice.concern'), 'corrected' => t('library.notice.corrected')] as $value => $label): ?><option value="<?= $e((string) $value) ?>"<?= ($filter['notice'] ?? '') === (string) $value ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select></label></div>
<label class="radio"><input type="checkbox" name="uncited" value="1"<?= isset($filter['uncited']) ? ' checked' : '' ?>><span class="dot"></span><?= $e(t('library.filters.uncited')) ?></label>
<footer class="wk-actions"><button class="btn btn-secondary btn-sm" type="submit"><?= $e(t('library.filters.apply')) ?></button><?php if ($filter !== []): ?><a class="btn btn-ghost btn-sm" href="<?= $e($link(array_fill_keys(\Reporion\Support\LibraryFilter::KEYS, ''))) ?>"><i class="ph ph-x" aria-hidden="true"></i><?= $e(t('library.filters.clear')) ?></a><?php endif; ?></footer>
</form>
<?php
$collectionQuery = array_filter(['q' => $query, 'tag' => $tag, 'sort' => $sort === \Reporion\Controller\LibraryController::SORTS[0] ? '' : $sort] + $filter, static fn (string $v): bool => $v !== '');
?>
<?php if ($collectionQuery !== []): ?>
<form class="wk-lib-save" method="post" action="<?= $e($basePath) ?>/profile/views">
<input type="hidden" name="kind" value="library"><input type="hidden" name="query" value="<?= $e(http_build_query($collectionQuery)) ?>"><input type="hidden" name="return_to" value="/library?<?= $e(http_build_query($collectionQuery)) ?>">
<div class="field"><label><?= $e(t('library.coll.name')) ?><span class="group"><input class="input" type="text" name="name" maxlength="60" required placeholder="<?= $e(t('library.coll.name_hint')) ?>"><button class="btn btn-secondary" type="submit"><i class="ph ph-bookmark-simple" aria-hidden="true"></i><?= $e(t('library.coll.save')) ?></button></span><small><?= $e(t('library.coll.help')) ?></small></label></div>
</form>
<?php endif; ?>
</details>
<?php if ($searched): ?><p class="wk-dim wk-text-sm" role="status"><?= $e(t($total === 0 ? 'library.search_none' : 'library.search_found', [(string) $total, $query])) ?></p><?php endif; ?>
<div class="wk-lib-tools">
<label class="group wk-lib-filter"><span class="group-addon"><i class="ph ph-magnifying-glass" aria-hidden="true"></i></span><input class="input" type="search" placeholder="<?= $e(t('library.filter_hint')) ?>" aria-label="<?= $e(t('library.filter')) ?>" data-lib-filter></label>
<?php if ($tagCounts !== []): ?>
<div class="wk-lib-tags">
<?php if ($tag !== ''): ?><a class="wk-chip" href="<?= $e($link(['tag' => ''])) ?>"><i class="ph ph-x" aria-hidden="true"></i><?= $e(t('library.all_tags')) ?></a><?php endif; ?>
<?php foreach ($tagCounts as $name => $count): ?>
<a class="wk-chip<?= $name === $tag ? ' wk-chip-on' : '' ?>" href="<?= $e($link(['tag' => $name === $tag ? '' : (string) $name])) ?>"<?= $name === $tag ? ' aria-current="true"' : '' ?>><?= $e((string) $name) ?> <span class="wk-dim"><?= $count ?></span></a>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
</div>
<table class="table table-cards table-cards-compact wk-lib-table">
<thead><tr><th><?= $e(t('library.col_article')) ?></th><th><?= $e(t('library.col_year')) ?></th><th><?= $e(t('library.col_journal')) ?></th><th><?= $e(t('library.col_cited')) ?></th><th><?= $e(t('library.col_tags')) ?></th></tr></thead>
<tbody>
<?php foreach ($entries as $entry): ?>
<tr data-lib-row>
<td class="wk-card-head"><a href="<?= $e($basePath . '/' . $entry['path']) ?>"><?= $e($entry['title']) ?></a><?php if (($entry['notice'] ?? null) !== null): ?> <span class="wk-src-flag wk-src-flag-<?= $e($entry['notice']) ?>" title="<?= $e(t('library.notice.banner_' . $entry['notice'])) ?>"><?= $e(t('library.notice.' . $entry['notice'])) ?></span><?php endif; ?><?php if ($entry['authors'] !== ''): ?><span class="wk-lib-authors wk-dim wk-text-sm"><?= $e($entry['authors']) ?></span><?php endif; ?><?php if (($entry['snippet'] ?? '') !== ''): /* highlightSnippet(): escaped, then <mark> */ ?><span class="wk-lib-snippet wk-text-sm"><?= $entry['snippet'] ?><?= isset($entry['page']) && $entry['page'] !== null ? ' <span class="wk-dim">p. ' . (int) $entry['page'] . '</span>' : '' ?></span><?php endif; ?></td>
<td class="wk-mono"><?= $entry['year'] !== null ? (int) $entry['year'] : '' ?></td>
<td class="wk-dim"><?= $e($entry['journal']) ?></td>
<td class="wk-mono"><?= $entry['cited'] > 0 ? '<a href="' . $e($basePath . '/' . $entry['path']) . '#backlinks" title="' . $e(t('library.cited_title')) . '">' . (int) $entry['cited'] . '</a>' : '' ?></td>
<td><?php foreach ($entry['tags'] as $entryTag): ?><a class="wk-chip" href="<?= $e($link(['tag' => $entryTag])) ?>"><?= $e($entryTag) ?></a> <?php endforeach; ?><?php if ($entry['ext'] !== ''): ?><a class="wk-chip" href="<?= $e($basePath . '/library/file/' . $entry['pid']) ?>" title="<?= $e(t('library.original')) ?>"><i class="ph ph-file" aria-hidden="true"></i><?= $e($entry['ext']) ?></a><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<p class="wk-dim" data-lib-none hidden><?= $e(t('library.none_match')) ?></p>
</section>
<?php endif; ?>
</div>
<script src="<?= $e(\Reporion\Support\Asset::url($basePath, 'js/library.js')) ?>" defer></script>
<script src="<?= $e(\Reporion\Support\Asset::url($basePath, 'js/library-tools.js')) ?>" defer></script>
