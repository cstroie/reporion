<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET/POST /{path}/edit (Controller\EditorController). Two modes (phase
 * 14): **curated** (`$raw === false`, the default): a Details panel
 * (Service\FrontmatterFields, native form fields, no YAML) above a
 * body-only textarea (`name="body"`); **raw** (`$raw === true`, `?raw=1`
 * or a multi-exam report always): the mockup's single textarea
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
 * Not rendered until they work: the AI rail (D15: hidden while no
 * provider is configured), and "sign on save" (signing is its own
 * action). "Minor edit" is wired (Storage\FlatFile::saveMinor(),
 * invariant 3's one exception): hidden for a page's first save
 * (baseRev === 0, nothing to squash into yet) and for a signed page
 * (a signature covers its revision's exact bytes, D3).
 * The preview is the server's own renderer (POST /api/v1/render through
 * assets/js/render-client.js; D17 amended 2026-10-15, marked.js is gone):
 * body only, raw HTML escaped, unsafe URLs dropped. A failed request leaves
 * the last render and says so; typing and the local draft go on.
 *
 * Content only: Http\View::page() wraps it in templates/layout.php with
 * $editorShell set: the mockup's full-bleed, full-height editor
 * (design/mockup/WikiEditor.dc.html): no page header and no tab row, a
 * crumbs line instead (path, rev N → N+1, status, the raw/curated toggle),
 * Cancel back to the report. Kept beyond the mockup: the Details panel,
 * the exam tabs, Minor edit, the split preview on the toolbar.
 *
 * Variables in scope (see Controller\EditorController):
 * string $path, $basePath, $status; int $baseRev; bool $raw;
 * array{href: string, label: string} $rawLink;
 * ?string $error, $document, $body, $conflictDocument;
 * ?array $details (Service\FrontmatterFields::forPage(), null in raw mode)
 * ?Reporion\Auth\User $principal
 */

declare(strict_types=1);

/** @var string $path */
/** @var int $baseRev */
/** @var bool $raw */
/** @var bool $signed */
/** @var string $status */
/** @var array{href: string, label: string} $rawLink */
/** @var ?string $error */
/** @var ?string $document */
/** @var ?string $body */
/** @var ?array{fields: list<array{key: string, label: string, widget: string, value: mixed, options: list<array{value: string, label: string}>, required: bool}>, patient: ?list<array{key: string, label: string, widget: string, value: string, options: list<array{value: string, label: string}>, required: bool}>, accession: ?string, visibility: string, extra: array<string, mixed>} $details */
/** @var ?string $conflictDocument */
/** @var list<array{path: string, label: string, date: string, modality: string}> $priorCandidates */
/** @var list<array{path: string, title: string, region: string}> $templates */
/** @var string $template */
/** @var list<array{name: string, title: string, body: string, modality: bool}> $snippets */
/** @var string $basePath */
/** @var bool $canWrite */
/** @var ?\Reporion\Auth\User $principal */
/** @var ?array{actions: list<array{id: string, label: string, tooltip: string, icon: string, result: string, custom: bool}>, provider: string, external: bool} $ai */
/** @var list<array{exam: int, title: string, template: string, items: list<array{section: bool, label: string, keywords: list<string>}>}> $checklists */
/** @var ?array{expand: bool, descriptors: bool} $recall */
$ai ??= null;
$checklists ??= [];
$references ??= [];
// The Tools section (editing aids that need no assistant) is always there, so is the rail;
// the enabled plugins add their own tools to it (the `editor_tool` slot)
$pluginTools = reporion_plugin_ui()['editor_tool'] ?? [];
$recall ??= null;
$aids ??= false;
// Recall's Expand / Descriptors ask the assistant without a rail action of their own
$recallAi = $recall !== null && ($recall['expand'] || $recall['descriptors']);
$rail = true;
$signed ??= false;
$status ??= 'draft';
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$b = htmlspecialchars($basePath, ENT_QUOTES);
$formAction = "$b/" . $e($path) . '/edit' . ($raw ? '?raw=1' : '');
/**
 * An assistant action's icon (the profile table's Icon column): a filename
 * with an image extension is an attachment under assets/img/ai/; a bare
 * name (letters/digits/hyphens, no extension) is a Phosphor icon,
 * `ph-{name}`; anything else: a unicode character: is an emoji, `✦` when
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
<div class="wk-edit<?= $rail ? ' wk-has-ai' : '' ?>">
<div class="wk-edit-main">
<?php /* The mockup's crumbs line (WikiEditor .wk-crumbs): no page header or
 * tab row on this route. Cancel goes back to the report. #editor-status is
 * where assets/js/editor.js reports saved / unsaved / offline. */ ?>
<?php /* The page's one h1, for a screen reader's outline: the crumbs line says the same to the eye */ ?>
<h1 class="wk-vh"><?= $e(t('editor.editing')) ?> <?= $e($path) ?></h1>
<div class="wk-crumbs wk-mono wk-edit-crumbs">
<i class="ph ph-pencil-simple" aria-hidden="true"></i><span><?= $e(t('editor.editing')) ?></span><b><?= $e($path) ?></b>
<?php if ($newPage ?? false): ?>
<span class="tag tag-accent"><?= $e(t('editor.rev_new')) ?></span>
<span class="wk-dim wk-edit-note"><?= $e(t('editor.new_note')) ?></span>
<?php else: ?>
<span class="tag tag-neutral"><?= $e(t('editor.rev_next', [$baseRev, $baseRev + 1])) ?></span>
<span class="tag <?= \Reporion\Support\Badges::statusTag($status) ?>" title="<?= $e($status) ?>"><i class="ph <?= \Reporion\Support\Badges::statusIcon((string) $status) ?>" aria-hidden="true"></i><span class="tag-word"> <?= $e($status) ?></span></span>
<?php endif; ?>
<span class="wk-dim wk-edit-status" id="editor-status" aria-live="polite"></span>
<span class="wk-tflex"></span>
<?php if ($references !== []): /* Phase 25: opens the rail's Reference section */ ?>
<a class="btn btn-secondary btn-sm" href="#editor-reference" data-rail-open="reference" title="<?= $e(t('refs.open')) ?>"><i class="ph ph-book-open"></i><span class="wk-btn-label"><?= $e(t('refs.title')) ?></span></a>
<?php endif; ?>
<?php if (($carried ?? null) === null): /* Raw edit is a GET: a guided report not written yet would lose its carried frontmatter */ ?>
<a class="btn btn-secondary btn-sm" href="<?= $e($rawLink['href']) ?>" title="<?= $e($rawLink['label']) ?>"><i class="ph ph-file-code"></i><span class="wk-btn-label"><?= $e($rawLink['label']) ?></span></a>
<?php endif; ?>
<?php if (!$raw): ?>
<button type="button" class="btn btn-secondary btn-sm" id="editor-meta-toggle" aria-controls="editor-details" aria-pressed="false" title="<?= $e(t('details.panel')) ?>" hidden><i class="ph ph-list-dashes"></i><span class="wk-btn-label"><?= $e(t('details.panel')) ?></span></button>
<?php endif; ?>
</div>

<div id="editor-draft-banner" class="wk-notice wk-notice-info" role="status" hidden><i class="ph ph-clock-counter-clockwise" aria-hidden="true"></i><div>
<?= htmlspecialchars(t('editor.draft_found'), ENT_QUOTES) ?> <span class="wk-mono" id="editor-draft-when"></span>
<span class="wk-draft-acts"><button type="button" class="btn btn-secondary btn-sm" id="editor-draft-restore"><i class="ph ph-arrow-counter-clockwise" aria-hidden="true"></i><?= htmlspecialchars(t('editor.draft_restore'), ENT_QUOTES) ?></button><button type="button" class="btn btn-ghost btn-sm" id="editor-draft-dismiss"><i class="ph ph-trash" aria-hidden="true"></i><?= htmlspecialchars(t('editor.draft_dismiss'), ENT_QUOTES) ?></button></span>
</div></div>

<?php /* The server autosave (D25 amended, assets/js/editor.js): the user's own draft kept in the page's directory: offered back, or just restored */ ?>
<?php if (($autosave['draft'] ?? null) !== null): $draft = $autosave['draft']; ?>
<div id="editor-autosave-banner" class="wk-notice wk-notice-info" role="status"><i class="ph ph-cloud-arrow-up" aria-hidden="true"></i><div>
<?= htmlspecialchars(t('editor.autosave_found'), ENT_QUOTES) ?> <span class="wk-mono"><?= htmlspecialchars(\Reporion\Support\MetaText::when($draft['ts']), ENT_QUOTES) ?></span>
<?php if ($draft['stale']): ?><br><strong><?= htmlspecialchars(t('editor.autosave_stale', [$draft['baseRev'], $draft['currentRev']]), ENT_QUOTES) ?></strong><?php endif; ?>
<span class="wk-draft-acts"><a class="btn btn-secondary btn-sm" id="editor-autosave-restore" href="<?= htmlspecialchars($basePath . '/' . $path . '/edit?' . ($draft['raw'] ? 'raw=1&' : '') . 'autosave=restore', ENT_QUOTES) ?>"><i class="ph ph-arrow-counter-clockwise" aria-hidden="true"></i><?= htmlspecialchars(t('editor.draft_restore'), ENT_QUOTES) ?></a><button type="button" class="btn btn-ghost btn-sm" id="editor-autosave-discard"><i class="ph ph-trash" aria-hidden="true"></i><?= htmlspecialchars(t('editor.draft_dismiss'), ENT_QUOTES) ?></button></span>
</div></div>
<?php endif; ?>
<?php if (($restored ?? null) !== null): ?>
<?php $noticeKind = 'info'; $noticeText = $restored; $noticeId = 'editor-restored-notice'; $noticeIcon = 'cloud-arrow-up'; include __DIR__ . '/partials/notice.php'; ?>
<?php endif; ?>

<?php if ($error !== null): ?>
<?php $noticeKind = 'error'; $noticeText = $error; $noticeLink = ($existingPath ?? null) !== null ? [$basePath . '/' . $existingPath . '/edit', t('new.open_existing')] : null; include __DIR__ . '/partials/notice.php'; ?>
<?php endif; ?>

<?php if ($conflictDocument !== null): ?>
<div class="wk-panel" id="editor-conflict">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= htmlspecialchars(t('editor.conflict_current'), ENT_QUOTES) ?></h2></hgroup></header>
<pre class="wk-mono wk-difftext"><?= htmlspecialchars($conflictDocument, ENT_QUOTES) ?></pre>
</div>
<?php endif; ?>

<form action="<?= $formAction ?>" method="post" class="wk-edit-form" data-island="editor" data-config-id="editor-config">
<input type="hidden" name="base_rev" value="<?= $baseRev ?>">
<?php if (($carried ?? null) !== null): /* A guided report not written yet: its frontmatter, for the first Save */ ?><input type="hidden" name="carried" value="<?= htmlspecialchars($carried, ENT_QUOTES) ?>"><?php endif; ?>
<?php /* The Metadata view (2026-09-29): the Details form in place of the
 * toolbar and text, from the crumbs line's Metadata button: out of the
 * way of the text until asked for (2026-09-27). Without JS both show. */ ?>
<?php if (!$raw): ?>
<?php include __DIR__ . '/partials/editor-details.php'; ?>
<?php endif; ?>
<div class="wk-edit-body" id="editor-body">

<?php
$tb = static fn (string $action, string $icon, string $key, bool $show = true): string => $show
    ? '<button type="button" class="wk-tbtn" data-tb="' . $action . '" title="' . htmlspecialchars(t($key), ENT_QUOTES) . '" aria-label="' . htmlspecialchars(t($key), ENT_QUOTES) . '"><i class="ph ph-' . $icon . '"></i></button>' . "\n"
    : '';
?>
<div class="wk-tbar" id="editor-toolbar" role="toolbar" aria-label="<?= $e(t('editor.toolbar')) ?>">
<?php if (($savedRev ?? null) !== null && $error === null): /* Save keeps the editor open (Admin → Settings): over the toolbar, same size, then fades away; assets/js/editor.js removes it */ ?>
<div class="wk-tbar-toast">
<?php $noticeKind = 'ok'; $noticeId = 'editor-saved-notice'; $noticeText = ($savedNote ?? '') !== '' ? t('editor.saved_rev_note', [$savedRev, $savedNote]) : t('editor.saved_rev', [$savedRev]); include __DIR__ . '/partials/notice.php'; ?>
</div>
<?php endif; ?>
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
<?= $tb('url', 'globe-simple', 'editor.tb.url') ?>
<?= $tb('image', 'image-square', 'editor.tb.image') ?>
<?= $tb('prior', 'clock-clockwise', 'editor.tb.prior', $priorCandidates !== []) ?>
<?= $tb('template', 'cards', 'editor.tb.template', $templates !== []) ?>
<?= $tb('snippets', 'lightning', 'editor.tb.snippets', $snippets !== []) ?>
<?= $tb('slash', 'command', 'editor.tb.slash') ?>
<span class="wk-tflex"></span>
<span class="wk-mono wk-dim" id="editor-chars"><?= htmlspecialchars(t('editor.tb.chars', [mb_strlen($raw ? (string) $document : (string) $body)]), ENT_QUOTES) ?></span>
<?= $tb('copy', 'copy', 'editor.tb.copy') ?>
<button type="button" class="wk-tbtn" title="<?= htmlspecialchars(t('editor.tb.split'), ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('editor.tb.split'), ENT_QUOTES) ?>" id="editor-preview-toggle-tb"><i class="ph ph-columns"></i></button>
<input type="file" id="editor-image-file" accept="image/png,image/jpeg,image/gif,image/webp" multiple hidden>
</div>

<?php /* A report's exam tabs (phase 12, assets/js/editor-exams.js): filled by the script, absent without it: normal edit only since 2026-10-07 (raw edit is the one whole document); the exams list is the Details panel's cards */ ?>
<div class="wk-examtabs" id="editor-exams" role="toolbar" aria-label="<?= htmlspecialchars(t('editor.exams'), ENT_QUOTES) ?>" hidden></div>
<?php /* Side by side when the split preview is on (TODO 13: it used to stack
   above/below the text); docArea.parentNode is this wrapper, so exam-tab
   panes (assets/js/editor.js newPane()) land here too. */ ?>
<div class="wk-editpane" id="editor-pane">
<?php if ($raw): ?>
<textarea class="wk-ta wk-mono" name="document" spellcheck="false"><?= htmlspecialchars((string) $document, ENT_QUOTES) ?></textarea>
<?php else: ?>
<textarea class="wk-ta wk-mono" name="body" spellcheck="false"><?= htmlspecialchars((string) $body, ENT_QUOTES) ?></textarea>
<?php endif; ?>
<div class="wk-preview" id="editor-preview" hidden></div>
<template id="editor-preview-offline"><?php $noticeKind = 'warn'; $noticeText = t('editor.preview.offline'); include __DIR__ . '/partials/notice.php'; ?></template>
</div>
</div>
<?php if (!$raw): ?>
<script>
(function () {
  // Before first paint: the text in front, the Metadata form behind its button
  var btn = document.getElementById('editor-meta-toggle');
  var meta = document.getElementById('editor-details');
  var body = document.getElementById('editor-body');
  if (!btn || !meta || !body) return;
  function show(on) {
    meta.hidden = !on;
    body.hidden = on;
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    // Primary while the panel is open: press again to close it
    btn.classList.toggle('btn-primary', on);
    btn.classList.toggle('btn-secondary', !on);
    var focus = on ? meta.querySelector('input, select, textarea') : body.querySelector('#editor-pane textarea:not([hidden])');
    return focus;
  }
  // A save sent back for the D16 acknowledgement opens on the visibility picker
  var reopen = meta.getAttribute('data-open') === 'visibility';
  show(reopen);
  if (reopen) { var pick = meta.querySelector('.wk-vispick'); if (pick) pick.scrollIntoView({ block: 'center' }); }
  btn.hidden = false;
  btn.addEventListener('click', function () {
    var f = show(meta.hidden);
    if (f) f.focus();
  });
  meta.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') { ev.preventDefault(); var f = show(false); if (f) f.focus(); }
  });
})();
</script>
<?php endif; ?>
<div class="wk-savebar">
<?php /* A minor edit keeps its revision's note: assets/js/editor.js shows it, disabled, while Minor edit is ticked */ ?>
<input class="input wk-commit" type="text" id="note" name="note" autocomplete="off" placeholder="<?= htmlspecialchars(t('editor.note_hint'), ENT_QUOTES) ?>"<?php if ($baseRev > 0 && !$signed): ?> data-last-note="<?= htmlspecialchars((string) ($lastNote ?? ''), ENT_QUOTES) ?>"<?php endif; ?>>
<?php if ($baseRev > 0 && !$signed): ?>
<label class="radio wk-minor" title="<?= htmlspecialchars(t('editor.minor_help'), ENT_QUOTES) ?>"><input type="checkbox" id="editor-minor" name="minor" value="1"><span class="dot"></span><?= htmlspecialchars(t('editor.minor'), ENT_QUOTES) ?></label>
<?php endif; ?>
<span class="wk-tflex"></span>
<a class="btn btn-secondary" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($path, ENT_QUOTES) ?>"><i class="ph ph-x" aria-hidden="true"></i><?= htmlspecialchars(t(($saveStaysOpen ?? false) ? 'editor.close' : 'editor.cancel'), ENT_QUOTES) ?></a>
<button class="btn btn-primary" type="submit" id="editor-save-btn" data-rev="<?= $baseRev ?>"><i class="ph ph-check"></i><span id="editor-save-label"><?= htmlspecialchars(t('editor.save', [$baseRev + 1]), ENT_QUOTES) ?></span></button>
</div>
<?php if ($ai !== null || $recallAi): ?><input type="hidden" name="ai_assisted" id="editor-ai-assisted" value=""><?php endif; ?>
</form>
</div>
<?php if ($rail): ?>
<?php /* The rail (2026-10-02): an accordion of the exams' reference page (phase 25), their
 * checklists (phase 26) and the Assistant (phase 15d, design/mockup/WikiEditor.dc.html .wk-ai:
 * it proposes, the doctor applies. A3, D8), one section open at a time: <details name>
 * does it natively, assets/js/editor-rail.js for older browsers and to remember the last
 * one opened. Opened by default: the checklist, else the Assistant, else the reference. */ ?>
