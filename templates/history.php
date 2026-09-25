<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /{path}/history (Controller\HistoryController). Structure/classes
 * ported from design/mockup/WikiHistory.dc.html (.wk-hist / .wk-diff /
 * .wk-difftext / .wk-ctx / .wk-al / .wk-dl). The .wk-radio multiselect IS
 * wired up — clicking a row's dot toggles it, a small inline script (bottom
 * of this file, same pattern as editor.php's preview toggle) caps the
 * selection at two and enables "Compare selected", which navigates to
 * ?from=&to= — plain query-string state, no autosave/draft concerns like
 * the editor island, so it doesn't need a real JS module. Rows already
 * marking the from/to of a displayed diff start pre-selected. The diff
 * header's unified/side-by-side/rendered .seg toggle is present in the
 * markup like editor.php's formatting toolbar (docs/BUILD_LOG.md) — only
 * "unified" is wired up; the other two options don't switch views yet.
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
<link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/css/phosphor.css">
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
<div class="wk-actions">
<button class="btn btn-secondary" type="button" id="history-compare-btn" disabled><i class="ph ph-git-diff"></i><?= htmlspecialchars(t('history.compare_selected'), ENT_QUOTES) ?></button>
</div>
</div>
<div class="wk-badges">
<span class="tag tag-neutral"><?= htmlspecialchars(t('history.rev_count', [\count($rows)]), ENT_QUOTES) ?></span>
</div>
</div>

<table class="table wk-hist">
<thead><tr>
<th></th>
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
<?php $isDiffEndpoint = $diffLines !== null && ($rev === $from || $rev === $to); ?>
<tr>
<td><button type="button" class="wk-radio-btn" data-rev="<?= $rev ?>" aria-label="select rev <?= $rev ?> for diff"><span class="wk-radio<?= $isDiffEndpoint ? ' wk-on' : '' ?>"></span></button></td>
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
<div class="wk-diff-h">
<span class="wk-eyebrow"><?= htmlspecialchars(t('history.diff_title', [$from, $to]), ENT_QUOTES) ?></span>
<div class="wk-actions">
<span class="seg">
<label class="seg-opt"><input type="radio" name="dv" checked><?= htmlspecialchars(t('history.view_unified'), ENT_QUOTES) ?></label>
<label class="seg-opt"><input type="radio" name="dv"><?= htmlspecialchars(t('history.view_side'), ENT_QUOTES) ?></label>
<label class="seg-opt"><input type="radio" name="dv"><?= htmlspecialchars(t('history.view_rendered'), ENT_QUOTES) ?></label>
</span>
<?php if ($canWrite): ?>
<form action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($path, ENT_QUOTES) ?>/history/revert" method="post" style="display:inline">
<input type="hidden" name="to" value="<?= $from ?>">
<button class="btn btn-primary btn-sm" type="submit"><i class="ph ph-arrow-counter-clockwise"></i><?= htmlspecialchars(t('history.diff_restore', [$from]), ENT_QUOTES) ?></button>
</form>
<?php endif; ?>
</div>
</div>
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
<script>
(function() {
  var buttons = Array.prototype.slice.call(document.querySelectorAll('.wk-radio-btn'));
  var compareBtn = document.getElementById('history-compare-btn');
  if (!buttons.length || !compareBtn) return;
  var selected = [];
  buttons.forEach(function(btn) {
    if (btn.querySelector('.wk-radio').classList.contains('wk-on')) selected.push(btn.dataset.rev);
  });
  compareBtn.disabled = selected.length !== 2;
  buttons.forEach(function(btn) {
    btn.addEventListener('click', function() {
      var rev = btn.dataset.rev;
      var idx = selected.indexOf(rev);
      if (idx !== -1) {
        selected.splice(idx, 1);
        btn.querySelector('.wk-radio').classList.remove('wk-on');
      } else {
        if (selected.length >= 2) {
          var oldest = selected.shift();
          var oldestBtn = buttons.filter(function(b) { return b.dataset.rev === oldest; })[0];
          if (oldestBtn) oldestBtn.querySelector('.wk-radio').classList.remove('wk-on');
        }
        selected.push(rev);
        btn.querySelector('.wk-radio').classList.add('wk-on');
      }
      compareBtn.disabled = selected.length !== 2;
    });
  });
  compareBtn.addEventListener('click', function() {
    if (selected.length !== 2) return;
    var nums = selected.map(Number).sort(function(a, b) { return a - b; });
    location.search = '?from=' + nums[0] + '&to=' + nums[1];
  });
})();
</script>
</body>
</html>
