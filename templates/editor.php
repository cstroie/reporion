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
 * The formatting toolbar (phase 10) is assets/js/editor.js over the pure
 * transforms in assets/js/editor-format.js; Insert prior study and Insert
 * template show only when there is something to insert. Left out: the
 * measurement macro (D18). Snippets (phase 11, D24): `;name` + space,
 * Enter or Tab expands one; the lightning button picks one.
 *
 * Not rendered until they work: the AI rail
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
/** @var list<array{path: string, label: string, date: string, modality: string}> $priorCandidates */
/** @var list<array{path: string, title: string}> $templates */
/** @var string $template */
/** @var list<array{name: string, title: string, body: string, modality: bool}> $snippets */
/** @var string $basePath */
/** @var bool $canWrite */
/** @var ?\Reporion\Auth\User $principal */
/** @var ?array{actions: list<array{id: string, label: string, tooltip: string, icon: string, result: string, custom: bool}>, provider: string, external: bool} $ai */
$ai ??= null;
?>
<div id="editor-draft-banner" class="wk-notice" role="status" hidden><i class="ph ph-clock-counter-clockwise"></i><div>
<?= htmlspecialchars(t('editor.draft_found'), ENT_QUOTES) ?> <span class="wk-mono" id="editor-draft-when"></span>
<span style="display:inline-flex;gap:var(--space-2);margin-left:var(--space-2)"><button type="button" class="btn btn-secondary btn-sm" id="editor-draft-restore"><?= htmlspecialchars(t('editor.draft_restore'), ENT_QUOTES) ?></button><button type="button" class="btn btn-ghost btn-sm" id="editor-draft-dismiss"><?= htmlspecialchars(t('editor.draft_dismiss'), ENT_QUOTES) ?></button></span>
</div></div>
<div class="wk-edit<?= $ai !== null ? ' wk-has-ai' : '' ?>">
<div class="wk-edit-main">
<?php if ($newPage ?? false): ?>
<?php /* A page not written yet: the first Save creates it, as revision 1 */ ?>
<div class="wk-doc-titlerow"><h1 class="wk-doc-title"><?= htmlspecialchars(t('editor.new_heading'), ENT_QUOTES) ?></h1></div>
<p class="wk-dim" style="margin:0"><span class="wk-mono"><?= htmlspecialchars($path, ENT_QUOTES) ?></span> · <?= htmlspecialchars(t('editor.new_note'), ENT_QUOTES) ?></p>
<?php endif; ?>

<?php if ($error !== null): ?>
<p role="alert"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
<?php endif; ?>

<?php if ($conflictDocument !== null): ?>
<div class="wk-panel" id="editor-conflict">
<div class="wk-panel-h"><span class="wk-eyebrow"><?= htmlspecialchars(t('editor.conflict_current'), ENT_QUOTES) ?></span></div>
<pre class="wk-mono wk-difftext"><?= htmlspecialchars($conflictDocument, ENT_QUOTES) ?></pre>
</div>
<?php endif; ?>

<?php
$tb = static fn (string $action, string $icon, string $key, bool $show = true): string => $show
    ? '<button type="button" class="wk-tbtn" data-tb="' . $action . '" title="' . htmlspecialchars(t($key), ENT_QUOTES) . '" aria-label="' . htmlspecialchars(t($key), ENT_QUOTES) . '"><i class="ph ph-' . $icon . '"></i></button>' . "\n"
    : '';
?>
<div class="wk-tbar" id="editor-toolbar">
<?= $tb('heading', 'text-h', 'editor.tb.heading') ?>
<?= $tb('bold', 'text-b', 'editor.tb.bold') ?>
<?= $tb('italic', 'text-italic', 'editor.tb.italic') ?>
<span class="wk-tsep"></span>
<?= $tb('bullets', 'list-bullets', 'editor.tb.bullets') ?>
<?= $tb('numbers', 'list-numbers', 'editor.tb.numbers') ?>
<?= $tb('table', 'table', 'editor.tb.table') ?>
<?= $tb('code', 'code', 'editor.tb.code') ?>
<span class="wk-tsep"></span>
<?= $tb('link', 'link-simple', 'editor.tb.link') ?>
<?= $tb('image', 'image-square', 'editor.tb.image') ?>
<?= $tb('prior', 'clock-clockwise', 'editor.tb.prior', $priorCandidates !== []) ?>
<?= $tb('template', 'cards', 'editor.tb.template', $templates !== []) ?>
<?= $tb('snippets', 'lightning', 'editor.tb.snippets', $snippets !== []) ?>
<span class="wk-tflex"></span>
<span class="wk-mono wk-dim" id="editor-chars"><?= htmlspecialchars(t('editor.tb.chars', [mb_strlen($document)]), ENT_QUOTES) ?></span>
<?= $tb('copy', 'copy', 'editor.tb.copy') ?>
<button type="button" class="wk-tbtn" title="<?= htmlspecialchars(t('editor.tb.split'), ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('editor.tb.split'), ENT_QUOTES) ?>" id="editor-preview-toggle-tb"><i class="ph ph-columns"></i></button>
<input type="file" id="editor-image-file" accept="image/png,image/jpeg,image/gif,image/webp" multiple hidden>
</div>

