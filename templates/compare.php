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
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(t('page.compare'), ENT_QUOTES) ?> — <?= htmlspecialchars($path, ENT_QUOTES) ?> — <?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?></title>
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
<?php if ($i === $last): ?><a href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars($path, ENT_QUOTES) ?>"><?= htmlspecialchars($segment, ENT_QUOTES) ?></a><span>›</span><b><?= htmlspecialchars(t('page.compare'), ENT_QUOTES) ?></b>
<?php else: ?><a href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/<?= htmlspecialchars(implode(':', $prefix), ENT_QUOTES) ?>:"><?= htmlspecialchars($segment, ENT_QUOTES) ?></a><span>›</span>
<?php endif; ?>
<?php endforeach; ?>
</div>
<div class="wk-doc-titlerow">
<h1 class="wk-doc-title"><?= htmlspecialchars(t('page.compare'), ENT_QUOTES) ?></h1>
</div>
</div>

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
</main>
</div>
</div>
<?php include __DIR__ . '/status.php'; ?>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/js/palette.js" defer></script>
</body>
</html>