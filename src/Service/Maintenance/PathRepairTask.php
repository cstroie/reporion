<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Maintenance;

use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Exception\PageNotFoundException;
use Reporion\Storage\FileTree;
use RuntimeException;

/**
 * pages:repair-paths: page files under pages/ that are no page where they
 * are (D54 §5, FileTree::strayPaths()). Where the file is is authoritative:
 * a file moved by hand takes its page along (the row's path, a `move` row,
 * a redirect from the old path, the attic zip moved); a file no page is
 * known for is adopted as a new page, its bytes revision 1; a copy of
 * another page's file is left for the owner (FileTree::reconcilePath()).
 * A file an open journal intent names is left for journal:replay. Files
 * are named by path hash, pages by pid (invariant 8).
 */
final class PathRepairTask implements MaintenanceTask
{
    public function __construct(private readonly FileTree $storage)
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
        $report->count('files', 0);
        foreach ($this->storage->strayPaths() as $path) {
            $report->count('files');
            $kind = $this->storage->strayKind($path);
            $data = ['path_hash' => AuditLog::pathHash($path)];
            if ($mode === self::CHECK || $kind === 'copy') {
                $report->item(null, null, $kind, match ($kind) {
                    'moved' => 'a page file moved by hand: its page follows it',
                    'copy' => 'a copy of another page\'s file: remove it by hand',
                    default => 'a page file no page is known for: adopted as a new page',
                }, $data);
                $report->count($kind);

                continue;
            }
            try {
                $outcome = $this->storage->reconcilePath($path, 'system:admin');
                $pid = $outcome === FileTree::PATH_REPAIRED ? $this->storage->read($path)->pid : null;
            } catch (PageNotFoundException | InvalidArgumentException | RuntimeException) {
                [$outcome, $pid] = [FileTree::PATH_BUSY, null];
            }
            $report->item($pid, null, $outcome, $outcome === FileTree::PATH_BUSY ? 'an unfinished write names it, or it does not read: journal:replay, integrity:verify' : '', $data);
            $report->count($outcome);
        }
        if ($mode === self::APPLY && isset($report->summary()[FileTree::PATH_BUSY])) {
            $report->note('Files with an unfinished write, or that do not read, were left: run journal:replay, then this again.');
            $report->fail();
        }

        return $report;
    }
}
