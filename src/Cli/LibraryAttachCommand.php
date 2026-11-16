<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Exception\LibraryException;
use Reporion\Exception\PageNotFoundException;
use Reporion\Service\Library\Library;

/**
 * bin/reporion library:attach <key> <file> [--actor=<u>] [--dry-run] [--json]: a file for a library
 * source that has only its record (Library::attachFile(), 2026-11-16), read like an upload (the
 * patient guard, OCR) and attached; the key, tags and record kept. For a source with no DOI or PMID,
 * which an upload cannot recognise. Audited `library.attach` as the actor (default `cli`); run as
 * the web server's user on a live install.
 */
final class LibraryAttachCommand implements CommandInterface
{
    public function __construct(
        private readonly Library $library,
        private readonly AuditLog $audit,
    ) {
    }

    public static function help(): CommandHelp
    {
        return new CommandHelp(
            summary: 'Attaches a file (PDF, EPUB, Word…) to a library source that has only its record.',
            usage: '<key> <file> [--actor=<username>] [--dry-run] [--json]',
            options: [
                '<key>' => 'the source\'s citation key (library:{key})',
                '<file>' => 'the article\'s file, read as an upload is',
                '--actor=<username>' => 'attribute the revision to this user (default: cli)',
                '--dry-run' => 'read the file and check it; keep nothing',
                '--json' => 'print the result as JSON',
            ],
            details: 'The source keeps its key, tags and record; the file\'s text becomes its text, and a DOI or PMID the file names fills a blank. Refused when the source has a file, when the file names another DOI or PMID, holds a patient\'s CNP, or is in the library under another source (merge them with library:merge). Nothing is sent anywhere.',
        );
    }

    public function run(array $args, Output $output): int
    {
        $positional = [];
        $actor = 'cli';
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--actor=') && \strlen($arg) > 8) {
                $actor = substr($arg, 8);
            } elseif (!str_starts_with($arg, '--')) {
                $positional[] = $arg;
            } elseif (!\in_array($arg, ['--dry-run', '--json'], true)) {
                $output->error('Unknown option ' . $arg);

                return 1;
            }
        }
        $json = \in_array('--json', $args, true);
        $dryRun = \in_array('--dry-run', $args, true);
        if (\count($positional) !== 2) {
            $output->error('Usage: bin/reporion library:attach ' . self::help()->usage);

            return 1;
        }
        [$key, $file] = $positional;
        $path = $this->library->namespace() . ':' . $key;
        if (!$json) {
            $output->line(($dryRun ? 'Checking ' : 'Attaching ') . basename($file) . ' to ' . $path . ':' . ($dryRun ? ' (dry run, nothing kept)' : ''));
        }
        $bytes = is_file($file) && is_readable($file) ? file_get_contents($file) : false;
        if ($bytes === false) {
            $output->error('Cannot read ' . $file);

            return 1;
        }
        // The command line acts for the instance: it sees the whole library, as an owner does
        $principal = new User($actor, '', true, [], true, 'now', 'now');
        try {
            $page = $this->library->attachFile($path, $bytes, basename($file), $principal, $actor, $dryRun);
        } catch (LibraryException $e) {
            $result = ['key' => $key, 'status' => $e->reason] + ($e->pid !== null ? ['other_pid' => $e->pid] : []);
            $json ? $output->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) : $output->error($key . ': ' . t('library.err_' . $e->reason, $e->reason === LibraryException::TOO_LARGE ? [(string) intdiv($this->library->maxBytes(), 1024 * 1024)] : []));

            return 1;
        } catch (PageNotFoundException) {
            $output->error('No such source: ' . $path);

            return 1;
        }
        if (!$dryRun) {
            $original = (array) $page->frontmatter['original'];
            $this->audit->record('library.attach', $actor, null, $page->pid, $page->path, $page->rev, extra: [
                'sha256' => (string) ($original['sha256'] ?? ''), 'bytes' => (int) ($original['bytes'] ?? 0), 'ext' => (string) ($original['ext'] ?? ''),
            ]);
        }
        $result = ['key' => $key, 'status' => $dryRun ? 'would_attach' : 'attached', 'pid' => $page->pid, 'rev' => $page->rev];
        $output->line($json ? (string) json_encode($result + ['dry_run' => $dryRun], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $key . ': ' . $result['status'] . ($dryRun ? '' : ' (rev ' . $page->rev . ')'));

        return 0;
    }
}
