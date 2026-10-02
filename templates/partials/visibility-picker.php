<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The one control that changes a page's visibility — the editor's Metadata
 * view and GET /{path}/visibility both include it. Three option cards
 * (Support\Visibility: icon, label, sentence); the saved level is marked
 * "now". Choosing Public on a page that is not public opens, in place, what
 * D16 asks for: what everyone will be able to read and the acknowledgement
 * (`acknowledge=1`), which the server requires (Service\Publishing) — the
 * CSS shows it, nothing is hidden from a form without it.
 *
 * Variables in scope: string $visName (the radio's name); string $visNow
 * (saved); string $visChosen; array $visPreview (Publishing::previewOf());
 * bool $visAckMissing (a post that chose Public without the acknowledgement)
 */

declare(strict_types=1);

use Reporion\Support\Visibility;

/** @var string $visName */
/** @var string $visNow */
/** @var string $visChosen */
/** @var array{path: string, title: string, pathLooksPersonal: bool, hiddenPatientFields: list<string>, attachedMedia: int} $visPreview */
/** @var bool $visAckMissing */

$ve = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$visNow = Visibility::normal($visNow);
$visChosen = Visibility::normal($visChosen);
?>
<fieldset class="wk-vispick">
<div class="wk-vispick-opts" role="radiogroup">
<?php foreach (Visibility::LEVELS as $level): ?>
<label class="wk-vispick-opt wk-vispick-opt-<?= $level ?>">
<input type="radio" name="<?= $ve($visName) ?>" value="<?= $level ?>"<?= $level === $visChosen ? ' checked' : '' ?>>
<i class="ph <?= Visibility::icon($level) ?>" aria-hidden="true"></i>
<span class="wk-vispick-t"><b><?= $ve(Visibility::label($level)) ?><?php if ($level === $visNow): ?> <small class="wk-vispick-now"><?= $ve(t('vis.now')) ?></small><?php endif; ?></b><small><?= $ve(Visibility::explain($level)) ?></small></span>
</label>
<?php endforeach; ?>
</div>
<?php if ($visNow !== 'public'): ?>
<div class="wk-vispick-public">
<?php if ($visAckMissing): ?><p class="wk-vispick-err" role="alert"><?= $ve(t('vis.err_ack')) ?></p><?php endif; ?>
<p><?= $ve(t('vis.confirm_intro')) ?></p>
<div class="wk-kv">
<span><?= $ve(t('vis.shown_path')) ?></span><b class="wk-mono"><?= $ve($visPreview['path']) ?></b>
<span><?= $ve(t('vis.shown_title')) ?></span><b><?= $ve($visPreview['title']) ?></b>
<span><?= $ve(t('vis.shown_body')) ?></span><b><?= $ve(t('vis.shown_body_all')) ?></b>
<?php if ($visPreview['attachedMedia'] > 0): ?>
<span><?= $ve(t('vis.shown_media')) ?></span><b><?= $ve(t('vis.shown_media_n', [$visPreview['attachedMedia']])) ?></b>
<?php endif; ?>
<?php if ($visPreview['hiddenPatientFields'] !== []): ?>
<span><?= $ve(t('vis.hidden')) ?></span><b class="wk-mono"><?= $ve(implode(', ', $visPreview['hiddenPatientFields'])) ?></b>
<?php endif; ?>
</div>
<?php if ($visPreview['pathLooksPersonal']): ?>
<div class="wk-notice" role="alert"><i class="ph ph-warning"></i><div><?= $ve(t('vis.personal_path')) ?></div></div>
<?php endif; ?>
<label class="radio"><input type="checkbox" name="acknowledge" value="1"><span class="dot"></span><?= $ve(t('vis.acknowledge')) ?></label>
</div>
<?php endif; ?>
</fieldset>
