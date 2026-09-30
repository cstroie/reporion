<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use InvalidArgumentException;
use Reporion\Exception\MaintenanceBusyException;
use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Service\Maintenance\MaintenanceTask;

/**
 * bin/reporion <task> [--apply --actor=<username>] [--json] [--<option>=<value> …]
 *
 * A maintenance task a plugin adds through the maintenance.tasks hook
 * (e.g. pacs:link --site=<code> [--limit=<n>] from the dicom plugin): check
 * by default, --apply writes as --actor, the run is kept and audited like
 * any Service\Maintenance task. $options names the --key=value options the
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
        $apply = \in_array('--apply', $args, true);
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
            $output->error('--apply needs --actor=<username>: the new revisions are attributed to them');

            return 1;
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
        $output->line(implode('; ', $parts) . ($apply ? '' : ' (check only — run with --apply --actor=<username>)'));

        return $report->exit();
    }
}
