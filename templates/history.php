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
 * marking the from/to of a displayed diff start pre-selected. The diff is
 * unified only; the mockup's side-by-side/rendered toggle is not rendered
 * until those views exist (rendered side by side is /{path}/compare).
 *
 * Content only: Http\View::page() wraps it in templates/layout.php, whose
 * page header shows the page and its tabs (A6).
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
?>
<div class="wk-doc">
<div class="wk-doc-titlerow wk-sec">
<h2 class="wk-sec-title"><?= htmlspecialchars(t('history.rev_count', [\count($rows)]), ENT_QUOTES) ?></h2>
<div class="wk-actions">
<button class="btn btn-secondary btn-sm" type="button" id="history-compare-btn" disabled><i class="ph ph-git-diff"></i><?= htmlspecialchars(t('history.compare_selected'), ENT_QUOTES) ?></button>
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
