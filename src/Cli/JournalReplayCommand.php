<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Storage\FlatFile;

/**
 * bin/reporion journal:replay [--min-age=60] [--dry-run]
 *
 * Finishes (or discards) writes a crash left half-done (invariant 7). The
 * front controller already does this on the first request after a crash;
 * this is the same recovery for an operator or a deploy script. Intents
 * younger than --min-age seconds are skipped — they may be writes still
 * running. Output names pages by pid, never by path (invariant 8).
 */
final class JournalReplayCommand implements CommandInterface
{
    public function __construct(private readonly FlatFile $storage)
    {
    }

    public function run(array $args, Output $output): int
    {
        $minAge = 60;
        foreach ($args as $arg) {
            if (preg_match('/^--min-age=(\d+)$/', $arg, $m) === 1) {
                $minAge = (int) $m[1];
            }
        }

        if (\in_array('--dry-run', $args, true)) {
            $intents = $this->storage->staleIntents($minAge);
            foreach ($intents as $intent) {
                $output->line(\sprintf(
                    '%s  %-8s pid %s rev %d',
                    (string) ($intent['ts'] ?? '?'),
                    (string) ($intent['op'] ?? '?'),
                    (string) ($intent['pid'] ?? '?'),
                    (int) ($intent['rev'] ?? 0),
                ));
            }
            $output->line(\sprintf('%d unfinished write(s) older than %ds would be replayed', \count($intents), $minAge));

            return 0;
        }

        $counts = [];
        foreach ($this->storage->replayJournal($minAge) as $outcome) {
            $output->line(\sprintf('%-10s pid %s rev %d', $outcome['outcome'], $outcome['pid'], $outcome['rev']));
            $counts[$outcome['outcome']] = ($counts[$outcome['outcome']] ?? 0) + 1;
        }
        ksort($counts);
        $summary = [];
        foreach ($counts as $outcome => $n) {
            $summary[] = $n . ' ' . $outcome;
        }
        $output->line($summary === [] ? 'nothing to replay' : 'replayed: ' . implode(', ', $summary));

        return isset($counts['corrupt']) ? 1 : 0;
    }
}
