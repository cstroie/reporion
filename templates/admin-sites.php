<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Admin → Sites (Controller\AdminSitesController): a card per site, each its own form:
 * the letterhead on printed reports (with a preview of it), the accession code, and the site's devices as rows, the PACS scanner names and
 * institution texts linked to a device as chips (a ticked box: untick it to drop it).
 * From data/settings.yaml, else from conf/local.php (marked as such).
 *
 * Variables in scope: list<array<string, mixed>> $cards; string $open, $saved;
 * array<string, bool> $fromFile; ?string $error, $errorSite; array<string, mixed> $addDraft;
 * string $adminTab, $basePath
 */

declare(strict_types=1);

/** @var list<array<string, mixed>> $cards */
/** @var string $open */
/** @var string $saved */
/** @var array<string, bool> $fromFile */
/** @var ?string $error */
/** @var ?string $errorSite */
/** @var array<string, mixed> $addDraft */
/** @var string $basePath */

$b = htmlspecialchars($basePath, ENT_QUOTES);
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$source = $fromFile['sites'] ?? false ? '' : ' <span class="wk-mono wk-dim wk-text-xs">' . $e(t('admin.settings.from_local')) . '</span>';
$count = static fn (int $n, string $key): string => $n === 1 ? t($key . '_one') : t($key, [$n]);
$blankDevices = 1;
?>
<div class="wk-doc">
<div class="wk-doc-head">
<?= \Reporion\Http\Breadcrumb::render([['label' => t('nav.admin')], ['label' => t('admin.sites.title')]]) ?>
<h1 class="wk-doc-title"><?= $e(t('admin.sites.title')) ?></h1>
</div>
<?php include __DIR__ . '/admin-tabs.php'; ?>

