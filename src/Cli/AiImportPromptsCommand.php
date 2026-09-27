<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Exception\PageNotFoundException;
use Reporion\Service\Ai\PromptImport;

/**
 * bin/reporion ai:import-prompts --from dokullm:profiles:reports --to ai:profiles:reports --actor=<u> [--dry-run] [--json]
 *
 * DokuLLM's prompt profile as assistant pages (Service\Ai\PromptImport):
 * creates what does not exist yet, skips what does, and lists the lines
 * still to review by hand (DokuWiki markup, the patient's name).
 */
final class AiImportPromptsCommand implements CommandInterface
{
    public function __construct(private readonly PromptImport $import)
    {
    }

    public function run(array $args, Output $output): int
    {
        $opt = [];
        // `--from=x` and `--from x` alike, as the other commands take them
        foreach ($args as $i => $arg) {
            if (preg_match('/^--(from|to|actor)=(.+)$/', $arg, $m) === 1) {
                $opt[$m[1]] = $m[2];
            } elseif (preg_match('/^--(from|to|actor)$/', $arg, $m) === 1 && isset($args[$i + 1]) && !str_starts_with($args[$i + 1], '--')) {
                $opt[$m[1]] = $args[$i + 1];
            }
        }
        $dryRun = \in_array('--dry-run', $args, true);
        if (!isset($opt['from'], $opt['to']) || (!$dryRun && !isset($opt['actor']))) {
            $output->error('Usage: bin/reporion ai:import-prompts --from dokullm:profiles:reports --to ai:profiles:reports --actor=<username> [--dry-run] [--json]');

            return 1;
        }
        try {
            $report = $this->import->run(trim($opt['from'], ':'), trim($opt['to'], ':'), $opt['actor'] ?? 'cli', $dryRun);
        } catch (PageNotFoundException) {
            $output->error('No profile page at ' . $opt['from'] . ' (nor ' . $opt['from'] . '-2)');

            return 1;
        }
        if (\in_array('--json', $args, true)) {
            $output->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return 0;
        }
        foreach ($report['created'] as $path) {
            $output->line(($dryRun ? 'would create ' : 'created ') . $path);
        }
        foreach ($report['skipped'] as $path) {
            $output->line('exists, skipped ' . $path);
        }
        if ($report['review'] !== []) {
            $output->line('');
            $output->line('To review by hand:');
            foreach ($report['review'] as $item) {
                $output->line(\sprintf('  %s:%d  %s', $item['page'], $item['line'], $item['text']));
            }
        }

        return 0;
    }
}
