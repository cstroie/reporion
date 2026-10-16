<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Backup;

/**
 * The backup machine reached through an rsync daemon (D49): `rsync://host/module/dir`,
 * for a trusted local network. There is no ssh and no remote shell, so
 * everything is rsync: directories are made by sending an empty tree, a
 * finished snapshot is marked by sending a `.reporion-complete` file into it
 * (a snapshot cannot be renamed, so it is written under its final name and
 * the marker says it is whole; the last good one is the state file's, which
 * is what `--link-dest` follows, there being no `latest` symlink: a daemon
 * would munge it), the directory is listed with `--list-only`, and old
 * snapshots are removed by syncing an empty directory over them with
 * `--delete` and an include for exactly their names.
 *
 * The transport is not encrypted: this is for a network you trust. The
 * module must be writable and may not refuse `--delete`.
 */
final class DaemonTarget implements SnapshotTarget
{
    public const MARKER = '.reporion-complete';

    private readonly string $dir;

    public function __construct(
        private readonly RsyncConfig $config,
        private readonly string $dataRoot,
        private readonly string $rsync,
    ) {
        $this->dir = trim($config->remoteDir, '/');
    }

    public function problem(): string
    {
        if ($this->config->passwordFile !== '' && !is_readable($this->config->passwordFile)) {
            return 'password_unreadable';
        }

        return '';
    }

    public function prepare(bool $withConf): int
    {
        // rsync makes only the last component of a destination: send a tree holding the directories wanted
        return $this->withTemp(function (string $tmp) use ($withConf): int {
            $root = $tmp . ($this->dir !== '' ? '/' . $this->dir : '');
            foreach (['snapshots', ...($withConf ? ['conf'] : [])] as $sub) {
                if (!@mkdir($root . '/' . $sub, 0700, true) && !is_dir($root . '/' . $sub)) {
                    return -1;
                }
            }

            return $this->rsync(['-r', '--no-perms', '--omit-dir-times', $tmp . '/', $this->root()])[0];
        });
    }

    public function linkDest(string $previous, string $name): string
    {
        return $previous !== '' && $previous !== $name ? '../' . $previous : '';
    }

    public function transport(): array
    {
        return $this->extra();
    }

    public function snapshotDest(string $name, bool $dry): string
    {
        return $this->url('snapshots/' . ($dry ? '.dry-run' : $name)) . '/';
    }

    public function confDest(string $name): string
    {
        return $this->url('conf/local.php.' . $name);
    }

    public function publish(string $name): int
    {
        return $this->withTemp(function (string $tmp) use ($name): int {
            file_put_contents($tmp . '/' . self::MARKER, $name . "\n");

            return $this->rsync(['-t', $tmp . '/' . self::MARKER, $this->url('snapshots/' . $name . '/' . self::MARKER)])[0];
        });
    }

    public function listing(): ?array
    {
        [$code, $out] = $this->rsync(['--list-only', $this->url('snapshots') . '/']);
        if ($code !== 0) {
            return null;
        }
        $names = [];
        foreach (preg_split('/\R/', $out) ?: [] as $line) {
            // "drwxr-xr-x          4,096 2026/10/17 04:00:00 2026-10-17T040000": the name is the last field
            if (preg_match('/^d\S+\s+[\d,]+\s+\S+\s+\S+\s+(\S+)$/', trim($line), $m) === 1) {
                $names[] = $m[1];
            }
        }

        return $names;
    }

    public function remove(array $entries, array $confs): int
    {
        return $this->withTemp(function (string $tmp) use ($entries, $confs): int {
            foreach ([['snapshots', $entries], ['conf', array_map(static fn (string $n): string => 'local.php.' . $n, $confs)]] as [$sub, $names]) {
                foreach (array_chunk($names, 40) as $chunk) {
                    // An empty directory sent over the parent, with only these names let through, deletes exactly them
                    $argv = ['-r', '--delete'];
                    foreach ($chunk as $name) {
                        $argv[] = '--include=/' . $name . ($sub === 'snapshots' ? '/***' : '');
                    }
                    array_push($argv, '--exclude=*', $tmp . '/', $this->url($sub) . '/');
                    $code = $this->rsync($argv)[0];
                    if ($code !== 0) {
                        return $code;
                    }
                }
            }

            return 0;
        });
    }

    /** The module's root: `rsync://[user@]host[:port]/module/` */
    private function root(): string
    {
        return 'rsync://' . ($this->config->user !== '' ? $this->config->user . '@' : '') . $this->config->host . ':' . $this->config->port . '/' . $this->config->module . '/';
    }

    /** `rsync://[user@]host[:port]/module/dir/{path}` */
    private function url(string $path): string
    {
        $parts = array_filter([$this->config->module, $this->dir, $path], static fn (string $p): bool => $p !== '');

        return 'rsync://' . ($this->config->user !== '' ? $this->config->user . '@' : '') . $this->config->host . ':' . $this->config->port . '/' . implode('/', $parts);
    }

    /**
     * @return list<string>
     */
    private function extra(): array
    {
        $extra = ['--contimeout=20'];
        if ($this->config->passwordFile !== '') {
            $extra[] = '--password-file=' . $this->config->passwordFile;
        }

        return $extra;
    }

    /**
     * @param list<string> $args
     *
     * @return array{0: int, 1: string}
     */
    private function rsync(array $args): array
    {
        return ProcessRun::run([$this->rsync, '--timeout=300', ...$this->extra(), ...$args], 600);
    }

    /**
     * @param callable(string): int $do
     */
    private function withTemp(callable $do): int
    {
        $parent = $this->dataRoot . '/tmp';
        if (!is_dir($parent)) {
            @mkdir($parent, 0770, true);
        }
        $tmp = $parent . '/rsync-' . bin2hex(random_bytes(6));
        if (!@mkdir($tmp, 0700)) {
            return -1;
        }
        try {
            return $do($tmp);
        } finally {
            self::removeTree($tmp);
        }
    }

    private static function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
