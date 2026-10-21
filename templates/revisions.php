<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /{path}/revisions (Controller\RevisionsController): the page's own
 * revision history, not to be confused with the Timeline tab's timeline
 * (2026-09-30: renamed from "History" for exactly that ambiguity).
 * Structure/classes ported from design/mockup/WikiHistory.dc.html (.wk-rev /
 * .wk-diff / .wk-difftext / .wk-ctx / .wk-al / .wk-dl). The .wk-radio
 * multiselect IS wired up: clicking a row's dot toggles it, caps the
 * selection at two, and picking the second one navigates straight to
 * ?from=&to= (2026-09-30: no separate "Compare selected" step), carrying
 * the current style along: plain query-string state, no autosave/draft
 * concerns like the editor island, so it doesn't need a real JS module.
 * Rows already marking the from/to of a displayed diff start pre-selected.
 *
 * Absorbs the old Compare tab (2026-09-30, see Controller\RevisionsController's
 * header): a three-way style switch in the title row's actions (2026-10-01;
 * which two revisions is the row picker's job, marked in the table: no
 * from/to bar), the mockup's Profile "Reading size" .seg control
 * (design/mockup/WikiProfile.dc.html): replaces Compare's own from/to
 * selects. **word** (default, Compare's track-changes read), **line**
 * (this screen's original unified diff: also the automatic fallback when
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
 *   hunks?: ?list<array{heading: string, lines: list<string>}>,   (the line style, as Support\CommitDiff writes it)
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
/** @var bool $ignoreSpace */
/** @var ?array{style: string, ops: ?list<array{op: string, line: string}>, panes: ?list<array{rev: int, ts: string, title: string, html: ?string, raw: string}>, fromTs: string, toTs: string} $diff */
/** @var ?array{path: string, paths: list<string>, title: string, body: string, ts: string, by: string} $template */
/** @var ?array{path: string, paths: list<string>, title: string, body: string, ts: string, by: string} $template */
/** @var bool $canWrite */
/** @var string $basePath */

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$b = $e($basePath);
$p = $b . '/' . $e($path);
$keep = '&amp;style=' . $e($requestedStyle) . ($ignoreSpace ? '&amp;ws=1' : '');
$bar = static function (?array $c): string {
    if ($c === null || ($c['add'] === 0 && $c['remove'] === 0)) {
        return '';
    }
    $total = $c['add'] + $c['remove'];
    $green = (int) round(5 * $c['add'] / $total);
    if ($c['add'] > 0) {
        $green = max(1, $green);
    }
    if ($c['remove'] > 0) {
        $green = min(4, $green);
    }
    $out = '<span class="wk-bar" aria-hidden="true">';
    for ($i = 0; $i < 5; ++$i) {
        $out .= '<i class="' . ($i < $green ? 'wk-bar-add' : 'wk-bar-del') . '"></i>';
    }

    return $out . '</span>';
};
?>
<div class="wk-doc">
<div class="wk-doc-titlerow wk-sec"><hgroup>
<h2 class="wk-sec-title"><?= $e(\count($rows) === 1 ? t('revisions.rev_count_one') : t('revisions.rev_count', [\count($rows)])) ?></h2>
<p class="wk-dim"><?= $e(t('revisions.subtitle') . ($canWrite ? ' ' . t('revisions.subtitle_restore') : '')) ?></p>
</hgroup>
</div>

<?php if ($diff === null && \count($rows) < 2 && $template === null): ?>
<?php $noticeKind = 'info'; $noticeText = t('revisions.single_rev'); $noticeClass = 'wk-mb-4'; include __DIR__ . '/partials/notice.php'; ?>
<?php endif; ?>

<div class="wk-panel">
<header class="wk-panel-h"><hgroup><h2 class="wk-eyebrow"><?= $e(t('revisions.panel')) ?></h2><p class="wk-dim"><?= $e(t('revisions.panel_help')) ?></p></hgroup></header>
<table class="table wk-rev table-cards">
<caption class="wk-vh"><?= $e(t('revisions.table')) ?></caption>
<thead><tr>
<th><span class="wk-vh"><?= $e(t('revisions.col_select')) ?></span></th>
<th><?= $e(t('revisions.col_rev')) ?></th>
<th><?= $e(t('revisions.col_when')) ?></th>
<th><?= $e(t('revisions.col_author')) ?></th>
<th><?= $e(t('revisions.col_change')) ?></th>
<th><?= $e(t('revisions.col_note')) ?></th>
<th><?= $e(t('revisions.col_size')) ?></th>
<th><span class="wk-vh"><?= $e(t('revisions.col_actions')) ?></span></th>
</tr></thead>
<tbody>
<?php foreach (array_reverse($rows) as $row): ?>
<?php $entry = $row['entry']; $rev = (int) $entry['n']; $isCurrent = $rev === $currentRev; ?>
<?php $isDiffEndpoint = $diff !== null && ($rev === $from || $rev === $to); ?>
<tr>
<td data-label="<?= $e(t('revisions.col_select')) ?>"><div class="wk-cell"><button type="button" class="wk-radio-btn" data-rev="<?= $rev ?>" aria-label="<?= $e(t('revisions.pick', [$rev])) ?>" aria-pressed="<?= $isDiffEndpoint ? 'true' : 'false' ?>"><span class="wk-radio<?= $isDiffEndpoint ? ' wk-on' : '' ?>"></span></button></div></td>
<td class="wk-mono" data-label="<?= $e(t('revisions.col_rev')) ?>"><div class="wk-cell"><?= $rev ?></div></td>
<td class="wk-nowrap" data-label="<?= $e(t('revisions.col_when')) ?>"><div class="wk-cell"><time datetime="<?= $e((string) ($entry['ts'] ?? '')) ?>"><?= $e(\Reporion\Support\MetaText::when($entry['ts'] ?? null)) ?></time></div></td>
<td data-label="<?= $e(t('revisions.col_author')) ?>"><div class="wk-cell"><?= $e(display_name((string) $entry['by'])) ?></div></td>
<td class="wk-mono<?= $row['counts'] === null || ($row['counts']['add'] === 0 && $row['counts']['remove'] === 0) ? ' wk-nocard' : '' ?>" data-label="<?= $e(t('revisions.col_change')) ?>"><div class="wk-cell">
<?php if ($row['counts'] !== null): ?>
<?php if ($row['counts']['add'] > 0): ?><span class="wk-add">+<?= $row['counts']['add'] ?></span><?php endif; ?>
<?php if ($row['counts']['remove'] > 0): ?> <span class="wk-del">−<?= $row['counts']['remove'] ?></span><?php endif; ?>
<?= $bar($row['counts']) ?>
<?php endif; ?>
</div></td>
<td class="wk-dim<?= (string) ($entry['note'] ?? '') === '' ? ' wk-nocard' : '' ?>" data-label="<?= $e(t('revisions.col_note')) ?>"><div class="wk-cell"><?= $e((string) ($entry['note'] ?? '')) ?></div></td>
<td class="wk-mono wk-dim wk-nowrap" data-label="<?= $e(t('revisions.col_size')) ?>"><div class="wk-cell"><?= $e(t('revisions.bytes', [(int) $entry['bytes']])) ?></div></td>
<td class="wk-right wk-nowrap">
<?php if ($isCurrent): ?>
<span class="tag tag-accent"><?= $e(t('revisions.current')) ?></span>
<?php else: ?>
<div class="wk-actions">
<?php if ($canWrite): ?>
<form action="<?= $p ?>/revisions/revert" method="post">
<input type="hidden" name="to" value="<?= $rev ?>">
<button class="btn btn-ghost btn-sm" type="submit"><i class="ph ph-arrow-counter-clockwise" aria-hidden="true"></i><?= $e(t('revisions.restore')) ?></button>
</form>
<?php endif; ?>
<a class="btn btn-secondary btn-sm" href="?from=<?= $rev ?>&amp;to=<?= $currentRev ?><?= $keep ?>"><i class="ph ph-git-diff" aria-hidden="true"></i><?= $e(t('revisions.diff')) ?></a>
</div>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
<?php if ($template !== null): ?>
<?php /* Revision zero: the template the page names: compare with it like any revision; never restored */ ?>
<tr class="wk-rev-zero">
<td data-label="<?= $e(t('revisions.col_select')) ?>"><div class="wk-cell"><?php $zeroOn = $diff !== null && ($from === 0 || $to === 0); ?><button type="button" class="wk-radio-btn" data-rev="0" aria-label="<?= $e(t('revisions.pick_template')) ?>" aria-pressed="<?= $zeroOn ? 'true' : 'false' ?>"><span class="wk-radio<?= $zeroOn ? ' wk-on' : '' ?>"></span></button></div></td>
<td class="wk-mono" data-label="<?= $e(t('revisions.col_rev')) ?>"><div class="wk-cell">0</div></td>
<td class="wk-nowrap" data-label="<?= $e(t('revisions.col_when')) ?>"><div class="wk-cell"><?= $e(\Reporion\Support\MetaText::when($template['ts'] !== '' ? $template['ts'] : null)) ?></div></td>
<td data-label="<?= $e(t('revisions.col_author')) ?>"><div class="wk-cell"><?= $e($template['by'] !== '' ? display_name($template['by']) : 'n/a') ?></div></td>
<td class="wk-mono wk-nocard" data-label="<?= $e(t('revisions.col_change')) ?>"><div class="wk-cell"></div></td>
<td data-label="<?= $e(t('revisions.col_note')) ?>"><div class="wk-cell"><span class="tag tag-outline"><?= $e(t('revisions.template')) ?></span> <?php foreach ($template['paths'] as $i => $templatePath): ?><?= $i > 0 ? ' + ' : '' ?><a class="wk-mono" href="<?= $b ?>/<?= $e($templatePath) ?>"><?= $e($templatePath) ?></a><?php endforeach; ?></div></td>
<td class="wk-mono wk-dim wk-nowrap" data-label="<?= $e(t('revisions.col_size')) ?>"><div class="wk-cell"><?= $e(t('revisions.bytes', [\strlen($template['body'])])) ?></div></td>
<td class="wk-right wk-nowrap"><div class="wk-actions"><a class="btn btn-secondary btn-sm" href="?from=0&amp;to=<?= $currentRev ?><?= $keep ?>"><i class="ph ph-git-diff" aria-hidden="true"></i><?= $e(t('revisions.diff')) ?></a></div></td>
</tr>
<?php endif; ?>
</tbody>
</table>
</div>

<?php if ($diff !== null): ?>
<?php $revName = static fn (?int $n): string => $n === 0 ? t('revisions.template') : t('revisions.rev_label', [(int) $n]); ?>
<section class="wk-diff" aria-labelledby="rev-diff-h">
<h3 class="wk-vh" id="rev-diff-h"><?= $e(t('revisions.changes', [$revName($from), $revName($to)])) ?></h3>
<?php /* No Restore action here: every non-current row in the table above already has its own (2026-09-30) */ ?>
<?php if ($diff['style'] !== $requestedStyle): ?>
<?php $noticeKind = 'warn'; $noticeText = t('revisions.style_fallback'); include __DIR__ . '/partials/notice.php'; ?>
<?php endif; ?>
<?php
$canWrap = \in_array($diff['style'], ['line', 'split'], true);
$withNav = $diff['style'] !== 'side' && ($diff['stats']['add'] + $diff['stats']['remove']) > 0;
?>
<?php /* The one toolbar of the diff, a joined .group strip (the look of the search bars): totals, then changes, wrap, whitespace and the style, the last two always rightmost. Sticky under the top bar. The hidden inputs and the Apply button are outside the strip (form="rev-style-form") so they do not take its corners */ ?>
<input type="hidden" name="from" value="<?= $from ?>" form="rev-style-form">
<input type="hidden" name="to" value="<?= $to ?>" form="rev-style-form">
<?php if ($ignoreSpace): ?><input type="hidden" name="ws" value="1" form="rev-style-form"><?php /* kept when the style changes; the Whitespace button, after it, sends 0 and wins */ ?><?php endif; ?>
<div class="wk-difftools" data-difftools>
<form class="wk-gbar" method="get" action="<?= $p ?>/revisions" id="rev-style-form" role="toolbar" aria-label="<?= $e(t('revisions.toolbar')) ?>">
<span class="group wk-gstat">
<?php if ($diff['stats'] !== null): ?>
<span class="group-addon wk-diffstat"><span class="wk-add">+<?= (int) $diff['stats']['add'] ?></span>&nbsp;<span class="wk-del">−<?= (int) $diff['stats']['remove'] ?></span>&nbsp;<span class="wk-dim"><?= $e(t($diff['style'] === 'word' ? 'revisions.stat_words' : 'revisions.stat_lines')) ?></span></span>
<?php endif; ?>
<span class="group-addon wk-gfill" aria-hidden="true"></span>
</span>
<span class="group" role="group" aria-label="<?= $e(t('revisions.changes_nav')) ?>">
<button type="button" class="btn btn-sm" data-diff-prev title="<?= $e(t('revisions.prev_change')) ?>"<?= $withNav ? '' : ' disabled' ?> aria-label="<?= $e(t('revisions.prev_change')) ?>"><i class="ph ph-caret-up" aria-hidden="true"></i><span class="wk-btn-label"><?= $e(t('revisions.prev_change')) ?></span></button>
<span class="group-addon wk-gpos wk-mono" data-diff-pos aria-live="polite"></span>
<button type="button" class="btn btn-sm" data-diff-next title="<?= $e(t('revisions.next_change')) ?>"<?= $withNav ? '' : ' disabled' ?> aria-label="<?= $e(t('revisions.next_change')) ?>"><i class="ph ph-caret-down" aria-hidden="true"></i><span class="wk-btn-label"><?= $e(t('revisions.next_change')) ?></span></button>
</span>
<span class="group" role="group" aria-label="<?= $e(t('revisions.display')) ?>">
<button type="button" class="btn btn-sm" data-diff-wrap aria-pressed="<?= $canWrap ? 'true' : 'false' ?>"<?= $canWrap ? '' : ' disabled' ?> title="<?= $e(t('revisions.wrap_help')) ?>"><i class="ph ph-text-align-left" aria-hidden="true"></i><span class="wk-btn-label"><?= $e(t('revisions.wrap')) ?></span></button>
<button type="submit" class="btn btn-sm" name="ws" value="<?= $ignoreSpace ? '0' : '1' ?>" aria-pressed="<?= $ignoreSpace ? 'false' : 'true' ?>" title="<?= $e(t('revisions.space_help')) ?>"><i class="ph ph-text-indent" aria-hidden="true"></i><span class="wk-btn-label"><?= $e(t('revisions.space')) ?></span></button>
</span>
<span class="group" role="radiogroup" aria-label="<?= $e(t('revisions.style')) ?>">
<?php foreach (['word' => 'style_word', 'line' => 'style_line', 'split' => 'style_split', 'side' => 'style_side'] as $value => $key): ?>
<label class="btn btn-sm wk-gopt"><input type="radio" name="style" value="<?= $value ?>"<?= $requestedStyle === $value ? ' checked' : '' ?>><?= $e(t('revisions.' . $key)) ?></label>
<?php endforeach; ?>
</span>
</form>
</div>
<button type="submit" class="btn btn-secondary btn-sm" id="rev-style-apply" form="rev-style-form"><i class="ph ph-check" aria-hidden="true"></i><?= $e(t('revisions.style_apply')) ?></button>
<script>
(function() {
  var form = document.getElementById('rev-style-form');
  var btn = document.getElementById('rev-style-apply');
  // With JS, picking a style re-submits at once; the button hides, and stays the fallback without JS
  if (btn) btn.hidden = true;
  Array.prototype.forEach.call(form.querySelectorAll('[name="style"]'), function(radio) {
    radio.addEventListener('change', function() { form.submit(); });
  });
})();
</script>
<?php if ($diff['style'] === 'word'): ?>
<div class="wk-worddiff wk-prose" data-wordtext><?php foreach ($diff['ops'] as $op): ?><?php
    $text = htmlspecialchars($op['line'], ENT_QUOTES);
    echo match ($op['op']) {
        'add' => '<ins>' . $text . '</ins>',
        'remove' => '<del>' . $text . '</del>',
        default => $text,
    };
?><?php endforeach; ?></div>
<?php elseif ($diff['style'] === 'side'): ?>
<?php $paneName = static fn (array $pane): string => $pane['rev'] === 0 ? t('revisions.template') : t('revisions.rev_label', [$pane['rev']]); ?>
<?php $paneHead = static fn (array $pane): string => '<div class="wk-crumbs wk-mono"><b>' . htmlspecialchars($paneName($pane), ENT_QUOTES) . '</b><span class="wk-dim">' . htmlspecialchars(\Reporion\Support\MetaText::when($pane['ts']), ENT_QUOTES) . '</span></div>'; ?>
<?php if (($diff['aligned'] ?? null) !== null): ?>
<?php /* One grid, two columns: each section's two cells share a row (Support\CompareSections, as the Compare screen does); one column under 1024px, each cell naming its revision */ ?>
<?php if ($diff['aligned']['reordered']): ?>
<p class="wk-dim wk-cmp-note"><i class="ph ph-arrows-down-up" aria-hidden="true"></i> <?= $e(t('revisions.reordered', [$paneName($diff['panes'][1])])) ?></p>
<?php endif; ?>
<div class="wk-cmp wk-cmp-sync">
<div class="wk-cmp-cap"><?= $paneHead($diff['panes'][0]) ?></div>
<div class="wk-cmp-cap"><?= $paneHead($diff['panes'][1]) ?></div>
<?php foreach ($diff['aligned']['rows'] as [$left, $right]): ?>
<div class="wk-prose wk-cmp-cell<?= $left === '' ? ' wk-cmp-none' : '' ?>"><span class="wk-cmp-when wk-mono wk-dim"><?= $e($paneName($diff['panes'][0])) ?></span><?= $left /* Render::toHtml() output, split at its headings */ ?></div>
<div class="wk-prose wk-cmp-cell<?= $right === '' ? ' wk-cmp-none' : '' ?>"><span class="wk-cmp-when wk-mono wk-dim"><?= $e($paneName($diff['panes'][1])) ?></span><?= $right ?></div>
<?php endforeach; ?>
</div>
<?php else: ?>
<div class="wk-cmp">
<?php foreach ($diff['panes'] as $pane): ?>
<div>
<?= $paneHead($pane) ?>
<?php if ($pane['html'] !== null): ?>
<?php if ($pane['title'] !== ''): ?><h4 class="wk-cmp-title"><?= htmlspecialchars($pane['title'], ENT_QUOTES) ?></h4><?php endif; ?>
<div class="wk-prose"><?= $pane['html'] /* Render::toHtml() output, the same canonical HTML the page view prints */ ?></div>
<?php else: ?>
<pre class="wk-mono"><?= htmlspecialchars($pane['raw'], ENT_QUOTES) ?></pre>
<?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php else: ?>
<?php
$split = $diff['style'] === 'split';
$segs = static function (string $text, ?array $parts, string $kind) use ($e): string {
    if ($parts === null) {
        return $e($text);
    }
    $out = '';
    foreach ($parts as [$k, $part]) {
        $out .= $k === 'chg' ? '<mark class="wk-mark-' . $kind . '">' . $e($part) . '</mark>' : $e($part);
    }

    return $out;
};
$foldLabel = static fn (array $row): string => t('revisions.unchanged', [(int) $row['n']]) . ($row['heading'] !== '' ? ' · ' . $row['heading'] : '');
$foldHideLabel = static fn (array $row): string => t('revisions.fold_hide', [(int) $row['n']]) . ($row['heading'] !== '' ? ' · ' . $row['heading'] : '');
$foldId = 0;
?>
<?php if ($diff['stats']['add'] + $diff['stats']['remove'] === 0): ?>
<?php $noticeKind = 'info'; $noticeText = t('revisions.no_changes'); include __DIR__ . '/partials/notice.php'; ?>
<?php endif; ?>
<div class="wk-dt-wrap wk-dt-wrapped" data-dtwrap>
<table class="wk-dt<?= $split ? ' wk-dt-split' : '' ?> wk-mono">
<?php if ($split): ?>
<colgroup><col class="wk-dt-n"><col class="wk-dt-c"><col class="wk-dt-n"><col class="wk-dt-c"></colgroup>
<?php else: ?>
<colgroup><col class="wk-dt-n"><col class="wk-dt-n"><col class="wk-dt-s"><col class="wk-dt-c"></colgroup>
<?php endif; ?>
<tbody>
<?php foreach ($diff['rows'] as $row): ?>
<?php if ($split): ?>
<?php if (isset($row['fold'])): $foldId++; ?>
<tr class="wk-dt-fold"><td colspan="4"><button type="button" data-fold="<?= $foldId ?>" aria-expanded="false" data-show="<?= $e($foldLabel($row)) ?>" data-hide="<?= $e($foldHideLabel($row)) ?>"><i class="ph ph-arrows-out-line-vertical" aria-hidden="true"></i><span><?= $e($foldLabel($row)) ?></span></button></td></tr>
<?php foreach ($row['rows'] as $hid): ?>
<tr class="wk-dt-hid" data-foldrow="<?= $foldId ?>" hidden><td class="wk-dt-n"><?= (int) $hid['l']['n'] ?></td><td class="wk-dt-c"><?= $e($hid['l']['text']) ?></td><td class="wk-dt-n"><?= (int) $hid['r']['n'] ?></td><td class="wk-dt-c"><?= $e($hid['r']['text']) ?></td></tr>
<?php endforeach; ?>
<?php else: ?>
<?php $l = $row['l']; $r = $row['r']; ?>
<tr<?= ($l !== null && $l['t'] !== 'ctx') || ($r !== null && $r['t'] !== 'ctx') ? ' data-change' : '' ?>>
<td class="wk-dt-n<?= $l !== null && $l['t'] === 'del' ? ' wk-dt-del' : '' ?>"><?= $l !== null ? (int) $l['n'] : '' ?></td>
<td class="wk-dt-c<?= $l === null ? ' wk-dt-empty' : ($l['t'] === 'del' ? ' wk-dt-del' : '') ?>"><?= $l !== null ? $segs($l['text'], $l['segs'], 'del') : '' ?></td>
<td class="wk-dt-n<?= $r !== null && $r['t'] === 'add' ? ' wk-dt-add' : '' ?>"><?= $r !== null ? (int) $r['n'] : '' ?></td>
<td class="wk-dt-c<?= $r === null ? ' wk-dt-empty' : ($r['t'] === 'add' ? ' wk-dt-add' : '') ?>"><?= $r !== null ? $segs($r['text'], $r['segs'], 'add') : '' ?></td>
</tr>
<?php endif; ?>
<?php else: ?>
<?php if ($row['t'] === 'fold'): $foldId++; ?>
<tr class="wk-dt-fold"><td colspan="4"><button type="button" data-fold="<?= $foldId ?>" aria-expanded="false" data-show="<?= $e($foldLabel($row)) ?>" data-hide="<?= $e($foldHideLabel($row)) ?>"><i class="ph ph-arrows-out-line-vertical" aria-hidden="true"></i><span><?= $e($foldLabel($row)) ?></span></button></td></tr>
<?php foreach ($row['rows'] as $hid): ?>
<tr class="wk-dt-hid" data-foldrow="<?= $foldId ?>" hidden><td class="wk-dt-n"><?= (int) $hid['a'] ?></td><td class="wk-dt-n"><?= (int) $hid['b'] ?></td><td class="wk-dt-s"></td><td class="wk-dt-c"><?= $e($hid['text']) ?></td></tr>
<?php endforeach; ?>
<?php else: ?>
<?php
    $cls = $row['t'] === 'add' ? ' wk-dt-add' : ($row['t'] === 'del' ? ' wk-dt-del' : '');
    $sign = $row['t'] === 'add' ? '+' : ($row['t'] === 'del' ? '−' : '');
?>
<tr<?= $row['t'] !== 'ctx' ? ' data-change' : '' ?>><td class="wk-dt-n<?= $cls ?>"><?= $row['a'] !== null ? (int) $row['a'] : '' ?></td><td class="wk-dt-n<?= $cls ?>"><?= $row['b'] !== null ? (int) $row['b'] : '' ?></td><td class="wk-dt-s<?= $cls ?>" aria-hidden="true"><?= $sign ?></td><td class="wk-dt-c<?= $cls ?>"><?= $segs($row['text'], $row['segs'], $row['t']) ?></td></tr>
<?php endif; ?>
<?php endif; ?>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</section>
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
        btn.setAttribute('aria-pressed', 'false');
        return;
      }
      if (selected.length >= 2) {
        var oldest = selected.shift();
        var oldestBtn = buttons.filter(function(b) { return b.dataset.rev === oldest; })[0];
        if (oldestBtn) { oldestBtn.querySelector('.wk-radio').classList.remove('wk-on'); oldestBtn.setAttribute('aria-pressed', 'false'); }
      }
      selected.push(rev);
      btn.querySelector('.wk-radio').classList.add('wk-on');
      btn.setAttribute('aria-pressed', 'true');
      // The second pick runs the diff immediately: no separate "Compare selected" step
      if (selected.length === 2) {
        var nums = selected.map(Number).sort(function(a, b) { return a - b; });
        location.search = '?from=' + nums[0] + '&to=' + nums[1] + '&style=' + encodeURIComponent(style) + <?= $ignoreSpace ? "'&ws=1'" : "''" ?>;
      }
    });
  });
})();
</script>
<script>
(function() {
  // Unchanged runs folded away: a click opens them, and the same row hides them again
  Array.prototype.forEach.call(document.querySelectorAll('.wk-dt-fold button'), function(btn) {
    btn.addEventListener('click', function() {
      var open = btn.getAttribute('aria-expanded') !== 'true';
      Array.prototype.forEach.call(document.querySelectorAll('[data-foldrow="' + btn.getAttribute('data-fold') + '"]'), function(r) { r.hidden = !open; });
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      btn.querySelector('span').textContent = btn.getAttribute(open ? 'data-hide' : 'data-show');
      btn.querySelector('i').className = 'ph ' + (open ? 'ph-arrows-in-line-vertical' : 'ph-arrows-out-line-vertical');
    });
  });
  var wrap = document.querySelector('[data-diff-wrap]');
  var box = document.querySelector('[data-dtwrap]');
  if (wrap && box) wrap.addEventListener('click', function() {
    var on = wrap.getAttribute('aria-pressed') !== 'true';
    wrap.setAttribute('aria-pressed', on ? 'true' : 'false');
    box.classList.toggle('wk-dt-wrapped', on);
  });
  // Jump between the changes: whole changed blocks in a line diff, ins/del runs in a prose one
  var prev = document.querySelector('[data-diff-prev]');
  var next = document.querySelector('[data-diff-next]');
  var pos = document.querySelector('[data-diff-pos]');
  if (!prev || !next) return;
  var stops = [];
  var text = document.querySelector('[data-wordtext]');
  if (text) {
    stops = Array.prototype.slice.call(text.querySelectorAll('ins, del'));
  } else {
    var before = null;
    Array.prototype.forEach.call(document.querySelectorAll('.wk-dt tr'), function(tr) {
      var changed = tr.hasAttribute('data-change');
      if (changed && !before) stops.push(tr);
      before = changed;
    });
  }
  var at = -1;
  function show() { if (pos) pos.textContent = stops.length ? (at < 0 ? '–' : at + 1) + ' / ' + stops.length : '–'; }
  show();
  function go(delta) {
    if (!stops.length) return;
    at = Math.max(0, Math.min(stops.length - 1, at + delta));
    stops[at].scrollIntoView({block: 'center'});
    stops.forEach(function(el) { el.classList.remove('wk-dt-now'); });
    stops[at].classList.add('wk-dt-now');
    show();
  }
  if (!stops.length) { prev.disabled = true; next.disabled = true; }
  prev.addEventListener('click', function() { go(-1); });
  next.addEventListener('click', function() { go(1); });
})();
</script>
