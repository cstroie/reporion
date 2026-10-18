<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Admin → Tags: a finished tags:review run's proposals (2026-10-15), each
 * with a tick to apply it, its target editable, and a "never suggest" tick.
 * Posts to /admin/tags/review/{runId} (AdminTagsController::review()).
 * Proposals already done (none of their tags in use) or turned down are
 * shown dimmed, without ticks.
 *
 * Variables in scope: list<array{i: int, op: string, from: array<string, int>, to: string, reason: string, source: string, done: bool, rejected: bool}> $review;
 * string $runId; string $basePath
 */

declare(strict_types=1);

/** @var list<array{i: int, op: string, from: array<string, int>, to: string, reason: string, source: string, done: bool, rejected: bool}> $review */
/** @var string $runId */
/** @var string $basePath */

$b = htmlspecialchars($basePath, ENT_QUOTES);
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$open = array_filter($review, static fn (array $r): bool => !$r['done'] && !$r['rejected']);
?>
<div class="wk-panel" id="review">
<form data-busy action="<?= $b ?>/admin/tags/review/<?= $e($runId) ?>" method="post">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><i class="ph ph-sparkle"></i> <?= $e(t('admin.tags.review.title')) ?></h2><p><?= $e(t('admin.tags.review.help')) ?></p></hgroup>
<?php if ($open !== []): ?><button class="btn btn-primary btn-sm" type="submit"><i class="ph ph-check"></i><?= $e(t('admin.tags.review.apply')) ?></button><?php endif; ?></header>
<?php if ($review === []): ?>
<p class="wk-empty"><?= $e(t('admin.tags.review.none')) ?></p>
<?php else: ?>
<table class="table table-cards">
<thead><tr><th class="wk-selcol"></th><th><?= $e(t('admin.tags.review.col_op')) ?></th><th><?= $e(t('admin.tags.review.col_from')) ?></th><th><?= $e(t('admin.tags.review.col_to')) ?></th><th><?= $e(t('admin.tags.review.col_why')) ?></th><th><?= $e(t('admin.tags.review.never')) ?></th></tr></thead>
<tbody>
<?php foreach ($review as $row): ?>
<?php $closed = $row['done'] || $row['rejected']; ?>
<tr<?= $closed ? ' class="wk-dim"' : '' ?>>
<td class="wk-selcol"><?php if (!$closed): ?><label class="radio"><input type="checkbox" name="pick[]" value="<?= $row['i'] ?>" aria-label="<?= $e(t('admin.tags.review.pick', [implode(', ', array_keys($row['from']))])) ?>"><span class="dot"></span></label><?php endif; ?></td>
<td class="wk-card-head"><span class="tag tag-outline wk-mono"><?= $e(t('admin.tags.review.op.' . $row['op'])) ?></span> <span class="wk-dim wk-text-sm wk-mono"><?= $e($row['source']) ?></span><?php if ($row['done']): ?> <span class="tag tag-neutral"><?= $e(t('admin.tags.review.done')) ?></span><?php elseif ($row['rejected']): ?> <span class="tag tag-neutral"><?= $e(t('admin.tags.review.rejected')) ?></span><?php endif; ?></td>
<td data-label="<?= $e(t('admin.tags.review.col_from')) ?>"><?php foreach ($row['from'] as $tag => $n): ?><span class="wk-chip"><?= $e((string) $tag) ?> · <?= (int) $n ?></span> <?php endforeach; ?></td>
<td data-label="<?= $e(t('admin.tags.review.col_to')) ?>"><?php if ($row['op'] === 'drop'): ?><span class="wk-dim">n/a</span><?php elseif ($closed): ?><span class="wk-mono"><?= $e($row['to']) ?></span><?php else: ?><input class="input wk-inline-input" type="text" name="to[<?= $row['i'] ?>]" maxlength="64" value="<?= $e($row['to']) ?>" aria-label="<?= $e(t('admin.tags.review.col_to')) ?>"><?php endif; ?></td>
<td class="wk-dim wk-text-sm" data-label="<?= $e(t('admin.tags.review.col_why')) ?>"><?= $e($row['reason']) ?></td>
<td data-label="<?= $e(t('admin.tags.review.never')) ?>"><?php if (!$closed): ?><label class="radio"><input type="checkbox" name="reject[]" value="<?= $row['i'] ?>" aria-label="<?= $e(t('admin.tags.review.never')) ?>"><span class="dot"></span></label><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</form>
</div>
