<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Maintenance;

use Reporion\Exception\PageNotFoundException;
use Reporion\Storage\FlatFile;
use RuntimeException;

/**
 * pages:repair-paths: pages whose meta.json names another path than the
 * directory they are in (moved or copied on the filesystem by hand). The
 * directory is authoritative, so meta.json's `path` is rewritten to match
 * (one atomic write, no revision, a `moves` entry) and the page indexed
 * under that path (FlatFile::reconcilePath()). A page an open journal intent
 * names is left for journal:replay. Pages are named by pid (invariant 8).
 */
final class PathRepairTask implements MaintenanceTask
{
    public function __construct(private readonly FlatFile $storage)
    {
    }

    public function name(): string
    {
        return 'pages:repair-paths';
    }

    public function modes(): array
    {
        return [self::CHECK, self::APPLY];
    }

    public function options(array $raw): array
    {
        return [];
    }

    public function run(string $mode, string $actor, array $options): MaintenanceReport
    {
        $report = new MaintenanceReport($this->name(), $mode, $actor, $options, date('Y-m-d\TH:i:sP'));
        $report->count('pages', 0);
        $report->count('drifted', 0);
        foreach ($this->storage->allPaths() as $path) {
            $report->count('pages');
            if ($this->storage->storedPath($path) === $path) {
                continue;
            }
            $report->count('drifted');
            try {
                $pid = $this->storage->read($path)->pid;
            } catch (RuntimeException) {
                $report->item(null, null, 'unreadable', 'meta.json does not read: integrity:verify');
                $report->count('unreadable');

                continue;
            }
            if ($mode === self::CHECK) {
                $report->item($pid, null, 'drifted', 'meta.json names another path than the directory');

                continue;
            }
            try {
                $outcome = $this->storage->reconcilePath($path, 'system:admin');
            } catch (PageNotFoundException | RuntimeException) {
                $outcome = FlatFile::PATH_BUSY;
            }
            $report->item($pid, null, $outcome, $outcome === FlatFile::PATH_BUSY ? 'an unfinished write names it: journal:replay first' : '');
            $report->count($outcome);
        }
        if ($mode === self::APPLY && isset($report->summary()[FlatFile::PATH_BUSY])) {
            $report->note('Pages with an unfinished write were left: run journal:replay, then this again.');
            $report->fail();
        }

        return $report;
    }
}
