<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Exception\MaintenanceBusyException;
use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Service\Maintenance\MaintenanceTask;

/**
 * bin/reporion pages:apply-meta-block [--apply --actor=<username>] [--limit=<n>] [--json]
 *
 * Lists reports still carrying the imported `~~META: … ~~` block (TODO.md
 * idea 10) and what it would fill; --apply writes one new revision per
 * unsigned report — the block's fields into any frontmatter field left
 * empty, the block itself stripped from the body — attributed to --actor,
 * at most --limit of them per run — Service\Maintenance\MetaBlockTask, the
 * same task Admin → Maintenance runs. A field that disagrees with one
 * already set sends the page to review instead of guessing; a malformed
 * block is listed unparseable; a signed report is listed, never rewritten
 * here (D3). Pages are named by pid.
 */
final class PagesApplyMetaBlockCommand implements CommandInterface
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
            $report = $this->runner->run('pages:apply-meta-block', $apply ? MaintenanceTask::APPLY : MaintenanceTask::CHECK, $actor ?? 'cli', $raw)['report'];
        } catch (MaintenanceBusyException $e) {
            $output->error($e->getMessage());

            return 75;
        }
        if (\in_array('--json', $args, true)) {
            $output->line($report->toJson());

            return $report->exit();
        }

        // Items are what needs a reader: review, unparseable and signed
        foreach ($report->items() as $item) {
            $output->line(\sprintf('pid %s rev %d: %s — %s', (string) $item['pid'], (int) $item['rev'], $item['outcome'], $item['detail']));
        }

        $summary = $report->summary();
        $output->line(\sprintf(
            '%s; %d to review by hand; %d unparseable (left as they are); %d signed (correct and re-sign by hand)%s',
            $apply
                ? $summary['applied'] . ' applied'
                : $summary['would_apply'] . ' to apply (run with --apply --actor=<username>)',
            $summary['review'],
            $summary['unparseable'],
            $summary['signed'],
            $apply && $summary['remaining'] > 0 ? '; ' . $summary['remaining'] . ' left for the next run (--limit)' : '',
        ));

        return 0;
    }
}
