<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Backup;

use DateTimeImmutable;
use Reporion\Storage\AtomicWriter;

/**
 * The periodic rsync backup (D49, amending D22): a dated, differential
 * snapshot of data/ on another machine, over ssh. Each run rsyncs into a new
 * `snapshots/{YYYY-MM-DDTHHMMSS}.partial/` with `--link-dest=../latest`, so
 * a file that did not change is a hard link to the last snapshot's and costs
 * no space or transfer; when rsync has finished the directory is renamed
 * (`mv`, atomic) and `latest` points to it. A crashed or interrupted run
 * leaves only a `.partial` directory, which is never `latest` and is removed
 * with the old snapshots. Snapshots older than the retention (a week or a
 * month, counted from the name) are removed on the target; the newest one is
 * never removed. Deleting an old snapshot is safe: hard links keep the data
 * of the files a newer snapshot still shares.
 *
 * The target is SshTarget (the above, with a few remote commands) or, for a
 * trusted local network, DaemonTarget (`rsync://host/module/`, no ssh and no
 * remote shell: everything is done with rsync, a snapshot is written under
 * its final name and marked complete, `--link-dest` follows the state file's
 * last good snapshot instead of a `latest` link).
 *
 * Everything runs as an argument list, never through a shell, with the
 * values RsyncConfig validated. rsync's and ssh's stderr go to /dev/null and
 * its stdout is read only for the `--stats` counts: both can name files, and
 * a page directory is `{yymmdd}-{name}` (invariant 8). A failure is reported
 * as a fixed message with the exit status, nothing rsync said.
 *
 * The target keeps `snapshots/` (data/ as it was: `integrity:verify
 * --backup=` can read one) and, with `include_conf`, `conf/local.php.{name}`.
 */
final class RsyncBackup
{
    /** Where the last attempt and the last success are kept, under data/ (a status note: disk holds the backup itself) */
    public const STATE = 'maintenance/backup-rsync.json';

    public const SNAPSHOT = '/^\d{4}-\d{2}-\d{2}T\d{6}$/';

    /** Left out, as in BackupService: caches, scratch space, earlier archives, locks */
    private const EXCLUDES = ['/index.sqlite*', '/vectors.sqlite*', '/tmp/', '/import/', '/backups/', '/.pre-restore-*/', '*.lock', '/maintenance/.lock'];

    public function __construct(
        private readonly RsyncConfig $config,
        private readonly string $dataRoot,
        private readonly string $confFile,
        private readonly string $rsync,
        private readonly string $ssh,
        private readonly int $timeout = 10800,
        private readonly ?SnapshotTarget $target = null,
    ) {
    }

    /** The first executable called $name in the usual places; '' when there is none */
    public static function binary(string $name): string
    {
        foreach (['/usr/bin', '/usr/local/bin', '/bin', '/opt/homebrew/bin'] as $dir) {
            if (is_executable($dir . '/' . $name)) {
                return $dir . '/' . $name;
            }
        }

        return '';
    }

    /**
     * @return array{last_attempt: int, last_success: int, last_exit: int, snapshot: string, files: int, bytes: int}
     */
    public static function state(string $dataRoot): array
    {
        $raw = @file_get_contents($dataRoot . '/' . self::STATE);
        $data = \is_string($raw) ? json_decode($raw, true) : null;
        $data = \is_array($data) ? $data : [];

        return [
            'last_attempt' => (int) ($data['last_attempt'] ?? 0),
            'last_success' => (int) ($data['last_success'] ?? 0),
            'last_exit' => (int) ($data['last_exit'] ?? 0),
            'snapshot' => \is_string($data['snapshot'] ?? null) && preg_match(self::SNAPSHOT, $data['snapshot']) === 1 ? $data['snapshot'] : '',
            'files' => (int) ($data['files'] ?? 0),
            'bytes' => (int) ($data['bytes'] ?? 0),
        ];
    }

