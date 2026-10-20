<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Storage;

use DateTimeInterface;
use JsonException;
use Reporion\Exception\AtticException;
use Reporion\Support\Revlog;
use Reporion\Support\Zip;
use Reporion\Support\ZipEntry;
use RuntimeException;

/**
 * A page's history as one zip (D54, docs/architecture-v2.md §4):
 * `0001.md` … `NNNN.md`, each revision's exact bytes, and `revlog.json`,
 * the legal record (`{v: 2, pid, status, archived, revlog, signatures}`),
 * so every zip verifies on its own.
 *
 * A zip cannot be appended to atomically, so every write builds the whole
 * container again, but no entry is ever changed (invariant 3): the earlier
 * entries are copied forward with their compressed bytes as they were,
 * and before the new zip replaces the old one it is read back from its
 * durable temp file and checked: exactly one entry per revision, each
 * matching its revlog sha256, every earlier entry byte-identical, every
 * earlier revlog entry and signature still there. A failed check leaves
 * the zip as it was (AtticException). The one exception is the minor edit
 * of an unsigned newest revision (replaceNewest()).
 *
 * The caller holds the page's lock and writes the journal line; this class
 * only owns the container.
 */
final class Attic
{
    public const HISTORY = 'revlog.json';
    public const FORMAT = 2;

    private const STATUSES = ['draft', 'signed', 'archived'];

    /**
     * @return array{v: int, pid: string, status: string, archived: ?array<string, mixed>, revlog: list<array<string, mixed>>, signatures: list<array<string, mixed>>}
     */
    public function history(string $zip): array
    {
        return $this->decodeHistory($this->entries($zip));
    }

    /** Revision $n's exact bytes, checked against its revlog sha256 */
    public function revision(string $zip, int $n): string
    {
        $entries = $this->entries($zip);
        $history = $this->decodeHistory($entries);
        $entry = $entries[self::entryName($n)] ?? null;
        if ($entry === null || !isset($history['revlog'][$n - 1])) {
            throw new AtticException('no_revision', $n);
        }
        $bytes = self::plain($entry, $n);
        if (!hash_equals((string) $history['revlog'][$n - 1]['sha256'], hash('sha256', $bytes))) {
            throw new AtticException('sha_mismatch', $n);
        }

        return $bytes;
    }

    /**
     * Add the newest revision: $history is the whole record after it
     * (its last revlog entry, chained, describes $document). Creates the
     * zip, and its directory, for revision 1.
     *
     * @param array<string, mixed> $history
     *
     * @return bool false: this exact revision and record are already there
     *              (a replayed or repeated write), nothing written
     */
    public function append(string $zip, string $document, array $history, DateTimeInterface $at): bool
    {
        $history = self::normalize($history);
        $n = \count($history['revlog']);
        $old = is_file($zip) ? $this->entries($zip) : null;
        $oldHistory = $old !== null ? $this->decodeHistory($old) : null;
        $have = $oldHistory !== null ? \count($oldHistory['revlog']) : 0;

        if ($old !== null && $have === $n && isset($old[self::entryName($n)])
            && hash_equals(hash('sha256', self::plain($old[self::entryName($n)], $n)), hash('sha256', $document))
            && $oldHistory === $history) {
            return false;
        }
        if ($have !== $n - 1) {
            throw new AtticException($have >= $n ? 'revision_exists' : 'gap', $n);
        }

        $entries = $old !== null ? self::withoutHistory($old) : [];
        $entries[] = ZipEntry::deflated(self::entryName($n), $document, $at);
        $this->write($zip, $entries, $old, $oldHistory, $history, $n, $at);

        return true;
    }

    /**
     * The minor edit (invariant 3's exception, `saveMinor()`): the newest
     * revision's entry replaced in place, refused once that revision
     * carries a signature. $history has the same number of revisions, its
     * last entry re-chained for $document.
     *
     * @param array<string, mixed> $history
     */
    public function replaceNewest(string $zip, string $document, array $history, DateTimeInterface $at): void
    {
        $history = self::normalize($history);
        $old = $this->entries($zip);
        $oldHistory = $this->decodeHistory($old);
        $n = \count($history['revlog']);
        if ($n !== \count($oldHistory['revlog']) || $n === 0) {
            throw new AtticException('not_newest', $n);
        }
        foreach ($oldHistory['signatures'] as $signature) {
            if ((int) ($signature['rev'] ?? 0) === $n) {
                throw new AtticException('signed', $n);
            }
        }

        $entries = [];
        foreach (self::withoutHistory($old) as $entry) {
            $entries[] = $entry->name === self::entryName($n) ? ZipEntry::deflated($entry->name, $document, $at) : $entry;
        }
        $this->write($zip, $entries, $old, $oldHistory, $history, $n, $at, replaced: true);
    }

