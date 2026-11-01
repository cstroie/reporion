<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Service\Maintenance\MaintenanceRunner;

/**
 * bin/reporion library:abstracts: PagesSummarizeCommand for the abstract page of each library
 * source that has none (Service\Maintenance\LibraryAbstractsTask, 2026-11-01).
 */
final class LibraryAbstractsCommand extends PagesSummarizeCommand
{
    public function __construct(MaintenanceRunner $runner)
    {
        parent::__construct($runner, 'library:abstracts');
    }

    public static function help(): CommandHelp
    {
        return new CommandHelp(
            summary: "Makes the abstract page (library:{key}:abstract) of each library source that has none: the record's abstract, else PubMed's or CrossRef's for its DOI or PMID when the lookups are on, else the article's own when its text marks it plainly. The short analyses (summary, problem-idea-evidence, key points) read it instead of the whole article.",
            usage: '[--offline] [--dry-run] [--limit=<n>] [--actor=<username>] [--json]',
            options: [
                '--offline' => 'do not ask PubMed or CrossRef, even with the lookups on',
                '--dry-run' => 'say what would be made; write nothing (the lookups are still asked, by identifier only)',
                '--limit=<n>' => 'make at most n pages per run (the next run carries on)',
                '--actor=<username>' => 'on whose behalf it runs (audit, run report); default: cli. The revisions are system:librarian\'s (D41)',
                '--json' => 'print the run report as JSON',
            ],
            details: 'No AI. Only a source\'s DOI or PMID is sent, never its text. An abstract page there already, or a page at that path that is not one, is left as it is.',
        );
    }
}
