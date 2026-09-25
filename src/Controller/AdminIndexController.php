<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Service\IndexMaintenance;

/**
 * GET /admin/index and POST /admin/index/rebuild — Admin → Index & storage
 * (design/mockup/WikiAdmin.dc.html's "index" tab), owner-only. The status
 * and the rebuild are Service\IndexMaintenance, the same code as
 * `bin/reporion index:verify|index:rebuild` — run here by the web server's
 * own user, so the index files keep the ownership PHP-FPM needs.
 */
final class AdminIndexController
{
    public function __construct(
        private readonly IndexMaintenance $maintenance,
        private readonly IndexInterface $index,
        private readonly AuditLog $audit,
    ) {
    }

    public function show(Request $request, ?User $principal): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }

        $rebuilt = $request->query['rebuilt'] ?? null;

        return Response::html(View::page(\dirname(__DIR__, 2) . '/templates/admin-index.php', [
            'status' => $this->maintenance->status(),
            'drift' => $this->maintenance->verify(),
            'rebuilt' => \is_string($rebuilt) && ctype_digit($rebuilt) ? (int) $rebuilt : null,
            'adminTab' => 'index',
            'basePath' => $request->basePath,
        ] + ChromeVars::shell($request, $principal, $this->index, ''), t('admin.index.title')));
    }

    public function rebuild(Request $request, ?User $principal): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }

        // A full rebuild is < 60 s at the target size (CLAUDE.md); don't let
        // a default 30 s limit cut it off halfway through its transaction
        set_time_limit(300);
        $count = $this->maintenance->rebuild();
        $this->audit->record('index.rebuild', $principal->username, $request, extra: ['pages' => $count]);

        return Response::redirect($request->basePath . '/admin/index?rebuilt=' . $count);
    }
}