    /**
     * The record changed without a revision: a signature added, the status
     * or the archived marker set. Every revlog entry stays as it is.
     *
     * @param array<string, mixed> $history
     */
    public function writeHistory(string $zip, array $history, DateTimeInterface $at): void
    {
        $history = self::normalize($history);
        $old = $this->entries($zip);
        $oldHistory = $this->decodeHistory($old);
        if ($history['revlog'] !== $oldHistory['revlog']) {
            throw new AtticException('history_changed');
        }
        $this->write($zip, self::withoutHistory($old), $old, $oldHistory, $history, null, $at);
    }

    /**
     * Everything wrong with a zip, for integrity:verify: unreadable,
     * an entry missing, extra, corrupt or not matching its revlog sha256,
     * the chain broken, a signature on a revision that is not there.
     *
     * @return list<array{rev: ?int, problem: string}>
     */
    public function problems(string $zip): array
    {
        try {
            $entries = $this->entries($zip);
            $history = $this->decodeHistory($entries);
        } catch (AtticException $e) {
            return [['rev' => null, 'problem' => $e->reason]];
        }

        return self::inspect($entries, $history);
    }

    public static function entryName(int $n): string
    {
        return sprintf('%04d.md', $n);
    }

    /**
     * @param list<ZipEntry>                $revisions  the revision entries to write, in order
     * @param array<string, ZipEntry>|null  $old        the zip's entries before, null when it is new
     * @param array<string, mixed>|null     $oldHistory
     * @param array<string, mixed>          $history    normalized
     * @param ?int                          $written    the revision this write adds or replaces
     */
    private function write(string $zip, array $revisions, ?array $old, ?array $oldHistory, array $history, ?int $written, DateTimeInterface $at, bool $replaced = false): void
    {
        self::assertShape($history);
        if ($oldHistory !== null) {
            self::assertGrows($oldHistory, $history, $replaced ? $written : null);
        }
        if ($written !== null) {
            foreach (Revlog::chainProblems($history['revlog'], $history['pid']) as [$n, $problem]) {
                if ($n >= $written) {
                    throw new AtticException($problem, $n);
                }
            }
            if (!\is_string($history['revlog'][$written - 1]['chain'] ?? null)) {
                throw new AtticException('chain_missing', $written);
            }
        }

        $entries = $revisions;
        $entries[] = ZipEntry::deflated(self::HISTORY, self::encode($history), $at);
        $bytes = Zip::assemble($entries);

        $dir = \dirname($zip);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new AtticException('no_directory');
        }

        $keep = $replaced && $written !== null ? self::entryName($written) : null;
        AtomicWriter::putChecked($zip, $bytes, static function (string $tmp) use ($old, $history, $keep): void {
            $read = self::parse((string) file_get_contents($tmp));
            $problems = self::inspect($read, $history);
            if ($problems !== []) {
                throw new AtticException($problems[0]['problem'], $problems[0]['rev']);
            }
            $written = self::normalize(self::decodeJson(self::plain($read[self::HISTORY], null)));
            if ($written !== $history) {
                throw new AtticException('history_mismatch');
            }
            foreach ($old ?? [] as $name => $entry) {
                if ($name === self::HISTORY || $name === $keep) {
                    continue;
                }
                if (!isset($read[$name]) || !$read[$name]->sameAs($entry)) {
                    throw new AtticException('entry_changed', (int) $name);
                }
            }
        });
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     */
    private static function assertGrows(array $old, array $new, ?int $replaced): void
    {
        if ($old['pid'] !== $new['pid']) {
            throw new AtticException('pid_changed');
        }
        foreach ($old['revlog'] as $i => $entry) {
            if ($i + 1 !== $replaced && ($new['revlog'][$i] ?? null) !== $entry) {
                throw new AtticException('history_changed', $i + 1);
            }
        }
        if (\array_slice($new['signatures'], 0, \count($old['signatures'])) !== $old['signatures']) {
            throw new AtticException('signature_removed');
        }
        if ($old['archived'] !== null && $new['archived'] !== $old['archived']) {
            throw new AtticException('archived_changed');
        }
    }

    /** @param array<string, mixed> $history */
    private static function assertShape(array $history): void
    {
        $ok = ($history['v'] ?? null) === self::FORMAT
            && \is_string($history['pid'] ?? null) && $history['pid'] !== ''
            && \in_array($history['status'] ?? null, self::STATUSES, true)
            && \array_key_exists('archived', $history) && ($history['archived'] === null || \is_array($history['archived']))
            && \is_array($history['revlog'] ?? null) && array_is_list($history['revlog'])
            && \is_array($history['signatures'] ?? null) && array_is_list($history['signatures']);
        if ($ok) {
            foreach ($history['revlog'] as $i => $entry) {
                $ok = $ok && \is_array($entry) && ($entry['n'] ?? null) === $i + 1
                    && \is_string($entry['sha256'] ?? null) && preg_match('/^[0-9a-f]{64}$/', $entry['sha256']) === 1
                    && \is_int($entry['bytes'] ?? null);
            }
            foreach ($history['signatures'] as $signature) {
                $ok = $ok && \is_array($signature) && \is_int($signature['rev'] ?? null)
                    && $signature['rev'] >= 1 && $signature['rev'] <= \count($history['revlog']);
            }
        }
        if (!$ok) {
            throw new AtticException('history_invalid');
        }
    }

