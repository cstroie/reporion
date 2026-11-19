<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use DateTimeImmutable;
use Exception;
use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Service\Backup\BackupService;
use Reporion\Service\IndexMaintenance;
use Reporion\Storage\StorageInterface;

/**
 * GET /admin/data[?run={id}]: Admin → Data, owner-only. Everything about
 * where the pages are kept, on one screen: the pages and the index's
 * health with Rebuild (AdminIndexController::rebuild), the storage tasks
 * (index:verify, integrity:verify, journal:replay, pages:repair-paths), the
 * trash with its retention and purge (AdminTrashController::restore), the
 * rsync backup's settings and the archives (backup:rsync, backup:create),
 * the cron lines and the recent runs of every task. Paths are shown on
 * this screen only: never logged (invariant 8).
 */
final class AdminDataController
{
    public function __construct(
        private readonly IndexMaintenance $maintenance,
        private readonly StorageInterface $storage,
        private readonly IndexInterface $index,
        private readonly TaskPanels $tasks,
        private readonly SettingsPanels $settings,
        private readonly ?BackupService $backups,
        private readonly int $purgeDays,
    ) {
    }

    public function show(Request $request, ?User $principal, ?string $error = null, ?string $where = null): Response
    {
        if ($principal?->isOwner !== true) {
            throw new PageNotFoundException();
        }
        $maintError = $where === null ? $error : null;
        $rebuilt = $request->query['rebuilt'] ?? null;

        return Response::html(View::page(\dirname(__DIR__, 2) . '/templates/admin-data.php', [
            'status' => $this->maintenance->status(),
            'drift' => $this->maintenance->verify(),
            'rebuilt' => \is_string($rebuilt) && ctype_digit($rebuilt) ? (int) $rebuilt : null,
            'entries' => array_map(fn (array $entry): array => $entry + ['daysLeft' => $this->daysLeft($entry['deletedAt'])], $this->storage->trash()),
            'purgeDays' => $this->purgeDays,
            'backups' => $this->backups?->backups() ?? [],
            'recent' => $this->tasks->runner()->recent(15),
            'cronRoot' => \dirname(__DIR__, 2),
            'cronUser' => self::processUser(),
            'adminTab' => 'data',
            'basePath' => $request->basePath,
        ] + $this->tasks->vars($request, $principal, 'data', $maintError)
          + $this->settings->vars($request, $where !== null ? $error : null, $where)
          + ChromeVars::shell($request, $principal, $this->index, ''), t('admin.data.title')), $error === null ? 200 : 422);
    }

    /** The account PHP runs as: whose crontab the scheduled jobs belong in */
    private static function processUser(): string
    {
        if (\function_exists('posix_geteuid') && \function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());
            if (\is_array($info) && $info['name'] !== '') {
                return (string) $info['name'];
            }
        }

        return 'www-data';
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
