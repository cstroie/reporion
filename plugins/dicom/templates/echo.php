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
<div class="wk-doc-titlerow"><h1 class="wk-doc-title"><?= $e(t('dicom.echo.title')) ?></h1><div class="wk-actions">
<a class="btn btn-ghost" href="<?= $b ?>/admin/plugins#plugin-dicom"><?= $e(t('admin.plugins.title')) ?></a>
<a class="btn btn-secondary" href="<?= $b ?>/x/dicom/echo"><i class="ph ph-arrow-clockwise"></i><?= $e(t('dicom.echo.all')) ?></a>
</div></div>
</div>
<?php if ($unconfigured !== null): ?>
<div class="wk-notice" role="alert"><i class="ph ph-warning"></i><div><?= $e(t('dicom.echo.unconfigured', [$unconfigured])) ?></div></div>
<?php endif; ?>
<?php if ($servers === []): ?>
<div class="wk-notice" role="alert"><i class="ph ph-warning"></i><div><?= $e(t('dicom.err.not-configured')) ?></div></div>
<?php else: ?>
<table class="table">
<thead><tr><th><?= $e(t('dicom.col.site')) ?></th><th><?= $e(t('dicom.echo.server')) ?></th><th></th><th></th></tr></thead>
<tbody>
<?php foreach ($servers as $code => $server): ?>
<tr><td class="wk-mono"><?= $e($code) ?></td><td class="wk-mono"><?= $e($server['calling'] . ' → ' . $server['aet'] . ' @ ' . $server['host'] . ':' . $server['port']) ?></td>
<td><?php if (!\array_key_exists($code, $results)): ?><span class="wk-dim"><?= $e(t('dicom.echo.not_tested')) ?></span><?php elseif ($results[$code] === null): ?><span class="tag tag-signed"><?= $e(t('dicom.echo.ok')) ?></span><?php else: ?><span class="tag tag-caution"><?= $e(t('dicom.err.' . $results[$code])) ?></span><?php endif; ?></td>
<td style="text-align:right"><a class="btn btn-primary btn-sm" href="<?= $b ?>/x/dicom/echo?site=<?= $e(rawurlencode($code)) ?>"><i class="ph ph-plugs-connected"></i><?= $e(t('dicom.echo.test')) ?></a></td></tr>
<?php if (($logs[$code] ?? '') !== ''): ?>
<tr><td colspan="4"><details open><summary class="wk-dim"><?= $e(t('dicom.echo.log')) ?></summary><pre class="wk-mono" style="white-space:pre-wrap;margin:var(--space-2) 0 0;font-size:12px"><?= $e($logs[$code]) ?></pre></details></td></tr>
<?php endif; ?>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>