<?php $railOpen = $checklists !== [] ? 'checklist' : ($ai !== null ? 'assistant' : ($recall !== null ? 'recall' : ($references !== [] ? 'reference' : 'tools'))); ?>
<aside class="wk-ai wk-rail" id="editor-ai" aria-label="<?= $e(t('editor.rail')) ?>">
<?php if ($references !== []): ?>
<details class="wk-rail-sec" name="editor-rail" data-rail="reference" id="editor-reference"<?= $railOpen === 'reference' ? ' open' : '' ?>>
<summary class="wk-rail-head"><span class="wk-eyebrow"><i class="ph ph-book-open"></i> <?= $e(t('refs.title')) ?></span><span class="wk-rail-sub"><?= $e($references[0]['title']) ?></span><i class="ph ph-caret-down wk-rail-caret"></i></summary>
<div class="wk-rail-body">
<?php include __DIR__ . '/partials/reference-pages.php'; ?>
</div>
</details>
<?php endif; ?>
<?php if ($checklists !== []): ?>
<?php /* Phase 26: each exam's template checklist. Ticks are the doctor's own aid
 * kept in this browser, never saved (D18: the prose is the report); an item
 * with keywords none of which is in its exam's text is marked "not mentioned"
 * (assets/js/editor-checklist.js). Not in print, PDF, ODT or SR. */ ?>
