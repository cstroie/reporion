<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Exception\MaintenanceBusyException;
use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Service\Maintenance\MaintenanceTask;

/**
 * bin/reporion pages:repair-paths [--dry-run] [--json]
 *
 * Pages whose meta.json names another path than the directory they are in
 * (moved or copied by hand): meta.json is made to say where the page is, and
 * the page is indexed there. Service\Maintenance\PathRepairTask, the same task
 * Admin → Maintenance runs. Output names pages by pid, never by path
 * (invariant 8).
 */
final class PagesRepairPathsCommand implements CommandInterface
{
    public function __construct(private readonly MaintenanceRunner $runner)
    {
    }

    public static function help(): CommandHelp
    {
        return new CommandHelp(
            summary: 'Makes meta.json\'s path say where the page\'s directory is (pages moved by hand), and indexes the page there.',
            usage: '[--dry-run] [--json]',
            options: [
                '--dry-run' => 'list the pages that would be repaired; change nothing',
                '--json' => 'print the run report as JSON',
            ],
        );
    }

    public function run(array $args, Output $output): int
    {
        $dryRun = \in_array('--dry-run', $args, true);

        try {
            $report = $this->runner->run('pages:repair-paths', $dryRun ? MaintenanceTask::CHECK : MaintenanceTask::APPLY, 'cli', [])['report'];
        } catch (MaintenanceBusyException $e) {
            $output->error($e->getMessage());

            return 75;
        }
        if (\in_array('--json', $args, true)) {
            $output->line($report->toJson());

            return $report->exit();
        }

        foreach ($report->items() as $item) {
            $output->line(\sprintf('%-10s pid %s', $item['outcome'], (string) $item['pid']));
        }
        $counts = $report->summary();
        $output->line(\sprintf(
            '%d page(s), %d with a path that is not their directory%s',
            $counts['pages'] ?? 0,
            $counts['drifted'] ?? 0,
            $dryRun ? '' : ', ' . ($counts['repaired'] ?? 0) . ' repaired',
        ));

        return $report->exit();
    }
}
