<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Storage\StorageInterface;

/**
 * POST /admin/trash/{pid}/restore, owner-only (decided 2026-09-26): puts a
 * deleted page back. The trash is listed on Admin → Data
 * (AdminDataController); GET /admin/trash redirects there. Purging stays a scheduled CLI job
 * (bin/reporion trash:purge), with the D3b override for signed pages.
 * Paths are shown on this screen only: never logged (invariant 8).
 */
final class AdminTrashController
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly AuditLog $audit,
    ) {
    }

    /** GET /admin/trash: the old address of the trash, now a panel of Admin → Data */
    public function show(Request $request, ?User $principal): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }

        return Response::redirect($request->basePath . '/admin/data#trash', 301);
    }

    public function restore(Request $request, string $pid, ?User $principal): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }

        $record = $this->storage->restore($pid, $principal->username);
        $this->audit->record('page.restore', $principal->username, $request, $record->pid, $record->path, $record->rev);

        return Response::redirect($request->basePath . '/' . $record->path);
    }
}
