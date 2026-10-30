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
$link = static function (array $query) use ($basePath, $tag, $sort): string {
    $query += ['tag' => $tag, 'sort' => $sort];
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

<?php if ($total === 0 && $tag === ''): ?>
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
<table class="table table-cards table-cards-compact wk-lib-table">
<thead><tr><th><?= $e(t('library.col_article')) ?></th><th><?= $e(t('library.col_year')) ?></th><th><?= $e(t('library.col_journal')) ?></th><th><?= $e(t('library.col_cited')) ?></th><th><?= $e(t('library.col_tags')) ?></th></tr></thead>
<tbody>
<?php foreach ($entries as $entry): ?>
<tr data-lib-row>
<td class="wk-card-head"><a href="<?= $e($basePath . '/' . $entry['path']) ?>"><?= $e($entry['title']) ?></a><?php if ($entry['authors'] !== ''): ?><span class="wk-lib-authors wk-dim wk-text-sm"><?= $e($entry['authors']) ?></span><?php endif; ?></td>
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
