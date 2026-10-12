<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Maintenance;

use Reporion\Auth\User;
use Reporion\Controller\AdminMaintenanceController;
use Reporion\Index\IndexInterface;

/**
 * What a screen needs to show one stored run (`?run=`): the report, whether
 * it is still running, and the title and path of the pages its first items
 * name (the report holds pids only — this owner-only lookup is the one place
 * they are resolved). Shared by Admin → Maintenance and Admin → Tags.
 */
final class ReportView
{
    public const SHOWN_ITEMS = 500;

    public function __construct(
        private readonly MaintenanceRunner $runner,
        private readonly IndexInterface $index,
    ) {
    }

    /**
     * @return array{runId: ?string, report: ?MaintenanceReport, running: ?bool, pages: array<string, ?array{path: string, title: string}>, shownItems: int}
     */
    public function forRun(string $runId, ?User $principal): array
    {
        $report = $runId !== '' ? $this->runner->load($runId) : null;
        $pages = [];
        foreach ($report !== null ? \array_slice($report->items(), 0, self::SHOWN_ITEMS) : [] as $item) {
            if ($item['pid'] !== null && !isset($pages[$item['pid']])) {
                $row = $this->index->findByPid($item['pid'], $principal);
                $pages[$item['pid']] = $row === null ? null : ['path' => (string) $row['path'], 'title' => (string) $row['title']];
            }
        }

        return [
            'runId' => $report !== null ? $runId : null,
            'report' => $report,
            'running' => $report !== null && $report->finishedAt() === '' ? AdminMaintenanceController::stillRunning($report) : null,
            'pages' => $pages,
            'shownItems' => self::SHOWN_ITEMS,
        ];
    }
}
