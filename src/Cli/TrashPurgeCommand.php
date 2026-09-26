<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use DateTimeImmutable;
use Exception;
use Reporion\Audit\AuditLog;
use Reporion\Storage\FlatFile;

/**
 * bin/reporion trash:purge [--older-than=30d] [--include-signed --operator=<username>] [--dry-run]
 *
 * Permanently removes pages deleted more than N days ago (default:
 * pages.trash_purge_days). Signed pages are kept and reported unless the
 * D3b override is given — --include-signed together with --operator,
 * the person taking responsibility, who is named in the audit line.
 * Meant for a daily cron as the web server's user.
 */
final class TrashPurgeCommand implements CommandInterface
{
    public function __construct(
        private readonly FlatFile $storage,
        private readonly AuditLog $audit,
        private readonly int $defaultDays,
    ) {
    }

    public function run(array $args, Output $output): int
    {
        $days = $this->defaultDays;
        $operator = null;
        $includeSigned = \in_array('--include-signed', $args, true);
        $dryRun = \in_array('--dry-run', $args, true);
        foreach ($args as $arg) {
            if (preg_match('/^--older-than=(\d+)d?$/', $arg, $m) === 1) {
                $days = (int) $m[1];
            } elseif (str_starts_with($arg, '--operator=') && \strlen($arg) > 11) {
                $operator = substr($arg, 11);
            }
        }
        if ($includeSigned && $operator === null) {
            $output->error('--include-signed needs --operator=<username>: purging signed content is an explicit, named override (D3b)');

            return 1;
        }

        $cutoff = new DateTimeImmutable('-' . $days . ' days');
        $purged = 0;
        $keptSigned = 0;
        foreach ($this->storage->trash() as $entry) {
            try {
                $deletedAt = $entry['deletedAt'] !== null ? new DateTimeImmutable($entry['deletedAt']) : null;
            } catch (Exception) {
                $deletedAt = null;
            }
            if ($deletedAt === null || $deletedAt > $cutoff) {
                continue;
            }
            if ($entry['signed'] && !$includeSigned) {
                ++$keptSigned;
                continue;
            }
            if (!$dryRun) {
                $actor = $operator ?? 'cli';
                $this->storage->purge($entry['pid'], $actor, $includeSigned);
                $this->audit->record('page.purge', $actor, null, $entry['pid'], $entry['path'], null, extra: ['signed' => $entry['signed']]);
            }
            ++$purged;
        }

        $output->line(\sprintf(
            '%s %d page(s) deleted over %d day(s) ago; %d signed page(s) kept (D3b)',
            $dryRun ? 'would purge' : 'purged',
            $purged,
            $days,
            $keptSigned
        ));

        return 0;
    }
}