<details class="wk-rail-sec" name="editor-rail" data-rail="checklist"<?= $railOpen === 'checklist' ? ' open' : '' ?>>
<summary class="wk-rail-head"><span class="wk-eyebrow"><i class="ph ph-list-checks"></i> <?= $e(t('editor.check.title')) ?></span><span class="wk-mono wk-dim" id="editor-check-count"></span><i class="ph ph-caret-down wk-rail-caret"></i></summary>
<div class="wk-rail-body">
<section class="wk-check" id="editor-checklist">
<?php foreach ($checklists as $list): ?>
<details class="wk-check-exam" open data-exam="<?= (int) $list['exam'] ?>">
<summary><?= $e($list['title'] !== '' ? $list['title'] : t('editor.check.exam', [$list['exam'] + 1])) ?></summary>
<ul class="wk-check-list">
<?php foreach ($list['items'] as $i => $item): ?>
<?php if ($item['section']): ?>
<li class="wk-check-section"><?= $e($item['label']) ?></li>
<?php else: ?>
<li class="wk-check-item" data-keywords="<?= $e(json_encode($item['keywords'], JSON_UNESCAPED_UNICODE) ?: '[]') ?>"><label class="radio"><input type="checkbox" data-check="<?= (int) $list['exam'] . ':' . $i ?>"><span class="dot"></span><span class="wk-check-label"><?= $e($item['label']) ?></span></label><span class="wk-check-miss" title="<?= $e(t('editor.check.missing_help')) ?>" hidden><?= $e(t('editor.check.missing')) ?></span></li>
<?php endif; ?>
<?php endforeach; ?>
</ul>
</details>
<?php endforeach; ?>
<p class="wk-mono wk-dim wk-check-note"><?= $e(t('editor.check.note')) ?></p>
</section>
</div>
</details>
<?php endif; ?>
<?php /* Order (2026-10-20, fixed): Assistant, Prior, Recall, Checks, Tools, Comments; the exam's own Reference and Checklist, when it has them, come first */ ?>
<?php if ($ai !== null): ?>
<details class="wk-rail-sec" name="editor-rail" data-rail="assistant"<?= $railOpen === 'assistant' ? ' open' : '' ?>>
<summary class="wk-rail-head"><span class="wk-eyebrow"><i class="ph ph-sparkle"></i> <?= htmlspecialchars(t('editor.ai.title'), ENT_QUOTES) ?></span><span class="wk-mono wk-dim" title="<?= htmlspecialchars($ai['provider'], ENT_QUOTES) ?>"><?= htmlspecialchars($ai['server'], ENT_QUOTES) ?></span><i class="ph ph-caret-down wk-rail-caret"></i></summary>
<div class="wk-rail-body">
<div class="wk-ai-acts">
<?php foreach ($ai['actions'] as $action): ?>
<?php if ($action['section']): ?><?php if ($action['sectionLabel'] !== ''): ?><h4 class="wk-ai-sec"><?= $action['sectionIcon'] !== '' ? $aiIcon($action['sectionIcon']) : '' ?><?= htmlspecialchars($action['sectionLabel'], ENT_QUOTES) ?></h4><?php else: ?><hr class="wk-ai-sep"><?php endif; ?><?php endif; ?>
<?php if ($action['custom']): ?>
<div class="group wk-ai-custom"><input class="input" type="text" data-ai-prompt="<?= htmlspecialchars($action['id'], ENT_QUOTES) ?>" placeholder="<?= htmlspecialchars($action['tooltip'] !== '' ? $action['tooltip'] : $action['label'], ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars($action['label'], ENT_QUOTES) ?>"><button type="button" class="btn" data-ai-action="<?= htmlspecialchars($action['id'], ENT_QUOTES) ?>"><?= htmlspecialchars($action['label'], ENT_QUOTES) ?></button></div>
<?php else: ?>
<button type="button" class="wk-ai-btn" data-ai-action="<?= htmlspecialchars($action['id'], ENT_QUOTES) ?>" title="<?= htmlspecialchars($action['tooltip'], ENT_QUOTES) ?>"><?= $aiIcon($action['icon']) ?><?= htmlspecialchars($action['label'], ENT_QUOTES) ?></button>
<?php endif; ?>
<?php endforeach; ?>
</div>
<div class="wk-ai-ctx"><span class="wk-eyebrow"><?= htmlspecialchars(t('editor.ai.context'), ENT_QUOTES) ?></span><div class="wk-links" id="editor-ai-context"><span class="wk-chip wk-chip-off"><?= htmlspecialchars(t('editor.ai.no_identifiers'), ENT_QUOTES) ?></span></div><p class="wk-mono wk-dim"><?= htmlspecialchars(t($ai['external'] ? 'editor.ai.external' : 'editor.ai.local', [$ai['provider']]), ENT_QUOTES) ?></p></div>
<div class="wk-ai-last" id="editor-ai-last" hidden><span class="wk-eyebrow"><?= htmlspecialchars(t('editor.ai.last'), ENT_QUOTES) ?></span><dl class="wk-mono" id="editor-ai-last-list"></dl></div>
</div>
</details>
<?php endif; ?>
<?php if ($aids): ?>
<details class="wk-rail-sec" name="editor-rail" data-rail="prior">
<summary class="wk-rail-head"><span class="wk-eyebrow"><i class="ph ph-clock-clockwise"></i> <?= $e(t('editor.prior.title')) ?></span><span class="wk-rail-sub" id="editor-prior-head"></span><i class="ph ph-caret-down wk-rail-caret"></i></summary>
<div class="wk-rail-body">
<section class="wk-aid" id="editor-prior">
<div class="wk-recall-bar"><label class="radio"><input type="checkbox" id="editor-prior-auto" checked><span class="dot"></span><?= $e(t('editor.prior.auto')) ?></label></div>
<p class="wk-mono wk-dim wk-recall-status" id="editor-prior-meta" aria-live="polite"><?= $e(t('editor.prior.loading')) ?></p>
<div class="wk-aid-prior-hint" id="editor-prior-hint" hidden></div>
<div class="wk-aid-prior" id="editor-prior-body"></div>
</section>
</div>
</details>
<?php endif; ?>
<?php if ($recall !== null): ?>
<?php /* D50: passages of finished reports for the line just written, the doctor's own
 * first, and what several of them say that the text does not: assets/js/editor-recall.js.
 * Closed by default: its count shows on the head; it never takes the focus. */ ?>
