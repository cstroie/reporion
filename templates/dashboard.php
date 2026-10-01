<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET / for a signed-in user: the start page (Controller\HomeController::
 * dashboard()) — content only, in the app shell. Top to bottom: greeting
 * and actions, four counts, "Continue" + quick navigation, drafts waiting
 * for a signature + the caller's own recent changes, the team's week, and
 * the link to every recent change (/?all=1). Panels, cards, tags and
 * buttons are the existing design classes; only the grid and the compact
 * row (.wk-start-*, .wk-srow) are new.
 *
 * Variables in scope: string $greeting, $today, $staleBefore, $homePagePath,
 * $basePath; array{drafts: int, stale: int, today: int, week: int} $stats;
 * int $cap, $staleDays; ?array $last + array $lastActions; list $drafts,
 * $mine, $team; array $startQuick (Http\QuickNav::links()); callable
 * $canWritePath; array $actions.
 */

declare(strict_types=1);

use Reporion\Support\Badges;
use Reporion\Support\MetaText;
use Reporion\Support\ReportPath;

/** @var string $greeting */
/** @var string $today */
/** @var string $staleBefore */
/** @var string $homePagePath */
/** @var string $basePath */
/** @var array{drafts: int, stale: int, today: int, week: int} $stats */
/** @var int $cap */
/** @var int $staleDays */
/** @var array<string, mixed>|null $last */
/** @var array{edit?: bool, sign?: bool, patient?: bool} $lastActions */
/** @var list<array<string, mixed>> $drafts */
/** @var list<array<string, mixed>> $mine */
/** @var list<array<string, mixed>> $team */
/** @var array{fixed: list<array{href: string, label: string, icon: string}>, pinned: list<array{href: string, label: string, icon: string}>, related: list<array{href: string, label: string, icon: string}>} $startQuick */
/** @var callable(string): bool $canWritePath */
/** @var array{newReport: ?string, newReportNs: string, followUp: ?string, newPage: ?string, worklists: list<array{plugin: string, label: string, icon: string, href: string}>} $actions */

$b = htmlspecialchars($basePath, ENT_QUOTES);
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES);
$count = static fn (int $n): string => $n >= $cap ? $cap . '+' : (string) $n;
$ns = static function (string $path): string {
    $colon = strrpos($path, ':');

    return $colon === false ? '' : substr($path, 0, $colon);
};
// One compact row: title, where and when, then its tags and actions
$row = static function (array $page, string $meta, bool $signable = false, bool $status = true) use ($b, $e): string {
    $path = (string) $page['path'];
    $out = '<div class="wk-srow"><a class="wk-srow-main" href="' . $b . '/' . $e($path) . '">'
        . '<span class="wk-srow-t">' . $e($page['title'] ?: $path) . '</span>'
        . '<span class="wk-srow-m wk-mono">' . $meta . '</span></a>'
        . '<span class="wk-srow-end">'
        . ($status ? '<span class="tag ' . Badges::statusTag((string) $page['status']) . '">' . $e($page['status']) . '</span>' : '');
    if ($signable) {
        $out .= '<a class="btn btn-secondary btn-sm" href="' . $b . '/' . $e($path) . '/sign"><i class="ph ph-seal-check"></i>' . $e(t('start.sign')) . '</a>';
    }

    return $out . '</span></div>';
};
// Home and Root read as words; pins and the modality's namespaces as paths
$quickLinks = [
    ...array_map(static fn (array $link): array => $link + ['mono' => false], $startQuick['fixed']),
    ...array_map(static fn (array $link): array => $link + ['mono' => true], [...$startQuick['pinned'], ...$startQuick['related']]),
];
?>
<div class="wk-doc wk-start">

