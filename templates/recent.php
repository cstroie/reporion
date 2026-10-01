<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /?all=1 (Controller\HomeController::recent()) — every recently
 * changed page the caller can see; the start page (templates/dashboard.php)
 * links here. Content only, in the app shell. One panel with the pages
 * table of the namespace index (templates/namespace.php: .wk-panel,
 * table.table, status/visibility tags); the filters sit in its header as
 * three small side-by-side switches (.seg-sm), each option a plain link.
 *
 * Variables in scope: list<array<string, mixed>> $rows;
 * list<string> $modalities; array{mod: string, days: int, mine: bool} $filter;
 * string $basePath
 */

declare(strict_types=1);

/** @var list<array<string, mixed>> $rows */
/** @var list<string> $modalities */
/** @var array{mod: string, days: int, mine: bool} $filter */
/** @var string $basePath */

$b = htmlspecialchars($basePath, ENT_QUOTES);
// The dashboard URL with one filter changed (null removes it)
$link = static function (array $change) use ($filter, $b): string {
    $q = array_filter([
        'all' => '1',
        'mod' => $filter['mod'],
        'days' => $filter['days'] > 0 ? (string) $filter['days'] : '',
        'mine' => $filter['mine'] ? '1' : '',
    ]);
    foreach ($change as $key => $value) {
        if ($value === null || $value === '') {
            unset($q[$key]);
        } else {
            $q[$key] = $value;
        }
    }

    return $b . '/' . ($q !== [] ? '?' . htmlspecialchars(http_build_query($q), ENT_QUOTES) : '');
};
?>
<div class="wk-doc">
<div class="wk-doc-titlerow"><h1 class="wk-doc-title"><?= htmlspecialchars(t('recent.title'), ENT_QUOTES) ?></h1><div class="wk-actions"><a class="btn btn-ghost" href="<?= $b ?>/"><i class="ph ph-arrow-left"></i><?= htmlspecialchars(t('recent.back'), ENT_QUOTES) ?></a></div></div>
<p class="wk-dim"><?= htmlspecialchars(t('recent.lead'), ENT_QUOTES) ?></p>

<div class="wk-panel">
<header class="wk-panel-h"><h2 class="wk-eyebrow"><?= htmlspecialchars(t('recent.changes'), ENT_QUOTES) ?><span class="wk-count"><?= count($rows) ?></span></h2>
<nav class="wk-filters" aria-label="<?= htmlspecialchars(t('dash.filters'), ENT_QUOTES) ?>">
<?php /* Three side-by-side switches (.seg-sm), each option a plain link: modality, period, whose */ ?>
<?php $opt = static fn (bool $on, string $href, string $label): string => '<a class="seg-opt' . ($on ? ' seg-on' : '') . '" href="' . $href . '"' . ($on ? ' aria-current="true"' : '') . '>' . htmlspecialchars($label, ENT_QUOTES) . '</a>'; ?>
<span class="seg seg-sm" role="group" aria-label="<?= htmlspecialchars(t('recent.col_modality'), ENT_QUOTES) ?>">
<?= $opt($filter['mod'] === '', $link(['mod' => null]), t('recent.any_modality')) ?>
<?php foreach ($modalities as $modality): ?>
<?= $opt($filter['mod'] === $modality, $link(['mod' => $modality]), $modality) ?>
<?php endforeach; ?>
</span>
<span class="seg seg-sm" role="group" aria-label="<?= htmlspecialchars(t('recent.period'), ENT_QUOTES) ?>">
<?= $opt($filter['days'] === 0, $link(['days' => null]), t('recent.any_time')) ?>
<?= $opt($filter['days'] > 0, $link(['days' => '30']), t('dash.last_30')) ?>
</span>
<span class="seg seg-sm" role="group" aria-label="<?= htmlspecialchars(t('ns.col_by'), ENT_QUOTES) ?>">
<?= $opt(!$filter['mine'], $link(['mine' => null]), t('recent.everyone')) ?>
<?= $opt($filter['mine'], $link(['mine' => '1']), t('dash.mine')) ?>
</span>
</nav></header>
<?php if ($rows === []): ?>
<p class="wk-dim"><?= htmlspecialchars(t('dash.empty'), ENT_QUOTES) ?></p>
<?php else: ?>
<table class="table">
<thead><tr>
<th><?= htmlspecialchars(t('ns.col_title'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('recent.col_ns'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('recent.col_modality'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('ns.col_status'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('ns.col_visibility'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('ns.col_updated'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('ns.col_by'), ENT_QUOTES) ?></th>
</tr></thead>
<tbody>
<?php foreach ($rows as $page): ?>
<?php
$path = (string) $page['path'];
// strrpos() is false for a top-level page ("reports"): no namespace, the whole path is the leaf
$colon = strrpos($path, ':');
$pageNs = $colon === false ? '' : substr($path, 0, $colon);
?>
<tr>
<td><a href="<?= $b ?>/<?= htmlspecialchars($path, ENT_QUOTES) ?>"><?= htmlspecialchars((string) ($page['title'] ?: $path), ENT_QUOTES) ?></a><?php if (trim((string) ($page['summary'] ?? '')) !== ''): ?><br><span class="wk-row-s"><?= htmlspecialchars(\Reporion\Support\Snippet::words((string) $page['summary'], 40), ENT_QUOTES) ?></span><?php endif; ?></td>
<td class="wk-mono"><?php if ($pageNs !== ''): ?><a href="<?= $b ?>/<?= htmlspecialchars($pageNs, ENT_QUOTES) ?>:"><?= htmlspecialchars($pageNs, ENT_QUOTES) ?></a><?php endif; ?></td>
<td class="wk-mono"><?= htmlspecialchars((string) ($page['modality'] ?? ''), ENT_QUOTES) ?></td>
<td><span class="tag <?= \Reporion\Support\Badges::statusTag((string) $page['status']) ?>"><?= htmlspecialchars((string) $page['status'], ENT_QUOTES) ?></span></td>
<td><span class="tag <?= \Reporion\Support\Badges::visibilityTag((string) $page['visibility']) ?>"><?= htmlspecialchars((string) $page['visibility'], ENT_QUOTES) ?></span></td>
<td class="wk-mono"><?= htmlspecialchars(\Reporion\Support\MetaText::when($page['updated'] ?? null), ENT_QUOTES) ?></td>
<td class="wk-mono"><?= htmlspecialchars(display_name((string) ($page['updated_by'] ?? '')), ENT_QUOTES) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>
</div>
