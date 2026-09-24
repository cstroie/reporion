<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /{path}/history (Controller\HistoryController). Structure/classes
 * ported from design/mockup/WikiHistory.dc.html (.wk-hist / .wk-diff /
 * .wk-difftext / .wk-ctx / .wk-al / .wk-dl). Deliberately NOT ported: the
 * radio-multiselect "compare selected" flow (needs JavaScript state; a
 * per-row "diff vs previous" link plus a plain from/to query string cover
 * the useful case without it — see docs/BUILD_LOG.md) and the
 * unified/side-by-side/rendered diff-view toggle (only "unified" exists).
 *
 * Chrome: templates/rail.php + templates/tabs.php (Workbench chrome) — the
 * "Back to page" button that used to sit in .wk-actions is dropped, same
 * reasoning as page-view.php's now-gone standalone Edit/History buttons:
 * the tab strip's Report tab already goes back to the page (see
 * docs/BUILD_LOG.md).
 *
 * Variables in scope (see Controller\HistoryController::history()):
 * string $path; int $currentRev, ?int $from, ?int $to; bool $canWrite; string $basePath
 * list<array{entry: array<string,mixed>, counts: ?array{add:int,remove:int}}> $rows
 * ?list<array{op:string,line:string}> $diffLines
 */

declare(strict_types=1);

/** @var string $path */
/** @var list<array{entry: array<string, mixed>, counts: ?array{add: int, remove: int}}> $rows */
/** @var int $currentRev */
/** @var ?int $from */
/** @var ?int $to */
/** @var ?list<array{op: string, line: string}> $diffLines */
/** @var bool $canWrite */
/** @var string $basePath */
/** @var bool $isOwner */
/** @var bool $canCreate */
/** @var ?string $railEditHref */
/** @var string $railActive */
/** @var string $tabActive */
/** @var string $worklistNs */
/** @var list<array<string, mixed>> $worklistRows */
/** @var string $theme */
/** @var string $themeBodyClass */
/** @var string $currentUrl */
/** @var int $statusTotal */
/** @var int $statusDraft */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(t('page.history'), ENT_QUOTES) ?> — <?= htmlspecialchars($path, ENT_QUOTES) ?> — <?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?></title>
<link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/css/tokens.css">
<link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/css/wiki.css">
<link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/css/fontawesome.css">
</head>
<body class="wk wk-shell<?= htmlspecialchars($themeBodyClass, ENT_QUOTES) ?>">
<div class="wk-body wk-body-worklist">
<?php include __DIR__ . '/rail.php'; ?>
<?php include __DIR__ . '/worklist.php'; ?>
<div class="wk-col">
<div class="wk-top">
<span class="wk-brand"><?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?></span>
<form class="wk-search" action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/search" method="get" data-island="palette" data-config-id="palette-config">
<input type="search" name="q" placeholder="<?= htmlspecialchars(t('nav.search'), ENT_QUOTES) ?>">
</form>
<script type="application/json" id="palette-config"><?= json_encode(['basePath' => $basePath], JSON_HEX_TAG) ?></script>
</div>
<?php include __DIR__ . '/tabs.php'; ?>
<main class="wk-pad">
<div class="wk-doc">
<div class="wk-doc-head">
<div class="wk-crumbs wk-mono">
<?php $segments = explode(':', $path); $last = array_key_last($segments); $prefix = []; ?>
<?php foreach ($segments as $i => $segment): ?>
<?php $prefix[] = $segment; ?>
<?php if ($i === $last): ?><a href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($path, ENT_QUOTES) ?>"><?= htmlspecialchars($segment, ENT_QUOTES) ?></a><span>›</span><b><?= htmlspecialchars(t('page.history'), ENT_QUOTES) ?></b>
<?php else: ?><a href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars(implode(':', $prefix), ENT_QUOTES) ?>:"><?= htmlspecialchars($segment, ENT_QUOTES) ?></a><span>›</span>
<?php endif; ?>
<?php endforeach; ?>
</div>
<div class="wk-doc-titlerow">
<h1 class="wk-doc-title"><?= htmlspecialchars(t('page.history'), ENT_QUOTES) ?></h1>
</div>
<div class="wk-badges">
<span class="tag tag-neutral"><?= htmlspecialchars(t('history.rev_count', [\count($rows)]), ENT_QUOTES) ?></span>
</div>
</div>

<table class="table wk-hist">
<thead><tr>
<th><?= htmlspecialchars(t('history.col_rev'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('history.col_when'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('history.col_author'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('history.col_change'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('history.col_note'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('history.col_size'), ENT_QUOTES) ?></th>
<th></th>
</tr></thead>
<tbody>
<?php foreach (array_reverse($rows) as $row): ?>
<?php $entry = $row['entry']; $rev = (int) $entry['n']; $isCurrent = $rev === $currentRev; ?>
<tr>
<td class="wk-mono"><?= $rev ?></td>
<td><?= htmlspecialchars((string) $entry['ts'], ENT_QUOTES) ?></td>
<td><?= htmlspecialchars((string) $entry['by'], ENT_QUOTES) ?></td>
<td class="wk-mono">
<?php if ($row['counts'] !== null): ?>
<?php if ($row['counts']['add'] > 0): ?><span class="wk-add">+<?= $row['counts']['add'] ?></span><?php endif; ?>
<?php if ($row['counts']['remove'] > 0): ?> <span class="wk-del">−<?= $row['counts']['remove'] ?></span><?php endif; ?>
<?php endif; ?>
</td>
<td><?= htmlspecialchars((string) ($entry['note'] ?? ''), ENT_QUOTES) ?></td>
<td class="wk-mono"><?= htmlspecialchars(t('history.bytes', [(int) $entry['bytes']]), ENT_QUOTES) ?></td>
<td>
<?php if ($isCurrent): ?>
<button class="btn btn-ghost btn-sm" type="button" disabled><?= htmlspecialchars(t('history.current'), ENT_QUOTES) ?></button>
<?php else: ?>
<a class="btn btn-ghost btn-sm" href="?from=<?= $rev ?>&to=<?= $currentRev ?>"><?= htmlspecialchars(t('history.diff'), ENT_QUOTES) ?></a>
<?php if ($canWrite): ?>
<form action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($path, ENT_QUOTES) ?>/history/revert" method="post" style="display:inline">
<input type="hidden" name="to" value="<?= $rev ?>">
<button class="btn btn-secondary btn-sm" type="submit"><?= htmlspecialchars(t('history.restore'), ENT_QUOTES) ?></button>
</form>
<?php endif; ?>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<?php if ($diffLines !== null): ?>
<div class="wk-diff">
<div class="wk-diff-h"><span class="wk-eyebrow"><?= htmlspecialchars(t('history.diff_title', [$from, $to]), ENT_QUOTES) ?></span></div>
<pre class="wk-mono wk-difftext"><?php foreach ($diffLines as $line): ?><?php
    $class = match ($line['op']) {
        'add' => 'wk-al',
        'remove' => 'wk-dl',
        default => 'wk-ctx',
    };
    $prefix = match ($line['op']) {
        'add' => '+ ',
        'remove' => '- ',
        default => '  ',
    };
?><span class="<?= $class ?>"><?= htmlspecialchars($prefix . $line['line'], ENT_QUOTES) ?></span>
<?php endforeach; ?></pre>
</div>
<?php endif; ?>
</div>
</main>
</div>
</div>
<?php include __DIR__ . '/status.php'; ?>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/js/palette.js" defer></script>
</body>
</html>
