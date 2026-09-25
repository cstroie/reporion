<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Audit;

use DateTimeImmutable;
use Reporion\Http\Request;
use Reporion\Support\Fsync;
use RuntimeException;
use Throwable;

/**
 * The audit trail (docs/FORMATS.md §6): one append-only NDJSON file per
 * month in data/audit/, outside SQLite — an audit log in a rebuildable
 * cache would not be one (invariant 1). Same append discipline as
 * Storage\Journal: flock, write, fflush, fsync file and directory.
 *
 * A page path never appears in a line — only `path_hash` (invariant 8, D1).
 *
 * Recording is best-effort by design: it runs after the write it describes
 * has already succeeded, and turning that success into an error response
 * would make the caller retry a write that happened (the duplicate-page
 * failure this deployment has seen). A failure goes to the PHP error log
 * instead, and `bin/reporion doctor` checks the directory is writable.
 */
final class AuditLog
{
    public function __construct(private readonly string $dir)
    {
    }

    /**
     * @param array<string, scalar|null> $extra action-specific fields (e.g. `format` for an export)
     */
    public function record(
        string $action,
        string $actor,
        ?Request $request = null,
        ?string $pid = null,
        ?string $path = null,
        ?int $rev = null,
        string $outcome = 'ok',
        array $extra = [],
    ): void {
        $now = new DateTimeImmutable('now');
        $line = [
            'ts' => $now->format('Y-m-d\TH:i:sP'),
            'actor' => $actor,
            'action' => $action,
            'pid' => $pid,
            'path_hash' => $path !== null ? self::pathHash($path) : null,
            'rev' => $rev,
            'ip' => $request?->remoteAddr ?: null,
            'ua' => $request !== null && $request->userAgent !== '' ? mb_substr($request->userAgent, 0, 160) : null,
            'outcome' => $outcome,
        ] + $extra;

        try {
            $this->append($now, array_filter($line, static fn (mixed $value): bool => $value !== null));
        } catch (Throwable $e) {
            error_log('reporion audit: could not record ' . $action . ': ' . $e->getMessage());
        }
    }

    /** `sha256:<hex>` of a colon path — how a page is named in an audit line */
    public static function pathHash(string $path): string
    {
        return 'sha256:' . hash('sha256', $path);
    }

    public function directory(): string
    {
        return $this->dir;
    }

    /**
     * @param array<string, mixed> $line
     */
    private function append(DateTimeImmutable $now, array $line): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new RuntimeException('Cannot create audit directory');
        }

        $file = $this->dir . '/' . $now->format('Y-m') . '.ndjson';
        $fh = @fopen($file, 'ab');
        if ($fh === false) {
            throw new RuntimeException('Cannot open audit file');
        }

        try {
            if (!flock($fh, LOCK_EX)) {
                throw new RuntimeException('Cannot lock audit file');
            }
            fwrite($fh, json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
            fflush($fh);
            flock($fh, LOCK_UN);
        } finally {
            fclose($fh);
        }

        Fsync::file($file);
        Fsync::directory($this->dir);
    }
}
