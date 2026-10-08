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
 * bin/reporion pages:summarize [--apply --actor=<username>] [--namespace=<ns>] [--limit=<n>] [--overwrite] [--json]
 *
 * Asks the assistant's `summary` prompt for the one-line summary of each
 * unsigned report under --namespace that has none (--overwrite: all of
 * them), at most --limit per run; where there is no `summary` prompt (no
 * assistant), the conclusion's first sentence instead, nothing sent. Without --apply it only lists what it
 * would ask and sends nothing. Each page becomes one new revision by
 * --actor; signed reports are never touched (D3). One line per page to the
 * terminal ("[n/total] pid … ok"); pages are named by pid —
 * Service\Maintenance\SummarizeTask, the same task Admin → Maintenance runs.
 */
final class PagesSummarizeCommand implements CommandInterface
{
    public function __construct(private readonly MaintenanceRunner $runner)
    {
    }

    public function run(array $args, Output $output): int
    {
        $apply = \in_array('--apply', $args, true);
        $actor = null;
        $raw = ['overwrite' => \in_array('--overwrite', $args, true)];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--actor=') && \strlen($arg) > 8) {
                $actor = substr($arg, 8);
            } elseif (str_starts_with($arg, '--limit=')) {
                $raw['limit'] = substr($arg, 8);
            } elseif (str_starts_with($arg, '--namespace=')) {
                $raw['namespace'] = substr($arg, 12);
            }
        }
        if ($apply && $actor === null) {
            $output->error('--apply needs --actor=<username>: the new revisions are attributed to them');

            return 1;
        }

        $task = $this->runner->tasks()['pages:summarize'];
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
            $report = $this->runner->run('pages:summarize', $apply ? MaintenanceTask::APPLY : MaintenanceTask::CHECK, $actor ?? 'cli', $raw)['report'];
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
        $output->line(implode('; ', $parts) . ($apply ? '' : ' (nothing sent: run with --apply --actor=<username>)'));

        return $report->exit();
    }
}
