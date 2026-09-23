<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Storage;

use DateTimeImmutable;
use InvalidArgumentException;
use Reporion\Exception\PageNotFoundException;
use Reporion\Exception\RevisionConflictException;
use Reporion\Index\IndexInterface;
use Reporion\Index\PageSnapshot;
use Reporion\Support\Ulid;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Disk is authoritative (CLAUDE.md invariant 1). See docs/architecture-storage-index.md
 * §5 for the write path this class implements: journal intent, rev file,
 * current.md, meta.json, index, journal done.
 *
 * Path scope note: this implementation keys a page's on-disk location by its
 * current path (1:1 with data/pages/), matching what milestone 1 needs.
 * Move/redirect support (§2 "Moves and redirects") is out of scope here and
 * will need a pid→path resolution step when it lands.
 */
final class FlatFile implements StorageInterface
{
    public function __construct(
        private readonly string $dataRoot,
        private readonly IndexInterface $index,
    ) {
    }

    public function create(string $path, array $frontmatter, string $body, string $actor, ?string $note = null): PageRecord
    {
        // Directory reservation (and therefore the collision-suffix decision,
        // docs/FORMATS.md §1) happens before the journal line because the
        // journal line must record the final allocated path. The gap this
        // leaves — an orphan empty directory if the process dies between
        // reserving it and writing the journal intent — is a few
        // microseconds wide and self-evident on disk (empty dir, no rev/,
        // no meta.json); nothing currently sweeps it automatically.
        $finalPath = $this->allocatePath($path);
        $dir = $this->pathToDir($finalPath);

        $pid = Ulid::generate();
        $document = $this->encodeDocument($frontmatter, $body);
        $bodySha = hash('sha256', $document);

        $journal = $this->journal();
        $journal->appendIntent('create', $pid, $finalPath, 1, null, $bodySha, $actor);

        $this->writeRevisionAndCurrent($dir, 1, $document);

        $now = self::now();
        $visibility = (string) ($frontmatter['visibility'] ?? 'private');
        $meta = [
            'pid' => $pid,
            'created' => $now,
            'created_by' => $actor,
            'path' => $finalPath,
            'rev' => 1,
            'revlog' => [self::revlogEntry(1, $now, $actor, $note, \strlen($document), $bodySha, 'create')],
            'signatures' => [],
            'status' => 'draft',
            'visibility' => $visibility,
            'share_token' => null,
            'locks' => null,
        ];
        $this->writeMeta($dir, $meta);

        $this->index->index($this->snapshot($dir, $meta, $frontmatter, $body, $document));

        $journal->appendDone($pid, 1);

        return new PageRecord($pid, $finalPath, 1, 'draft', $visibility, $frontmatter, $body, $meta['revlog'], $meta);
    }

    public function save(string $path, array $frontmatter, string $body, int $baseRev, string $actor, ?string $note = null): PageRecord
    {
        $dir = $this->pathToDir($path);
        if (!is_file($dir . '/meta.json')) {
            throw new PageNotFoundException();
        }

        $meta = $this->readMeta($dir);
        if ((int) $meta['rev'] !== $baseRev) {
            throw new RevisionConflictException($this->read($path), $baseRev);
        }

        $nextRev = (int) $meta['rev'] + 1;
        $document = $this->encodeDocument($frontmatter, $body);
        $bodySha = hash('sha256', $document);

        $journal = $this->journal();
        $journal->appendIntent('save', (string) $meta['pid'], $path, $nextRev, $baseRev, $bodySha, $actor);

        $this->writeRevisionAndCurrent($dir, $nextRev, $document);

        $now = self::now();
        $meta['rev'] = $nextRev;
        $meta['revlog'][] = self::revlogEntry($nextRev, $now, $actor, $note, \strlen($document), $bodySha, 'edit');
        $meta['visibility'] = (string) ($frontmatter['visibility'] ?? $meta['visibility']);
        // Archived (imported legacy) pages stay archived through an edit —
        // 'draft' would silently claim it as a native document in progress.
        // Everything else that gets edited again is, by definition, a draft
        // (a signed page not yet re-signed).
        if ($meta['status'] !== 'archived') {
            $meta['status'] = 'draft';
        }
        $this->writeMeta($dir, $meta);

        $this->index->index($this->snapshot($dir, $meta, $frontmatter, $body, $document));

        $journal->appendDone((string) $meta['pid'], $nextRev);

        return new PageRecord((string) $meta['pid'], $path, $nextRev, (string) $meta['status'], $meta['visibility'], $frontmatter, $body, $meta['revlog'], $meta);
    }

    public function read(string $path): PageRecord
    {
        $dir = $this->pathToDir($path);
        if (!is_file($dir . '/current.md') || !is_file($dir . '/meta.json')) {
            throw new PageNotFoundException();
        }

        $meta = $this->readMeta($dir);
        [$frontmatter, $body] = $this->parseDocument((string) file_get_contents($dir . '/current.md'));

        return new PageRecord(
            (string) $meta['pid'],
            (string) $meta['path'],
            (int) $meta['rev'],
            (string) $meta['status'],
            (string) $meta['visibility'],
            $frontmatter,
            $body,
            $meta['revlog'],
            $meta,
        );
    }

