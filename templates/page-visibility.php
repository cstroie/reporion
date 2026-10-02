<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET/POST /{path}/visibility (Controller\VisibilityController) — content
 * only, under the page header: the visibility picker
 * (partials/visibility-picker.php), the same control as the editor's
 * Metadata view. D16: Public opens, in place, what everyone will see and
 * the acknowledgement it needs.
 *
 * Variables in scope: string $path, $current, $chosen, $basePath; int $rev;
 * bool $signed, $confirm (Public posted without the acknowledgement);
 * array $preview (Service\Publishing::preview()); ?string $error
 */

declare(strict_types=1);

/** @var string $path */
/** @var string $current */
/** @var string $chosen */
/** @var int $rev */
/** @var bool $signed */
/** @var bool $confirm */
/** @var array{path: string, title: string, pathLooksPersonal: bool, hiddenPatientFields: list<string>, attachedMedia: int} $preview */
/** @var ?string $error */
/** @var string $basePath */

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$action = $e($basePath . '/' . $path) . '/visibility';
?>
<div class="wk-doc wk-vis-doc">
<h2 class="wk-sec-title"><?= $e(t('vis.title')) ?></h2>

<?php if ($signed): ?>
<p class="wk-vis-current"><?= \Reporion\Support\Visibility::badge($current) ?></p>
<div class="wk-notice" role="status"><i class="ph ph-seal-check"></i><div><?= $e(t('vis.err_signed')) ?>
<a href="<?= $e($basePath) ?>/new?from=<?= $e(rawurlencode($path)) ?>"><?= $e(t('page.duplicate')) ?></a></div></div>
<?php else: ?>

<?php if ($error !== null): ?>
<div class="wk-notice" role="alert"><i class="ph ph-warning"></i><div><?= $e($error) ?></div></div>
<?php endif; ?>

<form class="wk-form" action="<?= $action ?>" method="post">
<input type="hidden" name="base_rev" value="<?= $rev ?>">
<?php
$visName = 'visibility';
$visNow = $current;
$visChosen = $chosen;
$visPreview = $preview;
$visAckMissing = $confirm;
include __DIR__ . '/partials/visibility-picker.php';
?>
<div class="wk-actions wk-actions-end">
<a class="btn btn-secondary" href="<?= $e($basePath . '/' . $path) ?>"><?= $e(t('editor.cancel')) ?></a>
<button class="btn btn-primary" type="submit"><?= $e(t('vis.save')) ?></button>
</div>
</form>
<?php endif; ?>
</div>
