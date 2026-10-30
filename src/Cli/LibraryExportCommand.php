<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Auth\User;
use Reporion\Service\Library\Library;
use Reporion\Support\Bibliography;
use Reporion\Support\Citation;

/**
 * bin/reporion library:export [--format=bibtex|ris|csl|text] [--style=vancouver|apa|ama] [--tag=<t>] [--to=<file>]
 *
 * The whole library's references (docs/library.md), for a reference manager or a manuscript; reads
 * only, the instance's view (an owner's). Without --to, the references are the output.
 */
final class LibraryExportCommand implements CommandInterface
{
    public function __construct(private readonly Library $library)
    {
    }

    public static function help(): CommandHelp
    {
        return new CommandHelp(
            summary: 'Writes the library\'s references as BibTeX, RIS, CSL-JSON or a formatted list.',
            usage: '[--format=bibtex|ris|csl|text] [--style=vancouver|apa|ama] [--tag=<tag>] [--to=<file>]',
            options: [
                '--format=<f>' => 'bibtex (default), ris, csl (CSL-JSON) or text (one formatted reference a line)',
                '--style=<s>' => 'for text: vancouver (default), apa or ama',
                '--tag=<tag>' => 'only the sources with this tag',
                '--to=<file>' => 'write to this file instead of the output (refused when it exists)',
            ],
            details: 'Reads the index and the pages; changes nothing. Without --to the references follow the first line, which says what is exported.',
        );
    }

    public function run(array $args, Output $output): int
    {
        $options = [];
        foreach ($args as $arg) {
            if (preg_match('/^--(format|style|tag|to)=(.*)$/', $arg, $m) !== 1) {
                $output->error('Unknown option ' . $arg);

                return 1;
            }
            $options[$m[1]] = $m[2];
        }
        $format = $options['format'] ?? 'bibtex';
        if (!\in_array($format, Bibliography::FORMATS, true)) {
            $output->error('Unknown format ' . $format);

            return 1;
        }
        $records = $this->library->records(new User('cli', '', true, [], true, 'now', 'now'), null, ($options['tag'] ?? '') !== '' ? $options['tag'] : null);
        $text = Bibliography::write($format, $records, Citation::style($options['style'] ?? null));
        $output->line('Exporting ' . \count($records) . ' reference(s) as ' . $format . (isset($options['to']) ? ' to ' . $options['to'] : ''));
        if (isset($options['to'])) {
            if (file_exists($options['to']) || @file_put_contents($options['to'], $text) === false) {
                $output->error('Cannot write ' . $options['to'] . ' (it exists, or is not writable)');

                return 1;
            }

            return 0;
        }
        $output->line(rtrim($text));

        return 0;
    }
}
