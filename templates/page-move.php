<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET/POST /{path}/move (Controller\PageController::moveForm()/move()) —
 * content only, under the page header. Says plainly what a move does:
 * the old path keeps redirecting, links in unsigned pages are updated,
 * signed reports are left as they are.
 *
 * Variables in scope: string $path, $to, $basePath; ?string $error
 */

declare(strict_types=1);

/** @var string $path */
/** @var string $to */
/** @var ?string $error */
/** @var string $basePath */
?>
<div class="wk-doc" style="max-width:560px">
<h2 class="wk-sec-title"><?= htmlspecialchars(t('move.title'), ENT_QUOTES) ?></h2>
<p style="font-size:13px"><?= htmlspecialchars(t('move.explain'), ENT_QUOTES) ?></p>
<?php if ($error !== null): ?>
<p role="alert"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
<?php endif; ?>
<form class="wk-form" action="<?= htmlspecialchars($basePath . '/' . $path, ENT_QUOTES) ?>/move" method="post">
<div class="field"><label for="to"><?= htmlspecialchars(t('move.to'), ENT_QUOTES) ?></label>
<input class="input wk-mono" type="text" id="to" name="to" value="<?= htmlspecialchars($to, ENT_QUOTES) ?>" autocomplete="off" required></div>
<div class="wk-actions">
<button class="btn btn-primary" type="submit"><i class="ph ph-arrow-elbow-down-right"></i><?= htmlspecialchars(t('move.submit'), ENT_QUOTES) ?></button>
<a class="btn btn-ghost" href="<?= htmlspecialchars($basePath . '/' . $path, ENT_QUOTES) ?>"><?= htmlspecialchars(t('editor.cancel'), ENT_QUOTES) ?></a>
</div>
</form>
</div>