<form action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($path, ENT_QUOTES) ?>/edit" method="post" data-island="editor" data-config-id="editor-config" style="display:flex; flex-direction:column; flex:1; gap:var(--space-3); min-height:0;">
<input type="hidden" name="base_rev" value="<?= $baseRev ?>">
<?php /* A report's exam tabs (phase 12, assets/js/editor-exams.js): filled by the script, absent without it */ ?>
<div class="wk-examtabs" id="editor-exams" role="toolbar" aria-label="<?= htmlspecialchars(t('editor.exams'), ENT_QUOTES) ?>" hidden></div>
<textarea class="wk-ta wk-mono" name="document" spellcheck="false"><?= htmlspecialchars($document, ENT_QUOTES) ?></textarea>
<div class="wk-preview" id="editor-preview" hidden></div>
<div class="wk-savebar">
<input class="input wk-commit" type="text" id="note" name="note" autocomplete="off" placeholder="<?= htmlspecialchars(t('editor.note'), ENT_QUOTES) ?>">
<span class="wk-tflex"></span>
<a class="btn btn-ghost" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($path, ENT_QUOTES) ?>"><?= htmlspecialchars(t('editor.cancel'), ENT_QUOTES) ?></a>
<button class="btn btn-secondary" type="button" id="editor-preview-toggle"><?= htmlspecialchars(t('editor.preview'), ENT_QUOTES) ?></button>
<button class="btn btn-primary" type="submit"><i class="ph ph-check"></i><?= htmlspecialchars(t('editor.save', [$baseRev + 1]), ENT_QUOTES) ?></button>
</div>
<?php if ($ai !== null): ?><input type="hidden" name="ai_assisted" id="editor-ai-assisted" value=""><?php endif; ?>
</form>
</div>
<?php if ($ai !== null): ?>
<?php /* The Assistant rail (phase 15d, design/mockup/WikiEditor.dc.html .wk-ai): it proposes, the doctor applies (A3, D8) */ ?>
<aside class="wk-ai" id="editor-ai" aria-label="<?= htmlspecialchars(t('editor.ai.title'), ENT_QUOTES) ?>">
<div class="wk-rail-head"><span class="wk-eyebrow"><i class="ph ph-sparkle"></i> <?= htmlspecialchars(t('editor.ai.title'), ENT_QUOTES) ?></span><span class="wk-mono wk-dim"><?= htmlspecialchars($ai['provider'], ENT_QUOTES) ?></span></div>
<div class="wk-ai-acts">
<?php foreach ($ai['actions'] as $action): ?>
<?php if ($action['custom']): ?>
<div class="wk-ai-custom"><input class="input" type="text" data-ai-prompt="<?= htmlspecialchars($action['id'], ENT_QUOTES) ?>" placeholder="<?= htmlspecialchars($action['tooltip'] !== '' ? $action['tooltip'] : $action['label'], ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars($action['label'], ENT_QUOTES) ?>"><button type="button" class="btn btn-secondary btn-sm" data-ai-action="<?= htmlspecialchars($action['id'], ENT_QUOTES) ?>"><?= htmlspecialchars($action['label'], ENT_QUOTES) ?></button></div>
<?php else: ?>
<button type="button" class="wk-ai-btn" data-ai-action="<?= htmlspecialchars($action['id'], ENT_QUOTES) ?>" title="<?= htmlspecialchars($action['tooltip'], ENT_QUOTES) ?>"><?php if (str_starts_with($action['icon'], 'ph-')): ?><i class="ph <?= htmlspecialchars($action['icon'], ENT_QUOTES) ?>"></i><?php else: ?><span class="wk-ai-emoji" aria-hidden="true"><?= htmlspecialchars($action['icon'] !== '' ? $action['icon'] : '✦', ENT_QUOTES) ?></span><?php endif; ?><?= htmlspecialchars($action['label'], ENT_QUOTES) ?></button>
<?php endif; ?>
<?php endforeach; ?>
</div>
<div class="wk-ai-outs" id="editor-ai-outs"></div>
<div class="wk-ai-ctx"><span class="wk-eyebrow"><?= htmlspecialchars(t('editor.ai.context'), ENT_QUOTES) ?></span><div class="wk-links" id="editor-ai-context"><span class="wk-chip wk-chip-off"><?= htmlspecialchars(t('editor.ai.no_identifiers'), ENT_QUOTES) ?></span></div><p class="wk-mono wk-dim"><?= htmlspecialchars(t($ai['external'] ? 'editor.ai.external' : 'editor.ai.local', [$ai['provider']]), ENT_QUOTES) ?></p></div>
</aside>
<?php endif; ?>
</div>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'marked.js'), ENT_QUOTES) ?>" defer></script>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/markdown-preview.js'), ENT_QUOTES) ?>" defer></script>
<script type="application/json" id="editor-config"><?= json_encode([
    'basePath' => $basePath,
    'path' => $path,
    'baseRev' => $baseRev,
    'strings' => [
        'saved' => t('editor.saved'),
        'unsaved' => t('editor.unsaved'),
        'saving' => t('editor.saving'),
        'draft' => t('editor.draft'),
        'draftRestored' => t('editor.draft_restored'),
        'draftDismiss' => t('editor.draft_dismiss'),
        'offline' => t('editor.offline'),
        'conflictTitle' => t('err.409.title'),
        'conflictBody' => t('err.409.body'),
        'autosaved' => t('editor.autosaved'),
        'mediaUploading' => t('editor.media_uploading'),
        'mediaFailed' => t('editor.media_failed'),
        'chars' => t('editor.tb.chars'),
        'copied' => t('editor.tb.copied'),
        'copyFailed' => t('editor.tb.copy_failed'),
        'priorUnknown' => t('editor.tb.prior_unknown'),
        'filter' => t('editor.tb.filter'),
        'noMatch' => t('editor.tb.no_match'),
        'templateFailed' => t('editor.tb.template_failed'),
        'column' => t('editor.tb.column'),
        'snippetModality' => t('editor.tb.snippet_modality'),
        'examHead' => t('editor.exam.head'),
        'examAdd' => t('editor.exam.add'),
        'examAddHelp' => t('editor.exam.add_help'),
        'examNew' => t('editor.exam.new'),
        'examRemove' => t('editor.exam.remove'),
        'examRemoveConfirm' => t('editor.exam.remove_confirm'),
        'examLeft' => t('editor.exam.left'),
        'examRight' => t('editor.exam.right'),
        'examUntitled' => t('editor.exam.untitled'),
        'examNoHeading' => t('editor.exam.no_heading'),
        'examShape' => t('editor.exam.shape'),
        'examsUnreadable' => t('editor.exam.unreadable'),
    ],
    'isReport' => \Reporion\Support\ReportPath::isReport($path),
    'ai' => $ai !== null ? [
        'actions' => $ai['actions'],
        'strings' => [
            'working' => t('editor.ai.working'),
            'apply' => t('editor.ai.apply'),
            'insert' => t('editor.ai.insert'),
            'replace' => t('editor.ai.replace'),
            'append' => t('editor.ai.append'),
            'copy' => t('editor.ai.copy'),
            'copied' => t('editor.tb.copied'),
            'regenerate' => t('editor.ai.regenerate'),
            'close' => t('editor.ai.close'),
            'applied' => t('editor.ai.applied'),
            'failed' => t('editor.ai.failed'),
            'selection' => t('editor.ai.selection'),
            'exam' => t('editor.ai.exam'),
            'text' => t('editor.ai.text'),
            'noText' => t('editor.ai.no_text'),
        ],
    ] : null,
    'priors' => $priorCandidates,
    'templates' => $templates,
    'template' => $template,
    'snippets' => $snippets,
], JSON_HEX_TAG) ?></script>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/editor-format.js'), ENT_QUOTES) ?>" defer></script>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/editor-exams.js'), ENT_QUOTES) ?>" defer></script>
<?php if ($ai !== null): ?><script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/editor-ai.js'), ENT_QUOTES) ?>" defer></script><?php endif; ?>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/editor.js'), ENT_QUOTES) ?>" defer></script>
<script>
(function() {
  var toggle = document.getElementById('editor-preview-toggle');
  var toggleTb = document.getElementById('editor-preview-toggle-tb');
  var preview = document.getElementById('editor-preview');
  if (!toggle || !preview) return;
  var configured = false; // marked.js is deferred: configure on first use
  var opts = { basePath: <?= json_encode($basePath, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, examIds: false };
  function show() {
    if (!window.marked || !window.ReporionPreview) return;
    if (!configured) { ReporionPreview.configure(marked, opts); configured = true; }
    var doc = document.querySelector('[name="document"]').value;
    // A multi-exam report's exams anchored as the page view does them (phase 12)
    var fm = /^---\n([\s\S]*?)\n---\n/.exec(doc);
    opts.examIds = !!fm && /^exams:/m.test(fm[1]) && <?= json_encode(\Reporion\Support\ReportPath::isReport($path)) ?>;
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