<div class="wk-doc-titlerow">
<hgroup class="wk-start-hello">
<p class="wk-eyebrow"><?= $e($today) ?></p>
<h1 class="wk-doc-title"><?= $e($greeting) ?></h1>
</hgroup>
<div class="wk-actions">
<?php if ($actions['newReport'] !== null): ?>
<a class="btn btn-primary" href="<?= $b ?><?= $e($actions['newReport']) ?>"><i class="ph ph-plus"></i><?= $e($actions['newReportNs'] !== '' ? t('start.new_report_in', [$actions['newReportNs']]) : t('start.new_report')) ?></a>
<?php endif; ?>
<?php if ($actions['followUp'] !== null): ?>
<a class="btn btn-secondary" href="<?= $b ?><?= $e($actions['followUp']) ?>"><i class="ph ph-user-plus"></i><?= $e(t('start.follow_up')) ?></a>
<?php endif; ?>
<?php foreach ($actions['worklists'] as $slot): ?>
<a class="btn btn-secondary" href="<?= $b ?><?= $e($slot['href']) ?>"><i class="ph ph-<?= $e($slot['icon']) ?>"></i><?= $e(t($slot['label'])) ?></a>
<?php endforeach; ?>
<?php if ($actions['newPage'] !== null): ?>
<a class="btn btn-secondary" href="<?= $b ?><?= $e($actions['newPage']) ?>"><i class="ph ph-plus"></i><?= $e(t('start.new_page')) ?></a>
<?php endif; ?>
</div>
</div>

<div class="wk-start-stats">
<a class="wk-start-stat" href="#drafts"><b><?= $count($stats['drafts']) ?></b><span><?= $e(t('start.stat_drafts')) ?></span></a>
<a class="wk-start-stat<?= $stats['stale'] > 0 ? ' wk-start-stat-warn' : '' ?>" href="#drafts"><b><?= $count($stats['stale']) ?></b><span><?= $e(t('start.stat_stale', [$staleDays])) ?></span></a>
<a class="wk-start-stat" href="#mine"><b><?= $count($stats['today']) ?></b><span><?= $e(t('start.stat_today')) ?></span></a>
<a class="wk-start-stat" href="#mine"><b><?= $count($stats['week']) ?></b><span><?= $e(t('start.stat_week')) ?></span></a>
</div>

<div class="wk-start-grid">
<section class="wk-panel wk-start-continue">
<header class="wk-panel-h"><h2 class="wk-eyebrow"><?= $e(t('start.continue')) ?></h2></header>
<?php if ($last === null): ?>
<p class="wk-dim"><?= $e(t('start.continue_none')) ?></p>
<?php else: ?>
<?php $lastPath = (string) $last['path']; ?>
<a class="wk-start-last" href="<?= $b ?>/<?= $e($lastPath) ?>">
<b><?= $e($last['title'] ?: $lastPath) ?></b>
<span class="wk-mono wk-dim"><?= $e($ns($lastPath)) ?></span>
</a>
<div class="wk-badges">
<span class="tag <?= Badges::statusTag((string) $last['status']) ?>"><?= $e($last['status']) ?></span>
<span class="tag <?= Badges::visibilityTag((string) $last['visibility']) ?>"><?= $e($last['visibility']) ?></span>
<?php if (($last['modality'] ?? '') !== ''): ?><span class="wk-mono"><?= $e($last['modality']) ?></span><?php endif; ?>
<span class="wk-dim"><?= $e(t('start.edited_ago', [MetaText::ago($last['updated'])])) ?></span>
</div>
<?php if (($last['summary'] ?? '') !== ''): ?><p class="wk-start-summary"><?= $e($last['summary']) ?></p><?php endif; ?>
<div class="wk-actions">
<a class="btn btn-secondary btn-sm" href="<?= $b ?>/<?= $e($lastPath) ?>"><i class="ph ph-file-text"></i><?= $e(t('start.open')) ?></a>
<?php if ($lastActions['edit'] ?? false): ?><a class="btn btn-secondary btn-sm" href="<?= $b ?>/<?= $e($lastPath) ?>/edit"><i class="ph ph-pencil-simple"></i><?= $e(t('start.edit')) ?></a><?php endif; ?>
<?php if ($lastActions['sign'] ?? false): ?><a class="btn btn-primary btn-sm" href="<?= $b ?>/<?= $e($lastPath) ?>/sign"><i class="ph ph-seal-check"></i><?= $e(t('start.sign')) ?></a><?php endif; ?>
<?php if ($lastActions['patient'] ?? false): ?><a class="btn btn-ghost btn-sm" href="<?= $b ?>/<?= $e($lastPath) ?>/timeline"><i class="ph ph-clock-counter-clockwise"></i><?= $e(t('start.patient')) ?></a><?php endif; ?>
</div>
<?php endif; ?>
</section>

