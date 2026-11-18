<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Exception\MaintenanceBusyException;
use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Service\Maintenance\MaintenanceTask;

/**
 * bin/reporion ai:prompts-relocate [--dry-run] [--json]
 *
 * Service\Maintenance\PromptRelocateTask, the same task as Admin → Maintenance: every profile's
 * reserved prompts moved from `special:{id}` to their place (Actions::PLACES). Run it as the
 * web server's user on a live install.
 */
final class AiPromptsRelocateCommand implements CommandInterface
{
    public function __construct(private readonly MaintenanceRunner $runner)
    {
    }

    public static function help(): CommandHelp
    {
        return new CommandHelp(
            summary: 'Moves every profile\'s reserved prompts from ai:profiles:{profile}:special:{id} to their folder (panels, buttons, questions, library, studio, auto), with their history and a redirect.',
            usage: '[--dry-run] [--json]',
            options: [
                '--dry-run' => 'list what would move; change nothing',
                '--json' => 'print the run report as JSON',
            ],
            details: 'A prompt whose new place already has a page is left (the page at the place is the one read). Until it runs, the old place is still read.',
        );
    }

    public function run(array $args, Output $output): int
    {
        $dryRun = \in_array('--dry-run', $args, true);
        if (!\in_array('--json', $args, true)) {
            $output->line($dryRun ? 'Listing the reserved prompts still under special: (nothing changes)' : 'Moving the reserved prompts from special: to their folders');
        }
        try {
            $report = $this->runner->run('ai:prompts-relocate', $dryRun ? MaintenanceTask::CHECK : MaintenanceTask::APPLY, 'cli', [])['report'];
        } catch (MaintenanceBusyException $e) {
            $output->error($e->getMessage());

            return 75;
        }
        if (\in_array('--json', $args, true)) {
            $output->line($report->toJson());

            return $report->exit();
        }
        foreach ($report->items() as $item) {
            $output->line(\sprintf('%-8s %s', $item['outcome'], $item['detail']));
        }
        $counts = $report->summary();
        $output->line(\sprintf('%d page(s) under special:%s', $counts['pages'] ?? 0, $dryRun ? ', ' . ($counts['move'] ?? 0) . ' to move' : ', ' . ($counts['moved'] ?? 0) . ' moved'));

        return $report->exit();
    }
}
