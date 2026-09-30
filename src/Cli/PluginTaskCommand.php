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
 * bin/reporion <task> --actor=<username> [--dry-run] [--json] [--<option>=<value> …]
 *
 * A maintenance task a plugin adds through the maintenance.tasks hook
 * (e.g. pacs:link --site=<code> [--limit=<n>] from the dicom plugin): it writes
 * as --actor unless --dry-run (as trash:purge and journal:replay), the run is kept and audited like
 * any Service\Maintenance task. A task that reports progress (ProgressAware) prints
 * one line per report — "[n/total] name … status" — to the terminal only. $options names the --key=value options the
 * task takes; the task validates them.
 */
final class PluginTaskCommand implements CommandInterface
{
    /** @param list<string> $options */
    public function __construct(
        private readonly MaintenanceRunner $runner,
        private readonly string $task,
        private readonly array $options,
    ) {
    }

    public function run(array $args, Output $output): int
    {
        if (!isset($this->runner->tasks()[$this->task])) {
            $output->error($this->task . ' comes from a plugin that is not enabled');

            return 1;
        }
        $apply = !\in_array('--dry-run', $args, true);
        $actor = null;
        $raw = [];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--actor=') && \strlen($arg) > 8) {
                $actor = substr($arg, 8);
                continue;
            }
            foreach ($this->options as $option) {
                if (str_starts_with($arg, '--' . $option . '=')) {
                    $raw[$option] = substr($arg, \strlen($option) + 3);
                }
            }
        }
        if ($apply && $actor === null) {
            $output->error('--actor=<username> is needed unless --dry-run: the new revisions are attributed to them');

            return 1;
        }

        // Unless --json (whose stdout must stay parseable), say which report is being worked on and how it ended
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
        $output->line(implode('; ', $parts) . ($apply ? '' : ' (dry run: nothing written)'));

        return $report->exit();
    }
}
