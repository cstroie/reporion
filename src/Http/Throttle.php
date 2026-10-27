<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Http;

use Reporion\Storage\AtomicWriter;

/**
 * Counted events by key over a sliding window (D56 hardening, 2026-10-27): failed sign-ins per
 * address, OAuth registrations per address, and the authorization codes already redeemed (a code
 * is good once). One small JSON file under `data/tmp/`, rewritten under a lock, keys stored only
 * as sha256 (no address, no code on disk), entries older than their window dropped on every
 * write. A status note, not data (invariant 1): deleting it only forgets recent failures.
 */
final class Throttle
{
    /** Entries kept at most: a flood of keys cannot grow the file without bound */
    private const MAX_KEYS = 5000;

    public function __construct(private readonly string $file)
    {
    }

    /** How many times $key happened in the last $window seconds */
    public function count(string $key, int $window): int
    {
        $now = time();
        $entries = $this->read()[hash('sha256', $key)] ?? [];

        return \count(array_filter($entries, static fn (int $t): bool => $t > $now - $window));
    }

    /** Records $key happening now; returns the count in the last $window seconds, this one included */
    public function hit(string $key, int $window): int
    {
        $id = hash('sha256', $key);
        $now = time();
        $count = 0;
        $this->update(static function (array $all) use ($id, $now, $window, &$count): array {
            $entries = array_values(array_filter($all[$id] ?? [], static fn (int $t): bool => $t > $now - $window));
            $entries[] = $now;
            $all[$id] = \array_slice($entries, -100);
            $count = \count($entries);

            return $all;
        });

        return $count;
    }

    /** Forgets $key (a successful sign-in clears its address's failures) */
    public function clear(string $key): void
    {
        $id = hash('sha256', $key);
        $this->update(static function (array $all) use ($id): array {
            unset($all[$id]);

            return $all;
        });
    }

    /** @return array<string, list<int>> */
    private function read(): array
    {
        $raw = is_file($this->file) ? @file_get_contents($this->file) : false;
        $data = \is_string($raw) ? json_decode($raw, true) : null;

        return \is_array($data) ? array_map(static fn (mixed $v): array => array_values(array_filter((array) $v, 'is_int')), $data) : [];
    }

    /** @param \Closure(array<string, list<int>>): array<string, list<int>> $change */
    private function update(\Closure $change): void
    {
        $dir = \dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            return;
        }
        $lock = @fopen($this->file . '.lock', 'c');
        if ($lock === false) {
            return;
        }
        try {
            flock($lock, LOCK_EX);
            $all = $change($this->read());
            // Anything a day old is past every window used here
            $cutoff = time() - 86400;
            $all = array_filter(array_map(static fn (array $e): array => array_values(array_filter($e, static fn (int $t): bool => $t > $cutoff)), $all));
            if (\count($all) > self::MAX_KEYS) {
                uasort($all, static fn (array $a, array $b): int => max($b) <=> max($a));
                $all = \array_slice($all, 0, self::MAX_KEYS, true);
            }
            AtomicWriter::put($this->file, (string) json_encode($all));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
