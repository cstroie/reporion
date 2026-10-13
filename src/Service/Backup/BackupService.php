<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Backup;

use FilesystemIterator;
use Reporion\Exception\BackupException;

/**
 * Backup and restore as an archive (D44): for moving an instance (staging
 * to production) and as a copy to keep; rsync with --link-dest stays the
 * routine backup (D22). The archive is a gzip tar of data/ (without the
 * index cache, tmp/, import/ and earlier backups) and, when present, conf/local.php
 * with a manifest of every file's sha256.
 * Disk stays authoritative (invariant 1): nothing here reads the index.
 *
 * Restore cannot rename data/ itself: the web user does not own its
 * parent, so everything happens inside data/: the archive is extracted and
 * checked in data/tmp/restore-<ts>/, the current content moves to
 * data/.pre-restore-<ts>/ (never deleted), the restored content moves up.
 */
final class BackupService
{
    public const DIR = 'backups';
    public const FILE_PATTERN = '/^reporion-backup-\d{8}-\d{6}\.tar\.gz$/';

    /** conf files an archive carries, relative to conf/ */
    private const CONF_FILES = ['local.php'];

    public function __construct(
        private readonly string $dataRoot,
        private readonly string $confDir,
        private readonly string $auditDir = '',
    ) {
    }

    public function directory(): string
    {
        return $this->dataRoot . '/' . self::DIR;
    }

    /**
     * What a backup would hold, without writing it
     *
     * @return array{files: int, bytes: int, skipped: int}
     */
    public function plan(): array
    {
        $files = 0;
        $bytes = 0;
        foreach ($this->entries($skipped) as [$kind, , $source]) {
            if ($kind === 'file') {
                ++$files;
                $bytes += (int) @filesize($source);
            }
        }

        return ['files' => $files, 'bytes' => $bytes, 'skipped' => $skipped];
    }

    /**
     * @return array{file: string, files: int, bytes: int, size: int, skipped: int}
     *
     * @throws BackupException
     */
    public function create(): array
    {
        $dir = $this->directory();
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new BackupException('Cannot create the backups directory');
        }
        $plan = $this->plan();
        $free = @disk_free_space($dir);
        if ($free !== false && $free < $plan['bytes'] / 2) {
            throw new BackupException('Not enough free disk space for the backup');
        }
        $name = 'reporion-backup-' . date('Ymd-His') . '.tar.gz';
        $part = $dir . '/' . $name . '.part';
        $writer = new ArchiveWriter($part);
        try {
            foreach ($this->entries($skipped) as [$kind, $entry, $source]) {
                $kind === 'dir' ? $writer->addDirectory($entry) : $writer->addFile($entry, $source);
            }
            $done = $writer->finish([
                'created' => date('Y-m-d\TH:i:sP'),
                'paths' => ['data' => $this->dataRoot, 'audit' => $this->auditDir],
                'php' => PHP_VERSION,
            ]);
            @chmod($part, 0660);
            if (!rename($part, $dir . '/' . $name)) {
                throw new BackupException('Cannot finish the backup file');
            }
        } catch (\Throwable $e) {
            $writer->abort();
            throw $e instanceof BackupException ? $e : new BackupException('The backup failed: ' . $e->getMessage(), 0, $e);
        }

