<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /{path}/compare (Controller\CompareController).
 * Static mockup content only, ported verbatim from
 * design/mockup/WikiCompare.dc.html: the "Delta" panel and the .wk-cmp
 * two-column rendered-content section. Neither reflects the from/to
 * revisions CompareController resolves — the from/to revision-select form
 * and the .wk-diff/.wk-difftext unified-diff panel that used to render
 * them here were removed. The Delta panel follows the same
 * non-functional-AI-output convention as editor.php's wk-ai-out panels
 * (D15: no AI provider is wired up yet); .wk-cmp is a hardcoded example
 * pair standing in for the mockup's fuller cross-page patient-timeline
 * compare (rendering the real from/to bodies via Render::toHtml() side by
 * side) — not built here yet.
 *
 * $from, $to, $currentRev, $diffLines, $revOptions and $canWrite are still
 * computed by CompareController::compare() but are no longer used by this
 * template now that the diff panel is gone.
 *
 * Variables in scope (see Controller\CompareController::compare()):
 * string $path; int $from, $to, $currentRev; ?list<array{op:string,line:string}> $diffLines
 * list<array{n:int,ts:string}> $revOptions; bool $canWrite; string $basePath
 */

declare(strict_types=1);

/** @var string $path */
/** @var int $from */
/** @var int $to */
/** @var int $currentRev */
/** @var ?list<array{op: string, line: string}> $diffLines */
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

<div class="wk-panel">
<div class="wk-panel-h"><span class="wk-eyebrow"><i class="ph ph-sparkle"></i> <?= htmlspecialchars(t('compare.ai_delta'), ENT_QUOTES) ?></span><span class="wk-mono wk-dim"><?= htmlspecialchars(t('compare.ai_delta_meta'), ENT_QUOTES) ?></span></div>
<p style="font-size:13px;margin:0"><?= htmlspecialchars(t('compare.ai_delta_p1'), ENT_QUOTES) ?> <span class="wk-add wk-mono">(<?= htmlspecialchars(t('compare.ai_delta_add1'), ENT_QUOTES) ?>)</span><?= htmlspecialchars(t('compare.ai_delta_p2'), ENT_QUOTES) ?> <span class="wk-add wk-mono">(<?= htmlspecialchars(t('compare.ai_delta_add2'), ENT_QUOTES) ?>)</span><?= htmlspecialchars(t('compare.ai_delta_p3'), ENT_QUOTES) ?> <b><?= htmlspecialchars(t('compare.ai_delta_concl'), ENT_QUOTES) ?></b></p>
</div>

<div class="wk-cmp">
<div>
<div class="wk-crumbs wk-mono"><b><?= htmlspecialchars(t('compare.pane1_date'), ENT_QUOTES) ?></b><span class="tag tag-neutral"><?= htmlspecialchars(t('compare.pane1_tag'), ENT_QUOTES) ?></span></div>
<div class="wk-prose">
<h2><?= htmlspecialchars(t('compare.h_descriere'), ENT_QUOTES) ?></h2>
<p><?= htmlspecialchars(t('compare.descriere_pre'), ENT_QUOTES) ?> <b><?= htmlspecialchars(t('compare.pane1_mm'), ENT_QUOTES) ?></b>.</p>
<ul>
<li><?= htmlspecialchars(t('compare.li_periventriculare'), ENT_QUOTES) ?></li>
<li><?= htmlspecialchars(t('compare.li_juxtacorticale_pre'), ENT_QUOTES) ?> <b class="wk-add"><?= htmlspecialchars(t('compare.li_juxtacorticale_new'), ENT_QUOTES) ?></b></li>
<li><?= htmlspecialchars(t('compare.li_infratentoriale'), ENT_QUOTES) ?></li>
<li><?= htmlspecialchars(t('compare.li_active'), ENT_QUOTES) ?></li>
</ul>
<h2><?= htmlspecialchars(t('compare.h_concluzie'), ENT_QUOTES) ?></h2>
<p><?= htmlspecialchars(t('compare.pane1_concluzie_pre'), ENT_QUOTES) ?> <b><?= htmlspecialchars(t('compare.pane1_concluzie_b'), ENT_QUOTES) ?></b><?= htmlspecialchars(t('compare.pane1_concluzie_post'), ENT_QUOTES) ?></p>
<h2><?= htmlspecialchars(t('compare.h_recomandari'), ENT_QUOTES) ?></h2>
<p><?= htmlspecialchars(t('compare.pane1_recomandari'), ENT_QUOTES) ?></p>
</div>
</div>
<div>
<div class="wk-crumbs wk-mono"><b><?= htmlspecialchars(t('compare.pane2_date'), ENT_QUOTES) ?></b><span class="tag tag-accent"><?= htmlspecialchars(t('compare.pane2_tag'), ENT_QUOTES) ?></span></div>
<div class="wk-prose">
<h2><?= htmlspecialchars(t('compare.h_descriere'), ENT_QUOTES) ?></h2>
<p><?= htmlspecialchars(t('compare.descriere_pre'), ENT_QUOTES) ?> <b><?= htmlspecialchars(t('compare.pane2_mm'), ENT_QUOTES) ?></b>.</p>
<ul>
<li><?= htmlspecialchars(t('compare.li_periventriculare'), ENT_QUOTES) ?></li>
<li><?= htmlspecialchars(t('compare.li_juxtacorticale_pre'), ENT_QUOTES) ?> <b class="wk-del"><?= htmlspecialchars(t('compare.li_juxtacorticale_old'), ENT_QUOTES) ?></b></li>
<li><?= htmlspecialchars(t('compare.li_infratentoriale'), ENT_QUOTES) ?></li>
<li><?= htmlspecialchars(t('compare.li_active'), ENT_QUOTES) ?></li>
</ul>
<h2><?= htmlspecialchars(t('compare.h_concluzie'), ENT_QUOTES) ?></h2>
<p><?= htmlspecialchars(t('compare.pane2_concluzie'), ENT_QUOTES) ?></p>
<h2><?= htmlspecialchars(t('compare.h_recomandari'), ENT_QUOTES) ?></h2>
<p><?= htmlspecialchars(t('compare.pane2_recomandari'), ENT_QUOTES) ?></p>
</div>
</div>
</div>

</div>
</main>
</div>
</div>
<?php include __DIR__ . '/status.php'; ?>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/js/palette.js" defer></script>
</body>
</html>