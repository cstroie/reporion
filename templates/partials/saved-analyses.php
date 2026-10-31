<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * What the assistant made of a page before (Service\Library\Analyses, 2026-10-31): each saved
 * analysis of a library source, or Studio text of a notebook, kept beside it in a page named by
 * its prompt, folded, its text rendered by the server (PageTemplateRenderer::rendered()), with
 * when it was made, whether the page's text has changed since, and a link to its own page (its
 * history keeps the earlier runs); an audio overview with its player (GET /library/audio/{pid}).
 *
 * Variables in scope: list<array{prompt: string, path: string, at: string, provider: string, stale: bool, html: string}> $savedAnalyses;
 * array<string, string> $savedLabels prompt => label; string $basePath
 */

declare(strict_types=1);

/** @var list<array{prompt: string, pid: string, path: string, at: string, provider: string, stale: bool, html: string, audio?: ?array{ext: string, seconds: int}}> $savedAnalyses */
/** @var array<string, string> $savedLabels */
/** @var string $basePath */
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
?>
<?php if ($savedAnalyses !== []): ?>
<div class="wk-saved" data-saved>
<h3 class="wk-eyebrow"><i class="ph ph-archive" aria-hidden="true"></i> <?= $e(t('library.saved.title')) ?></h3>
<?php foreach ($savedAnalyses as $saved): ?>
<details class="wk-saved-item" data-saved-prompt="<?= $e($saved['prompt']) ?>">
<summary><span><?= $e($savedLabels[$saved['prompt']] ?? $saved['prompt']) ?></span>
<span class="wk-dim wk-text-xs wk-mono"><?= $e($saved['at'] !== '' ? substr($saved['at'], 0, 10) : '') ?><?= $saved['provider'] !== '' ? ' · ' . $e($saved['provider']) : '' ?></span>
<?php if ($saved['stale']): ?><span class="wk-chip" title="<?= $e(t('library.saved.stale_help')) ?>"><?= $e(t('library.saved.stale')) ?></span><?php endif; ?></summary>
<?php if (($saved['audio'] ?? null) !== null): ?>
<p class="wk-saved-audio"><audio controls preload="none" src="<?= $e($basePath . '/library/audio/' . $saved['pid']) ?>"></audio> <span class="wk-dim wk-text-xs wk-mono"><?= $e(sprintf('%d:%02d', intdiv($saved['audio']['seconds'], 60), $saved['audio']['seconds'] % 60)) ?> · <?= $e(strtoupper($saved['audio']['ext'])) ?></span></p>
<?php endif; ?>
<div class="wk-saved-text wk-prose"><?= $saved['html'] ?></div>
<p class="wk-dim wk-text-xs"><?php if (\in_array($saved['prompt'], ['source-flashcards', 'studio-flashcards'], true)): ?><a class="btn btn-secondary btn-sm" href="<?= $e($basePath . '/library/flashcards/' . $saved['pid']) ?>" title="<?= $e(t('library.saved.anki_help')) ?>"><i class="ph ph-cards" aria-hidden="true"></i><?= $e(t('library.saved.anki')) ?></a> <?php endif; ?><a href="<?= $e($basePath . '/' . $saved['path']) ?>"><?= $e(t('library.saved.open')) ?></a> · <?= $e(t('library.saved.note')) ?></p>
</details>
<?php endforeach; ?>
</div>
<?php endif; ?>
