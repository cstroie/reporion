<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use InvalidArgumentException;
use Reporion\Audit\AuditLog;
use Reporion\Exception\PageNotFoundException;
use Reporion\Service\Duplicates;
use Reporion\Storage\FlatFile;

/**
 * bin/reporion page:new <path> [--template=<path>] [--actor=<username>]
 *
 * Creates a page: from a template page (D19 — "new report" copies a page
 * under templates:, via Service\Duplicates, so no patient fields cross) or
 * from the empty scaffold. Private draft either way; audited as
 * page.create. The actor defaults to "cli"; run as the web server's user
 * on a live install.
 */
final class PageNewCommand implements CommandInterface
{
    public function __construct(
        private readonly FlatFile $storage,
        private readonly AuditLog $audit,
    ) {
    }

    public function run(array $args, Output $output): int
    {
        $options = [];
        $positional = [];
        foreach ($args as $arg) {
            if (preg_match('/^--(template|actor)=(.+)$/', $arg, $m) === 1) {
                $options[$m[1]] = $m[2];
            } elseif (!str_starts_with($arg, '--')) {
                $positional[] = $arg;
            }
        }
        if (\count($positional) !== 1) {
            $output->error('Usage: bin/reporion page:new <path> [--template=<path>] [--actor=<username>]');

            return 1;
        }
        $actor = $options['actor'] ?? 'cli';

        try {
            [$frontmatter, $body] = isset($options['template'])
                ? Duplicates::document($this->storage->read($options['template']))
                : [['title' => '', 'visibility' => 'private'], ''];
            $record = $this->storage->create($positional[0], $frontmatter, $body, $actor, isset($options['template']) ? 'from template' : null);
        } catch (PageNotFoundException) {
            $output->error('No template page at ' . $options['template']);

            return 1;
        } catch (InvalidArgumentException $e) {
            $output->error($e->getMessage());

            return 1;
        }
        $this->audit->record('page.create', $actor, null, $record->pid, $record->path, $record->rev);
        $output->line('created ' . $record->path);

        return 0;
    }
}
