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
<div class="wk-doc-titlerow"><h1 class="wk-doc-title"><?= $e(t('ask.title')) ?></h1></div>
<p class="wk-dim wk-text-sm"><?= $e(t('ask.lede')) ?></p>
</header>
<?php if (!$available): ?>
<?php $noticeKind = 'info'; $noticeText = t('ask.unavailable'); include __DIR__ . '/partials/notice.php'; ?>
<?php else: ?>
<section class="wk-ask" data-island="ask">
<form class="wk-ask-form" data-ask-form>
<label class="wk-text-sm"><?= $e(t('ask.question')) ?>
<textarea class="input" name="question" rows="3" maxlength="<?= $maxQuestion ?>" placeholder="<?= $e(t('ask.question_hint')) ?>" required><?= $e($question) ?></textarea>
<small class="wk-dim"><?= $e(t('ask.question_help')) ?></small></label>
<div class="wk-recall-row"><button class="btn btn-primary btn-sm" type="submit" data-ask-submit><i class="ph ph-chats-circle" aria-hidden="true"></i><?= $e(t('ask.submit')) ?></button></div>
</form>
<div data-ask-status aria-live="polite"></div>
<article class="wk-ask-answer wk-docbody" data-ask-answer hidden></article>
<p class="wk-mono wk-dim wk-text-xs" data-ask-context hidden></p>
<section data-ask-sources hidden>
<h2 class="wk-eyebrow"><i class="ph ph-books" aria-hidden="true"></i> <?= $e(t('ask.sources')) ?></h2>
<ol class="wk-ask-sources"></ol>
</section>
<script type="application/json"><?= json_encode([
    'basePath' => $basePath,
    'strings' => [
        'asking' => t('ask.asking'), 'failed' => t('ask.failed'), 'none' => t('ask.none'),
        'mine' => t('ask.mine'), 'open' => t('ask.open'), 'context' => t('ask.context'),
    ],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
</section>
<script src="<?= $e(\Reporion\Support\Asset::url($basePath, 'js/render-client.js')) ?>" defer></script>
<script src="<?= $e(\Reporion\Support\Asset::url($basePath, 'js/ask.js')) ?>" defer></script>
<?php endif; ?>
</div>
