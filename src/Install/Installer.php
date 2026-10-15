<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Install;

use DateTimeZone;
use InvalidArgumentException;
use Reporion\Auth\FlatFileUserStore;
use Reporion\Cli\DoctorCommand;
use Reporion\Cli\Output;
use Reporion\Index\Sqlite;
use Reporion\Kernel;
use Reporion\Service\Ai\AiConfig;
use Reporion\Service\Ai\DefaultPrompts;
use Reporion\Service\InstanceSettings;
use Reporion\Storage\FlatFile;
use Reporion\Support\Fsync;
use RuntimeException;
use Throwable;

/**
 * The web installer (D43, install.php): its progress, its lock, its log,
 * and what each step does. Everything before the configure step runs
 * without vendor/: this class is loaded by hand, and touches Reporion's
 * own services only once vendor/autoload.php is there.
 *
 * Installed means: conf/local.php exists and no install is in progress
 * (conf/.install-state.json). A hand-made install, or this box's own, is
 * therefore installed and install.php only redirects: it never offers to
 * create an owner on an instance that already has its configuration.
 *
 * The first browser that starts the install holds it (a random cookie,
 * stored hashed in the state): until the owner account exists, whoever
 * reaches install.php first would own a system for patient reports.
 */
final class Installer
{
    public const STATE_FILE = 'conf/.install-state.json';
    public const LOG_FILE = 'install.log';
    public const COOKIE = 'reporion_install';

    /**
     * The steps, in order. Security comes before the site and owner: in
     * root mode on a server that still serves data/, nothing secret (the
     * owner's hash, the session secret) may be written until the probe
     * has seen that it is not served.
     */
    public const STEPS = ['requirements', 'dependencies', 'secure', 'configure', 'verify'];

    /** An unattended install may be taken over after this long, until its owner account exists */
    private const IDLE_SECONDS = 3600;

    /** @var array<string, mixed>|null */
    private ?array $state = null;

    public function __construct(private readonly string $root)
    {
    }

    public static function isInstalled(string $root): bool
    {
        return is_file($root . '/conf/local.php') && !is_file($root . '/' . self::STATE_FILE);
    }

    public function root(): string
    {
        return $this->root;
    }

    public function dataDir(): string
    {
        return $this->root . '/data';
    }

    // ---- state and lock --------------------------------------------------

    public function started(): bool
    {
        return is_file($this->root . '/' . self::STATE_FILE);
    }

    /** @return array<string, mixed> */
    public function state(): array
    {
        if ($this->state === null) {
            $raw = @file_get_contents($this->root . '/' . self::STATE_FILE);
            $decoded = \is_string($raw) ? json_decode($raw, true) : null;
            $this->state = \is_array($decoded) ? $decoded : [];
        }

        return $this->state;
    }

