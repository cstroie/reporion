<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET/POST /{path}/edit (Controller\EditorController). Structure/classes
 * ported from design/mockup/WikiEditor.dc.html's single-document textarea
 * (.wk-edit / .wk-edit-main / .wk-ta) — the mockup edits the whole
 * "---\nfrontmatter\n---\n\nbody" block as one text field, not a
 * generated per-field form, and this keeps that exactly (see
 * EditorController's own docblock for why: Storage::save() replaces
 * frontmatter wholesale, so a curated-fields form would silently delete
 * anything it doesn't show).
 *
 * Deliberately NOT ported: the .wk-tbar formatting toolbar (bold/italic/
 * table/insert-template/attach-image/snippets — every one of those needs
 * either JavaScript or a backend that doesn't exist yet: media upload,
 * templates, dictation macros), the AI rail, and the split marked.js
 * preview pane. All separate, independently useful follow-ups — see
 * docs/BUILD_LOG.md. The mockup's `.wk-edit` is a two-column grid (editor
 * + 328px AI rail); simplified to one column here since there is no rail.
 *
 * Variables in scope (see Controller\EditorController):
 * string $path, $document, $basePath; int $baseRev; ?string $error, $conflictDocument
 */

declare(strict_types=1);

/** @var string $path */
/** @var int $baseRev */
/** @var ?string $error */
/** @var string $document */
/** @var ?string $conflictDocument */
/** @var string $basePath */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(t('page.edit'), ENT_QUOTES) ?> — <?= htmlspecialchars($path, ENT_QUOTES) ?> — <?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?></title>
<link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/css/tokens.css">
<link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/css/wiki.css">
</head>
<body class="wk">
<div class="wk-top">
<span class="wk-brand"><?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?></span>
<form class="wk-search" action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/search" method="get">
<input type="search" name="q" placeholder="<?= htmlspecialchars(t('nav.search'), ENT_QUOTES) ?>">
</form>
</div>
<main class="wk-pad">
<div class="wk-edit">
<div class="wk-edit-main">
<div class="wk-crumbs wk-mono">
<span><?= htmlspecialchars(t('editor.editing'), ENT_QUOTES) ?></span>
<b><?= htmlspecialchars($path, ENT_QUOTES) ?></b>
<span class="tag tag-neutral"><?= htmlspecialchars(t('editor.rev', [$baseRev]), ENT_QUOTES) ?></span>
</div>

<?php if ($error !== null): ?>
<p role="alert"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
<?php endif; ?>

<?php if ($conflictDocument !== null): ?>
<div class="wk-panel">
<div class="wk-panel-h"><span class="wk-eyebrow"><?= htmlspecialchars(t('editor.conflict_current'), ENT_QUOTES) ?></span></div>
<pre class="wk-mono wk-difftext"><?= htmlspecialchars($conflictDocument, ENT_QUOTES) ?></pre>
</div>
<?php endif; ?>

<form action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($path, ENT_QUOTES) ?>/edit" method="post" style="display:flex; flex-direction:column; flex:1; gap:var(--space-3); min-height:0;">
<input type="hidden" name="base_rev" value="<?= $baseRev ?>">
<textarea class="wk-ta wk-mono" name="document" spellcheck="false"><?= htmlspecialchars($document, ENT_QUOTES) ?></textarea>
<div class="field">
<label for="note"><?= htmlspecialchars(t('editor.note'), ENT_QUOTES) ?></label>
<input class="input" type="text" id="note" name="note" autocomplete="off">
</div>
<div class="wk-actions">
<button class="btn btn-primary" type="submit"><?= htmlspecialchars(t('editor.save', [$baseRev + 1]), ENT_QUOTES) ?></button>
<a class="btn btn-secondary" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($path, ENT_QUOTES) ?>"><?= htmlspecialchars(t('editor.cancel'), ENT_QUOTES) ?></a>
</div>
</form>
</div>
</div>
</main>
</body>
</html>
