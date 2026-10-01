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
 * header): a three-way style switch in the title row's actions (2026-10-01;
 * which two revisions is the row picker's job, marked in the table — no
 * from/to bar), the mockup's Profile "Reading size" .seg control
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
/** @var ?array{path: string, title: string, body: string, ts: string, by: string} $template */
/** @var ?array{path: string, title: string, body: string, ts: string, by: string} $template */
/** @var bool $canWrite */
/** @var string $basePath */

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$b = $e($basePath);
$p = $b . '/' . $e($path);
?>
<div class="wk-doc">
<div class="wk-doc-titlerow wk-sec"><hgroup>
<h2 class="wk-sec-title"><?= $e(\count($rows) === 1 ? t('revisions.rev_count_one') : t('revisions.rev_count', [\count($rows)])) ?></h2>
<p class="wk-dim"><?= $e(t('revisions.subtitle') . ($canWrite ? ' ' . t('revisions.subtitle_restore') : '')) ?></p>
</hgroup>
<?php if (\count($rows) >= 2 || $template !== null): ?>
<?php /* The diff style: re-renders an open diff, or sets the style the next comparison uses */ ?>
<form class="wk-actions" method="get" action="<?= $p ?>/revisions" id="rev-style-form">
<?php if ($diff !== null): ?>
<input type="hidden" name="from" value="<?= $from ?>">
<input type="hidden" name="to" value="<?= $to ?>">
<?php endif; ?>
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
  // With JS, picking a style re-submits immediately; the button hides once
  // this runs, and stays a working fallback without JS.
  if (btn) btn.hidden = true;
  Array.prototype.forEach.call(form.querySelectorAll('[name="style"]'), function(radio) {
    radio.addEventListener('change', function() { form.submit(); });
  });
})();
</script>
<?php endif; ?>
</div>

<?php if ($diff === null && \count($rows) < 2 && $template === null): ?>
<div class="wk-notice wk-mb-4" role="status"><i class="ph ph-info"></i><div><?= $e(t('revisions.single_rev')) ?></div></div>
<?php endif; ?>