<div class="wk-panel" id="sites">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.settings.sites')) ?></h2><p class="wk-dim"><?= $e(t('admin.sites.help')) ?></p></hgroup><?= $source ?></header>
<?php if ($saved === 'removed'): ?><?php $noticeKind = 'ok'; $noticeText = t('admin.sites.removed'); include __DIR__ . '/partials/notice.php'; ?><?php endif; ?>
<?php if ($cards === []): ?><p class="wk-dim"><?= $e(t('admin.sites.empty')) ?></p><?php endif; ?>
<div class="wk-sites">
<?php foreach ($cards as $card): $code = (string) $card['code']; $lines = array_values(array_filter([$card['address'], $card['phone']], static fn (string $v): bool => $v !== '')); ?>
<details class="wk-site" name="site" id="site-<?= $e($code) ?>"<?= $open === $code ? ' open' : '' ?>>
<summary class="wk-site-h"><b><?= $e($card['name'] !== '' ? $card['name'] : $code) ?></b><span class="wk-chip wk-chip-on"><?= $e($code) ?></span>
<span class="wk-mono wk-dim"><?= $e($count((int) $card['reports'], 'admin.sites.reports')) ?> · <?= $e($count(\count($card['devices']), 'admin.sites.devices')) ?></span></summary>
<form class="wk-site-body" data-busy action="<?= $b ?>/admin/sites/<?= $e($code) ?>" method="post" autocomplete="off">
<?php if ($errorSite === $code && $error !== null): ?><?php $noticeKind = 'error'; $noticeText = $error; include __DIR__ . '/partials/notice.php'; ?>
<?php elseif ($saved === $code): ?><?php $noticeKind = 'ok'; $noticeText = t('admin.settings.saved'); include __DIR__ . '/partials/notice.php'; ?><?php endif; ?>
<fieldset class="wk-ai-group">
<legend class="wk-eyebrow"><?= $e(t('admin.sites.letterhead')) ?></legend>
<div class="field"><label class="radio"><input type="checkbox" name="letterhead" value="1"<?= $card['letterhead'] ? ' checked' : '' ?> data-lh-switch><span class="dot"></span><?= $e(t('admin.sites.print_letterhead')) ?></label>
<small class="wk-dim"><?= $e(t('admin.sites.print_letterhead_help')) ?></small></div>
<div class="wk-site-grid">
<?php foreach (['name', 'dept', 'address', 'phone'] as $f): ?>
<div class="field"><label for="site-<?= $e($code) ?>-<?= $f ?>"><?= $e(t('admin.sites.f_' . $f)) ?>
<input class="input" type="text" id="site-<?= $e($code) ?>-<?= $f ?>" name="<?= $f ?>" value="<?= $e((string) $card[$f]) ?>" placeholder="<?= $e(t('admin.settings.site_' . $f)) ?>" maxlength="200" data-lh-field="<?= $f ?>"></label></div>
<?php endforeach; ?>
<div class="wk-letterhead<?= $card['letterhead'] ? '' : ' wk-letterhead-off' ?>" aria-label="<?= $e(t('admin.sites.preview')) ?>" data-lh-preview data-lh-fallback="<?= $e(t('app.name')) ?>">
<b data-lh="name"><?= $e($card['name'] !== '' ? $card['name'] : t('app.name')) ?></b>
<span data-lh="dept"><?= $e($card['dept']) ?></span>
<span data-lh="line"><?= $e(implode(' · ', $lines)) ?></span>
</div>
<div class="field"><label for="site-<?= $e($code) ?>-accession"><?= $e(t('admin.sites.f_accession')) ?>
<input class="input wk-mono" type="text" id="site-<?= $e($code) ?>-accession" name="accession_code" value="<?= $e($card['accession_code']) ?>" placeholder="<?= $e(strtoupper($code)) ?>" maxlength="12" data-upper>
<small class="wk-dim"><?= $e(t('admin.sites.accession_help')) ?></small></label></div>
</div>
</fieldset>
<fieldset class="wk-ai-group">
<legend class="wk-eyebrow"><?= $e(t('admin.sites.devices_title')) ?></legend>
<table class="table table-cards table-cards-form wk-site-devices">
<thead><tr><th scope="col"><?= $e(t('admin.sites.dev_code')) ?></th><th scope="col"><?= $e(t('admin.sites.dev_name')) ?></th><th scope="col"><?= $e(t('admin.sites.dev_links')) ?></th><th scope="col"><span class="wk-vh"><?= $e(t('admin.sites.dev_remove')) ?></span></th></tr></thead>
<tbody>
<?php $rows = $card['devices']; for ($k = 0; $k < $blankDevices; ++$k) { $rows[] = ['code' => '', 'name' => '', 'pacs' => [], 'inst' => [], 'link_new' => '', 'remove' => false]; } ?>
<?php foreach ($rows as $i => $device): $blank = $device['code'] === '' && $device['name'] === '' && $device['pacs'] === [] && $device['inst'] === [] && ($device['link_new'] ?? '') === ''; ?>
<tr>
<td data-label="<?= $e(t('admin.sites.dev_code')) ?>"><input class="input wk-mono" type="text" name="devices[<?= $i ?>][code]" value="<?= $e($device['code']) ?>" placeholder="<?= $e(t('admin.sites.dev_code_hint')) ?>" maxlength="32" aria-label="<?= $e(t('admin.sites.dev_code')) ?>"></td>
<td data-label="<?= $e(t('admin.sites.dev_name')) ?>"><input class="input" type="text" name="devices[<?= $i ?>][name]" value="<?= $e($device['name']) ?>" maxlength="200" aria-label="<?= $e(t('admin.sites.dev_name')) ?>"></td>
<td data-label="<?= $e(t('admin.sites.dev_links')) ?>"><div class="wk-site-links">
<?php foreach ($device['pacs'] as $link): ?><label class="wk-chip wk-chip-check" title="<?= $e(t('admin.sites.chip_pacs')) ?>"><input type="checkbox" name="devices[<?= $i ?>][pacs][]" value="<?= $e($link) ?>" checked><i class="ph ph-monitor" aria-hidden="true"></i><?= $e(preg_match('/^SN (.+)$/', $link, $m) === 1 ? 'sn:' . $m[1] : 'pacs:' . $link) ?></label><?php endforeach; ?>
<?php foreach ($device['inst'] as $link): ?><label class="wk-chip wk-chip-check" title="<?= $e(t('admin.sites.chip_inst')) ?>"><input type="checkbox" name="devices[<?= $i ?>][inst][]" value="<?= $e($link) ?>" checked><i class="ph ph-buildings" aria-hidden="true"></i><?= $e('inst:' . $link) ?></label><?php endforeach; ?>
<input class="input wk-site-inst" type="text" name="devices[<?= $i ?>][link_new]" value="<?= $e($device['link_new']) ?>" placeholder="<?= $e(t('admin.sites.link_hint')) ?>" maxlength="400" aria-label="<?= $e(t('admin.sites.link_hint')) ?>" title="<?= $e(t('admin.sites.link_new_help')) ?>">
</div></td>
<td class="wk-right<?= $blank ? ' wk-nocard' : '' ?>"><?php if (!$blank): ?><label class="radio wk-text-sm"><input type="checkbox" name="devices[<?= $i ?>][remove]" value="1"<?= $device['remove'] ? ' checked' : '' ?>><span class="dot"></span><?= $e(t('admin.sites.dev_remove')) ?></label><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<small class="wk-dim"><?= $e(t('admin.sites.devices_help')) ?></small>
</fieldset>
<footer>
<?php if ((int) $card['reports'] > 0): ?><label class="radio wk-text-sm"><input type="checkbox" name="confirm" value="1"><span class="dot"></span><?= $e(t('admin.sites.remove_confirm', [(int) $card['reports']])) ?></label><?php endif; ?>
<button class="btn btn-secondary btn-danger" type="submit" formaction="<?= $b ?>/admin/sites/<?= $e($code) ?>/remove"><i class="ph ph-trash" aria-hidden="true"></i><?= $e(t('admin.sites.remove')) ?></button>
<button class="btn btn-primary" type="submit"><i class="ph ph-check" aria-hidden="true"></i><?= $e(t('admin.settings.save')) ?></button>
</footer>
</form>
</details>
<?php endforeach; ?>
</div>
</div>

