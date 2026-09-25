<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET / for a signed-in user (Controller\HomeController::dashboard()) —
 * content only, in the app shell. Rows and chips from
 * design/mockup/WikiWorklist.dc.html (.wk-list / .wk-row / .wk-filters /
 * .wk-chip). Every chip is a plain link that toggles one query parameter;
 * the mockup's sort button is not built.
 *
 * Variables in scope: list<array<string, mixed>> $rows, $drafts;
 * list<string> $modalities; array{mod: string, days: int, mine: bool} $filter;
 * string $homePagePath, $basePath
 */

declare(strict_types=1);

/** @var list<array<string, mixed>> $rows */
/** @var list<array<string, mixed>> $drafts */
/** @var list<string> $modalities */
/** @var array{mod: string, days: int, mine: bool} $filter */
/** @var string $homePagePath */
/** @var string $basePath */

$b = htmlspecialchars($basePath, ENT_QUOTES);
// The dashboard URL with one filter changed (null removes it)
$link = static function (array $change) use ($filter, $b): string {
    $q = array_filter([
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
$row = static function (array $page) use ($b): string {
    $path = (string) $page['path'];
    $leaf = substr($path, (int) strrpos($path, ':') + 1);
    $out = '<a class="wk-row" href="' . $b . '/' . htmlspecialchars($path, ENT_QUOTES) . '">';
    $out .= '<div class="wk-row-t">' . htmlspecialchars((string) ($page['title'] ?: $path), ENT_QUOTES);
    if ((string) $page['visibility'] !== 'public') {
        $out .= '<span class="wk-vis">' . htmlspecialchars((string) $page['visibility'], ENT_QUOTES) . '</span>';
    }
    $out .= '</div><div class="wk-row-m wk-mono">' . htmlspecialchars($leaf, ENT_QUOTES)
        . ' · ' . htmlspecialchars(substr((string) $page['updated'], 0, 10), ENT_QUOTES)
        . ' · ' . htmlspecialchars((string) $page['status'], ENT_QUOTES)
        . (($page['modality'] ?? '') !== '' ? ' · ' . htmlspecialchars((string) $page['modality'], ENT_QUOTES) : '')
        . ' · ' . htmlspecialchars((string) ($page['updated_by'] ?? ''), ENT_QUOTES) . '</div>';
    if (($page['summary'] ?? '') !== '') {
        $out .= '<div class="wk-row-s">' . htmlspecialchars((string) $page['summary'], ENT_QUOTES) . '</div>';
    }

    return $out . '</a>';
};
?>
<div class="wk-doc">
<div class="wk-doc-titlerow"><h1 class="wk-doc-title"><?= htmlspecialchars(t('dash.title'), ENT_QUOTES) ?></h1><div class="wk-actions"><a class="btn btn-ghost btn-sm" href="<?= $b ?>/<?= htmlspecialchars($homePagePath, ENT_QUOTES) ?>"><?= htmlspecialchars(t('dash.home_page'), ENT_QUOTES) ?></a></div></div>

<?php if ($drafts !== []): ?>
<div class="wk-panel">
<div class="wk-panel-h"><span class="wk-eyebrow"><?= htmlspecialchars(t('dash.my_drafts'), ENT_QUOTES) ?></span><span class="wk-count"><?= count($drafts) ?></span></div>
<div class="wk-list wk-list-flat">
<?php foreach ($drafts as $page): ?><?= $row($page) ?><?php endforeach; ?>
</div>
</div>
<?php endif; ?>

<div class="wk-filters" aria-label="<?= htmlspecialchars(t('dash.filters'), ENT_QUOTES) ?>">
<?php foreach ($modalities as $modality): ?>
<a class="wk-chip<?= $filter['mod'] === $modality ? ' wk-chip-on' : '' ?>" href="<?= $link(['mod' => $filter['mod'] === $modality ? null : $modality]) ?>"><?= htmlspecialchars($modality, ENT_QUOTES) ?></a>
<?php endforeach; ?>
<a class="wk-chip<?= $filter['days'] > 0 ? ' wk-chip-on' : '' ?>" href="<?= $link(['days' => $filter['days'] > 0 ? null : '30']) ?>"><?= htmlspecialchars(t('dash.last_30'), ENT_QUOTES) ?></a>
<a class="wk-chip<?= $filter['mine'] ? ' wk-chip-on' : '' ?>" href="<?= $link(['mine' => $filter['mine'] ? null : '1']) ?>"><?= htmlspecialchars(t('dash.mine'), ENT_QUOTES) ?></a>
</div>

<?php if ($rows === []): ?>
<p class="wk-dim"><?= htmlspecialchars(t('dash.empty'), ENT_QUOTES) ?></p>
<?php else: ?>
<div class="wk-list wk-list-flat">
<?php foreach ($rows as $page): ?><?= $row($page) ?><?php endforeach; ?>
</div>
<?php endif; ?>
</div>
