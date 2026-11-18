<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use FilesystemIterator;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Reporion\Service\Backup\RsyncBackup;
use Throwable;

/**
 * The start page's Storage panel (owners only): what data/ holds, by part,
 * and how much room is left. Labels and numbers only: never a path
 * (invariant 8). Walking attic/ and media/ is slow on a large archive, so
 * the result is kept for an hour in data/maintenance/storage-stats.json, a
 * status note like backup-rsync.json (disposable, not data: invariant 1).
 */
final class StorageStats
{
    public const CACHE = 'maintenance/storage-stats.json';

    public const TTL = 3600;

    /** Part => what it is under data/: a directory (walked) or a file */
    private const PARTS = [
        'pages' => 'pages',
        'attic' => 'attic',
        'media' => 'media',
        'sources' => 'sources',
        'state' => 'reporion.sqlite',
        'index' => 'index.sqlite',
        'vectors' => 'vectors.sqlite',
        'trash' => 'trash',
        'backups' => 'backups',
    ];

    public function __construct(private readonly string $dataRoot)
    {
    }

    /**
     * @return array{
     *   at: int,
     *   parts: array<string, array{bytes: int, files: int}>,
     *   total: int,
     *   free: ?int,
     *   disk: ?int,
     *   pages: int,
     *   revisions: int,
     *   signatures: int,
     *   backup: int
     * }
     */
    public function get(int $now, bool $refresh = false): array
    {
        if (!$refresh) {
            $raw = @file_get_contents($this->dataRoot . '/' . self::CACHE);
            $cached = $raw !== false ? json_decode($raw, true) : null;
            if (\is_array($cached) && isset($cached['at'], $cached['parts']) && $now - (int) $cached['at'] < self::TTL) {
                return $this->live($cached);
            }
        }
        $stats = $this->live($this->measure($now));
        $this->keep($stats);

        return $stats;
    }

    /**
     * What is cheap is read on every call: free space and the last backup
     *
     * @param array<string, mixed> $stats
     *
     * @return array<string, mixed>
     */
    private function live(array $stats): array
    {
        $free = @disk_free_space($this->dataRoot);
        $disk = @disk_total_space($this->dataRoot);
        $stats['free'] = $free !== false ? (int) $free : null;
        $stats['disk'] = $disk !== false ? (int) $disk : null;
        $stats['backup'] = RsyncBackup::state($this->dataRoot)['last_success'];
        $archive = 0;
        foreach (glob($this->dataRoot . '/backups/*') ?: [] as $file) {
            $archive = max($archive, (int) @filemtime($file));
        }
        $stats['backup'] = max((int) $stats['backup'], $archive);

        return $stats;
    }

    /** @return array<string, mixed> */
    private function measure(int $now): array
    {
        $parts = [];
        foreach (self::PARTS as $part => $rel) {
            $parts[$part] = $this->size($this->dataRoot . '/' . $rel);
        }
        // The databases' -wal files belong to them
        foreach (['state' => 'reporion.sqlite', 'index' => 'index.sqlite', 'vectors' => 'vectors.sqlite'] as $part => $file) {
            $wal = $this->dataRoot . '/' . $file . '-wal';
            if (is_file($wal)) {
                $parts[$part]['bytes'] += (int) filesize($wal);
            }
        }
        [$pages, $revisions, $signatures] = $this->counts();

        return [
            'at' => $now,
            'parts' => $parts,
            'total' => array_sum(array_column($parts, 'bytes')),
            'pages' => $pages,
            'revisions' => $revisions,
            'signatures' => $signatures,
        ];
    }

    /** @return array{bytes: int, files: int} */
    private function size(string $path): array
    {
        if (is_link($path)) {
            return ['bytes' => 0, 'files' => 0];
        }
        if (is_file($path)) {
            return ['bytes' => (int) filesize($path), 'files' => 1];
        }
        $bytes = 0;
        $files = 0;
        if (is_dir($path)) {
            try {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
                foreach ($it as $file) {
                    if ($file->isFile() && !$file->isLink()) {
                        $bytes += $file->getSize();
                        ++$files;
                    }
                }
            } catch (Throwable) {
                // An unreadable subdirectory: count what was reached
            }
        }

        return ['bytes' => $bytes, 'files' => $files];
    }

    /** @return array{int, int, int} pages, revisions and signatures, read-only from reporion.sqlite */
    private function counts(): array
    {
        $file = $this->dataRoot . '/reporion.sqlite';
        if (!is_file($file)) {
            return [0, 0, 0];
        }
        try {
            $pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('PRAGMA query_only = 1');
            $one = static fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();

            return [$one('SELECT COUNT(*) FROM page'), $one('SELECT COUNT(*) FROM revision'), $one('SELECT COUNT(*) FROM signature')];
        } catch (Throwable) {
            return [0, 0, 0];
        }
    }

    /** @param array<string, mixed> $stats */
    private function keep(array $stats): void
    {
        $dir = $this->dataRoot . '/maintenance';
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            return;
        }
        unset($stats['free'], $stats['disk'], $stats['backup']);
        $tmp = $dir . '/.storage-stats.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode($stats, JSON_THROW_ON_ERROR)) === false) {
            return;
        }
        if (!@rename($tmp, $this->dataRoot . '/' . self::CACHE)) {
            @unlink($tmp);
        }
    }
}
