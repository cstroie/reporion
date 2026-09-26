<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * A bare error page (Http\ErrorMapper): every 404 for an anonymous caller,
 * and every 500. It names nothing — no path, no reason — so a private page
 * and a missing one are indistinguishable (invariant 9) and an internal
 * error leaks nothing. No app shell: it must render even when the shell's
 * own queries are what failed.
 *
 * Variables in scope: int $status; string $basePath, $themeBodyClass
 */

declare(strict_types=1);

/** @var int $status */
/** @var string $basePath */
/** @var string $themeBodyClass */

$b = htmlspecialchars($basePath, ENT_QUOTES);
$key = $status === 404 ? 'err.404' : 'err.500';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(t($key . '.title'), ENT_QUOTES) ?> — <?= htmlspecialchars(t('app.name'), ENT_QUOTES) ?></title>
<?php include __DIR__ . '/partials/site-icon.php'; ?>
<link rel="stylesheet" href="<?= $b ?>/assets/css/tokens.css">
<link rel="stylesheet" href="<?= $b ?>/assets/css/wiki.css">
</head>
<body class="wk<?= htmlspecialchars($themeBodyClass, ENT_QUOTES) ?>">
<main class="wk-panes" data-pad="read"><div class="wk-panebox">
<div class="wk-err">
<div class="wk-errc">
<b><?= $status ?></b>
<h1 class="wk-sec-title"><?= htmlspecialchars(t($key . '.title'), ENT_QUOTES) ?></h1>
<p style="font-size:13px;margin:0"><?= htmlspecialchars(t($key . '.body'), ENT_QUOTES) ?></p>
<div class="wk-actions"><a class="btn btn-secondary btn-sm" href="<?= $b ?>/"><?= htmlspecialchars(t('err.home'), ENT_QUOTES) ?></a></div>
</div>
</div>
</div></main>
</body>
</html>