    public function readRevision(string $path, int $rev): string
    {
        $revFile = \sprintf('%s/rev/%04d.md.gz', $this->pathToDir($path), $rev);
        if (!is_file($revFile)) {
            throw new PageNotFoundException();
        }

        $plain = gzdecode((string) file_get_contents($revFile));
        if ($plain === false) {
            throw new RuntimeException('Corrupt revision file');
        }

        return $plain;
    }

    public function revisions(string $path): array
    {
        return $this->read($path)->revlog;
    }

    public function replayJournal(): array
    {
        $journal = $this->journal();
        $outcomes = [];

        foreach ($journal->files() as $file) {
            $intents = [];
            $done = [];
            foreach ($journal->readLines($file) as $line) {
                $key = ($line['pid'] ?? '') . '#' . ($line['rev'] ?? '');
                if (($line['state'] ?? null) === 'intent') {
                    $intents[$key] = $line;
                } elseif (($line['state'] ?? null) === 'done') {
                    $done[$key] = true;
                }
            }

            foreach ($intents as $key => $intent) {
                if (isset($done[$key])) {
                    continue;
                }
                $outcomes[] = $this->recoverIntent($journal, $intent);
            }
        }

        return $outcomes;
    }

    /**
     * @param array<string, mixed> $intent
     *
     * @return array{pid: string, rev: int, outcome: string}
     */
    private function recoverIntent(Journal $journal, array $intent): array
    {
        $path = (string) $intent['path'];
        $pid = (string) $intent['pid'];
        $rev = (int) $intent['rev'];
        $dir = $this->pathToDir($path);
        $revFile = \sprintf('%s/rev/%04d.md.gz', $dir, $rev);

        if (!is_file($revFile)) {
            // Crashed before the revision file was durably written: from the
            // reader's point of view this write never happened. Nothing to
            // recover, and no partial current.md/meta.json can exist yet
            // because both are only ever written after the rev file.
            return ['pid' => $pid, 'rev' => $rev, 'outcome' => 'discarded'];
        }

        $document = gzdecode((string) file_get_contents($revFile));
        if ($document === false) {
            return ['pid' => $pid, 'rev' => $rev, 'outcome' => 'corrupt'];
        }

        [$frontmatter, $body] = $this->parseDocument($document);
        $bodySha = hash('sha256', $document);

        AtomicWriter::put($dir . '/current.md', $document);

        $metaFile = $dir . '/meta.json';
        $meta = is_file($metaFile) ? $this->readMeta($dir) : [
            'pid' => $pid,
            'created' => (string) $intent['ts'],
            'created_by' => (string) $intent['actor'],
            'path' => $path,
            'rev' => 0,
            'revlog' => [],
            'signatures' => [],
            'status' => 'draft',
            'visibility' => (string) ($frontmatter['visibility'] ?? 'private'),
            'share_token' => null,
            'locks' => null,
        ];

        $hasEntry = false;
        foreach ($meta['revlog'] as $entry) {
            if (($entry['n'] ?? null) === $rev) {
                $hasEntry = true;
                break;
            }
        }
        if (!$hasEntry) {
            $kind = (string) $intent['op'] === 'create' ? 'create' : 'edit';
            $meta['revlog'][] = self::revlogEntry($rev, (string) $intent['ts'], (string) $intent['actor'], null, \strlen($document), $bodySha, $kind);
        }
        $meta['rev'] = max((int) $meta['rev'], $rev);
        $meta['visibility'] = (string) ($frontmatter['visibility'] ?? $meta['visibility']);
        $this->writeMeta($dir, $meta);

        $this->index->index($this->snapshot($dir, $meta, $frontmatter, $body, $document));

        $journal->appendDone($pid, $rev);

        return ['pid' => $pid, 'rev' => $rev, 'outcome' => 'recovered'];
    }

    private function writeRevisionAndCurrent(string $dir, int $rev, string $document): void
    {
        $revDir = $dir . '/rev';
        if (!is_dir($revDir) && !mkdir($revDir, 0775, true) && !is_dir($revDir)) {
            throw new RuntimeException('Cannot create revision directory');
        }

        $revFile = \sprintf('%s/%04d.md.gz', $revDir, $rev);
        $wrote = AtomicWriter::putOnce($revFile, (string) gzencode($document, 9));

        if ($wrote) {
            AtomicWriter::put($dir . '/current.md', $document);

            return;
        }

        // putOnce() returning false means this exact revision was already
        // written — a duplicate submission (retried journal replay, retried
        // request). current.md must mirror exactly what is on disk in the
        // rev file, never the freshly re-encoded $document (which could
        // differ byte-for-byte from a retried caller) — CLAUDE.md D2:
        // current.md is always the newest revision, byte for byte.
        $existing = gzdecode((string) file_get_contents($revFile));
        if ($existing === false) {
            throw new RuntimeException('Corrupt revision file');
        }
        AtomicWriter::put($dir . '/current.md', $existing);
    }

