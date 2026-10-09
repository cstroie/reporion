<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Service\Maintenance\MaintenanceTask;

/**
 * bin/reporion integrity:verify [--backup=<dir>] [--json]
 *
 * Service\Maintenance\IntegrityVerifyTask, the same check Admin →
 * Maintenance runs (roadmap phase 23): revisions, current.md, signatures,
 * media, the journal and the index; with --backup, the signed revisions
 * in that copy of data/. Only reports — exit 1 on any problem, for cron.
 * Pages are named by pid, never by path (invariant 8).
 */
final class IntegrityVerifyCommand implements CommandInterface
{
    public function __construct(private readonly MaintenanceRunner $runner)
    {
    }

    public static function help(): CommandHelp
    {
        return new CommandHelp(
            summary: 'Checks revisions, signatures, media files, stray page files, the journal and the index, and reports what it finds. Changes nothing.',
            usage: '[--backup=<dir>] [--json]',
            options: [
                '--backup=<dir>' => 'also checks the signed revisions in a copy of data/ at <dir>',
                '--json' => 'print the run report as JSON',
            ],
            details: 'Exit 1 on any problem, for cron.',
        );
    }

    public function run(array $args, Output $output): int
    {
        $backup = '';
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--backup=')) {
                $backup = substr($arg, \strlen('--backup='));
            }
        }
        $report = $this->runner->run('integrity:verify', MaintenanceTask::CHECK, 'cli', ['backup' => $backup])['report'];
        if (\in_array('--json', $args, true)) {
            $output->line($report->toJson());

            return $report->exit();
        }

        foreach ($report->items() as $item) {
            $output->line(\sprintf(
                '  %-18s %s%s  %s',
                $item['outcome'],
                $item['pid'] ?? (string) ($item['data']['path_hash'] ?? $item['data']['file'] ?? '-'),
                $item['rev'] !== null ? ' rev ' . $item['rev'] : '',
                $item['detail'],
            ));
        }
        $summary = $report->summary();
        $output->line(\sprintf(
            '%d page(s), %d revision(s), %d signature(s), %d media file(s)%s — %s',
            $summary['pages'],
            $summary['revisions'],
            $summary['signatures'],
            $summary['media'],
            isset($summary['backup_checked']) ? ', ' . $summary['backup_checked'] . ' signed revision(s) in the backup' : '',
            $summary['problems'] === 0 ? 'all intact' : $summary['problems'] . ' problem(s)',
        ));

        return $report->exit();
    }
}
