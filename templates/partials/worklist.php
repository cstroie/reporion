<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The page view's left panel (the mockup's Workbench list column,
 * WikiWorklist): the pages of the namespace on screen, newest first, the
 * one open highlighted. Rows come from Index::listWorklist() under the
 * visibility predicate (invariant 6); the search box and the chips only hide
 * rows in the browser (assets/js/worklist.js), so without the script it is a
 * plain list of links.
 *
 * Variables in scope: list<array<string, mixed>> $worklist; string $path,
 * $basePath, $username.
 */

declare(strict_types=1);

use Reporion\Support\Badges;
use Reporion\Support\MetaText;
use Reporion\Support\Visibility;

/** @var list<array<string, mixed>> $worklist */
/** @var string $path */
/** @var string $basePath */
/** @var string $username */

$wb = htmlspecialchars($basePath, ENT_QUOTES);
$wlNs = implode(':', array_slice(explode(':', $path), 0, -1));
$wlModalities = [];
foreach ($worklist as $row) {
    foreach (array_filter(array_map('trim', explode(',', (string) ($row['modality'] ?? '')))) as $modality) {
        $wlModalities[$modality] = true;
    }
}
ksort($wlModalities);
?>
<aside class="wk-side" aria-label="<?= htmlspecialchars(t('worklist.title'), ENT_QUOTES) ?>" data-island="worklist" data-me="<?= htmlspecialchars($username, ENT_QUOTES) ?>">
<div class="wk-list">
<div class="wk-rail-head">
<span class="wk-eyebrow" title="<?= htmlspecialchars($wlNs, ENT_QUOTES) ?>"><?= htmlspecialchars($wlNs === '' ? t('ns.root_title') : $wlNs, ENT_QUOTES) ?></span>
<button type="button" class="wk-tbtn" data-wl-sort title="<?= htmlspecialchars(t('worklist.sort'), ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('worklist.sort'), ENT_QUOTES) ?>" aria-pressed="false"><i class="ph ph-sort-ascending" aria-hidden="true"></i></button>
</div>
<label class="wk-wl-search"><i class="ph ph-magnifying-glass" aria-hidden="true"></i><input type="search" data-wl-q autocomplete="off" placeholder="<?= htmlspecialchars(t('worklist.search_hint'), ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('worklist.search'), ENT_QUOTES) ?>"></label>
<div class="wk-filters" data-wl-chips hidden>
<?php foreach (array_keys($wlModalities) as $modality): ?>
<button type="button" class="wk-chip" data-wl-mod="<?= htmlspecialchars((string) $modality, ENT_QUOTES) ?>" aria-pressed="false"><?= htmlspecialchars((string) $modality, ENT_QUOTES) ?></button>
<?php endforeach; ?>
<button type="button" class="wk-chip" data-wl-recent aria-pressed="false"><?= htmlspecialchars(t('worklist.recent'), ENT_QUOTES) ?></button>
<button type="button" class="wk-chip" data-wl-mine aria-pressed="false"><?= htmlspecialchars(t('worklist.mine'), ENT_QUOTES) ?></button>
</div>
<div data-wl-rows>
<?php foreach ($worklist as $row): ?>
<?php
$rowPath = (string) $row['path'];
$segments = explode(':', $rowPath);
$rowTitle = trim((string) ($row['title'] ?? '')) !== '' ? trim((string) $row['title']) : (string) end($segments);
$meta = [(string) end($segments)];
if (($row['updated'] ?? '') !== '') {
    $meta[] = MetaText::date($row['updated'], 'd M');
}
$meta[] = (string) $row['status'];
$summary = trim((string) ($row['summary'] ?? ''));
?>
<a class="wk-row<?= $rowPath === $path ? ' wk-sel' : '' ?>" href="<?= $wb ?>/<?= htmlspecialchars($rowPath, ENT_QUOTES) ?>"<?= $rowPath === $path ? ' aria-current="page"' : '' ?> data-wl-row data-mod="<?= htmlspecialchars(strtoupper(str_replace(' ', '', (string) ($row['modality'] ?? ''))), ENT_QUOTES) ?>" data-updated="<?= htmlspecialchars((string) ($row['updated'] ?? ''), ENT_QUOTES) ?>" data-by="<?= htmlspecialchars((string) ($row['updated_by'] ?? ''), ENT_QUOTES) ?>" data-title="<?= htmlspecialchars(mb_strtolower($rowTitle), ENT_QUOTES) ?>">
<span class="wk-row-t"><?= htmlspecialchars($rowTitle, ENT_QUOTES) ?><i class="ph <?= Visibility::icon((string) $row['visibility']) ?> wk-vis" title="<?= htmlspecialchars(Visibility::label((string) $row['visibility']), ENT_QUOTES) ?>"></i></span>
<span class="wk-row-m wk-mono"><?= htmlspecialchars(implode(' · ', $meta), ENT_QUOTES) ?></span>
<?php if ($summary !== ''): ?><span class="wk-row-s"><?= htmlspecialchars($summary, ENT_QUOTES) ?></span><?php endif; ?>
</a>
<?php endforeach; ?>
</div>
<p class="wk-dim wk-wl-empty" data-wl-empty hidden><?= htmlspecialchars(t('worklist.none'), ENT_QUOTES) ?></p>
</div>
</aside>
<script src="<?= htmlspecialchars(\Reporion\Support\Asset::url($basePath, 'js/worklist.js'), ENT_QUOTES) ?>" defer></script>
