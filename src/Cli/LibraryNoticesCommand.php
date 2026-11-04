<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Service\Maintenance\MaintenanceRunner;

/**
 * bin/reporion library:notices: PagesSummarizeCommand for the retraction check of the library's
 * sources (Service\Maintenance\LibraryNoticesTask, 2026-11-04).
 */
final class LibraryNoticesCommand extends PagesSummarizeCommand
{
    public function __construct(MaintenanceRunner $runner)
    {
        parent::__construct($runner, 'library:notices');
    }

    public static function help(): CommandHelp
    {
        return new CommandHelp(
            summary: 'Asks CrossRef and PubMed, by each library source\'s DOI or PMID, whether the article was retracted, put under an expression of concern or corrected, and keeps the answer as the source\'s notice (a banner on its card, a mark on its citations). A source the services no longer flag loses it.',
            usage: '[--all] [--dry-run] [--limit=<n>] [--actor=<username>] [--json]',
            options: [
                '--all' => 'ask about every source, also those asked within the last 30 days',
                '--dry-run' => 'say what would change; write nothing (the services are still asked, by identifier only)',
                '--limit=<n>' => 'ask about at most n sources per run, which bounds the requests (a source asked within 30 days is passed over, so the next run carries on)',
                '--actor=<username>' => 'on whose behalf it runs (audit, run report); default: cli. The revisions are system:librarian\'s (D41)',
                '--json' => 'print the run report as JSON',
            ],
            details: 'Needs the online lookups on (Admin → Settings → Library). Only a source\'s DOI or PMID is sent, never its text. A source whose notice is unchanged gets no revision. No AI.',
        );
    }
}
