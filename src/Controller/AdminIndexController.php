<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Exception\MaintenanceBusyException;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Service\IndexMaintenance;

/**
 * POST /admin/index/rebuild, from Admin → Data (AdminDataController shows
 * the index's status; GET /admin/index redirects there), owner-only. The status
 * and the rebuild are Service\IndexMaintenance, the same code as
 * `bin/reporion index:verify|index:rebuild`: run here by the web server's
 * own user, so the index files keep the ownership PHP-FPM needs.
 */
final class AdminIndexController
{
    public function __construct(
        private readonly IndexMaintenance $maintenance,
        private readonly AuditLog $audit,
    ) {
    }

    /** GET /admin/index: the old address of the index's screen, now Admin → Data */
    public function show(Request $request, ?User $principal): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }

        return Response::redirect($request->basePath . '/admin/data#health', 301);
    }

    public function rebuild(Request $request, ?User $principal): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }

        // A full rebuild is < 60 s at the target size (CLAUDE.md); don't let
        // a default 30 s limit cut it off halfway through its transaction
        set_time_limit(300);
        try {
            $count = $this->maintenance->rebuild();
        } catch (MaintenanceBusyException) {
            return Response::redirect($request->basePath . '/admin/data?busy=1#rebuild');
        }
        $this->audit->record('index.rebuild', $principal->username, $request, extra: ['pages' => $count]);

        return Response::redirect($request->basePath . '/admin/data?rebuilt=' . $count . '#rebuild');
    }
}
