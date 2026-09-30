<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET/POST /{path}/move (Controller\PageController::moveForm()/move()) —
 * content only, under the page header. Says plainly what a move does:
 * the old path keeps redirecting, links in unsigned pages are updated,
 * signed reports are left as they are.
 *
 * `?rename=1` (the page header's "Rename", TODO 13) is the same route and
 * form, stricter: $to holds only the last path segment, $nsPrefix (never
 * user input — Controller\PageController::nsPrefix()) is shown read-only
 * beside it, and the namespace can never change, even from a hand-built
 * POST (the controller rebuilds `to` server-side from $path, not from
 * whatever the request sent).
 *
 * Variables in scope: string $path, $to, $basePath, $nsPrefix; ?string $error; bool $rename
 */

declare(strict_types=1);

/** @var string $path */
/** @var string $to */
/** @var ?string $error */
/** @var string $basePath */
/** @var bool $rename */
/** @var string $nsPrefix */
?>
<div class="wk-doc" style="max-width:720px">
<h2 class="wk-sec-title"><?= htmlspecialchars(t($rename ? 'rename.title' : 'move.title'), ENT_QUOTES) ?></h2>
<p class="wk-text-sm"><?= htmlspecialchars(t($rename ? 'rename.explain' : 'move.explain'), ENT_QUOTES) ?></p>
<?php if ($error !== null): ?>
<p role="alert"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
<?php endif; ?>
<form class="wk-form" action="<?= htmlspecialchars($basePath . '/' . $path, ENT_QUOTES) ?>/move<?= $rename ? '?rename=1' : '' ?>" method="post">
<?php if ($rename): ?>
<div class="field"><label for="name"><?= htmlspecialchars(t('rename.name'), ENT_QUOTES) ?></label>
<div class="wk-rename-row"><span class="wk-mono wk-dim"><?= htmlspecialchars($nsPrefix, ENT_QUOTES) ?></span><input class="input wk-mono" type="text" id="name" name="name" value="<?= htmlspecialchars($to, ENT_QUOTES) ?>" autocomplete="off" required></div></div>
<?php else: ?>
<div class="field"><label for="to"><?= htmlspecialchars(t('move.to'), ENT_QUOTES) ?></label>
<input class="input wk-mono" type="text" id="to" name="to" value="<?= htmlspecialchars($to, ENT_QUOTES) ?>" autocomplete="off" required></div>
<?php endif; ?>
<div class="wk-actions">
<button class="btn btn-primary" type="submit"><i class="ph ph-arrow-elbow-down-right"></i><?= htmlspecialchars(t($rename ? 'rename.submit' : 'move.submit'), ENT_QUOTES) ?></button>
<a class="btn btn-secondary" href="<?= htmlspecialchars($basePath . '/' . $path, ENT_QUOTES) ?>"><?= htmlspecialchars(t('editor.cancel'), ENT_QUOTES) ?></a>
</div>
</form>
</div>
