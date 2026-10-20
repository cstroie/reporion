<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Closure;
use Reporion\Storage\StateRecovery;

/**
 * bin/reporion db:recover [--dry-run] [--json] (D54 §10): data/reporion.sqlite
 * made to say what the attic zips say: a page row for every zip that has
 * none, the path and the revision and signature mirror put right for the
 * rest. After a lost state database (with no snapshot to restore) or a
 * `mirror_drift` from integrity:verify. Counts only, never a path
 * (invariant 8). CLI only: run it with nothing else writing.
 */
final class DbRecoverCommand implements CommandInterface
{
    /** @param Closure(): StateRecovery $recovery built only when the command runs */
    public function __construct(private readonly Closure $recovery)
    {
    }

    public static function help(): CommandHelp
    {
        return new CommandHelp(
            summary: 'Rebuilds the state database\'s page rows, revisions and signatures from the attic zips. Removes nothing.',
            usage: '[--dry-run] [--json]',
            options: [
                '--dry-run' => 'count what would change, change nothing',
                '--json' => 'print the counts as JSON',
            ],
            details: 'Comments, attachment names, moves and redirects, deliveries and drafts live only in the database: restore its snapshot first if you have one. Then bin/reporion index:rebuild.',
        );
    }

    public function run(array $args, Output $output): int
    {
        $dryRun = \in_array('--dry-run', $args, true);
        $json = \in_array('--json', $args, true);
        if (!$json) {
            $output->line($dryRun ? 'checking the state database against the attic zips (dry run) …' : 'recovering the state database from the attic zips …');
        }
        $counts = ($this->recovery)()->run($dryRun);
        if ($json) {
            $output->line((string) json_encode(['dry_run' => $dryRun] + $counts));
        } else {
            $output->line(\sprintf(
                '%d zip(s): %d page row(s) %s, %d put right, %d unchanged (%d in the trash); %d unreadable, %d conflict(s); %d row(s) with no zip',
                $counts['zips'],
                $counts['created'],
                $dryRun ? 'to create' : 'created',
                $counts['repaired'],
                $counts['unchanged'],
                $counts['trashed'],
                $counts['unreadable'],
                $counts['conflicts'],
                $counts['rows_without_zip'],
            ));
            if (!$dryRun && $counts['created'] + $counts['repaired'] > 0) {
                $output->line('now: bin/reporion index:rebuild');
            }
        }

        return $counts['unreadable'] + $counts['conflicts'] > 0 ? 1 : 0;
    }
}
