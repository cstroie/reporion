<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Exception\MaintenanceBusyException;
use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Service\Maintenance\MaintenanceTask;

/**
 * bin/reporion pages:normalize-headings [--apply --actor=<username>] [--limit=<n>] [--json]
 *
 * Lists the reports whose headings are not yet in the one shape
 * (`#` name, `##` exam, `###` sections — docs/FORMATS.md §11) and those
 * that need a look by hand; --apply writes one new revision per unsigned
 * report, attributed to --actor, at most --limit of them per run —
 * Service\Maintenance\HeadingNormalizeTask, the same task Admin →
 * Maintenance runs. Signed reports are listed, never rewritten here (D3).
 * Pages are named by pid.
 */
final class PagesNormalizeHeadingsCommand implements CommandInterface
{
    public function __construct(private readonly MaintenanceRunner $runner)
    {
    }

    public function run(array $args, Output $output): int
    {
        $apply = \in_array('--apply', $args, true);
        $actor = null;
        $raw = [];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--actor=') && \strlen($arg) > 8) {
                $actor = substr($arg, 8);
            } elseif (str_starts_with($arg, '--limit=')) {
                $raw['limit'] = substr($arg, 8);
            }
        }
        if ($apply && $actor === null) {
            $output->error('--apply needs --actor=<username>: the new revisions are attributed to them');

            return 1;
        }

        try {
            $report = $this->runner->run('pages:normalize-headings', $apply ? MaintenanceTask::APPLY : MaintenanceTask::CHECK, $actor ?? 'cli', $raw)['report'];
        } catch (MaintenanceBusyException $e) {
            $output->error($e->getMessage());

            return 75;
        }
        if (\in_array('--json', $args, true)) {
            $output->line($report->toJson());

            return $report->exit();
        }

        // Items are what needs a reader: review and signed
        foreach ($report->items() as $item) {
            $output->line(\sprintf('pid %s rev %d: %s — %s', (string) $item['pid'], (int) $item['rev'], $item['outcome'], $item['detail']));
        }

        $summary = $report->summary();
        $output->line(\sprintf(
            '%s; %d unchanged; %d to review by hand; %d signed (correct and re-sign by hand)%s',
            $apply
                ? $summary['normalized'] . ' normalized'
                : $summary['would_normalize'] . ' to normalize (run with --apply --actor=<username>)',
            $summary['unchanged'],
            $summary['review'],
            $summary['signed'],
            $apply && $summary['remaining'] > 0 ? '; ' . $summary['remaining'] . ' left for the next run (--limit)' : '',
        ));

        return 0;
    }
}