        return ['file' => $name, 'files' => $done['files'], 'bytes' => $done['bytes'], 'size' => (int) filesize($dir . '/' . $name), 'skipped' => $skipped];
    }

    /**
     * The backups kept in data/backups/, newest first
     *
     * @return list<array{file: string, size: int, time: int}>
     */
    public function backups(): array
    {
        $found = [];
        foreach (glob($this->directory() . '/reporion-backup-*.tar.gz') ?: [] as $path) {
            if (preg_match(self::FILE_PATTERN, basename($path)) === 1) {
                $found[] = ['file' => basename($path), 'size' => (int) filesize($path), 'time' => (int) filemtime($path)];
            }
        }
        usort($found, static fn (array $a, array $b): int => strcmp($b['file'], $a['file']));

        return $found;
    }

    /**
     * Reads the whole archive and checks every file against the manifest
     *
     * @return array{manifest: array<string, mixed>, files: int, bytes: int}
     */
    public function verify(string $archive): array
    {
        return (new ArchiveReader($archive))->extract(null);
    }

    /**
     * Replaces the instance's data with the archive's. Run it as the data
     * directory's owner, with nothing else writing.
     *
     * @param array{replace?: bool, with_conf?: bool, after_stage?: callable(): void} $options after_stage is a test seam, called between staging and the swap
     *
     * @return array{files: int, bytes: int, moved_to: ?string, conf: string, created: string}
     *
     * @throws BackupException
     */
    public function restore(string $archive, array $options = []): array
    {
        $replace = (bool) ($options['replace'] ?? false);
        if (!is_file($archive)) {
            throw new BackupException('The backup file does not exist');
        }
        if (!$replace && $this->hasPages()) {
            throw new BackupException('This instance already holds pages. Restoring replaces everything in data/; add --replace (the current content is kept in data/.pre-restore-*)');
        }
        $stamp = date('Ymd-His');
        $stage = $this->dataRoot . '/tmp/restore-' . $stamp;
        if (is_dir($stage)) {
            throw new BackupException('A restore is already staged for this second; run again');
        }
        mkdir($stage, 0770, true);
        try {
            $read = (new ArchiveReader($archive))->extract($stage);
            $manifest = $read['manifest'];
            if (!is_dir($stage . '/data')) {
                mkdir($stage . '/data', 0770);
            }
            if (isset($options['after_stage'])) {
                ($options['after_stage'])();
            }
            $movedTo = $this->swap($stage . '/data', $stamp);
            $conf = $this->restoreConf($stage . '/conf', (string) ($manifest['paths']['data'] ?? ''), (bool) ($options['with_conf'] ?? false), $stamp);
        } catch (\Throwable $e) {
            $this->removeTree($stage);
            throw $e instanceof BackupException ? $e : new BackupException('The restore failed: ' . $e->getMessage(), 0, $e);
        }
        $this->removeTree($stage);

        return ['files' => $read['files'], 'bytes' => $read['bytes'], 'moved_to' => $movedTo, 'conf' => $conf, 'created' => (string) ($manifest['created'] ?? '')];
    }

    /**
     * The archive's entries in order: ['dir'|'file', name in the archive, source path]
     *
     * @param-out int $skipped
     *
     * @return \Generator<int, array{0: string, 1: string, 2: string}>
     */
    private function entries(?int &$skipped = 0): \Generator
    {
        $skipped = 0;
        yield ['dir', 'data', $this->dataRoot];
        yield from $this->walkData($this->dataRoot, 'data', true, $skipped);
        foreach (self::CONF_FILES as $file) {
            $path = $this->confDir . '/' . $file;
            if (is_file($path) && !is_link($path)) {
                yield ['file', 'conf/' . $file, $path];
            }
        }
    }

    /**
     * @return \Generator<int, array{0: string, 1: string, 2: string}>
     */
    private function walkData(string $dir, string $name, bool $top, int &$skipped): \Generator
    {
        $items = new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO);
        $list = iterator_to_array($items, false);
        usort($list, static fn (\SplFileInfo $a, \SplFileInfo $b): int => strcmp($a->getFilename(), $b->getFilename()));
        foreach ($list as $item) {
            $base = $item->getFilename();
            if (self::excluded($base, $top)) {
                continue;
            }
            $entry = $name . '/' . $base;
            if ($item->isLink() || (!$item->isFile() && !$item->isDir())) {
                ++$skipped;
                continue;
            }
            if ($item->isDir()) {
                yield ['dir', $entry, $item->getPathname()];
                yield from $this->walkData($item->getPathname(), $entry, false, $skipped);
            } else {
                yield ['file', $entry, $item->getPathname()];
            }
        }
    }

    /** The index is a cache, tmp/ holds uploads in flight, import/ is source material, earlier backups would nest, locks are per-machine */
    private static function excluded(string $base, bool $top): bool
    {
        if (str_ends_with($base, '.lock') || str_ends_with($base, '.part')) {
            return true;
        }
        if (!$top) {
            return false;
        }

        return $base === 'tmp' || $base === 'import' || $base === self::DIR || str_starts_with($base, '.pre-restore-') || str_starts_with($base, 'index.sqlite');
    }

    private function hasPages(): bool
    {
        $pages = $this->dataRoot . '/pages';

        return is_dir($pages) && (new FilesystemIterator($pages, FilesystemIterator::SKIP_DOTS))->valid();
    }

    /**
     * Current content of data/ to .pre-restore-<ts>/, the staged content up. Each move is
     * logged first in the .pre-restore directory so an interrupted swap can be undone by hand.
     */
    private function swap(string $staged, string $stamp): ?string
    {
        $old = $this->dataRoot . '/.pre-restore-' . $stamp;
        $keep = [];
        foreach (new FilesystemIterator($this->dataRoot, FilesystemIterator::SKIP_DOTS) as $item) {
            $base = $item->getFilename();
            if ($base === 'tmp' || $base === 'import' || str_starts_with($base, '.pre-restore-') || $base === self::DIR) {
                continue;
            }
            $keep[] = $base;
        }
        $movedTo = null;
        $log = null;
        if ($keep !== []) {
            mkdir($old, 0770);
            $movedTo = basename($old);
            $log = fopen($old . '/MOVED.log', 'ab');
        }
        foreach ($keep as $base) {
            if ($log !== false && $log !== null) {
                fwrite($log, 'out ' . $base . "\n");
                fflush($log);
            }
            if (!rename($this->dataRoot . '/' . $base, $old . '/' . $base)) {
                throw new BackupException('Cannot move the current data aside; nothing was replaced beyond .pre-restore (see MOVED.log there)');
            }
        }
        foreach (new FilesystemIterator($staged, FilesystemIterator::SKIP_DOTS) as $item) {
            $base = $item->getFilename();
            if ($log !== false && $log !== null) {
                fwrite($log, 'in ' . $base . "\n");
                fflush($log);
            }
            if (!rename($item->getPathname(), $this->dataRoot . '/' . $base)) {
                throw new BackupException('Cannot move the restored data in; the previous data is in ' . ($movedTo ?? 'data/.pre-restore-*') . ' (see MOVED.log there)');
            }
        }
        if (\is_resource($log)) {
            fclose($log);
        }

        return $movedTo;
    }

    /**
     * conf/local.php belongs to the machine it was made for. It is installed over the current one
     * only when asked for and when it names the same data directory; otherwise it is left beside
     * as local.php.from-backup for a person to merge.
     */
    private function restoreConf(string $staged, string $archivedData, bool $withConf, string $stamp): string
    {
        $local = $staged . '/local.php';
        if (!is_file($local)) {
            return 'none';
        }
        if ($withConf && $archivedData === $this->dataRoot) {
            $this->install($local, $this->confDir . '/local.php', $stamp);

            return 'installed';
        }
        $this->install($local, $this->confDir . '/local.php.from-backup', $stamp);

        return $withConf ? 'paths-differ' : 'kept';
    }

    private function install(string $from, string $to, string $stamp): void
    {
        if (is_file($to)) {
            if (!@copy($to, $to . '.pre-restore-' . $stamp)) {
                throw new BackupException('Cannot keep the current ' . basename($to) . ' aside; the conf directory is not writable');
            }
            @chmod($to . '.pre-restore-' . $stamp, 0640);
        }
        if (!@copy($from, $to . '.tmp') || !rename($to . '.tmp', $to)) {
            @unlink($to . '.tmp');
            throw new BackupException('Cannot write ' . basename($to) . '; the conf directory is not writable');
        }
        @chmod($to, 0640);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }
        foreach (new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS) as $item) {
            $item->isDir() && !$item->isLink() ? $this->removeTree($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
