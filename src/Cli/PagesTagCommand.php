<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Service\Maintenance\MaintenanceRunner;

/**
 * bin/reporion pages:tag — PagesSummarizeCommand for the `tags` prompt
 * (Service\Maintenance\TagTask, 2026-10-08), with its own --help.
 */
final class PagesTagCommand extends PagesSummarizeCommand
{
    public function __construct(MaintenanceRunner $runner)
    {
        parent::__construct($runner, 'pages:tag');
    }

    public static function help(): CommandHelp
    {
        return new CommandHelp(
            summary: 'Writes 3 to 5 tags from the assistant for unsigned reports that have none, plus the RADS categories the conclusion states.',
            usage: '--actor=<username> [--dry-run] [--namespace=<ns>] [--limit=<n>] [--overwrite] [--json]',
            options: [
                '--actor=<username>' => 'required unless --dry-run; the new revisions are attributed to them',
                '--dry-run' => 'list what would be asked; send and write nothing',
                '--namespace=<ns>' => 'only the reports under this namespace',
                '--limit=<n>' => 'at most n per run (the next run carries on)',
                '--overwrite' => 'also replace the tags that are already there',
                '--json' => 'print the run report as JSON',
            ],
            details: 'Each report becomes one new revision. Signed reports are never touched. Output names pids only.',
        );
    }
}
