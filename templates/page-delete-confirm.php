<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /{path}/delete (Controller\PageController::confirmDelete()) — the
 * confirmation step the kebab menu's Delete item links to. Not in the
 * mockup (WikiPage.dc.html deletes straight from the menu via client-side
 * state with no confirmation) — added because this app has no restore UI
 * at all: the only way back from a delete is data/trash/ on disk until
 * trash:purge runs, unlike revert or deactivate which stay reversible from
 * inside the app. A no-JS <details> menu makes a stray double-click enough
 * to trigger the POST otherwise (see docs/BUILD_LOG.md). Reuses .card, the
 * same primitive the auth screen uses, rather than the mockup's .dialog
 * overlay — .dialog has no captured CSS values in this repo (see
 * assets/css/wiki.css's header note) and is JS-toggled in the mockup;
 * a plain page needs neither.
 *
 * Variables in scope (see Controller\PageController::confirmDelete()):
 * string $path, $title, $basePath; int $trashPurgeDays
 */

declare(strict_types=1);

/** @var string $path */
/** @var string $title */
/** @var int $trashPurgeDays */
/** @var string $basePath */
/** @var ?string $error */
?>
<div class="wk-doc" style="max-width: 480px; margin: 0;">
<div class="card">
<span class="card-kicker"><?= htmlspecialchars(t('page.delete_confirm_title'), ENT_QUOTES) ?></span>
<?php if (($error ?? null) !== null): ?>
<p role="alert" style="margin: 0 0 var(--space-4);"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
<?php else: ?>
<p style="margin: 0 0 var(--space-4);"><?= htmlspecialchars(t('page.delete_confirm_body', [$title, $trashPurgeDays]), ENT_QUOTES) ?></p>
<?php endif; ?>
<div class="wk-actions">
<?php if (($error ?? null) === null): ?>
<form action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($path, ENT_QUOTES) ?>/delete" method="post">
<button type="submit" class="btn btn-danger"><?= htmlspecialchars(t('page.delete_confirm_submit'), ENT_QUOTES) ?></button>
</form>
<?php endif; ?>
<a class="btn btn-secondary" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($path, ENT_QUOTES) ?>"><?= htmlspecialchars(t('editor.cancel'), ENT_QUOTES) ?></a>
</div>
</div>
</div>
