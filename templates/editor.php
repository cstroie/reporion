<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET/POST /{path}/edit (Controller\EditorController). Two modes (phase
 * 14): **curated** (`$raw === false`, the default) — a Details panel
 * (Service\FrontmatterFields, native form fields, no YAML) above a
 * body-only textarea (`name="body"`); **raw** (`$raw === true`, `?raw=1`
 * or a multi-exam report always) — the mockup's single textarea
 * (`.wk-edit / .wk-edit-main / .wk-ta`) holding the whole
 * "---\nfrontmatter\n---\n\nbody" block (`name="document"`), unchanged
 * from before this phase.
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
 * string $path, $basePath; int $baseRev; bool $raw;
 * ?string $error, $document, $body, $conflictDocument;
 * ?array $details (Service\FrontmatterFields::forPage(), null in raw mode)
 * ?Reporion\Auth\User $principal
 */

declare(strict_types=1);

/** @var string $path */
/** @var int $baseRev */
/** @var bool $raw */
/** @var ?string $error */
/** @var ?string $document */
/** @var ?string $body */
/** @var ?array{fields: list<array{key: string, label: string, widget: string, value: mixed, options: list<array{value: string, label: string}>, required: bool}>, patient: ?list<array{key: string, label: string, widget: string, value: string, options: list<array{value: string, label: string}>, required: bool}>, accession: ?string, visibility: string, extra: array<string, mixed>} $details */
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
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$b = htmlspecialchars($basePath, ENT_QUOTES);
$formAction = "$b/" . $e($path) . '/edit' . ($raw ? '?raw=1' : '');
/**
 * An assistant action's icon (the profile table's Icon column): a filename
 * with an image extension is an attachment under assets/img/ai/; a bare
 * name (letters/digits/hyphens, no extension) is a Phosphor icon,
 * `ph-{name}`; anything else — a unicode character — is an emoji, `✦` when
 * the column was left empty.
 */
$aiIcon = static function (string $icon) use ($e, $basePath): string {
    $icon = trim($icon);
    if (preg_match('/^[\w-]+\.(png|jpe?g|gif|webp|svg)$/i', $icon) === 1) {
        return '<img class="wk-ai-icon" src="' . $e(\Reporion\Support\Asset::url($basePath, 'img/ai/' . $icon)) . '" alt="">';
    }
    if (preg_match('/^[a-z0-9-]+$/i', $icon) === 1) {
        return '<i class="ph ph-' . $e($icon) . '"></i>';
    }

    return '<span class="wk-ai-emoji" aria-hidden="true">' . $e($icon !== '' ? $icon : '✦') . '</span>';
};
?>
<div id="editor-draft-banner" class="wk-notice" role="status" hidden><i class="ph ph-clock-counter-clockwise"></i><div>
<?= htmlspecialchars(t('editor.draft_found'), ENT_QUOTES) ?> <span class="wk-mono" id="editor-draft-when"></span>
<span style="display:inline-flex;gap:var(--space-2);margin-left:var(--space-2)"><button type="button" class="btn btn-secondary btn-sm" id="editor-draft-restore"><?= htmlspecialchars(t('editor.draft_restore'), ENT_QUOTES) ?></button><button type="button" class="btn btn-ghost btn-sm" id="editor-draft-dismiss"><?= htmlspecialchars(t('editor.draft_dismiss'), ENT_QUOTES) ?></button></span>
</div></div>
<div class="wk-edit<?= $ai !== null ? ' wk-has-ai' : '' ?>">
<div class="wk-edit-main">
<?php if ($newPage ?? false): ?>
<?php /* A page not written yet: the first Save creates it, as revision 1. No
 * page header exists yet to put the raw/curated toggle near Sign, so it
 * stays here as a small button (phase 14). */ ?>
<div class="wk-doc-titlerow"><h1 class="wk-doc-title"><?= htmlspecialchars(t('editor.new_heading'), ENT_QUOTES) ?></h1><div class="wk-actions"><a class="btn btn-secondary btn-sm" href="<?= $raw ? "$b/$path/edit" : "$b/$path/edit?raw=1" ?>"><i class="ph ph-file-code"></i><?= $e(t($raw ? 'details.curated_link' : 'details.raw_link')) ?></a></div></div>
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

<form action="<?= $formAction ?>" method="post" data-island="editor" data-config-id="editor-config" style="display:flex; flex-direction:column; flex:1; gap:var(--space-3); min-height:0;">
<input type="hidden" name="base_rev" value="<?= $baseRev ?>">
<?php /* Collapsed by default (2026-09-27: "I don't want the frontmatter editor to stay in my way") and above the toolbar, out of the way of the text */ ?>
<?php if (!$raw): ?>
<?php include __DIR__ . '/partials/editor-details.php'; ?>
<?php endif; ?>

