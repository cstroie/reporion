<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Exception\MaintenanceBusyException;
use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Service\Maintenance\MaintenanceTask;

/**
 * bin/reporion journal:replay [--min-age=60] [--dry-run] [--json]
 *
 * Finishes (or discards) writes a crash left half-done (invariant 7) —
 * Service\Maintenance\JournalReplayTask, the same task Admin → Maintenance
 * runs. --json prints the run report instead of text. Output names pages
 * by pid, never by path (invariant 8).
 */
final class JournalReplayCommand implements CommandInterface
{
    public function __construct(private readonly MaintenanceRunner $runner)
    {
    }

    public function run(array $args, Output $output): int
    {
        $minAge = 60;
        foreach ($args as $arg) {
            if (preg_match('/^--min-age=(\d+)$/', $arg, $m) === 1) {
                $minAge = (int) $m[1];
            }
        }
        $dryRun = \in_array('--dry-run', $args, true);

        try {
            $report = $this->runner->run('journal:replay', $dryRun ? MaintenanceTask::CHECK : MaintenanceTask::APPLY, 'cli', ['min_age' => $minAge])['report'];
        } catch (MaintenanceBusyException $e) {
            $output->error($e->getMessage());

            return 75;
        }
        if (\in_array('--json', $args, true)) {
            $output->line($report->toJson());

            return $report->exit();
        }

        if ($dryRun) {
            foreach ($report->items() as $item) {
                $output->line(\sprintf('%s  %-8s pid %s rev %d', (string) $item['data']['ts'], $item['outcome'], (string) $item['pid'], (int) $item['rev']));
            }
            $output->line(\sprintf('%d unfinished write(s) older than %ds would be replayed', \count($report->items()), $minAge));

            return 0;
        }

        foreach ($report->items() as $item) {
            $output->line(\sprintf('%-10s pid %s rev %d', $item['outcome'], (string) $item['pid'], (int) $item['rev']));
        }
        $counts = $report->summary();
        ksort($counts);
        $summary = [];
        foreach ($counts as $outcome => $n) {
            $summary[] = $n . ' ' . $outcome;
        }
        $output->line($summary === [] ? 'nothing to replay' : 'replayed: ' . implode(', ', $summary));

        return $report->exit();
    }
}
