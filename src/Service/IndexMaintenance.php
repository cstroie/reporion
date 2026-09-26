<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service;

use FilesystemIterator;
use PDO;
use Reporion\Index\Sqlite;
use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Storage\FlatFile;
use Reporion\Storage\Journal;

/**
 * The index's health and its rebuild — one implementation behind both
 * `bin/reporion index:verify|index:rebuild` and Admin → Index & storage.
 * Disk is authoritative (invariant 1): verify compares disk facts with the
 * index, rebuild recreates the index from disk alone.
 *
 * The status never names a page path — trash and journal are counted, not
 * listed (their names carry the patient-named segment, invariant 8).
 */
final class IndexMaintenance
{
    public function __construct(
        private readonly FlatFile $storage,
        private readonly Sqlite $index,
        private readonly string $dataRoot,
        private readonly string $auditDir,
    ) {
    }

    /**
     * @return array{orphans: list<string>, missing: list<string>, drifted: list<string>}
     */
    public function verify(): array
    {
        $diskFacts = (function () {
            foreach ($this->storage->allPaths() as $path) {
                $snapshot = $this->storage->snapshotOf($path);
                yield [
                    'pid' => $snapshot->pid,
                    'bytes' => $snapshot->bytes,
                    'mtime' => $snapshot->mtime,
                    'bodySha' => $snapshot->bodySha,
                ];
            }
        })();

        return $this->index->verify($diskFacts);
    }

    /**
     * Recreate the index from disk; returns the number of pages indexed.
     * Under the maintenance lock (MaintenanceRunner::lock()) when this
     * knows the data directory, so it never races a repair or a purge.
     *
     * @throws \Reporion\Exception\MaintenanceBusyException
     */
    public function rebuild(): int
    {
        $lock = $this->dataRoot !== '' ? MaintenanceRunner::lock($this->dataRoot) : null;
        try {
            return $this->rebuildUnlocked();
        } finally {
            if ($lock !== null) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function rebuildUnlocked(): int
    {
        $count = 0;
        $snapshots = (function () use (&$count) {
            foreach ($this->storage->allPaths() as $path) {
                ++$count;
                yield $this->storage->snapshotOf($path);
            }
        })();

        $this->index->rebuild($snapshots);

        return $count;
    }

    /**
     * @return array{
     *     byStatus: array<string, int>, byVisibility: array<string, int>, total: int,
     *     openIntents: int, trashEntries: int, audit: list<array{file: string, bytes: int}>
     * }
     */
    public function status(): array
    {
        $byStatus = [];
        $byVisibility = [];
        foreach ($this->index->countsByStatusAndVisibility() as $row) {
            $byStatus[$row['status']] = ($byStatus[$row['status']] ?? 0) + $row['n'];
            $byVisibility[$row['visibility']] = ($byVisibility[$row['visibility']] ?? 0) + $row['n'];
        }

        return [
            'byStatus' => $byStatus,
            'byVisibility' => $byVisibility,
            'total' => array_sum($byStatus),
            'openIntents' => $this->openIntents(),
            'trashEntries' => $this->countEntries($this->dataRoot . '/trash'),
            'audit' => $this->auditFiles(),
        ];
    }

    /** Journal write-intents with no matching "done" — what replay would recover */
    private function openIntents(): int
    {
        return \count((new Journal($this->dataRoot . '/journal'))->openIntents());
    }

    private function countEntries(string $dir): int
    {
        return is_dir($dir) ? iterator_count(new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS)) : 0;
    }

    /** @return list<array{file: string, bytes: int}> */
    private function auditFiles(): array
    {
        $files = glob($this->auditDir . '/*.ndjson') ?: [];
        rsort($files);

        return array_map(static fn (string $file): array => ['file' => basename($file), 'bytes' => (int) filesize($file)], $files);
    }
}