<div class="wk-panel" id="add-site">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('admin.sites.add_title')) ?></h2><p class="wk-dim"><?= $e(t('admin.sites.add_help')) ?></p></hgroup></header>
<?php if ($errorSite === '' && $error !== null): ?><?php $noticeKind = 'error'; $noticeText = $error; include __DIR__ . '/partials/notice.php'; ?><?php endif; ?>
<form class="group group-fill group-stack" data-busy action="<?= $b ?>/admin/sites" method="post" autocomplete="off">
<label class="group-addon" for="add-site-code"><i class="ph ph-buildings" aria-hidden="true"></i>&nbsp;<?= $e(t('admin.sites.add_code')) ?></label>
<input class="input wk-mono" type="text" id="add-site-code" name="code" value="<?= $e((string) ($addDraft['code'] ?? '')) ?>" placeholder="<?= $e(t('admin.sites.add_code_hint')) ?>" maxlength="32" required>
<label class="group-addon" for="add-site-name"><?= $e(t('admin.sites.add_name')) ?></label>
<input class="input grow" type="text" id="add-site-name" name="name" value="<?= $e((string) ($addDraft['name'] ?? '')) ?>" placeholder="<?= $e(t('admin.settings.site_name')) ?>" maxlength="200">
<button class="btn btn-primary" type="submit"><i class="ph ph-plus" aria-hidden="true"></i><?= $e(t('admin.sites.add')) ?></button>
</form>
</div>
</div>
<script src="<?= $e(\Reporion\Support\Asset::url($basePath, 'js/admin-sites.js')) ?>" defer></script>
