<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Ask the notebook (docs/library.md, phase 5): a question about this page and the library sources
 * it cites, answered by the assistant (POST /api/v1/notebook/ask, assets/js/notebook-ask.js). The
 * answer cites `[@key, p. N]` and is rendered by the server's renderer, so the citations number
 * and link as in the page; nothing is saved.
 *
 * Variables in scope: array{path: string} $notebookAsk; string $basePath; array<string, mixed> $frontmatter
 */

declare(strict_types=1);

/** @var array{path: string} $notebookAsk */
/** @var string $basePath */
/** @var array<string, mixed> $frontmatter */
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
?>
<details class="wk-panel wk-notebook-ask" data-island="notebook-ask">
<summary class="wk-eyebrow"><i class="ph ph-chats-circle" aria-hidden="true"></i> <?= $e(t('notebook.title')) ?></summary>
<p class="wk-dim wk-text-sm"><?= $e(t('notebook.lede')) ?></p>
<form class="wk-notebook-form" data-notebook-form>
<div class="field"><label><?= $e(t('notebook.title')) ?>
<textarea class="input" name="question" rows="2" maxlength="1000" required placeholder="<?= $e(t('notebook.hint')) ?>"></textarea>
</label></div>
<footer class="wk-actions"><button class="btn btn-primary" type="submit" data-notebook-ask><i class="ph ph-sparkle" aria-hidden="true"></i><?= $e(t('notebook.ask')) ?></button></footer>
</form>
<div class="wk-notebook-out" data-notebook-out aria-live="polite" hidden></div>
<script type="application/json"><?= json_encode([
    'basePath' => $basePath,
    'path' => $notebookAsk['path'],
    'citationStyle' => \Reporion\Support\Citation::style($frontmatter['citation_style'] ?? null),
    'language' => \is_string($frontmatter['language'] ?? null) ? $frontmatter['language'] : '',
    'strings' => [
        'asking' => t('notebook.asking'), 'failed' => t('notebook.failed'), 'sources' => t('notebook.sources'),
        'whole' => t('notebook.whole'), 'passages' => t('notebook.passages'), 'understood' => t('notebook.understood'),
        'dropped' => t('notebook.dropped'), 'note' => t('notebook.note'),
    ],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
</details>
<script src="<?= $e(\Reporion\Support\Asset::url($basePath, 'js/render-client.js')) ?>" defer></script>
<script src="<?= $e(\Reporion\Support\Asset::url($basePath, 'js/notebook-ask.js')) ?>" defer></script>
