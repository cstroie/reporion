<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Storage;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Reporion\Exception\AtticException;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * db:recover (D54 §10): what of data/reporion.sqlite the disk can give back.
 * Every attic zip carries its page's record (pid, revisions, signatures,
 * status), so for each zip under attic/ (and trash/{pid}/attic.zip) the
 * page's row is made, or its path and mirror put right. Never removes a
 * row, never touches a file. What only the database held (comments, media
 * names, moves and redirects, deliveries, drafts) does not come back; the
 * accession counters are re-seeded from disk on their next use.
 *
 * The index is not touched: index:rebuild afterwards.
 */
final class StateRecovery
{
    private readonly StateDb $state;
    private readonly Attic $attic;

    public function __construct(private readonly string $dataRoot, ?string $statePath = null)
    {
        $this->state = new StateDb(Databases::openState($statePath ?? $dataRoot . '/reporion.sqlite'));
        $this->attic = new Attic();
    }

    /**
     * @return array{zips: int, created: int, repaired: int, unchanged: int, trashed: int, unreadable: int, conflicts: int, rows_without_zip: int}
     */
    public function run(bool $dryRun): array
    {
        $counts = ['zips' => 0, 'created' => 0, 'repaired' => 0, 'unchanged' => 0, 'trashed' => 0, 'unreadable' => 0, 'conflicts' => 0, 'rows_without_zip' => 0];
        $seen = [];
        foreach ($this->zips() as [$zip, $path, $trashed]) {
            ++$counts['zips'];
            try {
                $history = $this->attic->history($zip);
            } catch (AtticException) {
                ++$counts['unreadable'];
                continue;
            }
            $pid = $history['pid'];
            $seen[$pid] = true;
            $outcome = $this->one($zip, $path, $trashed, $history, $dryRun);
            ++$counts[$outcome];
            if ($trashed && $outcome !== 'conflicts' && $outcome !== 'unreadable') {
                ++$counts['trashed'];
            }
        }
        foreach ($this->state->livePaths() as $path) {
            $row = $this->state->pageAt($path);
            if ($row !== null && !isset($seen[(string) $row['pid']])) {
                ++$counts['rows_without_zip'];
            }
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $history
     *
     * @return 'created'|'repaired'|'unchanged'|'conflicts'
     */
    private function one(string $zip, ?string $path, bool $trashed, array $history, bool $dryRun): string
    {
        $pid = (string) $history['pid'];
        $row = $this->state->pageByPid($pid);
        if ($path === null) {
            // A trashed zip whose path note is gone: only an existing row knows where it was
            if ($row === null) {
                return 'conflicts';
            }
            $path = (string) $row['path'];
        }
        $holder = $trashed ? null : $this->state->pageAt($path);
        if ($holder !== null && (string) $holder['pid'] !== $pid) {
            // Another page's row has this path: a person decides
            return 'conflicts';
        }
        $file = $trashed ? \dirname($zip) . '/page.md' : $this->dataRoot . '/pages/' . str_replace(':', '/', $path) . '.md';
        $facts = self::facts($file, $history);
        $n = \count($history['revlog']);

        if ($row === null) {
            if (!$dryRun) {
                $first = $history['revlog'][0];
                $this->state->transaction(function () use ($pid, $path, $n, $history, $first, $facts, $file, $trashed, $zip): void {
                    $this->state->insertPage([
                        'pid' => $pid,
                        'path' => $path,
                        'rev' => $n,
                        'status' => (string) $history['status'],
                        'created' => (string) ($first['ts'] ?? ''),
                        'created_by' => (string) ($first['by'] ?? ''),
                        'visibility' => self::visibility($file),
                        'trashed_at' => $trashed ? date('Y-m-d\TH:i:sP', (int) filemtime($zip)) : null,
                        'trashed_by' => $trashed ? 'db:recover' : null,
                    ] + $facts);
                    $this->state->mirror($pid, $history);
                });
            }

            return 'created';
        }

        $logged = [];
        foreach ($history['revlog'] as $entry) {
            $logged[(int) $entry['n']] = (string) $entry['sha256'];
        }
        $signed = array_map(static fn (array $s): int => (int) $s['rev'], $history['signatures']);
        sort($signed);
        $agrees = (string) $row['path'] === $path && ($row['trashed_at'] !== null) === $trashed
            && $logged === $this->state->revisionShas($pid) && $signed === $this->state->signatureRevs($pid)
            && (int) $row['rev'] === $n && (string) $row['status'] === (string) $history['status'];
        if ($agrees) {
            return 'unchanged';
        }
        if (!$dryRun) {
            $this->state->transaction(function () use ($pid, $path, $history, $facts, $trashed, $row, $zip): void {
                $fields = ['path' => $path] + $facts;
                if ($trashed && $row['trashed_at'] === null) {
                    $fields += ['trashed_at' => date('Y-m-d\TH:i:sP', (int) filemtime($zip)), 'trashed_by' => 'db:recover'];
                } elseif (!$trashed) {
                    $fields += ['trashed_at' => null, 'trashed_by' => null];
                }
                $this->state->updatePage($pid, $fields);
                $this->state->mirror($pid, $history);
            });
        }

        return 'repaired';
    }

    /**
     * Every attic zip: the live ones with the path their place under attic/
     * names, the trashed ones with the path their note keeps (null if none)
     *
     * @return iterable<array{0: string, 1: ?string, 2: bool}>
     */
    private function zips(): iterable
    {
        $root = $this->dataRoot . '/attic';
        if (is_dir($root)) {
            $found = [];
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $item) {
                if ($item->isFile() && str_ends_with($item->getFilename(), '.zip')) {
                    $found[] = $item->getPathname();
                }
            }
            sort($found);
            foreach ($found as $zip) {
                yield [$zip, str_replace('/', ':', substr($zip, \strlen($root) + 1, -4)), false];
            }
        }
        foreach (glob($this->dataRoot . '/trash/*/' . FileTree::TRASH_ATTIC) ?: [] as $zip) {
            $note = \dirname($zip) . '/' . FileTree::TRASH_PATH;
            $path = is_file($note) ? trim((string) file_get_contents($note)) : '';
            yield [$zip, FileTree::isValidPath($path) ? $path : null, true];
        }
    }

    /**
     * @param array<string, mixed> $history
     *
     * @return array{file_sha: string, file_mtime: int, file_bytes: int}
     */
    private static function facts(string $file, array $history): array
    {
        if (is_file($file)) {
            $bytes = (string) file_get_contents($file);

            return ['file_sha' => hash('sha256', $bytes), 'file_mtime' => (int) filemtime($file), 'file_bytes' => \strlen($bytes)];
        }
        $last = $history['revlog'][array_key_last($history['revlog'])];

        return ['file_sha' => (string) $last['sha256'], 'file_mtime' => 0, 'file_bytes' => (int) ($last['bytes'] ?? 0)];
    }

    /** The page file's frontmatter visibility; private when it has none or does not read */
    private static function visibility(string $file): string
    {
        if (!is_file($file) || preg_match('/^---\n(.*?\n)---\n/s', (string) file_get_contents($file), $m) !== 1) {
            return 'private';
        }
        try {
            $frontmatter = Yaml::parse($m[1]);
        } catch (RuntimeException) {
            return 'private';
        }
        $visibility = \is_array($frontmatter) ? ($frontmatter['visibility'] ?? null) : null;

        return \in_array($visibility, ['private', 'unlisted', 'public'], true) ? $visibility : 'private';
    }
}
