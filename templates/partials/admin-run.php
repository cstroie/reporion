<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The top of an admin tab that runs maintenance tasks (Controller\TaskPanels):
 * the busy and refused notices, then the report of the run just made.
 *
 * Variables in scope: TaskPanels::vars() (bool $busy; ?string $maintError; the run's view); string $basePath
 */

declare(strict_types=1);

/** @var bool $busy */
/** @var ?string $maintError */
?>
<?php if ($busy): ?>
<?php $noticeKind = 'warn'; $noticeText = t('admin.maint.busy'); include __DIR__ . '/notice.php'; ?>
<?php endif; ?>
<?php if ($maintError !== null): ?>
<?php $noticeKind = 'error'; $noticeText = $maintError; include __DIR__ . '/notice.php'; ?>
<?php endif; ?>
<?php include __DIR__ . '/maint-report.php'; ?>
