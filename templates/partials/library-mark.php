<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The reader's own marks on a library source (Auth\LibraryMarks, 2026-11-15): a star, and where
 * their reading stands (none → to read → reading → read, one click each). On a row of the list
 * and on the source's card; assets/js/library.js sends each change (PUT /api/v1/library/{pid}/mark).
 * Kept in the account's file: no one else sees them.
 *
 * Variables in scope: string $markPid, $markStatus ('' or toread|reading|read), $basePath; bool $markStar
 */

declare(strict_types=1);

/** @var string $markPid */
/** @var string $markStatus */
/** @var bool $markStar */
/** @var string $basePath */
$markIcons = ['' => 'ph-circle-dashed', 'toread' => 'ph-bookmark-simple', 'reading' => 'ph-book-open', 'read' => 'ph-check-circle'];
$markLabels = ['' => t('library.mark.none'), 'toread' => t('library.mark.toread'), 'reading' => t('library.mark.reading'), 'read' => t('library.mark.read')];
?>
<span class="wk-lib-marks" data-lib-mark data-url="<?= htmlspecialchars($basePath . '/api/v1/library/' . $markPid . '/mark', ENT_QUOTES) ?>" data-status="<?= htmlspecialchars($markStatus, ENT_QUOTES) ?>" data-labels="<?= htmlspecialchars((string) json_encode($markLabels, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>">
<button type="button" class="wk-tbtn wk-lib-star<?= $markStar ? ' is-on' : '' ?>" data-mark-star aria-pressed="<?= $markStar ? 'true' : 'false' ?>" title="<?= htmlspecialchars(t('library.mark.star'), ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('library.mark.star'), ENT_QUOTES) ?>"><i class="ph ph-star" aria-hidden="true"></i></button>
<button type="button" class="wk-tbtn wk-lib-status" data-mark-status title="<?= htmlspecialchars($markLabels[$markStatus] ?? $markLabels[''], ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars($markLabels[$markStatus] ?? $markLabels[''], ENT_QUOTES) ?>"><i class="ph <?= $markIcons[$markStatus] ?? $markIcons[''] ?>" aria-hidden="true"></i></button>
</span>
