<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET / for a signed-in user: the start page (Controller\HomeController::
 * dashboard()): content only, in the app shell. Top to bottom: greeting
 * and actions, four counts, "Continue" + quick navigation, drafts waiting
 * for a signature + the caller's own recent changes, the team's week, and
 * a footer like a report's (.wk-doc-foot) saying what the lists count.
 * Every recent change is linked from the team's panel; the site home page
 * is the top nav's brand, from here. Panels, cards, tags and
 * buttons are the existing design classes; only the grid and the compact
 * row (.wk-start-*, .wk-srow) are new.
 *
 * Variables in scope: string $greeting, $greetingName, $today, $staleBefore, $basePath;
 * array{drafts: int, stale: int, today: int, week: int} $stats;
 * int $cap, $staleDays; ?array $last + array $lastActions; list $drafts,
 * $mine, $team; array $startQuick (Http\QuickNav::links()); callable
 * $canWritePath, $canSignPath; array $actions.
 */

declare(strict_types=1);

use Reporion\Support\Badges;
use Reporion\Support\MetaText;
use Reporion\Support\ReportPath;

/** @var string $greeting */
/** @var string $greetingName */
/** @var string $today */
/** @var string $staleBefore */
/** @var string $basePath */
/** @var array{drafts: int, stale: int, today: int, week: int} $stats */
/** @var int $cap */
/** @var array{signed: int, median: ?float, lastSigned: int, lastMedian: ?float}|null $month */
/** @var array<string, mixed>|null $insights */
/** @var array<string, mixed>|null $storage */
/** @var int $staleDays */
/** @var array<string, mixed>|null $last */
/** @var array{edit?: bool, patient?: bool} $lastActions */
/** @var list<array<string, mixed>> $drafts */
/** @var list<array<string, mixed>> $mine */
/** @var list<array<string, mixed>> $team */
/** @var array{fixed: list<array{href: string, label: string, icon: string}>, pinned: list<array{href: string, label: string, icon: string}>, related: list<array{href: string, label: string, icon: string}>} $startQuick */
/** @var callable(string): bool $canWritePath */
/** @var callable(string): bool $canSignPath */
/** @var array{newReport: ?string, newReportNs: string, newPage: ?string, worklists: list<array{plugin: string, label: string, icon: string, href: string}>} $actions */

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
        . ($status ? '<span class="tag ' . Badges::statusTag((string) $page['status']) . '" title="' . $e($page['status']) . '">' . '<i class="ph ' . Badges::statusIcon((string) $page['status']) . '" aria-hidden="true"></i><span class="tag-word"> ' . $e($page['status']) . '</span></span>' : '');
    if ($signable) {
        $out .= '<a class="btn btn-secondary btn-sm" href="' . $b . '/' . $e($path) . '/sign"><i class="ph ph-signature"></i>' . $e(t('start.sign')) . '</a>';
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

<header class="wk-doc-head">
<p class="wk-crumbs wk-mono wk-m0"><i class="ph ph-calendar-blank" aria-hidden="true"></i><b><?= $e($today) ?></b></p>
<div class="wk-doc-titlerow">
<hgroup class="wk-start-hello">
<h1 class="wk-doc-title"><span class="wk-nowrap"><?= $e($greeting) ?></span> <span class="wk-greet-name"><?= $e($greetingName) ?></span></h1>
</hgroup>
<div class="wk-actions">
<?php if ($actions['newReport'] !== null): ?>
<a class="btn btn-primary" href="<?= $b ?><?= $e($actions['newReport']) ?>" title="<?= $e($actions['newReportNs'] !== '' ? t('start.new_report_in', [$actions['newReportNs']]) : t('start.new_report')) ?>"><i class="ph ph-plus"></i><span class="wk-btn-label"><?= $e(t('start.new_report')) ?></span></a>
<?php endif; ?>
<?php foreach ($actions['worklists'] as $slot): ?>
<a class="btn btn-secondary" href="<?= $b ?><?= $e($slot['href']) ?>" title="<?= $e(t($slot['label'])) ?>"><i class="ph ph-<?= $e($slot['icon']) ?>"></i><span class="wk-btn-label"><?= $e(t($slot['label'])) ?></span></a>
<?php endforeach; ?>
<?php if ($actions['newPage'] !== null): ?>
<a class="btn btn-secondary" href="<?= $b ?><?= $e($actions['newPage']) ?>" title="<?= $e(t('start.new_page')) ?>"><i class="ph ph-plus"></i><span class="wk-btn-label"><?= $e(t('start.new_page')) ?></span></a>
<?php endif; ?>
</div>
</div>
</header>

<?php /* The notes app (D55), advertised until dismissed or used on this device (assets/js/notes-promo.js): hidden without JavaScript */ ?>
<div data-notes-promo hidden>
<?php $noticeKind = 'info';
$noticeIcon = 'note-pencil';
$noticeText = t('start.notes_promo');
$noticeLink = [$basePath . '/notes', t('start.notes_open')];
include __DIR__ . '/partials/notice.php'; ?>
</div>

<?php $month ??= null; ?>
<div class="wk-start-stats<?= $month !== null ? ' wk-start-stats-5' : '' ?>">
<a class="wk-start-stat<?= $stats['drafts'] > 0 ? ' wk-start-stat-hl' : '' ?>" href="#drafts"><b><?= $count($stats['drafts']) ?></b><span><?= $e(t('start.stat_drafts')) ?></span></a>
<a class="wk-start-stat<?= $stats['stale'] > 0 ? ' wk-start-stat-hl' : '' ?>" href="#drafts"><b><?= $count($stats['stale']) ?></b><span><?= $e(t('start.stat_stale', [$staleDays])) ?></span></a>
<a class="wk-start-stat" href="#mine"><b><?= $count($stats['today']) ?></b><span><?= $e(t('start.stat_today')) ?></span></a>
<a class="wk-start-stat" href="#mine"><b><?= $count($stats['week']) ?></b><span><?= $e(t('start.stat_week')) ?></span></a>
<?php if ($month !== null): /* Phase 24c: reports signed this month, against last month (Service\Stats::thisMonth()) */ ?>
<a class="wk-start-stat" href="<?= $b ?>/stats"><b><?= (int) $month['signed'] ?></b><span><?= $e(t('start.stat_month', [\Reporion\Service\Stats::formatDays($month['median'])])) ?></span><span><?= $e(t('start.stat_last_month', [$month['lastSigned'], \Reporion\Service\Stats::formatDays($month['lastMedian'])])) ?></span></a>
<?php endif; ?>
</div>

<?php $insights ??= null;
$storage ??= null; ?>
<?php if ($insights !== null || $storage !== null): /* Insights (Service\Stats::insights()) and, for owners, Storage (Service\StorageStats) */ ?>
<?php
$pct = static fn (?float $v): string => $v === null ? 'n/a' : (string) round($v * 100) . '%';
$fmtBytes = static function (?int $n): string {
    if ($n === null) {
        return 'n/a';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $v = (float) $n;
    while ($v >= 1024 && $i < 4) {
        $v /= 1024;
        ++$i;
    }

    return ($i === 0 ? (string) $n : number_format($v, $v < 10 ? 1 : 0)) . ' ' . $units[$i];
};
?>
<div class="wk-start-ins<?= $storage !== null ? ' wk-start-ins-3' : '' ?>">
<?php if ($insights !== null): ?>
<?php $ins = $insights;
$peak = max(1, ...array_map(static fn (array $w): int => $w['mine'] + $w['team'], $ins['weeks']));
$trend = $ins['trend'];
$weekdays = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun']; ?>
<section class="wk-panel">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('start.ins_activity')) ?></h2></hgroup><a class="wk-start-more" href="<?= $b ?>/stats"><?= $e(t('start.ins_more')) ?></a></header>
<svg class="wk-spark" viewBox="0 0 120 32" preserveAspectRatio="none" role="img" aria-label="<?= $e(t('start.ins_weeks')) ?>">
<?php foreach ($ins['weeks'] as $i => $w):
    $hm = 30 * $w['mine'] / $peak;
    $ht = 30 * $w['team'] / $peak; ?>
<g><title><?= $e($w['week'] . ': ' . t('start.ins_week_tip', [$w['mine'], $w['team']])) ?></title>
<rect class="wk-spark-team" x="<?= $i * 10 + 1 ?>" y="<?= round(32 - $hm - $ht, 2) ?>" width="8" height="<?= round($ht, 2) ?>"/>
<rect class="wk-spark-mine" x="<?= $i * 10 + 1 ?>" y="<?= round(32 - $hm, 2) ?>" width="8" height="<?= round($hm, 2) ?>"/></g>
<?php endforeach; ?>
<line class="wk-spark-base" x1="0" y1="31.5" x2="120" y2="31.5"/>
</svg>
<p class="wk-spark-legend"><span class="wk-key wk-key-mine"></span><?= $e(t('start.ins_mine')) ?> <span class="wk-key wk-key-team"></span><?= $e(t('start.ins_team')) ?> <span class="wk-dim">· <?= $e(t('start.ins_weeks')) ?></span></p>
<dl class="wk-ins-dl">
<dt><?= $e(t('start.ins_turnaround')) ?></dt>
<dd><b><?= $e(\Reporion\Service\Stats::formatDays($trend['median'])) ?></b><?php if ($trend['change'] !== null): $up = $trend['change'] > 0; ?> <span class="<?= $up ? 'wk-ins-worse' : 'wk-ins-better' ?>" title="<?= $e(t('start.ins_vs_before', [\Reporion\Service\Stats::formatDays($trend['before'])])) ?>"><i class="ph ph-arrow-<?= $up ? 'up' : 'down' ?>-right" aria-hidden="true"></i><?= $e((string) abs((int) round($trend['change'] * 100))) ?>%</span><?php endif; ?> <span class="wk-dim">p90 <?= $e(\Reporion\Service\Stats::formatDays($trend['p90'])) ?></span></dd>
<dt><?= $e(t('start.ins_same_day')) ?></dt><dd><b><?= $e($pct($ins['sameDay'])) ?></b></dd>
<dt><?= $e(t('start.ins_share')) ?></dt><dd><b><?= $e($pct($ins['share'])) ?></b> <span class="wk-dim"><?= $e(t('start.ins_of', [$ins['mine'], $ins['signed']])) ?></span></dd>
<dt><?= $e(t('start.ins_pace')) ?></dt><dd><b><?= $ins['pace'] !== null ? '~' . (int) $ins['pace'] : 'n/a' ?></b> <span class="wk-dim"><?= $e(t('start.ins_pace_note')) ?></span></dd>
<?php if ($ins['weekday'] !== null): ?>
<dt><?= $e(t('start.ins_busiest')) ?></dt><dd><b><?= $e($weekdays[$ins['weekday']]) ?></b> <span class="wk-dim"><?= $e(t('start.ins_hour', [sprintf('%02d:00', $ins['hour'])])) ?></span></dd>
<?php endif; ?>
</dl>
</section>

<section class="wk-panel">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('start.ins_backlog')) ?></h2></hgroup><a class="wk-start-more" href="#drafts"><?= $e(t('start.ins_backlog_n', [$ins['backlog']['total']])) ?></a></header>
<?php $bl = $ins['backlog']; ?>
<?php if ($bl['total'] === 0): ?>
<p class="wk-dim"><?= $e(t('start.ins_backlog_none')) ?></p>
<?php else: ?>
<div class="wk-sbar" role="img" aria-label="<?= $e(t('start.ins_backlog')) ?>">
<?php foreach ($bl['buckets'] as $key => $n): if ($n === 0) {
    continue;
} ?>
<span class="wk-sbar-<?= $e($key) ?>" style="flex-grow: <?= (int) $n ?>" title="<?= $e(t('start.ins_age_' . $key) . ': ' . $n) ?>"></span>
<?php endforeach; ?>
</div>
<p class="wk-spark-legend">
<?php foreach ($bl['buckets'] as $key => $n): ?>
<span class="wk-key wk-sbar-<?= $e($key) ?>"></span><?= $e(t('start.ins_age_' . $key)) ?> <b><?= (int) $n ?></b>
<?php endforeach; ?>
</p>
<p class="wk-dim wk-ins-small"><?= $e(t('start.ins_oldest', [\Reporion\Service\Stats::formatDays($bl['oldest'])])) ?></p>
<?php endif; ?>
<?php foreach (['modality' => 'start.ins_mix_modality', 'site' => 'start.ins_mix_site'] as $group => $label): if ($ins[$group] === []) {
    continue;
} ?>
<h3 class="wk-eyebrow wk-ins-h3"><?= $e(t($label)) ?></h3>
<ul class="wk-ins-mix">
<?php foreach ($ins[$group] as $item): ?>
<li><span class="wk-mono"><?= $e($item['key']) ?></span><span class="wk-ins-track"><span style="width: <?= round($item['share'] * 100, 1) ?>%"></span></span><b><?= (int) $item['n'] ?></b></li>
<?php endforeach; ?>
</ul>
<?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($storage !== null): ?>
<?php $st = $storage;
$used = $st['disk'] !== null && $st['free'] !== null ? $st['disk'] - $st['free'] : null;
$freeShare = $st['disk'] ? $st['free'] / $st['disk'] : null;
$backupAge = $st['backup'] > 0 ? (time() - $st['backup']) / 86400 : null;
$groups = ['pages' => ['pages'], 'history' => ['attic'], 'media' => ['media', 'sources'], 'databases' => ['state', 'index', 'vectors'], 'other' => ['trash', 'backups']];
$sum = static fn (array $parts): int => array_sum(array_map(static fn (string $p): int => (int) ($st['parts'][$p]['bytes'] ?? 0), $parts)); ?>
<section class="wk-panel">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('start.storage')) ?></h2></hgroup><a class="wk-start-more" href="<?= $b ?>/?storage=refresh" title="<?= $e(t('start.storage_measured', [MetaText::ago(date('c', (int) $st['at']))])) ?>"><?= $e(t('start.storage_refresh')) ?></a></header>
<?php if ($freeShare !== null && $freeShare < 0.10): ?>
<?php $noticeKind = 'warn';
$noticeIcon = 'hard-drives';
$noticeText = t('start.storage_low', [$pct($freeShare)]);
$noticeLink = null;
include __DIR__ . '/partials/notice.php'; ?>
<?php endif; ?>
<?php if ($backupAge === null || $backupAge > 2): ?>
<?php $noticeKind = 'warn';
$noticeIcon = 'cloud-warning';
$noticeText = $backupAge === null ? t('start.storage_no_backup') : t('start.storage_old_backup', [(int) floor($backupAge)]);
$noticeLink = [$basePath . '/admin/maintenance', t('start.storage_backup_link')];
include __DIR__ . '/partials/notice.php'; ?>
<?php endif; ?>
<p class="wk-ins-total"><b><?= $e($fmtBytes((int) $st['total'])) ?></b> <span class="wk-dim"><?= $e(t('start.storage_in_data')) ?></span></p>
<div class="wk-sbar" role="img" aria-label="<?= $e(t('start.storage')) ?>">
<?php foreach ($groups as $key => $parts): $n = $sum($parts);
    if ($n === 0) {
        continue;
    } ?>