    /**
     * @param array<string, ZipEntry> $entries
     * @param array<string, mixed>    $history
     *
     * @return list<array{rev: ?int, problem: string}>
     */
    private static function inspect(array $entries, array $history): array
    {
        $problems = [];
        $expected = [self::HISTORY => true];
        foreach ($history['revlog'] as $i => $logged) {
            $n = $i + 1;
            $name = self::entryName($n);
            $expected[$name] = true;
            if (!isset($entries[$name])) {
                $problems[] = ['rev' => $n, 'problem' => 'entry_missing'];
                continue;
            }
            try {
                $bytes = self::plain($entries[$name], $n);
            } catch (AtticException $e) {
                $problems[] = ['rev' => $n, 'problem' => $e->reason];
                continue;
            }
            if (!\is_string($logged['sha256'] ?? null) || !hash_equals($logged['sha256'], hash('sha256', $bytes))) {
                $problems[] = ['rev' => $n, 'problem' => 'sha_mismatch'];
            } elseif (($logged['bytes'] ?? null) !== \strlen($bytes)) {
                $problems[] = ['rev' => $n, 'problem' => 'size_mismatch'];
            }
        }
        foreach (array_keys($entries) as $name) {
            if (!isset($expected[$name])) {
                $problems[] = ['rev' => null, 'problem' => 'entry_unexpected'];
            }
        }
        foreach (Revlog::chainProblems($history['revlog'], (string) $history['pid']) as [$n, $problem]) {
            $problems[] = ['rev' => $n, 'problem' => $problem];
        }
        foreach ($history['signatures'] as $signature) {
            $rev = (int) ($signature['rev'] ?? 0);
            if ($rev < 1 || $rev > \count($history['revlog'])) {
                $problems[] = ['rev' => $rev, 'problem' => 'signature_orphan'];
            }
        }

        return $problems;
    }

    /** @return array<string, ZipEntry> */
    private function entries(string $zip): array
    {
        if (!is_file($zip)) {
            throw new AtticException('missing');
        }
        $bytes = @file_get_contents($zip);
        if ($bytes === false) {
            throw new AtticException('unreadable');
        }

        return self::parse($bytes);
    }

    /** @return array<string, ZipEntry> */
    private static function parse(string $bytes): array
    {
        try {
            return Zip::read($bytes);
        } catch (RuntimeException) {
            throw new AtticException('unreadable');
        }
    }

    /**
     * @param array<string, ZipEntry> $entries
     *
     * @return array{v: int, pid: string, status: string, archived: ?array<string, mixed>, revlog: list<array<string, mixed>>, signatures: list<array<string, mixed>>}
     */
    private function decodeHistory(array $entries): array
    {
        if (!isset($entries[self::HISTORY])) {
            throw new AtticException('history_missing');
        }
        $history = self::normalize(self::decodeJson(self::plain($entries[self::HISTORY], null)));
        self::assertShape($history);

        /** @var array{v: int, pid: string, status: string, archived: ?array<string, mixed>, revlog: list<array<string, mixed>>, signatures: list<array<string, mixed>>} $history */
        return $history;
    }

    /**
     * @param array<string, ZipEntry> $entries
     *
     * @return list<ZipEntry> the revision entries, in archive order
     */
    private static function withoutHistory(array $entries): array
    {
        unset($entries[self::HISTORY]);

        return array_values($entries);
    }

    private static function plain(ZipEntry $entry, ?int $n): string
    {
        try {
            return $entry->bytes();
        } catch (RuntimeException) {
            throw new AtticException('entry_corrupt', $n);
        }
    }

    /**
     * The record as JSON round-trips it, so what is checked is what is stored
     *
     * @param array<string, mixed> $history
     *
     * @return array<string, mixed>
     */
    private static function normalize(array $history): array
    {
        return self::decodeJson(self::encode($history));
    }

    /** @param array<string, mixed> $history */
    private static function encode(array $history): string
    {
        try {
            return json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        } catch (JsonException) {
            throw new AtticException('history_invalid');
        }
    }

    /** @return array<string, mixed> */
    private static function decodeJson(string $json): array
    {
        try {
            $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AtticException('history_unreadable');
        }
        if (!\is_array($value)) {
            throw new AtticException('history_unreadable');
        }

        return $value;
    }
}
