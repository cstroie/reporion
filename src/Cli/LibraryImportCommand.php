<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Exception\LibraryException;
use Reporion\Service\Library\Library;

/**
 * bin/reporion library:import <file>... [--tags=<a,b>] [--actor=<u>] [--dry-run] [--json]
 *
 * Records from a reference manager's export (BibTeX, RIS, CSL-JSON: Mendeley, Zotero, EndNote)
 * added to the library as sources with no file (docs/library.md); a later upload of the same DOI
 * or PMID takes the file. Audited `library.import` as the actor (default `cli`); run as the web
 * server's user on a live install.
 */
final class LibraryImportCommand implements CommandInterface
{
    public function __construct(
        private readonly Library $library,
        private readonly AuditLog $audit,
    ) {
    }

    public static function help(): CommandHelp
    {
        return new CommandHelp(
            summary: 'Adds the records of a BibTeX, RIS or CSL-JSON export to the library, as sources with no file.',
            usage: '<file>... [--tags=<a,b>] [--actor=<username>] [--dry-run] [--json]',
            options: [
                '--tags=<a,b>' => 'tags for every record added',
                '--actor=<username>' => 'attribute the pages to this user (default: cli)',
                '--dry-run' => 'read the files and print what would be added; keep nothing',
                '--json' => 'print the results as JSON',
            ],
            details: 'A record in the library already (DOI, PMID, or title and year), one with no title and one holding a CNP are skipped. At most 500 records a file.',
        );
    }

    public function run(array $args, Output $output): int
    {
        $options = [];
        $files = [];
        foreach ($args as $arg) {
            if (preg_match('/^--(tags|actor)=(.*)$/', $arg, $m) === 1) {
                $options[$m[1]] = $m[2];
            } elseif ($arg === '--dry-run' || $arg === '--json') {
                $options[substr($arg, 2)] = true;
            } elseif (!str_starts_with($arg, '--')) {
                $files[] = $arg;
            } else {
                $output->error('Unknown option ' . $arg);

                return 1;
            }
        }
        if ($files === []) {
            $output->error('Usage: bin/reporion library:import ' . self::help()->usage);

            return 1;
        }
        $dryRun = isset($options['dry-run']);
        $json = isset($options['json']);
        $actor = \is_string($options['actor'] ?? null) && $options['actor'] !== '' ? $options['actor'] : 'cli';
        $tags = isset($options['tags']) ? array_values(array_filter(array_map('trim', explode(',', (string) $options['tags'])))) : null;
        $principal = new User($actor, '', true, [], true, 'now', 'now');

        if (!$json) {
            $output->line(($dryRun ? 'Reading ' : 'Importing ') . \count($files) . ' file(s) into ' . $this->library->namespace() . ':' . ($dryRun ? ' (dry run, nothing kept)' : ''));
        }
        $results = [];
        $failed = 0;
        foreach ($files as $file) {
            $text = is_file($file) ? @file_get_contents($file) : false;
            if ($text === false) {
                $results[] = ['file' => $file, 'outcome' => 'unreadable'];
                ++$failed;
                continue;
            }
            try {
                $result = $this->library->importRecords($text, $principal, $actor, $tags, $dryRun);
                if (!$dryRun) {
                    $this->audit->record('library.import', $actor, null, extra: ['added' => \count($result['added']), 'skipped' => \count($result['skipped'])]);
                }
                $results[] = ['file' => $file, 'outcome' => $dryRun ? 'would_add' : 'imported'] + $result;
            } catch (LibraryException $e) {
                $results[] = ['file' => $file, 'outcome' => $e->reason];
                ++$failed;
            }
        }

        if ($json) {
            $output->line((string) json_encode(['dry_run' => $dryRun, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($results as $r) {
                if (isset($r['added'])) {
                    $output->line('  ' . $r['file'] . ': ' . ($dryRun ? 'would add ' : 'added ') . \count($r['added']) . ', skipped ' . \count($r['skipped']));
                    foreach ($r['added'] as $a) {
                        $output->line('    ' . $a['path'] . ' "' . $a['title'] . '"');
                    }
                } else {
                    $output->line('  skipped ' . $r['file'] . ': ' . ($r['outcome'] === 'unreadable' ? 'cannot read the file' : t('library.err_' . $r['outcome'], $r['outcome'] === LibraryException::TOO_LARGE ? [(string) \Reporion\Service\Library\Library::MAX_IMPORT] : [])));
                }
            }
        }

        return $failed > 0 ? 1 : 0;
    }
}
