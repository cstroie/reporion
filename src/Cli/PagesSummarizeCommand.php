<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use InvalidArgumentException;
use Reporion\Exception\MaintenanceBusyException;
use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Service\Maintenance\MaintenanceTask;
use Reporion\Service\Maintenance\ProgressAware;

/**
 * bin/reporion pages:summarize --actor=<username> [--dry-run] [--namespace=<ns>] [--limit=<n>] [--overwrite] [--json]
 *
 * Asks the assistant's `summary` prompt for the one-line summary of each
 * unsigned report under --namespace that has none (--overwrite: all of
 * them), at most --limit per run; where there is no `summary` prompt (no
 * assistant), the conclusion's first sentence instead, nothing sent. With --dry-run it only lists what it
 * would ask and sends nothing; without it, it writes. Each page becomes one new revision by
 * --actor; signed reports are never touched (D3). One line per page to the
 * terminal ("[n/total] pid … ok"); pages are named by pid —
 * Service\Maintenance\SummarizeTask, the same task Admin → Maintenance runs.
 *
 * bin/reporion pages:tag takes the same options for the `tags` prompt
 * (Service\Maintenance\TagTask, 2026-10-08): reports with no tags.
 *
 * bin/reporion index:vectors [--dry-run] [--limit=<n>] [--force] [--json] runs
 * Service\Maintenance\VectorsTask (phase 34e): it writes no page, so it
 * needs no --actor. --force embeds every report again, not only the ones
 * whose text changed.
 */
final class PagesSummarizeCommand implements CommandInterface
{
    public function __construct(
        private readonly MaintenanceRunner $runner,
        private readonly string $task = 'pages:summarize',
        /** Whether the writes need --actor */
        private readonly bool $needsActor = true,
    ) {
    }

    public function run(array $args, Output $output): int
    {
        if (\in_array('--apply', $args, true)) {
            $output->error('--apply is gone: writes are the default now; add --dry-run to only look');

            return 1;
        }
        $apply = !\in_array('--dry-run', $args, true);
        $actor = null;
        $raw = ['overwrite' => \in_array('--overwrite', $args, true), 'force' => \in_array('--force', $args, true)];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--actor=') && \strlen($arg) > 8) {
                $actor = substr($arg, 8);
            } elseif (str_starts_with($arg, '--limit=')) {
                $raw['limit'] = substr($arg, 8);
            } elseif (str_starts_with($arg, '--namespace=')) {
                $raw['namespace'] = substr($arg, 12);
            }
        }
        if ($apply && $actor === null && $this->needsActor) {
            $output->error('--actor=<username> is needed unless --dry-run: the new revisions are attributed to them');

            return 1;
        }

        $task = $this->runner->tasks()[$this->task];
        if ($task instanceof ProgressAware) {
            $task->setProgress(\in_array('--json', $args, true) ? null : static function (string $event, array $info) use ($output): void {
                if ($event === 'start') {
                    $output->write(\sprintf('[%d/%d] %s … ', (int) $info['n'], (int) $info['total'], (string) $info['label']));
                } else {
                    $output->line((string) $info['status']);
                }
            });
        }

        try {
            $report = $this->runner->run($this->task, $apply ? MaintenanceTask::APPLY : MaintenanceTask::CHECK, $actor ?? 'cli', $raw)['report'];
        } catch (MaintenanceBusyException $e) {
            $output->error($e->getMessage());

            return 75;
        } catch (InvalidArgumentException $e) {
            $output->error($e->getMessage());

            return 1;
        }
        if (\in_array('--json', $args, true)) {
            $output->line($report->toJson());

            return $report->exit();
        }

        foreach ($report->items() as $item) {
            $output->line(\sprintf('pid %s rev %d: %s — %s', (string) $item['pid'], (int) $item['rev'], $item['outcome'], $item['detail']));
        }
        foreach ($report->notes() as $note) {
            $output->line($note);
        }
        $parts = [];
        foreach ($report->summary() as $key => $n) {
            $parts[] = $n . ' ' . str_replace('_', ' ', $key);
        }
        $output->line(implode('; ', $parts) . ($apply ? '' : ' (dry run: nothing sent)'));

        return $report->exit();
    }
}
