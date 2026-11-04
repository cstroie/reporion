<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Auth\User;
use Reporion\Service\Library\Library;

/**
 * bin/reporion library:duplicates [--json]: the library's sources that look like one article (a
 * shared DOI, PMID, original file, or title and year), group by group, the one to keep first.
 * Reads only; library:merge folds one into another.
 */
final class LibraryDuplicatesCommand implements CommandInterface
{
    public function __construct(private readonly Library $library)
    {
    }

    public static function help(): CommandHelp
    {
        return new CommandHelp(
            summary: 'Lists the library\'s sources that look like one article, group by group.',
            usage: '[--json]',
            options: ['--json' => 'print the groups as JSON'],
            details: 'A group shares a DOI, a PMID, an original file, or a title and year; the source cited most (then the one with the file) comes first, the usual one to keep. Reads only: bin/reporion library:merge <keep> <from> folds one into another.',
        );
    }

    public function run(array $args, Output $output): int
    {
        $json = \in_array('--json', $args, true);
        if (array_diff($args, ['--json']) !== []) {
            $output->error('Unknown option ' . implode(' ', array_diff($args, ['--json'])));

            return 1;
        }
        $groups = $this->library->duplicateGroups(new User('cli', '', true, [], true, 'now', 'now'));
        if ($json) {
            $output->line((string) json_encode(['groups' => $groups], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return 0;
        }
        $output->line('Looking for duplicates in ' . $this->library->namespace() . ': ' . \count($groups) . ' group(s)');
        foreach ($groups as $group) {
            $output->line('same ' . implode(' and ', $group['by']) . ': ' . implode(', ', array_map(static fn (array $s): string => $s['key'] . ($s['cited'] > 0 ? ' (cited ' . $s['cited'] . ')' : '') . ($s['has_original'] ? ' +file' : ''), $group['sources'])));
        }

        return 0;
    }
}