<div class="wk-panel">
<table class="table wk-rev table-cards">
<thead><tr>
<th></th>
<th><?= $e(t('revisions.col_rev')) ?></th>
<th><?= $e(t('revisions.col_when')) ?></th>
<th><?= $e(t('revisions.col_author')) ?></th>
<th><?= $e(t('revisions.col_change')) ?></th>
<th><?= $e(t('revisions.col_note')) ?></th>
<th><?= $e(t('revisions.col_size')) ?></th>
<th></th>
</tr></thead>
<tbody>
<?php foreach (array_reverse($rows) as $row): ?>
<?php $entry = $row['entry']; $rev = (int) $entry['n']; $isCurrent = $rev === $currentRev; ?>
<?php $isDiffEndpoint = $diff !== null && ($rev === $from || $rev === $to); ?>
<tr>
<td data-label="<?= $e(t('revisions.col_select')) ?>"><div class="wk-cell"><button type="button" class="wk-radio-btn" data-rev="<?= $rev ?>" aria-label="select rev <?= $rev ?> for diff"><span class="wk-radio<?= $isDiffEndpoint ? ' wk-on' : '' ?>"></span></button></div></td>
<td class="wk-mono" data-label="<?= $e(t('revisions.col_rev')) ?>"><div class="wk-cell"><?= $rev ?></div></td>
<td class="wk-nowrap" data-label="<?= $e(t('revisions.col_when')) ?>"><div class="wk-cell"><?= $e(\Reporion\Support\MetaText::when($entry['ts'] ?? null)) ?></div></td>
<td data-label="<?= $e(t('revisions.col_author')) ?>"><div class="wk-cell"><?= $e(display_name((string) $entry['by'])) ?></div></td>
<td class="wk-mono<?= $row['counts'] === null || ($row['counts']['add'] === 0 && $row['counts']['remove'] === 0) ? ' wk-nocard' : '' ?>" data-label="<?= $e(t('revisions.col_change')) ?>"><div class="wk-cell">
<?php if ($row['counts'] !== null): ?>
<?php if ($row['counts']['add'] > 0): ?><span class="wk-add">+<?= $row['counts']['add'] ?></span><?php endif; ?>
<?php if ($row['counts']['remove'] > 0): ?> <span class="wk-del">−<?= $row['counts']['remove'] ?></span><?php endif; ?>
<?php endif; ?>
</div></td>
<td class="wk-dim<?= (string) ($entry['note'] ?? '') === '' ? ' wk-nocard' : '' ?>" data-label="<?= $e(t('revisions.col_note')) ?>"><div class="wk-cell"><?= $e((string) ($entry['note'] ?? '')) ?></div></td>
<td class="wk-mono wk-dim wk-nowrap" data-label="<?= $e(t('revisions.col_size')) ?>"><div class="wk-cell"><?= $e(t('revisions.bytes', [(int) $entry['bytes']])) ?></div></td>
<td class="wk-right wk-nowrap">
<?php if ($isCurrent): ?>
<span class="tag tag-accent"><?= $e(t('revisions.current')) ?></span>
<?php else: ?>
<div class="wk-actions">
<a class="btn btn-ghost btn-sm" href="?from=<?= $rev ?>&to=<?= $currentRev ?>&style=<?= $e($requestedStyle) ?>"><?= $e(t('revisions.diff')) ?></a>
<?php if ($canWrite): ?>
<form action="<?= $p ?>/revisions/revert" method="post">
<input type="hidden" name="to" value="<?= $rev ?>">
<button class="btn btn-secondary btn-sm" type="submit"><?= $e(t('revisions.restore')) ?></button>
</form>
<?php endif; ?>
</div>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
<?php if ($template !== null): ?>
<?php /* Revision zero: the template the page names — compare with it like any revision; never restored */ ?>
<tr class="wk-rev-zero">
<td data-label="<?= $e(t('revisions.col_select')) ?>"><div class="wk-cell"><button type="button" class="wk-radio-btn" data-rev="0" aria-label="select the template for diff"><span class="wk-radio<?= $diff !== null && ($from === 0 || $to === 0) ? ' wk-on' : '' ?>"></span></button></div></td>
<td class="wk-mono" data-label="<?= $e(t('revisions.col_rev')) ?>"><div class="wk-cell">0</div></td>
<td class="wk-nowrap" data-label="<?= $e(t('revisions.col_when')) ?>"><div class="wk-cell"><?= $e(\Reporion\Support\MetaText::when($template['ts'] !== '' ? $template['ts'] : null)) ?></div></td>
<td data-label="<?= $e(t('revisions.col_author')) ?>"><div class="wk-cell"><?= $e($template['by'] !== '' ? display_name($template['by']) : '—') ?></div></td>
<td class="wk-mono wk-nocard" data-label="<?= $e(t('revisions.col_change')) ?>"><div class="wk-cell"></div></td>
<td data-label="<?= $e(t('revisions.col_note')) ?>"><div class="wk-cell"><span class="tag tag-outline"><?= $e(t('revisions.template')) ?></span> <a class="wk-mono" href="<?= $b ?>/<?= $e($template['path']) ?>"><?= $e($template['path']) ?></a></div></td>
<td class="wk-mono wk-dim wk-nowrap" data-label="<?= $e(t('revisions.col_size')) ?>"><div class="wk-cell"><?= $e(t('revisions.bytes', [\strlen($template['body'])])) ?></div></td>
<td class="wk-right wk-nowrap"><div class="wk-actions"><a class="btn btn-ghost btn-sm" href="?from=0&to=<?= $currentRev ?>&style=<?= $e($requestedStyle) ?>"><?= $e(t('revisions.diff')) ?></a></div></td>
</tr>
<?php endif; ?>
</tbody>
</table>
</div>

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
<div class="wk-crumbs wk-mono"><b><?= htmlspecialchars($pane['rev'] === 0 ? t('revisions.template') : t('revisions.rev_label', [$pane['rev']]), ENT_QUOTES) ?></b><span class="wk-dim"><?= htmlspecialchars(\Reporion\Support\MetaText::when($pane['ts']), ENT_QUOTES) ?></span></div>
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
