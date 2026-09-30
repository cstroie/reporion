<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /{path}/revisions (Controller\RevisionsController) — the page's own
 * revision history, not to be confused with the Patient tab's timeline
 * (2026-09-30: renamed from "History" for exactly that ambiguity).
 * Structure/classes ported from design/mockup/WikiHistory.dc.html (.wk-rev /
 * .wk-diff / .wk-difftext / .wk-ctx / .wk-al / .wk-dl). The .wk-radio
 * multiselect IS wired up — clicking a row's dot toggles it, caps the
 * selection at two, and picking the second one navigates straight to
 * ?from=&to= (2026-09-30: no separate "Compare selected" step), carrying
 * the current style along — plain query-string state, no autosave/draft
 * concerns like the editor island, so it doesn't need a real JS module.
 * Rows already marking the from/to of a displayed diff start pre-selected.
 *
 * Absorbs the old Compare tab (2026-09-30, see Controller\RevisionsController's
 * header): the .wk-rev-bar toolbar — plain from/to dates (which two
 * revisions is the row picker's job, not a second control) and a three-way
 * style switch, the mockup's Profile "Reading size" .seg control
 * (design/mockup/WikiProfile.dc.html) — replaces Compare's own from/to
 * selects. **word** (default, Compare's track-changes read), **line**
 * (this screen's original unified diff — also the automatic fallback when
 * word doesn't fit, Support\Diff::wordsFits(), or a side's frontmatter
 * doesn't parse), **side** (Compare's other render: two full pages through
 * the canonical renderer, .wk-cmp).
 *
 * Content only: Http\View::page() wraps it in templates/layout.php, whose
 * page header shows the page and its tabs (A6).
 *
 * Variables in scope (see Controller\RevisionsController::revisions()):
 * string $path, $basePath, $requestedStyle; int $currentRev; ?int $from, $to;
 * bool $canWrite;
 * list<array{entry: array<string,mixed>, counts: ?array{add:int,remove:int}}> $rows
 * ?array{
 *   style: 'word'|'line'|'side',
 *   ops: ?list<array{op:string,line:string}>,
 *   panes: ?list<array{rev:int,ts:string,title:string,html:?string,raw:string}>,
 *   fromTs: string, toTs: string,
 * } $diff
 */

declare(strict_types=1);

/** @var string $path */
/** @var list<array{entry: array<string, mixed>, counts: ?array{add: int, remove: int}}> $rows */
/** @var int $currentRev */
/** @var ?int $from */
/** @var ?int $to */
/** @var string $requestedStyle */
/** @var ?array{style: string, ops: ?list<array{op: string, line: string}>, panes: ?list<array{rev: int, ts: string, title: string, html: ?string, raw: string}>, fromTs: string, toTs: string} $diff */
/** @var bool $canWrite */
/** @var string $basePath */

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$b = $e($basePath);
$p = $b . '/' . $e($path);
?>
<div class="wk-doc">
<div class="wk-doc-titlerow wk-sec">
<h2 class="wk-sec-title"><?= htmlspecialchars(\count($rows) === 1 ? t('revisions.rev_count_one') : t('revisions.rev_count', [\count($rows)]), ENT_QUOTES) ?></h2>
</div>

<?php if ($diff !== null): ?>
<form class="wk-rev-bar" method="get" action="<?= $p ?>/revisions" id="rev-style-form">
<input type="hidden" name="from" value="<?= $from ?>">
<input type="hidden" name="to" value="<?= $to ?>">
<span class="wk-mono wk-dim"><?= $e(\Reporion\Support\MetaText::when($diff['fromTs'])) ?></span>
<i class="ph ph-arrow-right wk-dim" aria-hidden="true"></i>
<span class="wk-mono wk-dim"><?= $e(\Reporion\Support\MetaText::when($diff['toTs'])) ?></span>
<span class="seg">
<label class="seg-opt"><input type="radio" name="style" value="word"<?= $requestedStyle === 'word' ? ' checked' : '' ?>><?= $e(t('revisions.style_word')) ?></label>
<label class="seg-opt"><input type="radio" name="style" value="line"<?= $requestedStyle === 'line' ? ' checked' : '' ?>><?= $e(t('revisions.style_line')) ?></label>
<label class="seg-opt"><input type="radio" name="style" value="side"<?= $requestedStyle === 'side' ? ' checked' : '' ?>><?= $e(t('revisions.style_side')) ?></label>
</span>
<button type="submit" class="btn btn-secondary" id="rev-style-apply"><?= $e(t('revisions.style_apply')) ?></button>
</form>
<script>
(function() {
  var form = document.getElementById('rev-style-form');
  var btn = document.getElementById('rev-style-apply');
  if (!form) return;
  // With JS, picking a style re-submits immediately; the button hides once
  // this runs, and stays a working fallback without JS (same pattern the
  // old Compare form used for its from/to selects).
  if (btn) btn.hidden = true;
  Array.prototype.forEach.call(form.querySelectorAll('[name="style"]'), function(radio) {
    radio.addEventListener('change', function() { form.submit(); });
  });
})();
</script>
<?php elseif (\count($rows) < 2): ?>
<p class="wk-dim"><?= htmlspecialchars(t('revisions.single_rev'), ENT_QUOTES) ?></p>
<?php endif; ?>

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
<?php $isDiffEndpoint = $diff !== null && ($rev === $from || $rev === $to); ?>
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
<a class="btn btn-ghost btn-sm" href="?from=<?= $rev ?>&to=<?= $currentRev ?>&style=<?= $e($requestedStyle) ?>"><?= htmlspecialchars(t('revisions.diff'), ENT_QUOTES) ?></a>
<?php if ($canWrite): ?>
<form action="<?= $p ?>/revisions/revert" method="post" style="display:inline">
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

<?php if ($diff !== null): ?>
<div class="wk-diff">
<?php /* No Restore action here — every non-current row in the table above already has its own (2026-09-30) */ ?>
<?php if ($diff['style'] !== $requestedStyle): ?>
<div class="wk-diff-h"><span class="wk-eyebrow wk-dim"><?= htmlspecialchars(t('revisions.style_fallback'), ENT_QUOTES) ?></span></div>
<?php endif; ?>
<?php if ($diff['style'] === 'word'): ?>
<div class="wk-worddiff wk-prose"><?php foreach ($diff['ops'] as $op): ?><?php
    $text = htmlspecialchars($op['line'], ENT_QUOTES);
    echo match ($op['op']) {
        'add' => '<ins>' . $text . '</ins>',
        'remove' => '<del>' . $text . '</del>',
        default => $text,
    };
?><?php endforeach; ?></div>
<?php elseif ($diff['style'] === 'side'): ?>
<div class="wk-cmp">
<?php foreach ($diff['panes'] as $pane): ?>
<div>
<div class="wk-crumbs wk-mono"><b><?= htmlspecialchars(t('revisions.rev_label', [$pane['rev']]), ENT_QUOTES) ?></b><span class="wk-dim"><?= htmlspecialchars(\Reporion\Support\MetaText::when($pane['ts']), ENT_QUOTES) ?></span></div>
<?php if ($pane['html'] !== null): ?>
<?php if ($pane['title'] !== ''): ?><h2><?= htmlspecialchars($pane['title'], ENT_QUOTES) ?></h2><?php endif; ?>
<div class="wk-prose"><?= $pane['html'] /* Render::toHtml() output, the same canonical HTML the page view prints */ ?></div>
<?php else: ?>
<pre class="wk-mono"><?= htmlspecialchars($pane['raw'], ENT_QUOTES) ?></pre>
<?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php else: ?>
<pre class="wk-mono wk-difftext"><?php foreach ($diff['ops'] as $line): ?><?php
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
<?php endif; ?>
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
  var style = <?= json_encode($requestedStyle, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
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
        location.search = '?from=' + nums[0] + '&to=' + nums[1] + '&style=' + encodeURIComponent(style);
      }
    });
  });
})();
</script>
