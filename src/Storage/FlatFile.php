<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Storage;

use DateTimeImmutable;
use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Reporion\Exception\PageNotFoundException;
use Reporion\Exception\RevisionConflictException;
use Reporion\Index\IndexInterface;
use Reporion\Index\PageSnapshot;
use Reporion\Support\Canonical;
use Reporion\Support\Fsync;
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
        $initialStatus = ($frontmatter['status'] ?? 'draft') === 'archived' ? 'archived' : 'draft';
        $meta = [
            'pid' => $pid,
            'created' => $now,
            'created_by' => $actor,
            'path' => $finalPath,
            'rev' => 1,
            'revlog' => [self::revlogEntry(1, $now, $actor, $note, \strlen($document), $bodySha, 'create')],
            'signatures' => [],
            'status' => $initialStatus,
            'visibility' => $visibility,
            'share_token' => null,
            'locks' => null,
        ];
        $this->writeMeta($dir, $meta);

        $this->index->index($this->snapshot($dir, $meta, $frontmatter, $body, $document));

        $journal->appendDone($pid, 1);

        // Read back rather than construct from $body/$frontmatter directly:
        // those are the caller's raw input, not necessarily what actually
        // got persisted (normalizeText() adds the trailing newline; a
        // duplicate submission racing writeRevisionAndCurrent() could even
        // have kept a different rev file's content). The caller must never
        // see a PageRecord that read($path) would then contradict.
        return $this->read($finalPath);
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

        // D3: correcting a signed report is a 'resign', not a plain 'edit'
        // — the revlog/history distinction between "further drafting" and
        // "this edit corrected what used to be the official signed
        // document" depends on capturing status BEFORE it gets overwritten
        // below.
        $kind = $meta['status'] === 'signed' ? 'resign' : 'edit';

        $now = self::now();
        $meta['rev'] = $nextRev;
        $meta['revlog'][] = self::revlogEntry($nextRev, $now, $actor, $note, \strlen($document), $bodySha, $kind);
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

        // Read back rather than construct from $body/$frontmatter directly —
        // same reasoning as create(): those are the caller's raw input, not
        // necessarily what got persisted.
        return $this->read($path);
    }

    /**
     * No $baseRev/conflict check, unlike save() — deliberately, not an
     * oversight. save()'s base_rev protects caller-supplied content from
     * silently overwriting an edit it never saw. revert() always appends
     * $toRev's own bytes as the new current revision regardless of what
     * happened in between — there is no unseen edit to clobber, because
     * revert never claims "the page was at rev X when I read it," only
     * "make rev $toRev's content current" (A2: a forward operation).
     */
    public function revert(string $path, int $toRev, string $actor, ?string $note = null): PageRecord
    {
        // readRevision() throws PageNotFoundException on a missing rev
        // file, which also covers "the page itself doesn't exist" — no
        // page ever has a rev/ directory without a meta.json alongside it.
        $document = $this->readRevision($path, $toRev);
        [$frontmatter, $body] = $this->parseDocument($document);

        $dir = $this->pathToDir($path);
        $meta = $this->readMeta($dir);
        $nextRev = (int) $meta['rev'] + 1;
        $bodySha = hash('sha256', $document);

        $journal = $this->journal();
        $journal->appendIntent('revert', (string) $meta['pid'], $path, $nextRev, (int) $meta['rev'], $bodySha, $actor);

        // $document is $toRev's own bytes, verbatim — not re-encoded from
        // $frontmatter/$body — so "revision N+1 equals revision $toRev" is
        // byte-for-byte true (A2), not just equal after normalisation.
        $this->writeRevisionAndCurrent($dir, $nextRev, $document);

        $now = self::now();
        $meta['rev'] = $nextRev;
        $meta['revlog'][] = self::revlogEntry($nextRev, $now, $actor, $note, \strlen($document), $bodySha, 'revert');
        $meta['visibility'] = (string) ($frontmatter['visibility'] ?? $meta['visibility']);
        // A reverted-to revision's status is never carried forward: a
        // signed revision restored this way is a new, unsigned draft that
        // needs signing again in its own right (D3 — correction is a new
        // revision, signed again), not a page silently claiming to be
        // signed with no signature record for this revision number.
        if ($meta['status'] !== 'archived') {
            $meta['status'] = 'draft';
        }
        $this->writeMeta($dir, $meta);

        $this->index->index($this->snapshot($dir, $meta, $frontmatter, $body, $document));

        $journal->appendDone((string) $meta['pid'], $nextRev);

        return $this->read($path);
    }

    public function sign(string $path, string $actor, array $schemaFields, ?string $parafa = null): PageRecord
    {
        $dir = $this->pathToDir($path);
        if (!is_file($dir . '/meta.json')) {
            throw new PageNotFoundException();
        }

        $meta = $this->readMeta($dir);
        $currentRev = (int) $meta['rev'];

        foreach ($meta['signatures'] as $existing) {
            if ((int) ($existing['rev'] ?? -1) === $currentRev) {
                // Already signed — a retried request, not a second
                // signature for the same revision.
                return $this->read($path);
            }
        }

        // current.md, not readRevision($currentRev): D2 makes them
        // byte-identical, and current.md is already open for every other
        // read here — readRevision() would gunzip a file whose plain
        // bytes are sitting right next to it.
        $document = (string) file_get_contents($dir . '/current.md');
        [$frontmatter, $body] = $this->parseDocument($document);
        $digest = hash('sha256', Canonical::bytes($frontmatter, $body, $schemaFields));

        $meta['signatures'][] = [
            'rev' => $currentRev,
            'by' => $actor,
            'ts' => self::now(),
            'alg' => 'sha256',
            'digest' => $digest,
            'parafa' => $parafa,
        ];
        $meta['status'] = 'signed';
        $this->writeMeta($dir, $meta);

        $this->index->index($this->snapshot($dir, $meta, $frontmatter, $body, $document));

        return $this->read($path);
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

    /**
     * Every real page directory under data/pages/, as colon paths — the
     * disk-walk that index:verify and index:rebuild need (their inputs are
     * caller-supplied iterables; nothing else produces them). A directory
     * counts as a page only if it has both current.md and meta.json, which
     * naturally excludes rev/ subdirectories, redirect stubs (no meta.json)
     * and anything else that isn't a real page — no special-casing needed.
     *
     * @return iterable<string>
     */
    public function allPaths(): iterable
    {
        $pagesRoot = $this->dataRoot . '/pages';
        if (!is_dir($pagesRoot)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($pagesRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if (!$item->isDir()) {
                continue;
            }

            $dir = $item->getPathname();
            if (!is_file($dir . '/meta.json') || !is_file($dir . '/current.md')) {
                continue;
            }

            $relative = ltrim(substr($dir, \strlen($pagesRoot)), '/');
            yield str_replace('/', ':', $relative);
        }
    }

    /**
     * The same PageSnapshot shape create()/save() already build for the
     * index, reconstructed for an existing page straight from disk — so
     * index:rebuild can feed Index\Sqlite::rebuild() without duplicating
     * the meta/frontmatter/body → snapshot mapping.
     */
    public function snapshotOf(string $path): PageSnapshot
    {
        $dir = $this->pathToDir($path);
        if (!is_file($dir . '/current.md') || !is_file($dir . '/meta.json')) {
            throw new PageNotFoundException();
        }

        $meta = $this->readMeta($dir);
        $document = (string) file_get_contents($dir . '/current.md');
        [$frontmatter, $body] = $this->parseDocument($document);

        return $this->snapshot($dir, $meta, $frontmatter, $body, $document);
    }

    public function replayJournal(int $minAgeSeconds = 0): array
    {
        $journal = $this->journal();
        $outcomes = [];
        foreach (self::stale($journal->openIntents(), $minAgeSeconds) as $intent) {
            $outcomes[] = $this->recoverIntent($journal, $intent);
        }

        return $outcomes;
    }

    /**
     * Journal intents still open and older than $minAgeSeconds — what
     * replayJournal() would act on (journal:replay --dry-run).
     *
     * @return list<array<string, mixed>>
     */
    public function staleIntents(int $minAgeSeconds): array
    {
        return self::stale($this->journal()->openIntents(), $minAgeSeconds);
    }

    /**
     * The front controller's crash recovery (decided 2026-09-26: replay at
     * boot, plus journal:replay). Cheap when there is nothing to do — the
     * journal is read incrementally — and it never waits: if another
     * request is already replaying, this one just carries on. Only intents
     * older than $minAgeSeconds count as crashed, since there is no page
     * write lock to tell a crashed write from one still running.
     *
     * @return list<array{pid: string, rev: int, outcome: string}>
     */
    public function replayCrashedWrites(int $minAgeSeconds): array
    {
        $journal = $this->journal();
        $stale = self::stale($journal->openIntentsSinceCheckpoint(), $minAgeSeconds);
        if ($stale === []) {
            return [];
        }

        $lock = fopen($this->dataRoot . '/journal/.replay.lock', 'c');
        if ($lock === false) {
            return [];
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return [];
            }
            // Re-read under the lock: whoever held it may have just finished these
            $outcomes = [];
            foreach (self::stale($journal->openIntentsSinceCheckpoint(), $minAgeSeconds) as $intent) {
                $outcomes[] = $this->recoverIntent($journal, $intent);
            }
            flock($lock, LOCK_UN);

            return $outcomes;
        } finally {
            fclose($lock);
        }
    }

    /**
     * @param list<array<string, mixed>> $intents
     *
     * @return list<array<string, mixed>>
     */
    private static function stale(array $intents, int $minAgeSeconds): array
    {
        if ($minAgeSeconds <= 0) {
            return $intents;
        }
        $cutoff = time() - $minAgeSeconds;

        return array_values(array_filter($intents, static function (array $intent) use ($cutoff): bool {
            $ts = strtotime((string) ($intent['ts'] ?? ''));

            return $ts !== false && $ts <= $cutoff;
        }));
    }

    /**
     * Soft delete: moves the page directory into trash/, intact.
     *
     * Known limitation, not built here: if the process dies between the
     * rename() below and index->remove(), the page is on disk in trash but
     * still present in the index — a real drift window, narrow but real
     * (index->remove() alone opens its own SQLite transaction). No journal
     * replay repairs it: recoverIntent() explicitly skips 'delete' journal
     * ops rather than misapplying its create/save recovery logic to them —
     * that intent line exists as a record of "a delete was attempted here",
     * not as something replay can act on (rev's rev/NNNN.md.gz has, by the
     * time anyone would replay it, already moved to trash with the rest of
     * the page). Disk stays authoritative regardless (invariant 1) — the
     * page genuinely is gone from data/pages/ — so this is an
     * index-verify-class problem, not a data-loss one, and index:verify /
     * index:rebuild (neither built yet) are the intended fix once they exist.
     *
     * @throws PageNotFoundException
     */
    public function delete(string $path, string $actor): void
    {
        $dir = $this->pathToDir($path);
        if (!is_file($dir . '/meta.json')) {
            throw new PageNotFoundException();
        }

        $meta = $this->readMeta($dir);
        $pid = (string) $meta['pid'];
        $rev = (int) $meta['rev'];
        $lastEntry = $meta['revlog'][array_key_last($meta['revlog'])] ?? [];
        $bodySha = (string) ($lastEntry['sha256'] ?? '');

        $trashDir = $this->allocateTrashPath($path, $pid);

        $journal = $this->journal();
        $journal->appendIntent('delete', $pid, $path, $rev, null, $bodySha, $actor);

        if (!rename($dir, $trashDir)) {
            throw new RuntimeException('Cannot move page directory to trash');
        }
        Fsync::directory(\dirname($trashDir));
        Fsync::directory(\dirname($dir));

        $this->index->remove($pid);

        $journal->appendDone($pid, $rev, 'delete');
    }

    public function move(string $from, string $to, string $actor): PageRecord
    {
        $this->assertValidPath($to);
        $fromDir = $this->pathToDir($from);
        if (!is_file($fromDir . '/meta.json')) {
            throw new PageNotFoundException();
        }
        if ($to === $from) {
            throw new InvalidArgumentException('The page is already at that path');
        }
        foreach (scandir($fromDir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && $entry !== 'rev' && is_dir($fromDir . '/' . $entry)) {
                // A rename would carry the child pages along without their
                // own paths, index rows or journal lines ever changing
                throw new InvalidArgumentException('The page has pages under it; move those first');
            }
        }

        $toDir = $this->pathToDir($to);
        if (file_exists($toDir)) {
            if (!$this->isStub($toDir)) {
                throw new InvalidArgumentException('Another page already has that path');
            }
            // A redirect stub may be reused — typically moving a page back
            @unlink($toDir . '/redirect');
            if (!@rmdir($toDir)) {
                throw new InvalidArgumentException('Another page already has that path');
            }
        }
        $parent = \dirname($toDir);
        if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
            throw new RuntimeException('Cannot create namespace directory');
        }

        $meta = $this->readMeta($fromDir);
        $pid = (string) $meta['pid'];
        $rev = (int) $meta['rev'];
        $lastEntry = $meta['revlog'][array_key_last($meta['revlog'])] ?? [];

        $journal = $this->journal();
        $journal->appendIntent('move', $pid, $to, $rev, null, (string) ($lastEntry['sha256'] ?? ''), $actor, ['from' => $from]);

        if (!rename($fromDir, $toDir)) {
            throw new RuntimeException('Cannot move page directory');
        }
        Fsync::directory($parent);
        Fsync::directory(\dirname($fromDir));

        $this->finishMove($meta, $from, $to, $actor, self::now());
        $journal->appendDone($pid, $rev, 'move');

        return $this->read($to);
    }

    public function redirectTarget(string $path): ?string
    {
        foreach (explode(':', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, '/')) {
                return null;
            }
        }
        $dir = $this->pathToDir($path);
        if (!$this->isStub($dir)) {
            return null;
        }
        $target = trim((string) file_get_contents($dir . '/redirect'));

        return $target !== '' ? $target : null;
    }

    public function trash(): array
    {
        $deletions = $this->deletionsFromJournal();
        $entries = [];
        foreach (glob($this->dataRoot . '/trash/*/meta.json') ?: [] as $metaFile) {
            $dir = \dirname($metaFile);
            $meta = $this->readMeta($dir);
            $pid = (string) $meta['pid'];
            $title = (string) $meta['path'];
            try {
                [$frontmatter] = $this->parseDocument((string) file_get_contents($dir . '/current.md'));
                $title = \is_string($frontmatter['title'] ?? null) && $frontmatter['title'] !== '' ? $frontmatter['title'] : $title;
            } catch (RuntimeException) {
                // an unparseable page is still listed, by path
            }
            $entries[] = [
                'pid' => $pid,
                'path' => (string) $meta['path'],
                'title' => $title,
                'status' => (string) $meta['status'],
                'signed' => ($meta['signatures'] ?? []) !== [],
                'deletedAt' => $deletions[$pid]['ts'] ?? null,
                'deletedBy' => $deletions[$pid]['actor'] ?? null,
            ];
        }
        usort($entries, static fn (array $a, array $b): int => strcmp((string) $b['deletedAt'], (string) $a['deletedAt']));

        return $entries;
    }

    public function restore(string $pid, string $actor): PageRecord
    {
        $trashDir = $this->trashDirOf($pid);
        $meta = $this->readMeta($trashDir);
        $target = $this->allocatePath((string) $meta['path']);
        $targetDir = $this->pathToDir($target);
        $rev = (int) $meta['rev'];

        $journal = $this->journal();
        $journal->appendIntent('restore', $pid, $target, $rev, null, '', $actor);

        // allocatePath() claimed the directory with mkdir; hand it back
        // empty so rename() can take the name
        rmdir($targetDir);
        if (!rename($trashDir, $targetDir)) {
            throw new RuntimeException('Cannot restore page directory');
        }
        Fsync::directory(\dirname($targetDir));
        Fsync::directory(\dirname($trashDir));

        $meta['path'] = $target;
        $this->writeMeta($targetDir, $meta);
        $this->reindexDir($targetDir, $meta);
        $journal->appendDone($pid, $rev, 'restore');

        return $this->read($target);
    }

    public function purge(string $pid, string $actor, bool $includeSigned = false): void
    {
        $trashDir = $this->trashDirOf($pid);
        $meta = $this->readMeta($trashDir);
        if (($meta['signatures'] ?? []) !== [] && !$includeSigned) {
            throw new InvalidArgumentException('Signed content is purged only with an explicit override (D3b)');
        }

        $journal = $this->journal();
        $journal->appendIntent('purge', $pid, (string) $meta['path'], (int) $meta['rev'], null, '', $actor);
        self::removeTree($trashDir);
        Fsync::directory(\dirname($trashDir));
        $journal->appendDone($pid, (int) $meta['rev'], 'purge');
    }

    /**
     * The part of a move after the rename — shared with journal replay:
     * the stub at the old path, earlier stubs repointed (chains collapse
     * at write time), meta.json's path and `moves`, the index row.
     *
     * @param array<string, mixed> $meta the page's meta.json as it was before the move
     */
    private function finishMove(array $meta, string $from, string $to, string $actor, string $ts): void
    {
        $this->writeStub($this->pathToDir($from), $to);
        foreach ((array) ($meta['moves'] ?? []) as $earlier) {
            $earlierFrom = (string) ($earlier['from'] ?? '');
            if ($earlierFrom !== '' && $earlierFrom !== $to && $this->isStub($this->pathToDir($earlierFrom))) {
                $this->writeStub($this->pathToDir($earlierFrom), $to);
            }
        }

        $toDir = $this->pathToDir($to);
        $meta['path'] = $to;
        $meta['moves'] = [...(array) ($meta['moves'] ?? []), ['from' => $from, 'to' => $to, 'ts' => $ts, 'by' => $actor]];
        $this->writeMeta($toDir, $meta);
        $this->reindexDir($toDir, $meta);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function reindexDir(string $dir, array $meta): void
    {
        $document = (string) file_get_contents($dir . '/current.md');
        [$frontmatter, $body] = $this->parseDocument($document);
        $this->index->index($this->snapshot($dir, $meta, $frontmatter, $body, $document));
    }

    private function isStub(string $dir): bool
    {
        return is_file($dir . '/redirect') && !is_file($dir . '/meta.json');
    }

    private function writeStub(string $dir, string $to): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create redirect stub');
        }
        AtomicWriter::put($dir . '/redirect', $to . "\n");
    }

    private function trashDirOf(string $pid): string
    {
        if (preg_match('/^[0-9A-Za-z]+$/', $pid) !== 1) {
            throw new PageNotFoundException();
        }
        $matches = glob($this->dataRoot . '/trash/*.' . $pid) ?: [];
        if ($matches === [] || !is_file($matches[0] . '/meta.json')) {
            throw new PageNotFoundException();
        }

        return $matches[0];
    }

    /**
     * When and by whom each trashed pid was deleted, from the journal's
     * `delete` intents (the last one wins).
     *
     * @return array<string, array{ts: string, actor: string}>
     */
    private function deletionsFromJournal(): array
    {
        $journal = $this->journal();
        $found = [];
        foreach ($journal->files() as $file) {
            foreach ($journal->readLines($file) as $line) {
                if (($line['op'] ?? null) === 'delete' && ($line['state'] ?? null) === 'intent') {
                    $found[(string) $line['pid']] = ['ts' => (string) ($line['ts'] ?? ''), 'actor' => (string) ($line['actor'] ?? '')];
                }
            }
        }

        return $found;
    }

    private static function removeTree(string $dir): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    private function allocateTrashPath(string $path, string $pid): string
    {
        $segments = explode(':', $path);
        $lastSegment = (string) end($segments);

        $trashRoot = $this->dataRoot . '/trash';
        if (!is_dir($trashRoot) && !mkdir($trashRoot, 0775, true) && !is_dir($trashRoot)) {
            throw new RuntimeException('Cannot create trash directory');
        }

        // pid is already globally unique, so unlike allocatePath() for
        // create(), no collision-retry loop is needed here.
        return $trashRoot . '/' . $lastSegment . '.' . $pid;
    }

    /**
     * Recovers one intent and closes it. A discarded one (the write never
     * happened) is closed too, or every later replay — one per request,
     * once replay runs at boot — would find it again; only a corrupt
     * revision stays open for a human to look at.
     *
     * @param array<string, mixed> $intent
     *
     * @return array{pid: string, rev: int, outcome: string}
     */
    private function recoverIntent(Journal $journal, array $intent): array
    {
        $outcome = $this->recoverIntentOnce($journal, $intent);
        if ($outcome['outcome'] === 'discarded') {
            $op = $intent['op'] ?? null;
            $journal->appendDone($outcome['pid'], $outcome['rev'], \in_array($op, ['move', 'restore', 'purge', 'delete'], true) ? (string) $op : null);
        }

        return $outcome;
    }

    /**
     * @param array<string, mixed> $intent
     *
     * @return array{pid: string, rev: int, outcome: string}
     */
    private function recoverIntentOnce(Journal $journal, array $intent): array
    {
        $op = $intent['op'] ?? null;
        if ($op === 'move' || $op === 'restore') {
            $dir = $this->pathToDir((string) $intent['path']);
            if (!is_file($dir . '/meta.json')) {
                // The rename never happened: the page is still where it was
                return ['pid' => (string) $intent['pid'], 'rev' => (int) $intent['rev'], 'outcome' => 'discarded'];
            }
            $meta = $this->readMeta($dir);
            if ($op === 'move' && (string) $meta['path'] !== (string) $intent['path']) {
                $this->finishMove($meta, (string) ($intent['from'] ?? $meta['path']), (string) $intent['path'], (string) $intent['actor'], (string) $intent['ts']);
            } else {
                $meta['path'] = (string) $intent['path'];
                $this->writeMeta($dir, $meta);
                $this->reindexDir($dir, $meta);
            }
            $journal->appendDone((string) $intent['pid'], (int) $intent['rev'], (string) $op);

            return ['pid' => (string) $intent['pid'], 'rev' => (int) $intent['rev'], 'outcome' => 'recovered'];
        }
        if ($op === 'purge') {
            // Whatever is left of the trashed copy stays in trash/ for the
            // next purge run; nothing live depends on it
            return ['pid' => (string) $intent['pid'], 'rev' => (int) $intent['rev'], 'outcome' => 'discarded'];
        }
        if (($intent['op'] ?? null) === 'delete') {
            // Not create/save recovery's job — see delete()'s own docblock
            // for the (narrow, disk-stays-authoritative) known gap here.
            return ['pid' => (string) $intent['pid'], 'rev' => (int) $intent['rev'], 'outcome' => 'discarded'];
        }

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

        if (is_file($dir . '/meta.json') && (int) $this->readMeta($dir)['rev'] > $rev) {
            // A later write already went through: this revision is in
            // history, and current.md must stay the newer one
            $journal->appendDone($pid, $rev);

            return ['pid' => $pid, 'rev' => $rev, 'outcome' => 'superseded'];
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
            // A crashed revert recovered here must still say 'revert' in
            // the revlog, not 'edit' — the audit trail (D3/D37) depends on
            // this being the true operation, not whatever recovery
            // defaults to. Same for a crashed save() that was correcting a
            // signed page ('resign', D3): $meta at this point is still the
            // PRE-crash meta.json (the crash happened before it was
            // rewritten), so $meta['status'] here is genuinely the status
            // this write is correcting FROM, not the new one.
            $kind = match (true) {
                $intent['op'] === 'create' => 'create',
                $intent['op'] === 'revert' => 'revert',
                $meta['status'] === 'signed' => 'resign',
                default => 'edit',
            };
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
            if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, '/') || str_contains($segment, "\0")) {
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
     * Same LF-only regex as Support\DocumentFormat::parse() — deliberately
     * NOT given the same CRLF-normalizing fix. Every call site here parses
     * content this class's own encodeDocument() wrote, which runs
     * normalizeText() (LF-only) and Yaml::dump() (also LF-only) before this
     * ever sees it; there is no browser-textarea boundary on this path. A
     * pre-existing regex twin, not a missed fix.
     *
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
            updated: (string) ($lastEntry['ts'] ?? self::now()),
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
