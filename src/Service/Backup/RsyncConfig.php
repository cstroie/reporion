<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Backup;

/**
 * The `backup:` block of data/settings.yaml (D49): where the periodic rsync
 * backup goes and how long it keeps its snapshots. Every field is typed and
 * validated here, once: Service\InstanceSettings checks a form with the same
 * functions and RsyncBackup re-reads the file through fromSettings(), so a
 * hand-edited value that would not have passed the form is blank, never
 * passed on to ssh or rsync. There is no free-form argument field on purpose:
 * rsync's `-e` and `--rsync-path` would run any command.
 */
final class RsyncConfig
{
    /** How long a snapshot is kept: the key → days (0: never pruned) */
    public const KEEP = ['week' => 7, 'month' => 30, 'all' => 0];

    /** How the backup machine is reached: ssh (encrypted, a shell on the target), or an rsync daemon (`rsync://`, a trusted network only) */
    public const TRANSPORTS = ['ssh', 'daemon'];

    public const DEFAULT_PORT = 22;
    public const DEFAULT_DAEMON_PORT = 873;
    public const DEFAULT_KEEP = 'week';
    public const DEFAULT_INTERVAL_HOURS = 24;

    public function __construct(
        public readonly bool $enabled = false,
        public readonly string $host = '',
        public readonly int $port = self::DEFAULT_PORT,
        public readonly string $user = '',
        public readonly string $remoteDir = '',
        public readonly string $sshKey = '',
        public readonly int $bwlimit = 0,
        public readonly bool $includeConf = false,
        public readonly string $keep = self::DEFAULT_KEEP,
        public readonly int $intervalHours = self::DEFAULT_INTERVAL_HOURS,
        public readonly string $transport = 'ssh',
        public readonly string $module = '',
        public readonly string $passwordFile = '',
    ) {
    }

    /**
     * @param array<string, mixed> $settings the whole of data/settings.yaml
     */
    public static function fromSettings(array $settings): self
    {
        $b = \is_array($settings['backup'] ?? null) ? $settings['backup'] : [];
        $text = static fn (string $key): string => \is_string($b[$key] ?? null) ? trim($b[$key]) : '';
        $int = static fn (string $key): ?int => \is_int($b[$key] ?? null) ? $b[$key] : null;
        $transport = \in_array($text('transport'), self::TRANSPORTS, true) ? $text('transport') : 'ssh';

        return new self(
            enabled: ($b['enabled'] ?? false) === true,
            host: self::validHost($text('host')) ? $text('host') : '',
            port: self::validPort($int('port')) ? (int) $int('port') : ($transport === 'daemon' ? self::DEFAULT_DAEMON_PORT : self::DEFAULT_PORT),
            user: self::validUser($text('user')) ? $text('user') : '',
            remoteDir: self::validRemoteDir($text('remote_dir')) ? $text('remote_dir') : '',
            sshKey: self::validKeyPath($text('ssh_key')) ? $text('ssh_key') : '',
            bwlimit: self::validBwlimit($int('bwlimit')) ? (int) $int('bwlimit') : 0,
            includeConf: ($b['include_conf'] ?? false) === true,
            keep: isset(self::KEEP[$text('keep')]) ? $text('keep') : self::DEFAULT_KEEP,
            intervalHours: self::validHours($int('interval_hours')) ? (int) $int('interval_hours') : self::DEFAULT_INTERVAL_HOURS,
            transport: $transport,
            module: self::validModule($text('module')) ? $text('module') : '',
            passwordFile: self::validKeyPath($text('password_file')) ? $text('password_file') : '',
        );
    }

    /** A host name or an IPv4 address; it can never start with `-` (an ssh option) */
    public static function validHost(string $host): bool
    {
        return preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9.-]{0,250}[A-Za-z0-9])?$/', $host) === 1;
    }

    public static function validUser(string $user): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,31}$/', $user) === 1;
    }

    /** An rsync daemon module's name */
    public static function validModule(string $module): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $module) === 1;
    }

    public static function validPort(?int $port): bool
    {
        return $port !== null && $port >= 1 && $port <= 65535;
    }

    /** Absolute, or relative to the ssh account's home (for a daemon: inside the module; blank is the module's root): letters, digits and `._/-`, no `..`, never the root itself */
    public static function validRemoteDir(string $dir): bool
    {
        return preg_match('~^[A-Za-z0-9._/][A-Za-z0-9._/-]{0,199}$~', $dir) === 1
            && !str_contains($dir, '..')
            && !str_contains($dir, '//')
            && trim($dir, '/.') !== '';
    }

    /** Blank (ssh's own default key), or the absolute path of a private key file: no spaces, since it travels inside ssh's command line */
    public static function validKeyPath(string $path): bool
    {
        return $path === '' || (preg_match('~^/[A-Za-z0-9._/@+-]{1,300}$~', $path) === 1 && !str_contains($path, '..'));
    }

    /** KB/s; 0: no limit */
    public static function validBwlimit(?int $kbps): bool
    {
        return $kbps !== null && $kbps >= 0 && $kbps <= 1_000_000;
    }

    public static function validHours(?int $hours): bool
    {
        return $hours !== null && $hours >= 1 && $hours <= 720;
    }

    public function configured(): bool
    {
        return $this->host !== '' && ($this->transport === 'daemon' ? $this->module !== '' : $this->user !== '' && $this->remoteDir !== '');
    }

    public function keepDays(): int
    {
        return self::KEEP[$this->keep];
    }
}
