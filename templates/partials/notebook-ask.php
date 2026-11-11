<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Ask the notebook (docs/library.md, phase 5): a question about this page and the library sources
 * it cites, answered by the assistant (POST /api/v1/notebook/ask, assets/js/notebook-ask.js). The
 * answer cites `[@key, p. N]` and is rendered by the server's renderer, so the citations number
 * and link as in the page. Nothing is saved unless the writer chooses (2026-11-11): *Keep* adds the
 * answer to the notebook's kept answers beside it (POST /api/v1/notebook/keep), *Insert* opens the
 * editor with it added at the end of the page, saved only by the writer's Save (`assisted: ask`).
 *
 * Variables in scope: array{path: string, kept: ?string} $notebookAsk; string $basePath; array<string, mixed> $frontmatter
 */

declare(strict_types=1);

/** @var array{path: string, kept: ?string} $notebookAsk */
/** @var string $basePath */
/** @var array<string, mixed> $frontmatter */
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
?>
<details class="wk-panel wk-tool wk-notebook-ask" data-island="notebook-ask">
<summary class="wk-eyebrow"><i class="ph ph-chats-circle" aria-hidden="true"></i> <?= $e(t('notebook.title')) ?></summary>
<p class="wk-dim wk-text-sm"><?= $e(t('notebook.lede')) ?></p>
<form class="wk-notebook-form" data-notebook-form>
<div class="field"><label><?= $e(t('notebook.question')) ?>
<textarea class="input" name="question" rows="2" maxlength="1000" required placeholder="<?= $e(t('notebook.hint')) ?>"></textarea>
</label></div>
<footer class="wk-actions"><button class="btn btn-primary" type="submit" data-notebook-ask><i class="ph ph-sparkle" aria-hidden="true"></i><?= $e(t('notebook.ask')) ?></button></footer>
</form>
<div class="wk-notebook-out" data-notebook-out aria-live="polite" hidden></div>
<p class="wk-text-sm" data-notebook-kept<?= $notebookAsk['kept'] === null ? ' hidden' : '' ?>><a href="<?= $e($notebookAsk['kept'] ?? '') ?>"><i class="ph ph-bookmarks" aria-hidden="true"></i> <?= $e(t('notebook.kept_link')) ?></a></p>
<script type="application/json"><?= json_encode([
    'basePath' => $basePath,
    'path' => $notebookAsk['path'],
    'keep' => true,
    'editHref' => $basePath . '/' . $notebookAsk['path'] . '/edit',
    'citationStyle' => \Reporion\Support\Citation::style($frontmatter['citation_style'] ?? null),
    'language' => \is_string($frontmatter['language'] ?? null) ? $frontmatter['language'] : '',
    'strings' => [
        'asking' => t('notebook.asking'), 'failed' => t('notebook.failed'), 'sources' => t('notebook.sources'),
        'whole' => t('notebook.whole'), 'passages' => t('notebook.passages'), 'understood' => t('notebook.understood'),
        'dropped' => t('notebook.dropped'), 'note' => t('notebook.note_keep'),
        'keep' => t('notebook.keep'), 'keep_title' => t('notebook.keep_title'), 'keeping' => t('notebook.keeping'), 'kept' => t('notebook.kept'),
        'insert' => t('notebook.insert'), 'insert_title' => t('notebook.insert_title'),
        'step_terms' => t('notebook.step_terms'), 'step_searching' => t('notebook.step_searching'), 'step_answering' => t('notebook.step_answering'),
    ],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
</details>
<script src="<?= $e(\Reporion\Support\Asset::url($basePath, 'js/render-client.js')) ?>" defer></script>
<script src="<?= $e(\Reporion\Support\Asset::url($basePath, 'js/notebook-ask.js')) ?>" defer></script>
