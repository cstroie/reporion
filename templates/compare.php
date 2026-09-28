<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /{path}/compare (Controller\CompareController): a word-level,
 * track-changes read of the two revisions' body text (TODO 13, 2026-09-28
 * — Support\Diff::words(), red strikethrough for a removal, green for an
 * addition, inline), falling back to the two raw documents side by side
 * (.wk-cmp from design/mockup/WikiCompare.dc.html) only when one side's
 * frontmatter does not parse. A plain GET form picks from/to (works
 * without JS). Not built: the mockup's AI delta panel (D15) and
 * report-vs-prior-report compare across pages.
 *
 * Variables in scope (see Controller\CompareController::compare()):
 * string $path; ?int $from, $to; int $currentRev;
 * list<array{rev:int,ts:string,title:string,html:?string,raw:string,body:?string}> $panes;
 * ?list<array{op:'equal'|'add'|'remove',line:string}> $wordDiff;
 * list<array{n:int,ts:string}> $revOptions; bool $canWrite; string $basePath
 */

declare(strict_types=1);

/** @var string $path */
/** @var ?int $from */
/** @var ?int $to */
/** @var int $currentRev */
/** @var list<array{rev: int, ts: string, title: string, html: ?string, raw: string, body: ?string}> $panes */
/** @var ?list<array{op: 'equal'|'add'|'remove', line: string}> $wordDiff */
/** @var list<array{n: int, ts: string}> $revOptions */
/** @var bool $canWrite */
/** @var string $basePath */
?>
<div class="wk-doc">
<?php if (count($revOptions) < 2): ?>
<p class="wk-dim"><?= htmlspecialchars(t('compare.single_rev'), ENT_QUOTES) ?></p>
<?php else: ?>
<form class="wk-cmp-bar" method="get" action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($path, ENT_QUOTES) ?>/compare">
<label class="wk-cmp-bar-field">
<span class="wk-cmp-bar-cap wk-dim"><?= htmlspecialchars(t('compare.from'), ENT_QUOTES) ?></span>
<select class="input" name="from">
<?php foreach ($revOptions as $option): ?>
<option value="<?= $option['n'] ?>"<?= $option['n'] === $from ? ' selected' : '' ?>><?= htmlspecialchars(t('compare.rev_option', [$option['n'], \Reporion\Support\MetaText::when($option['ts'])]), ENT_QUOTES) ?></option>
<?php endforeach; ?>
</select></label>
<i class="ph ph-arrow-right wk-dim wk-cmp-bar-arrow" aria-hidden="true"></i>
<label class="wk-cmp-bar-field">
<span class="wk-cmp-bar-cap wk-dim"><?= htmlspecialchars(t('compare.to'), ENT_QUOTES) ?></span>
<select class="input" name="to">
<?php foreach ($revOptions as $option): ?>
<option value="<?= $option['n'] ?>"<?= $option['n'] === $to ? ' selected' : '' ?>><?= htmlspecialchars(t('compare.rev_option', [$option['n'], \Reporion\Support\MetaText::when($option['ts'])]), ENT_QUOTES) ?></option>
<?php endforeach; ?>
</select></label>
<button class="btn btn-primary" type="submit"><?= htmlspecialchars(t('compare.apply'), ENT_QUOTES) ?></button>
</form>

<?php if ($wordDiff !== null): ?>
<div class="wk-worddiff wk-prose">
<?php foreach ($wordDiff as $op): ?><?php
    $text = htmlspecialchars($op['line'], ENT_QUOTES);
    echo match ($op['op']) {
        'add' => '<ins>' . $text . '</ins>',
        'remove' => '<del>' . $text . '</del>',
        default => $text,
    };
?><?php endforeach; ?>
</div>
<?php else: ?>
<div class="wk-cmp">
<?php foreach ($panes as $pane): ?>
<div>
<div class="wk-crumbs wk-mono"><b><?= htmlspecialchars(t('compare.rev_label', [$pane['rev']]), ENT_QUOTES) ?></b><span class="wk-dim"><?= htmlspecialchars(\Reporion\Support\MetaText::when($pane['ts']), ENT_QUOTES) ?></span></div>
<?php if ($pane['html'] !== null): ?>
<?php if ($pane['title'] !== ''): ?><h2><?= htmlspecialchars($pane['title'], ENT_QUOTES) ?></h2><?php endif; ?>
<div class="wk-prose"><?= $pane['html'] /* Render::toHtml() output, the same canonical HTML the page view prints */ ?></div>
<?php else: ?>
<pre class="wk-mono"><?= htmlspecialchars($pane['raw'], ENT_QUOTES) ?></pre>
<?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

</div>
