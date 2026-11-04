<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Exception\LibraryException;
use Reporion\Exception\PageNotFoundException;
use Reporion\Exception\RevisionConflictException;
use Reporion\Service\Library\Library;

/**
 * bin/reporion library:fulltext [<key>...] [--limit=<n>] [--actor=<u>] [--dry-run] [--json]: the free
 * PDF of the sources that have a DOI and no file (Library::findFullText(), D60): the DOI and the
 * library's e-mail address to Unpaywall, the PDF fetched with the web clip's safeguards and read like
 * an upload. Needs Admin → Settings → Library: the online lookups and the e-mail address.
 */
final class LibraryFulltextCommand implements CommandInterface
{
    public function __construct(
        private readonly Library $library,
        private readonly AuditLog $audit,
        // Between two sources, so a whole library is not a burst on Unpaywall and the publishers
        private readonly int $pauseMs = 500,
    ) {
    }

    public static function help(): CommandHelp
    {
        return new CommandHelp(
            summary: 'Finds the free PDF of library sources that have a DOI and no file, by Unpaywall, and attaches it.',
            usage: '[<key>...] [--limit=<n>] [--actor=<username>] [--dry-run] [--json]',
            options: [
                '<key>...' => 'the citation keys to try (default: every source with a DOI and no file)',
                '--limit=<n>' => 'try at most n sources per run (the next run carries on with those still without a file)',
                '--actor=<username>' => 'attribute the revisions to this user (default: cli)',
                '--dry-run' => 'ask Unpaywall and fetch the file, but keep nothing',
                '--json' => 'print the results as JSON',
            ],
            details: 'Only the DOI and the library\'s e-mail address go to Unpaywall; the PDF address it names is fetched with the web clip\'s safeguards (public addresses only, the size limit). A file that names another DOI, holds a patient\'s CNP or is the library\'s already is not kept. Needs the online lookups on and an e-mail address in Admin → Settings → Library.',
        );
    }

    public function run(array $args, Output $output): int
    {
        $keys = [];
        $actor = 'cli';
        $limit = 0;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--actor=') && \strlen($arg) > 8) {
                $actor = substr($arg, 8);
            } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m) === 1) {
                $limit = (int) $m[1];
            } elseif (!str_starts_with($arg, '--')) {
                $keys[] = $arg;
            } elseif (!\in_array($arg, ['--dry-run', '--json'], true)) {
                $output->error('Unknown option ' . $arg);

                return 1;
            }
        }
        if (!$this->library->fullTextOn()) {
            $output->error('Find full text is not set up: turn on the online lookups and give an e-mail address in Admin → Settings → Library.');

            return 1;
        }
        $json = \in_array('--json', $args, true);
        $dryRun = \in_array('--dry-run', $args, true);
        // The command line acts for the instance: it sees the whole library, as an owner does
        $principal = new User($actor, '', true, [], true, 'now', 'now');
        $records = array_values(array_filter(
            $this->library->records($principal, $keys !== [] ? $keys : null),
            static fn (array $r): bool => \is_string($r['csl']['DOI'] ?? null) && $r['csl']['DOI'] !== ''
        ));
        if (!$json) {
            $output->line(($dryRun ? 'Looking for the free PDF of ' : 'Finding and attaching the free PDF of ') . \count($records) . ' source(s) of ' . $this->library->namespace() . ':' . ($dryRun ? ' (dry run, nothing kept)' : ''));
        }
        $results = [];
        $tried = 0;
        $failed = 0;
        foreach ($records as $record) {
            if ($limit > 0 && $tried >= $limit) {
                break;
            }
            $path = $this->library->namespace() . ':' . $record['key'];
            try {
                $r = $this->library->findFullText($path, $principal, $actor, $dryRun);
            } catch (LibraryException $e) {
                $results[] = ['key' => $record['key'], 'status' => $e->reason];
                ++$failed;
                continue;
            } catch (RevisionConflictException|PageNotFoundException) {
                $results[] = ['key' => $record['key'], 'status' => 'conflict'];
                ++$failed;
                continue;
            }
            if ($r['status'] === 'has_file') {
                continue;
            }
            ++$tried;
            if ($r['status'] === 'attached' && !$dryRun) {
                $this->audit->record('library.fulltext', $actor, null, $r['pid'], $r['path'], $r['rev'], extra: ['status' => 'attached', 'host' => $r['host']]);
            }
            if ($r['status'] === 'failed') {
                ++$failed;
            }
            $results[] = ['key' => $record['key'], 'status' => $r['status'], 'host' => $r['host']];
            if ($this->pauseMs > 0) {
                usleep($this->pauseMs * 1000);
            }
        }
        if ($json) {
            $output->line((string) json_encode(['dry_run' => $dryRun, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($results as $r) {
                $output->line($r['key'] . ': ' . $r['status'] . (isset($r['host']) && $r['host'] !== null ? ' (' . $r['host'] . ')' : ''));
            }
            $output->line(\count(array_filter($results, static fn (array $r): bool => $r['status'] === 'attached')) . ' attached; ' . $failed . ' failed' . ($dryRun ? ' (dry run: nothing kept)' : ''));
        }

        return $failed > 0 ? 1 : 0;
    }
}
