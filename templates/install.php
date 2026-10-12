<?php
/**
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The web installer's page (D43, public/install.php): the sign-in page's
 * layout (wk-auth) with the steps on the left and the current step's card
 * on the right. Rendered before vendor/ exists, so no Support\Asset and no
 * partials that need the Kernel: plain versioned links, t() from
 * src/lang.php.
 *
 * Variables in scope: string $base, $root, $step, $csrf, $detectedUrl, $serverName;
 * string $webUser; bool $rootMode, $held; Install\Installer $installer; list<Check> $requirements;
 * list<string> $errors; array<string, string> $form; ?array $flash; and, past
 * the first step, list<string> $secureActions, list<Check> $probeChecks,
 * $verifyChecks, array<string, string> $snippets
 */

declare(strict_types=1);

use Reporion\Install\Check;
use Reporion\Install\Installer;
use Reporion\Install\Requirements;

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$asset = static function (string $file) use ($base, $root): string {
    $mtime = @filemtime($root . '/assets/' . $file);

    return $base . '/assets/' . $file . ($mtime !== false ? '?v=' . dechex($mtime) : '');
};
$owns = $installer->owns($csrf);
$stepIndex = (int) array_search($step, Installer::STEPS, true);
$secureActions ??= [];
$probeChecks ??= [];
$verifyChecks ??= [];
$snippets ??= [];
$action = $base . '/install.php';

