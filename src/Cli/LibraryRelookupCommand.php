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
 * bin/reporion library:relookup [<key>...] [--tag=<t>] [--all] [--actor=<u>] [--yes] [--dry-run] [--json]
 *
 * Asks CrossRef and PubMed again for sources already in the library, by their DOI or PMID (the
 * identifier only, never text), and **overwrites** each record's fields with what they return
 * (Library::relookup(): owner's choice, 2026-10-31). Because it replaces what a writer may have
 * corrected, it warns and refuses to write without --yes; --dry-run asks and shows what would change,
 * writes nothing. One new revision per source that changed, audited `page.save` as the actor
 * (default `cli`). Needs Admin → Settings → Library → online lookups on.
 */
final class LibraryRelookupCommand implements CommandInterface
{
    public function __construct(
        private readonly Library $library,
        private readonly AuditLog $audit,
        // Between two sources that were asked, so a whole library is not a burst on CrossRef
        private readonly int $pauseMs = 250,
    ) {
    }

    public static function help(): CommandHelp
    {
        return new CommandHelp(
            summary: 'Asks CrossRef and PubMed again for sources already in the library and overwrites their records with the answer.',
            usage: '[<key>...] [--tag=<t>] [--all] [--actor=<username>] [--yes] [--dry-run] [--json]',
            options: [
                '<key>...' => 'the citation keys to look up (the page names under library:)',
                '--tag=<t>' => 'every source with this tag',
                '--all' => 'every source the library holds',
                '--actor=<username>' => 'attribute the revisions to this user (default: cli)',
                '--yes' => 'go ahead and overwrite: without it nothing is written',
                '--dry-run' => 'ask, and print what would change; write nothing',
                '--json' => 'print the results as JSON',
            ],
            details: 'WARNING: the fields the lookup returns REPLACE the record\'s, including any corrected by hand; fields it does not return stay, and PubMed\'s MeSH terms are added to the tags, none removed. The earlier record stays in the page\'s history. Only the DOI or PMID is sent (the DOI\'s PMID is looked up too); a source with neither is skipped. Needs the online lookups on in Admin → Settings → Library.',
        );
    }

    public function run(array $args, Output $output): int
    {
        $keys = [];
        $options = [];
        foreach ($args as $arg) {
            if (preg_match('/^--(tag|actor)=(.*)$/', $arg, $m) === 1) {
                $options[$m[1]] = $m[2];
            } elseif (\in_array($arg, ['--all', '--yes', '--dry-run', '--json'], true)) {
                $options[substr($arg, 2)] = true;
            } elseif (!str_starts_with($arg, '--')) {
                $keys[] = $arg;
            } else {
                $output->error('Unknown option ' . $arg);

                return 1;
            }
        }
        $tag = \is_string($options['tag'] ?? null) && $options['tag'] !== '' ? $options['tag'] : null;
        $all = isset($options['all']);
        if ($keys === [] && $tag === null && !$all) {
            $output->error('Usage: bin/reporion library:relookup ' . self::help()->usage . ' (name keys, a --tag or --all)');

            return 1;
        }
        if (!$this->library->lookupOn()) {
            $output->error(t('library.err_lookup_off'));

            return 1;
        }
        $dryRun = isset($options['dry-run']);
        $json = isset($options['json']);
        $actor = \is_string($options['actor'] ?? null) && $options['actor'] !== '' ? $options['actor'] : 'cli';
        // The command line acts for the instance: it sees the whole library, as an owner does
        $principal = new User($actor, '', true, [], true, 'now', 'now');
        $records = $this->library->records($principal, $keys !== [] ? $keys : null, $tag);

        if (!$json) {
            $output->line(($dryRun ? 'Asking CrossRef and PubMed about ' : 'Looking up and overwriting ') . \count($records) . ' source(s) of ' . $this->library->namespace() . ':' . ($dryRun ? ' (dry run, nothing written)' : ''));
        }
        if (!$dryRun && !isset($options['yes'])) {
            $message = 'This OVERWRITES each record\'s fields with what CrossRef and PubMed return, including any you corrected by hand (the old record stays in the page\'s history). Nothing was done: run again with --yes to go ahead, or --dry-run to see what would change.';
            if ($json) {
                $output->line((string) json_encode(['error' => 'confirmation_needed', 'message' => $message], JSON_UNESCAPED_SLASHES));
            } else {
                $output->error($message);
            }

            return 1;
        }
        $missing = array_diff($keys, array_column($records, 'key'));
        $results = [];
        $failed = 0;
        foreach ($missing as $key) {
            $results[] = ['key' => $key, 'status' => 'not_found'];
            ++$failed;
        }
        foreach ($records as $record) {
            $key = $record['key'];
            try {
                $r = $this->library->relookup($this->library->namespace() . ':' . $key, $principal, $actor, $dryRun);
            } catch (LibraryException $e) {
                $results[] = ['key' => $key, 'status' => $e->reason];
                ++$failed;
                continue;
            } catch (RevisionConflictException | PageNotFoundException) {
                $results[] = ['key' => $key, 'status' => 'conflict'];
                ++$failed;
                continue;
            }
            if ($r['status'] === 'updated' && !$dryRun) {
                $this->audit->record('page.save', $actor, null, $r['pid'], $r['path'], $r['rev'], extra: ['lookup' => (string) $r['how'], 'fields' => \count($r['changed'])]);
            }
            if ($r['status'] === 'failed') {
                ++$failed;
            }
            $results[] = ['key' => $key, 'status' => $r['status'], 'how' => $r['how'], 'changed' => $r['changed'], 'tags' => $r['tags']];
            if ($r['status'] !== 'no_id' && $this->pauseMs > 0) {
                usleep($this->pauseMs * 1000);
            }
        }

        if ($json) {
            $output->line((string) json_encode(['dry_run' => $dryRun, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($results as $r) {
                $output->line($this->describe($r, $dryRun));
            }
        }

        return $failed > 0 ? 1 : 0;
    }

    /** @param array<string, mixed> $r */
    private function describe(array $r, bool $dryRun): string
    {
        $key = (string) $r['key'];

        return match ($r['status']) {
            'updated' => '  ' . ($dryRun ? 'would update ' : 'updated ') . $key . ': ' . implode(', ', (array) $r['changed']) . ((array) $r['tags'] !== [] ? ' + tags ' . implode(', ', (array) $r['tags']) : ''),
            'unchanged' => '  unchanged ' . $key,
            'no_id' => '  skipped ' . $key . ': no DOI or PMID',
            'none' => '  nothing found for ' . $key,
            'failed' => '  could not reach CrossRef or PubMed for ' . $key,
            'not_found' => '  skipped ' . $key . ': not a source of the library',
            default => '  skipped ' . $key . ': ' . t('library.err_' . $r['status'], []),
        };
    }
}
