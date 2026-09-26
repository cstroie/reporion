<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Exception\MaintenanceBusyException;
use Reporion\Index\Sqlite;
use Reporion\Service\IndexMaintenance;
use Reporion\Storage\FlatFile;

/**
 * bin/reporion index:rebuild — full rebuild from disk (CLAUDE.md Commands
 * table). Walks data/pages/ (FlatFile::allPaths()), reconstructs each
 * page's PageSnapshot straight from disk (FlatFile::snapshotOf()), and
 * replaces the whole index in one transaction (Index\Sqlite::rebuild()).
 * This is the safety net the cache-is-disposable claim (invariant 1)
 * depends on: data/index.sqlite can always be deleted and regenerated.
 *
 * The `--vectors` flag CLAUDE.md's Commands table lists is out of scope —
 * embeddings need a loadable sqlite vector extension (D15/D28) that isn't
 * wired up yet; see docs/BUILD_LOG.md.
 */
final class IndexRebuildCommand implements CommandInterface
{
    public function __construct(
        private readonly FlatFile $storage,
        private readonly Sqlite $index,
        // Takes the maintenance lock when given (bin/reporion always does)
        private readonly string $dataRoot = '',
    ) {
    }

    public function run(array $args, Output $output): int
    {
        // Same rebuild the admin screen runs (Service\IndexMaintenance)
        try {
            $count = (new IndexMaintenance($this->storage, $this->index, dataRoot: $this->dataRoot, auditDir: ''))->rebuild();
        } catch (MaintenanceBusyException $e) {
            $output->error($e->getMessage());

            return 75;
        }

        $output->line(\sprintf('rebuilt index from %d page(s)', $count));

        return 0;
    }
}
