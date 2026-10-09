<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Service\Maintenance\MaintenanceRunner;

/**
 * bin/reporion pacs:link — PluginTaskCommand for the dicom plugin's task
 * (plugins/dicom), with its own --help.
 */
final class PacsLinkCommand extends PluginTaskCommand
{
    public function __construct(MaintenanceRunner $runner)
    {
        parent::__construct($runner, 'pacs:link', ['site', 'limit']);
    }

    public static function help(): CommandHelp
    {
        return new CommandHelp(
            summary: "Links a site's unlinked reports to their PACS study, on unambiguous CNP or name-and-day matches only. Signed reports return to draft.",
            usage: '--site=<code> --actor=<username> [--dry-run] [--limit=<n>] [--json]',
            options: [
                '--site=<code>' => 'the site whose reports and PACS are used (required)',
                '--actor=<username>' => 'required unless --dry-run; attributed in the audit',
                '--dry-run' => 'list what would be linked; write nothing',
                '--limit=<n>' => 'at most n per run (the next run carries on)',
                '--json' => 'print the run report as JSON',
            ],
            details: 'dicom plugin. Retrieves no images.',
        );
    }
}
