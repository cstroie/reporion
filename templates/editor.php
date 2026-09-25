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
 * The formatting toolbar (.wk-tbar) and AI rail (.wk-ai) are present in the markup
 * but non-functional until their backends land — buttons have no JS handlers
 * yet (docs/BUILD_LOG.md).
 *
 * Chrome: templates/rail.php + templates/tabs.php (Workbench chrome).
 *
 * Variables in scope (see Controller\EditorController):
 * string $path, $document, $basePath; int $baseRev; ?string $error, $conflictDocument
 * ?Reporion\Auth\User $principal
 */

declare(strict_types=1);

/** @var string $path */
/** @var int $baseRev */
/** @var ?string $error */
/** @var string $document */
/** @var ?string $conflictDocument */
/** @var string $basePath */
/** @var bool $isOwner */
/** @var bool $canWrite */
/** @var bool $canCreate */
/** @var ?string $railEditHref */
/** @var string $railActive */
/** @var string $tabActive */
/** @var string $worklistNs */
/** @var list<array<string, mixed>> $worklistRows */
/** @var string $theme */
/** @var string $themeBodyClass */
/** @var string $currentUrl */
/** @var int $statusTotal */
/** @var int $statusDraft */
/** @var ?\Reporion\Auth\User $principal */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(t('page.edit'), ENT_QUOTES) ?> — <?= htmlspecialchars($path, ENT_QUOTES) ?> — <?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?></title>
<link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/css/tokens.css">
<link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/css/wiki.css">
<link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/css/fontawesome.css">
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
</head>
<body class="wk wk-shell<?= htmlspecialchars($themeBodyClass, ENT_QUOTES) ?>">
<div class="wk-body wk-body-worklist">
<?php include __DIR__ . '/rail.php'; ?>
<?php include __DIR__ . '/worklist.php'; ?>
<div class="wk-col">
<div class="wk-top">
<span class="wk-brand"><?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?></span>
<form class="wk-search" action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/search" method="get" data-island="palette" data-config-id="palette-config">
<input type="search" name="q" placeholder="<?= htmlspecialchars(t('nav.search'), ENT_QUOTES) ?>">
</form>
<script type="application/json" id="palette-config"><?= json_encode(['basePath' => $basePath], JSON_HEX_TAG) ?></script>
<span class="wk-editor-status wk-mono" id="editor-status" aria-live="polite"></span>
</div>
<?php include __DIR__ . '/tabs.php'; ?>
<main class="wk-pad">
<div id="editor-draft-banner" class="wk-panel" hidden>
<div class="wk-panel-h"><span class="wk-eyebrow"><?= htmlspecialchars(t('editor.draft_restored'), ENT_QUOTES) ?></span>
<button type="button" class="btn btn-ghost" id="editor-draft-dismiss"><?= htmlspecialchars(t('editor.draft_dismiss'), ENT_QUOTES) ?></button></div>
<p><?= htmlspecialchars(t('editor.draft_restored'), ENT_QUOTES) ?></p>
</div>
<div class="wk-edit">
<div class="wk-edit-main">
<div class="wk-crumbs wk-mono">
<i class="ph ph-pencil-simple"></i><span><?= htmlspecialchars(t('editor.editing'), ENT_QUOTES) ?></span>
<b><?= htmlspecialchars($path, ENT_QUOTES) ?></b>
<span class="tag tag-neutral"><?= htmlspecialchars(t('editor.rev', [$baseRev]), ENT_QUOTES) ?> → <?= $baseRev + 1 ?></span>
</div>

<?php if ($error !== null): ?>
<p role="alert"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
<?php endif; ?>

<?php if ($conflictDocument !== null): ?>
<div class="wk-panel" id="editor-conflict">
<div class="wk-panel-h"><span class="wk-eyebrow"><?= htmlspecialchars(t('editor.conflict_current'), ENT_QUOTES) ?></span></div>
<pre class="wk-mono wk-difftext"><?= htmlspecialchars($conflictDocument, ENT_QUOTES) ?></pre>
</div>
<?php endif; ?>

