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
 * Not rendered until they work: the AI rail (D15 — hidden while no
 * provider is configured), and "sign on save" (signing is its own
 * action). "Minor edit" is wired (Storage\FlatFile::saveMinor(),
 * invariant 3's one exception) — hidden for a page's first save
 * (baseRev === 0, nothing to squash into yet) and for a signed page
 * (a signature covers its revision's exact bytes, D3).
 * The preview is marked.js (vendored, the version the D17 conformance test
 * runs) configured by assets/js/markdown-preview.js: body only, raw HTML
 * escaped, unsafe URLs dropped — same as Service\Render.
 *
 * Content only: Http\View::page() wraps it in templates/layout.php with
 * $editorShell set — the mockup's full-bleed, full-height editor
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
/** @var list<array{path: string, title: string}> $templates */
/** @var string $template */
/** @var list<array{name: string, title: string, body: string, modality: bool}> $snippets */
/** @var string $basePath */
/** @var bool $canWrite */
/** @var ?\Reporion\Auth\User $principal */
/** @var ?array{actions: list<array{id: string, label: string, tooltip: string, icon: string, result: string, custom: bool}>, provider: string, external: bool} $ai */
/** @var list<array{exam: int, title: string, template: string, items: list<array{section: bool, label: string, keywords: list<string>}>}> $checklists */
$ai ??= null;
$checklists ??= [];
$references ??= [];
$rail = $ai !== null || $checklists !== [] || $references !== [];
$signed ??= false;
$status ??= 'draft';
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
<div class="wk-edit<?= $rail ? ' wk-has-ai' : '' ?>">
<div class="wk-edit-main">
<?php /* The mockup's crumbs line (WikiEditor .wk-crumbs): no page header or
 * tab row on this route — Cancel goes back to the report. #editor-status is
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
<span class="tag <?= \Reporion\Support\Badges::statusTag($status) ?>"><?php if ($status === 'signed'): ?><i class="ph ph-seal-check" aria-hidden="true"></i> <?php endif; ?><?= $e($status) ?></span>
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

<div id="editor-draft-banner" class="wk-notice" role="status" hidden><i class="ph ph-clock-counter-clockwise" aria-hidden="true"></i><div>
<?= htmlspecialchars(t('editor.draft_found'), ENT_QUOTES) ?> <span class="wk-mono" id="editor-draft-when"></span>
<span class="wk-draft-acts"><button type="button" class="btn btn-secondary btn-sm" id="editor-draft-restore"><?= htmlspecialchars(t('editor.draft_restore'), ENT_QUOTES) ?></button><button type="button" class="btn btn-ghost btn-sm" id="editor-draft-dismiss"><?= htmlspecialchars(t('editor.draft_dismiss'), ENT_QUOTES) ?></button></span>
</div></div>

<?php if (($savedRev ?? null) !== null && $error === null): /* Save keeps the editor open (Admin → Settings) */ ?>
<div class="wk-notice" role="status" id="editor-saved-notice"><i class="ph ph-check" aria-hidden="true"></i><div><?= htmlspecialchars(($savedNote ?? '') !== '' ? t('editor.saved_rev_note', [$savedRev, $savedNote]) : t('editor.saved_rev', [$savedRev]), ENT_QUOTES) ?></div></div>
<?php endif; ?>
<?php if ($error !== null): ?>
<p role="alert"><?= htmlspecialchars($error, ENT_QUOTES) ?><?php if (($existingPath ?? null) !== null): ?> <a href="<?= htmlspecialchars($basePath . '/' . $existingPath . '/edit', ENT_QUOTES) ?>"><?= htmlspecialchars(t('new.open_existing'), ENT_QUOTES) ?></a><?php endif; ?></p>
<?php endif; ?>

<?php if ($conflictDocument !== null): ?>
<div class="wk-panel" id="editor-conflict">
<header class="wk-panel-h"><h2 class="wk-eyebrow"><?= htmlspecialchars(t('editor.conflict_current'), ENT_QUOTES) ?></h2></header>
<pre class="wk-mono wk-difftext"><?= htmlspecialchars($conflictDocument, ENT_QUOTES) ?></pre>
</div>
<?php endif; ?>

<form action="<?= $formAction ?>" method="post" class="wk-edit-form" data-island="editor" data-config-id="editor-config">
<input type="hidden" name="base_rev" value="<?= $baseRev ?>">
<?php if (($carried ?? null) !== null): /* A guided report not written yet: its frontmatter, for the first Save */ ?><input type="hidden" name="carried" value="<?= htmlspecialchars($carried, ENT_QUOTES) ?>"><?php endif; ?>
<?php /* The Metadata view (2026-09-29): the Details form in place of the
 * toolbar and text, from the crumbs line's Metadata button — out of the
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

<?php /* A report's exam tabs (phase 12, assets/js/editor-exams.js): filled by the script, absent without it — normal edit only since 2026-10-07 (raw edit is the one whole document); the exams list is the Details panel's cards */ ?>
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
<input class="input wk-commit" type="text" id="note" name="note" autocomplete="off" placeholder="<?= htmlspecialchars(t('editor.note'), ENT_QUOTES) ?>"<?php if ($baseRev > 0 && !$signed): ?> data-last-note="<?= htmlspecialchars((string) ($lastNote ?? ''), ENT_QUOTES) ?>"<?php endif; ?>>
<?php if ($baseRev > 0 && !$signed): ?>
<label class="radio wk-minor" title="<?= htmlspecialchars(t('editor.minor_help'), ENT_QUOTES) ?>"><input type="checkbox" id="editor-minor" name="minor" value="1"><span class="dot"></span><?= htmlspecialchars(t('editor.minor'), ENT_QUOTES) ?></label>
<?php endif; ?>
<span class="wk-tflex"></span>
<a class="btn btn-secondary" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($path, ENT_QUOTES) ?>"><?= htmlspecialchars(t(($saveStaysOpen ?? false) ? 'editor.close' : 'editor.cancel'), ENT_QUOTES) ?></a>
<button class="btn btn-primary" type="submit" id="editor-save-btn" data-rev="<?= $baseRev ?>"><i class="ph ph-check"></i><span id="editor-save-label"><?= htmlspecialchars(t('editor.save', [$baseRev + 1]), ENT_QUOTES) ?></span></button>
</div>
<?php if ($ai !== null): ?><input type="hidden" name="ai_assisted" id="editor-ai-assisted" value=""><?php endif; ?>
</form>
</div>
<?php if ($rail): ?>
<?php /* The rail (2026-10-02): an accordion of the exams' reference page (phase 25), their
 * checklists (phase 26) and the Assistant (phase 15d, design/mockup/WikiEditor.dc.html .wk-ai:
 * it proposes, the doctor applies — A3, D8), one section open at a time — <details name>
 * does it natively, assets/js/editor-rail.js for older browsers and to remember the last
 * one opened. Opened by default: the checklist, else the Assistant, else the reference. */ ?>
<?php $railOpen = $checklists !== [] ? 'checklist' : ($ai !== null ? 'assistant' : 'reference'); ?>
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
<?php /* Phase 26: each exam's template checklist. Ticks are the doctor's own aid —
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
<li class="wk-check-item" data-keywords="<?= $e(json_encode($item['keywords'], JSON_UNESCAPED_UNICODE) ?: '[]') ?>"><label class="radio"><input type="checkbox" data-check="<?= (int) $list['exam'] . ':' . $i ?>"><span class="dot"></span><span class="wk-check-label"><?= $e($item['label']) ?></span></label><span class="wk-check-miss" title="<?= $e(t('editor.check.missing_hint')) ?>" hidden><?= $e(t('editor.check.missing')) ?></span></li>
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
<?php if ($ai !== null): ?>
<details class="wk-rail-sec" name="editor-rail" data-rail="assistant"<?= $railOpen === 'assistant' ? ' open' : '' ?>>
<summary class="wk-rail-head"><span class="wk-eyebrow"><i class="ph ph-sparkle"></i> <?= htmlspecialchars(t('editor.ai.title'), ENT_QUOTES) ?></span><span class="wk-mono wk-dim" title="<?= htmlspecialchars($ai['provider'], ENT_QUOTES) ?>"><?= htmlspecialchars($ai['server'], ENT_QUOTES) ?></span><i class="ph ph-caret-down wk-rail-caret"></i></summary>
<div class="wk-rail-body">
<div class="wk-ai-acts">
<?php foreach ($ai['actions'] as $action): ?>
<?php if ($action['section']): ?><hr class="wk-ai-sep"><?php endif; ?>
<?php if ($action['custom']): ?>
<div class="group wk-ai-custom"><input class="input" type="text" data-ai-prompt="<?= htmlspecialchars($action['id'], ENT_QUOTES) ?>" placeholder="<?= htmlspecialchars($action['tooltip'] !== '' ? $action['tooltip'] : $action['label'], ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars($action['label'], ENT_QUOTES) ?>"><button type="button" class="btn" data-ai-action="<?= htmlspecialchars($action['id'], ENT_QUOTES) ?>"><?= htmlspecialchars($action['label'], ENT_QUOTES) ?></button></div>
<?php else: ?>
<button type="button" class="wk-ai-btn" data-ai-action="<?= htmlspecialchars($action['id'], ENT_QUOTES) ?>" title="<?= htmlspecialchars($action['tooltip'], ENT_QUOTES) ?>"><?= $aiIcon($action['icon']) ?><?= htmlspecialchars($action['label'], ENT_QUOTES) ?></button>
<?php endif; ?>
<?php endforeach; ?>
</div>
<div class="wk-ai-ctx"><span class="wk-eyebrow"><?= htmlspecialchars(t('editor.ai.context'), ENT_QUOTES) ?></span><div class="wk-links" id="editor-ai-context"><span class="wk-chip wk-chip-off"><?= htmlspecialchars(t('editor.ai.no_identifiers'), ENT_QUOTES) ?></span></div><p class="wk-mono wk-dim"><?= htmlspecialchars(t($ai['external'] ? 'editor.ai.external' : 'editor.ai.local', [$ai['provider']]), ENT_QUOTES) ?></p></div>
</div>
</details>
<?php endif; ?>
</aside>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/editor-rail.js'), ENT_QUOTES) ?>" defer></script>
<?php if ($references !== []): ?><script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/reference-panel.js'), ENT_QUOTES) ?>" defer></script><?php endif; ?>
<?php endif; ?>
<?php if ($ai !== null): ?>
<?php /* result: show opens its answer here, rendered — one persistent
 * dialog, reset per run (editor.js's aiModal()); the other results write
 * straight into the text */ ?>
<dialog class="wk-modal wk-modal-wide" id="editor-ai-modal" aria-labelledby="editor-ai-modal-title">
<header><h2 class="wk-eyebrow" id="editor-ai-modal-title"></h2><span class="wk-mono wk-dim" id="editor-ai-modal-meta"></span><button type="button" class="wk-tbtn" data-modal-close title="<?= htmlspecialchars(t('drawer.close'), ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('drawer.close'), ENT_QUOTES) ?>"><i class="ph ph-x"></i></button></header>
<div class="wk-modal-main" tabindex="-1" autofocus><div class="wk-ai-text" id="editor-ai-modal-body"></div></div>
<footer id="editor-ai-modal-row"></footer>
</dialog>
<?php endif; ?>
</div>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'marked.js'), ENT_QUOTES) ?>" defer></script>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/markdown-preview.js'), ENT_QUOTES) ?>" defer></script>
<script type="application/json" id="editor-config"><?= json_encode([
    'basePath' => $basePath,
    'path' => $path,
    'baseRev' => $baseRev,
    'saveStaysOpen' => $saveStaysOpen ?? false,
    'savedRev' => $savedRev ?? null,
    'strings' => [
        'saveRevNext' => t('editor.save', [$baseRev + 1]),
        'saveRevSame' => t('editor.save', [$baseRev]),
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
        'charsText' => t('editor.tb.chars_text'),
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
        ],
    ] : null,
    'priors' => $priorCandidates,
    'templates' => $templates,
    'template' => $template,
    'snippets' => $snippets,
], JSON_HEX_TAG) ?></script>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/editor-format.js'), ENT_QUOTES) ?>" defer></script>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/editor-exams.js'), ENT_QUOTES) ?>" defer></script>
<?php if (!$raw && ($details['exams'] ?? null) !== null): ?><script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/editor-meta-exams.js'), ENT_QUOTES) ?>" defer></script><?php endif; ?>
<?php if (!$raw && \in_array('checklist', array_column($details['fields'] ?? [], 'key'), true)): ?><script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/details-checklist.js'), ENT_QUOTES) ?>" defer></script><?php endif; ?>
<?php if ($ai !== null): ?><script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/editor-ai.js'), ENT_QUOTES) ?>" defer></script><?php endif; ?>
<?php if ($checklists !== []): ?><script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/editor-checklist.js'), ENT_QUOTES) ?>" defer></script><?php endif; ?>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/editor.js'), ENT_QUOTES) ?>" defer></script>
<script>
(function() {
  var toggleTb = document.getElementById('editor-preview-toggle-tb');
  var preview = document.getElementById('editor-preview');
  var pane = document.getElementById('editor-pane');
  var configured = false; // marked.js is deferred: configure on first use
  var opts = { basePath: <?= json_encode($basePath, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, examIds: false };
  // The same marked.js setup renders the assistant's `show` answers (editor.js)
  window.ReporionRenderMarkdown = function(markdown, el) {
    if (!window.marked || !window.ReporionPreview) return false;
    if (!configured) { ReporionPreview.configure(marked, opts); configured = true; }
    opts.examIds = false;
    el.innerHTML = marked.parse(markdown);
    ReporionPreview.sanitize(el);
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
  var MARKUP = ['heading', 'bold', 'italic', 'bullets', 'numbers', 'table', 'code', 'link', 'image'];
  function syncFormat() {
    var text = isText();
    MARKUP.forEach(function (action) {
      var b = document.querySelector('#editor-toolbar [data-tb="' + action + '"]');
      if (b) b.hidden = text;
    });
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
  function show() {
    var doc = document.querySelector('[name="document"], [name="body"]').value;
    if (isText()) {
      var pre = document.createElement('pre');
      pre.className = 'wk-plaintext';
      pre.textContent = window.ReporionPreview ? ReporionPreview.body(doc) : doc;
      preview.innerHTML = '';
      preview.appendChild(pre);
      preview.hidden = false;
      if (pane) pane.classList.add('wk-editpane-split');
      return;
    }
    if (!window.marked || !window.ReporionPreview) return;
    if (!configured) { ReporionPreview.configure(marked, opts); configured = true; }
    // A multi-exam report's exams anchored as the page view does them (phase 12)
    var fm = /^---\n([\s\S]*?)\n---\n/.exec(doc);
    opts.examIds = !!fm && /^exams:/m.test(fm[1]) && <?= json_encode(\Reporion\Support\ReportPath::isReport($path)) ?>;
    preview.innerHTML = marked.parse(ReporionPreview.body(doc));
    ReporionPreview.sanitize(preview);
    // highlight.js is fetched by copy-code.js the first time a preview has code (layout.php)
    if (window.ReporionCopyCode) { ReporionCopyCode.highlight(preview); ReporionCopyCode.enhance(preview); }
    preview.hidden = false;
    if (pane) pane.classList.add('wk-editpane-split');
  }
  function hide() {
    preview.hidden = true;
    if (pane) pane.classList.remove('wk-editpane-split');
  }
  toggleTb.addEventListener('click', function() {
    preview.hidden ? show() : hide();
  });
  syncFormat();
})();
</script>