<details class="wk-rail-sec" name="editor-rail" data-rail="recall"<?= $railOpen === 'recall' ? ' open' : '' ?>>
<summary class="wk-rail-head"><span class="wk-eyebrow"><i class="ph ph-clock-counter-clockwise"></i> <?= $e(t('editor.recall.title')) ?></span><span class="wk-mono wk-dim" id="editor-recall-count"></span><i class="ph ph-caret-down wk-rail-caret"></i></summary>
<div class="wk-rail-body">
<section class="wk-recall" id="editor-recall">
<div class="wk-recall-bar"><label class="radio"><input type="checkbox" id="editor-recall-auto" checked><span class="dot"></span><?= $e(t('editor.recall.auto')) ?></label><button type="button" class="btn btn-secondary btn-sm" data-recall-now title="<?= $e(t('editor.recall.now_hint')) ?>"><i class="ph ph-magnifying-glass"></i> <?= $e(t('editor.recall.now')) ?></button></div>
<p class="wk-mono wk-dim wk-recall-status" id="editor-recall-status" aria-live="polite"><?= $e(t('editor.recall.hint')) ?></p>
<div class="wk-recall-missing" id="editor-recall-missing" hidden><span class="wk-eyebrow" title="<?= $e(t('editor.recall.missing_hint')) ?>"><?= $e(t('editor.recall.missing')) ?></span><div class="wk-links" id="editor-recall-chips"></div></div>
<?php if ($recallAi): ?>
<div class="wk-recall-ai" id="editor-recall-ai" hidden>
<div class="wk-recall-row">
<?php if ($recall['expand']): ?><button type="button" class="wk-ai-btn" data-recall-ai="recall-expand" title="<?= $e(t('editor.recall.expand_hint')) ?>"><i class="ph ph-arrows-out-line-horizontal"></i><?= $e(t('editor.recall.expand')) ?></button><?php endif; ?>
<?php if ($recall['descriptors']): ?><button type="button" class="wk-ai-btn" data-recall-ai="recall-missing" title="<?= $e(t('editor.recall.descriptors_hint')) ?>"><i class="ph ph-list-magnifying-glass"></i><?= $e(t('editor.recall.descriptors')) ?></button><?php endif; ?>
</div>
<div class="wk-recall-ai-out" id="editor-recall-ai-out" hidden></div>
<div class="wk-recall-row" id="editor-recall-ai-row"></div>
</div>
<?php endif; ?>
<ol class="wk-recall-list" id="editor-recall-list"></ol>
<p class="wk-mono wk-dim wk-check-note"><?= $e(t('editor.recall.note')) ?></p>
</section>
</div>
</details>
<?php endif; ?>
<?php if ($aids): ?>
<?php /* D51: what the text still lacks (Support\WritingChecks, the Sign screen's list) and the
 * patient's prior report at the section the cursor is in: assets/js/editor-aids.js. Closed by
 * default; the counts show on the heads; nothing here writes without a click. */ ?>
