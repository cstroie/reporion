<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Exception\MaintenanceBusyException;
use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Service\Maintenance\MaintenanceTask;

/**
 * bin/reporion tags:prune [--max-uses=1] [--actor=<username>] [--dry-run] [--json]
 *
 * Takes off the tags carried by no more than N reports (1 to 5, default 1)
 * — one new revision per report, by system:admin (D41); signed reports keep
 * theirs; RADS categories and the tags of Admin → Tags are never pruned.
 * Service\Maintenance\TagPruneTask, the same task Admin → Maintenance runs.
 */
final class TagsPruneCommand implements CommandInterface
{
    public function __construct(private readonly MaintenanceRunner $runner)
    {
    }

    public static function help(): CommandHelp
    {
        return new CommandHelp(
            summary: 'Removes the tags that few reports carry (default: those that occur once). Signed reports keep theirs; RADS categories and dictionary tags stay.',
            usage: '[--max-uses=<1-5>] [--actor=<username>] [--dry-run] [--json]',
            options: [
                '--max-uses=<n>' => 'remove a tag carried by at most n reports, 1 to 5 (default 1)',
                '--actor=<username>' => 'on whose behalf it runs (audit, run report); default: cli. The revisions are system:admin (D41)',
                '--dry-run' => 'list the tags that would go; write nothing',
                '--json' => 'print the run report as JSON',
            ],
        );
    }

    public function run(array $args, Output $output): int
    {
        $dryRun = \in_array('--dry-run', $args, true);
        $actor = null;
        $raw = [];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--actor=') && \strlen($arg) > 8) {
                $actor = substr($arg, 8);
            } elseif (str_starts_with($arg, '--max-uses=')) {
                $raw['max_uses'] = substr($arg, 11);
            }
        }
        $output->line(\sprintf('tags:prune (%s)', $dryRun ? 'dry run' : 'writes'));

        try {
            $report = $this->runner->run('tags:prune', $dryRun ? MaintenanceTask::CHECK : MaintenanceTask::APPLY, $actor ?? 'cli', $raw)['report'];
        } catch (MaintenanceBusyException $e) {
            $output->error($e->getMessage());

            return 75;
        }
        if (\in_array('--json', $args, true)) {
            $output->line($report->toJson());

            return $report->exit();
        }

        foreach ($report->items() as $item) {
            $output->line($item['detail'] . ' … ' . $item['outcome']);
        }
        $summary = $report->summary();
        $output->line(\sprintf(
            '%s %d tag(s) carried by at most %d report(s)%s; %d signed page(s) keep theirs',
            $dryRun ? 'would remove' : 'removed',
            $summary[$dryRun ? 'tags_to_remove' : 'tags_removed'],
            (int) $report->options['max_uses'],
            $dryRun ? '' : \sprintf(' from %d page(s)', $summary['pages_changed']),
            $summary['signed_pages_kept']
        ));
        foreach ($report->notes() as $note) {
            $output->error($note);
        }

        return $report->exit();
    }
}
