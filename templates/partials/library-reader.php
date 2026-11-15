<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The reader's bar over a library source's text (docs/library.md, *The reader*): find in this
 * article, the page in view and a jump to another, the original opened at that page, the
 * sections folded to an outline or opened. It stays at the top while the text scrolls.
 * assets/js/library-reader.js does the work in the browser, on the text as the server rendered
 * it; without JavaScript the bar is hidden and the text reads as before.
 *
 * Variables in scope: array{pid: string, key: string, original: ?array{ext: string, pages: int, bytes: int}} $librarySource;
 * string $basePath; bool $comments
 */

declare(strict_types=1);

/** @var array{pid: string, key: string, original: ?array{ext: string, pages: int, bytes: int}} $librarySource */
/** @var string $basePath */
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$readerPdf = ($librarySource['original']['ext'] ?? '') === 'pdf' ? $basePath . '/library/file/' . $librarySource['pid'] : null;
?>
<div class="wk-reader" data-island="library-reader" hidden>
<span class="group wk-reader-find" role="search"><span class="group-addon"><i class="ph ph-magnifying-glass" aria-hidden="true"></i></span><input class="input" type="search" maxlength="100" placeholder="<?= $e(t('library.reader.find_hint')) ?>" aria-label="<?= $e(t('library.reader.find')) ?>" data-reader-find></span>
<span class="wk-reader-hits wk-mono wk-text-xs" data-reader-hits aria-live="polite"></span>
<button class="wk-tbtn" type="button" data-reader-prev title="<?= $e(t('library.reader.prev')) ?>" aria-label="<?= $e(t('library.reader.prev')) ?>" disabled><i class="ph ph-caret-up" aria-hidden="true"></i></button>
<button class="wk-tbtn" type="button" data-reader-next title="<?= $e(t('library.reader.next')) ?>" aria-label="<?= $e(t('library.reader.next')) ?>" disabled><i class="ph ph-caret-down" aria-hidden="true"></i></button>
<span class="wk-reader-gap"></span>
<label class="wk-reader-page wk-text-xs" data-reader-pagebox hidden><span class="wk-dim"><?= $e(t('library.reader.page')) ?></span> <select class="input" data-reader-page aria-label="<?= $e(t('library.reader.page_jump')) ?>"></select></label>
<?php if ($readerPdf !== null): ?>
<a class="btn btn-ghost btn-sm" href="<?= $e($readerPdf) ?>" target="_blank" rel="noopener" data-reader-pdf title="<?= $e(t('library.reader.pdf_title')) ?>"><i class="ph ph-file-pdf" aria-hidden="true"></i><span class="wk-btn-label"><?= $e(t('library.reader.pdf')) ?></span></a>
<?php endif; ?>
<button class="btn btn-ghost btn-sm" type="button" data-reader-fold hidden><i class="ph ph-list-dashes" aria-hidden="true"></i><span class="wk-btn-label" data-reader-fold-label></span></button>
<span class="wk-reader-stat wk-dim wk-text-xs" data-reader-stat></span>
<script type="application/json"><?= json_encode([
    'key' => $librarySource['key'],
    'pdf' => $readerPdf,
    'strings' => [
        'hits' => t('library.reader.hits'), 'no_hits' => t('library.reader.no_hits'), 'fold' => t('library.reader.fold'), 'unfold' => t('library.reader.unfold'),
        'words' => t('library.reader.words'), 'minutes' => t('library.reader.minutes'), 'page' => t('library.reader.page_n'), 'open_page' => t('library.reader.open_page'),
        'toggle' => t('library.reader.toggle'), 'copy_cite' => t('library.reader.copy_cite'), 'copied' => t('library.copied'), 'passage' => t('library.reader.passage'), 'comment' => t('library.reader.comment'),
        'section_hits' => t('library.reader.section_hits'),
    ],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
</div>
<script src="<?= $e(\Reporion\Support\Asset::url($basePath, 'js/library-reader.js')) ?>" defer></script>
