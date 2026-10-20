<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Storage;

use Closure;
use PDO;
use Throwable;

/**
 * The SQL of data/reporion.sqlite (D54, docs/architecture-v2.md §6), for
 * FileTree only: the one class that writes it (invariant 5). The revision
 * and signature tables mirror each page's attic zip and are rewritten from
 * it on every write (mirror()); everything else here is the only copy.
 */
final class StateDb
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * $work inside BEGIN IMMEDIATE: the write lock taken first, so two
     * writers never interleave a read and a write. Re-entrant: a nested
     * call joins the open transaction.
     *
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    public function transaction(Closure $work): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $work();
        }
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $work();
            $this->pdo->exec('COMMIT');

            return $result;
        } catch (Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    /** @return array<string, mixed>|null the live page at $path */
    public function pageAt(string $path): ?array
    {
        return $this->one('SELECT * FROM page WHERE path = ? AND trashed_at IS NULL', [$path]);
    }

    /** @return array<string, mixed>|null live or trashed */
    public function pageByPid(string $pid): ?array
    {
        return $this->one('SELECT * FROM page WHERE pid = ?', [$pid]);
    }

    /**
     * Live pages whose page file was last written with these bytes: a page
     * file found elsewhere is rejoined to one of them (FileTree::rejoin()).
     *
     * @return list<array<string, mixed>>
     */
    public function pagesWithFileSha(string $sha): array
    {
        return $this->all('SELECT * FROM page WHERE file_sha = ? AND trashed_at IS NULL ORDER BY pid', [$sha]);
    }

    /** @return list<string> every live page's path, sorted */
    public function livePaths(): array
    {
        return array_map('strval', $this->pdo->query('SELECT path FROM page WHERE trashed_at IS NULL ORDER BY path')?->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /** @return list<array<string, mixed>> trashed pages, newest deletion first */
    public function trashed(): array
    {
        return $this->all('SELECT * FROM page WHERE trashed_at IS NOT NULL ORDER BY trashed_at DESC, pid', []);
    }

    /** @param array<string, mixed> $row every column of `page` */
    public function insertPage(array $row): void
    {
        $columns = array_keys($row);
        $this->pdo->prepare(
            'INSERT INTO page (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')'
        )->execute($row);
    }

    /** @param array<string, mixed> $fields */
    public function updatePage(string $pid, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $set = implode(', ', array_map(static fn (string $c): string => $c . ' = :' . $c, array_keys($fields)));
        $this->pdo->prepare('UPDATE page SET ' . $set . ' WHERE pid = :pid')->execute($fields + ['pid' => $pid]);
    }

    /** Every row of the page, by cascade */
    public function deletePage(string $pid): void
    {
        $this->pdo->prepare('DELETE FROM page WHERE pid = ?')->execute([$pid]);
    }

    /**
     * The revision and signature rows, and the page's rev and status, made
     * what the attic zip's record says (its authority, invariant 1)
     *
     * @param array<string, mixed> $history Attic::history()'s shape
     */
    public function mirror(string $pid, array $history): void
    {
        $this->pdo->prepare('DELETE FROM revision WHERE pid = ?')->execute([$pid]);
        $insert = $this->pdo->prepare(
            'INSERT INTO revision (pid, n, ts, by, note, kind, minor, auto, bytes, sha256, chain)
             VALUES (:pid, :n, :ts, :by, :note, :kind, :minor, :auto, :bytes, :sha256, :chain)'
        );
        foreach ($history['revlog'] as $entry) {
            $insert->execute([
                'pid' => $pid,
                'n' => (int) $entry['n'],
                'ts' => (string) ($entry['ts'] ?? ''),
                'by' => (string) ($entry['by'] ?? ''),
                'note' => isset($entry['note']) && \is_scalar($entry['note']) ? (string) $entry['note'] : null,
                'kind' => (string) ($entry['kind'] ?? 'edit'),
                'minor' => !empty($entry['minor']) ? 1 : 0,
                'auto' => !empty($entry['auto']) ? 1 : 0,
                'bytes' => (int) ($entry['bytes'] ?? 0),
                'sha256' => (string) $entry['sha256'],
                'chain' => \is_string($entry['chain'] ?? null) ? $entry['chain'] : null,
            ]);
        }
        $this->pdo->prepare('DELETE FROM signature WHERE pid = ?')->execute([$pid]);
        $insert = $this->pdo->prepare(
            'INSERT OR REPLACE INTO signature (pid, rev, by, ts, alg, digest, parafa, field_order)
             VALUES (:pid, :rev, :by, :ts, :alg, :digest, :parafa, :field_order)'
        );
        foreach ($history['signatures'] as $signature) {
            $insert->execute([
                'pid' => $pid,
                'rev' => (int) $signature['rev'],
                'by' => (string) ($signature['by'] ?? ''),
                'ts' => (string) ($signature['ts'] ?? ''),
                'alg' => (string) ($signature['alg'] ?? 'sha256'),
                'digest' => (string) ($signature['digest'] ?? ''),
                'parafa' => isset($signature['parafa']) && \is_scalar($signature['parafa']) ? (string) $signature['parafa'] : null,
                'field_order' => (string) json_encode($signature['field_order'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        }
        $this->updatePage($pid, ['rev' => \count($history['revlog']), 'status' => (string) $history['status']]);
    }

    /** @return array<int, string> the mirrored revisions' sha256, by n */
    public function revisionShas(string $pid): array
    {
        $out = [];
        foreach ($this->all('SELECT n, sha256 FROM revision WHERE pid = ? ORDER BY n', [$pid]) as $r) {
            $out[(int) $r['n']] = (string) $r['sha256'];
        }

        return $out;
    }

    /** @return list<int> the mirrored signatures' revisions, in order */
    public function signatureRevs(string $pid): array
    {
        return array_map(static fn (array $r): int => (int) $r['rev'], $this->all('SELECT rev FROM signature WHERE pid = ? ORDER BY rev, ts', [$pid]));
    }

    /** @return list<string> what SQLite's integrity and foreign-key checks find; none when sound */
    public function check(): array
    {
        $found = array_values(array_filter(
            array_map('strval', $this->pdo->query('PRAGMA integrity_check')?->fetchAll(PDO::FETCH_COLUMN) ?: []),
            static fn (string $line): bool => $line !== 'ok'
        ));
        foreach ($this->pdo->query('PRAGMA foreign_key_check')?->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $found[] = 'foreign key: ' . (string) ($row['table'] ?? '');
        }

        return $found;
    }

    public function hasSignature(string $pid): bool
    {
        return $this->one('SELECT 1 AS x FROM signature WHERE pid = ? LIMIT 1', [$pid]) !== null;
    }

    /** @return list<array{from: string, to: string, ts: string, by: string}> oldest first */
    public function moves(string $pid): array
    {
        return array_map(static fn (array $r): array => ['from' => (string) $r['from_path'], 'to' => (string) $r['to_path'], 'ts' => (string) $r['ts'], 'by' => (string) $r['by']],
            $this->all('SELECT * FROM move WHERE pid = ? ORDER BY rowid', [$pid]));
    }

    public function addMove(string $pid, string $from, string $to, string $ts, string $by): void
    {
        $this->pdo->prepare('INSERT INTO move (pid, from_path, to_path, ts, by) VALUES (?, ?, ?, ?, ?)')->execute([$pid, $from, $to, $ts, $by]);
    }

    /** The pid an old path now names, or null */
    public function redirectPid(string $path): ?string
    {
        $row = $this->one('SELECT pid FROM redirect WHERE path = ?', [$path]);

        return $row !== null ? (string) $row['pid'] : null;
    }

    public function setRedirect(string $path, string $pid): void
    {
        $this->pdo->prepare('INSERT INTO redirect (path, pid) VALUES (?, ?) ON CONFLICT(path) DO UPDATE SET pid = excluded.pid')->execute([$path, $pid]);
    }

    public function dropRedirect(string $path): void
    {
        $this->pdo->prepare('DELETE FROM redirect WHERE path = ?')->execute([$path]);
    }

    /** @return list<array{sha256: string, ext: string, name: string, bytes: int, w: int, h: int, added: string, by: string}> oldest first */
    public function media(string $pid): array
    {
        return array_map(static fn (array $r): array => [
            'sha256' => (string) $r['sha256'],
            'ext' => (string) $r['ext'],
            'name' => (string) $r['name'],
            'bytes' => (int) $r['bytes'],
            'w' => (int) $r['w'],
            'h' => (int) $r['h'],
            'added' => (string) $r['added'],
            'by' => (string) $r['by'],
        ], $this->all('SELECT * FROM media WHERE pid = ? ORDER BY rowid', [$pid]));
    }

    /** @param array{sha256: string, ext: string, name: string, bytes: int, w: int, h: int, added: string, by: string} $entry */
    public function addMedia(string $pid, array $entry): void
    {
        $this->pdo->prepare(
            'INSERT INTO media (pid, sha256, ext, name, bytes, w, h, added, by) VALUES (:pid, :sha256, :ext, :name, :bytes, :w, :h, :added, :by)'
        )->execute(['pid' => $pid] + $entry);
    }

    /** @return list<array{id: string, by: string, ts: string, text: string, quote: string, rev: int, resolved: ?array{by: string, ts: string}}> oldest first */
    public function comments(string $pid): array
    {
        return array_map(static fn (array $r): array => [
            'id' => (string) $r['id'],
            'by' => (string) $r['by'],
            'ts' => (string) $r['ts'],
            'text' => (string) $r['text'],
            'quote' => (string) ($r['quote'] ?? ''),
            'rev' => (int) ($r['rev'] ?? 0),
            'resolved' => $r['resolved_by'] !== null ? ['by' => (string) $r['resolved_by'], 'ts' => (string) ($r['resolved_ts'] ?? '')] : null,
        ], $this->all('SELECT * FROM comment WHERE pid = ? ORDER BY rowid', [$pid]));
    }

    /**
     * The page's comments replaced by $comments, in that order; an entry
     * without an id, author or text is left out
     *
     * @param list<array<string, mixed>> $comments
     */
    public function replaceComments(string $pid, array $comments): void
    {
        $this->pdo->prepare('DELETE FROM comment WHERE pid = ?')->execute([$pid]);
        $insert = $this->pdo->prepare(
            'INSERT OR REPLACE INTO comment (id, pid, by, ts, text, quote, rev, resolved_by, resolved_ts)
             VALUES (:id, :pid, :by, :ts, :text, :quote, :rev, :resolved_by, :resolved_ts)'
        );
        foreach ($comments as $c) {
            if (!\is_string($c['id'] ?? null) || !\is_string($c['by'] ?? null) || !\is_string($c['text'] ?? null)) {
                continue;
            }
            $resolved = \is_array($c['resolved'] ?? null) && \is_string($c['resolved']['by'] ?? null) ? $c['resolved'] : null;
            $insert->execute([
                'id' => $c['id'],
                'pid' => $pid,
                'by' => $c['by'],
                'ts' => \is_string($c['ts'] ?? null) ? $c['ts'] : '',
                'text' => $c['text'],
                'quote' => \is_string($c['quote'] ?? null) ? $c['quote'] : '',
                'rev' => \is_int($c['rev'] ?? null) ? $c['rev'] : 0,
                'resolved_by' => $resolved['by'] ?? null,
                'resolved_ts' => $resolved !== null ? (\is_string($resolved['ts'] ?? null) ? $resolved['ts'] : '') : null,
            ]);
        }
    }

    /** @return list<array<string, scalar|null>> oldest first, as recordDelivery() was given them */
    public function deliveries(string $pid): array
    {
        $out = [];
        foreach ($this->all('SELECT * FROM delivery WHERE pid = ? ORDER BY rowid', [$pid]) as $r) {
            $extra = \is_string($r['extra']) ? json_decode($r['extra'], true) : null;
            $out[] = ['to' => (string) $r['target'], 'rev' => (int) $r['rev'], 'at' => (string) $r['at'], 'by' => (string) $r['by'], 'outcome' => (string) $r['outcome']]
                + (\is_array($extra) ? $extra : []);
        }

        return $out;
    }

    /** @param array<string, scalar|null> $entry scalars only; the oldest past $keep are dropped */
    public function addDelivery(string $pid, array $entry, int $keep): void
    {
        $extra = array_diff_key($entry, array_flip(['to', 'rev', 'at', 'by', 'outcome']));
        $this->pdo->prepare('INSERT INTO delivery (pid, target, rev, at, by, outcome, extra) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([
            $pid,
            (string) ($entry['to'] ?? ''),
            (int) ($entry['rev'] ?? 0),
            (string) ($entry['at'] ?? ''),
            (string) ($entry['by'] ?? ''),
            (string) ($entry['outcome'] ?? ''),
            $extra !== [] ? json_encode($extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
        ]);
        $this->pdo->prepare(
            'DELETE FROM delivery WHERE pid = :pid AND rowid NOT IN (SELECT rowid FROM delivery WHERE pid = :pid ORDER BY rowid DESC LIMIT CAST(:keep AS INTEGER))'
        )->execute(['pid' => $pid, 'keep' => $keep]);
    }

    /** @return array{base_rev: int, saved: string, raw: bool, form: string}|null */
    public function draft(string $pid, string $username): ?array
    {
        $row = $this->one('SELECT * FROM draft WHERE pid = ? AND username = ?', [$pid, $username]);

        return $row !== null ? ['base_rev' => (int) $row['base_rev'], 'saved' => (string) $row['saved'], 'raw' => (int) $row['raw'] === 1, 'form' => (string) $row['form']] : null;
    }

    public function putDraft(string $pid, string $username, int $baseRev, bool $raw, string $form, string $saved): void
    {
        $this->pdo->prepare(
            'INSERT INTO draft (pid, username, base_rev, raw, form, saved) VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT(pid, username) DO UPDATE SET base_rev = excluded.base_rev, raw = excluded.raw, form = excluded.form, saved = excluded.saved'
        )->execute([$pid, $username, $baseRev, $raw ? 1 : 0, $form, $saved]);
    }

    public function dropDraft(string $pid, string $username): void
    {
        $this->pdo->prepare('DELETE FROM draft WHERE pid = ? AND username = ?')->execute([$pid, $username]);
    }

    /**
     * The next number of a D20 sequence, never below $floor + 1 (the
     * numbers already on disk or in the index), under BEGIN IMMEDIATE
     */
    public function nextCounter(string $key, int $floor): int
    {
        return $this->transaction(function () use ($key, $floor): int {
            $row = $this->one('SELECT value FROM counter WHERE key = ?', [$key]);
            $next = max($row !== null ? (int) $row['value'] : 0, $floor) + 1;
            $this->pdo->prepare('INSERT INTO counter (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')->execute([$key, $next]);

            return $next;
        });
    }

    /** @return array<string, int> every sequence's last number */
    public function counters(): array
    {
        $out = [];
        foreach ($this->all('SELECT key, value FROM counter ORDER BY key', []) as $r) {
            $out[(string) $r['key']] = (int) $r['value'];
        }

        return $out;
    }

    /**
     * @param list<scalar|null> $params
     *
     * @return array<string, mixed>|null
     */
    private function one(string $sql, array $params): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return \is_array($row) ? $row : null;
    }

    /**
     * @param list<scalar|null> $params
     *
     * @return list<array<string, mixed>>
     */
    private function all(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
