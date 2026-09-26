<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET/POST /{path}/visibility (Controller\VisibilityController) — content
 * only, under the page header. D16: before a page becomes public this says
 * exactly what everyone will see and needs an explicit acknowledgement.
 *
 * Variables in scope: string $path, $current, $chosen, $basePath; int $rev;
 * bool $signed, $confirm; array $preview (Service\Publishing::preview()); ?string $error
 */

declare(strict_types=1);

/** @var string $path */
/** @var string $current */
/** @var string $chosen */
/** @var int $rev */
/** @var bool $signed */
/** @var bool $confirm */
/** @var array{path: string, title: string, pathLooksPersonal: bool, hiddenPatientFields: list<string>} $preview */
/** @var ?string $error */
/** @var string $basePath */

$action = htmlspecialchars($basePath . '/' . $path, ENT_QUOTES) . '/visibility';
?>
<div class="wk-doc" style="max-width:600px">
<h2 class="wk-sec-title"><?= htmlspecialchars(t('vis.title'), ENT_QUOTES) ?></h2>

<?php if ($signed): ?>
<div class="wk-notice" role="status"><i class="ph ph-seal-check"></i><div><?= htmlspecialchars(t('vis.err_signed'), ENT_QUOTES) ?>
<a href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/new?from=<?= htmlspecialchars(rawurlencode($path), ENT_QUOTES) ?>"><?= htmlspecialchars(t('page.duplicate'), ENT_QUOTES) ?></a></div></div>
<?php else: ?>

<?php if ($error !== null): ?>
<p role="alert"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
<?php endif; ?>

<?php if ($confirm): ?>
<div class="wk-panel">
<div class="wk-panel-h"><span class="wk-eyebrow"><?= htmlspecialchars(t('vis.confirm_title'), ENT_QUOTES) ?></span></div>
<p style="font-size:13px;margin:0 0 var(--space-3)"><?= htmlspecialchars(t('vis.confirm_intro'), ENT_QUOTES) ?></p>
<div class="wk-kv">
<span><?= htmlspecialchars(t('vis.shown_path'), ENT_QUOTES) ?></span><b class="wk-mono"><?= htmlspecialchars($preview['path'], ENT_QUOTES) ?></b>
<span><?= htmlspecialchars(t('vis.shown_title'), ENT_QUOTES) ?></span><b><?= htmlspecialchars($preview['title'], ENT_QUOTES) ?></b>
<span><?= htmlspecialchars(t('vis.shown_body'), ENT_QUOTES) ?></span><b><?= htmlspecialchars(t('vis.shown_body_all'), ENT_QUOTES) ?></b>
<?php if ($preview['hiddenPatientFields'] !== []): ?>
<span><?= htmlspecialchars(t('vis.hidden'), ENT_QUOTES) ?></span><b class="wk-mono"><?= htmlspecialchars(implode(', ', $preview['hiddenPatientFields']), ENT_QUOTES) ?></b>
<?php endif; ?>
</div>
<?php if ($preview['pathLooksPersonal']): ?>
<div class="wk-notice" role="alert" style="margin-top:var(--space-3)"><i class="ph ph-warning"></i><div><?= htmlspecialchars(t('vis.personal_path'), ENT_QUOTES) ?></div></div>
<?php endif; ?>
<form class="wk-form" action="<?= $action ?>" method="post" style="margin-top:var(--space-4)">
<input type="hidden" name="visibility" value="public">
<input type="hidden" name="base_rev" value="<?= $rev ?>">
<label class="radio"><input type="checkbox" name="acknowledge" value="1" required><span class="dot"></span><?= htmlspecialchars(t('vis.acknowledge'), ENT_QUOTES) ?></label>
<div class="wk-actions">
<button class="btn btn-primary" type="submit"><i class="ph ph-globe"></i><?= htmlspecialchars(t('vis.publish'), ENT_QUOTES) ?></button>
<a class="btn btn-ghost" href="<?= htmlspecialchars($basePath . '/' . $path, ENT_QUOTES) ?>"><?= htmlspecialchars(t('editor.cancel'), ENT_QUOTES) ?></a>
</div>
</form>
</div>
<?php else: ?>
<form class="wk-form" action="<?= $action ?>" method="post">
<input type="hidden" name="base_rev" value="<?= $rev ?>">
<?php foreach (['private', 'unlisted', 'public'] as $option): ?>
<label class="radio"><input type="radio" name="visibility" value="<?= $option ?>"<?= $option === $chosen ? ' checked' : '' ?>><span class="dot"></span><b><?= htmlspecialchars($option, ENT_QUOTES) ?></b> — <?= htmlspecialchars(t('vis.explain_' . $option), ENT_QUOTES) ?></label>
<?php endforeach; ?>
<div class="wk-actions">
<button class="btn btn-primary" type="submit"><?= htmlspecialchars(t('vis.save'), ENT_QUOTES) ?></button>
<a class="btn btn-ghost" href="<?= htmlspecialchars($basePath . '/' . $path, ENT_QUOTES) ?>"><?= htmlspecialchars(t('editor.cancel'), ENT_QUOTES) ?></a>
</div>
</form>
<?php endif; ?>
<?php endif; ?>
</div>
