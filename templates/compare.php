<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * GET /{path}/compare (Controller\CompareController).
 * Dedicated diff view — from/to revision selectors above a
 * unified diff panel. Follows templates/history.php's structure
 * and CSS classes (.wk-diff / .wk-difftext / wk-al / wk-dl / wk-ctx).
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
<link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/css/fontawesome.css">
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

<form class="wk-compare-form wk-mono" method="get">
<label><?= htmlspecialchars(t('compare.from'), ENT_QUOTES) ?>
<select name="from">
<option value="0" <?= $from === 0 ? 'selected' : '' ?>><?= htmlspecialchars(t('compare.current'), ENT_QUOTES) ?></option>
<?php foreach ($revOptions as $opt): ?>
<option value="<?= $opt['n'] ?>" <?= $opt['n'] === $from ? 'selected' : '' ?>>rev <?= $opt['n'] ?> — <?= htmlspecialchars($opt['ts'], ENT_QUOTES) ?></option>
<?php endforeach ?>
</select>
</label>
<label><?= htmlspecialchars(t('compare.to'), ENT_QUOTES) ?>
<select name="to">
<option value="0" <?= $to === 0 ? 'selected' : '' ?>><?= htmlspecialchars(t('compare.current'), ENT_QUOTES) ?></option>
<?php foreach ($revOptions as $opt): ?>
<option value="<?= $opt['n'] ?>" <?= $opt['n'] === $to ? 'selected' : '' ?>>rev <?= $opt['n'] ?> — <?= htmlspecialchars($opt['ts'], ENT_QUOTES) ?></option>
<?php endforeach ?>
</select>
</label>
<button class="btn btn-primary" type="submit"><?= htmlspecialchars(t('compare.diff'), ENT_QUOTES) ?></button>
</form>

<?php if ($diffLines !== null && $diffLines !== []): ?>
<div class="wk-diff">
<div class="wk-diff-h"><span class="wk-eyebrow"><?= htmlspecialchars(t('compare.diff_title', [$from === 0 ? 'current' : (string) $from, $to === 0 ? 'current' : (string) $to]), ENT_QUOTES) ?></span></div>
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
<?php elseif ($diffLines !== null): ?>
<p><?= htmlspecialchars(t('compare.no_diff'), ENT_QUOTES) ?></p>
<?php endif; ?>
</div>
</main>
</div>
</div>
<?php include __DIR__ . '/status.php'; ?>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/js/palette.js" defer></script>
</body>
</html>