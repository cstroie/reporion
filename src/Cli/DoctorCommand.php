<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use DateTimeZone;
use FFI;
use PDO;
use Reporion\Auth\FlatFileUserStore;
use Throwable;

/**
 * bin/reporion doctor (docs/deploy-lighttpd.md "Checks"). Every check here
 * traces back to something that actually broke this session and had no
 * automated way to catch it before now — see docs/BUILD_LOG.md.
 *
 * Two checks are explicitly best-effort and say so in their own output,
 * rather than claiming a guarantee they can't provide:
 *
 *  - The FFI check only proves FFI works from THIS process (bin/reporion
 *    doctor runs as plain CLI, which trusts FFI::cdef() regardless of
 *    ffi.enable — confirmed empirically, see Support\Fsync). It cannot see
 *    whether the web SAPI (PHP-FPM, or php -S) has ffi.enable=1 set; that
 *    is a live-server-only property with no CLI-observable proxy.
 *  - The "docroot exposure" check is a live HTTP request to
 *    site.base_url + "/.git/config" and only means anything if base_url is
 *    the deployment's real, reachable URL — not the example.ro placeholder
 *    conf/local.php.example ships with.
 */
final class DoctorCommand implements CommandInterface
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config,
    ) {
    }

    public function run(array $args, Output $output): int
    {
        $checks = [
            $this->checkPhpVersion(),
            $this->checkExtensions(),
            $this->checkFts5(),
            $this->checkOwnerAccountExists(),
            $this->checkSessionSecret(),
            $this->checkDataWritable(),
            $this->checkFfiFromThisProcess(),
            $this->checkTimezone(),
            $this->checkDocrootExposure(),
            $this->checkFrontControllerReachable(),
        ];

        $failed = false;
        foreach ($checks as $check) {
            $tag = match ($check->status) {
                CheckStatus::Pass => 'PASS',
                CheckStatus::Warn => 'WARN',
                CheckStatus::Fail => 'FAIL',
            };
            $line = "[{$tag}] {$check->label}";
            if ($check->detail !== '') {
                $line .= ' — ' . $check->detail;
            }
            $output->line($line);
            $failed = $failed || $check->status === CheckStatus::Fail;
        }

        return $failed ? 1 : 0;
    }

    private function checkPhpVersion(): CheckResult
    {
        return PHP_VERSION_ID >= 80100
            ? CheckResult::pass('PHP version', PHP_VERSION)
            : CheckResult::fail('PHP version', PHP_VERSION . ' — 8.1+ required (D23)');
    }

    private function checkExtensions(): CheckResult
    {
        $required = ['pdo_sqlite', 'sqlite3', 'mbstring', 'intl', 'zlib', 'dom', 'ffi'];
        $missing = array_values(array_filter($required, static fn (string $ext): bool => !\extension_loaded($ext)));

        return $missing === []
            ? CheckResult::pass('PHP extensions', implode(', ', $required))
            : CheckResult::fail('PHP extensions', 'missing: ' . implode(', ', $missing));
    }

    private function checkFts5(): CheckResult
    {
        try {
            $pdo = new PDO('sqlite::memory:');
            $pdo->exec('CREATE VIRTUAL TABLE t USING fts5(x)');

            return CheckResult::pass('SQLite FTS5');
        } catch (Throwable) {
            return CheckResult::fail('SQLite FTS5', 'CREATE VIRTUAL TABLE ... USING fts5 failed');
        }
    }

    /**
     * D35 superseded auth.owner_password_hash — this now checks the thing
     * that actually determines whether anyone can sign in: at least one
     * active owner account in data/users/. Constructing FlatFileUserStore
     * does no I/O by itself (unlike Index\Sqlite), so this is safe to run
     * unconditionally, the same as every other check here.
     */
    private function checkOwnerAccountExists(): CheckResult
    {
        $dataDir = (string) ($this->config['paths']['data'] ?? '');
        if ($dataDir === '' || !is_dir($dataDir)) {
            return CheckResult::fail('At least one active owner account exists', 'paths.data does not exist: ' . $dataDir);
        }

        $users = new FlatFileUserStore($dataDir);
        foreach ($users->all() as $user) {
            if ($user->isOwner && $user->active) {
                return CheckResult::pass('At least one active owner account exists');
            }
        }

        return CheckResult::fail(
            'At least one active owner account exists',
            'no active owner in data/users/ — create one with: '
                . 'bin/reporion user:create --username=<u> --password-hash="$(php -r \'echo password_hash("…", PASSWORD_ARGON2ID);\')" --owner'
                . ' (quote --password-hash — an unquoted argon2id hash contains $ characters the shell will try to expand)'
        );
    }

    private function checkSessionSecret(): CheckResult
    {
        $secret = (string) ($this->config['auth']['session_secret'] ?? '');

        return $secret !== ''
            ? CheckResult::pass('Session secret configured')
            : CheckResult::fail('Session secret configured', 'auth.session_secret is empty in conf/local.php');
    }

    private function checkDataWritable(): CheckResult
    {
        $dataDir = (string) ($this->config['paths']['data'] ?? '');
        if ($dataDir === '' || !is_dir($dataDir)) {
            return CheckResult::fail('data/ writable', 'paths.data does not exist: ' . $dataDir);
        }

        // A real write, not just is_writable() — tonight's own permission
        // mismatch (costin vs www-data group ownership) would have passed
        // an is_writable() check on the directory while still failing an
        // actual file creation, depending on which process asks.
        $probe = $dataDir . '/.doctor-write-test-' . bin2hex(random_bytes(4));
        $canWrite = @file_put_contents($probe, 'x') !== false;
        if ($canWrite) {
            @unlink($probe);
        }

        return $canWrite
            ? CheckResult::pass('data/ writable')
            : CheckResult::fail('data/ writable', 'could not create a file in ' . $dataDir . ' as the current process user');
    }

    private function checkFfiFromThisProcess(): CheckResult
    {
        try {
            $ffi = FFI::cdef('int fsync(int fd);', 'libc.so.6');
            unset($ffi);

            return CheckResult::pass(
                'FFI usable from this process',
                'CLI only — does NOT prove the web SAPI has ffi.enable=1; see docs/deploy-lighttpd.md'
            );
        } catch (Throwable $e) {
            return CheckResult::fail('FFI usable from this process', $e->getMessage());
        }
    }

    private function checkTimezone(): CheckResult
    {
        $tz = (string) ($this->config['site']['timezone'] ?? '');
        if ($tz === '') {
            return CheckResult::fail('Timezone configured', 'site.timezone is empty in conf/local.php');
        }

        return \in_array($tz, DateTimeZone::listIdentifiers(), true)
            ? CheckResult::pass('Timezone configured', $tz)
            : CheckResult::fail('Timezone configured', "'{$tz}' is not a valid timezone identifier");
    }

    private function checkDocrootExposure(): CheckResult
    {
        $baseUrl = (string) ($this->config['site']['base_url'] ?? '');
        if ($baseUrl === '') {
            return CheckResult::warn('data/.git not web-exposed', 'site.base_url is empty, skipped');
        }

        $result = $this->httpGet(rtrim($baseUrl, '/') . '/.git/config');
        if ($result === null) {
            return CheckResult::warn('data/.git not web-exposed', 'could not reach ' . $baseUrl . ' — best-effort check skipped');
        }

        [$status, $body] = $result;
        // Status 200 alone is ambiguous: the app's own /{path} route
        // matches ANY path, including "/.git/config", and 404s or 200s on
        // it exactly as readily as a misconfigured web server would — a
        // check that only looked at the status code would pass for the
        // wrong reason even against a broken docroot. Content is what
        // actually proves the web server served a real file from outside
        // public/: "[core]" is the unambiguous signature of a real
        // .git/config — the exact file that leaked earlier tonight.
        $looksLikeGitConfig = $status === 200 && str_contains($body, '[core]');

        return !$looksLikeGitConfig
            ? CheckResult::pass('data/.git not web-exposed', ".git/config → HTTP {$status}")
            : CheckResult::fail('data/.git not web-exposed', '.git/config served as plain text — docroot is not restricted to public/ (D23)');
    }

    private function checkFrontControllerReachable(): CheckResult
    {
        $baseUrl = (string) ($this->config['site']['base_url'] ?? '');
        if ($baseUrl === '') {
            return CheckResult::warn('Front controller reachable', 'site.base_url is empty, skipped');
        }

        $result = $this->httpGet(rtrim($baseUrl, '/') . '/');
        if ($result === null) {
            return CheckResult::warn('Front controller reachable', 'could not reach ' . $baseUrl);
        }

        return CheckResult::pass('Front controller reachable', "GET / → HTTP {$result[0]}");
    }

    /**
     * @return array{0: int, 1: string}|null
     */
    private function httpGet(string $url): ?array
    {
        $context = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false || !isset($http_response_header[0])) {
            return null;
        }

        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m) !== 1) {
            return null;
        }

        return [(int) $m[1], $body];
    }
}
