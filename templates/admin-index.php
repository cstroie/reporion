<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Admin → Index & storage (Controller\AdminIndexController) — content only.
 * Counts and drift only: no page path is listed (trash and journal names
 * carry the patient-named segment, invariant 8).
 *
 * Variables in scope: array $status (Service\IndexMaintenance::status()),
 * array{orphans: list<string>, missing: list<string>, drifted: list<string>} $drift,
 * ?int $rebuilt, bool $busy, string $adminTab, $basePath
 */

declare(strict_types=1);

/** @var array<string, mixed> $status */
/** @var array{orphans: list<string>, missing: list<string>, drifted: list<string>} $drift */
/** @var ?int $rebuilt */
/** @var bool $busy */
$m = htmlspecialchars($basePath, ENT_QUOTES) . '/admin/maintenance';
/** @var string $basePath */

$clean = $drift['orphans'] === [] && $drift['missing'] === [] && $drift['drifted'] === [];
?>
<div class="wk-doc">
<div class="wk-doc-head">
<div class="wk-crumbs wk-mono"><b><?= htmlspecialchars(t('nav.admin'), ENT_QUOTES) ?></b><span>›</span><span><?= htmlspecialchars(t('admin.index.title'), ENT_QUOTES) ?></span></div>
<h1 class="wk-doc-title"><?= htmlspecialchars(t('admin.index.title'), ENT_QUOTES) ?></h1>
</div>
<?php include __DIR__ . '/admin-tabs.php'; ?>

<?php if ($busy): ?>
<div class="wk-notice" role="alert"><i class="ph ph-warning"></i><div><?= htmlspecialchars(t('admin.maint.busy'), ENT_QUOTES) ?></div></div>
<?php endif; ?>
<?php if ($rebuilt !== null): ?>
<div class="wk-notice" role="status"><i class="ph ph-check"></i><div><?= htmlspecialchars(t('admin.index.rebuilt', [$rebuilt]), ENT_QUOTES) ?></div></div>
<?php endif; ?>

<div class="wk-panel">
<div class="wk-panel-h"><span class="wk-eyebrow"><?= htmlspecialchars(t('admin.index.pages'), ENT_QUOTES) ?></span><span class="wk-count"><?= (int) $status['total'] ?></span></div>
<div class="wk-stats">
<?php foreach (['draft', 'signed', 'archived'] as $key): ?>
<div class="wk-stat"><b><?= (int) ($status['byStatus'][$key] ?? 0) ?></b><span><?= htmlspecialchars($key, ENT_QUOTES) ?></span></div>
<?php endforeach; ?>
<?php foreach (['private', 'unlisted', 'public'] as $key): ?>
<div class="wk-stat"><b><?= (int) ($status['byVisibility'][$key] ?? 0) ?></b><span><?= htmlspecialchars($key, ENT_QUOTES) ?></span></div>
<?php endforeach; ?>
</div>
</div>

<div class="wk-panel">
<div class="wk-panel-h"><span class="wk-eyebrow"><?= htmlspecialchars(t('admin.index.health'), ENT_QUOTES) ?></span></div>
<div class="wk-kv">
<span><?= htmlspecialchars(t('admin.index.drift'), ENT_QUOTES) ?></span><b><?= $clean ? htmlspecialchars(t('admin.index.clean'), ENT_QUOTES) : htmlspecialchars(t('admin.index.drift_counts', [\count($drift['missing']), \count($drift['orphans']), \count($drift['drifted'])]), ENT_QUOTES) ?></b>
<span><?= htmlspecialchars(t('admin.index.intents'), ENT_QUOTES) ?></span><b><?= (int) $status['openIntents'] ?><?php if ((int) $status['openIntents'] > 0): ?> · <a href="<?= $m ?>#journal-replay"><?= htmlspecialchars(t('admin.index.to_replay'), ENT_QUOTES) ?></a><?php endif; ?></b>
<span><?= htmlspecialchars(t('admin.index.trash'), ENT_QUOTES) ?></span><b><?= (int) $status['trashEntries'] ?><?php if ((int) $status['trashEntries'] > 0): ?> · <a href="<?= $m ?>#trash-purge"><?= htmlspecialchars(t('admin.index.to_purge'), ENT_QUOTES) ?></a><?php endif; ?></b>
<span><?= htmlspecialchars(t('admin.index.audit'), ENT_QUOTES) ?></span><b class="wk-mono"><?php if ($status['audit'] === []): ?>—<?php else: ?><?php foreach ($status['audit'] as $file): ?><?= htmlspecialchars($file['file'], ENT_QUOTES) ?> (<?= number_format($file['bytes'] / 1024, 1) ?> KB) <?php endforeach; ?><?php endif; ?></b>
</div>
</div>

<div class="wk-panel">
<div class="wk-panel-h"><span class="wk-eyebrow"><?= htmlspecialchars(t('admin.index.rebuild'), ENT_QUOTES) ?></span></div>
<p style="font-size:13px;margin:0 0 var(--space-3)"><?= htmlspecialchars(t('admin.index.rebuild_note'), ENT_QUOTES) ?></p>
<form action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/admin/index/rebuild" method="post">
<button class="btn btn-secondary" type="submit"><i class="ph ph-arrows-clockwise"></i><?= htmlspecialchars(t('admin.index.rebuild'), ENT_QUOTES) ?></button>
</form>
</div>
</div>
