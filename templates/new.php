<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET/POST /new (Controller\NewPageController) — the create half of the
 * write UI; templates/editor.php is the edit half. Same .wk-doc shell as
 * design/mockup/WikiCreate.dc.html (.wk-doc-head, .wk-panel, .wk-pathb).
 *
 * Only the Path panel is built. The mockup's template picker, visibility &
 * access panel and HL7 metadata prefill have no backend yet and are left
 * out rather than shown with sample data; the page starts private
 * (SCAFFOLD) and everything else is set in the editor right after.
 *
 * $segments non-null: the reports builder — four inputs the server
 * assembles into reports:{modality}:{site}:{yymmdd}-{name} (no JS needed).
 * $segments null: one plain colon-path field.
 *
 * Variables in scope: ?string $error; string $path, $document, $basePath;
 * ?array<string, string> $segments
 */

declare(strict_types=1);

/** @var ?string $error */
/** @var string $path */
/** @var string $document */
/** @var ?array<string, string> $segments */
/** @var string $basePath */
?>
<div class="wk-doc">
<div class="wk-doc-head">
<?= \Reporion\Http\Breadcrumb::render($crumbs) ?>
<div class="wk-doc-titlerow"><h1 class="wk-doc-title"><?= htmlspecialchars(t('new.title'), ENT_QUOTES) ?></h1></div>
</div>
<?php if (($duplicateOf ?? null) !== null): ?>
<div class="wk-notice" role="status"><i class="ph ph-copy-simple"></i><div><?= str_replace('{path}', '<span class="wk-mono">' . htmlspecialchars($duplicateOf, ENT_QUOTES) . '</span>', htmlspecialchars(t(($duplicateIsReport ?? false) ? 'dup.note' : 'dup.note_page', ['{path}']), ENT_QUOTES)) ?></div></div>
<?php endif; ?>
<?php if ($error !== null): ?>
<p role="alert"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
<?php endif; ?>
<form id="new-page-form" action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/new" method="post">
<textarea name="document" hidden><?= htmlspecialchars($document, ENT_QUOTES) ?></textarea>
<?php if (($duplicateOf ?? null) !== null): ?><input type="hidden" name="from" value="<?= htmlspecialchars($duplicateOf, ENT_QUOTES) ?>"><?php endif; ?>
<div class="wk-panel">
<?php if ($segments !== null): ?>
<input type="hidden" name="builder" value="1">
<header class="wk-panel-h"><h2 class="wk-eyebrow"><?= htmlspecialchars(t('new.path'), ENT_QUOTES) ?></h2><span class="wk-mono wk-dim">reports:{modality}:{site}:{yymmdd}-{name}</span></header>
<div class="wk-pathb"><span class="wk-dim">reports</span><span>:</span><input class="input wk-mono" name="modality" value="<?= htmlspecialchars($segments['modality'], ENT_QUOTES) ?>" style="width:82.5px" placeholder="modality" aria-label="modality" /><span>:</span><input class="input wk-mono" name="site" value="<?= htmlspecialchars($segments['site'], ENT_QUOTES) ?>" style="width:123.5px" placeholder="site" aria-label="site" /><span>:</span><input class="input wk-mono" name="date" value="<?= htmlspecialchars($segments['date'], ENT_QUOTES) ?>" style="width:97.5px" placeholder="yymmdd" aria-label="yymmdd" /><span>-</span><input class="input wk-mono" name="name" value="<?= htmlspecialchars($segments['name'], ENT_QUOTES) ?>" style="width:193px" placeholder="name" aria-label="name" /></div>
<p class="wk-mono wk-dim wk-text-sm wk-mt-flush"><a href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/new?mode=path"><?= htmlspecialchars(t('new.other_namespace'), ENT_QUOTES) ?></a></p>
<?php else: ?>
<header class="wk-panel-h"><label class="wk-eyebrow" for="new-page-path"><?= htmlspecialchars(t('new.path'), ENT_QUOTES) ?></label><span class="wk-mono wk-dim">namespace:page</span></header>
<input class="input wk-mono" type="text" id="new-page-path" name="path" value="<?= htmlspecialchars($path, ENT_QUOTES) ?>" autocomplete="off" style="width:100%">
<?php endif; ?>
<footer>
<a class="btn btn-secondary" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/"><?= htmlspecialchars(t('editor.cancel'), ENT_QUOTES) ?></a>
<button class="btn btn-primary" type="submit"><i class="ph ph-arrow-right"></i><?= htmlspecialchars(t('new.create_open'), ENT_QUOTES) ?></button>
</footer>
</div>
</form>
</div>
