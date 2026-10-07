<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /x/dicom/worklist — studies from each site's PACS, filtered by site,
 * modality, patient name (optional) and date range (a POST form, so a name is never in a URL; works without JavaScript). "Start" opens
 * the guided new-report form filled from the study; a study that already
 * has a report links to it.
 *
 * Variables in scope: array{rows: list<array<string, mixed>>, errors: array<string, string>} $list;
 * array<string, array> $servers; ?string $site; list<string> $modalities; ?string $modality; string $name, $from, $to; bool $invalidRange, $queried, $isOwner; string $basePath
 */

declare(strict_types=1);

/** @var array{rows: list<array<string, mixed>>, errors: array<string, string>} $list */
/** @var array<string, array{host: string, port: int, aet: string, calling: string}> $servers */
/** @var ?string $site */
/** @var list<string> $modalities */
/** @var ?string $modality */
/** @var string $name */
/** @var string $from */
/** @var string $to */
/** @var bool $invalidRange */
/** @var bool $queried */
/** @var bool $isOwner */
/** @var string $basePath */

$b = htmlspecialchars($basePath, ENT_QUOTES);
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
?>
<div class="wk-doc">
<div class="wk-doc-head">
<?= \Reporion\Http\Breadcrumb::render([['label' => t('dicom.name'), 'icon' => 'monitor']]) ?>
<div class="wk-doc-titlerow"><hgroup><h1 class="wk-doc-title"><?= $e(t('dicom.worklist.title')) ?></h1><p class="wk-dim"><?= $e(t('dicom.worklist.subtitle')) ?></p></hgroup><div class="wk-actions">
<?php if ($isOwner): ?><a class="btn btn-ghost" href="<?= $b ?>/x/dicom/echo" title="<?= $e(t('dicom.echo.title')) ?>"><i class="ph ph-plugs-connected"></i><span class="wk-btn-label"><?= $e(t('dicom.echo.title')) ?></span></a><?php endif; ?>
<a class="btn btn-secondary" href="<?= $b ?>/new" title="<?= $e(t('dicom.worklist.manual')) ?>"><i class="ph ph-pencil-simple-line"></i><span class="wk-btn-label"><?= $e(t('dicom.worklist.manual')) ?></span></a>
</div></div>
</div>
<?php if ($servers === []): ?>
<div class="wk-notice" role="alert"><i class="ph ph-warning"></i><div><?= $e(t('dicom.err.not-configured')) ?></div></div>
<?php else: ?>
<?php /* One joined bar: site, modality, from → to, Query (.group; one control per line on a narrow screen) */ ?>
<form class="group group-fill group-stack wk-mb-4" method="post" action="<?= $b ?>/x/dicom/worklist" data-busy>
<span class="group-addon" aria-hidden="true"><i class="ph ph-hospital"></i></span>
<select class="input grow" name="site" aria-label="<?= $e(t('dicom.col.site')) ?>"><option value=""><?= $e(t('dicom.worklist.all_sites')) ?></option><?php foreach (array_keys($servers) as $code): ?><option value="<?= $e($code) ?>"<?= $code === $site ? ' selected' : '' ?>><?= $e($code) ?></option><?php endforeach; ?></select>
<span class="group-addon" aria-hidden="true"><i class="ph ph-scan"></i></span>
<select class="input grow" name="modality" aria-label="<?= $e(t('dicom.col.modality')) ?>"><option value=""><?= $e(t('dicom.worklist.all_modalities', [implode(', ', $modalities)])) ?></option><?php foreach ($modalities as $code): ?><option value="<?= $e($code) ?>"<?= $code === $modality ? ' selected' : '' ?>><?= $e($code) ?></option><?php endforeach; ?></select>
<span class="group-addon" aria-hidden="true"><i class="ph ph-user"></i></span>
<input class="input grow" type="text" name="patient" value="<?= $e($patient) ?>" placeholder="<?= $e(t('dicom.worklist.patient')) ?>" aria-label="<?= $e(t('dicom.worklist.patient')) ?>" autocomplete="off" maxlength="120">
<span class="group-addon" aria-hidden="true"><i class="ph ph-calendar-blank"></i></span>
<input class="input wk-mono group-date" type="date" name="from" value="<?= $e($from) ?>" aria-label="<?= $e(t('dicom.worklist.from')) ?>">
<span class="group-addon" aria-hidden="true"><i class="ph ph-arrow-right"></i></span>
<input class="input wk-mono group-date" type="date" name="to" value="<?= $e($to) ?>" aria-label="<?= $e(t('dicom.worklist.to')) ?>">
<button class="btn" type="submit"><i class="ph ph-magnifying-glass"></i><?= $e(t('dicom.worklist.query')) ?></button>
</form>
<?php if (!$queried): ?>
<div class="wk-panel"><div class="wk-empty"><i class="ph ph-list-magnifying-glass"></i><p><?= $e(t('dicom.worklist.idle')) ?></p></div></div>
<?php else: ?>
<?php if ($invalidRange): ?>
<div class="wk-notice wk-mb-4" role="alert"><i class="ph ph-warning"></i><div><?= $e(t('dicom.worklist.bad_range')) ?></div></div>
<?php endif; ?>
<?php foreach ($list['errors'] as $code => $error): ?>
<div class="wk-notice wk-mb-4" role="alert"><i class="ph ph-warning"></i><div><b><?= $e((string) $code) ?></b> — <?= $e(t('dicom.err.' . $error)) ?></div></div>
<?php endforeach; ?>
<div class="wk-panel">
<header class="wk-panel-h"><div class="wk-panel-title"><h2 class="wk-eyebrow"><?= $e(t('dicom.worklist.results')) ?></h2><span class="wk-count"><?= \count($list['rows']) ?></span></div><span class="wk-mono wk-dim wk-text-sm"><?= $e($from === $to ? $from : $from . ' → ' . $to) ?></span></header>
<?php if ($list['rows'] === []): ?>
<div class="wk-empty"><i class="ph ph-magnifying-glass"></i><p><?= $e(t('dicom.worklist.empty')) ?></p></div>
<?php else: ?>
<?php /* Several studies of one patient → one multi-exam report. Needs JavaScript (the link is built from the ticks); without it the cells stay hidden and Start works as before */ ?>
<div class="wk-selbar" id="dicom-multi-bar" role="status" hidden><i class="ph ph-stack" aria-hidden="true"></i><div class="wk-selbar-text"><strong id="dicom-multi-count"></strong><span><?= $e(t('dicom.worklist.multi_help', [\Reporion\Plugin\Dicom\Pacs::MULTI_MAX])) ?></span></div><a class="btn btn-primary btn-sm" data-busy id="dicom-multi-go" href="<?= $b ?>/new"><i class="ph ph-plus" aria-hidden="true"></i><?= $e(t('dicom.worklist.multi')) ?></a></div>
<table class="table table-cards">
<thead><tr><th class="wk-multi" hidden></th><th><?= $e(t('dicom.col.when')) ?></th><th><?= $e(t('dicom.col.modality')) ?></th><th><?= $e(t('dicom.col.patient')) ?></th><th><?= $e(t('dicom.col.description')) ?></th><th><?= $e(t('dicom.col.site')) ?></th><th></th></tr></thead>
<tbody>
<?php foreach ($list['rows'] as $row): ?>
<tr>
<td class="wk-multi" hidden><?php if ($row['report'] === null): ?><label class="radio" style="flex-direction:row"><input type="checkbox" data-ref="<?= $e((string) $row['ref']) ?>" data-group="<?= $e((string) $row['group']) ?>" aria-label="<?= $e(t('dicom.worklist.pick')) ?>"><span class="dot"></span></label><?php endif; ?></td>
<td class="wk-mono wk-nowrap" data-label="<?= $e(t('dicom.col.when')) ?>"><div class="wk-cell"><?= $e((string) $row['when']) ?></div></td>
<td data-label="<?= $e(t('dicom.col.modality')) ?>"><div class="wk-cell"><span class="tag tag-outline"><?= $e((string) $row['modality']) ?></span></div></td>
<td data-label="<?= $e(t('dicom.col.patient')) ?>"><div class="wk-cell"><?= $e((string) $row['patient']) ?><?php if ($row['cnp'] === ''): ?> <span class="tag tag-caution" title="<?= $e(t('dicom.worklist.no_cnp_help')) ?>"><?= $e(t('dicom.worklist.no_cnp')) ?></span><?php endif; ?>
<?php $meta = trim((string) ($row['born'] ?? '') . ' ' . (string) ($row['sex'] ?? '')); if ($meta !== ''): ?><div class="wk-mono wk-dim wk-text-sm"><?= $e($meta) ?></div><?php endif; ?></div></td>
<td class="wk-dim" data-label="<?= $e(t('dicom.col.description')) ?>"><div class="wk-cell"><?= $e((string) $row['description']) ?><?php if ((string) $row['accession'] !== ''): ?><div class="wk-mono wk-text-sm"><?= $e((string) $row['accession']) ?></div><?php endif; ?></div></td>
<td class="wk-mono wk-dim" data-label="<?= $e(t('dicom.col.site')) ?>"><div class="wk-cell"><?= $e((string) $row['site']) ?></div></td>
<td class="wk-right">
<?php if ($row['report'] !== null): ?>
<a class="btn btn-ghost btn-sm" href="<?= $b ?>/<?= $e((string) $row['report']) ?>"><i class="ph ph-file-text"></i><?= $e(t('dicom.worklist.open')) ?></a>
<?php else: ?>
<a class="btn btn-primary btn-sm" data-busy href="<?= $b ?>/new?prefill=dicom&amp;ref=<?= $e(rawurlencode((string) $row['ref'])) ?>"><i class="ph ph-plus"></i><?= $e(t('dicom.worklist.start')) ?></a>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<script>
(function () {
  var boxes = Array.prototype.slice.call(document.querySelectorAll('input[data-ref]'));
  var bar = document.getElementById('dicom-multi-bar');
  if (boxes.length < 2 || !bar) return;
  var max = <?= (int) \Reporion\Plugin\Dicom\Pacs::MULTI_MAX ?>;
  var label = <?= json_encode(t('dicom.worklist.multi_count'), JSON_HEX_TAG) ?>;
  Array.prototype.forEach.call(document.querySelectorAll('.wk-multi'), function (n) { n.hidden = false; });
  function update() {
    var picked = boxes.filter(function (b) { return b.checked; });
    var group = picked.length ? picked[0].getAttribute('data-group') : null;
    boxes.forEach(function (b) {
      b.disabled = !b.checked && ((group !== null && b.getAttribute('data-group') !== group) || picked.length >= max);
    });
    boxes.forEach(function (b) { b.closest('tr').classList.toggle('wk-picked', b.checked); });
    bar.hidden = picked.length < 2;
    document.getElementById('dicom-multi-count').textContent = label.replace('%d', String(picked.length));
    document.getElementById('dicom-multi-go').href = <?= json_encode($basePath . '/new?prefill=dicom&ref=', JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?> + picked.map(function (b) { return encodeURIComponent(b.getAttribute('data-ref')); }).join(',');
  }
  // Ticking a study ticks the other studies of its patient, site, modality and day — one report holds them all; untick the ones that are not part of it
  boxes.forEach(function (b) {
    b.addEventListener('change', function () {
      if (b.checked) {
        var group = b.getAttribute('data-group');
        var count = boxes.filter(function (x) { return x.checked; }).length;
        boxes.forEach(function (x) {
          if (!x.checked && !x.disabled && x.getAttribute('data-group') === group && count < max) { x.checked = true; count++; }
        });
      }
      update();
    });
  });
  update();
})();
</script>
<?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>
</div>
