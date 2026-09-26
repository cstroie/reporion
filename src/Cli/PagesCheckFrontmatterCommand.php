<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Exception\MaintenanceBusyException;
use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Service\Maintenance\MaintenanceTask;

/**
 * bin/reporion pages:check-frontmatter [--repair --actor=<username>] [--json]
 *
 * Lists pages whose current frontmatter the editor autosave flattened
 * before 2026-09-26, with the last intact revision; --repair writes one new
 * revision per unsigned page, attributed to --actor — Service\Maintenance\
 * FrontmatterCheckTask, the same task Admin → Maintenance runs. Signed
 * pages are listed, never repaired here (D3). Pages are named by pid.
 */
final class PagesCheckFrontmatterCommand implements CommandInterface
{
    public function __construct(private readonly MaintenanceRunner $runner)
    {
    }

    public function run(array $args, Output $output): int
    {
        $repair = \in_array('--repair', $args, true);
        $actor = null;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--actor=') && \strlen($arg) > 8) {
                $actor = substr($arg, 8);
            }
        }
        if ($repair && $actor === null) {
            $output->error('--repair needs --actor=<username>: the repair revisions are attributed to them');

            return 1;
        }

        try {
            $report = $this->runner->run('pages:check-frontmatter', $repair ? MaintenanceTask::APPLY : MaintenanceTask::CHECK, $actor ?? 'cli', [])['report'];
        } catch (MaintenanceBusyException $e) {
            $output->error($e->getMessage());

            return 75;
        }
        if (\in_array('--json', $args, true)) {
            $output->line($report->toJson());

            return $report->exit();
        }

        foreach ($report->items() as $item) {
            $data = $item['data'];
            $output->line(\sprintf(
                'pid %s rev %d%s: %s; last intact rev %s',
                (string) $item['pid'],
                (int) $item['rev'],
                $data['signed'] ? ' (signed)' : '',
                implode(', ', $data['damage']),
                $data['intact_rev'] === null ? 'none' : (string) $data['intact_rev'],
            ));
            if ($data['repaired_rev'] !== null) {
                $output->line('  repaired as rev ' . $data['repaired_rev']);
            }
        }

        $summary = $report->summary();
        $output->line(\sprintf(
            '%d damaged page(s); %s; %d signed (correct and re-sign by hand); %d with no intact revision',
            $summary['damaged'],
            $repair ? $summary['repaired'] . ' repaired' : 'run with --repair --actor=<username> to repair the unsigned ones',
            $summary['signed'],
            $summary['unrecoverable'],
        ));

        return 0;
    }
}
