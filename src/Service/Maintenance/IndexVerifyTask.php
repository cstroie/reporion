<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Maintenance;

use Reporion\Service\IndexMaintenance;

/**
 * index:verify — the cheap stat/hash drift pass: pages indexed but gone
 * from disk (orphans), on disk but not indexed (missing), indexed but stale
 * (drifted). Only reports; disk stays authoritative (invariant 1) and the
 * fix is a rebuild (Admin → Index & storage, or index:rebuild).
 */
final class IndexVerifyTask implements MaintenanceTask
{
    private const KINDS = ['orphans' => 'orphan', 'missing' => 'missing', 'drifted' => 'drifted'];

    public function __construct(private readonly IndexMaintenance $maintenance)
    {
    }

    public function name(): string
    {
        return 'index:verify';
    }

    public function modes(): array
    {
        return [self::CHECK];
    }

    public function options(array $raw): array
    {
        return [];
    }

    public function run(string $mode, string $actor, array $options): MaintenanceReport
    {
        $report = new MaintenanceReport($this->name(), $mode, $actor, $options, date('Y-m-d\TH:i:sP'));
        $found = $this->maintenance->verify();
        foreach (self::KINDS as $key => $outcome) {
            $report->count($key, 0);
            foreach ($found[$key] as $pid) {
                $report->item($pid, null, $outcome);
                $report->count($key);
            }
        }
        if ($report->items() !== []) {
            $report->note('Rebuild the index to fix drift: Admin → Index & storage, or bin/reporion index:rebuild.');
            $report->fail();
        }

        return $report;
    }
}