<div class="wk-tbar">
<button class="wk-tbtn" title="Heading"><i class="ph ph-text-h"></i></button>
<button class="wk-tbtn" title="Bold"><i class="ph ph-text-b"></i></button>
<button class="wk-tbtn" title="Italic"><i class="ph ph-text-italic"></i></button>
<span class="wk-tsep"></span>
<button class="wk-tbtn" title="Bullet list"><i class="ph ph-list-bullets"></i></button>
<button class="wk-tbtn" title="Numbered list"><i class="ph ph-list-numbers"></i></button>
<button class="wk-tbtn" title="Table"><i class="ph ph-table"></i></button>
<button class="wk-tbtn" title="Code"><i class="ph ph-code"></i></button>
<span class="wk-tsep"></span>
<button class="wk-tbtn" title="Internal link"><i class="ph ph-link-simple"></i></button>
<button class="wk-tbtn" title="Attach image / key slice"><i class="ph ph-image-square"></i></button>
<button class="wk-tbtn" title="Measurement macro"><i class="ph ph-ruler"></i></button>
<button class="wk-tbtn" title="Insert prior study"><i class="ph ph-clock-clockwise"></i></button>
<span class="wk-tsep"></span>
<button class="wk-tbtn" title="Insert template"><i class="ph ph-cards"></i></button>
<button class="wk-tbtn" title="Snippets (dictation macros)"><i class="ph ph-lightning"></i></button>
<span class="wk-tflex"></span>
<span class="wk-mono wk-dim">markdown · <?= strlen($document) ?> chars</span>
<button class="wk-tbtn" title="Split preview" id="editor-preview-toggle-tb"><i class="ph ph-columns"></i></button>
</div>