<details class="wk-rail-sec" name="editor-rail" data-rail="checks">
<summary class="wk-rail-head"><span class="wk-eyebrow"><i class="ph ph-list-checks"></i> <?= $e(t('editor.checks.title')) ?></span><span class="wk-mono wk-dim" id="editor-checks-count"></span><i class="ph ph-caret-down wk-rail-caret"></i></summary>
<div class="wk-rail-body">
<section class="wk-aid" id="editor-checks">
<div class="wk-recall-bar"><label class="radio"><input type="checkbox" id="editor-checks-auto" checked><span class="dot"></span><?= $e(t('editor.checks.auto')) ?></label><button type="button" class="btn btn-secondary btn-sm" data-checks-now><i class="ph ph-arrows-clockwise"></i> <?= $e(t('editor.checks.now')) ?></button></div>
<p class="wk-mono wk-dim wk-recall-status" id="editor-checks-status" aria-live="polite"></p>
<ul class="wk-aid-checks" id="editor-checks-list"></ul>
<p class="wk-mono wk-dim wk-check-note"><?= $e(t('editor.checks.note')) ?></p>
</section>
</div>
</details>
<?php endif; ?>
<?php /* Tools (2026-10-16): text clean-ups that run in the browser on the text in front (its
 * selection, else all of it) through assets/js/editor-format.js: no assistant, no server
 * call, one undo step each. Three are the editor's own; a plugin adds more (plugins/dokuwiki). */ ?>
