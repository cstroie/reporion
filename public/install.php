<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

/**
 * The web installer (D43). Reached as public/install.php when the URL maps
 * to public/ (D23), or through ../install.php when the archive was
 * unpacked inside a web root. Once installed (Install\Installer::
 * isInstalled()) it only redirects to the site; it can also be deleted.
 *
 * Runs before vendor/ exists, so it loads what it needs by hand: the
 * strings (src/lang.php, D26) and src/Install/. Reporion's services are
 * used only from the configure step on, once Composer has run.
 */

use Reporion\Install\Check;
use Reporion\Install\Environment;
use Reporion\Install\Installer;
use Reporion\Install\Requirements;
use Reporion\Install\ServerConfig;

$root = \dirname(__DIR__);
require_once $root . '/src/lang.php';
foreach (['Check', 'Environment', 'Requirements', 'ConfigWriter', 'ServerConfig', 'ComposerRunner', 'Installer'] as $class) {
    require_once $root . '/src/Install/' . $class . '.php';
}

$base = ServerConfig::basePath((string) ($_SERVER['SCRIPT_NAME'] ?? '/install.php'));

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

if (Installer::isInstalled($root)) {
    header('Location: ' . $base . '/', true, 302);
    exit;
}

$installer = new Installer($root);
$installer->ensureAssetsLink();
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
$host = preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
$detectedUrl = ($https ? 'https' : 'http') . '://' . $host . $base;
$scriptFile = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) ?: '';
$rootMode = $scriptFile !== '' && $scriptFile === realpath($root . '/install.php');

// The browser holding this install (Installer::claim())
$token = (string) ($_COOKIE[Installer::COOKIE] ?? '');
if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
    $token = bin2hex(random_bytes(32));
}
setcookie(Installer::COOKIE, $token, [
    'path' => $base . '/',
    'httponly' => true,
    'samesite' => 'Strict',
    'secure' => $https,
]);

$env = Environment::current();
$requirements = (new Requirements($root, $env))->all();

$render = static function (array $vars) use ($root): never {
    extract($vars);
    require $root . '/templates/install.php';
    exit;
};
$redirect = static function (string $step) use ($base): never {
    header('Location: ' . $base . '/install.php?step=' . rawurlencode($step), true, 303);
    exit;
};
/** @param list<Check> $checks */
$toArrays = static fn (array $checks): array => array_map(static fn (Check $c): array => (array) get_object_vars($c), $checks);
$fromArrays = static fn (mixed $rows): array => array_map(
    static fn (array $r): Check => new Check((string) ($r['status'] ?? 'fail'), (string) ($r['label'] ?? ''), (string) ($r['detail'] ?? ''), (string) ($r['fix'] ?? '')),
    \is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [],
);

/** The browser's probe (install.js), posted as JSON */
$results = static function (): array {
    $decoded = json_decode((string) ($_POST['results'] ?? ''), true);

    return \is_array($decoded) ? $decoded : [];
};

$vars = [
    'base' => $base,
    'step' => 'requirements',
    'installer' => $installer,
    'requirements' => $requirements,
    'csrf' => $token,
    'detectedUrl' => $detectedUrl,
    'rootMode' => $rootMode,
    'serverName' => $env->server(),
    'webUser' => $env->webUser,
    'errors' => [],
    'form' => [],
    'flash' => null,
    'held' => false,
];

if ($installer->heldByOther($token)) {
    http_response_code(409);
    $render(['held' => true] + $vars);
}

$state = $installer->state();
$flash = $state['flash'] ?? null;
if ($flash !== null) {
    $installer->update(['flash' => null]);
}
$vars['flash'] = \is_array($flash) ? $flash : null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals($token, (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        $render(['flash' => ['type' => 'fail', 'text' => t('install.err.csrf')]] + $vars);
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action !== 'begin' && !$installer->owns($token)) {
        $redirect('requirements');
    }

    try {
        switch ($action) {
            case 'begin':
                if (Check::anyFailed($requirements)) {
                    $redirect('requirements');
                }
                $installer->claim($token, $requirements, $detectedUrl);
                $redirect('dependencies');
                // no break
            case 'dependencies':
                $ok = $installer->installDependencies();
                $installer->update(['flash' => $ok
                    ? ['type' => 'pass', 'text' => t('install.dep.ok')]
                    : ['type' => 'fail', 'text' => t('install.dep.failed')]]);
                $redirect($ok ? 'secure' : 'dependencies');
                // no break
            case 'configure':
                $form = array_map(static fn (mixed $v): string => \is_string($v) ? $v : '', $_POST);
                $errors = $installer->configure($form);
                if ($errors !== []) {
                    unset($form['password'], $form['password2']);
                    $render(['step' => 'configure', 'errors' => $errors, 'form' => $form] + $vars);
                }
                $redirect('verify');
                // no break
            case 'secure':
                $installer->update(['secure_actions' => $installer->secure(), 'probe_checks' => null]);
                $redirect('secure');
                // no break
            case 'probes':
                $checks = $installer->judgeLeaks($results());
                $installer->update(['probe_checks' => $toArrays($checks)]);
                if (!Check::anyFailed($checks)) {
                    $installer->markDone('secure');
                    $redirect('configure');
                }
                $redirect('secure');
                // no break
            case 'verify':
                $installer->update(['verify_checks' => $toArrays($installer->verify($results()))]);
                $redirect('verify');
                // no break
            case 'finish':
                $verified = $fromArrays($installer->state()['verify_checks'] ?? null);
                if ($verified === [] || Check::anyFailed($verified)) {
                    $redirect('verify');
                }
                $installer->finish(($_POST['remove'] ?? '') === '1');
                header('Location: ' . $base . '/login', true, 303);
                exit;
            default:
                $redirect('requirements');
        }
    } catch (Throwable $e) {
        $installer->log('error: ' . $e::class . ': ' . $e->getMessage());
        if ($installer->started()) {
            $installer->update(['flash' => ['type' => 'fail', 'text' => $e->getMessage()]]);
            $redirect($installer->current());
        }
        $render(['flash' => ['type' => 'fail', 'text' => $e->getMessage()]] + $vars);
    }
}

// GET: a step already reached, else the current one
$wanted = (string) ($_GET['step'] ?? '');
$step = 'requirements';
if ($installer->owns($token)) {
    $current = $installer->current();
    $reachable = \array_slice(Installer::STEPS, 0, (int) array_search($current, Installer::STEPS, true) + 1);
    $step = \in_array($wanted, $reachable, true) ? $wanted : $current;
}

$render([
    'step' => $step,
    'secureActions' => (array) ($installer->state()['secure_actions'] ?? []),
    'probeChecks' => $fromArrays($installer->state()['probe_checks'] ?? null),
    'verifyChecks' => $fromArrays($installer->state()['verify_checks'] ?? null),
    'snippets' => [
        'lighttpd' => ServerConfig::lighttpd($root, $base, $rootMode),
        'nginx' => ServerConfig::nginx($root, $base, $rootMode, '/run/php/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '-fpm.sock'),
        'apache' => ServerConfig::apache($root, $base, $rootMode),
        'recommended' => ServerConfig::lighttpd($root, $base, false),
        // Only the /assets/ caching, for the "next steps"
        'cache_lighttpd' => ServerConfig::assetCache('lighttpd', $base),
        'cache_nginx' => ServerConfig::assetCache('nginx', $base),
        'cache_apache' => ServerConfig::assetCache('apache', $base),
    ],
] + $vars);
