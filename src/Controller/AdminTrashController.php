<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use DateTimeImmutable;
use Exception;
use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Storage\StorageInterface;

/**
 * GET /admin/trash and POST /admin/trash/{pid}/restore — Admin → Trash,
 * owner-only (decided 2026-09-26). Lists deleted pages with who deleted
 * them and when, and puts one back. Purging stays a scheduled CLI job
 * (bin/reporion trash:purge), with the D3b override for signed pages.
 * Paths are shown on this screen only — never logged (invariant 8).
 */
final class AdminTrashController
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly IndexInterface $index,
        private readonly AuditLog $audit,
        private readonly int $purgeDays,
    ) {
    }

    public function show(Request $request, ?User $principal): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }

        $entries = array_map(fn (array $entry): array => $entry + ['daysLeft' => $this->daysLeft($entry['deletedAt'])], $this->storage->trash());

        return Response::html(View::page(\dirname(__DIR__, 2) . '/templates/admin-trash.php', [
            'entries' => $entries,
            'purgeDays' => $this->purgeDays,
            'adminTab' => 'trash',
            'basePath' => $request->basePath,
        ] + ChromeVars::shell($request, $principal, $this->index, ''), t('admin.trash.title')));
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

    private function daysLeft(?string $deletedAt): ?int
    {
        if ($deletedAt === null || $deletedAt === '') {
            return null;
        }
        try {
            $age = (new DateTimeImmutable($deletedAt))->diff(new DateTimeImmutable('now'))->days;
        } catch (Exception) {
            return null;
        }

        return max(0, $this->purgeDays - (int) $age);
    }
}
