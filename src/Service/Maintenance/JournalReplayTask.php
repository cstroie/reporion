<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Maintenance;

use Reporion\Storage\FlatFile;

/**
 * journal:replay — finish or discard writes a crash left half-done
 * (invariant 7). Boot replay already handles new crashes; this is for the
 * backlog it deliberately leaves alone, and for an operator. Intents
 * younger than min_age seconds may be writes still running and are left.
 */
final class JournalReplayTask implements MaintenanceTask
{
    public function __construct(private readonly FlatFile $storage)
    {
    }

    public function name(): string
    {
        return 'journal:replay';
    }

    public function modes(): array
    {
        return [self::CHECK, self::APPLY];
    }

    public function options(array $raw): array
    {
        $minAge = $raw['min_age'] ?? 60;

        return ['min_age' => is_numeric($minAge) && (int) $minAge >= 0 ? (int) $minAge : 60];
    }

    public function run(string $mode, string $actor, array $options): MaintenanceReport
    {
        $minAge = (int) $options['min_age'];
        $report = new MaintenanceReport($this->name(), $mode, $actor, $options, date('Y-m-d\TH:i:sP'));

        if ($mode === self::CHECK) {
            $report->count('unfinished', 0);
            foreach ($this->storage->staleIntents($minAge) as $intent) {
                $report->item((string) ($intent['pid'] ?? ''), (int) ($intent['rev'] ?? 0), (string) ($intent['op'] ?? '?'), (string) ($intent['ts'] ?? ''), ['ts' => (string) ($intent['ts'] ?? '?')]);
                $report->count('unfinished');
            }

            return $report;
        }

        foreach ($this->storage->replayJournal($minAge) as $outcome) {
            $report->item($outcome['pid'], $outcome['rev'], $outcome['outcome']);
            $report->count($outcome['outcome']);
        }
        if (isset($report->summary()['corrupt'])) {
            $report->note('A revision file that does not decompress stays open for a human to look at.');
            $report->fail();
        }

        return $report;
    }
}
