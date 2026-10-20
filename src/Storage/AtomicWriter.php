<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Storage;

use Closure;
use Reporion\Support\Fsync;
use RuntimeException;
use Throwable;

/**
 * The two atomic write shapes used throughout the page write path
 * (CLAUDE.md invariant 7 and docs/architecture-storage-index.md §5): temp
 * file, fsync, then either an overwriting rename() or a no-clobber link().
 */
final class AtomicWriter
{
    /**
     * Overwrite-safe atomic write: tmp + fsync + rename, then fsync the
     * containing directory (a rename is not durable until its directory
     * entry is synced too).
     */
    public static function put(string $finalPath, string $bytes): void
    {
        $tmp = self::tmpPath($finalPath);
        self::writeTmp($tmp, $bytes);

        if (!rename($tmp, $finalPath)) {
            @unlink($tmp);
            throw new RuntimeException('Atomic rename failed');
        }

        Fsync::directory(\dirname($finalPath));
    }

    /**
     * put() with a check between the fsync and the rename (D54's attic,
     * docs/architecture-v2.md §4): $check reads the durable temp file back
     * and throws if it is not what was meant; the temp file is then removed
     * and $finalPath is left exactly as it was.
     *
     * @param Closure(string): void $check given the temp file's path
     */
    public static function putChecked(string $finalPath, string $bytes, Closure $check): void
    {
        $tmp = self::tmpPath($finalPath);
        self::writeTmp($tmp, $bytes);

        try {
            $check($tmp);
        } catch (Throwable $e) {
            @unlink($tmp);
            throw $e;
        }

        if (!rename($tmp, $finalPath)) {
            @unlink($tmp);
            throw new RuntimeException('Atomic rename failed');
        }

        Fsync::directory(\dirname($finalPath));
    }

    /**
     * Write-once, no-clobber: tmp + fsync + link() (atomic, fails if the
     * target already exists) + unlink tmp. Returns false without writing
     * anything if the target already exists: a duplicate submission, which
     * the caller treats as an idempotent no-op (CLAUDE.md invariant 3:
     * history files are written once, never edited).
     */
    public static function putOnce(string $finalPath, string $bytes): bool
    {
        if (is_file($finalPath)) {
            return false;
        }

        $tmp = self::tmpPath($finalPath);
        self::writeTmp($tmp, $bytes);

        $linked = link($tmp, $finalPath);
        @unlink($tmp);

        if (!$linked) {
            // Lost a race with another writer: fine, the target exists either way.
            if (!is_file($finalPath)) {
                throw new RuntimeException('Atomic link failed');
            }

            return false;
        }

        Fsync::directory(\dirname($finalPath));

        return true;
    }

    private static function writeTmp(string $tmp, string $bytes): void
    {
        $fh = fopen($tmp, 'xb');
        if ($fh === false) {
            throw new RuntimeException('Cannot create temp file');
        }

        try {
            // A short write (disk full) is not false: anything less than the
            // whole buffer would rename a truncated file into place.
            if (fwrite($fh, $bytes) !== \strlen($bytes) || !fflush($fh)) {
                throw new RuntimeException('Write failed');
            }
        } catch (RuntimeException $e) {
            fclose($fh);
            @unlink($tmp);
            throw $e;
        }
        fclose($fh);

        try {
            Fsync::file($tmp);
        } catch (RuntimeException $e) {
            @unlink($tmp);
            throw $e;
        }
    }

    private static function tmpPath(string $finalPath): string
    {
        return $finalPath . '.tmp-' . bin2hex(random_bytes(4));
    }
}
