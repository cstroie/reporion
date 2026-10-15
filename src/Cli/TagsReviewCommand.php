<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Service\Maintenance\MaintenanceTask;

/**
 * bin/reporion tags:review [--no-ai] [--actor=<username>] [--json]
 *
 * Proposals to tidy the tags in use: near-duplicates and tags made too
 * specific by a side or a spine level (the rule), and the assistant's
 * `tag-review` prompt's merges, generalisations, renames and drops, asked
 * about the tag list only. Writes no page: the run's report holds the
 * proposals, applied one by one from Admin → Tags.
 * Service\Maintenance\TagReviewTask, the same task Admin → Tags runs.
 */
final class TagsReviewCommand implements CommandInterface
{
    public function __construct(private readonly MaintenanceRunner $runner)
    {
    }

    public static function help(): CommandHelp
    {
        return new CommandHelp(
            summary: 'Proposes merges, generalisations, renames and drops for the tags in use (a rule, and the assistant\'s tag-review prompt on the tag list only). Writes no page: apply the proposals in Admin → Tags.',
            usage: '[--no-ai] [--actor=<username>] [--json]',
            options: [
                '--no-ai' => 'the rule\'s proposals only; ask no server',
                '--actor=<username>' => 'on whose behalf it runs (audit, run report); default: cli',
                '--json' => 'print the run report as JSON',
            ],
        );
    }

    public function run(array $args, Output $output): int
    {
        $actor = null;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--actor=') && \strlen($arg) > 8) {
                $actor = substr($arg, 8);
            }
        }
        $raw = ['ai' => !\in_array('--no-ai', $args, true)];
        $json = \in_array('--json', $args, true);
        if (!$json) {
            $output->line(\sprintf('tags:review (proposals only%s)', $raw['ai'] ? '' : ', no assistant'));
        }

        ['report' => $report, 'id' => $id] = $this->runner->run('tags:review', MaintenanceTask::CHECK, $actor ?? 'cli', $raw);
        if ($json) {
            $output->line($report->toJson());

            return $report->exit();
        }

        foreach ($report->items() as $item) {
            $output->line(\sprintf('%-10s %s  [%s]', (string) ($item['data']['op'] ?? ''), $item['detail'], (string) ($item['data']['source'] ?? '')));
        }
        $summary = $report->summary();
        $output->line(\sprintf(
            '%d tag(s): %d proposal(s) by the rule, %d by the assistant; %d tag(s) withheld from it. Review them in Admin → Tags (run %s).',
            $summary['tags'],
            $summary['proposals_rule'],
            $summary['proposals_ai'],
            $summary['withheld'],
            $id
        ));
        foreach ($report->notes() as $note) {
            $output->error($note);
        }

        return $report->exit();
    }
}
