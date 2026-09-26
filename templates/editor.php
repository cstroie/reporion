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
 * Not rendered until they work: the mockup's formatting buttons, the AI rail
 * (D15 — hidden while no provider is configured), and the "minor edit" /
 * "sign on save" options (nothing reads them; signing is its own action).
 * The preview is marked.js (vendored, the version the D17 conformance test
 * runs) configured by assets/js/markdown-preview.js: body only, raw HTML
 * escaped, unsafe URLs dropped — same as Service\Render.
 *
 * Content only: Http\View::page() wraps it in templates/layout.php, whose
 * page header shows the page and its tabs (A6).
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
/** @var bool $canWrite */
/** @var ?\Reporion\Auth\User $principal */
?>
<div id="editor-draft-banner" class="wk-panel" hidden>
<div class="wk-panel-h"><span class="wk-eyebrow"><?= htmlspecialchars(t('editor.draft_restored'), ENT_QUOTES) ?></span>
<button type="button" class="btn btn-ghost" id="editor-draft-dismiss"><?= htmlspecialchars(t('editor.draft_dismiss'), ENT_QUOTES) ?></button></div>
<p><?= htmlspecialchars(t('editor.draft_restored'), ENT_QUOTES) ?></p>
</div>
<div class="wk-edit">
<div class="wk-edit-main">

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
<span class="wk-tflex"></span>
<span class="wk-mono wk-dim">markdown · <?= mb_strlen($document) ?> chars</span>
<button class="wk-tbtn" title="Split preview" id="editor-preview-toggle-tb"><i class="ph ph-columns"></i></button>
</div>

<form action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($path, ENT_QUOTES) ?>/edit" method="post" data-island="editor" data-config-id="editor-config" style="display:flex; flex-direction:column; flex:1; gap:var(--space-3); min-height:0;">
<input type="hidden" name="base_rev" value="<?= $baseRev ?>">
<textarea class="wk-ta wk-mono" name="document" spellcheck="false"><?= htmlspecialchars($document, ENT_QUOTES) ?></textarea>
<div class="wk-preview" id="editor-preview" hidden></div>
<div class="wk-savebar">
<input class="input wk-commit" type="text" id="note" name="note" autocomplete="off" placeholder="<?= htmlspecialchars(t('editor.note'), ENT_QUOTES) ?>">
<span class="wk-tflex"></span>
<a class="btn btn-ghost" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($path, ENT_QUOTES) ?>"><?= htmlspecialchars(t('editor.cancel'), ENT_QUOTES) ?></a>
<button class="btn btn-secondary" type="button" id="editor-preview-toggle"><?= htmlspecialchars(t('editor.preview'), ENT_QUOTES) ?></button>
<button class="btn btn-primary" type="submit"><i class="ph ph-check"></i><?= htmlspecialchars(t('editor.save', [$baseRev + 1]), ENT_QUOTES) ?></button>
</div>
</form>
</div>
</div>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/marked.js" defer></script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/js/markdown-preview.js" defer></script>
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
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/js/editor.js" defer></script>
<script>
(function() {
  var toggle = document.getElementById('editor-preview-toggle');
  var toggleTb = document.getElementById('editor-preview-toggle-tb');
  var preview = document.getElementById('editor-preview');
  if (!toggle || !preview) return;
  var configured = false; // marked.js is deferred: configure on first use
  function show() {
    if (!window.marked || !window.ReporionPreview) return;
    if (!configured) { ReporionPreview.configure(marked, { basePath: <?= json_encode($basePath, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> }); configured = true; }
    var doc = document.querySelector('[name="document"]').value;
    preview.innerHTML = marked.parse(ReporionPreview.body(doc));
    ReporionPreview.sanitize(preview);
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
