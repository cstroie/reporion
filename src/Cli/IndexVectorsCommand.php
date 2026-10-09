<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Service\Maintenance\MaintenanceRunner;

/**
 * bin/reporion index:vectors — PagesSummarizeCommand for the embeddings of
 * Similar reports (Service\Maintenance\VectorsTask, phase 34e). It writes no
 * page, so it takes no --actor.
 */
final class IndexVectorsCommand extends PagesSummarizeCommand
{
    public function __construct(MaintenanceRunner $runner)
    {
        parent::__construct($runner, 'index:vectors', false);
    }

    public static function help(): CommandHelp
    {
        return new CommandHelp(
            summary: "Embeds each report's de-identified conclusion for Similar reports into page_vectors. It is a cache: unchanged reports are skipped.",
            usage: '[--dry-run] [--limit=<n>] [--force] [--json]',
            options: [
                '--dry-run' => 'count what would be embedded; send nothing',
                '--limit=<n>' => 'at most n per run (the next run carries on)',
                '--force' => 'embed every report again, including the current ones',
                '--json' => 'print the run report as JSON',
            ],
            details: 'Writes no page. Uses the one embedding model set under Admin → AI.',
        );
    }
}