<span class="wk-sbar-st-<?= $e($key) ?>" style="flex-grow: <?= max(1, (int) round($n / max(1, $st['total']) * 1000)) ?>" title="<?= $e(t('start.storage_' . $key) . ': ' . $fmtBytes($n)) ?>"></span>
<?php endforeach; ?>
</div>
<dl class="wk-ins-dl">
<?php foreach ($groups as $key => $parts): ?>
<dt><span class="wk-key wk-sbar-st-<?= $e($key) ?>"></span><?= $e(t('start.storage_' . $key)) ?></dt><dd><b><?= $e($fmtBytes($sum($parts))) ?></b> <span class="wk-dim"><?= $e(t('start.storage_files', [array_sum(array_map(static fn (string $p): int => (int) ($st['parts'][$p]['files'] ?? 0), $parts))])) ?></span></dd>
<?php endforeach; ?>
<dt><?= $e(t('start.storage_disk')) ?></dt><dd><b><?= $e($fmtBytes($st['free'])) ?></b> <span class="wk-dim"><?= $e(t('start.storage_free_of', [$fmtBytes($st['disk']), $pct($freeShare)])) ?></span></dd>
<dt><?= $e(t('start.storage_content')) ?></dt><dd><b><?= (int) $st['pages'] ?></b> <span class="wk-dim"><?= $e(t('start.storage_counts', [(int) $st['revisions'], (int) $st['signatures']])) ?></span></dd>
<dt><?= $e(t('start.storage_backup')) ?></dt><dd><b><?= $e($backupAge === null ? 'n/a' : MetaText::ago(date('c', (int) $st['backup']))) ?></b></dd>
</dl>
</section>
<?php endif; ?>
</div>
<?php endif; ?>

