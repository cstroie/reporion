<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Maintenance;

use DateTimeImmutable;
use Exception;
use Reporion\Audit\AuditLog;
use Reporion\Storage\FlatFile;
use Reporion\Support\MetaText;

/**
 * trash:purge — permanently removes pages deleted more than older_than
 * days ago. Signed pages are kept unless include_signed, the D3b override,
 * in which case the actor running it is the named operator in the audit
 * line.
 */
final class TrashPurgeTask implements MaintenanceTask
{
    public function __construct(
        private readonly FlatFile $storage,
        private readonly AuditLog $audit,
        private readonly int $defaultDays,
    ) {
    }

    public function name(): string
    {
        return 'trash:purge';
    }

    public function modes(): array
    {
        return [self::CHECK, self::APPLY];
    }

    public function options(array $raw): array
    {
        $days = $raw['older_than'] ?? $this->defaultDays;

        return [
            'older_than' => is_numeric($days) && (int) $days >= 0 ? (int) $days : $this->defaultDays,
            'include_signed' => \in_array($raw['include_signed'] ?? false, [true, '1', 'on', 'yes'], true),
        ];
    }

    public function run(string $mode, string $actor, array $options): MaintenanceReport
    {
        $days = (int) $options['older_than'];
        $includeSigned = (bool) $options['include_signed'];
        $report = new MaintenanceReport($this->name(), $mode, $actor, $options, date('Y-m-d\TH:i:sP'));
        $report->count($mode === self::APPLY ? 'purged' : 'would_purge', 0);
        $report->count('kept_signed', 0);

        $cutoff = new DateTimeImmutable('-' . $days . ' days');
        foreach ($this->storage->trash() as $entry) {
            try {
                $deletedAt = $entry['deletedAt'] !== null ? new DateTimeImmutable($entry['deletedAt']) : null;
            } catch (Exception) {
                $deletedAt = null;
            }
            if ($deletedAt === null || $deletedAt > $cutoff) {
                continue;
            }
            $data = ['deleted_at' => $entry['deletedAt'], 'signed' => $entry['signed']];
            // Out of the index once deleted, so the screen cannot look it up: the exam
            // title (never the path — invariant 8) and when it went
            $detail = trim($entry['title'] . ' · ' . t('admin.maint.deleted', [MetaText::when($entry['deletedAt'])]), ' ·');
            if ($entry['signed'] && !$includeSigned) {
                $report->count('kept_signed');
                $report->item($entry['pid'], null, 'kept_signed', $detail, $data);
                continue;
            }
            if ($mode === self::APPLY) {
                $this->storage->purge($entry['pid'], $actor, $includeSigned);
                $this->audit->record('page.purge', $actor, null, $entry['pid'], $entry['path'], null, extra: ['signed' => $entry['signed']]);
                $report->count('purged');
                $report->item($entry['pid'], null, 'purged', $detail, $data);
            } else {
                $report->count('would_purge');
                $report->item($entry['pid'], null, 'would_purge', $detail, $data);
            }
        }

        return $report;
    }
}
