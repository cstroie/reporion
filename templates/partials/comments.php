<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * A page's comments (D52): the page view's panel and the editor's rail
 * section, mounted by assets/js/comments.js (the page includes the script).
 *
 * Variables in scope: string $basePath, $path; ?array{username: string, owner: bool} $commenter.
 */

declare(strict_types=1);

/** @var string $basePath */
/** @var string $path */
/** @var ?array{username: string, owner: bool} $commenter */
?>
<section class="wk-comments" data-island="comments" aria-labelledby="comments-h-<?= md5($path) ?>">
<h2 class="wk-eyebrow" id="comments-h-<?= md5($path) ?>"><i class="ph ph-chat-circle-text" aria-hidden="true"></i> <?= htmlspecialchars(t('comments.title'), ENT_QUOTES) ?> <span class="wk-dim" data-comments-count></span></h2>
<ol class="wk-comment-list" data-comments-list><li class="wk-mono wk-dim"><?= htmlspecialchars(t('comments.loading'), ENT_QUOTES) ?></li></ol>
<details class="wk-comments-resolved" data-comments-resolved hidden><summary class="wk-mono wk-dim"></summary><ol class="wk-comment-list"></ol></details>
<form class="wk-comment-form" data-comments-form>
<blockquote class="wk-comment-quote" data-comments-quote hidden></blockquote>
<label class="wk-text-sm"><?= htmlspecialchars(t('comments.new'), ENT_QUOTES) ?>
<textarea class="input" name="text" rows="3" maxlength="<?= \Reporion\Service\Comments::MAX_TEXT ?>" placeholder="<?= htmlspecialchars(t('comments.new_hint'), ENT_QUOTES) ?>"></textarea>
<small class="wk-dim"><?= htmlspecialchars(t('comments.new_help'), ENT_QUOTES) ?></small></label>
<div class="wk-recall-row"><button class="btn btn-primary btn-sm" type="submit"><i class="ph ph-paper-plane-right" aria-hidden="true"></i><?= htmlspecialchars(t('comments.add'), ENT_QUOTES) ?></button></div>
<p class="wk-mono wk-dim" data-comments-status aria-live="polite"></p>
</form>
<script type="application/json"><?= json_encode([
    'basePath' => $basePath,
    'path' => $path,
    'me' => $commenter['username'] ?? null,
    'owner' => ($commenter['owner'] ?? false) === true,
    'strings' => [
        'none' => t('comments.none'), 'failed' => t('comments.failed'), 'resolved' => t('comments.resolved'),
        'resolve' => t('comments.resolve'), 'reopen' => t('comments.reopen'), 'delete' => t('comments.delete'),
        'deleteConfirm' => t('comments.delete_confirm'), 'quote' => t('comments.quote'), 'rev' => t('comments.rev'),
        'resolvedBy' => t('comments.resolved_by'),
    ],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
</section>
