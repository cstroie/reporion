<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /x/hipobridge/ask: Ask HippoBridge (D38, D53), the Ask the archive screen
 * over the HIS's texts. The answer cites the passages by number; a passage is
 * shown by its date and kind only (no name, no CNP, no link: the bridge has
 * no deep link). Mounted by core's assets/js/ask.js; the answer is rendered by the one parser
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
<?= \Reporion\Http\Breadcrumb::render([['label' => t('hipobridge.ask.title'), 'icon' => 'chats-circle']]) ?>
<div class="wk-doc-titlerow"><h1 class="wk-doc-title"><?= $e(t('hipobridge.ask.title')) ?></h1></div>
<p class="wk-dim wk-text-sm"><?= $e(t('hipobridge.ask.lede')) ?></p>
</header>
<?php if (!$available): ?>
<?php $noticeKind = 'info'; $noticeText = t('hipobridge.ask.unavailable'); include \dirname(__DIR__, 3) . '/templates/partials/notice.php'; ?>
<?php else: ?>
<section class="wk-ask" data-island="ask">
<form class="wk-ask-form" data-ask-form>
<label class="wk-text-sm"><?= $e(t('hipobridge.ask.question')) ?>
<textarea class="input" name="question" rows="3" maxlength="<?= $maxQuestion ?>" placeholder="<?= $e(t('hipobridge.ask.question_hint')) ?>" required><?= $e($question) ?></textarea>
<small class="wk-dim"><?= $e(t('hipobridge.ask.question_help')) ?></small></label>
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
    'endpoint' => '/x/hipobridge/ask',
    'strings' => [
        'asking' => t('ask.asking'), 'failed' => t('ask.failed'), 'none' => t('hipobridge.ask.none'),
        'mine' => t('ask.mine'), 'open' => t('ask.open'), 'context' => t('ask.context'),
    ],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
</section>
<script src="<?= $e(\Reporion\Support\Asset::url($basePath, 'js/render-client.js')) ?>" defer></script>
<script src="<?= $e(\Reporion\Support\Asset::url($basePath, 'js/ask.js')) ?>" defer></script>
<?php endif; ?>
</div>
