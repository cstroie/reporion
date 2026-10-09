<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Exception\MaintenanceBusyException;
use Reporion\Index\Sqlite;
use Reporion\Service\IndexMaintenance;
use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Service\Maintenance\MaintenanceTask;
use Reporion\Service\Maintenance\ProgressAware;
use Reporion\Storage\FlatFile;

/**
 * bin/reporion index:rebuild — full rebuild from disk (CLAUDE.md Commands
 * table). Walks data/pages/ (FlatFile::allPaths()), reconstructs each
 * page's PageSnapshot straight from disk (FlatFile::snapshotOf()), and
 * replaces the whole index in one transaction (Index\Sqlite::rebuild()).
 * This is the safety net the cache-is-disposable claim (invariant 1)
 * depends on: data/index.sqlite can always be deleted and regenerated.
 *
 * --vectors then runs index:vectors (phase 34e, Service\Maintenance\
 * VectorsTask): the embeddings of Similar reports. A rebuild keeps the
 * vectors already there; only those whose text changed, or that are
 * missing, are asked of the embedding model.
 */
final class IndexRebuildCommand implements CommandInterface
{
    public function __construct(
        private readonly FlatFile $storage,
        private readonly Sqlite $index,
        // Takes the maintenance lock when given (bin/reporion always does)
        private readonly string $dataRoot = '',
        private readonly ?MaintenanceRunner $runner = null,
    ) {
    }

    public static function help(): CommandHelp
    {
        return new CommandHelp(
            summary: 'Rebuilds the SQLite index from the page files on disk. The index is a cache, so nothing is lost.',
            usage: '[--vectors]',
            options: [
                '--vectors' => 'then embeds the reports for Similar reports (as index:vectors does)',
            ],
            details: 'Takes the maintenance lock; refused while another run is in progress (exit 75).',
        );
    }

    public function run(array $args, Output $output): int
    {
        // Same rebuild the admin screen runs (Service\IndexMaintenance)
        $output->line('rebuilding the index from disk …');
        try {
            $count = (new IndexMaintenance($this->storage, $this->index, dataRoot: $this->dataRoot, auditDir: ''))->rebuild();
        } catch (MaintenanceBusyException $e) {
            $output->error($e->getMessage());

            return 75;
        }

        $output->line(\sprintf('rebuilt index from %d page(s)', $count));
        if (!\in_array('--vectors', $args, true)) {
            return 0;
        }
        if ($this->runner === null) {
            $output->error('--vectors: no maintenance runner');

            return 1;
        }
        $task = $this->runner->tasks()['index:vectors'];
        if ($task instanceof ProgressAware) {
            $task->setProgress(static function (string $event, array $info) use ($output): void {
                if ($event === 'start') {
                    $output->write(\sprintf('[%d/%d] %s … ', (int) $info['n'], (int) $info['total'], (string) $info['label']));
                } else {
                    $output->line((string) $info['status']);
                }
            });
        }
        $output->line('embedding reports (index:vectors) …');
        try {
            $report = $this->runner->run('index:vectors', MaintenanceTask::APPLY, 'cli', [])['report'];
        } catch (MaintenanceBusyException $e) {
            $output->error($e->getMessage());

            return 75;
        }
        foreach ($report->notes() as $note) {
            $output->line($note);
        }
        $parts = [];
        foreach ($report->summary() as $key => $n) {
            $parts[] = $n . ' ' . str_replace('_', ' ', $key);
        }
        $output->line('vectors: ' . implode('; ', $parts));

        return $report->exit();
    }
}
