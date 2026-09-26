<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Service\Maintenance\MaintenanceTask;

/**
 * bin/reporion index:verify [--json] — the cheap stat/hash drift pass:
 * orphans (indexed, not on disk), missing (on disk, not indexed) and
 * drifted (indexed but stale) pids. Service\Maintenance\IndexVerifyTask,
 * the same task Admin → Maintenance runs. Disk stays authoritative
 * regardless (invariant 1) — this command only ever reports.
 */
final class IndexVerifyCommand implements CommandInterface
{
    public function __construct(private readonly MaintenanceRunner $runner)
    {
    }

    public function run(array $args, Output $output): int
    {
        $report = $this->runner->run('index:verify', MaintenanceTask::CHECK, 'cli', [])['report'];
        if (\in_array('--json', $args, true)) {
            $output->line($report->toJson());

            return $report->exit();
        }

        $summary = $report->summary();
        $output->line(\sprintf('orphans: %d, missing: %d, drifted: %d', $summary['orphans'], $summary['missing'], $summary['drifted']));
        $labels = [
            'orphan' => 'orphan (indexed, not on disk)',
            'missing' => 'missing (on disk, not indexed)',
            'drifted' => 'drifted (index stale)',
        ];
        foreach ($report->items() as $item) {
            $output->line('  ' . $labels[$item['outcome']] . ': ' . $item['pid']);
        }
        if ($report->exit() === 0) {
            $output->line('index is clean');
        }

        return $report->exit();
    }
}
