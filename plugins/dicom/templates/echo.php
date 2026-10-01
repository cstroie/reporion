<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /x/dicom/echo[?site=] — owner only: a C-ECHO (echoscu) to every
 * configured PACS, or to one site (its Test button in Admin → Plugins and
 * here), as the AE title we present to that site's PACS. A failure shows
 * echoscu's own log — an echo carries no patient data.
 *
 * Variables in scope: array<string, ?string> $results (site code → null when
 * it answered, else an error code; only the sites tested); array<string, string> $logs;
 * array $servers; ?string $unconfigured; string $basePath
 */

declare(strict_types=1);

/** @var array<string, ?string> $results */
/** @var array<string, string> $logs */
/** @var array<string, array{host: string, port: int, aet: string, calling: string}> $servers */
/** @var ?string $unconfigured */
/** @var string $basePath */

$b = htmlspecialchars($basePath, ENT_QUOTES);
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
?>
<div class="wk-doc">
<div class="wk-doc-head">
<div class="wk-crumbs wk-mono"><i class="ph ph-monitor"></i><b><?= $e(t('dicom.name')) ?></b></div>
<div class="wk-doc-titlerow"><hgroup><h1 class="wk-doc-title"><?= $e(t('dicom.echo.title')) ?></h1><p class="wk-dim"><?= $e(t('dicom.echo.subtitle')) ?></p></hgroup><div class="wk-actions">
<a class="btn btn-secondary" href="<?= $b ?>/x/dicom/echo" title="<?= $e(t('dicom.echo.all')) ?>"><i class="ph ph-arrow-clockwise"></i><span class="wk-btn-label"><?= $e(t('dicom.echo.all')) ?></span></a>
</div></div>
</div>
<?php if ($unconfigured !== null): ?>
<div class="wk-notice wk-mb-4" role="alert"><i class="ph ph-warning"></i><div><?= $e(t('dicom.echo.unconfigured', [$unconfigured])) ?></div></div>
<?php endif; ?>
<?php if ($servers === []): ?>
<div class="wk-notice" role="alert"><i class="ph ph-warning"></i><div><?= $e(t('dicom.err.not-configured')) ?></div></div>
<?php else: ?>
<div class="wk-panel">
<header class="wk-panel-h"><div class="wk-panel-title"><h2 class="wk-eyebrow"><?= $e(t('dicom.echo.servers')) ?></h2><span class="wk-count"><?= \count($servers) ?></span></div></header>
<table class="table table-cards">
<thead><tr><th><?= $e(t('dicom.col.site')) ?></th><th><?= $e(t('dicom.echo.server')) ?></th><th></th><th></th></tr></thead>
<tbody>
<?php foreach ($servers as $code => $server): ?>
<tr><td class="wk-mono" data-label="<?= $e(t('dicom.col.site')) ?>"><div class="wk-cell"><?= $e($code) ?></div></td><td class="wk-mono" data-label="<?= $e(t('dicom.echo.server')) ?>"><div class="wk-cell"><?= $e($server['calling'] . ' → ' . $server['aet'] . ' @ ' . $server['host'] . ':' . $server['port']) ?></div></td>
<td data-label="<?= $e(t('dicom.echo.status')) ?>"><div class="wk-cell"><?php if (!\array_key_exists($code, $results)): ?><span class="wk-dim"><?= $e(t('dicom.echo.not_tested')) ?></span><?php elseif ($results[$code] === null): ?><span class="tag tag-signed"><?= $e(t('dicom.echo.ok')) ?></span><?php else: ?><span class="tag tag-caution"><?= $e(t('dicom.err.' . $results[$code])) ?></span><?php endif; ?></div></td>
<td class="wk-right"><a class="btn btn-secondary btn-sm" href="<?= $b ?>/x/dicom/echo?site=<?= $e(rawurlencode($code)) ?>"><i class="ph ph-plugs-connected"></i><?= $e(t('dicom.echo.test')) ?></a></td></tr>
<?php if (($logs[$code] ?? '') !== ''): ?>
<tr><td colspan="4"><details open><summary class="wk-dim"><?= $e(t('dicom.echo.log')) ?></summary><pre class="wk-mono wk-log"><?= $e($logs[$code]) ?></pre></details></td></tr>
<?php endif; ?>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</div>
