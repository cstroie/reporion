<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Maintenance;

use Reporion\Exception\BackupException;
use Reporion\Service\Backup\BackupService;

/**
 * backup:create: an archive of data/ (and conf/local.php) in data/backups/
 * (D44). The check counts what it would hold; the apply writes it. Counts
 * and sizes only: never a page path (invariant 8).
 */
final class BackupTask implements MaintenanceTask
{
    public function __construct(private readonly BackupService $backups)
    {
    }

    public function name(): string
    {
        return 'backup:create';
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
        if ($mode === self::CHECK) {
            $plan = $this->backups->plan();
            $report->count('files', $plan['files']);
            $report->count('bytes', $plan['bytes']);
            $report->count('skipped', $plan['skipped']);

            return $report;
        }
        try {
            $made = $this->backups->create();
        } catch (BackupException $e) {
            $report->count('failed');
            $report->note($e->getMessage());
            $report->fail();

            return $report;
        }
        $report->count('files', $made['files']);
        $report->count('bytes', $made['bytes']);
        $report->count('archive_bytes', $made['size']);
        $report->count('skipped', $made['skipped']);
        $report->note($made['file']);

        return $report;
    }
}
