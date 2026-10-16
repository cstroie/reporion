<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Backup;

/**
 * The backup machine reached over ssh (D49): `user@host:dir`. Snapshots are
 * written to `{name}.partial`, renamed when complete and `latest` moved to
 * them, with a few remote commands built only from validated names.
 */
final class SshTarget implements SnapshotTarget
{
    private readonly string $base;
    private readonly string $knownHosts;

    public function __construct(
        private readonly RsyncConfig $config,
        string $dataRoot,
        private readonly string $ssh,
    ) {
        $this->base = rtrim($config->remoteDir, '/');
        $this->knownHosts = $dataRoot . '/maintenance/rsync_known_hosts';
    }

    public function problem(): string
    {
        if ($this->config->sshKey !== '' && !is_readable($this->config->sshKey)) {
            return 'key_unreadable';
        }
        if (preg_match('~^[A-Za-z0-9._/@+-]+$~', $this->knownHosts) !== 1) {
            return 'known_hosts_path';
        }
        if (!is_dir(\dirname($this->knownHosts))) {
            @mkdir(\dirname($this->knownHosts), 0770, true);
        }

        return '';
    }

    public function prepare(bool $withConf): int
    {
        [$code] = $this->remote('mkdir -p -- ' . self::q($this->base . '/snapshots') . ($withConf ? ' ' . self::q($this->base . '/conf') : ''));

        return $code;
    }

    public function linkDest(string $previous, string $name): string
    {
        return '../latest';
    }

    public function transport(): array
    {
        return ['-e', $this->ssh . ' ' . implode(' ', $this->options())];
    }

    public function snapshotDest(string $name, bool $dry): string
    {
        return $this->prefix() . $this->base . '/snapshots/' . ($dry ? '.dry-run' : $name . '.partial') . '/';
    }

    public function confDest(string $name): string
    {
        return $this->prefix() . $this->base . '/conf/local.php.' . $name;
    }

    public function publish(string $name): int
    {
        $snaps = $this->base . '/snapshots';
        [$code] = $this->remote('mv -- ' . self::q($snaps . '/' . $name . '.partial') . ' ' . self::q($snaps . '/' . $name) . ' && ln -sfn -- ' . self::q($name) . ' ' . self::q($snaps . '/latest'));

        return $code;
    }

    public function listing(): ?array
    {
        [$code, $out] = $this->remote('ls -1 -- ' . self::q($this->base . '/snapshots'));

        return $code === 0 ? array_values(array_filter(array_map('trim', preg_split('/\R/', $out) ?: []))) : null;
    }

    public function remove(array $entries, array $confs): int
    {
        $paths = [];
        foreach ($entries as $entry) {
            $paths[] = $this->base . '/snapshots/' . $entry;
        }
        foreach ($confs as $name) {
            $paths[] = $this->base . '/conf/local.php.' . $name;
        }
        foreach (array_chunk($paths, 40) as $chunk) {
            [$code] = $this->remote('rm -rf -- ' . implode(' ', array_map(self::q(...), $chunk)));
            if ($code !== 0) {
                return $code;
            }
        }

        return 0;
    }

    private function prefix(): string
    {
        return $this->config->user . '@' . $this->config->host . ':';
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function remote(string $command): array
    {
        return ProcessRun::run([$this->ssh, ...$this->options(), $this->config->user . '@' . $this->config->host, $command], 120);
    }

    /**
     * A new host's key is accepted once and pinned; a changed key is refused. BatchMode: cron has nobody to ask.
     *
     * @return list<string>
     */
    private function options(): array
    {
        $options = ['-p', (string) $this->config->port, '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=20', '-o', 'ServerAliveInterval=30', '-o', 'ServerAliveCountMax=6', '-o', 'StrictHostKeyChecking=accept-new', '-o', 'UserKnownHostsFile=' . $this->knownHosts];
        if ($this->config->sshKey !== '') {
            array_push($options, '-i', $this->config->sshKey, '-o', 'IdentitiesOnly=yes');
        }

        return $options;
    }

    /** Names are validated ([A-Za-z0-9._/-]) before they get here: quoting is for the shell's word splitting only */
    private static function q(string $path): string
    {
        return "'" . $path . "'";
    }
}
