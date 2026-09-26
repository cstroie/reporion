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
<div class="wk-crumbs wk-mono"><i class="ph ph-plus"></i><b><?= htmlspecialchars(t('new.title'), ENT_QUOTES) ?></b></div>
<div class="wk-doc-titlerow"><h1 class="wk-doc-title"><?= htmlspecialchars(t('new.title'), ENT_QUOTES) ?></h1><div class="wk-actions"><a class="btn btn-ghost" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/"><?= htmlspecialchars(t('editor.cancel'), ENT_QUOTES) ?></a><button class="btn btn-primary" type="submit" form="new-page-form"><i class="ph ph-arrow-right"></i><?= htmlspecialchars(t('new.create_open'), ENT_QUOTES) ?></button></div></div>
</div>
<?php if (($duplicateOf ?? null) !== null): ?>
<div class="wk-notice" role="status"><i class="ph ph-copy-simple"></i><div><?= htmlspecialchars(t('dup.note', [$duplicateOf]), ENT_QUOTES) ?></div></div>
<?php endif; ?>
<?php if ($error !== null): ?>
<p role="alert"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
<?php endif; ?>
<form id="new-page-form" action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/new" method="post">
<textarea name="document" hidden><?= htmlspecialchars($document, ENT_QUOTES) ?></textarea>
<div class="wk-panel">
<?php if ($segments !== null): ?>
<input type="hidden" name="builder" value="1">
<div class="wk-panel-h"><span class="wk-eyebrow"><?= htmlspecialchars(t('new.path'), ENT_QUOTES) ?></span><span class="wk-mono wk-dim">reports:{modality}:{site}:{yymmdd}-{name}</span></div>
<div class="wk-pathb"><span class="wk-dim">reports</span><span>:</span><input class="input wk-mono" name="modality" value="<?= htmlspecialchars($segments['modality'], ENT_QUOTES) ?>" style="width:64px" placeholder="modality" aria-label="modality" /><span>:</span><input class="input wk-mono" name="site" value="<?= htmlspecialchars($segments['site'], ENT_QUOTES) ?>" style="width:96px" placeholder="site" aria-label="site" /><span>:</span><input class="input wk-mono" name="date" value="<?= htmlspecialchars($segments['date'], ENT_QUOTES) ?>" style="width:76px" placeholder="yymmdd" aria-label="yymmdd" /><span>-</span><input class="input wk-mono" name="name" value="<?= htmlspecialchars($segments['name'], ENT_QUOTES) ?>" style="width:150px" placeholder="name" aria-label="name" /></div>
<p class="wk-mono wk-dim" style="margin:var(--space-3) 0 0;font-size:11.5px"><a href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/new?mode=path"><?= htmlspecialchars(t('new.other_namespace'), ENT_QUOTES) ?></a></p>
<?php else: ?>
<div class="wk-panel-h"><label class="wk-eyebrow" for="new-page-path"><?= htmlspecialchars(t('new.path'), ENT_QUOTES) ?></label><span class="wk-mono wk-dim">namespace:page</span></div>
<input class="input wk-mono" type="text" id="new-page-path" name="path" value="<?= htmlspecialchars($path, ENT_QUOTES) ?>" autocomplete="off" style="width:100%">
<?php endif; ?>
</div>
</form>
</div>
