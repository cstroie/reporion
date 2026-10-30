<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Exception\LibraryException;
use Reporion\Service\Library\Library;

/**
 * bin/reporion library:add <file>... [--key=<key>] [--tags=<a,b>] [--actor=<u>] [--dry-run] [--json]
 *
 * Adds articles to the library (docs/library.md) with the record proposed for each, as the web
 * upload would before the writer corrects it: the page `library:{key}`, the original in
 * data/sources/. A file whose article is there already, or that holds a CNP, is skipped and said.
 * Audited `library.add` as the actor (default `cli`); run as the web server's user on a live install.
 */
final class LibraryAddCommand implements CommandInterface
{
    public function __construct(
        private readonly Library $library,
        private readonly AuditLog $audit,
    ) {
    }

    public static function help(): CommandHelp
    {
        return new CommandHelp(
            summary: 'Adds documents (pdf, docx, odt, rtf, html, epub, office files, txt, md) to the library, each with the record read from it.',
            usage: '<file>... [--key=<key>] [--tags=<a,b>] [--actor=<username>] [--dry-run] [--json]',
            options: [
                '--key=<key>' => 'the citation key (one file only); default: first author and year',
                '--tags=<a,b>' => 'tags for every file added',
                '--actor=<username>' => 'attribute the pages to this user (default: cli)',
                '--dry-run' => 'read each file and print the record it would get; keep nothing',
                '--json' => 'print the results as JSON',
            ],
            details: 'An article already in the library (same file, DOI or PMID) and a file holding a CNP are skipped. With Admin → Settings → Library on, a DOI or PMID found is sent to CrossRef and PubMed to complete the record (the identifier only); --dry-run sends nothing.',
        );
    }

    public function run(array $args, Output $output): int
    {
        $options = [];
        $files = [];
        foreach ($args as $arg) {
            if (preg_match('/^--(key|tags|actor)=(.*)$/', $arg, $m) === 1) {
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
        if ($files === [] || (isset($options['key']) && \count($files) > 1)) {
            $output->error('Usage: bin/reporion library:add ' . self::help()->usage . ($files !== [] ? ' (--key takes one file)' : ''));

            return 1;
        }
        $dryRun = isset($options['dry-run']);
        $json = isset($options['json']);
        $actor = \is_string($options['actor'] ?? null) && $options['actor'] !== '' ? $options['actor'] : 'cli';
        $fields = [
            'key' => $options['key'] ?? null,
            'tags' => isset($options['tags']) ? explode(',', (string) $options['tags']) : [],
        ];
        // The command line acts for the instance: it sees the whole library, as an owner does
        $principal = new User($actor, '', true, [], true, 'now', 'now');

        if (!$json) {
            $output->line(($dryRun ? 'Reading ' : 'Adding ') . \count($files) . ' file(s) to ' . $this->library->namespace() . ':' . ($dryRun ? ' (dry run, nothing kept)' : ''));
        }
        $results = [];
        $failed = 0;
        foreach ($files as $file) {
            $bytes = is_file($file) ? @file_get_contents($file) : false;
            if ($bytes === false) {
                $results[] = ['file' => $file, 'outcome' => 'unreadable'];
                ++$failed;
                continue;
            }
            try {
                if ($dryRun) {
                    $proposal = $this->library->propose($bytes, basename($file), $principal);
                    $results[] = ['file' => $file, 'outcome' => $proposal['duplicate'] !== null && $proposal['duplicate']['by'] !== 'title' ? 'duplicate' : 'would_add',
                        'key' => $fields['key'] ?? $proposal['key'], 'csl' => $proposal['csl'], 'how' => $proposal['how'], 'duplicate' => $proposal['duplicate'],
                        'pages' => $proposal['pages'], 'has_text' => $proposal['has_text']];
                    continue;
                }
                [$page, $csl] = $this->library->add($bytes, basename($file), $principal, $actor, $fields);
                $original = (array) $page->frontmatter['original'];
                $this->audit->record('library.add', $actor, null, $page->pid, $page->path, $page->rev, extra: [
                    'sha256' => (string) ($original['sha256'] ?? ''), 'bytes' => (int) ($original['bytes'] ?? 0), 'ext' => (string) ($original['ext'] ?? ''),
                ]);
                $results[] = ['file' => $file, 'outcome' => 'added', 'path' => $page->path, 'pid' => $page->pid, 'csl' => $csl, 'has_text' => (bool) ($original['text'] ?? false)];
            } catch (LibraryException $e) {
                $results[] = ['file' => $file, 'outcome' => $e->reason] + ($e->pid !== null ? ['pid' => $e->pid] : []);
                ++$failed;
            }
        }

        if ($json) {
            $output->line((string) json_encode(['dry_run' => $dryRun, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($results as $r) {
                $output->line($this->describe($r));
            }
        }

        return $failed > 0 ? 1 : 0;
    }

    /** @param array<string, mixed> $r */
    private function describe(array $r): string
    {
        $title = \is_string($r['csl']['title'] ?? null) ? $r['csl']['title'] : '';
        $authors = array_map(static fn (array $a): string => (string) ($a['family'] ?? $a['literal'] ?? ''), \is_array($r['csl']['author'] ?? null) ? $r['csl']['author'] : []);
        $year = $r['csl']['issued']['date-parts'][0][0] ?? '';
        $record = $title !== '' ? ' "' . $title . '"' . ($authors !== [] ? ', ' . implode(', ', \array_slice($authors, 0, 3)) . (\count($authors) > 3 ? ' et al.' : '') : '') . ($year !== '' ? ', ' . $year : '') : '';
        $scan = ($r['has_text'] ?? true) === false ? ' (no text: a scan?)' : '';

        return match ($r['outcome']) {
            'added' => '  added ' . $r['path'] . $record . $scan,
            'would_add' => '  would add ' . $r['key'] . $record . (isset($r['csl']['DOI']) ? ' doi:' . $r['csl']['DOI'] : '') . $scan,
            'duplicate' => '  skipped ' . $r['file'] . ': in the library already' . (isset($r['duplicate']['path']) ? ' as ' . $r['duplicate']['path'] : (isset($r['pid']) ? ' (' . $r['pid'] . ')' : '')),
            default => '  skipped ' . $r['file'] . ': ' . t('library.err_' . $r['outcome'], $r['outcome'] === LibraryException::TOO_LARGE ? [(string) intdiv($this->library->maxBytes(), 1024 * 1024)] : []),
        };
    }
}