<form action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($path, ENT_QUOTES) ?>/edit" method="post" data-island="editor" data-config-id="editor-config" style="display:flex; flex-direction:column; flex:1; gap:var(--space-3); min-height:0;">
<input type="hidden" name="base_rev" value="<?= $baseRev ?>">
<textarea class="wk-ta wk-mono" name="document" spellcheck="false"><?= htmlspecialchars($document, ENT_QUOTES) ?></textarea>
<div class="wk-preview" id="editor-preview" hidden></div>
<div class="field">
<label for="note"><?= htmlspecialchars(t('editor.note'), ENT_QUOTES) ?></label>
<input class="input" type="text" id="note" name="note" autocomplete="off">
</div>
<div class="wk-savebar">
<label class="radio"><input type="checkbox" name="minor"><span class="dot"></span><?= htmlspecialchars(t('editor.minor'), ENT_QUOTES) ?></label>
<label class="radio"><input type="checkbox" name="sign"><span class="dot"></span><?= htmlspecialchars(t('editor.sign_on_save'), ENT_QUOTES) ?></label>
<span class="wk-tflex"></span>
<a class="btn btn-ghost" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($path, ENT_QUOTES) ?>"><?= htmlspecialchars(t('editor.cancel'), ENT_QUOTES) ?></a>
<button class="btn btn-secondary" type="button" id="editor-preview-toggle"><?= htmlspecialchars(t('editor.preview'), ENT_QUOTES) ?></button>
<button class="btn btn-primary" type="submit"><i class="ph ph-check"></i><?= htmlspecialchars(t('editor.save', [$baseRev + 1]), ENT_QUOTES) ?></button>
</div>
</form>
</div>
<aside class="wk-ai">
<div class="wk-rail-head"><span class="wk-eyebrow"><i class="ph ph-sparkle"></i> <?= htmlspecialchars(t('editor.assistant'), ENT_QUOTES) ?></span><span class="wk-mono wk-dim"><?= htmlspecialchars(t('editor.ai_model'), ENT_QUOTES) ?></span></div>
<div class="wk-ai-acts">
<button class="wk-ai-btn"><i class="ph ph-text-align-left"></i><?= htmlspecialchars(t('editor.ai_summarize'), ENT_QUOTES) ?></button>
<button class="wk-ai-btn"><i class="ph ph-list-checks"></i><?= htmlspecialchars(t('editor.ai_extract'), ENT_QUOTES) ?></button>
<button class="wk-ai-btn"><i class="ph ph-scales"></i><?= htmlspecialchars(t('editor.ai_compare'), ENT_QUOTES) ?></button>
<button class="wk-ai-btn"><i class="ph ph-magnifying-glass"></i><?= htmlspecialchars(t('editor.ai_consistency'), ENT_QUOTES) ?></button>
<button class="wk-ai-btn"><i class="ph ph-translate"></i><?= htmlspecialchars(t('editor.ai_translate'), ENT_QUOTES) ?></button>
<button class="wk-ai-btn"><i class="ph ph-tag"></i><?= htmlspecialchars(t('editor.ai_tags'), ENT_QUOTES) ?></button>
</div>
<div class="wk-ai-out">
<div class="wk-ai-out-h"><span class="wk-eyebrow"><?= htmlspecialchars(t('editor.ai_summary'), ENT_QUOTES) ?></span><span class="wk-mono wk-dim">1.9 s · 412 tok</span></div>
<p><?= htmlspecialchars(t('editor.ai_summary_placeholder'), ENT_QUOTES) ?></p>
<div class="wk-ai-row"><button class="btn btn-primary btn-sm"><i class="ph ph-arrow-line-down"></i><?= htmlspecialchars(t('editor.ai_insert'), ENT_QUOTES) ?></button><button class="btn btn-secondary btn-sm"><?= htmlspecialchars(t('editor.ai_regenerate'), ENT_QUOTES) ?></button><button class="wk-tbtn" title="<?= htmlspecialchars(t('editor.ai_copy'), ENT_QUOTES) ?>"><i class="ph ph-copy"></i></button></div>
</div>
<div class="wk-ai-ctx"><span class="wk-eyebrow"><?= htmlspecialchars(t('editor.ai_context'), ENT_QUOTES) ?></span><div class="wk-links"><span class="wk-chip"><?= htmlspecialchars(t('editor.ai_this_page'), ENT_QUOTES) ?></span><span class="wk-chip"><?= htmlspecialchars(t('editor.ai_priors'), ENT_QUOTES) ?></span><span class="wk-chip"><?= htmlspecialchars(t('editor.ai_protocol'), ENT_QUOTES) ?></span><span class="wk-chip wk-chip-off"><?= htmlspecialchars(t('editor.ai_no_patient'), ENT_QUOTES) ?></span></div><p class="wk-mono wk-dim">plugin: ai-assistant 0.6 · provider: ollama (on-prem) · <?= htmlspecialchars(t('editor.ai_audit'), ENT_QUOTES) ?></p></div>
</aside>
</div>
</main>
</div>
</div>
<?php include __DIR__ . '/status.php'; ?>
<script type="application/json" id="editor-config"><?= json_encode([
    'basePath' => $basePath,
    'path' => $path,
    'baseRev' => $baseRev,
    'strings' => [
        'saved' => t('editor.saved'),
        'saving' => t('editor.saving'),
        'draft' => t('editor.draft'),
        'draftRestored' => t('editor.draft_restored'),
        'draftDismiss' => t('editor.draft_dismiss'),
        'offline' => t('editor.offline'),
        'conflictTitle' => t('err.409.title'),
        'conflictBody' => t('err.409.body'),
        'autosaved' => t('editor.autosaved'),
    ],
], JSON_HEX_TAG) ?></script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/js/palette.js" defer></script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/js/editor.js" defer></script>
<script>
(function() {
  var toggle = document.getElementById('editor-preview-toggle');
  var toggleTb = document.getElementById('editor-preview-toggle-tb');
  var preview = document.getElementById('editor-preview');
  if (!toggle || !preview) return;
  function show() {
    var doc = document.querySelector('[name="document"]').value;
    preview.innerHTML = marked.parse(doc);
    preview.hidden = false;
  }
  function hide() { preview.hidden = true; }
  toggle.addEventListener('click', function() {
    preview.hidden ? show() : hide();
  });
  if (toggleTb) toggleTb.addEventListener('click', function() {
    preview.hidden ? show() : hide();
  });
})();
</script>
</body>
</html>
