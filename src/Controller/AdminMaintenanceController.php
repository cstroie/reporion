<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Exception\MaintenanceBusyException;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Service\Backup\BackupService;
use Reporion\Service\Maintenance\MaintenanceReport;
use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Service\Maintenance\MaintenanceTask;
use Reporion\Support\AdminTabs;

/**
 * POST /admin/maintenance/{task}, owner-only (decided 2026-09-26): the
 * maintenance commands of bin/reporion, run from the browser through the
 * same Service\Maintenance tasks. Their cards are on the admin tab that owns
 * their topic (Support\AdminTabs::TASK_TAB, Controller\TaskPanels): Data,
 * Reports, Library, AI, Tags; a run comes back there with its report. GET
 * /admin/maintenance, the cards' old screen, redirects to Data.
 *
 * Nothing runs on GET. Every run is a POST, which stores its report and
 * redirects to it (Post/Redirect/Get), so a refresh never repeats a repair
 * or a purge; apply additionally needs the confirm box ticked. The
 * signed-in owner is the actor, and, for a purge that includes signed
 * pages, the named operator (D3b). The report itself names pages by pid;
 * only this owner-only screen looks their titles and paths up.
 */
final class AdminMaintenanceController
{
    /** Seconds after which an unfinished run is taken as stopped, not running */
    private const UNFINISHED_AFTER = 3600;

    /** Under PHP-FPM a run finishes after its response: the form answers at once */
    private readonly bool $detach;

    /**
     * @param array<string, \Closure(Request, User, string): Response> $tabs a tab key => its screen with a
     *        refused run's message (Support\AdminTabs::TASK_TAB)
     */
    public function __construct(
        private readonly MaintenanceRunner $runner,
        ?bool $detach = null,
        private readonly ?BackupService $backups = null,
        private readonly ?AuditLog $audit = null,
        private readonly array $tabs = [],
    ) {
        $this->detach = $detach ?? \function_exists('fastcgi_finish_request');
    }

    /** GET /admin/maintenance: the old address of the task cards, now on Data and the tabs that own them */
    public function show(Request $request, ?User $principal): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }
        $run = (string) ($request->query['run'] ?? '');
        $report = $run !== '' ? $this->runner->load($run) : null;
        $home = $report !== null ? AdminTabs::taskRoute($report->task) . '?run=' . rawurlencode($run) . '#report' : AdminTabs::route('data');

        return Response::redirect($request->basePath . $home, 301);
    }

    /** A run refused before it began: its message on the tab whose card sent it */
    private function refused(Request $request, User $principal, string $task, string $error, string $code = 'invalid'): Response
    {
        $tab = AdminTabs::taskTab($task);
        if (isset($this->tabs[$tab])) {
            return ($this->tabs[$tab])($request, $principal, $error);
        }

        // A tab without its own error screen (Tags) shows the generic message
        return Response::redirect($request->basePath . AdminTabs::route($tab) . '?maint=' . $code);
    }

    public function run(Request $request, string $task, ?User $principal): Response
    {
        if ($principal?->isOwner !== true || !isset($this->runner->tasks()[$task])) {
            throw new PageNotFoundException();
        }
        parse_str($request->body, $fields);
        $mode = (string) ($fields['mode'] ?? MaintenanceTask::CHECK);
        // A run comes back, with its report, to the tab whose card started it
        $home = AdminTabs::taskRoute($task);
        if ($mode === MaintenanceTask::APPLY && ($fields['confirm'] ?? '') !== '1') {
            return $this->refused($request, $principal, $task, t('admin.maint.err_confirm'), 'confirm');
        }

        // A full pass over every page; don't let a default 30 s limit cut it off
        set_time_limit(300);
        try {
            $begun = $this->runner->begin($task, $mode, $principal->username, $fields, placeholder: $this->detach);
        } catch (MaintenanceBusyException) {
            return Response::redirect($request->basePath . $home . '?busy=1');
        } catch (InvalidArgumentException $e) {
            return $this->refused($request, $principal, $task, $e->getMessage());
        }
        if ($begun['id'] === null) {
            $run = $this->runner->complete($begun, $request);

            return Response::redirect($request->basePath . $home . '?run=' . $run['id'] . '#report');
        }

        // The redirect goes out first; the run carries on after it (the report page shows it running)
        $redirect = Response::redirect($request->basePath . $home . '?run=' . $begun['id'] . '#report');

        return new Response($redirect->status, '', $redirect->headers, function () use ($begun, $request): void {
            ignore_user_abort(true);
            if (\function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            $this->runner->complete($begun, $request);
        });
    }

    /** GET /admin/maintenance/runs/{id}.json: a stored report as it is kept, for tools */
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

    /**
     * GET /admin/maintenance/backups/{file} (D44): a backup archive from
     * data/backups/, streamed, never read into memory. Owner-only; anything
     * that is not one of the archives listed there is a 404. The archive
     * holds patient data: no caching, audited `backup.download` with its
     * size: never a path (invariant 8).
     */
    public function download(Request $request, string $file, ?User $principal): Response
    {
        if ($principal?->isOwner !== true || $this->backups === null || preg_match(BackupService::FILE_PATTERN, $file) !== 1) {
            throw new PageNotFoundException();
        }
        $path = $this->backups->directory() . '/' . $file;
        $size = is_file($path) && !is_link($path) ? filesize($path) : false;
        if ($size === false) {
            throw new PageNotFoundException();
        }
        $this->audit?->record('backup.download', $principal->username, $request, extra: ['bytes' => $size]);

        return new Response(200, '', [
            'Content-Type' => 'application/gzip',
            'Content-Disposition' => 'attachment; filename="' . $file . '"',
            'Content-Length' => (string) $size,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], static function () use ($path): void {
            $in = fopen($path, 'rb');
            if ($in === false) {
                return;
            }
            while (!feof($in)) {
                $chunk = fread($in, 1 << 20);
                if ($chunk === false) {
                    break;
                }
                echo $chunk;
                flush();
            }
            fclose($in);
        });
    }

    /**
     * An unfinished run: still running (true), or one that stopped without
     * its report (false). FPM ended it, or the server went down: past an
     * hour, longer than any limit a run gets
     */
    public static function stillRunning(MaintenanceReport $report): bool
    {
        $started = strtotime($report->started);

        return $started !== false && time() - $started < self::UNFINISHED_AFTER;
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
