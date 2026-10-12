<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Cli;

use Closure;
use Reporion\Audit\AuditLog;
use Reporion\Exception\BackupException;
use Reporion\Exception\MaintenanceBusyException;
use Reporion\Service\Backup\BackupService;
use Reporion\Support\MetaText;
use Reporion\Service\IndexMaintenance;
use Reporion\Service\Maintenance\MaintenanceRunner;
use Reporion\Service\Maintenance\MaintenanceTask;

/**
 * bin/reporion backup:restore <archive> [--replace] [--with-conf] [--actor=<u>] (D44)
 * — puts a backup's data/ in place of this instance's, then finishes what a
 * live backup can leave open (journal), rebuilds the index and checks the
 * result. Only for the data directory's owner: files written by anyone else
 * would be unwritable for the web server (the 2026-09-24 incident).
 */
final class BackupRestoreCommand implements CommandInterface
{
    /**
     * @param Closure(): array{0: MaintenanceRunner, 1: IndexMaintenance, 2: AuditLog} $services built after the swap, on the restored data
     */
    public function __construct(
        private readonly BackupService $backups,
        private readonly string $dataRoot,
        private readonly Closure $services,
    ) {
    }

    public static function help(): CommandHelp
    {
        return new CommandHelp(
            summary: 'Restores a backup archive: the instance\'s data/ is replaced by the archive\'s. The current content is moved to data/.pre-restore-<time>/, never deleted. Then it replays the journal, rebuilds the index and checks integrity.',
            usage: '<archive.tar.gz> [--replace] [--with-conf] [--actor=<username>]',
            options: [
                '--replace' => 'required when this instance already holds pages',
                '--with-conf' => 'also install the archive\'s conf/local.php, only if it names this same data directory (the current one is kept as local.php.pre-restore-<time>); otherwise it is left as conf/local.php.from-backup',
                '--actor=<username>' => 'who it runs on behalf of, named in the audit (default cli)',
            ],
            details: "Run it as the web server's user: sudo -u www-data bin/reporion backup:restore <file>. Refused when run as anyone else.\nThe archive's accounts replace this instance's, including the owner the installer created (it stays in .pre-restore-*). Similar reports needs index:vectors afterwards (it asks the embedding server; not run here).\nExit 75 while another maintenance run is in progress.",
        );
    }

    public function run(array $args, Output $output): int
    {
        $file = null;
        $actor = 'cli';
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--actor=') && \strlen($arg) > 8) {
                $actor = substr($arg, 8);
            } elseif (!str_starts_with($arg, '--')) {
                $file = $arg;
            }
        }
        if ($file === null) {
            $output->error('usage: bin/reporion backup:restore <archive.tar.gz> [--replace] [--with-conf] [--actor=<username>]');

            return 1;
        }
        if (\function_exists('posix_geteuid') && posix_geteuid() !== (int) @fileowner($this->dataRoot)) {
            $output->error('Run this as the owner of data/ (sudo -u www-data bin/reporion backup:restore …): files written by another user would not be writable for the web server');

            return 1;
        }

        $output->line('restoring ' . basename($file) . ' into data/ …');
        try {
            $lock = MaintenanceRunner::lock($this->dataRoot);
        } catch (MaintenanceBusyException $e) {
            $output->error($e->getMessage());

            return 75;
        }
        try {
            $restored = $this->backups->restore($file, ['replace' => \in_array('--replace', $args, true), 'with_conf' => \in_array('--with-conf', $args, true)]);
        } catch (BackupException $e) {
            $output->error($e->getMessage());

            return 1;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $output->line(\sprintf('restored %d file(s), %s, made %s', $restored['files'], MetaText::size($restored['bytes']), $restored['created']));
        if ($restored['moved_to'] !== null) {
            $output->line('previous content kept in data/' . $restored['moved_to'] . '/');
        }
        $output->line(match ($restored['conf']) {
            'installed' => 'conf/local.php installed from the archive (the previous one kept as local.php.pre-restore-*)',
            'paths-differ' => 'conf/local.php NOT installed: it names another data directory. The archive\'s copy is conf/local.php.from-backup, to merge by hand',
            'kept' => 'conf/local.php kept; the archive\'s copy is conf/local.php.from-backup (use --with-conf to install it)',
            default => 'the archive holds no conf/local.php',
        });

        [$runner, $maintenance, $audit] = ($this->services)();
        $failed = false;
        $output->write('finishing unfinished writes (journal:replay) … ');
        $replay = $runner->run('journal:replay', MaintenanceTask::APPLY, $actor, ['min_age' => 0])['report'];
        $output->line($replay->exit() === 0 ? 'ok' : 'problems, see Admin → Maintenance');
        $failed = $failed || $replay->exit() !== 0;

        $output->write('rebuilding the index … ');
        $output->line($maintenance->rebuild() . ' page(s)');

        $output->write('checking integrity … ');
        $integrity = $runner->run('integrity:verify', MaintenanceTask::CHECK, $actor, ['backup' => ''])['report'];
        $output->line($integrity->exit() === 0 ? 'ok' : 'problems found, see Admin → Maintenance or run integrity:verify');
        $failed = $failed || $integrity->exit() !== 0;

        $audit->record('backup.restore', $actor, extra: ['files' => $restored['files'], 'bytes' => $restored['bytes'], 'conf' => $restored['conf']]);
        $output->line('done. Similar reports needs index:vectors; sign in with the archive\'s accounts.');

        return $failed ? 1 : 0;
    }
}
