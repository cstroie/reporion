<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use InvalidArgumentException;
use Reporion\Auth\User;
use Reporion\Exception\MaintenanceBusyException;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Service\Maintenance\MaintenanceReport;
use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Service\Maintenance\MaintenanceTask;

/**
 * GET /admin/maintenance[?run={id}] and POST /admin/maintenance/{task} —
 * Admin → Maintenance, owner-only (decided 2026-09-26): the maintenance
 * commands of bin/reporion, run from the browser through the same
 * Service\Maintenance tasks.
 *
 * Nothing runs on GET. Every run is a POST, which stores its report and
 * redirects to it (Post/Redirect/Get), so a refresh never repeats a repair
 * or a purge; apply additionally needs the confirm box ticked. The
 * signed-in owner is the actor — and, for a purge that includes signed
 * pages, the named operator (D3b). The report itself names pages by pid;
 * only this owner-only screen looks their titles and paths up.
 */
final class AdminMaintenanceController
{
    private const SHOWN_ITEMS = 500;

    public function __construct(
        private readonly MaintenanceRunner $runner,
        private readonly IndexInterface $index,
    ) {
    }

    public function show(Request $request, ?User $principal, ?string $error = null): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }

        $runId = (string) ($request->query['run'] ?? '');
        $report = $runId !== '' ? $this->runner->load($runId) : null;
        $pages = [];
        foreach ($report !== null ? \array_slice($report->items(), 0, self::SHOWN_ITEMS) : [] as $item) {
            if ($item['pid'] !== null && !isset($pages[$item['pid']])) {
                $row = $this->index->findByPid($item['pid'], $principal);
                $pages[$item['pid']] = $row === null ? null : ['path' => (string) $row['path'], 'title' => (string) $row['title']];
            }
        }

        return Response::html(View::page(\dirname(__DIR__, 2) . '/templates/admin-maintenance.php', [
            'tasks' => $this->runner->tasks(),
            'recent' => $this->runner->recent(15),
            'runId' => $report !== null ? $runId : null,
            'report' => $report,
            'pages' => $pages,
            'shownItems' => self::SHOWN_ITEMS,
            'busy' => isset($request->query['busy']),
            'error' => $error,
            'adminTab' => 'maintenance',
            'basePath' => $request->basePath,
        ] + ChromeVars::shell($request, $principal, $this->index, ''), t('admin.maint.title')), $error === null ? 200 : 422);
    }

    public function run(Request $request, string $task, ?User $principal): Response
    {
        if ($principal?->isOwner !== true || !isset($this->runner->tasks()[$task])) {
            throw new PageNotFoundException();
        }
        parse_str($request->body, $fields);
        $mode = (string) ($fields['mode'] ?? MaintenanceTask::CHECK);
        if ($mode === MaintenanceTask::APPLY && ($fields['confirm'] ?? '') !== '1') {
            return $this->show($request, $principal, t('admin.maint.err_confirm'));
        }

        // A full pass over every page; don't let a default 30 s limit cut it off
        set_time_limit(300);
        try {
            $run = $this->runner->run($task, $mode, $principal->username, $fields, $request);
        } catch (MaintenanceBusyException) {
            return Response::redirect($request->basePath . '/admin/maintenance?busy=1');
        } catch (InvalidArgumentException $e) {
            return $this->show($request, $principal, $e->getMessage());
        }

        return Response::redirect($request->basePath . '/admin/maintenance?run=' . $run['id'] . '#report');
    }

    /** GET /admin/maintenance/runs/{id}.json — a stored report as it is kept, for tools */
    public function json(Request $request, string $id, ?User $principal): Response
    {
        $report = $principal?->isOwner === true ? $this->runner->load($id) : null;
        if ($report === null) {
            throw new PageNotFoundException();
        }

        return new Response(200, $report->toJson() . "\n", [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'inline; filename="maintenance-' . $id . '.json"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** The anchor id of a task's card, e.g. "journal-replay" */
    public static function anchor(string $task): string
    {
        return str_replace(':', '-', $task);
    }

    /** A run's one-line summary, e.g. "2 recovered · 1 discarded" */
    public static function summaryLine(MaintenanceReport $report): string
    {
        $parts = [];
        foreach ($report->summary() as $key => $n) {
            $parts[] = $n . ' ' . str_replace('_', ' ', $key);
        }

        return $parts === [] ? t('admin.maint.nothing') : implode(' · ', $parts);
    }
}
