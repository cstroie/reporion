<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Install;

/**
 * The web installer's first step (D43): what this PHP and this directory
 * need before anything is written, each with the command that fixes it
 * when the installer cannot. The PHP-side checks are those of
 * `bin/reporion doctor`, but taken in the web SAPI, where FFI and the
 * limits actually matter.
 */
final class Requirements
{
    /** Extensions composer.json does not list but the code needs */
    private const ALWAYS = ['pdo_sqlite', 'sqlite3', 'mbstring', 'intl', 'zlib', 'dom', 'xml', 'iconv', 'gd', 'ffi'];

    /** Debian/Ubuntu package suffix for an extension that is not built in */
    private const PACKAGES = [
        'pdo_sqlite' => 'sqlite3', 'sqlite3' => 'sqlite3', 'mbstring' => 'mbstring', 'intl' => 'intl',
        'dom' => 'xml', 'xml' => 'xml', 'gd' => 'gd', 'curl' => 'curl',
    ];

    public function __construct(
        private readonly string $root,
        private readonly Environment $env,
    ) {
    }

    /**
     * @return list<Check>
     */
    public function all(): array
    {
        return [
            $this->phpVersion(),
            $this->extensions(),
            $this->argon2(),
            $this->fts5(),
            $this->ffi(),
            $this->limits(),
            $this->openBasedir(),
            $this->folders(),
            $this->assetsLink(),
        ];
    }

    public function phpVersion(): Check
    {
        $label = t('install.req.php');

        return $this->env->phpVersionId >= 80100
            ? Check::pass($label, $this->env->phpVersion)
            : Check::fail($label, t('install.req.php_old', [$this->env->phpVersion]), t('install.req.php_fix'));
    }

    public function extensions(): Check
    {
        $label = t('install.req.ext');
        $required = array_values(array_unique(array_merge(self::ALWAYS, $this->composerExtensions())));
        $missing = array_values(array_filter($required, fn (string $ext): bool => !$this->env->has($ext)));
        if ($missing === []) {
            return Check::pass($label, implode(', ', $required));
        }

        $version = $this->phpMinor();
        $packages = array_values(array_unique(array_map(
            static fn (string $ext): string => 'php' . $version . '-' . self::PACKAGES[$ext],
            array_filter($missing, static fn (string $ext): bool => isset(self::PACKAGES[$ext])),
        )));
        $fix = $packages !== [] ? 'sudo apt install ' . implode(' ', $packages) . "\n" : '';

        return Check::fail(
            $label,
            t('install.req.ext_missing', [implode(', ', $missing)]),
            $fix . $this->restartCommand(),
        );
    }

    public function argon2(): Check
    {
        return $this->env->argon2
            ? Check::pass(t('install.req.argon2'))
            : Check::fail(t('install.req.argon2'), t('install.req.argon2_missing'), t('install.req.argon2_fix'));
    }

    public function fts5(): Check
    {
        return $this->env->fts5Error === null
            ? Check::pass(t('install.req.fts5'))
            : Check::fail(t('install.req.fts5'), $this->env->fts5Error, t('install.req.fts5_fix'));
    }

    /**
     * Every save fsyncs through FFI (invariant 7). What doctor cannot see
     * from the CLI, this sees directly.
     */
    public function ffi(): Check
    {
        $label = t('install.req.ffi');
        if ($this->env->ffiError === null) {
            return Check::pass($label, 'ffi.enable = ' . ($this->env->ini['ffi.enable'] ?? ''));
        }
        $fix = match ($this->env->server()) {
            'builtin' => 'php -d ffi.enable=1 -S …   (bin/reporion serve does this)',
            default => "php_admin_value[ffi.enable] = 1   ; in the PHP-FPM pool (/etc/php/{$this->phpMinor()}/fpm/pool.d/www.conf)\n" . $this->restartCommand(),
        };

        return Check::fail($label, $this->env->ffiError, $fix);
    }

    /** memory_limit, max_execution_time and the upload sizes: a warning each, never a stop */
    public function limits(): Check
    {
        $label = t('install.req.limits');
        $low = [];
        $memory = self::bytes($this->env->ini['memory_limit'] ?? '');
        if ($memory !== -1 && $memory < 256 * 1024 * 1024) {
            $low[] = 'memory_limit = ' . ($this->env->ini['memory_limit'] ?? '') . ' (256M)';
        }
        $time = (int) ($this->env->ini['max_execution_time'] ?? '0');
        if ($time !== 0 && $time < 300) {
            $low[] = 'max_execution_time = ' . $time . ' (300)';
        }
        foreach (['post_max_size', 'upload_max_filesize'] as $key) {
            $bytes = self::bytes($this->env->ini[$key] ?? '');
            if ($bytes !== -1 && $bytes !== 0 && $bytes < 8 * 1024 * 1024) {
                $low[] = $key . ' = ' . ($this->env->ini[$key] ?? '') . ' (32M)';
            }
        }

        if ($low === []) {
            return Check::pass($label, 'memory_limit ' . ($this->env->ini['memory_limit'] ?? '') . ', max_execution_time ' . $time . ', post_max_size ' . ($this->env->ini['post_max_size'] ?? ''));
        }

        return Check::warn(
            $label,
            t('install.req.limits_low', [implode(', ', $low)]),
            $this->env->server() === 'builtin'
                ? 'php -d memory_limit=256M -d max_execution_time=300 -d upload_max_filesize=32M -d post_max_size=32M -S …'
                : "php_admin_value[memory_limit] = 256M\nphp_admin_value[max_execution_time] = 300\nphp_admin_value[upload_max_filesize] = 32M\nphp_admin_value[post_max_size] = 32M\n" . $this->restartCommand(),
        );
    }

