<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /library/add (Controller\LibraryController::add(), 2026-11-04): adding articles to the library,
 * for an editor of it, one panel per way: files dropped or chosen (each read on the server and shown as
 * its proposed record to correct, then added) and an address clipped when the web clip is on
 * (assets/js/library.js, POST /api/v1/library/uploads, POST /api/v1/library, ?clip= from the
 * bookmarklet); PubMed searched when `library.search` is on (assets/js/library-tools.js).
 *
 * Variables in scope: string $basePath; int $maxMb; list<string> $extensions; bool $webClip, $onlineSearch, $fullText (an added record's free PDF is fetched)
 */

declare(strict_types=1);

/** @var string $basePath */
/** @var int $maxMb */
/** @var list<string> $extensions */
$webClip = ($webClip ?? false) === true;
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
?>
<div class="wk-doc wk-lib wk-lib-addpage">
<header class="wk-doc-head">
<?= \Reporion\Http\Breadcrumb::render([['label' => t('library.title'), 'href' => $basePath . '/library', 'icon' => 'books'], ['label' => t('library.add')]]) ?>
<div class="wk-doc-titlerow"><hgroup><h1 class="wk-doc-title"><?= $e(t('library.add')) ?></h1><p class="wk-dim"><?= $e(t('library.add_page_lede')) ?></p></hgroup>
<div class="wk-actions"><a class="btn btn-secondary" href="<?= $e($basePath . '/library') ?>"><i class="ph ph-books" aria-hidden="true"></i><span class="wk-btn-label"><?= $e(t('library.back')) ?></span></a></div>
</div>
</header>

<section class="wk-panel wk-lib-add" data-island="library-add">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><i class="ph ph-upload-simple" aria-hidden="true"></i> <?= $e(t('library.add_files')) ?></h2><p class="wk-dim"><?= $e(t('library.add_lede')) ?></p></hgroup></header>
<div class="wk-lib-add-top">
<label class="wk-lib-drop" data-lib-drop>
<i class="ph ph-file-arrow-up" aria-hidden="true"></i>
<span><?= $e(t('library.drop')) ?></span>
<small><?= $e(t('library.drop_help', [(string) $maxMb])) ?></small>
<input type="file" multiple accept="<?= $e(implode(',', array_map(static fn (string $x): string => '.' . $x, [...$extensions, ...\Reporion\Controller\LibraryController::IMPORT_EXTENSIONS]))) ?>" data-lib-files>
</label>
<?php if ($webClip): ?>
<form class="wk-lib-clip" data-lib-clip>
<div class="field"><label><?= $e(t('library.clip')) ?>
<span class="group group-fill"><span class="group-addon"><i class="ph ph-globe" aria-hidden="true"></i></span><input class="input" type="url" name="url" maxlength="2000" required placeholder="<?= $e(t('library.clip_hint')) ?>" autocapitalize="off" spellcheck="false"><button class="btn btn-primary" type="submit"><?= $e(t('library.clip_go')) ?></button></span>
<small><?= $e(t('library.clip_help')) ?></small></label></div>
<div class="wk-lib-bookmarklet">
<h3 class="wk-eyebrow"><i class="ph ph-bookmark-simple" aria-hidden="true"></i> <?= $e(t('library.bookmarklet_title')) ?></h3>
<a class="wk-chip" href="#" data-lib-bookmarklet draggable="true"><?= $e(t('library.bookmarklet')) ?></a>
<small><?= $e(t('library.bookmarklet_help')) ?></small>
</div>
</form>
<?php endif; ?>
</div>
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

<?php if (($onlineSearch ?? false) === true): /* Add by search (D60): PubMed asked by the words typed */ ?>
<section class="wk-panel wk-lib-online" data-island="library-tools" data-tool="online">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><i class="ph ph-magnifying-glass-plus" aria-hidden="true"></i> <?= $e(t('library.online.title')) ?></h2><p class="wk-dim"><?= $e(t('library.online.lede')) ?></p></hgroup></header>
<form class="wk-lib-search wk-lib-online-form" data-online-form role="search">
<span class="group group-fill"><span class="group-addon"><i class="ph ph-magnifying-glass" aria-hidden="true"></i></span><input class="input" type="search" name="q" maxlength="200" required placeholder="<?= $e(t('library.online.q_hint')) ?>" autocomplete="off" spellcheck="false" aria-label="<?= $e(t('library.online.q')) ?>" aria-describedby="wk-lib-online-help"><button class="btn btn-primary" type="submit"><?= $e(t('library.online.go')) ?></button></span>
<small class="wk-lib-search-help" id="wk-lib-online-help"><?= $e(t('library.online.q_help')) ?><?= ($fullText ?? false) === true ? ' ' . $e(t('library.online.fulltext_help')) : '' ?></small>
</form>
<div data-online-status aria-live="polite"></div>
<ul class="wk-lib-online-list" data-online-list></ul>
<script type="application/json"><?= json_encode([
    'basePath' => $basePath,
    'fullText' => ($fullText ?? false) === true,
    'strings' => [
        'ft_working' => t('library.fulltext_working'), 'ft_attached' => t('library.online.ft_attached'), 'ft_none' => t('library.fulltext_none'), 'ft_not_pdf' => t('library.fulltext_not_pdf'),
        'ft_mismatch' => t('library.fulltext_mismatch'), 'ft_duplicate' => t('library.fulltext_duplicate'), 'ft_refused' => t('library.fulltext_refused'), 'ft_failed' => t('library.online.ft_failed'),
        'searching' => t('library.online.searching'), 'none' => t('library.online.none'), 'failed' => t('library.failed'),
        'add' => t('library.online.add'), 'adding' => t('library.online.adding'), 'added' => t('library.online.added'), 'skipped' => t('library.online.skipped'),
        'have' => t('library.online.have'), 'by_title' => t('library.online.by_title'),
    ],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
</section>
<?php endif; ?>
</div>
<script src="<?= $e(\Reporion\Support\Asset::url($basePath, 'js/library.js')) ?>" defer></script>
<?php if (($onlineSearch ?? false) === true): ?><script src="<?= $e(\Reporion\Support\Asset::url($basePath, 'js/library-tools.js')) ?>" defer></script><?php endif; ?>
