<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Storage;

use DateTimeImmutable;
use Reporion\Support\Fsync;
use RuntimeException;

/**
 * data/journal/YYYY-MM-DD.ndjson (docs/FORMATS.md §2). Appended and fsynced
 * before any page file is touched; replay treats a line with no matching
 * "done" as an incomplete write.
 */
final class Journal
{
    public function __construct(private readonly string $dir)
    {
    }

    /**
     * @param array<string, scalar|null> $extra op-specific fields (a move's `from`)
     */
    public function appendIntent(string $op, string $pid, string $path, int $rev, ?int $baseRev, string $bodySha, string $actor, array $extra = []): void
    {
        $this->append($extra + [
            'ts' => self::now(),
            'op' => $op,
            'pid' => $pid,
            'path' => $path,
            'rev' => $rev,
            'base_rev' => $baseRev,
            'body_sha' => $bodySha,
            'actor' => $actor,
            'state' => 'intent',
        ]);
    }

    /**
     * @param ?string $op the intent's op, for ops that keep the page's rev
     *                    (move, restore, purge, delete) — see key()
     */
    public function appendDone(string $pid, int $rev, ?string $op = null): void
    {
        $line = [
            'ts' => self::now(),
            'pid' => $pid,
            'rev' => $rev,
            'state' => 'done',
        ];
        if ($op !== null) {
            $line['op'] = $op;
        }
        $this->append($line);
    }

    /**
     * Which intent a line belongs to. Writes (create/save/revert/sign/
     * import) mint a new rev, so pid#rev names them; move, restore, purge
     * and delete keep the page's rev and would collide with that rev's own
     * earlier "done", so their op is part of the key.
     *
     * @param array<string, mixed> $line
     */
    public static function key(array $line): string
    {
        $key = ($line['pid'] ?? '') . '#' . ($line['rev'] ?? '');
        $op = $line['op'] ?? null;

        return \in_array($op, ['move', 'restore', 'purge', 'delete'], true) ? $key . '#' . $op : $key;
    }

    /**
     * Intents with no later matching "done", in journal order.
     *
     * @return list<array<string, mixed>>
     */
    public function openIntents(): array
    {
        $open = [];
        foreach ($this->files() as $file) {
            foreach ($this->readLines($file) as $line) {
                self::apply($open, $line);
            }
        }

        return array_values($open);
    }

    /**
     * openIntents(), but reading only what was appended since the last call:
     * the position reached and the intents still open there are kept in
     * .checkpoint.json, so the check the front controller runs on every
     * request costs a stat and a small read. The checkpoint is disposable —
     * missing or unreadable, the next call scans everything again.
     *
     * @return list<array<string, mixed>>
     */
    public function openIntentsSinceCheckpoint(): array
    {
        $checkpointFile = $this->dir . '/.checkpoint.json';
        $checkpoint = is_file($checkpointFile) ? json_decode((string) file_get_contents($checkpointFile), true) : null;
        $files = $this->files();
        if (!\is_array($checkpoint) || !\is_string($checkpoint['file'] ?? null) || !\is_int($checkpoint['offset'] ?? null)
            || !\is_array($checkpoint['open'] ?? null) || !\in_array($this->dir . '/' . $checkpoint['file'], $files, true)) {
            $checkpoint = ['file' => '', 'offset' => 0, 'open' => []];
        }

        /** @var array<string, array<string, mixed>> $open */
        $open = $checkpoint['open'];
        $position = [$checkpoint['file'], $checkpoint['offset']];
        foreach ($files as $file) {
            $name = basename($file);
            if (strcmp($name, $checkpoint['file']) < 0) {
                continue;
            }
            $offset = $name === $checkpoint['file'] ? $checkpoint['offset'] : 0;
            clearstatcache(true, $file);
            $size = (int) filesize($file);
            if ($size > $offset) {
                $fh = fopen($file, 'rb');
                if ($fh === false) {
                    break;
                }
                fseek($fh, $offset);
                $chunk = (string) stream_get_contents($fh);
                fclose($fh);
                // Up to and including the last newline — a line still being
                // appended has none yet, and is left for next time
                $complete = substr($chunk, 0, (int) strrpos("\n" . $chunk, "\n"));
                foreach (explode("\n", $complete) as $raw) {
                    $decoded = $raw === '' ? null : json_decode($raw, true);
                    if (\is_array($decoded)) {
                        self::apply($open, $decoded);
                    }
                }
                $offset += \strlen($complete);
            }
            $position = [$name, $offset];
        }

        if ($position !== [$checkpoint['file'], $checkpoint['offset']] || $open !== $checkpoint['open']) {
            AtomicWriter::put($checkpointFile, (string) json_encode(
                ['file' => $position[0], 'offset' => $position[1], 'open' => $open],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));
        }

        return array_values($open);
    }

    /**
     * @param array<string, array<string, mixed>> $open
     * @param array<string, mixed> $line
     */
    private static function apply(array &$open, array $line): void
    {
        $key = self::key($line);
        if (($line['state'] ?? null) === 'intent') {
            $open[$key] = $line;
        } elseif (($line['state'] ?? null) === 'done') {
            unset($open[$key]);
            if (!isset($line['op'])) {
                // Written before done lines carried an op: a delete's
                // done looked exactly like this, so it closes that too
                unset($open[$key . '#delete']);
            }
        }
    }

    /**
     * @return list<string> absolute paths, oldest date first
     */
    public function files(): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }

        $files = glob($this->dir . '/*.ndjson') ?: [];
        sort($files);

        return $files;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function readLines(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $lines = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $raw) {
            $decoded = json_decode($raw, true);
            if (\is_array($decoded)) {
                $lines[] = $decoded;
            }
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $line
     */
    private function append(array $line): void
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new RuntimeException('Cannot create journal directory');
        }

        // Same clock reading drives both the filename and the "ts" field
        // already in $line (set by appendIntent()/appendDone()) — using
        // gmdate() here while ts carries a local offset would let an intent
        // near midnight land in a different day's file than its own timestamp
        // claims.
        $now = new DateTimeImmutable('now');
        $file = $this->dir . '/' . $now->format('Y-m-d') . '.ndjson';
        $fh = fopen($file, 'ab');
        if ($fh === false) {
            throw new RuntimeException('Cannot open journal file');
        }

        try {
            if (!flock($fh, LOCK_EX)) {
                throw new RuntimeException('Cannot lock journal file');
            }
            fwrite($fh, json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            fflush($fh);
            flock($fh, LOCK_UN);
        } finally {
            fclose($fh);
        }

        Fsync::file($file);
        Fsync::directory($this->dir);
    }

    private static function now(): string
    {
        return (new DateTimeImmutable('now'))->format('Y-m-d\TH:i:s.vP');
    }
}
