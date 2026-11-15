<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Ask this paper (2026-11-15, docs/library.md): Ask the library kept to the one source the page
 * is (POST /api/v1/library/ask with `source`), on its Overview; the same island
 * (assets/js/notebook-ask.js) and prompt (`ask-library`). The answer cites its pages; shown,
 * never written.
 *
 * Variables in scope: array{pid: string} $librarySource; string $basePath
 */

declare(strict_types=1);

/** @var array{pid: string} $librarySource */
/** @var string $basePath */
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
?>
<details class="wk-panel wk-tool wk-notebook-ask" data-island="notebook-ask" data-lib-tabpanel="overview" open>
<summary class="wk-eyebrow"><i class="ph ph-chats-circle" aria-hidden="true"></i> <?= $e(t('library.ask_source.title')) ?></summary>
<p class="wk-dim wk-text-sm"><?= $e(t('library.ask_source.lede')) ?></p>
<form class="wk-notebook-form" data-notebook-form>
<div class="field"><label><?= $e(t('library.ask.question')) ?>
<textarea class="input" name="question" rows="2" maxlength="1000" required placeholder="<?= $e(t('library.ask_source.hint')) ?>"></textarea>
</label></div>
<footer class="wk-actions"><button class="btn btn-primary" type="submit" data-notebook-ask><i class="ph ph-sparkle" aria-hidden="true"></i><?= $e(t('notebook.ask')) ?></button><button class="btn btn-ghost" type="button" data-notebook-clear hidden><i class="ph ph-arrow-counter-clockwise" aria-hidden="true"></i><?= $e(t('library.ask.clear')) ?></button></footer>
</form>
<div class="wk-notebook-out" data-notebook-out aria-live="polite" hidden></div>
<script type="application/json"><?= json_encode([
    'basePath' => $basePath,
    'endpoint' => '/api/v1/library/ask',
    'source' => $librarySource['pid'],
    'history' => true,
    'citationStyle' => \Reporion\Support\Citation::style(null),
    'strings' => [
        'asking' => t('notebook.asking'), 'failed' => t('notebook.failed'), 'sources' => t('notebook.sources'),
        'whole' => t('library.ask_source.whole'), 'passages' => t('library.ask_source.passages'), 'understood' => t('notebook.understood'),
        'dropped' => t('notebook.dropped'), 'note' => t('notebook.note'),
        'step_terms' => t('notebook.step_terms'), 'step_searching' => t('notebook.step_searching'), 'step_answering' => t('notebook.step_answering'),
    ],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
</details>
<script src="<?= $e(\Reporion\Support\Asset::url($basePath, 'js/render-client.js')) ?>" defer></script>
<script src="<?= $e(\Reporion\Support\Asset::url($basePath, 'js/notebook-ask.js')) ?>" defer></script>
