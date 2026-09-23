<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET/POST /new (Controller\NewPageController) — the create half of the
 * write UI; templates/editor.php is the edit half. Same .wk-edit/.wk-ta
 * shell, same reasoning: the whole raw document in one textarea, not a
 * generated per-field form. See NewPageController's own docblock for why
 * there is no segmented reports:{modality}:{site}:{yymmdd}-{name} path
 * builder from design/mockup/WikiCreate.dc.html — a plain text field
 * unblocks creation from the browser; the segmented builder is separate,
 * later work.
 *
 * Variables in scope: ?string $error; string $path, $document, $basePath
 */

declare(strict_types=1);

/** @var ?string $error */
/** @var string $path */
/** @var string $document */
/** @var string $basePath */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(t('new.title'), ENT_QUOTES) ?> — <?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?></title>
<link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/css/tokens.css">
<link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/css/wiki.css">
</head>
<body class="wk">
<div class="wk-top">
<span class="wk-brand"><?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?></span>
<form class="wk-search" action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/search" method="get" data-island="palette" data-config-id="palette-config">
<input type="search" name="q" placeholder="<?= htmlspecialchars(t('nav.search'), ENT_QUOTES) ?>">
</form>
<script type="application/json" id="palette-config"><?= json_encode(['basePath' => $basePath], JSON_HEX_TAG) ?></script>
</div>
<main class="wk-pad">
<div class="wk-edit">
<div class="wk-edit-main">
<div class="wk-crumbs wk-mono">
<span><?= htmlspecialchars(t('new.title'), ENT_QUOTES) ?></span>
</div>

<?php if ($error !== null): ?>
<p role="alert"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
<?php endif; ?>

<form action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/new" method="post" style="display:flex; flex-direction:column; flex:1; gap:var(--space-3); min-height:0;">
<div class="field">
<label for="path"><?= htmlspecialchars(t('new.path'), ENT_QUOTES) ?></label>
<input class="input wk-mono" type="text" id="path" name="path" value="<?= htmlspecialchars($path, ENT_QUOTES) ?>" placeholder="reports:mri:mioveni:260922-name" autocomplete="off" required autofocus>
</div>
<textarea class="wk-ta wk-mono" name="document" spellcheck="false"><?= htmlspecialchars($document, ENT_QUOTES) ?></textarea>
<div class="wk-actions">
<button class="btn btn-primary" type="submit"><?= htmlspecialchars(t('new.create'), ENT_QUOTES) ?></button>
<a class="btn btn-secondary" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/"><?= htmlspecialchars(t('editor.cancel'), ENT_QUOTES) ?></a>
</div>
</form>
</div>
</div>
</main>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/js/palette.js" defer></script>
</body>
</html>
