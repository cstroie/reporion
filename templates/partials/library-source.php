<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * A library source's card on its page view (docs/library.md, phase 2): the reference in
 * Vancouver, the original to open, the reference to copy, and for a writer the record to correct
 * (assets/js/library.js, PUT /api/v1/library/{pid}/record).
 *
 * Variables in scope: array{pid: string, key: string, rev: int, citation: string, csl: array<string, mixed>,
 * tags: list<string>, aiTasks: list<string>, path: string, original: ?array{ext: string, pages: int, bytes: int}, canEdit: bool} $librarySource;
 * string $basePath
 */

declare(strict_types=1);

/** @var array{pid: string, key: string, rev: int, citation: string, csl: array<string, mixed>, tags: list<string>, original: ?array{ext: string, pages: int, bytes: int}, canEdit: bool} $librarySource */
/** @var string $basePath */
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$source = $librarySource;
$doi = \is_string($source['csl']['DOI'] ?? null) ? $source['csl']['DOI'] : '';
$pmid = \is_scalar($source['csl']['PMID'] ?? null) ? (string) $source['csl']['PMID'] : '';
?>
<section class="wk-panel wk-lib-source" data-island="library-record">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><i class="ph ph-book-open-text" aria-hidden="true"></i> <?= $e(t('library.source')) ?></h2></hgroup>
<div class="wk-actions">
<?php if ($source['original'] !== null): ?>
<a class="btn btn-secondary btn-sm" href="<?= $e($basePath . '/library/file/' . $source['pid']) ?>"<?= $source['original']['ext'] === 'pdf' ? ' target="_blank" rel="noopener"' : '' ?>><i class="ph ph-file" aria-hidden="true"></i><span class="wk-btn-label"><?= $e(t('library.open_original', [strtoupper($source['original']['ext'])])) ?></span></a>
<?php endif; ?>
<button class="btn btn-ghost btn-sm" type="button" data-lib-copy title="<?= $e(t('library.copy_ref')) ?>"><i class="ph ph-copy" aria-hidden="true"></i><span class="wk-btn-label"><?= $e(t('library.copy_ref')) ?></span></button>
<a class="btn btn-ghost btn-sm" href="<?= $e($basePath . '/library/export?format=bibtex&key=' . rawurlencode($source['key'])) ?>" title="<?= $e(t('library.export_title', [t('library.export_bibtex')])) ?>"><i class="ph ph-download-simple" aria-hidden="true"></i><span class="wk-btn-label"><?= $e(t('library.export_bibtex')) ?></span></a>
<a class="btn btn-ghost btn-sm" href="<?= $e($basePath . '/library/export?format=ris&key=' . rawurlencode($source['key'])) ?>" title="<?= $e(t('library.export_title', [t('library.export_ris')])) ?>"><span class="wk-btn-label"><?= $e(t('library.export_ris')) ?></span></a>
<?php if ($source['canEdit']): ?>
<button class="btn btn-ghost btn-sm" type="button" data-lib-edit><i class="ph ph-pencil-simple" aria-hidden="true"></i><span class="wk-btn-label"><?= $e(t('library.edit_record')) ?></span></button>
<?php endif; ?>
</div></header>
<p class="wk-lib-ref" data-lib-ref><?= $e($source['citation']) ?></p>
<?php if ($doi !== '' || $pmid !== '' || $source['original'] !== null): ?>
<p class="wk-dim wk-text-xs wk-mono wk-lib-ids">
<?php if ($doi !== ''): ?><a href="https://doi.org/<?= $e($doi) ?>" rel="noopener noreferrer" target="_blank">doi:<?= $e($doi) ?></a><?php endif; ?>
<?php if ($pmid !== ''): ?> <a href="https://pubmed.ncbi.nlm.nih.gov/<?= $e($pmid) ?>/" rel="noopener noreferrer" target="_blank">PMID <?= $e($pmid) ?></a><?php endif; ?>
<?php if ($source['original'] !== null): ?> <?= $e($source['original']['ext'] === 'pdf' && $source['original']['pages'] > 0
    ? t('library.original_info_pages', [strtoupper($source['original']['ext']), (string) $source['original']['pages'], (string) max(1, (int) round($source['original']['bytes'] / 1024))])
    : t('library.original_info', [strtoupper($source['original']['ext']), (string) max(1, (int) round($source['original']['bytes'] / 1024))])) ?><?php endif; ?>
</p>
<?php endif; ?>
<?php if ($source['canEdit']): ?>
<div class="wk-lib-edit" data-lib-edit-host hidden></div>
<template data-lib-form-template><?php $libraryFormMode = 'edit'; include __DIR__ . '/library-record-form.php'; ?></template>
<script type="application/json"><?= json_encode([
    'basePath' => $basePath,
    'pid' => $source['pid'],
    'rev' => $source['rev'],
    'csl' => $source['csl'],
    'tags' => $source['tags'],
    'strings' => [
        'saving' => t('library.saving'), 'failed' => t('library.failed'), 'copied' => t('library.copied'),
        'title_needed' => t('library.title_needed'), 'conflict' => t('library.err_conflict'), 'duplicate' => t('library.duplicate'),
    ],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<?php else: ?>
<script type="application/json"><?= json_encode(['strings' => ['copied' => t('library.copied')]], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>
<?php if ($source['canEdit'] && ($source['aiTasks'] ?? []) !== []): ?>
<div class="wk-lib-ai" data-island="library-ai">
<h3 class="wk-eyebrow"><i class="ph ph-sparkle" aria-hidden="true"></i> <?= $e(t('library.ai.title')) ?></h3>
<div class="wk-lib-ai-tasks">
<?php foreach ($source['aiTasks'] as $task): ?>
<button class="btn btn-secondary btn-sm" type="button" data-lib-ai-task="<?= $e($task) ?>"><?= $e(t('library.ai.' . $task)) ?></button>
<?php endforeach; ?>
</div>
<div class="wk-lib-ai-out" data-lib-ai-out aria-live="polite" hidden></div>
<script type="application/json"><?= json_encode([
    'basePath' => $basePath,
    'pid' => $source['pid'],
    'path' => $source['path'],
    'rev' => $source['rev'],
    'strings' => [
        'working' => t('library.ai.working'), 'progress' => t('library.ai.progress'), 'failed' => t('library.ai.failed'), 'note' => t('library.ai.note'),
        'copy' => t('library.ai.copy'), 'copied' => t('library.ai.copied'), 'use_summary' => t('library.ai.use_summary'),
    ],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
</div>
<script src="<?= $e(\Reporion\Support\Asset::url($basePath, 'js/render-client.js')) ?>" defer></script>
<?php endif; ?>
</section>
<script src="<?= $e(\Reporion\Support\Asset::url($basePath, 'js/library.js')) ?>" defer></script>