<div class="wk-start-grid">
<section class="wk-panel wk-start-continue">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('start.continue')) ?></h2></hgroup></header>
<?php if ($last === null): ?>
<p class="wk-dim"><?= $e(t('start.continue_none')) ?></p>
<?php else: ?>
<?php $lastPath = (string) $last['path']; ?>
<a class="wk-start-last" href="<?= $b ?>/<?= $e($lastPath) ?>">
<b><?= $e($last['title'] ?: $lastPath) ?></b>
<span class="wk-mono wk-dim"><?= $e($ns($lastPath)) ?></span>
</a>
<div class="wk-badges">
<span class="tag <?= Badges::statusTag((string) $last['status']) ?>" title="<?= $e($last['status']) ?>"><i class="ph <?= \Reporion\Support\Badges::statusIcon((string) $last['status']) ?>" aria-hidden="true"></i><span class="tag-word"> <?= $e($last['status']) ?></span></span>
<?= \Reporion\Support\Visibility::badge((string) $last['visibility']) ?>
<?php if (($last['modality'] ?? '') !== ''): ?><span class="wk-mono"><?= $e($last['modality']) ?></span><?php endif; ?>
<span class="wk-dim"><?= $e(t('start.edited_ago', [MetaText::ago($last['updated'])])) ?></span>
</div>
<?php if (($last['summary'] ?? '') !== ''): ?><p class="wk-start-summary"><?= $e($last['summary']) ?></p><?php endif; ?>
<footer>
<?php if ($lastActions['patient'] ?? false): ?><a class="btn btn-ghost btn-sm" href="<?= $b ?>/<?= $e($lastPath) ?>/timeline" title="<?= $e(t('start.patient')) ?>"><i class="ph ph-clock-counter-clockwise"></i><span class="wk-btn-label"><?= $e(t('start.patient')) ?></span></a><?php endif; ?>
<?php if ($lastActions['edit'] ?? false): ?><a class="btn btn-secondary btn-sm" href="<?= $b ?>/<?= $e($lastPath) ?>/edit" title="<?= $e(t('start.edit')) ?>"><i class="ph ph-pencil-simple"></i><span class="wk-btn-label"><?= $e(t('start.edit')) ?></span></a><?php endif; ?>
<a class="btn btn-primary btn-sm" href="<?= $b ?>/<?= $e($lastPath) ?>" title="<?= $e(t('start.open')) ?>"><i class="ph ph-file-text"></i><span class="wk-btn-label"><?= $e(t('start.open')) ?></span></a>
</footer>
<?php endif; ?>
</section>