<details class="wk-rail-sec" name="editor-rail" data-rail="tools"<?= $railOpen === 'tools' ? ' open' : '' ?>>
<summary class="wk-rail-head"><span class="wk-eyebrow"><i class="ph ph-wrench"></i> <?= $e(t('editor.tools.title')) ?></span><span class="wk-rail-sub"><?= $e(t('editor.tools.sub')) ?></span><i class="ph ph-caret-down wk-rail-caret"></i></summary>
<div class="wk-rail-body">
<div class="wk-ai-acts">
<button type="button" class="wk-ai-btn" data-edit-tool="cleanText" title="<?= $e(t('editor.tools.clean_help')) ?>"><i class="ph ph-broom"></i><?= $e(t('editor.tools.clean')) ?></button>
<button type="button" class="wk-ai-btn" data-edit-tool="unitsDegrees" title="<?= $e(t('editor.tools.units_help')) ?>"><i class="ph ph-ruler"></i><?= $e(t('editor.tools.units')) ?></button>
<button type="button" class="wk-ai-btn" data-edit-tool="alignTables" title="<?= $e(t('editor.tools.align_help')) ?>"><i class="ph ph-columns"></i><?= $e(t('editor.tools.align')) ?></button>
<?php if ($pluginTools !== []): ?>
<hr class="wk-ai-sep">
<?php foreach ($pluginTools as $tool): ?>
<button type="button" class="wk-ai-btn" data-edit-tool="<?= $e($tool['tool']) ?>" title="<?= $e(t($tool['help'])) ?>"><i class="ph ph-<?= $e($tool['icon']) ?>"></i><?= $e(t($tool['label'])) ?></button>
<?php endforeach; ?>
<?php endif; ?>
</div>
<p class="wk-mono wk-dim wk-check-note"><?= $e(t('editor.tools.note')) ?></p>
</div>
</details>
<?php if (($commenter ?? null) !== null && $baseRev > 0): ?>
<?php /* D52: the page's comments beside the text: read, add, resolve (assets/js/comments.js) */ ?>
<details class="wk-rail-sec" name="editor-rail" data-rail="comments">
<summary class="wk-rail-head"><span class="wk-eyebrow"><i class="ph ph-chat-circle-text"></i> <?= $e(t('comments.title')) ?></span><span class="wk-mono wk-dim" id="editor-comments-count" data-comments-count></span><i class="ph ph-caret-down wk-rail-caret"></i></summary>
<div class="wk-rail-body">
<?php $commentsInRail = true; include __DIR__ . '/partials/comments.php'; ?>
</div>
</details>
<?php endif; ?>
</aside>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/editor-rail.js'), ENT_QUOTES) ?>" defer></script>
<?php if ($references !== []): ?><script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/reference-panel.js'), ENT_QUOTES) ?>" defer></script><?php endif; ?>
<?php endif; ?>
<?php if ($ai !== null): ?>
<?php /* result: show opens its answer here, rendered: one persistent
 * dialog, reset per run (editor.js's aiModal()); the other results write
 * straight into the text */ ?>
