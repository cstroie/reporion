<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Backup;

/**
 * Where the rsync backup's snapshots live and how to do the few things
 * rsync alone cannot (D49): make the directories, publish a finished
 * snapshot, list and remove old ones. Over ssh (SshTarget) these are remote
 * shell commands; against an rsync daemon (DaemonTarget) there is no shell,
 * so they are done with rsync itself. Every name passed in is a snapshot
 * name RsyncBackup built or one that matched RsyncBackup::SNAPSHOT.
 */
interface SnapshotTarget
{
    /** Whether the target can be used at all, as a RsyncBackup error code ('' = yes); checked before anything is run */
    public function problem(): string;

    /**
     * Makes `snapshots/` (and `conf/`) on the target.
     *
     * @return int 0, or the exit status of what failed
     */
    public function prepare(bool $withConf): int;

    /** The `--link-dest` value for the run ('' = none: nothing to link against yet) */
    public function linkDest(string $previous, string $name): string;

    /** The arguments that make rsync reach the target (`-e ssh …`, or the daemon's password file and timeouts) */
    public function transport(): array;

    /** The rsync destination for the snapshot being written ($dry: a throwaway name for `rsync -n`) */
    public function snapshotDest(string $name, bool $dry): string;

    /** The rsync destination for the copy of conf/local.php */
    public function confDest(string $name): string;

    /**
     * The copy is complete: make it a snapshot.
     *
     * @return int 0, or the exit status
     */
    public function publish(string $name): int;

    /**
     * Names in `snapshots/`, or null when the target could not be listed.
     *
     * @return ?list<string>
     */
    public function listing(): ?array;

    /**
     * Removes snapshot directories (and their kept conf copies) by name.
     *
     * @param list<string> $entries names in snapshots/ (a snapshot, or a `.partial` leftover)
     * @param list<string> $confs   snapshot names whose conf/local.php.{name} goes too
     *
     * @return int 0, or the exit status
     */
    public function remove(array $entries, array $confs): int;
}