    private function allocatePath(string $requestedPath): string
    {
        $this->assertValidPath($requestedPath);

        $segments = explode(':', $requestedPath);
        $last = array_pop($segments);
        $namespaceDir = $this->dataRoot . '/pages/' . implode('/', $segments);

        if (!is_dir($namespaceDir) && !mkdir($namespaceDir, 0775, true) && !is_dir($namespaceDir)) {
            throw new RuntimeException('Cannot create namespace directory');
        }

        for ($suffix = 1;; $suffix++) {
            $candidateLast = $suffix === 1 ? $last : $last . '-' . $suffix;
            $candidateDir = $namespaceDir . '/' . $candidateLast;

            // mkdir() fails atomically (EEXIST) if another process just
            // claimed this exact candidate — that atomicity is what makes
            // the collision suffix race-safe (docs/FORMATS.md §1).
            if (@mkdir($candidateDir, 0775)) {
                return implode(':', [...$segments, $candidateLast]);
            }
            if (!is_dir($candidateDir)) {
                throw new RuntimeException('Cannot allocate page directory');
            }
        }
    }

    private function assertValidPath(string $path): void
    {
        if ($path === '' || str_starts_with($path, ':') || str_ends_with($path, ':') || str_contains($path, '::')) {
            throw new InvalidArgumentException('Invalid page path');
        }
        foreach (explode(':', $path) as $segment) {
            if ($segment === '' || str_contains($segment, '/')) {
                throw new InvalidArgumentException('Invalid page path');
            }
        }
    }

    private function pathToDir(string $path): string
    {
        return $this->dataRoot . '/pages/' . str_replace(':', '/', $path);
    }

    /**
     * @param array<string, mixed> $frontmatter
     */
    private function encodeDocument(array $frontmatter, string $body): string
    {
        $yaml = Yaml::dump($frontmatter, 4, 2);

        return "---\n" . $yaml . "---\n\n" . $this->normalizeText($body);
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function parseDocument(string $raw): array
    {
        if (!preg_match('/^---\n(.*?\n)---\n\n?(.*)$/s', $raw, $m)) {
            throw new RuntimeException('Malformed page document');
        }

        $frontmatter = Yaml::parse($m[1]);
        if (!\is_array($frontmatter)) {
            throw new RuntimeException('Malformed frontmatter');
        }

        return [$frontmatter, $m[2]];
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return rtrim($text, "\n") . "\n";
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $frontmatter
     */
    private function snapshot(string $dir, array $meta, array $frontmatter, string $body, string $document): PageSnapshot
    {
        $path = (string) $meta['path'];
        $segments = explode(':', $path);
        array_pop($segments);

        $lastEntry = $meta['revlog'][array_key_last($meta['revlog'])] ?? [];

        // The real mtime of current.md, not time() — verify()'s drift check
        // (docs/architecture-storage-index.md Table 1) compares this against
        // a fresh stat() of the same file, and those must actually agree.
        $mtime = filemtime($dir . '/current.md');

        return new PageSnapshot(
            pid: (string) $meta['pid'],
            path: $path,
            ns: implode(':', $segments),
            rev: (int) $meta['rev'],
            status: (string) $meta['status'],
            visibility: (string) $meta['visibility'],
            frontmatter: $frontmatter,
            body: $body,
            bytes: \strlen($document),
            mtime: $mtime !== false ? $mtime : time(),
            bodySha: hash('sha256', $document),
            updated: self::now(),
            updatedBy: (string) ($lastEntry['by'] ?? ''),
            note: $lastEntry['note'] ?? null,
            kind: (string) ($lastEntry['kind'] ?? 'edit'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function readMeta(string $dir): array
    {
        $decoded = json_decode((string) file_get_contents($dir . '/meta.json'), true);
        if (!\is_array($decoded)) {
            throw new RuntimeException('Corrupt meta.json');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function writeMeta(string $dir, array $meta): void
    {
        $json = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Cannot encode meta.json');
        }

        AtomicWriter::put($dir . '/meta.json', $json . "\n");
    }

    /**
     * @return array<string, mixed>
     */
    private static function revlogEntry(int $n, string $ts, string $actor, ?string $note, int $bytes, string $sha256, string $kind): array
    {
        return [
            'n' => $n,
            'ts' => $ts,
            'by' => $actor,
            'note' => $note,
            'bytes' => $bytes,
            'sha256' => $sha256,
            'minor' => false,
            'kind' => $kind,
        ];
    }

    private function journal(): Journal
    {
        return new Journal($this->dataRoot . '/journal');
    }

    private static function now(): string
    {
        return (new DateTimeImmutable('now'))->format('Y-m-d\TH:i:sP');
    }
}
