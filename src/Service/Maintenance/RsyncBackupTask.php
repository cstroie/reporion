<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Maintenance;

use Reporion\Service\Backup\RsyncBackup;
use Reporion\Service\Backup\RsyncConfig;
use Reporion\Service\InstanceSettings;

/**
 * backup:rsync: the periodic differential rsync snapshot (D49). Check asks
 * the target without storing anything (`rsync -n --stats`: is the host
 * reachable, the key accepted, how much would be sent); apply makes the
 * snapshot, moves `latest` and prunes the old ones. Counts and fixed
 * messages only: nothing rsync printed reaches the report (invariant 8).
 * Whether the schedule is on (`backup.enabled`) matters to `--if-due`
 * only; a run asked for by hand works as soon as the target is set.
 */
final class RsyncBackupTask implements MaintenanceTask
{
    public function __construct(
        private readonly string $dataRoot,
        private readonly string $confFile,
        private readonly ?string $rsync = null,
        private readonly ?string $ssh = null,
    ) {
    }

    public function name(): string
    {
        return 'backup:rsync';
    }

    public function modes(): array
    {
        return [self::CHECK, self::APPLY];
    }

    public function options(array $raw): array
    {
        return [];
    }

    public function run(string $mode, string $actor, array $options): MaintenanceReport
    {
        $report = new MaintenanceReport($this->name(), $mode, $actor, $options, date('Y-m-d\TH:i:sP'));
        $config = RsyncConfig::fromSettings((new InstanceSettings($this->dataRoot))->load());
        $backup = new RsyncBackup(
            $config,
            $this->dataRoot,
            $this->confFile,
            $this->rsync ?? RsyncBackup::binary('rsync'),
            $this->ssh ?? RsyncBackup::binary('ssh'),
        );
        $result = $backup->run($mode === self::CHECK);
        if ($result['error'] !== null) {
            [$code, $args] = $result['error'];
            $report->count('failed');
            $report->note(t('admin.maint.rsync.err.' . $code, $args));
            $report->fail();

            return $report;
        }
        $report->count('files', $result['files']);
        $report->count('bytes', $result['bytes']);
        if ($mode === self::CHECK) {
            $report->count('to_send', $result['transferred']);
        } else {
            $report->count('sent_files', $result['transferred']);
            $report->count('sent_bytes', $result['sent']);
            $report->count('pruned', $result['pruned']);
            $report->note($result['snapshot']);
        }
        foreach ($result['warnings'] as $warning) {
            $report->note(t('admin.maint.rsync.warn.' . $warning));
        }

        return $report;
    }
}