/** @param list<Check> $checks */
$checklist = static function (array $checks) use ($e): void { ?>
<ul class="wk-install-checks">
<?php foreach ($checks as $check): ?>
<li class="is-<?= $e($check->status) ?>">
<span class="wk-install-dot" aria-hidden="true"></span>
<div>
<b><?= $e($check->label) ?></b> <span class="wk-install-status"><?= $e(t('install.status.' . $check->status)) ?></span>
<?php if ($check->detail !== ''): ?><span class="wk-install-detail"><?= $e($check->detail) ?></span><?php endif; ?>
<?php if ($check->fix !== '' && $check->status !== Check::PASS): ?><pre class="wk-install-fix"><?= $e($check->fix) ?></pre><?php endif; ?>
</div>
</li>
<?php endforeach; ?>
</ul>
<?php };
$hidden = static function (string $action) use ($e, $csrf): void { ?>
<input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
<input type="hidden" name="action" value="<?= $e($action) ?>">
<?php };
// The browser's probe (assets/js/install.js): it fetches $paths and posts what came back with $action
$probeForm = static function (string $action, array $paths, bool $auto, string $label) use ($e, $hidden, $base): void { ?>
<form method="post" action="<?= $e($base . '/install.php') ?>" id="install-probes"><?php $hidden($action); ?>
<input type="hidden" name="results" value="">
<p class="wk-install-note" data-probe-status aria-live="polite"><?= $e(t('install.sec.js')) ?></p>
<div class="wk-actions"><button class="btn btn-primary" type="submit"><?= $e(t($label)) ?></button></div>
</form>
<script type="application/json" id="install-probe-config"><?= json_encode([
    'base' => $base,
    'paths' => $paths,
    'auto' => $auto,
    'running' => t('install.sec.running'),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?></script>
<?php };
// What root must add to the web server's configuration, the detected server first
$serverHelp = static function () use ($e, $serverName, $rootMode, $snippets): void {
    $order = array_values(array_unique(array_merge(in_array($serverName, ['lighttpd', 'nginx', 'apache'], true) ? [$serverName] : [], ['lighttpd', 'nginx', 'apache']))); ?>
<p><?= $e(t('install.sec.fix_intro')) ?></p>
<?php if ($rootMode): ?>
<details class="wk-install-snippet" open>
<summary><?= $e(t('install.sec.recommended')) ?></summary>
<p class="wk-install-note"><?= $e(t('install.sec.recommended_hint')) ?></p>
<pre class="wk-install-fix"><?= $e($snippets['recommended'] ?? '') ?></pre>
</details>
<?php endif; ?>
<?php foreach ($order as $i => $name): ?>
<details class="wk-install-snippet"<?= $i === 0 && !$rootMode ? ' open' : '' ?>>
<summary><?= $e(t('install.sec.server', [$name])) ?></summary>
<pre class="wk-install-fix"><?= $e($snippets[$name] ?? '') ?></pre>
</details>
<?php endforeach; ?>
<?php };
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $e(t('install.title')) ?> — <?= $e(t('app.name')) ?></title>
<link rel="icon" href="<?= $e($asset('icon.svg')) ?>" type="image/svg+xml">
<?php foreach (['css/tokens.css', 'css/wiki.css'] as $css): ?>
<link rel="stylesheet" href="<?= $e($asset($css)) ?>">
<?php endforeach; ?>
</head>
<body class="wk">
<div class="wk-auth wk-install">
<div class="wk-auth-brand">
<div class="wk-auth-mark"><?= $e(mb_substr(t('app.name'), 0, 1)) ?></div>
<h1 class="wk-auth-h"><?= $e(t('app.name')) ?></h1>
<p class="wk-auth-p"><?= $e(t('install.tagline')) ?></p>
<ol class="wk-install-steps">
<?php foreach (Installer::STEPS as $i => $name):
    $state = $held ? 'todo' : ($name === $step ? 'current' : ($owns && $installer->done($name) ? 'done' : 'todo')); ?>
<li class="is-<?= $state ?>">
<?php if ($owns && $state !== 'current' && $i <= array_search($installer->current(), Installer::STEPS, true)): ?>
<a href="<?= $e($action . '?step=' . $name) ?>"><?= $e(t('install.step.' . $name)) ?></a>
<?php else: ?>
<span><?= $e(t('install.step.' . $name)) ?></span>
<?php endif; ?>
</li>
<?php endforeach; ?>
</ol>
</div>

<div class="wk-auth-form">
<div class="card elev-md wk-install-card">
<?php if ($held): ?>
<span class="card-kicker"><?= $e(t('install.title')) ?></span>
<h2 class="wk-sec-title"><?= $e(t('install.held.title')) ?></h2>
<p><?= $e(t('install.held.body')) ?></p>
<pre class="wk-install-fix">rm <?= $e($root . '/' . Installer::STATE_FILE) ?></pre>
<?php else: ?>
<span class="card-kicker"><?= $e(t('install.kicker', [$stepIndex + 1, count(Installer::STEPS)])) ?></span>
<h2 class="wk-sec-title"><?= $e(t('install.step.' . $step)) ?></h2>

<?php if ($flash !== null): ?>
<p class="wk-install-flash is-<?= $e((string) ($flash['type'] ?? 'fail')) ?>" role="alert"><?= $e((string) ($flash['text'] ?? '')) ?></p>
<?php endif; ?>

<?php if ($step === 'requirements'): ?>
<p><?= $e(t('install.req.intro')) ?></p>
<p class="wk-install-note"><?= $e(t($rootMode ? 'install.mode.root' : 'install.mode.public', [$root])) ?></p>
<?php $checklist($requirements); ?>
<div class="wk-actions">
<?php if (Check::anyFailed($requirements)): ?>
<a class="btn btn-primary" href="<?= $e($action) ?>"><?= $e(t('install.again')) ?></a>
<?php else: ?>
<form method="post" action="<?= $e($action) ?>"><?php $hidden('begin'); ?>
<button class="btn btn-primary" type="submit"><?= $e(t($owns ? 'install.continue' : 'install.begin')) ?></button>
</form>
<?php endif; ?>
</div>

<?php elseif ($step === 'dependencies'): ?>
<?php if (Requirements::vendorReady($root)): ?>
<p><?= $e(t('install.dep.present')) ?></p>
<?php else: ?>
<p><?= $e(t('install.dep.intro')) ?></p>
<p class="wk-install-note"><?= $e(t('install.dep.manual')) ?></p>
<pre class="wk-install-fix">cd <?= $e($root) ?> &amp;&amp; composer install --no-dev</pre>
<?php endif; ?>
<form method="post" action="<?= $e($action) ?>" data-install-busy="<?= $e(t('install.dep.busy')) ?>"><?php $hidden('dependencies'); ?>
<div class="wk-actions"><button class="btn btn-primary" type="submit"><?= $e(t(Requirements::vendorReady($root) ? 'install.continue' : 'install.dep.run')) ?></button></div>
</form>

<?php elseif ($step === 'configure'): ?>
<p><?= $e(t('install.cfg.intro')) ?></p>
<?php if ($errors !== []): ?>
<ul class="wk-install-flash is-fail" role="alert"><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<form method="post" action="<?= $e($action) ?>" class="wk-install-form" autocomplete="off"><?php $hidden('configure'); ?>
<fieldset>
<legend><?= $e(t('install.cfg.site')) ?></legend>
<div class="field">
<label for="site_title"><?= $e(t('install.cfg.site_title')) ?></label>
<input class="input" id="site_title" name="site_title" required maxlength="80" value="<?= $e($form['site_title'] ?? t('app.name')) ?>">
</div>
<div class="field">
<label for="base_url"><?= $e(t('install.cfg.base_url')) ?></label>
<input class="input" id="base_url" name="base_url" type="url" required value="<?= $e($form['base_url'] ?? $detectedUrl) ?>">
<small><?= $e(t('install.cfg.base_url_hint')) ?></small>
</div>
<div class="field">
<label for="timezone"><?= $e(t('install.cfg.timezone')) ?></label>
<?php $tz = $form['timezone'] ?? 'Europe/Bucharest'; ?>
<select class="input" id="timezone" name="timezone">
<?php foreach (DateTimeZone::listIdentifiers() as $id): ?>
<option<?= $id === $tz ? ' selected' : '' ?>><?= $e($id) ?></option>
<?php endforeach; ?>
</select>
</div>
</fieldset>
<fieldset>
<legend><?= $e(t('install.cfg.owner')) ?></legend>
<p class="wk-install-note"><?= $e(t('install.cfg.owner_hint')) ?></p>
<div class="field">
<label for="username"><?= $e(t('auth.username')) ?></label>
<input class="input" id="username" name="username" required pattern="[a-z0-9]([a-z0-9_.\-]{0,62}[a-z0-9])?" autocomplete="username" value="<?= $e($form['username'] ?? (string) ($installer->state()['owner'] ?? '')) ?>">
<small><?= $e(t('install.cfg.username_hint')) ?></small>
</div>
<div class="field">
<label for="display_name"><?= $e(t('install.cfg.display_name')) ?></label>
<input class="input" id="display_name" name="display_name" maxlength="120" value="<?= $e($form['display_name'] ?? '') ?>">
</div>
<div class="field">
<label for="password"><?= $e(t('auth.password')) ?></label>
<input class="input" id="password" name="password" type="password" required minlength="10" autocomplete="new-password">
</div>
<div class="field">
<label for="password2"><?= $e(t('install.cfg.password2')) ?></label>
<input class="input" id="password2" name="password2" type="password" required minlength="10" autocomplete="new-password">
</div>
</fieldset>
<div class="wk-actions"><button class="btn btn-primary" type="submit"><?= $e(t('install.cfg.submit')) ?></button></div>
</form>

<?php elseif ($step === 'secure'): ?>
<p><?= $e(t('install.sec.intro')) ?></p>
<?php if ($secureActions !== []): ?>
<p class="wk-install-note"><?= $e(t('install.sec.done', [implode(', ', $secureActions)])) ?></p>
<?php endif; ?>
<?php if (($installer->state()['canary'] ?? '') === ''): ?>
<form method="post" action="<?= $e($action) ?>"><?php $hidden('secure'); ?>
<div class="wk-actions"><button class="btn btn-primary" type="submit"><?= $e(t('install.sec.run')) ?></button></div>
</form>
<?php else: ?>
<?php if ($probeChecks !== []) {
    $checklist($probeChecks);
} ?>
<?php if (Check::anyFailed($probeChecks)) {
    $serverHelp();
} ?>
<?php $probeForm('probes', array_merge(Installer::LEAK_PROBES, [Installer::ASSET_PROBE]), $probeChecks === [], $probeChecks === [] ? 'install.sec.test' : 'install.sec.retest'); ?>
<?php endif; ?>

<?php elseif ($step === 'verify'): ?>
<?php if ($verifyChecks === []): ?>
<p><?= $e(t('install.ver.intro')) ?></p>
<?php else: ?>
<?php $checklist($verifyChecks); ?>
<?php endif; ?>
<?php if ($verifyChecks !== [] && !Check::anyFailed($verifyChecks)): ?>
<p><?= $e(t('install.ver.ready')) ?></p>
<h3 class="wk-install-h"><?= $e(t('install.ver.next')) ?></h3>
<ul class="wk-install-next">
<li><?= $e(t('install.ver.next_cron')) ?>
<pre class="wk-install-fix">sudo crontab -u <?= $e($webUser) ?> -e
30 3 * * *  cd <?= $e($root) ?> &amp;&amp; bin/reporion index:verify --json &gt; /dev/null
15 4 * * *  cd <?= $e($root) ?> &amp;&amp; bin/reporion trash:purge
45 4 * * 0  cd <?= $e($root) ?> &amp;&amp; bin/reporion integrity:verify --json &gt; /dev/null</pre></li>
<li><?= $e(t('install.ver.next_backup')) ?></li>
<li><?= $e(t('install.ver.next_tls')) ?></li>
<li><?= $e(t('install.ver.next_settings')) ?></li>
</ul>
<form method="post" action="<?= $e($action) ?>"><?php $hidden('finish'); ?>
<label class="wk-install-check"><input type="checkbox" name="remove" value="1"> <?= $e(t('install.ver.remove')) ?></label>
<div class="wk-actions"><button class="btn btn-primary" type="submit"><?= $e(t('install.ver.finish')) ?></button></div>
</form>
<?php else: ?>
<?php if (Check::anyFailed(array_slice($verifyChecks, 0, 3))) {
    $serverHelp();
} ?>
<?php $probeForm('verify', array_merge(Installer::LEAK_PROBES, [Installer::ASSET_PROBE, Installer::LOGIN_PROBE]), $verifyChecks === [], $verifyChecks === [] ? 'install.ver.run' : 'install.again'); ?>
<?php endif; ?>
<?php endif; ?>

<?php if ($owns && $step !== 'requirements'): ?>
<details class="wk-install-log">
<summary><?= $e(t('install.log', ['data/' . Installer::LOG_FILE])) ?></summary>
<pre><?= $e($installer->logTail()) ?></pre>
</details>
<?php endif; ?>
<?php endif; ?>
</div>
</div>
</div>
<script src="<?= $e($asset('js/install.js')) ?>" defer></script>
</body>
</html>
