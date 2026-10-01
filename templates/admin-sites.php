<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Admin → Sites (Controller\AdminSitesController) — content only: each
 * site's letterhead on printed reports and its devices, from
 * data/settings.yaml, else from conf/local.php (marked as such).
 *
 * Variables in scope: array<string, mixed> $values; array<string, bool> $fromFile;
 * bool $hasFile; bool $saved; ?string $error; string $adminTab, $basePath
 */

declare(strict_types=1);

/** @var array<string, mixed> $values */
/** @var array<string, bool> $fromFile */
/** @var bool $saved */
/** @var ?string $error */
/** @var string $basePath */

$b = htmlspecialchars($basePath, ENT_QUOTES);
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$source = static fn (string $key): string => $fromFile[$key] ?? false ? '' : ' <span class="wk-mono wk-dim wk-text-xs">' . htmlspecialchars(t('admin.settings.from_local'), ENT_QUOTES) . '</span>';
$notice = static function () use ($saved, $error, $e): string {
    if ($error !== null) {
        return '<div class="wk-notice wk-mb-3" role="alert"><i class="ph ph-warning"></i><div>' . $e($error) . '</div></div>';
    }

    return $saved ? '<div class="wk-notice wk-mb-3" role="status"><i class="ph ph-check"></i><div>' . $e(t('admin.settings.saved')) . '</div></div>' : '';
};
$sites = \is_array($values['sites']) ? $values['sites'] : [];
$sites['']  = ['name' => '', 'dept' => '', 'address' => '', 'phone' => '', 'accession_code' => '', 'devices' => []];
?>
<div class="wk-doc">
<div class="wk-doc-head">
<?= \Reporion\Http\Breadcrumb::render([['label' => t('nav.admin')], ['label' => t('admin.sites.title')]]) ?>
<h1 class="wk-doc-title"><?= $e(t('admin.sites.title')) ?></h1>
</div>
<?php include __DIR__ . '/admin-tabs.php'; ?>

<div class="wk-panel" id="sites">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.settings.sites')) ?></h2><p class="wk-dim"><?= $e(t('admin.settings.sites_help')) ?></p></hgroup><?= $source('sites') ?></header>
<?= $notice() ?>
<form action="<?= $b ?>/admin/sites" method="post">
<table class="table">
<thead><tr><th><?= $e(t('admin.settings.col_code')) ?></th><th><?= $e(t('admin.settings.col_letterhead')) ?></th><th><?= $e(t('admin.settings.col_devices')) ?></th><th></th></tr></thead>
<tbody>
<?php $i = 0; foreach ($sites as $code => $site): ?>
<tr>
<td class="wk-valign-top"><input class="input wk-inline-input wk-mono" type="text" name="sites[<?= $i ?>][code]" value="<?= $e((string) $code) ?>" placeholder="<?= $code === '' ? $e(t('admin.settings.new_site')) : '' ?>" style="max-width:141.5px"></td>
<td class="wk-valign-top"><div style="display:flex;flex-direction:column;gap:var(--space-2)">
<?php foreach (['name', 'dept', 'address', 'phone', 'accession_code'] as $f): ?>
<input class="input wk-inline-input" type="text" name="sites[<?= $i ?>][<?= $f ?>]" value="<?= $e((string) ($site[$f] ?? '')) ?>" placeholder="<?= $e(t('admin.settings.site_' . $f)) ?>" style="max-width:none">
<?php endforeach; ?>
</div></td>
<td class="wk-valign-top"><textarea class="input wk-mono" name="sites[<?= $i ?>][devices]" rows="4" style="min-width:308.5px;font-size:var(--text-sm)" placeholder="MV-MR-01 = Siemens Aera 1.5 T"><?php foreach ((array) ($site['devices'] ?? []) as $device => $deviceName): ?><?= $e((string) $device) ?> = <?= $e((string) $deviceName) ?>&#10;<?php endforeach; ?></textarea></td>
<td class="wk-valign-top"><?php if ($code !== ''): ?><label class="radio wk-text-sm"><input type="checkbox" name="sites[<?= $i ?>][remove]" value="1"><span class="dot"></span><?= $e(t('admin.settings.remove')) ?></label><?php endif; ?></td>
</tr>
<?php ++$i; endforeach; ?>
</tbody>
</table>
<p class="wk-mt-flush"><button class="btn btn-primary" type="submit"><i class="ph ph-check"></i><?= $e(t('admin.settings.save')) ?></button></p>
</form>
</div>
</div>
