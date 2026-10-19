<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /ask (Controller\AskController, D53): Ask the archive. One question;
 * the answer, from the passages of finished reports the caller can read,
 * cites them by number; each source links to its report by pid and rev.
 * Mounted by assets/js/ask.js; the answer is rendered by the one parser
 * (render-client.js, POST /api/v1/render). Shown, never written.
 *
 * Variables in scope: string $question (from ?q=); bool $available; int $maxQuestion; string $basePath.
 */

declare(strict_types=1);

/** @var string $question */
/** @var bool $available */
/** @var int $maxQuestion */
/** @var string $basePath */
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
?>
<div class="wk-doc">
<header class="wk-doc-head">
<?= \Reporion\Http\Breadcrumb::render([['label' => t('ask.title'), 'icon' => 'chats-circle']]) ?>
<div class="wk-doc-titlerow"><hgroup><h1 class="wk-doc-title"><?= $e(t('ask.title')) ?></h1><p class="wk-dim"><?= $e(t('ask.lede')) ?></p></hgroup></div>
</header>
<?php if (!$available): ?>
<?php $noticeKind = 'info'; $noticeText = t('ask.unavailable'); include __DIR__ . '/partials/notice.php'; ?>
<?php else: ?>
<section class="wk-ask" data-island="ask">
<form class="wk-ask-compose" data-ask-form>
<label class="wk-ask-q"><?= $e(t('ask.question')) ?>
<textarea class="input" name="question" rows="3" maxlength="<?= $maxQuestion ?>" placeholder="<?= $e(t('ask.question_hint')) ?>" required><?= $e($question) ?></textarea>
<small><?= $e(t('ask.question_help')) ?></small></label>
<footer>
<span class="wk-mono wk-dim wk-text-xs wk-ask-keys"><kbd>Ctrl</kbd> + <kbd>Enter</kbd></span>
<button class="btn btn-primary" type="submit" data-ask-submit><i class="ph ph-chats-circle" aria-hidden="true"></i><?= $e(t('ask.submit')) ?></button>
</footer>
</form>
<div class="wk-ask-examples" data-ask-examples>
<span class="wk-eyebrow"><?= $e(t('ask.examples')) ?></span>
<?php foreach (['1', '2', '3'] as $n): ?>
<button class="wk-chip wk-ask-example" type="button" data-ask-example="<?= $e(t('ask.example_' . $n)) ?>"><?= $e(t('ask.example_' . $n)) ?></button>
<?php endforeach; ?>
</div>
<div data-ask-status aria-live="polite"></div>
<div class="wk-ask-results" data-ask-results hidden>
<div class="wk-panel wk-ask-answer-panel">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><i class="ph ph-sparkle" aria-hidden="true"></i> <?= $e(t('ask.answer')) ?></h2></hgroup><div class="wk-actions"><button class="btn btn-ghost btn-sm" type="button" data-ask-copy title="<?= $e(t('ask.copy')) ?>" aria-label="<?= $e(t('ask.copy')) ?>"><i class="ph ph-copy" aria-hidden="true"></i><span class="wk-btn-label"><?= $e(t('ask.copy')) ?></span></button></div></header>
<article class="wk-ask-answer wk-docbody" data-ask-answer></article>
<p class="wk-ask-meta wk-mono wk-dim wk-text-xs" data-ask-context hidden></p>
</div>
<div class="wk-panel wk-ask-sources-panel" data-ask-sources hidden>
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><i class="ph ph-books" aria-hidden="true"></i> <?= $e(t('ask.sources')) ?><span class="wk-count" data-ask-count></span></h2></hgroup></header>
<ol class="wk-ask-sources"></ol>
</div>
</div>
<script type="application/json"><?= json_encode([
    'basePath' => $basePath,
    'strings' => [
        'asking' => t('ask.asking'), 'failed' => t('ask.failed'), 'none' => t('ask.none'),
        'mine' => t('ask.mine'), 'open' => t('ask.open'), 'context' => t('ask.context'),
        'copy' => t('ask.copy'), 'copied' => t('ask.copied'),
    ],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
</section>
<script src="<?= $e(\Reporion\Support\Asset::url($basePath, 'js/render-client.js')) ?>" defer></script>
<script src="<?= $e(\Reporion\Support\Asset::url($basePath, 'js/ask.js')) ?>" defer></script>
<?php endif; ?>
</div>
