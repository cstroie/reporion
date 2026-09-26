<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use InvalidArgumentException;
use Reporion\Exception\PageNotFoundException;
use Reporion\Service\PageMoves;

/**
 * bin/reporion page:move <from> <to> [--actor=<username>]
 *
 * Service\PageMoves — the same move as the page's Move… action: redirect
 * stub at the old path, links in unsigned pages updated, everything
 * audited. The actor defaults to "cli". Run it as the web server's user
 * (sudo -u www-data) on a live install, like every write command.
 */
final class PageMoveCommand implements CommandInterface
{
    public function __construct(private readonly PageMoves $moves)
    {
    }

    public function run(array $args, Output $output): int
    {
        $positional = array_values(array_filter($args, static fn (string $arg): bool => !str_starts_with($arg, '--')));
        $actor = 'cli';
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--actor=') && \strlen($arg) > 8) {
                $actor = substr($arg, 8);
            }
        }
        if (\count($positional) !== 2) {
            $output->error('Usage: bin/reporion page:move <from> <to> [--actor=<username>]');

            return 1;
        }

        try {
            $result = $this->moves->move($positional[0], $positional[1], $actor);
        } catch (PageNotFoundException) {
            $output->error('No page at ' . $positional[0]);

            return 1;
        } catch (InvalidArgumentException $e) {
            $output->error($e->getMessage());

            return 1;
        }

        $output->line(\sprintf(
            'moved to %s — links updated in %d page(s), %d signed page(s) left to the redirect',
            $result['moved']->path,
            \count($result['fixed']),
            $result['skippedSigned']
        ));

        return 0;
    }
}
