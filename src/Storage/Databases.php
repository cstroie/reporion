<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Storage;

use PDO;
use RuntimeException;

/**
 * The three SQLite files of D54 (docs/architecture-v2.md §6–7) and the one
 * connection they share: the permanent `reporion.sqlite` is `main`, the
 * disposable `index.sqlite` and `vectors.sqlite` are attached as `idx` and
 * `vec`, so a query joins across them and Search\Query::visibilityClause()
 * stays one predicate (invariant 6). Table names do not collide across the
 * files (`schema_meta` aside, never read through this connection), so the
 * index's queries need no schema prefix.
 *
 * Each file's migrations (`migrations/{state,index,vectors}/NNN_*.sql`) run
 * on a connection of its own before the attach, because a migration's
 * unqualified CREATE would otherwise land in `main`. Each runs in a
 * BEGIN IMMEDIATE transaction that re-reads the version, so two requests
 * opening a fresh instance at once apply it once.
 *
 * SQLite does not commit attached WAL databases atomically together, so no
 * write may rely on one transaction spanning files (§7).
 */
final class Databases
{
    /** Index migration that drops page_vectors: rows are copied to vectors.sqlite first */
    private const INDEX_VECTORS_MOVED = 6;

    public function __construct(
        private readonly string $statePath,
        private readonly string $indexPath,
        private readonly string $vectorsPath,
        private readonly string $migrationsRoot,
    ) {
    }

    /**
     * The three files side by side in the index's directory, for tests and a
     * config naming the index only: `index.sqlite` gives `reporion.sqlite` and
     * `vectors.sqlite`; any other `{stem}.sqlite` gives `{stem}.state.sqlite`
     * and `{stem}.vectors.sqlite`, so indexes sharing a directory share nothing.
     */
    public static function beside(string $indexPath, string $migrationsRoot, ?string $statePath = null, ?string $vectorsPath = null): self
    {
        $dir = \dirname($indexPath);
        $base = basename($indexPath);
        if ($base === 'index.sqlite') {
            [$state, $vectors] = ['reporion.sqlite', 'vectors.sqlite'];
        } else {
            $stem = preg_replace('/\.sqlite$/', '', $base);
            [$state, $vectors] = [$stem . '.state.sqlite', $stem . '.vectors.sqlite'];
        }

        return new self($statePath ?? $dir . '/' . $state, $indexPath, $vectorsPath ?? $dir . '/' . $vectors, $migrationsRoot);
    }

    /** The shipped migrations: the repository's own migrations/ */
    public static function migrationsRoot(): string
    {
        return \dirname(__DIR__, 2) . '/migrations';
    }

    /**
     * The permanent file alone, migrated: Storage's own connection (it needs
     * nothing of the caches, and an IndexInterface may not be Sqlite at all).
     */
    public static function openState(string $statePath, ?string $migrationsRoot = null): PDO
    {
        $self = new self($statePath, '', '', $migrationsRoot ?? self::migrationsRoot());
        $self->migrate($statePath, 'state', true);

        return self::open($statePath, true);
    }

    /**
     * A consistent copy of the permanent file at $target (D54 §9): SQLite's
     * `VACUUM INTO`, written beside $target and renamed over it, so a
     * reader (a backup, rsync) never sees half a database. A live WAL file
     * is never copied as it is: its last commits may be in the -wal.
     *
     * @return int the copy's size in bytes
     */
    public static function snapshotState(string $statePath, string $target): int
    {
        $dir = \dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create the snapshot directory');
        }
        $tmp = $target . '.tmp-' . bin2hex(random_bytes(4));
        $pdo = self::open($statePath, true);
        try {
            $pdo->prepare('VACUUM INTO ?')->execute([$tmp]);
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw new RuntimeException('The state database could not be copied', 0, $e);
        }
        unset($pdo);
        \Reporion\Support\Fsync::file($tmp);
        if (!rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException('Cannot put the state database copy in place');
        }
        \Reporion\Support\Fsync::directory($dir);

        return (int) filesize($target);
    }

    public function connect(): PDO
    {
        $this->migrate($this->vectorsPath, 'vectors', false);
        $this->migrate($this->indexPath, 'index', false);
        $this->migrate($this->statePath, 'state', true);

        $pdo = self::open($this->statePath, true);
        $pdo->exec('ATTACH DATABASE ' . $pdo->quote($this->indexPath) . ' AS idx');
        $pdo->exec('ATTACH DATABASE ' . $pdo->quote($this->vectorsPath) . ' AS vec');
        $pdo->exec('PRAGMA idx.synchronous = NORMAL');
        $pdo->exec('PRAGMA vec.synchronous = NORMAL');

        return $pdo;
    }

    /**
     * WAL for all three; the permanent file syncs on every commit (FULL),
     * the caches only at checkpoints (NORMAL: a power cut may lose their
     * last transactions, which index:verify finds and a rebuild restores).
     */
    private static function open(string $path, bool $permanent): PDO
    {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = ' . ($permanent ? 'FULL' : 'NORMAL'));
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    private function migrate(string $path, string $schema, bool $permanent): void
    {
        $files = glob($this->migrationsRoot . '/' . $schema . '/[0-9]*_*.sql') ?: [];
        if ($files === []) {
            throw new RuntimeException('No migrations for the ' . $schema . ' database');
        }
        sort($files);

        $pdo = self::open($path, $permanent);
        foreach ($files as $file) {
            $version = (int) basename($file);
            if ($version <= self::version($pdo)) {
                continue;
            }
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                if ($version <= self::version($pdo)) {
                    $pdo->exec('COMMIT');
                    continue;
                }
                if ($schema === 'index' && $version === self::INDEX_VECTORS_MOVED) {
                    $this->copyLegacyVectors($pdo);
                }
                $pdo->exec((string) file_get_contents($file));
                $pdo->prepare(
                    "INSERT INTO schema_meta (key, value) VALUES ('schema_version', :v)
                     ON CONFLICT(key) DO UPDATE SET value = :v"
                )->execute(['v' => (string) $version]);
                $pdo->exec('COMMIT');
            } catch (\Throwable $e) {
                $pdo->exec('ROLLBACK');
                throw $e;
            }
        }
    }

    /**
     * An index from before D54 kept the embeddings itself: they go to
     * vectors.sqlite (already migrated) so none is asked for again. A pid
     * already there keeps its row.
     */
    private function copyLegacyVectors(PDO $index): void
    {
        $stmt = $index->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'page_vectors'");
        if ($stmt === false || $stmt->fetchColumn() === false) {
            return;
        }
        $vectors = self::open($this->vectorsPath, false);
        $vectors->exec('BEGIN IMMEDIATE');
        $read = $index->query('SELECT pid, model, sha, dim, vec FROM page_vectors');
        $write = $vectors->prepare('INSERT OR IGNORE INTO page_vectors (pid, model, sha, dim, vec) VALUES (?, ?, ?, ?, ?)');
        while ($read !== false && ($row = $read->fetch(PDO::FETCH_NUM)) !== false) {
            $write->execute($row);
        }
        $vectors->exec('COMMIT');
    }

    private static function version(PDO $pdo): int
    {
        $stmt = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'schema_meta'");
        if ($stmt === false || $stmt->fetchColumn() === false) {
            return 0;
        }
        $value = $pdo->query("SELECT value FROM schema_meta WHERE key = 'schema_version'")?->fetchColumn();

        return $value !== false && $value !== null ? (int) $value : 0;
    }
}
