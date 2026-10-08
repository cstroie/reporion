<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /x/dicom/file — pick one DICOM file; a script sends it as the request
 * body (POST /x/dicom/file, no multipart), then opens the guided new-report
 * form filled from its header. The file is kept only until that form has
 * read it.
 *
 * Variables in scope: int $maxMb; string $basePath
 */

declare(strict_types=1);

/** @var int $maxMb */
/** @var string $basePath */

$b = htmlspecialchars($basePath, ENT_QUOTES);
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
?>
<div class="wk-doc">
<div class="wk-doc-head">
<?= \Reporion\Http\Breadcrumb::render([['label' => t('dicom.name'), 'icon' => 'monitor']]) ?>
<div class="wk-doc-titlerow"><hgroup><h1 class="wk-doc-title"><?= $e(t('dicom.file.title')) ?></h1><p class="wk-dim"><?= $e(t('dicom.file.lead')) ?></p></hgroup><div class="wk-actions">
<a class="btn btn-secondary" href="<?= $b ?>/new" title="<?= $e(t('dicom.worklist.manual')) ?>"><i class="ph ph-pencil-simple-line"></i><span class="wk-btn-label"><?= $e(t('dicom.worklist.manual')) ?></span></a>
</div></div>
</div>
<noscript><div class="wk-notice" role="alert"><i class="ph ph-warning"></i><div><?= $e(t('dicom.file.need_js')) ?></div></div></noscript>
<div id="dicom-file-error" class="wk-notice wk-mb-4" role="alert" hidden><i class="ph ph-warning"></i><div id="dicom-file-error-text"></div></div>
<form id="dicom-file-form" class="group group-fill group-stack wk-mb-4" action="<?= $b ?>/x/dicom/file">
<span class="group-addon" aria-hidden="true"><i class="ph ph-file-arrow-up"></i></span>
<input class="input grow" id="dicom-file-input" type="file" accept=".dcm,.dicom,application/dicom" aria-label="<?= $e(t('dicom.file.choose')) ?>" required>
<button class="btn btn-primary" id="dicom-file-go" type="submit"><i class="ph ph-arrow-right"></i><?= $e(t('dicom.file.go')) ?></button>
</form>
<p class="wk-dim wk-text-sm"><?= $e(t('dicom.file.max', [(string) $maxMb])) ?></p>
</div>
<script>
(function () {
  var form = document.getElementById('dicom-file-form');
  var input = document.getElementById('dicom-file-input');
  var go = document.getElementById('dicom-file-go');
  var box = document.getElementById('dicom-file-error');
  var text = document.getElementById('dicom-file-error-text');
  var reading = <?= json_encode(t('dicom.file.reading'), JSON_HEX_TAG) ?>;
  var network = <?= json_encode(t('dicom.file.err_network'), JSON_HEX_TAG) ?>;
  var label = go.lastChild.textContent;
  function fail(message) { text.textContent = message; box.hidden = false; go.disabled = false; go.lastChild.textContent = label; }
  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var file = input.files && input.files[0];
    if (!file) return;
    box.hidden = true;
    go.disabled = true;
    go.lastChild.textContent = reading;
    // The file as it is, the body of the request (no multipart)
    fetch(form.action, { method: 'POST', body: file, credentials: 'same-origin', headers: { 'Content-Type': 'application/octet-stream' } })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (res) {
        if (res.ok && res.body.url) { window.location.href = res.body.url; return; }
        fail(res.body.error || network);
      })
      .catch(function () { fail(network); });
  });
})();
</script>
