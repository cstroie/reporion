<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * What the assistant made of a page before (Service\Library\Analyses, 2026-10-31): each saved
 * analysis of a library source, or Studio text of a notebook, kept beside it in a page named by
 * its prompt, folded, its text rendered by the server (PageTemplateRenderer::rendered()), with
 * when it was made, whether the page's text has changed since, and a link to its own page (its
 * history keeps the earlier runs).
 *
 * Variables in scope: list<array{prompt: string, path: string, at: string, provider: string, stale: bool, html: string}> $savedAnalyses;
 * array<string, string> $savedLabels prompt => label; string $basePath
 */

declare(strict_types=1);

/** @var list<array{prompt: string, path: string, at: string, provider: string, stale: bool, html: string}> $savedAnalyses */
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
<div class="wk-saved-text wk-prose"><?= $saved['html'] ?></div>
<p class="wk-dim wk-text-xs"><a href="<?= $e($basePath . '/' . $saved['path']) ?>"><?= $e(t('library.saved.open')) ?></a> · <?= $e(t('library.saved.note')) ?></p>
</details>
<?php endforeach; ?>
</div>
<?php endif; ?>
