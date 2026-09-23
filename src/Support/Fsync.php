<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Support;

use FFI;
use RuntimeException;

/**
 * PHP has no native fsync(). This calls libc's fsync(2) directly via FFI so
 * that "temp file + fsync + rename()" (CLAUDE.md invariant 7) is a real
 * durability guarantee, not just a userspace flush.
 *
 * Plain CLI script execution (`php script.php`, `php -r`) trusts FFI::cdef()
 * regardless of the ffi.enable ini setting. Confirmed empirically that this
 * does NOT extend to any HTTP-serving SAPI: PHP-FPM needs ffi.enable=1 set
 * explicitly (docs/deploy-lighttpd.md), and so — easy to miss, since it is
 * still launched from the CLI — does PHP's built-in dev server (`php -S`),
 * which otherwise 500s every write. `ffi.enable` is PHP_INI_SYSTEM, so it
 * can only be set at process startup (`-d ffi.enable=1`, or php.ini), never
 * at runtime via ini_set().
 *
 * TODO: if ext-ffi is missing or disabled at runtime, fall back instead of
 * hard-failing every write — e.g. shell out to `sync` (whole-filesystem, but
 * still a real durability barrier) rather than degrading silently to
 * fflush()-only. Not implemented yet: today this throws at first use if FFI
 * is unavailable.
 */
final class Fsync
{
    private static ?FFI $ffi = null;

    private const O_WRONLY = 1;
    private const O_RDONLY = 0;

    public static function file(string $path): void
    {
        self::path($path, false);
    }

    public static function directory(string $path): void
    {
        self::path($path, true);
    }

    private static function path(string $path, bool $directory): void
    {
        $ffi = self::ffi();
        $fd = $ffi->open($path, $directory ? self::O_RDONLY : self::O_WRONLY);
        if ($fd < 0) {
            if ($directory) {
                // Some platforms refuse to open a directory for reading; best effort.
                return;
            }
            throw new RuntimeException('fsync: unable to open target for durability sync');
        }
        try {
            if ($ffi->fsync($fd) !== 0) {
                throw new RuntimeException('fsync: kernel sync call failed');
            }
        } finally {
            $ffi->close($fd);
        }
    }

    private static function ffi(): FFI
    {
        return self::$ffi ??= FFI::cdef(
            'int open(const char *pathname, int flags); int fsync(int fd); int close(int fd);',
            'libc.so.6'
        );
    }
}
