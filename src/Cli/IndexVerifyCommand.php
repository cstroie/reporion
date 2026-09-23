<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Index\Sqlite;
use Reporion\Storage\FlatFile;

/**
 * bin/reporion index:verify — the cheap stat/hash drift pass CLAUDE.md's
 * Commands table promises. Walks data/pages/ (FlatFile::allPaths()),
 * builds the disk-facts Index\Sqlite::verify() needs, and reports orphans
 * (indexed, not on disk), missing (on disk, not indexed) and drifted
 * (indexed but stale) pids. Disk stays authoritative regardless of what
 * this finds (CLAUDE.md invariant 1) — this command only ever reports.
 */
final class IndexVerifyCommand implements CommandInterface
{
    public function __construct(
        private readonly FlatFile $storage,
        private readonly Sqlite $index,
    ) {
    }

    public function run(array $args, Output $output): int
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

        $report = $this->index->verify($diskFacts);

        $output->line(\sprintf(
            'orphans: %d, missing: %d, drifted: %d',
            \count($report['orphans']),
            \count($report['missing']),
            \count($report['drifted'])
        ));

        foreach ($report['orphans'] as $pid) {
            $output->line("  orphan (indexed, not on disk): {$pid}");
        }
        foreach ($report['missing'] as $pid) {
            $output->line("  missing (on disk, not indexed): {$pid}");
        }
        foreach ($report['drifted'] as $pid) {
            $output->line("  drifted (index stale): {$pid}");
        }

        $clean = $report['orphans'] === [] && $report['missing'] === [] && $report['drifted'] === [];
        if ($clean) {
            $output->line('index is clean');
        }

        return $clean ? 0 : 1;
    }
}
