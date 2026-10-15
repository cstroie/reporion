<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * One maintenance task's card: its options, and the Check / Apply buttons.
 * Shared by Admin → Maintenance and Admin → Tags (the tag tasks). A non-empty
 * $return sends the owner back to that screen with the run's report.
 *
 * Variables in scope: string $name; MaintenanceTask $task; string $basePath; string $return
 */

declare(strict_types=1);

use Reporion\Controller\AdminMaintenanceController as Maint;
use Reporion\Service\Maintenance\MaintenanceTask;

$b = htmlspecialchars($basePath, ENT_QUOTES);
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
?>
<div class="wk-panel" id="<?= $e(Maint::anchor($name)) ?>">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.maint.task.' . $name . '.title')) ?></h2><p><?= $e(t('admin.maint.task.' . $name . '.desc')) ?></p></hgroup></header>
<form data-busy class="wk-form wk-maint-form" action="<?= $b ?>/admin/maintenance/<?= $e($name) ?>" method="post">
<?php if ($return !== ''): ?><input type="hidden" name="return" value="<?= $e($return) ?>"><?php endif; ?>
<?php $fid = 'opt-' . Maint::anchor($name); ?>
<?php if ($name === 'journal:replay'): ?>
<div class="field"><label for="<?= $e($fid) ?>-min-age"><?= $e(t('admin.maint.opt.min_age')) ?></label>
<input class="input wk-input-num" id="<?= $e($fid) ?>-min-age" type="number" name="min_age" min="0" value="60"></div>
<?php elseif ($name === 'index:vectors'): ?>
<div class="field"><label for="<?= $e($fid) ?>-limit"><?= $e(t('admin.maint.opt.limit')) ?></label>
<input class="input wk-input-num" id="<?= $e($fid) ?>-limit" type="number" name="limit" min="0" value="500"></div>
<?php elseif ($name === 'pages:summarize' || $name === 'pages:tag'): ?>
<div class="wk-maint-opts">
<div class="field"><label for="<?= $e($fid) ?>-namespace"><?= $e(t('admin.maint.opt.namespace')) ?></label>
<input class="input wk-mono" id="<?= $e($fid) ?>-namespace" type="text" name="namespace" value="" placeholder="reports:mri"></div>
<div class="field"><label for="<?= $e($fid) ?>-limit"><?= $e(t('admin.maint.opt.limit')) ?></label>
<input class="input wk-input-num" id="<?= $e($fid) ?>-limit" type="number" name="limit" min="0" value="20"></div>
<label class="radio"><input type="checkbox" name="overwrite" value="1"><span class="dot"></span><?= $e(t($name === 'pages:tag' ? 'admin.maint.opt.overwrite_tags' : 'admin.maint.opt.overwrite')) ?></label>
</div>
<?php elseif ($name === 'tags:prune'): ?>
<div class="field"><label for="<?= $e($fid) ?>-max-uses"><?= $e(t('admin.maint.opt.max_uses')) ?></label>
<input class="input wk-input-num" id="<?= $e($fid) ?>-max-uses" type="number" name="max_uses" min="1" max="5" value="1"></div>
<?php elseif ($name === 'tags:review'): ?>
<input type="hidden" name="ai" value="0">
<label class="radio"><input type="checkbox" name="ai" value="1" checked><span class="dot"></span><?= $e(t('admin.maint.opt.ai')) ?></label>
<?php elseif ($name === 'integrity:verify'): ?>
<div class="field"><label for="<?= $e($fid) ?>-backup"><?= $e(t('admin.maint.opt.backup')) ?></label>
<input class="input wk-mono" id="<?= $e($fid) ?>-backup" type="text" name="backup" value="" placeholder="/mnt/backup/reporion/latest/data"></div>
<?php elseif ($name === 'trash:purge'): ?>
<div class="wk-maint-opts">
<div class="field"><label for="<?= $e($fid) ?>-older-than"><?= $e(t('admin.maint.opt.older_than')) ?></label>
<input class="input wk-input-num" id="<?= $e($fid) ?>-older-than" type="number" name="older_than" min="0" value="<?= (int) $task->options([])['older_than'] ?>"></div>
<label class="radio"><input type="checkbox" name="include_signed" value="1"><span class="dot"></span><?= $e(t('admin.maint.opt.include_signed')) ?></label>
</div>
<?php endif; ?>
<?php /* The footer: the same task on the command line at the left; Check, then confirm + Apply, at the right */ ?>
<footer>
<p class="wk-mono"><?= $e('bin/reporion ' . $name) ?></p>
<button class="btn btn-secondary" type="submit" name="mode" value="check"><i class="ph ph-magnifying-glass"></i><?= $e(t('admin.maint.task.' . $name . '.check')) ?></button>
<?php if (\in_array(MaintenanceTask::APPLY, $task->modes(), true)): ?>
<span class="wk-apply">
<label class="radio"><input type="checkbox" name="confirm" value="1"><span class="dot"></span><?= $e(t('admin.maint.task.' . $name . '.confirm')) ?></label>
<button class="btn btn-primary" type="submit" name="mode" value="apply"><i class="ph ph-play"></i><?= $e(t('admin.maint.task.' . $name . '.apply')) ?></button>
</span>
<?php endif; ?>
</footer>
</form>
</div>
