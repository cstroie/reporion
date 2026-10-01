<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /{path}/print for a signed-in reader (Controller\ExportController::print()),
 * under the page header — design/mockup/WikiPrint.dc.html's sheet on a desk.
 * The sheet is an iframe onto /export/{path}.html: the very document dompdf
 * renders, with its own print.css, so screen CSS never touches it (D34) and
 * what is previewed is what is printed. Print prints the frame alone; without
 * JavaScript the button opens that document, for the browser's own Print.
 *
 * Variables in scope: string $basePath, $path, $docUrl; bool $canExport, $isDraft
 */

declare(strict_types=1);

/** @var string $basePath */
/** @var string $path */
/** @var string $docUrl */
/** @var bool $canExport */
/** @var bool $isDraft */

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$export = $basePath . '/export/' . $path;
?>
<div class="wk-doc">
<div class="wk-doc-titlerow" style="align-items:center">
<h2 class="wk-sec-title"><?= $e(t('page.print_preview')) ?></h2>
<div class="wk-actions">
<?php if ($canExport): ?>
<a class="btn btn-secondary" href="<?= $e($export) ?>.odt" title="<?= $e(t('page.export_odt')) ?>"><i class="ph ph-file-doc"></i><span class="wk-btn-label">ODT</span></a>
<a class="btn btn-secondary" href="<?= $e($export) ?>.pdf" title="<?= $e(t('page.export_pdf')) ?>"><i class="ph ph-file-pdf"></i><span class="wk-btn-label">PDF</span></a>
<?php endif; ?>
<a class="btn btn-primary" href="<?= $e($docUrl) ?>" data-print title="<?= $e(t('print.button')) ?>"><i class="ph ph-printer"></i><span class="wk-btn-label"><?= $e(t('print.button')) ?></span></a>
</div>
</div>
<?php if (!$canExport): ?>
<div class="wk-notice" role="status"><i class="ph ph-info"></i><div><?= $e(t('print.draft_print_only')) ?></div></div>
<?php endif; ?>
<div class="wk-desk">
<div class="wk-sheet-fit"><div class="wk-sheet"><iframe id="print-sheet" src="<?= $e($docUrl) ?>" title="<?= $e(t('page.print_preview')) ?>"></iframe></div></div>
<p class="wk-sheet-caption wk-mono wk-dim wk-text-sm"><?= $e(t($isDraft ? 'print.caption_draft' : 'print.caption')) ?></p>
</div>
</div>
<script>
(function () {
  var frame = document.getElementById('print-sheet');
  var sheet = frame.parentNode;
  var box = sheet.parentNode;
  // The sheet is as tall as the document in it (no scrollbar inside the
  // paper), and scaled down whole where the column is narrower than A4
  function fit() {
    try {
      var doc = frame.contentDocument.documentElement;
      doc.style.overflow = 'hidden';
      frame.style.height = doc.scrollHeight + 'px';
    } catch (e) {}
    var scale = Math.min(1, box.clientWidth / sheet.offsetWidth);
    sheet.style.transform = scale < 1 ? 'scale(' + scale + ')' : '';
    sheet.style.marginLeft = scale < 1 ? '0' : '';
    box.style.height = scale < 1 ? (sheet.offsetHeight * scale) + 'px' : '';
    box.style.overflow = scale < 1 ? 'hidden' : '';
  }
  function print() { frame.contentWindow.focus(); frame.contentWindow.print(); }
  frame.addEventListener('load', function () {
    fit();
    try { new ResizeObserver(fit).observe(frame.contentDocument.documentElement); } catch (e) {}
  });
  window.addEventListener('resize', fit);
  fit();
  document.querySelectorAll('[data-print]').forEach(function (button) {
    button.addEventListener('click', function (event) { event.preventDefault(); print(); });
  });
  // Ctrl+P here prints the sheet, not the app around it
  document.addEventListener('keydown', function (event) {
    if ((event.ctrlKey || event.metaKey) && !event.shiftKey && event.key.toLowerCase() === 'p') { event.preventDefault(); print(); }
  });
})();
</script>
