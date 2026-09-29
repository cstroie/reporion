<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /{path}/revisions (Controller\RevisionsController) — the page's own
 * revision history, not to be confused with the Patient tab's timeline
 * (2026-09-30: renamed from "History" for exactly that ambiguity). Structure/
 * classes ported from design/mockup/WikiHistory.dc.html (.wk-rev / .wk-diff /
 * .wk-difftext / .wk-ctx / .wk-al / .wk-dl). The .wk-radio multiselect IS
 * wired up — clicking a row's dot toggles it, caps the selection at two,
 * and picking the second one navigates straight to ?from=&to= (2026-09-30:
 * no separate "Compare selected" step) — plain query-string state, no
 * autosave/draft concerns like the editor island, so it doesn't need a
 * real JS module. Rows already marking the from/to of a displayed diff
 * start pre-selected. The diff is unified only; the mockup's
 * side-by-side/rendered toggle is not rendered until those views exist
 * (rendered side by side is /{path}/compare).
 *
 * Content only: Http\View::page() wraps it in templates/layout.php, whose
 * page header shows the page and its tabs (A6).
 *
 * Variables in scope (see Controller\RevisionsController::revisions()):
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
<h2 class="wk-sec-title"><?= htmlspecialchars(t('revisions.rev_count', [\count($rows)]), ENT_QUOTES) ?></h2>
</div>
<table class="table wk-rev">
<thead><tr>
<th></th>
<th><?= htmlspecialchars(t('revisions.col_rev'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('revisions.col_when'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('revisions.col_author'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('revisions.col_change'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('revisions.col_note'), ENT_QUOTES) ?></th>
<th><?= htmlspecialchars(t('revisions.col_size'), ENT_QUOTES) ?></th>
<th></th>
</tr></thead>
<tbody>
<?php foreach (array_reverse($rows) as $row): ?>
<?php $entry = $row['entry']; $rev = (int) $entry['n']; $isCurrent = $rev === $currentRev; ?>
<?php $isDiffEndpoint = $diffLines !== null && ($rev === $from || $rev === $to); ?>
<tr>
<td><button type="button" class="wk-radio-btn" data-rev="<?= $rev ?>" aria-label="select rev <?= $rev ?> for diff"><span class="wk-radio<?= $isDiffEndpoint ? ' wk-on' : '' ?>"></span></button></td>
<td class="wk-mono"><?= $rev ?></td>
<td><?= htmlspecialchars(\Reporion\Support\MetaText::when($entry['ts'] ?? null), ENT_QUOTES) ?></td>
<td><?= htmlspecialchars(display_name((string) $entry['by']), ENT_QUOTES) ?></td>
<td class="wk-mono">
<?php if ($row['counts'] !== null): ?>
<?php if ($row['counts']['add'] > 0): ?><span class="wk-add">+<?= $row['counts']['add'] ?></span><?php endif; ?>
<?php if ($row['counts']['remove'] > 0): ?> <span class="wk-del">−<?= $row['counts']['remove'] ?></span><?php endif; ?>
<?php endif; ?>
</td>
<td><?= htmlspecialchars((string) ($entry['note'] ?? ''), ENT_QUOTES) ?></td>
<td class="wk-mono"><?= htmlspecialchars(t('revisions.bytes', [(int) $entry['bytes']]), ENT_QUOTES) ?></td>
<td>
<?php if ($isCurrent): ?>
<button class="btn btn-ghost btn-sm" type="button" disabled><?= htmlspecialchars(t('revisions.current'), ENT_QUOTES) ?></button>
<?php else: ?>
<a class="btn btn-ghost btn-sm" href="?from=<?= $rev ?>&to=<?= $currentRev ?>"><?= htmlspecialchars(t('revisions.diff'), ENT_QUOTES) ?></a>
<?php if ($canWrite): ?>
<form action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($path, ENT_QUOTES) ?>/revisions/revert" method="post" style="display:inline">
<input type="hidden" name="to" value="<?= $rev ?>">
<button class="btn btn-secondary btn-sm" type="submit"><?= htmlspecialchars(t('revisions.restore'), ENT_QUOTES) ?></button>
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
<span class="wk-eyebrow"><?= htmlspecialchars(t('revisions.diff_title', [$from, $to]), ENT_QUOTES) ?></span>
<div class="wk-actions">
<?php if ($canWrite): ?>
<form action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($path, ENT_QUOTES) ?>/revisions/revert" method="post" style="display:inline">
<input type="hidden" name="to" value="<?= $from ?>">
<button class="btn btn-primary btn-sm" type="submit"><i class="ph ph-arrow-counter-clockwise"></i><?= htmlspecialchars(t('revisions.diff_restore', [$from]), ENT_QUOTES) ?></button>
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
    // No newline between the spans: they are blocks, and in a <pre> a newline would be one more line
?><span class="<?= $class ?>"><?= htmlspecialchars($prefix . $line['line'], ENT_QUOTES) ?></span><?php endforeach; ?></pre>
</div>
<?php endif; ?>
</div>
<script>
(function() {
  var buttons = Array.prototype.slice.call(document.querySelectorAll('.wk-radio-btn'));
  if (!buttons.length) return;
  var selected = [];
  buttons.forEach(function(btn) {
    if (btn.querySelector('.wk-radio').classList.contains('wk-on')) selected.push(btn.dataset.rev);
  });
  buttons.forEach(function(btn) {
    btn.addEventListener('click', function() {
      var rev = btn.dataset.rev;
      var idx = selected.indexOf(rev);
      if (idx !== -1) {
        selected.splice(idx, 1);
        btn.querySelector('.wk-radio').classList.remove('wk-on');
        return;
      }
      if (selected.length >= 2) {
        var oldest = selected.shift();
        var oldestBtn = buttons.filter(function(b) { return b.dataset.rev === oldest; })[0];
        if (oldestBtn) oldestBtn.querySelector('.wk-radio').classList.remove('wk-on');
      }
      selected.push(rev);
      btn.querySelector('.wk-radio').classList.add('wk-on');
      // The second pick runs the diff immediately — no separate "Compare selected" step
      if (selected.length === 2) {
        var nums = selected.map(Number).sort(function(a, b) { return a - b; });
        location.search = '?from=' + nums[0] + '&to=' + nums[1];
      }
    });
  });
})();
</script>
