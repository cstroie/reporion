<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /{path}/compare (Controller\CompareController): two revisions of this
 * page side by side (.wk-cmp from design/mockup/WikiCompare.dc.html), each
 * rendered from its own bytes by Render::toHtml(). A plain GET form picks
 * from/to (works without JS). Not built: the mockup's AI delta panel (D15)
 * and report-vs-prior-report compare across pages.
 *
 * Variables in scope (see Controller\CompareController::compare()):
 * string $path; ?int $from, $to; int $currentRev;
 * list<array{rev:int,ts:string,title:string,html:?string,raw:string}> $panes;
 * list<array{n:int,ts:string}> $revOptions; bool $canWrite; string $basePath
 */

declare(strict_types=1);

/** @var string $path */
/** @var ?int $from */
/** @var ?int $to */
/** @var int $currentRev */
/** @var list<array{rev: int, ts: string, title: string, html: ?string, raw: string}> $panes */
/** @var list<array{n: int, ts: string}> $revOptions */
/** @var bool $canWrite */
/** @var string $basePath */
?>
<div class="wk-doc">
<?php if (count($revOptions) < 2): ?>
<p class="wk-dim"><?= htmlspecialchars(t('compare.single_rev'), ENT_QUOTES) ?></p>
<?php else: ?>
<form class="wk-actions" method="get" action="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($path, ENT_QUOTES) ?>/compare" style="margin-bottom:var(--space-6)">
<?php foreach (['from' => $from, 'to' => $to] as $field => $selected): ?>
<label class="wk-mono wk-dim"><?= htmlspecialchars(t('compare.' . $field), ENT_QUOTES) ?>
<select class="input" name="<?= $field ?>">
<?php foreach ($revOptions as $option): ?>
<option value="<?= $option['n'] ?>"<?= $option['n'] === $selected ? ' selected' : '' ?>><?= htmlspecialchars(t('compare.rev_option', [$option['n'], $option['ts']]), ENT_QUOTES) ?></option>
<?php endforeach; ?>
</select></label>
<?php endforeach; ?>
<button class="btn btn-secondary" type="submit"><?= htmlspecialchars(t('compare.apply'), ENT_QUOTES) ?></button>
</form>
<div class="wk-cmp">
<?php foreach ($panes as $pane): ?>
<div>
<div class="wk-crumbs wk-mono"><b><?= htmlspecialchars(t('compare.rev_label', [$pane['rev']]), ENT_QUOTES) ?></b><span class="wk-dim"><?= htmlspecialchars($pane['ts'], ENT_QUOTES) ?></span></div>
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

</div>
