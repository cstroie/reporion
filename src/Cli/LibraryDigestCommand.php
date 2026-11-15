<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Service\Maintenance\MaintenanceRunner;

/**
 * bin/reporion library:digest: PagesSummarizeCommand for the assistant's one-line summary and key
 * points of each library source that lacks them (Service\Maintenance\LibraryDigestTask, 2026-11-15).
 */
final class LibraryDigestCommand extends PagesSummarizeCommand
{
    public function __construct(MaintenanceRunner $runner)
    {
        parent::__construct($runner, 'library:digest');
    }

    public static function help(): CommandHelp
    {
        return new CommandHelp(
            summary: "Gives each library source its overview: the assistant's one-line summary (into the page's `summary`) and its key points (kept as library:{key}:source-points), for the sources that lack one or the other. Read from the source's abstract page when it has one, else from its text.",
            usage: '[--dry-run] [--limit=<n>] [--actor=<username>] [--json]',
            options: [
                '--dry-run' => 'say what would be asked; send nothing, write nothing',
                '--limit=<n>' => 'ask about at most n sources per run (the next run carries on: what is made is not made again)',
                '--actor=<username>' => 'on whose behalf it runs (audit, run report); default: cli. The revisions are system:librarian\'s (D41)',
                '--json' => 'print the run report as JSON',
            ],
            details: 'Needs the assistant and the `summary` and `source-points` prompt pages of the profile serving the library (ai:prompts-install --target=research). A source with no text and no abstract, or too long to condense, is passed over; a server that does not answer stops the run. Run it from cron after articles are added, e.g. hourly with --limit=20.',
        );
    }
}