    /**
     * Whether the cron job should run now (`backup:rsync --if-due`): the
     * schedule is on, a snapshot is older than the interval, and the last
     * attempt, if it failed, was not in the last half hour.
     */
    public static function due(RsyncConfig $config, string $dataRoot, int $now): bool
    {
        if (!$config->enabled || !$config->configured()) {
            return false;
        }
        $state = self::state($dataRoot);
        // A few minutes of slack: cron fires at a fixed minute and a run takes time
        if ($state['last_success'] > 0 && $now - $state['last_success'] < $config->intervalHours * 3600 - 300) {
            return false;
        }

        return $now - $state['last_attempt'] >= 1800;
    }

    /**
     * A dry run only asks the target (`rsync -n --stats`, after making sure
     * `snapshots/` exists there) and writes nothing else; the state is kept
     * for real runs.
     *
     * @return array{ok: bool, error: ?array{0: string, 1: list<int|string>}, warnings: list<string>, files: int, transferred: int, bytes: int, sent: int, snapshot: string, pruned: int}
     */
    public function run(bool $dryRun, ?int $now = null): array
    {
        $now ??= time();
        $result = ['ok' => false, 'error' => null, 'warnings' => [], 'files' => 0, 'transferred' => 0, 'bytes' => 0, 'sent' => 0, 'snapshot' => '', 'pruned' => 0];
        $fail = static function (string $code, array $args = []) use (&$result): array {
            $result['error'] = [$code, $args];

            return $result;
        };

        if (!$this->config->configured()) {
            return $fail('not_configured');
        }
        $daemon = $this->config->transport === 'daemon';
        if ($this->rsync === '' || !is_executable($this->rsync) || (!$daemon && ($this->ssh === '' || !is_executable($this->ssh)))) {
            return $fail('no_binary');
        }
        $target = $this->target ?? ($daemon ? new DaemonTarget($this->config, $this->dataRoot, $this->rsync) : new SshTarget($this->config, $this->dataRoot, $this->ssh));
        if (($problem = $target->problem()) !== '') {
            return $fail($problem);
        }
        @set_time_limit(0);

        $name = date('Y-m-d\THis', $now);
        $previous = self::state($this->dataRoot)['snapshot'];
        if (!$dryRun) {
            $this->markAttempt($now);
        }
        if (($code = $target->prepare($this->config->includeConf && !$dryRun)) !== 0) {
            return $this->finish($dryRun, $now, $fail($code === 255 ? 'ssh' : 'remote', [$code]), $code);
        }

        $argv = [$this->rsync, '-a', '--no-owner', '--no-group', '-z', '--stats', '--timeout=300'];
        if ($dryRun) {
            $argv[] = '-n';
        } elseif (($link = $target->linkDest($previous, $name)) !== '') {
            $argv[] = '--link-dest=' . $link;
        }
        if ($this->config->bwlimit > 0) {
            $argv[] = '--bwlimit=' . $this->config->bwlimit;
        }
        foreach (self::EXCLUDES as $pattern) {
            $argv[] = '--exclude=' . $pattern;
        }
        array_push($argv, ...$target->transport());
        array_push($argv, rtrim($this->dataRoot, '/') . '/', $target->snapshotDest($name, $dryRun));
        [$code, $out] = ProcessRun::run($argv, $this->timeout);
        $result = array_merge($result, self::stats($out));
        if ($code === 24) {
            // Files that vanished while it ran: a live data/ does that (a draft removed, a temp file renamed)
            $result['warnings'][] = 'vanished';
        } elseif ($code !== 0) {
            return $this->finish($dryRun, $now, $fail($code === -2 ? 'timeout' : ($code === 255 ? 'ssh' : 'rsync'), [$code]), $code);
        }
        if ($dryRun) {
            $result['ok'] = true;

            return $result;
        }

        if ($this->config->includeConf && is_file($this->confFile)) {
            $conf = [$this->rsync, '-t', '--chmod=F600', '--timeout=60', ...$target->transport(), $this->confFile, $target->confDest($name)];
            if (ProcessRun::run($conf, 120)[0] !== 0) {
                $result['warnings'][] = 'conf';
            }
        }

        // The snapshot becomes real only now
        if (($code = $target->publish($name)) !== 0) {
            return $this->finish($dryRun, $now, $fail('promote', [$code]), $code);
        }
        $result['snapshot'] = $name;
        $result['ok'] = true;

        if ($this->config->keepDays() > 0) {
            $pruned = $this->prune($target, $name, $now);
            if ($pruned === null) {
                $result['warnings'][] = 'prune';
            } else {
                $result['pruned'] = $pruned;
            }
        }

        return $this->finish($dryRun, $now, $result, 0);
    }

