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

    private const MAX_OUTPUT = 65536;

    /** Left out, as in BackupService: caches, scratch space, earlier archives, locks */
    private const EXCLUDES = ['/index.sqlite*', '/tmp/', '/import/', '/backups/', '/.pre-restore-*/', '*.lock', '/maintenance/.lock'];

    public function __construct(
        private readonly RsyncConfig $config,
        private readonly string $dataRoot,
        private readonly string $confFile,
        private readonly string $rsync,
        private readonly string $ssh,
        private readonly int $timeout = 10800,
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
        if ($this->rsync === '' || $this->ssh === '' || !is_executable($this->rsync) || !is_executable($this->ssh)) {
            return $fail('no_binary');
        }
        if ($this->config->sshKey !== '' && !is_readable($this->config->sshKey)) {
            return $fail('key_unreadable');
        }
        $known = $this->dataRoot . '/maintenance/rsync_known_hosts';
        if (preg_match('~^[A-Za-z0-9._/@+-]+$~', $known) !== 1) {
            return $fail('known_hosts_path');
        }
        if (!is_dir(\dirname($known))) {
            @mkdir(\dirname($known), 0770, true);
        }
        @set_time_limit(0);

        $name = date('Y-m-d\THis', $now);
        $base = rtrim($this->config->remoteDir, '/');
        $snaps = $base . '/snapshots';
        $partial = $snaps . '/' . $name . '.partial';
        $sshOptions = $this->sshOptions($known);
        $target = $this->config->user . '@' . $this->config->host;
        $remote = fn (string $command): array => self::exec([$this->ssh, ...$sshOptions, $target, $command], 120);
        $shell = static fn (string $path): string => "'" . $path . "'";

        if (!$dryRun) {
            $this->markAttempt($now);
        }
        [$code] = $remote('mkdir -p -- ' . $shell($snaps) . ($this->config->includeConf && !$dryRun ? ' ' . $shell($base . '/conf') : ''));
        if ($code !== 0) {
            return $this->finish($dryRun, $now, $fail($code === 255 ? 'ssh' : 'remote', [$code]), $code);
        }

        $argv = [$this->rsync, '-a', '--no-owner', '--no-group', '-z', '--stats', '--timeout=300'];
        if ($dryRun) {
            $argv[] = '-n';
        } else {
            $argv[] = '--link-dest=../latest';
        }
        if ($this->config->bwlimit > 0) {
            $argv[] = '--bwlimit=' . $this->config->bwlimit;
        }
        foreach (self::EXCLUDES as $pattern) {
            $argv[] = '--exclude=' . $pattern;
        }
        array_push($argv, '-e', $this->ssh . ' ' . implode(' ', $sshOptions), rtrim($this->dataRoot, '/') . '/', $target . ':' . ($dryRun ? $snaps . '/.dry-run' : $partial) . '/');
        [$code, $out] = self::exec($argv, $this->timeout);
        $stats = self::stats($out);
        $result = array_merge($result, $stats);
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
            [$code] = self::exec([$this->rsync, '-t', '--chmod=F600', '--timeout=60', '-e', $this->ssh . ' ' . implode(' ', $sshOptions), $this->confFile, $target . ':' . $base . '/conf/local.php.' . $name], 120);
            if ($code !== 0) {
                $result['warnings'][] = 'conf';
            }
        }

        // The snapshot becomes real only now: renamed, then `latest` moved to it
        [$code] = $remote('mv -- ' . $shell($partial) . ' ' . $shell($snaps . '/' . $name) . ' && ln -sfn -- ' . $shell($name) . ' ' . $shell($snaps . '/latest'));
        if ($code !== 0) {
            return $this->finish($dryRun, $now, $fail('promote', [$code]), $code);
        }
        $result['snapshot'] = $name;
        $result['ok'] = true;

        if ($this->config->keepDays() > 0) {
            $pruned = $this->prune($remote, $shell, $snaps, $base, $name, $now);
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
     * match the snapshot pattern are ever passed to `rm`; the newest
     * snapshot and the one just made stay whatever their age. Null when the
     * target could not be listed or cleaned.
     *
     * @param \Closure(string): array{0: int, 1: string} $remote
     */
    private function prune(\Closure $remote, \Closure $shell, string $snaps, string $base, string $current, int $now): ?int
    {
        [$code, $listing] = $remote('ls -1 -- ' . $shell($snaps));
        if ($code !== 0) {
            return null;
        }
        $final = [];
        $partials = [];
        foreach (preg_split('/\R/', $listing) ?: [] as $entry) {
            $entry = trim($entry);
            if (preg_match(self::SNAPSHOT, $entry) === 1) {
                $final[] = $entry;
            } elseif (str_ends_with($entry, '.partial') && preg_match(self::SNAPSHOT, substr($entry, 0, -8)) === 1) {
                $partials[] = $entry;
            }
        }
        sort($final);
        $newest = $final === [] ? $current : end($final);
        $cutoff = $now - $this->config->keepDays() * 86400;
        $drop = [];
        foreach ($final as $snapshot) {
            if ($snapshot !== $newest && $snapshot !== $current && self::time($snapshot) < $cutoff) {
                $drop[] = $snaps . '/' . $snapshot;
                $drop[] = $base . '/conf/local.php.' . $snapshot;
            }
        }
        foreach ($partials as $leftover) {
            if ($leftover !== $current . '.partial' && self::time(substr($leftover, 0, -8)) < $now - 86400) {
                $drop[] = $snaps . '/' . $leftover;
            }
        }
        $count = 0;
        foreach (array_chunk($drop, 40) as $chunk) {
            [$code] = $remote('rm -rf -- ' . implode(' ', array_map($shell, $chunk)));
            if ($code !== 0) {
                return null;
            }
            $count += \count(array_filter($chunk, static fn (string $p): bool => !str_contains($p, '/conf/')));
        }

        return $count;
    }

    private static function time(string $snapshot): int
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\THis', $snapshot);

        return $date === false ? PHP_INT_MAX : $date->getTimestamp();
    }

    /**
     * @return list<string>
     */
    private function sshOptions(string $knownHosts): array
    {
        // A new host's key is accepted once and pinned; a changed key is refused. BatchMode: cron has nobody to ask
        $options = ['-p', (string) $this->config->port, '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=20', '-o', 'ServerAliveInterval=30', '-o', 'ServerAliveCountMax=6', '-o', 'StrictHostKeyChecking=accept-new', '-o', 'UserKnownHostsFile=' . $knownHosts];
        if ($this->config->sshKey !== '') {
            array_push($options, '-i', $this->config->sshKey, '-o', 'IdentitiesOnly=yes');
        }

        return $options;
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

    /**
     * Runs a program as an argument list. Its stderr is discarded (it can
     * name files, invariant 8); stdout is returned, capped.
     *
     * @param list<string> $argv
     *
     * @return array{0: int, 1: string} exit status (-1: did not start, -2: killed at the timeout) and stdout
     */
    private static function exec(array $argv, int $timeout): array
    {
        $env = array_merge(getenv() ?: [], ['LC_ALL' => 'C']);
        $process = @proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
        if (!\is_resource($process)) {
            return [-1, ''];
        }
        stream_set_blocking($pipes[1], false);
        $out = '';
        $deadline = microtime(true) + $timeout;
        while (true) {
            $chunk = fread($pipes[1], 8192);
            if (\is_string($chunk) && $chunk !== '' && \strlen($out) < self::MAX_OUTPUT) {
                $out .= $chunk;
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                $out .= (string) stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                $exit = proc_close($process);

                // proc_close() answers -1 once proc_get_status() has already reaped the child
                return [$status['exitcode'] >= 0 ? $status['exitcode'] : $exit, substr($out, 0, self::MAX_OUTPUT)];
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                proc_close($process);

                return [-2, ''];
            }
            if ($chunk === '' || $chunk === false) {
                usleep(20000);
            }
        }
    }
}
