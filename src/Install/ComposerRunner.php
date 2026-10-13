<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Install;

use Closure;

/**
 * `composer install --no-dev` from the web installer (D43), as the web
 * user. Uses the server's composer when there is one, otherwise downloads
 * composer.phar and checks it against the SHA-256 getcomposer.org
 * publishes for it. Run as an argument list (no shell), with a private
 * COMPOSER_HOME: the web user usually has no home directory of its own.
 */
final class ComposerRunner
{
    private const PHAR_URL = 'https://getcomposer.org/download/latest-stable/composer.phar';

    /**
     * @param Closure(string): void $log one line of what happened (never secret: there is nothing secret here)
     */
    public function __construct(
        private readonly string $root,
        private readonly string $home,
        private readonly Closure $log,
    ) {
    }

    /** True when vendor/ is complete afterwards */
    public function install(int $timeoutSeconds): bool
    {
        $php = self::phpCli();
        if ($php === null) {
            ($this->log)('composer: no PHP command-line binary found next to ' . PHP_BINARY . ' or on PATH');

            return false;
        }
        ($this->log)('composer: PHP CLI ' . $php);

        $composer = $this->composer();
        if ($composer === null) {
            return false;
        }
        ($this->log)('composer: ' . $composer);

        if (!is_dir($this->home) && !@mkdir($this->home, 0770, true) && !is_dir($this->home)) {
            ($this->log)('composer: cannot create ' . $this->home);

            return false;
        }

        $command = [$php, $composer, 'install', '--no-dev', '--no-interaction', '--no-progress', '--optimize-autoloader', '--working-dir=' . $this->root];
        ($this->log)('composer: ' . implode(' ', \array_slice($command, 2)));
        $exit = $this->run($command, $timeoutSeconds);
        ($this->log)('composer: exit ' . $exit);

        return $exit === 0 && Requirements::vendorReady($this->root);
    }

    /**
     * The php CLI matching this PHP: FPM's PHP_BINARY is php-fpm, which
     * cannot run a script
     */
    public static function phpCli(): ?string
    {
        $minor = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $candidates = [];
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server') {
            $candidates[] = PHP_BINARY;
        }
        foreach ([PHP_BINDIR, '/usr/bin', '/usr/local/bin'] as $dir) {
            $candidates[] = $dir . '/php' . $minor;
            $candidates[] = $dir . '/php';
        }
        foreach (array_unique($candidates) as $candidate) {
            if ($candidate !== '' && @is_file($candidate) && @is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** The server's composer, or a verified composer.phar in $home */
    private function composer(): ?string
    {
        foreach (['/usr/local/bin/composer', '/usr/bin/composer', $this->home . '/composer.phar'] as $candidate) {
            if (@is_file($candidate) && @is_readable($candidate)) {
                return $candidate;
            }
        }

        ($this->log)('composer: none installed, downloading ' . self::PHAR_URL);
        $phar = self::fetch(self::PHAR_URL);
        $sum = self::fetch(self::PHAR_URL . '.sha256');
        if ($phar === null || $sum === null) {
            ($this->log)('composer: download failed (no network, or allow_url_fopen off and no curl)');

            return null;
        }
        $expected = strtolower(substr(trim($sum), 0, 64));
        if (!hash_equals($expected, hash('sha256', $phar))) {
            ($this->log)('composer: composer.phar does not match its published SHA-256, not used');

            return null;
        }
        if (!is_dir($this->home)) {
            @mkdir($this->home, 0770, true);
        }
        $target = $this->home . '/composer.phar';
        if (@file_put_contents($target, $phar) !== \strlen($phar)) {
            ($this->log)('composer: cannot write ' . $target);

            return null;
        }
        ($this->log)('composer: composer.phar verified (sha256 ' . $expected . ')');

        return $target;
    }

    /**
     * @param list<string> $command
     */
    private function run(array $command, int $timeoutSeconds): int
    {
        $env = [
            'HOME' => $this->home,
            'COMPOSER_HOME' => $this->home,
            'COMPOSER_NO_INTERACTION' => '1',
            'PATH' => '/usr/local/bin:/usr/bin:/bin',
        ];
        $process = @proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $this->root, $env);
        if (!\is_resource($process)) {
            ($this->log)('composer: proc_open failed');

            return -1;
        }
        stream_set_blocking($pipes[1], false);
        $deadline = time() + $timeoutSeconds;
        $buffer = '';
        while (true) {
            $read = [$pipes[1]];
            $write = $except = null;
            if (@stream_select($read, $write, $except, 1) > 0) {
                $chunk = (string) fread($pipes[1], 8192);
                $buffer .= $chunk;
                while (($nl = strpos($buffer, "\n")) !== false) {
                    $this->logOutput(substr($buffer, 0, $nl));
                    $buffer = substr($buffer, $nl + 1);
                }
            }
            if (feof($pipes[1])) {
                break;
            }
            if (time() > $deadline) {
                ($this->log)('composer: still running after ' . $timeoutSeconds . ' s, stopped');
                proc_terminate($process);
                fclose($pipes[1]);
                proc_close($process);

                return -1;
            }
        }
        if (trim($buffer) !== '') {
            $this->logOutput($buffer);
        }
        fclose($pipes[1]);

        return proc_close($process);
    }

    private function logOutput(string $line): void
    {
        $line = rtrim($line);
        if ($line !== '') {
            ($this->log)('  ' . $line);
        }
    }

    private static function fetch(string $url): ?string
    {
        if (filter_var(\ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 60, 'follow_location' => 1]]));

            return \is_string($body) && $body !== '' ? $body : null;
        }
        if (\function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60]);
            $body = curl_exec($curl);
            curl_close($curl);

            return \is_string($body) && $body !== '' ? $body : null;
        }

        return null;
    }
}
