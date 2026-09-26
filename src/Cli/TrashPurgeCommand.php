<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Exception\MaintenanceBusyException;
use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Service\Maintenance\MaintenanceTask;

/**
 * bin/reporion trash:purge [--older-than=30d] [--include-signed --operator=<username>] [--dry-run] [--json]
 *
 * Permanently removes pages deleted more than N days ago (default:
 * pages.trash_purge_days). Signed pages are kept and reported unless the
 * D3b override is given — --include-signed together with --operator,
 * the person taking responsibility, who is named in the audit line.
 * Meant for a daily cron as the web server's user. Service\Maintenance\
 * TrashPurgeTask, the same task Admin → Maintenance runs.
 */
final class TrashPurgeCommand implements CommandInterface
{
    public function __construct(private readonly MaintenanceRunner $runner)
    {
    }

    public function run(array $args, Output $output): int
    {
        $days = null;
        $operator = null;
        $includeSigned = \in_array('--include-signed', $args, true);
        $dryRun = \in_array('--dry-run', $args, true);
        foreach ($args as $arg) {
            if (preg_match('/^--older-than=(\d+)d?$/', $arg, $m) === 1) {
                $days = (int) $m[1];
            } elseif (str_starts_with($arg, '--operator=') && \strlen($arg) > 11) {
                $operator = substr($arg, 11);
            }
        }
        if ($includeSigned && $operator === null) {
            $output->error('--include-signed needs --operator=<username>: purging signed content is an explicit, named override (D3b)');

            return 1;
        }

        $options = ['include_signed' => $includeSigned] + ($days !== null ? ['older_than' => $days] : []);
        try {
            $report = $this->runner->run('trash:purge', $dryRun ? MaintenanceTask::CHECK : MaintenanceTask::APPLY, $operator ?? 'cli', $options)['report'];
        } catch (MaintenanceBusyException $e) {
            $output->error($e->getMessage());

            return 75;
        }
        if (\in_array('--json', $args, true)) {
            $output->line($report->toJson());

            return $report->exit();
        }

        $summary = $report->summary();
        $output->line(\sprintf(
            '%s %d page(s) deleted over %d day(s) ago; %d signed page(s) kept (D3b)',
            $dryRun ? 'would purge' : 'purged',
            $summary[$dryRun ? 'would_purge' : 'purged'],
            (int) $report->options['older_than'],
            $summary['kept_signed']
        ));

        return 0;
    }
}
