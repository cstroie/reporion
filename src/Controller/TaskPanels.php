<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Auth\User;
use Reporion\Http\Request;
use Reporion\Index\IndexInterface;
use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Service\Maintenance\ReportView;
use Reporion\Support\AdminTabs;

/**
 * The maintenance tasks (Service\Maintenance) as cards on the admin tab
 * that owns their topic (Support\AdminTabs::TASK_TAB), with the report of
 * the run just made (`?run=`). templates/partials/admin-tasks.php draws
 * them; a run is still POST /admin/maintenance/{task}
 * (AdminMaintenanceController), which comes back to the same tab.
 */
final class TaskPanels
{
    private readonly ReportView $view;

    public function __construct(
        private readonly MaintenanceRunner $runner,
        IndexInterface $index,
    ) {
        $this->view = new ReportView($runner, $index);
    }

    public function runner(): MaintenanceRunner
    {
        return $this->runner;
    }

    /**
     * What partials/admin-tasks.php reads for one tab.
     *
     * @return array<string, mixed>
     */
    public function vars(Request $request, User $principal, string $tab, ?string $error = null): array
    {
        return [
            'tasks' => AdminTabs::tasksFor($this->runner->tasks(), $tab),
            'busy' => isset($request->query['busy']),
            'maintError' => $error ?? match ($request->query['maint'] ?? null) {
                'confirm' => t('admin.maint.err_confirm'),
                'invalid' => t('admin.maint.err_invalid'),
                default => null,
            },
        ] + $this->view->forRun((string) ($request->query['run'] ?? ''), $principal);
    }
}