<section class="wk-panel">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('start.go_to')) ?></h2></hgroup></header>
<nav class="wk-start-links" aria-label="<?= $e(t('start.go_to')) ?>">
<?php foreach ($quickLinks as $link): ?>
<a class="wk-start-link" <?= ($link['priority'] ?? '') !== '' ? 'data-priority="' . $e($link['priority']) . '" ' : '' ?>href="<?= $b ?><?= $e($link['href']) ?>"><i class="ph ph-<?= $e($link['icon']) ?>"></i><span<?= $link['mono'] ? ' class="wk-mono"' : '' ?>><?= $e($link['label']) ?></span></a>
<?php endforeach; ?>
</nav>
<?php if ($startQuick['pinned'] === []): ?>
<p class="wk-dim wk-start-hint"><?= $e(t('start.pin_help')) ?></p>
<?php endif; ?>
</section>
</div>

<div class="wk-start-grid">
<section class="wk-panel<?= $drafts !== [] ? ' wk-panel-hl' : '' ?>" id="drafts">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t($stats['drafts'] >= $cap ? 'start.drafts_many' : 'start.drafts')) ?></h2></hgroup><?php if ($stats['drafts'] > count($drafts)): ?><span class="wk-start-more"><?= $e(t('start.oldest_of', [count($drafts), $count($stats['drafts'])])) ?></span><?php endif; ?></header>
<?php if ($drafts === []): ?>
<p class="wk-dim"><?= $e(t('start.drafts_none')) ?></p>
<?php else: ?>
<div class="wk-slist">
<?php foreach ($drafts as $page): ?>
<?php $stale = (string) $page['updated'] < $staleBefore; ?>
<?= $row($page, $e($ns((string) $page['path'])) . ' · <span class="' . ($stale ? 'wk-start-stale' : '') . '">' . $e(MetaText::ago($page['updated'])) . '</span>', $canSignPath((string) $page['path']) && (string) $page['pid'] !== '' && ReportPath::isReport((string) $page['path']), false) ?>
<?php endforeach; ?>
</div>
<?php endif; ?>
</section>

<section class="wk-panel" id="mine">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('start.mine')) ?></h2></hgroup><a class="wk-start-more" href="<?= $b ?>/?mine=1"><?= $e(t('start.all_mine')) ?></a></header>
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
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('start.team')) ?></h2></hgroup><a class="wk-start-more" href="<?= $b ?>/?all=1"><?= $e(t('start.all_recent')) ?></a></header>
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

<div class="wk-doc-foot">
<div><span class="wk-eyebrow"><?= $e(t('start.foot_about')) ?></span><p class="wk-dim"><?= $e(t('start.foot_note', [$staleDays])) ?></p></div>
</div>
</div>
<script src="<?= $e(\Reporion\Support\Asset::url($basePath, 'js/notes-promo.js')) ?>" data-dismiss="<?= $e(t('start.notes_dismiss')) ?>" defer></script>