<section class="wk-panel">
<header class="wk-panel-h"><h2 class="wk-eyebrow"><?= $e(t('start.go_to')) ?></h2></header>
<nav class="wk-start-links" aria-label="<?= $e(t('start.go_to')) ?>">
<?php foreach ($quickLinks as $link): ?>
<a class="wk-start-link" href="<?= $b ?><?= $e($link['href']) ?>"><i class="ph ph-<?= $e($link['icon']) ?>"></i><span<?= $link['mono'] ? ' class="wk-mono"' : '' ?>><?= $e($link['label']) ?></span></a>
<?php endforeach; ?>
</nav>
<?php if ($startQuick['pinned'] === []): ?>
<p class="wk-dim wk-start-hint"><?= $e(t('start.pin_hint')) ?></p>
<?php endif; ?>
</section>
</div>

<div class="wk-start-grid">
<section class="wk-panel" id="drafts">
<header class="wk-panel-h"><h2 class="wk-eyebrow"><?= $e(t('start.drafts')) ?></h2><?php if ($stats['drafts'] > count($drafts)): ?><span class="wk-start-more"><?= $e(t('start.oldest_of', [count($drafts), $count($stats['drafts'])])) ?></span><?php endif; ?></header>
<?php if ($drafts === []): ?>
<p class="wk-dim"><?= $e(t('start.drafts_none')) ?></p>
<?php else: ?>
<div class="wk-slist">
<?php foreach ($drafts as $page): ?>
<?php $stale = (string) $page['updated'] < $staleBefore; ?>
<?= $row($page, $e($ns((string) $page['path'])) . ' · <span class="' . ($stale ? 'wk-start-stale' : '') . '">' . $e(MetaText::ago($page['updated'])) . '</span>', $canWritePath((string) $page['path']) && (string) $page['pid'] !== '' && ReportPath::isReport((string) $page['path']), false) ?>
<?php endforeach; ?>
</div>
<?php endif; ?>
</section>

<section class="wk-panel" id="mine">
<header class="wk-panel-h"><h2 class="wk-eyebrow"><?= $e(t('start.mine')) ?></h2><a class="wk-start-more" href="<?= $b ?>/?mine=1"><?= $e(t('start.all_mine')) ?></a></header>
<?php if ($mine === []): ?>
<p class="wk-dim"><?= $e(t('start.mine_none')) ?></p>
<?php else: ?>
<div class="wk-slist">
<?php foreach ($mine as $page): ?>
<?= $row($page, $e($ns((string) $page['path'])) . ' · ' . $e(MetaText::ago($page['updated']))) ?>
<?php endforeach; ?>
</div>
<?php endif; ?>
</section>
</div>

<section class="wk-panel">
<header class="wk-panel-h"><h2 class="wk-eyebrow"><?= $e(t('start.team')) ?></h2><a class="wk-start-more" href="<?= $b ?>/?all=1"><?= $e(t('start.all_recent')) ?></a></header>
<?php if ($team === []): ?>
<p class="wk-dim"><?= $e(t('start.team_none')) ?></p>
<?php else: ?>
<div class="wk-slist">
<?php foreach ($team as $page): ?>
<?= $row($page, $e(display_name((string) ($page['updated_by'] ?? ''))) . ' · ' . $e($ns((string) $page['path'])) . ' · ' . $e(MetaText::ago($page['updated']))) ?>
<?php endforeach; ?>
</div>
<?php endif; ?>
</section>

<p class="wk-dim wk-start-foot"><a href="<?= $b ?>/?all=1"><?= $e(t('start.all_recent')) ?></a> · <a href="<?= $b ?>/<?= $e($homePagePath) ?>"><?= $e(t('dash.home_page')) ?></a></p>
</div>