    public function openBasedir(): Check
    {
        $label = t('install.req.open_basedir');
        $value = $this->env->ini['open_basedir'] ?? '';

        return $value === ''
            ? Check::pass($label, t('install.req.open_basedir_off'))
            : Check::warn($label, t('install.req.open_basedir_on', [$value]));
    }

    /**
     * conf/, data/ and vendor/ as one line, so the commands the owner has to
     * run come as one block.
     */
    public function folders(): Check
    {
        $checks = [$this->confWritable(), $this->dataDirectory(), $this->vendor()];
        $status = Check::PASS;
        $details = [];
        $fixes = [];
        foreach ($checks as $check) {
            if ($check->status === Check::FAIL) {
                $status = Check::FAIL;
            } elseif ($check->status === Check::WARN && $status === Check::PASS) {
                $status = Check::WARN;
            }
            $details[] = $check->label . ': ' . $check->detail;
            if ($check->status !== Check::PASS && $check->fix !== '') {
                $fixes[] = $check->fix;
            }
        }

        return new Check($status, t('install.req.folders'), implode("\n", $details), implode("\n", array_unique($fixes)));
    }

    /** The installer writes conf/local.php and keeps its own progress in conf/ */
    public function confWritable(): Check
    {
        $label = t('install.req.conf');
        $conf = $this->root . '/conf';
        if (is_dir($conf) && is_writable($conf)) {
            return Check::pass($label, $conf);
        }

        return Check::fail(
            $label,
            t('install.req.not_writable', [$conf, $this->env->webUser]),
            "sudo chgrp {$this->env->webUser} {$conf} && sudo chmod 2775 {$conf}",
        );
    }

    /** data/ holds the patient reports: owned by the web user, no access for others */
    public function dataDirectory(): Check
    {
        $label = t('install.req.data');
        $data = $this->root . '/data';
        $fix = "sudo install -d -o {$this->env->webUser} -g {$this->env->webUser} -m 2770 {$data}";
        if (is_dir($data)) {
            return is_writable($data)
                ? Check::pass($label, $data)
                : Check::fail($label, t('install.req.not_writable', [$data, $this->env->webUser]), "sudo chown -R {$this->env->webUser}:{$this->env->webUser} {$data}");
        }

        return is_writable($this->root)
            ? Check::pass($label, t('install.req.data_create', [$data]))
            : Check::fail($label, t('install.req.data_missing', [$data, $this->env->webUser]), $fix);
    }

    /**
     * vendor/ (Composer's packages). Missing is not a stop when the next
     * step can run Composer here; otherwise it is the one command to run.
     */
    public function vendor(): Check
    {
        $label = t('install.req.vendor');
        if (self::vendorReady($this->root)) {
            return Check::pass($label, t('install.req.vendor_ok'));
        }
        $command = "cd {$this->root} && sudo -u {$this->env->webUser} composer install --no-dev";
        if ($this->env->disabled('proc_open') || !\function_exists('proc_open')) {
            return Check::fail($label, t('install.req.vendor_no_proc'), $command);
        }
        $vendor = $this->root . '/vendor';
        $writable = is_dir($vendor) ? is_writable($vendor) : is_writable($this->root);
        if (!$writable) {
            return Check::fail(
                $label,
                t('install.req.not_writable', [is_dir($vendor) ? $vendor : $this->root, $this->env->webUser]),
                "sudo install -d -o {$this->env->webUser} -g {$this->env->webUser} -m 2775 {$vendor}",
            );
        }

        return Check::warn($label, t('install.req.vendor_next'));
    }

    /**
     * public/assets is a symlink to ../assets (docs/deploy-lighttpd.md);
     * a zip extraction often drops it, and then every stylesheet is a 404.
     */
    public function assetsLink(): Check
    {
        $label = t('install.req.assets');
        $link = $this->root . '/public/assets';
        if (is_dir($link . '/css')) {
            return Check::pass($label, is_link($link) ? 'public/assets → ../assets' : 'public/assets');
        }
        $command = "ln -sfn ../assets {$link}";

        return is_writable($this->root . '/public') && !file_exists($link)
            ? Check::warn($label, t('install.req.assets_create'), $command)
            : Check::fail($label, t('install.req.assets_missing'), $command);
    }

    /** vendor/autoload.php and Composer's own record of what it installed */
    public static function vendorReady(string $root): bool
    {
        return is_file($root . '/vendor/autoload.php') && is_file($root . '/vendor/composer/installed.json');
    }

    /** php.ini shorthand to bytes; -1 for "no limit" */
    public static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * ext-* from composer.json's require: the list Composer itself refuses
     * to install without
     *
     * @return list<string>
     */
    private function composerExtensions(): array
    {
        $json = json_decode((string) @file_get_contents($this->root . '/composer.json'), true);
        $extensions = [];
        foreach (array_keys(\is_array($json['require'] ?? null) ? $json['require'] : []) as $package) {
            if (str_starts_with((string) $package, 'ext-')) {
                $extensions[] = strtolower(substr((string) $package, 4));
            }
        }

        return $extensions;
    }

    private function phpMinor(): string
    {
        $parts = explode('.', $this->env->phpVersion);

        return ($parts[0] ?? '8') . '.' . ($parts[1] ?? '1');
    }

    private function restartCommand(): string
    {
        return $this->env->server() === 'builtin'
            ? t('install.req.restart_builtin')
            : 'sudo systemctl restart php' . $this->phpMinor() . '-fpm';
    }
}
