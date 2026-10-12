<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Reporion\Exception\BackupException;
use Reporion\Service\Backup\BackupService;
use Reporion\Support\MetaText;

/**
 * bin/reporion backup:verify <archive> [--json] (D44) — reads a whole
 * backup archive and checks every file against its manifest. Changes nothing.
 */
final class BackupVerifyCommand implements CommandInterface
{
    public function __construct(private readonly BackupService $backups)
    {
    }

    public static function help(): CommandHelp
    {
        return new CommandHelp(
            summary: 'Reads a backup archive from end to end and checks every file against the checksums in its manifest. Writes nothing.',
            usage: '<archive.tar.gz> [--json]',
            options: ['--json' => 'print the result as JSON'],
            details: 'Exit 1 when the archive is damaged, truncated or holds an entry it should not.',
        );
    }

    public function run(array $args, Output $output): int
    {
        $json = \in_array('--json', $args, true);
        $file = null;
        foreach ($args as $arg) {
            if (!str_starts_with($arg, '--')) {
                $file = $arg;
            }
        }
        if ($file === null) {
            $output->error('usage: bin/reporion backup:verify <archive.tar.gz> [--json]');

            return 1;
        }
        try {
            $read = $this->backups->verify($file);
        } catch (BackupException $e) {
            $json ? $output->line((string) json_encode(['ok' => false, 'error' => $e->getMessage()])) : $output->error($e->getMessage());

            return 1;
        }
        $created = (string) ($read['manifest']['created'] ?? '');
        if ($json) {
            $output->line((string) json_encode(['ok' => true, 'files' => $read['files'], 'bytes' => $read['bytes'], 'created' => $created]));
        } else {
            $output->line(\sprintf('ok: %d file(s), %s, made %s', $read['files'], MetaText::size($read['bytes']), $created));
        }

        return 0;
    }
}