    /** @param array<string, mixed> $changes */
    public function update(array $changes): void
    {
        $state = array_merge($this->state(), $changes, ['touched' => time()]);
        $json = (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        ConfigWriter::write($this->root . '/' . self::STATE_FILE, $json . "\n");
        $this->state = $state;
    }

    /** Whether $token holds this install */
    public function owns(string $token): bool
    {
        $hash = (string) ($this->state()['token'] ?? '');

        return $token !== '' && $hash !== '' && hash_equals($hash, hash('sha256', $token));
    }

    /** Someone else's install, still being worked on (or past its owner account) */
    public function heldByOther(string $token): bool
    {
        if (!$this->started() || $this->owns($token)) {
            return false;
        }
        $idle = time() - (int) ($this->state()['touched'] ?? 0);

        return $this->done('configure') || $idle < self::IDLE_SECONDS;
    }

    /**
     * Starts the install for $token (or takes over an abandoned one, see
     * heldByOther()). Creates data/ if it is missing.
     *
     * @param list<Check> $requirements logged as they were seen
     */
    public function claim(string $token, array $requirements, string $baseUrl): void
    {
        if ($this->heldByOther($token)) {
            throw new RuntimeException(t('install.err.held'));
        }
        $takeover = $this->started();
        $this->update([
            'token' => hash('sha256', $token),
            'started' => $this->state()['started'] ?? date('c'),
            'done' => $takeover ? ($this->state()['done'] ?? []) : [],
        ]);
        $this->ensureDataDir();
        $this->log($takeover ? 'install taken over by a new browser session' : 'install started');
        $this->log('PHP ' . PHP_VERSION . ' (' . PHP_SAPI . '), ' . ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown server') . ', at ' . $baseUrl);
        foreach ($requirements as $check) {
            $this->log(\sprintf('[%s] %s%s', strtoupper($check->status), $check->label, $check->detail !== '' ? ': ' . $check->detail : ''));
        }
        $this->markDone('requirements');
    }

    public function done(string $step): bool
    {
        return \in_array($step, (array) ($this->state()['done'] ?? []), true);
    }

    public function markDone(string $step): void
    {
        $done = (array) ($this->state()['done'] ?? []);
        if (!\in_array($step, $done, true)) {
            $done[] = $step;
        }
        $this->update(['done' => array_values($done)]);
    }

    /** The first step not done yet */
    public function current(): string
    {
        foreach (self::STEPS as $step) {
            if (!$this->done($step)) {
                return $step;
            }
        }

        return 'verify';
    }

    // ---- log -------------------------------------------------------------

    /**
     * One line of data/install.log (0640, beside the data it describes and
     * as private). Never a password, hash or session secret.
     */
    public function log(string $line): void
    {
        $file = $this->dataDir() . '/' . self::LOG_FILE;
        if (!is_dir($this->dataDir())) {
            return;
        }
        $isNew = !is_file($file);
        @file_put_contents($file, date('Y-m-d H:i:s') . '  ' . str_replace(["\r", "\n"], ' ', $line) . "\n", FILE_APPEND | LOCK_EX);
        if ($isNew) {
            @chmod($file, 0640);
        }
    }

    /** The log's last lines, for the page */
    public function logTail(int $lines = 40): string
    {
        $text = (string) @file_get_contents($this->dataDir() . '/' . self::LOG_FILE);
        $all = explode("\n", rtrim($text));

        return implode("\n", \array_slice($all, -$lines));
    }

    // ---- steps -----------------------------------------------------------

    public function ensureDataDir(): void
    {
        $data = $this->dataDir();
        if (!is_dir($data)) {
            $old = umask(0007);
            $made = @mkdir($data, 02770, true);
            umask($old);
            if (!$made && !is_dir($data)) {
                throw new RuntimeException(t('install.err.data', [$data]));
            }
            @chmod($data, 02770);
        }
        foreach (['tmp'] as $sub) {
            if (!is_dir($data . '/' . $sub)) {
                @mkdir($data . '/' . $sub, 02770, true);
            }
        }
    }

    public function installDependencies(): bool
    {
        if (Requirements::vendorReady($this->root)) {
            $this->log('dependencies: vendor/ already complete');
            $this->markDone('dependencies');

            return true;
        }
        @set_time_limit(0);
        $limit = (int) \ini_get('max_execution_time');
        $timeout = $limit > 0 ? max(30, $limit - 15) : 600;
        $this->log('dependencies: composer install --no-dev (timeout ' . $timeout . ' s)');
        $ok = (new ComposerRunner($this->root, $this->dataDir() . '/tmp/composer', $this->log(...)))->install($timeout);
        if ($ok) {
            $this->markDone('dependencies');
            $this->log('dependencies: done');
        }

        return $ok;
    }

    /**
     * conf/local.php, the owner account, the site settings and the index.
     * Re-running it (a step gone wrong half way) keeps the session secret
     * and updates the same owner instead of failing on it.
     *
     * @param array<string, string> $form
     *
     * @return list<string> what is wrong with the form; empty when done
     */
    public function configure(array $form): array
    {
        $title = trim($form['site_title'] ?? '');
        $baseUrl = rtrim(trim($form['base_url'] ?? ''), '/');
        $timezone = trim($form['timezone'] ?? '');
        $username = strtolower(trim($form['username'] ?? ''));
        $displayName = trim($form['display_name'] ?? '');
        $password = (string) ($form['password'] ?? '');

        $this->requireDone('secure');
        $errors = [];
        if ($title === '' || mb_strlen($title) > 80) {
            $errors[] = t('install.cfg.err_title');
        }
        if (!self::validBaseUrl($baseUrl)) {
            $errors[] = t('install.cfg.err_url');
        }
        if (!\in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $errors[] = t('install.cfg.err_tz');
        }
        if (preg_match('/^[a-z0-9](?:[a-z0-9_.-]{0,62}[a-z0-9])?$/', $username) !== 1) {
            $errors[] = t('install.cfg.err_user');
        }
        if (mb_strlen($password) < 10) {
            $errors[] = t('install.cfg.err_pass_short');
        } elseif ($password !== (string) ($form['password2'] ?? '')) {
            $errors[] = t('install.cfg.err_pass_match');
        }
        $owner = (string) ($this->state()['owner'] ?? '');
        if ($owner !== '' && $owner !== $username) {
            $errors[] = t('install.cfg.err_owner', [$owner]);
        }
        if ($errors !== []) {
            return $errors;
        }

        $this->requireAutoload();
        $this->ensureDataDir();

        // conf/local.php: the secret is kept across a re-run, else everyone signed in so far is signed out
        $confFile = $this->root . '/conf/local.php';
        $secret = '';
        if (is_file($confFile)) {
            $existing = require $confFile;
            $secret = \is_array($existing) ? (string) ($existing['auth']['session_secret'] ?? '') : '';
        }
        if ($secret === '') {
            $secret = bin2hex(random_bytes(32));
        }
        $example = (string) file_get_contents($this->root . '/conf/local.php.example');
        ConfigWriter::write($confFile, ConfigWriter::render($example, [
            'auth.session_secret' => $secret,
        ]));
        $this->log('configure: conf/local.php written (mode 0640, new session secret)');

        $config = Kernel::withInstanceSettings(require $confFile);
        $dataDir = (string) $config['paths']['data'];

        // The owner (D35): the store validates the name again and writes atomically
        $users = new FlatFileUserStore($dataDir);
        $hash = password_hash($password, PASSWORD_ARGON2ID);
        $account = $users->find($username);
        if ($account === null) {
            $users->create($username, $hash, true, [], $displayName);
            $this->log('configure: owner account "' . $username . '" created');
        } else {
            $users->save($account->with(active: true, displayName: $displayName, passwordHash: $hash));
            $this->log('configure: owner account "' . $username . '" updated');
        }
        $this->update(['owner' => $username]);

        // Admin → Settings → Site, so the values live where the owner edits them later
        (new InstanceSettings($dataDir))->save([
            'site.title' => $title,
            'site.base_url' => $baseUrl,
            'site.timezone' => $timezone,
        ]);
        $this->log('configure: data/settings.yaml: site title, public address ' . $baseUrl . ', time zone ' . $timezone);

        // The first process to open the index creates its schema
        $index = new Sqlite((string) $config['paths']['index'], $this->root . '/migrations');
        $this->log('configure: index created at data/' . basename((string) $config['paths']['index']));

        // The proposed prompts (docs/prompts), when asked for: pages of the default profile; the assistant stays off (D15)
        if (($form['prompts'] ?? '') === '1') {
            $profile = AiConfig::promptProfile((array) ($config['ai'] ?? []));
            $created = (new DefaultPrompts(new FlatFile($dataDir, $index), $this->root . '/docs/prompts'))->install($profile, $username);
            $this->log('configure: ' . \count($created) . ' proposed prompt page(s) under ai:profiles:' . $profile . ':special');
        }
        unset($index);

        $this->markDone('configure');

        return [];
    }

    /**
     * Locks down what the installer can lock down itself: the .htaccess
     * files Apache reads (the others need server config, ServerConfig),
     * modes, the public/assets link, and a canary file for the browser's
     * exposure probe.
     *
     * @return list<string> the actions taken, for the page
     */
    public function secure(): array
    {
        $this->requireDone('dependencies');
        $this->requireAutoload();
        $actions = [];
        $deny = "# Written by install.php (D43). Apache only; lighttpd/nginx need server config.\n"
            . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
        foreach (['data', 'conf'] as $dir) {
            $file = $this->root . '/' . $dir . '/.htaccess';
            if (!is_file($file) && @file_put_contents($file, $deny) !== false) {
                $actions[] = $dir . '/.htaccess';
            }
        }

        foreach (['data' => 02770, 'conf/local.php' => 0640, 'data/' . self::LOG_FILE => 0640, 'data/' . InstanceSettings::FILE => 0640] as $path => $mode) {
            $full = $this->root . '/' . $path;
            if (file_exists($full) && (fileperms($full) & 07777) !== $mode && @chmod($full, $mode)) {
                $actions[] = \sprintf('chmod %o %s', $mode, $path);
            }
        }

        $canary = bin2hex(random_bytes(16));
        @file_put_contents($this->dataDir() . '/.install-probe.txt', $canary);
        $this->update(['canary' => $canary]);

        foreach ($actions as $action) {
            $this->log('secure: ' . $action);
        }

        return $actions;
    }

    /** What the browser fetches (install.js), relative to the base path; the routing probe only at the end */
    public const LEAK_PROBES = ['data/.install-probe.txt', 'conf/local.php.example', 'composer.json', '.git/config', 'data/' . self::LOG_FILE];
    public const ASSET_PROBE = 'assets/css/tokens.css';
    public const LOGIN_PROBE = 'login';

    /** What the browser said about a request that did not complete (install.js), bounded and printable */
    private static function probeError(array $result): string
    {
        $error = trim((string) preg_replace('/[^\P{C}]+/u', ' ', (string) ($result['error'] ?? '')));

        return $error !== '' ? mb_substr($error, 0, 300) : t('install.sec.no_error');
    }

    /**
     * The browser's probe results (install.js), judged: data/, conf/ and
     * the repository files must not be served, assets must be. Done once
     * before anything secret is written (the Security step), and again in
     * the final checks, when data/ holds the owner's account.
     *
     * @param array<string, array{status?: int, body?: string}> $results path → what the browser got
     *
     * @return list<Check>
     */
    public function judgeLeaks(array $results): array
    {
        $tells = array_combine(self::LEAK_PROBES, [(string) ($this->state()['canary'] ?? ''), 'session_secret', '"reporion/reporion"', '[core]', 'install started']);
        $leaked = [];
        foreach ($tells as $path => $tell) {
            if ($tell !== '' && str_contains((string) ($results[$path]['body'] ?? ''), $tell)) {
                $leaked[] = $path;
            }
        }
        // a request that did not complete proves nothing about what is served
        $unchecked = [];
        foreach (self::LEAK_PROBES as $path) {
            if (isset($results[$path]) && (int) ($results[$path]['status'] ?? 0) === 0) {
                $unchecked[] = $path;
            }
        }
        $checks = [];
        if ($leaked !== []) {
            $checks[] = Check::fail(t('install.sec.private'), t('install.sec.leaked', [implode(', ', $leaked)]));
        } elseif ($unchecked !== []) {
            $checks[] = Check::fail(t('install.sec.private'), t('install.sec.unchecked', [implode(', ', $unchecked), self::probeError($results[$unchecked[0]])]));
        } else {
            $checks[] = Check::pass(t('install.sec.private'), implode(', ', self::LEAK_PROBES));
        }

        $css = $results[self::ASSET_PROBE] ?? [];
        $status = (int) ($css['status'] ?? 0);
        $checks[] = $status === 200 && str_contains((string) ($css['body'] ?? ''), '--color-')
            ? Check::pass(t('install.sec.assets'), self::ASSET_PROBE . ' → 200')
            : Check::fail(t('install.sec.assets'), t('install.sec.assets_bad', [$status]) . ($status === 0 ? ': ' . self::probeError($css) : ''));

        return $this->logged('secure', $checks);
    }

    /**
     * The front controller answers: the sign-in page, as a visitor gets it
     *
     * @param array<string, array{status?: int, body?: string}> $results
     */
    public function judgeRouting(array $results): Check
    {
        $login = $results[self::LOGIN_PROBE] ?? [];

        return (int) ($login['status'] ?? 0) === 200 && str_contains((string) ($login['body'] ?? ''), 'name="password"')
            ? Check::pass(t('install.sec.routing'), 'GET /login → 200')
            : Check::fail(t('install.sec.routing'), t('install.sec.routing_bad', [(int) ($login['status'] ?? 0)]) . ((int) ($login['status'] ?? 0) === 0 ? ': ' . self::probeError($login) : ''));
    }

    /**
     * The end-to-end checks: the browser's probe again (now that data/
     * holds the owner) and the sign-in page, then what the installer can
     * run in the web SAPI itself, then `bin/reporion doctor`, in-process.
     *
     * @param array<string, array{status?: int, body?: string}> $results the browser's probe
     *
     * @return list<Check>
     */
    public function verify(array $results): array
    {
        $this->requireDone('configure');
        $this->requireAutoload();
        $checks = $this->judgeLeaks($results);
        $checks[] = $this->judgeRouting($results);

        // A real fsync through FFI, as the web server runs it: what INSTALL.md
        // otherwise only learns from the first failed save
        $probe = $this->dataDir() . '/tmp/.install-fsync-' . bin2hex(random_bytes(4));
        try {
            file_put_contents($probe, 'x');
            Fsync::file($probe);
            Fsync::directory(\dirname($probe));
            $checks[] = Check::pass(t('install.ver.fsync'));
        } catch (Throwable $e) {
            $checks[] = Check::fail(t('install.ver.fsync'), $e->getMessage(), 'php_admin_value[ffi.enable] = 1');
        } finally {
            @unlink($probe);
        }

        $config = Kernel::withInstanceSettings(require $this->root . '/conf/local.php');
        try {
            $index = new Sqlite((string) $config['paths']['index'], $this->root . '/migrations');
            unset($index);
            $checks[] = Check::pass(t('install.ver.index'));
        } catch (Throwable $e) {
            $checks[] = Check::fail(t('install.ver.index'), $e::class);
        }

        // judgeLeaks() logged its own two
        $this->logged('verify', \array_slice($checks, 2));

        return array_merge($checks, $this->logged('verify', $this->doctor($config)));
    }

    /**
     * Ends the install: the state goes, so install.php only redirects from
     * now on. $remove also deletes install.php itself where it can.
     *
     * @return list<string> the installer files that could not be removed
     */
    public function finish(bool $remove): array
    {
        $this->requireDone('configure');
        @unlink($this->dataDir() . '/.install-probe.txt');
        $kept = [];
        if ($remove) {
            foreach (['install.php', 'public/install.php'] as $file) {
                $full = $this->root . '/' . $file;
                if (is_file($full) && !@unlink($full)) {
                    $kept[] = $file;
                }
            }
        }
        $this->log('install finished' . ($remove ? '; installer removed' . ($kept !== [] ? ' except ' . implode(', ', $kept) : '') : ''));
        if (!@unlink($this->root . '/' . self::STATE_FILE)) {
            throw new RuntimeException(t('install.err.state'));
        }
        $this->state = [];

        return $kept;
    }

    /**
     * public/assets → ../assets, when an extraction dropped the symlink
     * (docs/deploy-lighttpd.md). Done on the first request, before any
     * claim: without it the installer's own page has no stylesheet when
     * the URL maps to public/. Harmless: it only points at assets/.
     */
    public function ensureAssetsLink(): void
    {
        $link = $this->root . '/public/assets';
        if (!file_exists($link) && !is_link($link) && is_writable($this->root . '/public')) {
            @symlink('../assets', $link);
        }
    }

    public static function validBaseUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return \in_array($scheme, ['http', 'https'], true) && parse_url($url, PHP_URL_QUERY) === null && parse_url($url, PHP_URL_FRAGMENT) === null;
    }

    /**
     * doctor's checks, run here: the same list, now in the web SAPI. Its
     * own FFI line ("CLI only") is dropped: install.ver.fsync above is the
     * real one.
     *
     * @param array<string, mixed> $config
     *
     * @return list<Check>
     */
    private function doctor(array $config): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        if ($out === false || $err === false) {
            return [];
        }
        (new DoctorCommand($config, $this->root . '/conf/local.php'))->run([], new Output($out, $err));
        rewind($out);
        $checks = [];
        foreach (explode("\n", (string) stream_get_contents($out)) as $line) {
            if (preg_match('/^\[(PASS|WARN|FAIL)\] (.*?)(?:: (.*))?$/u', $line, $m) !== 1 || str_starts_with($m[2], 'FFI usable')) {
                continue;
            }
            $checks[] = new Check(strtolower($m[1]), 'doctor: ' . $m[2], $m[3] ?? '');
        }

        return $checks;
    }

    /** Steps run in order, whatever a request asks for */
    private function requireDone(string $step): void
    {
        if (!$this->done($step)) {
            throw new RuntimeException(t('install.err.order', [t('install.step.' . $step)]));
        }
    }

    /**
     * @param list<Check> $checks
     *
     * @return list<Check>
     */
    private function logged(string $step, array $checks): array
    {
        foreach ($checks as $check) {
            $this->log(\sprintf('%s: [%s] %s%s', $step, strtoupper($check->status), $check->label, $check->detail !== '' ? ': ' . $check->detail : ''));
        }

        return $checks;
    }

    private function requireAutoload(): void
    {
        if (!Requirements::vendorReady($this->root)) {
            throw new InvalidArgumentException(t('install.err.vendor'));
        }
        require_once $this->root . '/vendor/autoload.php';
    }
}
