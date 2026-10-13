<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * What a group gives its members (D47): radiologist or not, and its grants. Inside the caller's <form>.
 *
 * Variables in scope: bool $radiologist; string $grantsText
 */

declare(strict_types=1);

/** @var bool $radiologist */
/** @var string $grantsText */

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
?>
<fieldset class="wk-access">
<legend class="wk-eyebrow"><?= $e(t('admin.groups.members_are')) ?></legend>
<label class="radio"><input type="checkbox" name="radiologist" value="1"<?= $radiologist ? ' checked' : '' ?>><span class="dot"></span><span><b><?= $e(t('admin.groups.radiologist')) ?></b><br><span class="wk-dim wk-text-sm"><?= $e(t('admin.users.radiologist_help')) ?></span></span></label>
</fieldset>
<div class="field">
<label for="grants"><?= $e(t('admin.groups.grants_label')) ?></label>
<textarea class="input wk-mono" id="grants" name="grants" rows="4" placeholder="reports:editor&#10;templates:viewer" aria-describedby="grants-help"><?= $e($grantsText) ?></textarea>
<p class="wk-help wk-dim" id="grants-help"><?= $e(t('admin.users.grants_help')) ?></p>
</div>