    /**
     * Removes the snapshots (and their kept conf copies) older than the
     * retention, and `.partial` leftovers older than a day. Only names that
     * match the snapshot pattern are ever passed on; the newest snapshot and
     * the one just made stay whatever their age. Null when the target could
     * not be listed or cleaned.
     */
    private function prune(SnapshotTarget $target, string $current, int $now): ?int
    {
        $listing = $target->listing();
        if ($listing === null) {
            return null;
        }
        $final = [];
        $partials = [];
        foreach ($listing as $entry) {
            if (preg_match(self::SNAPSHOT, $entry) === 1) {
                $final[] = $entry;
            } elseif (str_ends_with($entry, '.partial') && preg_match(self::SNAPSHOT, substr($entry, 0, -8)) === 1) {
                $partials[] = $entry;
            }
        }
        sort($final);
        $newest = $final === [] ? $current : end($final);
        $cutoff = $now - $this->config->keepDays() * 86400;
        $entries = [];
        $confs = [];
        foreach ($final as $snapshot) {
            if ($snapshot !== $newest && $snapshot !== $current && self::time($snapshot) < $cutoff) {
                $entries[] = $snapshot;
                $confs[] = $snapshot;
            }
        }
        foreach ($partials as $leftover) {
            if ($leftover !== $current . '.partial' && self::time(substr($leftover, 0, -8)) < $now - 86400) {
                $entries[] = $leftover;
            }
        }
        if ($entries === []) {
            return 0;
        }

        return $target->remove($entries, $confs) === 0 ? \count($entries) : null;
    }

    private static function time(string $snapshot): int
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\THis', $snapshot);

        return $date === false ? PHP_INT_MAX : $date->getTimestamp();
    }

    /**
     * @param array{ok: bool, error: ?array{0: string, 1: list<int|string>}, warnings: list<string>, files: int, transferred: int, bytes: int, sent: int, snapshot: string, pruned: int} $result
     *
     * @return array{ok: bool, error: ?array{0: string, 1: list<int|string>}, warnings: list<string>, files: int, transferred: int, bytes: int, sent: int, snapshot: string, pruned: int}
     */
    private function finish(bool $dryRun, int $now, array $result, int $code): array
    {
        if (!$dryRun) {
            $state = self::state($this->dataRoot);
            $state['last_exit'] = $result['ok'] ? 0 : max(1, $code);
            if ($result['ok']) {
                $state['last_success'] = $now;
                $state['snapshot'] = $result['snapshot'];
                $state['files'] = $result['files'];
                $state['bytes'] = $result['bytes'];
            }
            $this->writeState($state);
        }

        return $result;
    }

    private function markAttempt(int $now): void
    {
        $state = self::state($this->dataRoot);
        $state['last_attempt'] = $now;
        $this->writeState($state);
    }

    /**
     * @param array<string, int|string> $state
     */
    private function writeState(array $state): void
    {
        $file = $this->dataRoot . '/' . self::STATE;
        if (!is_dir(\dirname($file))) {
            @mkdir(\dirname($file), 0770, true);
        }
        AtomicWriter::put($file, json_encode($state, JSON_THROW_ON_ERROR) . "\n");
    }

    /**
     * The counts of rsync's `--stats` block (LC_ALL=C: commas for thousands).
     *
     * @return array{files: int, transferred: int, bytes: int, sent: int}
     */
    public static function stats(string $out): array
    {
        $n = static function (string $pattern) use ($out): int {
            return preg_match($pattern, $out, $m) === 1 ? (int) str_replace(',', '', $m[1]) : 0;
        };

        return [
            'files' => $n('/^Number of files: ([\d,]+)/m'),
            'transferred' => $n('/^Number of (?:regular )?files transferred: ([\d,]+)/m'),
            'bytes' => $n('/^Total file size: ([\d,]+)/m'),
            'sent' => $n('/^Total bytes sent: ([\d,]+)/m'),
        ];
    }
}
