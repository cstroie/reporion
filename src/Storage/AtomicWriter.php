<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Storage;

use Reporion\Support\Fsync;
use RuntimeException;

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
     * Write-once, no-clobber: tmp + fsync + link() (atomic, fails if the
     * target already exists) + unlink tmp. Returns false without writing
     * anything if the target already exists — a duplicate submission, which
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
            // Lost a race with another writer — fine, the target exists either way.
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
            if (fwrite($fh, $bytes) === false) {
                throw new RuntimeException('Write failed');
            }
            fflush($fh);
        } finally {
            fclose($fh);
        }

        Fsync::file($tmp);
    }

    private static function tmpPath(string $finalPath): string
    {
        return $finalPath . '.tmp-' . bin2hex(random_bytes(4));
    }
}
