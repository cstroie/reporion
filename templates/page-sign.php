<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET/POST /{path}/sign (Controller\SignController) — content only, under
 * the page header. Names the revision being signed and who signs it; lists
 * required fields still empty (with a link to the editor) instead of the
 * button; says plainly that any later edit needs signing again (D3).
 *
 * Variables in scope: string $path, $parafa, $signerName, $signerTitle,
 * $basePath; int $rev; list<string> $missing; bool $stale
 */

declare(strict_types=1);

/** @var string $path */
/** @var int $rev */
/** @var list<string> $missing */
/** @var bool $stale */
/** @var string $parafa */
/** @var string $signerName */
/** @var string $signerTitle */
/** @var string $basePath */

$p = htmlspecialchars($basePath . '/' . $path, ENT_QUOTES);
$label = static function (string $field): string {
    foreach (['meta.' . str_replace('.', '_', $field), 'meta.' . substr((string) strrchr('.' . $field, '.'), 1)] as $key) {
        if (t($key) !== $key) {
            return t($key);
        }
    }

    return $field;
};
?>
<div class="wk-doc" style="max-width:560px">
<h2 class="wk-sec-title"><?= htmlspecialchars(t('sign.title'), ENT_QUOTES) ?></h2>
<?php if ($stale): ?>
<p role="alert"><?= htmlspecialchars(t('sign.stale', [$rev]), ENT_QUOTES) ?></p>
<?php endif; ?>
<p style="font-size:13px"><?= htmlspecialchars(t('sign.explain', [$rev, $signerName . ($signerTitle !== '' ? ' · ' . $signerTitle : '')]), ENT_QUOTES) ?></p>
<?php if ($missing !== []): ?>
<div role="alert">
<p style="font-size:13px"><?= htmlspecialchars(t('sign.missing'), ENT_QUOTES) ?></p>
<ul>
<?php foreach ($missing as $field): ?>
<li><?= htmlspecialchars($label($field), ENT_QUOTES) ?> <span class="wk-mono wk-dim"><?= htmlspecialchars($field, ENT_QUOTES) ?></span></li>
<?php endforeach; ?>
</ul>
</div>
<div class="wk-actions">
<a class="btn btn-primary" href="<?= $p ?>/edit"><i class="ph ph-pencil-simple"></i><?= htmlspecialchars(t('sign.edit'), ENT_QUOTES) ?></a>
<a class="btn btn-ghost" href="<?= $p ?>"><?= htmlspecialchars(t('editor.cancel'), ENT_QUOTES) ?></a>
</div>
<?php else: ?>
<p style="font-size:13px"><?= htmlspecialchars(t('sign.after'), ENT_QUOTES) ?></p>
<form class="wk-form" action="<?= $p ?>/sign" method="post">
<input type="hidden" name="base_rev" value="<?= $rev ?>">
<div class="field"><label for="parafa"><?= htmlspecialchars(t('sign.parafa'), ENT_QUOTES) ?></label>
<input class="input wk-mono" type="text" id="parafa" name="parafa" value="<?= htmlspecialchars($parafa, ENT_QUOTES) ?>" maxlength="32" autocomplete="off"></div>
<div class="wk-actions">
<button class="btn btn-primary" type="submit"><i class="ph ph-seal-check"></i><?= htmlspecialchars(t('sign.submit', [$rev]), ENT_QUOTES) ?></button>
<a class="btn btn-ghost" href="<?= $p ?>"><?= htmlspecialchars(t('editor.cancel'), ENT_QUOTES) ?></a>
</div>
</form>
<?php endif; ?>
</div>
