<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Audit\AuditLog;
use Reporion\Exception\GuideException;
use Reporion\Service\Guide\Guide;

/**
 * bin/reporion guide:load <file.csv> [--source=<text>] [--actor=<u>] [--dry-run] [--json]: the imaging
 * guide (D62) from GIRI's GHID.csv: checked (the 13 columns, the legends, no CNP), kept as
 * data/guide/guide.csv with guide.json, and the index's guide tables filled. Audited `guide.load`
 * with the sha and the counts. Run as the web server's user on a live install.
 */
final class GuideLoadCommand implements CommandInterface
{
    public function __construct(
        private readonly Guide $guide,
        private readonly AuditLog $audit,
    ) {
    }

    public static function help(): CommandHelp
    {
        return new CommandHelp(
            summary: 'Loads the imaging guide (Ghid de indicații radioimagistice) from its CSV and makes it searchable.',
            usage: '<file.csv> [--source=<text>] [--actor=<username>] [--dry-run] [--json]',
            options: [
                '<file.csv>' => 'the guide\'s CSV, as GIRI keeps it (GHID.csv)',
                '--source=<text>' => 'where it came from, kept beside it (e.g. GIRI@e797896)',
                '--actor=<username>' => 'who loaded it (default: cli)',
                '--dry-run' => 'read and check the file; keep nothing',
                '--json' => 'print the result as JSON',
            ],
            details: 'The file must have the guide\'s 13 columns by name (NR.CRT … Terapeutic); a type, indication or grade outside the legends is kept and listed as a warning. It replaces the guide loaded before; index:rebuild fills the search tables again from the kept file.',
        );
    }

    public function run(array $args, Output $output): int
    {
        $files = [];
        $source = '';
        $actor = 'cli';
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--source=')) {
                $source = substr($arg, 9);
            } elseif (str_starts_with($arg, '--actor=') && \strlen($arg) > 8) {
                $actor = substr($arg, 8);
            } elseif (!str_starts_with($arg, '--')) {
                $files[] = $arg;
            } elseif (!\in_array($arg, ['--dry-run', '--json'], true)) {
                $output->error('Unknown option ' . $arg);

                return 1;
            }
        }
        $json = \in_array('--json', $args, true);
        $dryRun = \in_array('--dry-run', $args, true);
        if (\count($files) !== 1) {
            $output->error('Usage: bin/reporion guide:load ' . self::help()->usage);

            return 1;
        }
        if (!$json) {
            $output->line(($dryRun ? 'Checking ' : 'Loading the imaging guide from ') . basename($files[0]) . ($dryRun ? ' (dry run, nothing kept)' : '') . ':');
        }
        $bytes = is_file($files[0]) && is_readable($files[0]) ? file_get_contents($files[0]) : false;
        if ($bytes === false) {
            $output->error('Cannot read ' . $files[0]);

            return 1;
        }
        try {
            $result = $this->guide->load($bytes, $source, $actor, $dryRun);
        } catch (GuideException $e) {
            $json ? $output->line((string) json_encode(['error' => $e->reason, 'detail' => $e->getMessage()])) : $output->error('Not the guide: ' . $e->getMessage());

            return 1;
        }
        if (!$dryRun) {
            $this->audit->record('guide.load', $actor, extra: ['sha256' => $result['sha256'], 'rows' => $result['rows'], 'situations' => $result['situations']]);
        }
        if ($json) {
            $output->line((string) json_encode($result + ['dry_run' => $dryRun], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($result['warnings'] as $warning) {
                $output->line('  warning: ' . $warning);
            }
            $output->line(\sprintf('%d rows, %d situations%s%s', $result['rows'], $result['situations'], $result['unchanged'] ? ' (the same file as loaded)' : '', $dryRun ? '' : '; loaded'));
        }

        return 0;
    }
}
