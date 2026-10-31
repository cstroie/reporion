<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Install;

use FFI;
use PDO;
use Throwable;

/**
 * What the web installer (D43) knows about the PHP running it: taken in
 * the web SAPI itself, which is the point: `bin/reporion doctor` runs in the
 * CLI and cannot see PHP-FPM's `ffi.enable`, its php.ini or its limits.
 * A value object so Requirements can be tested with any PHP it likes.
 */
final class Environment
{
    /**
     * @param list<string>          $extensions loaded extensions, lower case
     * @param array<string, string> $ini        the php.ini values Requirements reads
     */
    public function __construct(
        public readonly int $phpVersionId,
        public readonly string $phpVersion,
        public readonly array $extensions,
        public readonly bool $argon2,
        public readonly ?string $fts5Error,
        public readonly ?string $ffiError,
        public readonly array $ini,
        public readonly string $webUser,
        public readonly string $sapi,
        public readonly string $serverSoftware,
        // The optional programs found (Requirements::software()): name => found; `tessdata:{lang}` for tesseract's language data
        public readonly array $programs = [],
    ) {
    }

    /** The programs the optional features use, looked up as files on PATH: nothing is run */
    public const PROGRAMS = ['pdftotext', 'pdfinfo', 'pdftohtml', 'pdftoppm', 'pandoc', 'soffice', 'antiword', 'catdoc', 'xls2csv', 'catppt', 'tesseract', 'ffmpeg', 'rsync', 'ssh', 'groff', 'findscu'];

    /** Where Debian and others keep tesseract's language data */
    private const TESSDATA = ['/usr/share/tesseract-ocr/*/tessdata', '/usr/share/tessdata', '/usr/local/share/tessdata'];

    public function found(string $program): bool
    {
        return ($this->programs[$program] ?? false) === true;
    }

    /** @return array<string, bool> */
    private static function programs(): array
    {
        $dirs = array_unique(array_filter(array_merge(
            explode(':', (string) getenv('PATH')),
            // PHP-FPM's PATH is often short: the usual places too
            ['/usr/local/bin', '/usr/bin', '/bin', '/usr/local/sbin', '/usr/sbin', '/usr/lib/libreoffice/program', '/opt/libreoffice/program'],
        )));
        $found = [];
        foreach (self::PROGRAMS as $program) {
            $found[$program] = false;
            foreach ($dirs as $dir) {
                // open_basedir may hide a directory: a warning silenced, the program then not found
                if (@is_file($dir . '/' . $program) && @is_executable($dir . '/' . $program)) {
                    $found[$program] = true;
                    break;
                }
            }
        }
        foreach (['ron', 'eng'] as $lang) {
            $found['tessdata:' . $lang] = false;
            foreach (self::TESSDATA as $pattern) {
                if ((@glob($pattern . '/' . $lang . '.traineddata') ?: []) !== []) {
                    $found['tessdata:' . $lang] = true;
                    break;
                }
            }
        }

        return $found;
    }

    public static function current(): self
    {
        $ini = [];
        foreach (['memory_limit', 'max_execution_time', 'post_max_size', 'upload_max_filesize', 'open_basedir', 'disable_functions', 'allow_url_fopen', 'ffi.enable'] as $key) {
            $ini[$key] = (string) \ini_get($key);
        }

        return new self(
            PHP_VERSION_ID,
            PHP_VERSION,
            array_map('strtolower', get_loaded_extensions()),
            \defined('PASSWORD_ARGON2ID'),
            self::fts5Error(),
            self::ffiError(),
            $ini,
            self::webUser(),
            PHP_SAPI,
            (string) ($_SERVER['SERVER_SOFTWARE'] ?? ''),
            self::programs(),
        );
    }

    public function has(string $extension): bool
    {
        return \in_array(strtolower($extension), $this->extensions, true);
    }

    /** A function php.ini's disable_functions takes away */
    public function disabled(string $function): bool
    {
        $list = array_map('trim', explode(',', strtolower($this->ini['disable_functions'] ?? '')));

        return \in_array(strtolower($function), $list, true);
    }

    /** Which web server this is, as far as SERVER_SOFTWARE tells: lighttpd, apache, nginx, builtin or other */
    public function server(): string
    {
        $software = strtolower($this->serverSoftware);
        foreach (['lighttpd', 'apache', 'nginx'] as $name) {
            if (str_contains($software, $name)) {
                return $name;
            }
        }

        return $this->sapi === 'cli-server' ? 'builtin' : 'other';
    }

    private static function fts5Error(): ?string
    {
        if (!class_exists(PDO::class) || !\in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            return 'pdo_sqlite is not loaded';
        }
        try {
            (new PDO('sqlite::memory:'))->exec('CREATE VIRTUAL TABLE t USING fts5(x)');

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * Support\Fsync's own call. Unlike the CLI, a web SAPI refuses it
     * unless ffi.enable=1 (docs/deploy-lighttpd.md): exactly what this
     * runs in.
     */
    private static function ffiError(): ?string
    {
        if (!class_exists(FFI::class)) {
            return 'the ffi extension is not loaded';
        }
        try {
            FFI::cdef('int fsync(int fd);', 'libc.so.6');

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    private static function webUser(): string
    {
        if (\function_exists('posix_geteuid') && \function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());
            if (\is_array($info) && $info['name'] !== '') {
                return (string) $info['name'];
            }
        }
        // get_current_user() would name the script's owner, not this process

        return 'www-data';
    }
}