<dialog class="wk-modal wk-modal-wide" id="editor-ai-modal" aria-labelledby="editor-ai-modal-title">
<header><h2 class="wk-eyebrow" id="editor-ai-modal-title"></h2><span class="wk-mono wk-dim wk-text-xs" id="editor-ai-modal-meta"></span><button type="button" class="wk-tbtn" data-modal-close title="<?= htmlspecialchars(t('drawer.close'), ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('drawer.close'), ENT_QUOTES) ?>"><i class="ph ph-x"></i></button></header>
<div class="wk-modal-main" tabindex="-1" autofocus><div class="wk-ai-text" id="editor-ai-modal-body"></div></div>
<footer id="editor-ai-modal-row"></footer>
</dialog>
<?php endif; ?>
</div>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/render-client.js'), ENT_QUOTES) ?>" defer></script>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/editor-sync.js'), ENT_QUOTES) ?>" defer></script>
<script type="application/json" id="editor-config"><?= json_encode([
    'basePath' => $basePath,
    'path' => $path,
    'baseRev' => $baseRev,
    'saveStaysOpen' => $saveStaysOpen ?? false,
    'savedRev' => $savedRev ?? null,
    // A refused save redisplayed: the text in front is not what is stored
    'unsaved' => ($error ?? null) !== null || ($restored ?? null) !== null,
    // The server autosave: an existing page only (a path not written yet has no directory to keep a draft in)
    'autosave' => ($autosave ?? null) !== null && $baseRev > 0 ? [
        'url' => $basePath . $autosave['url'],
        'delayMs' => $autosave['delayMs'],
        'raw' => $raw,
        'hasDraft' => $autosave['draft'] !== null,
    ] : null,
    'strings' => [
        'autosaved' => t('editor.autosave_saved'),
        'autosavePaused' => t('editor.autosave_paused'),
        'autosaveFailed' => t('editor.autosave_failed'),
        'saveRevNext' => t('editor.save', [$baseRev + 1]),
        'saveRevSame' => t('editor.save', [$baseRev]),
        'saved' => t('editor.saved'),
        'unsaved' => t('editor.unsaved'),
        'unsavedNotKept' => t('editor.unsaved_not_kept'),
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
        'charsText' => t('editor.tb.chars_text'),
        'copied' => t('editor.tb.copied'),
        'copyFailed' => t('editor.tb.copy_failed'),
        'priorUnknown' => t('editor.tb.prior_unknown'),
        'urlAddress' => t('editor.tb.url_address'),
        'urlAddressHint' => t('editor.tb.url_address_hint'),
        'urlText' => t('editor.tb.url_text'),
        'urlTextHint' => t('editor.tb.url_text_hint'),
        'urlTextHelp' => t('editor.tb.url_text_help'),
        'urlInsert' => t('editor.tb.url_insert'),
        'urlInvalid' => t('editor.tb.url_invalid'),
        'toolDone' => t('editor.tools.done'),
        'toolLeft' => t('editor.tools.left'),
        'toolNone' => t('editor.tools.none'),
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
        'slashSection' => t('editor.slash.section'),
        'slashFormat' => t('editor.slash.format'),
        'slashInsert' => t('editor.slash.insert'),
        'slashSnippet' => t('editor.slash.snippet'),
        'slashRail' => t('editor.slash.rail'),
        'slashRecall' => t('editor.slash.recall'),
        'slashChecks' => t('editor.slash.checks'),
        'slashOff' => t('editor.slash.off'),
        'slashOnLabel' => t('editor.slash.on'),
        'tbTable' => t('editor.slash.table'),
        'tbBullets' => t('editor.tb.bullets'),
        'tbNumbers' => t('editor.tb.numbers'),
        'tbCode' => t('editor.tb.code'),
        'tbLink' => t('editor.tb.link'),
        'tbUrl' => t('editor.tb.url'),
        'tbImage' => t('editor.tb.image'),
        'tbPrior' => t('editor.tb.prior'),
        'tbTemplate' => t('editor.tb.template'),
    ],
    // The slash menu's section headings (Romanian content, D26): a report's usual `###` sections
    'slashSections' => \Reporion\Support\ReportPath::isReport($path) ? explode('|', t('editor.slash.sections')) : [],
    'isReport' => \Reporion\Support\ReportPath::isReport($path),
    'ai' => $ai !== null ? [
        'actions' => $ai['actions'],
        'strings' => [
            'working' => t('editor.ai.working'),
            'asking' => t('editor.ai.asking'),
            'append' => t('editor.ai.append'),
            'copy' => t('editor.ai.copy'),
            'again' => t('ai.again'),
            'copied' => t('editor.tb.copied'),
            'close' => t('editor.ai.close'),
            'applied' => t('editor.ai.applied'),
            'failed' => t('editor.ai.failed'),
            'timeout' => t('editor.ai.timeout'),
            'selection' => t('editor.ai.selection'),
            'exam' => t('editor.ai.exam'),
            'text' => t('editor.ai.text'),
            'noText' => t('editor.ai.no_text'),
            'last' => [
                'action' => t('editor.ai.last.action'),
                'model' => t('editor.ai.last.model'),
                'time' => t('editor.ai.last.time'),
                'tokens' => t('editor.ai.last.tokens'),
                'speed' => t('editor.ai.last.speed'),
                'tries' => t('editor.ai.last.tries'),
                'failover' => t('editor.ai.last.failover'),
                'sent' => t('editor.ai.last.sent'),
                'error' => t('editor.ai.last.error'),
                'at' => t('editor.ai.last.at'),
            ],
        ],
    ] : null,
    'recall' => $recall !== null ? $recall + [
        'strings' => [
            'searching' => t('editor.recall.searching'),
            'forLine' => t('editor.recall.for_line'),
            'none' => t('editor.recall.none'),
            'failed' => t('editor.recall.failed'),
            'tooShort' => t('editor.recall.too_short'),
            'lineGone' => t('editor.recall.line_gone'),
            'mine' => t('editor.recall.mine'),
            'insert' => t('editor.recall.insert'),
            'open' => t('editor.recall.open'),
            'asking' => t('editor.recall.asking'),
            'aiFailed' => t('editor.ai.failed'),
            'replaceLine' => t('editor.recall.replace_line'),
        ],
    ] : null,
    'aids' => $aids ? [
        'strings' => [
            'checksNone' => t('editor.checks.none'),
            'checksFailed' => t('editor.checks.failed'),
            'jump' => t('editor.checks.jump'),
            'notFound' => t('editor.checks.not_found'),
            'priorNone' => t('editor.prior.none'),
            'priorFailed' => t('editor.prior.failed'),
            'priorOf' => t('editor.prior.of'),
            'priorWhole' => t('editor.prior.whole'),
            'priorSize' => t('editor.prior.size'),
            'open' => t('editor.recall.open'),
            'compare' => t('editor.prior.compare'),
            'comparePhrase' => t('editor.prior.compare_phrase'),
        ],
    ] : null,
    'priors' => $priorCandidates,
    'templates' => $templates,
    'template' => $template,
    // Each exam's own template, in exam order (the tab in front's goes first in the picker)
    'examTemplates' => $examTemplates ?? [],
    'snippets' => $snippets,
], JSON_HEX_TAG) ?></script>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/editor-format.js'), ENT_QUOTES) ?>" defer></script>
<?php foreach (array_unique(array_column($pluginTools, 'href')) as $toolScript): ?><script src="<?= htmlspecialchars($basePath . $toolScript, ENT_QUOTES) ?>" defer></script><?php endforeach; ?>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/editor-exams.js'), ENT_QUOTES) ?>" defer></script>
<?php if (!$raw && ($details['exams'] ?? null) !== null): ?><script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/editor-meta-exams.js'), ENT_QUOTES) ?>" defer></script><?php endif; ?>
<?php if (!$raw && \in_array('checklist', array_column($details['fields'] ?? [], 'key'), true)): ?><script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/details-checklist.js'), ENT_QUOTES) ?>" defer></script><?php endif; ?>
<?php if ($ai !== null || $recallAi): ?><script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/editor-ai.js'), ENT_QUOTES) ?>" defer></script><?php endif; ?>
<?php if ($recall !== null): ?><script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/editor-recall.js'), ENT_QUOTES) ?>" defer></script><?php endif; ?>
<?php if ($aids): ?><script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/editor-aids.js'), ENT_QUOTES) ?>" defer></script><?php endif; ?>
<?php if (($commenter ?? null) !== null && $baseRev > 0): ?><script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/comments.js'), ENT_QUOTES) ?>" defer></script><?php endif; ?>
<?php if ($checklists !== []): ?><script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/editor-checklist.js'), ENT_QUOTES) ?>" defer></script><?php endif; ?>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/editor.js'), ENT_QUOTES) ?>" defer></script>
<script>
(function() {
  var toggleTb = document.getElementById('editor-preview-toggle-tb');
  var preview = document.getElementById('editor-preview');
  var pane = document.getElementById('editor-pane');
  var opts = { basePath: <?= json_encode($basePath, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, examIds: false };
  // The same server renderer shows the assistant's `show` answers (editor.js):
  // done(true) once el holds the HTML, done(false) when it could not be had
  window.ReporionRenderMarkdown = function(markdown, el, done) {
    if (!window.ReporionRender) return false;
    var mine = el.reporionRender = (el.reporionRender || 0) + 1;
    ReporionRender.html(opts.basePath, markdown, false).then(function(html) {
      if (mine !== el.reporionRender) return;
      el.innerHTML = html;
      if (done) done(true);
    }, function() {
      if (mine === el.reporionRender && done) done(false);
    });
    return true;
  };
  // A text page (D40, format: text): the Metadata view's Format, or raw mode's frontmatter
  var formatPick = document.querySelector('select[name="fm[format]"]');
  function isText() {
    if (formatPick) return formatPick.value === 'text';
    var area = document.querySelector('[name="document"]');
    var fm = area ? /^---\n([\s\S]*?)\n---\n/.exec(area.value) : null;
    return !!fm && /^format:[ \t]*['"]?text['"]?[ \t]*$/m.test(fm[1]);
  }
  // No markdown to write on a text page: its formatting buttons go
  var MARKUP = ['heading', 'bold', 'italic', 'bullets', 'numbers', 'table', 'code', 'link', 'url', 'image'];
  function syncFormat() {
    var text = isText();
    MARKUP.forEach(function (action) {
      var b = document.querySelector('#editor-toolbar [data-tb="' + action + '"]');
      if (b) b.hidden = text;
    });
    // Nor does it have DokuWiki links to convert
    Array.prototype.forEach.call(document.querySelectorAll('#editor-ai [data-edit-tool]'), function (b) { b.hidden = text; });
    // Their separators too, and the count says what the text is
    Array.prototype.forEach.call(document.querySelectorAll('#editor-toolbar .wk-tsep'), function (sep) { sep.hidden = text; });
    var chars = document.getElementById('editor-chars');
    if (chars) {
      chars.setAttribute('data-format', text ? 'text' : 'markdown');
      var cfg = JSON.parse((document.getElementById('editor-config') || {}).textContent || '{}').strings || {};
      var label = text ? cfg.charsText : cfg.chars;
      var area = document.querySelector('[name="document"], [name="body"]');
      if (label && area) chars.textContent = label.replace('%d', String(area.value.length));
    }
    if (preview && !preview.hidden) show();
  }
  if (formatPick) formatPick.addEventListener('change', syncFormat);
  if (!toggleTb || !preview) { syncFormat(); return; }
  var seq = 0; // a newer render (or hide) makes an older answer stale
  // What the preview shows is what the text shows: with the exam tabs, the one in
  // front (the head above the first exam, or one exam), not the whole report
  function shown() {
    var areas = pane ? pane.querySelectorAll('textarea') : [];
    for (var i = 0; i < areas.length; i++) {
      if (!areas[i].hidden) return areas[i];
    }
    return document.querySelector('[name="document"], [name="body"]');
  }
  function show() {
    var area = shown();
    var doc = area.value;
    var mine = ++seq;
    if (isText()) {
      var pre = document.createElement('pre');
      pre.className = 'wk-plaintext';
      pre.textContent = window.ReporionRender ? ReporionRender.body(doc) : doc;
      preview.innerHTML = '';
      preview.appendChild(pre);
      preview.hidden = false;
      if (pane) pane.classList.add('wk-editpane-split');
      return;
    }
    if (!window.ReporionRender) return;
    var raw = area.name === 'document';
    // A multi-exam report's exams anchored as the page view does them (phase 12): when the
    // whole report is shown. Raw mode has the frontmatter in the text; the curated view keeps
    // the exams list as the Details panel's cards (a single ## has a card too: only a declared
    // list, or a second card added here, makes the report multi-exam)
    var fm = /^---\n([\s\S]*?)\n---\n/.exec(doc);
    var declared = (!!fm && /^exams:/m.test(fm[1])) || <?= json_encode(($details['examsDeclared'] ?? false) === true) ?> || document.querySelectorAll('[data-exam-cards] .wk-examcard').length > 1;
    var tabbed = !raw && area.name !== 'body';
    opts.examIds = declared && !tabbed && <?= json_encode(\Reporion\Support\ReportPath::isReport($path)) ?>;
    preview.hidden = false;
    if (pane) pane.classList.add('wk-editpane-split');
    ReporionRender.html(opts.basePath, raw ? ReporionRender.body(doc) : doc, opts.examIds, true).then(function(html) {
      if (mine !== seq) return;
      preview.innerHTML = html;
      // highlight.js is fetched by copy-code.js the first time a preview has code (layout.php)
      if (window.ReporionCopyCode) { ReporionCopyCode.highlight(preview); ReporionCopyCode.enhance(preview); }
      // The text and the preview scroll together, a click finds its line (assets/js/editor-sync.js)
      if (window.ReporionSync) ReporionSync.refresh();
    }, function() {
      if (mine !== seq) return;
      // The last render stays below the notice; typing and the local draft go on (D25)
      var old = preview.querySelector('.wk-notice');
      if (old) old.remove();
      var note = document.getElementById('editor-preview-offline');
      if (note) preview.insertBefore(note.content.cloneNode(true), preview.firstChild);
    });
  }
  function hide() {
    seq++;
    preview.hidden = true;
    if (pane) pane.classList.remove('wk-editpane-split');
  }
  toggleTb.addEventListener('click', function() {
    preview.hidden ? show() : hide();
  });
  // A tab brought forward (editor.js shows one pane, hides the others): the preview follows
  if (pane && window.MutationObserver) {
    new MutationObserver(function(records) {
      if (preview.hidden || !records.some(function(r) { return r.target !== pane && !preview.contains(r.target); })) return;
      clearTimeout(liveTimer);
      liveTimer = setTimeout(function() { if (!preview.hidden) show(); }, 60);
    }).observe(pane, { subtree: true, attributes: true, attributeFilter: ['hidden'], childList: true });
  }
  // Live: re-render shortly after the last edit while the split is open
  // (the exam panes and Details cards dispatch `input` too, editor.js)
  var liveTimer = null;
  document.addEventListener('input', function() {
    if (preview.hidden) return;
    clearTimeout(liveTimer);
    liveTimer = setTimeout(function() { if (!preview.hidden) show(); }, 250);
  });
  syncFormat();
})();
</script>
