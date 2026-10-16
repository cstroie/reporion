<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Exception\MaintenanceBusyException;
use Reporion\Service\Backup\RsyncBackup;
use Reporion\Service\Backup\RsyncConfig;
use Reporion\Service\InstanceSettings;
use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Service\Maintenance\MaintenanceTask;
use Reporion\Support\MetaText;

/**
 * bin/reporion backup:rsync [--dry-run] [--if-due] [--json] (D49): a dated,
 * differential rsync snapshot of data/ on the machine Admin → Settings →
 * Backup names. Service\Maintenance\RsyncBackupTask, the same task Admin →
 * Maintenance runs. The cron line is `backup:rsync --if-due`, hourly: what
 * it does follows the settings, so switching the schedule on or off or
 * changing its interval never touches the crontab.
 */
final class BackupRsyncCommand implements CommandInterface
{
    public function __construct(
        private readonly MaintenanceRunner $runner,
        private readonly string $dataRoot,
    ) {
    }

    public static function help(): CommandHelp
    {
        return new CommandHelp(
            summary: 'Copies data/ to the backup machine as a new dated snapshot over ssh + rsync; files unchanged since the last snapshot are hard links, and snapshots older than the retention (a week or a month) are removed there (D49).',
            usage: '[--dry-run] [--if-due] [--json]',
            options: [
                '--dry-run' => 'ask the target without storing anything: reachable, key accepted, how much would be sent',
                '--if-due' => 'for cron: do nothing unless the schedule is on (Admin → Settings → Backup) and the last snapshot is older than its interval',
                '--json' => 'print the run report as JSON',
            ],
            details: "The target, key, retention and interval are set in Admin → Settings → Backup. Without --if-due a run works as soon as the target is set, whether or not the schedule is on.\nRun as the web server's user (sudo -u www-data) so it can read data/ and the key. Nothing rsync or ssh print is kept or shown: a failure is a fixed message with the exit status. The copy holds patient data: keep the target on an encrypted volume.\nTakes the maintenance lock; refused while another run is in progress (exit 75).",
        );
    }

    public function run(array $args, Output $output): int
    {
        $dryRun = \in_array('--dry-run', $args, true);
        $json = \in_array('--json', $args, true);
        if (\in_array('--if-due', $args, true)) {
            $config = RsyncConfig::fromSettings((new InstanceSettings($this->dataRoot))->load());
            if (!RsyncBackup::due($config, $this->dataRoot, time())) {
                if (!$json) {
                    $output->line($config->enabled ? 'not due: nothing done' : 'the rsync backup schedule is off: nothing done');
                }

                return 0;
            }
        }
        try {
            $report = $this->runner->run('backup:rsync', $dryRun ? MaintenanceTask::CHECK : MaintenanceTask::APPLY, 'cli', [])['report'];
        } catch (MaintenanceBusyException $e) {
            $output->error($e->getMessage());

            return 75;
        }
        if ($json) {
            $output->line($report->toJson());

            return $report->exit();
        }
        $summary = $report->summary();
        if ($report->exit() !== 0) {
            foreach ($report->notes() as $note) {
                $output->error($note);
            }

            return $report->exit();
        }
        if ($dryRun) {
            $output->line(\sprintf('reachable; %d file(s), %s in data/; %d would be sent', (int) ($summary['files'] ?? 0), MetaText::size((int) ($summary['bytes'] ?? 0)), (int) ($summary['to_send'] ?? 0)));
        } else {
            $output->line(\sprintf(
                'snapshot %s: %d file(s) sent (%s), %d old snapshot(s) removed',
                $report->notes()[0] ?? '',
                (int) ($summary['sent_files'] ?? 0),
                MetaText::size((int) ($summary['sent_bytes'] ?? 0)),
                (int) ($summary['pruned'] ?? 0),
            ));
            foreach (\array_slice($report->notes(), 1) as $note) {
                $output->line('warning: ' . $note);
            }
        }

        return 0;
    }
}
