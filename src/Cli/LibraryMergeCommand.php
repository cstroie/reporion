<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Auth\User;
use Reporion\Exception\LibraryException;
use Reporion\Exception\PageNotFoundException;
use Reporion\Exception\RevisionConflictException;
use Reporion\Service\Library\Library;
use Reporion\Service\Library\Merge;

/**
 * bin/reporion library:merge <keep> <from> [--actor=<u>] [--dry-run] [--json]: the source `from`
 * folded into `keep` (Service\Library\Merge, D60). Both are citation keys (pages under library:).
 */
final class LibraryMergeCommand implements CommandInterface
{
    public function __construct(private readonly Merge $merge, private readonly Library $library)
    {
    }

    public static function help(): CommandHelp
    {
        return new CommandHelp(
            summary: 'Folds one library source into another that is the same article: the first keeps its key and gets the fields, tags, file and text the second lacked; the second goes to the trash with a redirect, and every page that cited it cites the first.',
            usage: '<keep> <from> [--actor=<username>] [--dry-run] [--json]',
            options: [
                '<keep>' => 'the citation key to keep',
                '<from>' => 'the citation key to fold into it',
                '--actor=<username>' => 'attribute the revisions to this user (default: cli)',
                '--dry-run' => 'say that both are sources and write nothing',
                '--json' => 'print the result as JSON',
            ],
            details: 'An unsigned page citing <from> gets a new revision citing <keep>; a signed page is never edited (its old key still finds <keep> through the redirect). The second source\'s comments and saved analyses go to the trash with it; Admin → Trash brings it back.',
        );
    }

    public function run(array $args, Output $output): int
    {
        $keys = [];
        $actor = 'cli';
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--actor=') && \strlen($arg) > 8) {
                $actor = substr($arg, 8);
            } elseif (!str_starts_with($arg, '--')) {
                $keys[] = $arg;
            } elseif (!\in_array($arg, ['--dry-run', '--json'], true)) {
                $output->error('Unknown option ' . $arg);

                return 1;
            }
        }
        if (\count($keys) !== 2) {
            $output->error('Usage: bin/reporion library:merge ' . self::help()->usage);

            return 1;
        }
        $json = \in_array('--json', $args, true);
        $dryRun = \in_array('--dry-run', $args, true);
        [$into, $from] = array_map(fn (string $key): string => $this->library->namespace() . ':' . $key, $keys);
        if (!$json) {
            $output->line(($dryRun ? 'Checking the merge of ' : 'Merging ') . $keys[1] . ' into ' . $keys[0] . ($dryRun ? ' (dry run, nothing written)' : ''));
        }
        // The command line acts for the instance: it writes everywhere, as an owner does
        $principal = new User($actor, '', true, [], true, 'now', 'now');
        try {
            if ($dryRun) {
                $sources = array_column($this->library->records($principal, $keys), 'key');
                if (\count(array_unique($sources)) !== 2) {
                    throw new PageNotFoundException();
                }
                $result = ['dry_run' => true, 'keep' => $keys[0], 'from' => $keys[1]];
            } else {
                $done = $this->merge->merge($into, $from, $principal, $actor);
                $result = ['keep' => $keys[0], 'from' => $done['from_key'], 'changed' => $done['changed'], 'relinked' => $done['relinked'], 'left_signed' => $done['left_signed'], 'subpages' => $done['subpages']];
            }
        } catch (LibraryException $e) {
            $output->error('Not merged: ' . $e->reason);

            return 1;
        } catch (PageNotFoundException) {
            $output->error('Not merged: both must be sources of ' . $this->library->namespace() . ':');

            return 1;
        } catch (RevisionConflictException) {
            $output->error('Not merged: a page was saved meanwhile; run it again');

            return 1;
        }
        if ($json) {
            $output->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } elseif (!$dryRun) {
            $output->line(\sprintf('merged: %d field(s) taken, %d page(s) re-cited, %d signed left as they were, %d saved page(s) trashed with it', \count($result['changed']), $result['relinked'], $result['left_signed'], $result['subpages']));
        }

        return 0;
    }
}
