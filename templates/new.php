<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET/POST /new (Controller\NewPageController) — the create half of the
 * write UI; templates/editor.php is the edit half. Same .wk-doc shell
 * as design/mockup/WikiCreate.dc.html: .wk-doc > .wk-doc-head (crumbs +
 * action buttons) + .wk-two two-column grid with Path panel (.wk-pathb
 * segmented builder), Template panel (.wk-tpl-list), Visibility & access
 * panel (.seg radio buttons + ACL input), and Metadata prefill panel
 * (.wk-kv grid).
 *
 * Variables in scope: ?string $error; string $path, $document, $basePath
 */

declare(strict_types=1);

/** @var ?string $error */
/** @var string $path */
/** @var string $document */
/** @var string $basePath */

// Prefill the segmented path builder from $path (either the `ns` query
// param on GET, or whatever was submitted, on a re-rendered error) so the
// hidden field the JS below rebuilds from starts non-empty instead of
// silently dropping the ns=… prefill or a resubmitted value.
$pathSegments = explode(':', trim($path, ':'));
if (($pathSegments[0] ?? '') === 'reports') {
    array_shift($pathSegments);
}
$segModality = $pathSegments[0] ?? '';
$segSite = $pathSegments[1] ?? '';
$segDateName = $pathSegments[2] ?? '';
if (str_contains($segDateName, '-')) {
    [$segDate, $segName] = explode('-', $segDateName, 2);
} else {
    $segDate = '';
    $segName = $segDateName;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(t('new.title'), ENT_QUOTES) ?> — <?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?></title>
<link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/css/tokens.css">
<link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/css/wiki.css">
<link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/css/phosphor.css">
</head>
<body class="wk">
<div class="wk-top">
<span class="wk-brand"><?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?></span>
<form class="wk-search" action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/search" method="get" data-island="palette" data-config-id="palette-config">
<input type="search" name="q" placeholder="<?= htmlspecialchars(t('nav.search'), ENT_QUOTES) ?>">
</form>
<script type="application/json" id="palette-config"><?= json_encode(['basePath' => $basePath], JSON_HEX_TAG) ?></script>
</div>
<main class="wk-pad">
<div class="wk-doc">
<div class="wk-doc-head">
<div class="wk-crumbs wk-mono"><i class="ph ph-plus"></i><b><?= htmlspecialchars(t('new.title'), ENT_QUOTES) ?></b><i class="ph ph-caret-right"></i><span><?= htmlspecialchars(t('new.from_order'), ENT_QUOTES) ?></span></div>
<div class="wk-doc-titlerow"><h1 class="wk-doc-title"><?= htmlspecialchars(t('new.title'), ENT_QUOTES) ?></h1><div class="wk-actions"><a class="btn btn-ghost" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/"><?= htmlspecialchars(t('editor.cancel'), ENT_QUOTES) ?></a><button class="btn btn-secondary" type="button"><?= htmlspecialchars(t('new.save_draft'), ENT_QUOTES) ?></button><button class="btn btn-primary" type="submit" form="new-page-form"><i class="ph ph-arrow-right"></i><?= htmlspecialchars(t('new.create_open'), ENT_QUOTES) ?></button></div></div>
</div>
<?php if ($error !== null): ?>
<p role="alert"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
<?php endif; ?>
<form id="new-page-form" action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/new" method="post">
<input type="hidden" name="path" id="new-page-path" value="<?= htmlspecialchars($path, ENT_QUOTES) ?>">
<textarea name="document" hidden><?= htmlspecialchars($document, ENT_QUOTES) ?></textarea>
<div class="wk-two">
<div>
<div class="wk-panel">
<div class="wk-panel-h"><span class="wk-eyebrow"><?= htmlspecialchars(t('new.path'), ENT_QUOTES) ?></span><span class="wk-mono wk-dim">reports:{modality}:{site}:{yymmdd}-{name}</span></div>
<div class="wk-pathb"><span class="wk-dim">reports</span><span>:</span><input class="input wk-mono" name="modality" value="<?= htmlspecialchars($segModality, ENT_QUOTES) ?>" style="width:64px" placeholder="mri" /><span>:</span><input class="input wk-mono" name="site" value="<?= htmlspecialchars($segSite, ENT_QUOTES) ?>" style="width:96px" placeholder="mioveni" /><span>:</span><input class="input wk-mono" name="date" value="<?= htmlspecialchars($segDate, ENT_QUOTES) ?>" style="width:76px" placeholder="260922" /><span>-</span><input class="input wk-mono" name="name" value="<?= htmlspecialchars($segName, ENT_QUOTES) ?>" style="width:150px" placeholder="vasilescu-radu" /></div>
<p class="wk-mono wk-dim" style="margin:var(--space-3) 0 0;font-size:11.5px"><i class="ph ph-check-circle wk-ok-i"></i> <?= htmlspecialchars(t('new.path_free'), ENT_QUOTES) ?><br><i class="ph ph-info"></i> <?= htmlspecialchars(t('new.path_patient_note'), ENT_QUOTES) ?></p>
</div>
<div class="wk-panel">
<div class="wk-panel-h"><span class="wk-eyebrow"><?= htmlspecialchars(t('new.template'), ENT_QUOTES) ?></span><button class="btn btn-ghost btn-sm"><?= htmlspecialchars(t('new.template_browse'), ENT_QUOTES) ?></button></div>
<div class="wk-tpl-list">
<div class="wk-tpl-i wk-sel"><b><?= htmlspecialchars(t('new.tpl_rm_sm'), ENT_QUOTES) ?></b><span class="wk-mono wk-dim">templates:mri:cerebral-sm · 6 sections · 214 uses</span></div>
<div class="wk-tpl-i"><b><?= htmlspecialchars(t('new.tpl_rm_nativ'), ENT_QUOTES) ?></b><span class="wk-mono wk-dim">templates:mri:cerebral-nativ · 5 sections</span></div>
<div class="wk-tpl-i"><b><?= htmlspecialchars(t('new.tpl_empty'), ENT_QUOTES) ?></b><span class="wk-mono wk-dim">frontmatter only</span></div>
<div class="wk-tpl-i"><b><?= htmlspecialchars(t('new.tpl_dup'), ENT_QUOTES) ?></b><span class="wk-mono wk-dim">copy body + metadata, new path</span></div>
</div>
</div>
<div class="wk-panel">
<div class="wk-panel-h"><span class="wk-eyebrow"><?= htmlspecialchars(t('new.visibility'), ENT_QUOTES) ?></span></div>
<div class="wk-form">
<span class="seg"><label class="seg-opt"><input type="radio" name="nv" checked="checked" /><i class="ph ph-lock-simple"></i><?= htmlspecialchars(t('vis.private'), ENT_QUOTES) ?></label><label class="seg-opt"><input type="radio" name="nv" /><i class="ph ph-link-simple"></i><?= htmlspecialchars(t('vis.unlisted'), ENT_QUOTES) ?></label><label class="seg-opt"><input type="radio" name="nv" /><i class="ph ph-globe"></i><?= htmlspecialchars(t('vis.public'), ENT_QUOTES) ?></label></span>
<div class="field"><label for="acl"><?= htmlspecialchars(t('new.acl_label'), ENT_QUOTES) ?></label><input class="input wk-mono" id="acl" value="@radiology:rw @mioveni:rw @referrers:r" /></div>
<label class="radio"><input type="checkbox" checked="checked" /><span class="dot"></span><?= htmlspecialchars(t('new.notify_referrer'), ENT_QUOTES) ?></label>
</div>
</div>
</div>
<div class="wk-panel">
<div class="wk-panel-h"><span class="wk-eyebrow"><?= htmlspecialchars(t('new.metadata'), ENT_QUOTES) ?></span><span class="wk-mono wk-dim">hl7-order-bridge</span></div>
<div class="wk-kv" style="grid-template-columns:auto minmax(0,1fr)">
<span>accession</span><b class="wk-mono">MV-RM-26-0921</b>
<span>patient</span><b class="wk-mono">VASILESCU RADU · 1968 · M</b>
<span>study date</span><b>22 Sep 2026, 11:40</b>
<span>modality</span><b>MR</b>
<span>region</span><b>spine — lombar</b>
<span>device</span><b>Siemens Aera 1.5 T · MV-MR-01</b>
<span>site</span><b>Mioveni</b>
<span>referrer</span><b>dr. L. Sandu — Neurochirurgie</b>
<span>protocol</span><b>spine-lumbar-v2</b>
<span>summary</span><b class="wk-dim">— generated on first save</b>
<span>tags</span><b><span class="wk-chip">lombar</span><span class="wk-chip">hernie</span><span class="wk-chip wk-chip-on">+ suggest</span></b>
</div>
<p class="wk-mono wk-dim" style="font-size:11px;margin-top:var(--space-4)"><?= htmlspecialchars(t('new.metadata_note'), ENT_QUOTES) ?></p>
</div>
</div>
</form>
</div>
</main>
<script>
(function(){
  var form=document.getElementById('new-page-form');
  var inputs=document.querySelectorAll('#new-page-form .wk-pathb .input');
  var pathInput=document.getElementById('new-page-path');
  if(!inputs.length||!pathInput)return;
  function update(){
    var modality=form.modality.value.trim();
    var site=form.site.value.trim();
    var date=form.date.value.trim();
    var name=form.name.value.trim();
    var dateName=[date,name].filter(function(v){return v!=='';}).join('-');
    var segments=['reports',modality,site,dateName].filter(function(v){return v!=='';});
    pathInput.value=segments.join(':');
  }
  inputs.forEach(function(i){i.addEventListener('input',update);});
  update();
})();
</script>
</body>
</html>
