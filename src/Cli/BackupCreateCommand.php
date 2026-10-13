<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Exception\MaintenanceBusyException;
use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Service\Maintenance\MaintenanceTask;
use Reporion\Support\MetaText;

/**
 * bin/reporion backup:create [--dry-run] [--json] (D44): an archive of
 * data/ and conf/local.php in data/backups/. Service\Maintenance\BackupTask,
 * the same task Admin → Maintenance runs.
 */
final class BackupCreateCommand implements CommandInterface
{
    public function __construct(private readonly MaintenanceRunner $runner)
    {
    }

    public static function help(): CommandHelp
    {
        return new CommandHelp(
            summary: 'Writes a backup archive (a .tar.gz of data/ and conf/local.php, with a checksum of every file) to data/backups/. For moving an instance and for a copy to keep; rsync stays the routine backup (D22).',
            usage: '[--dry-run] [--json]',
            options: [
                '--dry-run' => 'count what the archive would hold; write nothing',
                '--json' => 'print the run report as JSON',
            ],
            details: "Left out: the index (a cache, rebuilt on restore), data/tmp/, data/backups/ and lock files. The archive holds patient data and the session secret: keep it as carefully as data/.\nRun as the web server's user (sudo -u www-data). Takes the maintenance lock; refused while another run is in progress (exit 75).",
        );
    }

    public function run(array $args, Output $output): int
    {
        $dryRun = \in_array('--dry-run', $args, true);
        $json = \in_array('--json', $args, true);
        try {
            $report = $this->runner->run('backup:create', $dryRun ? MaintenanceTask::CHECK : MaintenanceTask::APPLY, 'cli', [])['report'];
        } catch (MaintenanceBusyException $e) {
            $output->error($e->getMessage());

            return 75;
        }
        if ($json) {
            $output->line($report->toJson());

            return $report->exit();
        }
        foreach ($report->notes() as $note) {
            $output->line($report->exit() === 0 ? 'wrote data/backups/' . $note : $note);
        }
        $summary = $report->summary();
        if ($report->exit() === 0) {
            $output->line(\sprintf(
                '%s %d file(s), %s%s',
                $dryRun ? 'would hold' : 'holds',
                (int) ($summary['files'] ?? 0),
                MetaText::size((int) ($summary['bytes'] ?? 0)),
                isset($summary['archive_bytes']) ? ' (archive ' . MetaText::size((int) $summary['archive_bytes']) . ')' : '',
            ));
            if (($summary['skipped'] ?? 0) > 0) {
                $output->line(\sprintf('%d link(s) or special file(s) skipped', (int) $summary['skipped']));
            }
        }

        return $report->exit();
    }
}