<?php /* The raw/curated toggle moved to the page header, near Sign (2026-09-27) */ ?>
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
<span class="wk-mono wk-dim" id="editor-chars"><?= htmlspecialchars(t('editor.tb.chars', [mb_strlen($raw ? (string) $document : (string) $body)]), ENT_QUOTES) ?></span>
<?= $tb('copy', 'copy', 'editor.tb.copy') ?>
<button type="button" class="wk-tbtn" title="<?= htmlspecialchars(t('editor.tb.split'), ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('editor.tb.split'), ENT_QUOTES) ?>" id="editor-preview-toggle-tb"><i class="ph ph-columns"></i></button>
<input type="file" id="editor-image-file" accept="image/png,image/jpeg,image/gif,image/webp" multiple hidden>
</div>

<?php /* A report's exam tabs (phase 12, assets/js/editor-exams.js): filled by the script, absent without it — raw mode only, phase 12 predates the split */ ?>
<div class="wk-examtabs" id="editor-exams" role="toolbar" aria-label="<?= htmlspecialchars(t('editor.exams'), ENT_QUOTES) ?>" hidden></div>
<?php if ($raw): ?>
<textarea class="wk-ta wk-mono" name="document" spellcheck="false"><?= htmlspecialchars((string) $document, ENT_QUOTES) ?></textarea>
<?php else: ?>
<textarea class="wk-ta wk-mono" name="body" spellcheck="false"><?= htmlspecialchars((string) $body, ENT_QUOTES) ?></textarea>
<?php endif; ?>
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
<div class="wk-rail-head"><span class="wk-eyebrow"><i class="ph ph-sparkle"></i> <?= htmlspecialchars(t('editor.ai.title'), ENT_QUOTES) ?></span><span class="wk-mono wk-dim" title="<?= htmlspecialchars($ai['provider'], ENT_QUOTES) ?>"><?= htmlspecialchars($ai['server'], ENT_QUOTES) ?></span></div>
<div class="wk-ai-acts">
<?php foreach ($ai['actions'] as $action): ?>
<?php if ($action['custom']): ?>
<div class="wk-ai-custom"><input class="input" type="text" data-ai-prompt="<?= htmlspecialchars($action['id'], ENT_QUOTES) ?>" placeholder="<?= htmlspecialchars($action['tooltip'] !== '' ? $action['tooltip'] : $action['label'], ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars($action['label'], ENT_QUOTES) ?>"><button type="button" class="btn btn-secondary btn-sm" data-ai-action="<?= htmlspecialchars($action['id'], ENT_QUOTES) ?>"><?= htmlspecialchars($action['label'], ENT_QUOTES) ?></button></div>
<?php else: ?>
<button type="button" class="wk-ai-btn" data-ai-action="<?= htmlspecialchars($action['id'], ENT_QUOTES) ?>" title="<?= htmlspecialchars($action['tooltip'], ENT_QUOTES) ?>"><?= $aiIcon($action['icon']) ?><?= htmlspecialchars($action['label'], ENT_QUOTES) ?></button>
<?php endif; ?>
<?php endforeach; ?>
</div>
<div class="wk-ai-outs" id="editor-ai-outs"></div>
<div class="wk-ai-ctx"><span class="wk-eyebrow"><?= htmlspecialchars(t('editor.ai.context'), ENT_QUOTES) ?></span><div class="wk-links" id="editor-ai-context"><span class="wk-chip wk-chip-off"><?= htmlspecialchars(t('editor.ai.no_identifiers'), ENT_QUOTES) ?></span></div><p class="wk-mono wk-dim"><?= htmlspecialchars(t($ai['external'] ? 'editor.ai.external' : 'editor.ai.local', [$ai['provider']]), ENT_QUOTES) ?></p></div>
</aside>
<?php /* result: show opens here instead of an inline .wk-ai-out card — one
 * persistent dialog, reset per run (editor.js's aiModal()) */ ?>
<dialog class="wk-ai-modal" id="editor-ai-modal" aria-label="<?= htmlspecialchars(t('editor.ai.title'), ENT_QUOTES) ?>">
<div class="wk-ai-modal-h"><span class="wk-eyebrow" id="editor-ai-modal-title"></span><span class="wk-mono wk-dim" id="editor-ai-modal-meta"></span></div>
<div class="wk-ai-text" id="editor-ai-modal-body"></div>
<div class="wk-ai-row" id="editor-ai-modal-row"></div>
</dialog>
<?php endif; ?>
</div>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'marked.js'), ENT_QUOTES) ?>" defer></script>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/markdown-preview.js'), ENT_QUOTES) ?>" defer></script>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/highlight.min.js'), ENT_QUOTES) ?>" defer></script>
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
    var doc = document.querySelector('[name="document"], [name="body"]').value;
    // A multi-exam report's exams anchored as the page view does them (phase 12)
    var fm = /^---\n([\s\S]*?)\n---\n/.exec(doc);
    opts.examIds = !!fm && /^exams:/m.test(fm[1]) && <?= json_encode(\Reporion\Support\ReportPath::isReport($path)) ?>;
    preview.innerHTML = marked.parse(ReporionPreview.body(doc));
    ReporionPreview.sanitize(preview);
    if (window.hljs) preview.querySelectorAll('pre code').forEach(function (block) { hljs.highlightElement(block); });
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
