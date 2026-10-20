<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Storage;

use Closure;
use DateTimeImmutable;
use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Reporion\Auth\User;
use Reporion\Exception\AtticException;
use Reporion\Exception\PageExistsException;
use Reporion\Exception\PageNotFoundException;
use Reporion\Exception\RevisionConflictException;
use Reporion\Index\IndexInterface;
use Reporion\Index\PageSnapshot;
use Reporion\Support\Canonical;
use Reporion\Support\Exams;
use Reporion\Support\Fsync;
use Reporion\Support\PageTitle;
use Reporion\Support\ReportPath;
use Reporion\Support\Revlog;
use Reporion\Support\Ulid;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * The 2.0 storage driver (D54, docs/architecture-v2.md): a page is a file.
 *
 *   data/pages/{ns…}/{slug}.md     the current state: frontmatter (with `pid`) + body
 *   data/attic/{ns…}/{slug}.zip    every revision and the legal record (Attic)
 *   data/reporion.sqlite           the pid <-> path binding and everything else (StateDb)
 *   data/trash/{pid}/              page.md + attic.zip of a deleted page
 *
 * Every write, under one write lock: journal intent, attic zip (verified
 * before its rename), page file, one state transaction, index, journal
 * done. Replay finishes a write from the zip, which is authoritative for
 * history: the state DB's revision and signature rows only mirror it.
 *
 * A page and a namespace of the same name never share a directory any
 * more (`a/b.md` beside `a/b/`), so moving or deleting a page with pages
 * under it is an ordinary move or delete of that one page.
 */
final class FileTree implements StorageInterface
{
    /** The frontmatter key Storage writes the pid under (§5); never the caller's */
    public const PID_KEY = 'pid';

    /** The most comments a page keeps (D52) */
    public const COMMENTS_MAX = 500;

    /** The largest server autosave draft kept, in bytes of the form text (D25) */
    public const DRAFT_MAX_BYTES = 2 * 1024 * 1024;

    /** How many delivery records a page keeps (recordDelivery()) */
    public const DELIVERIES_KEPT = 200;

    /** reconcilePath()'s answers */
    public const PATH_OK = 'ok';
    public const PATH_REPAIRED = 'repaired';
    public const PATH_BUSY = 'busy';
    /** A page file whose pid another live page has, with its own file: a copy, left alone */
    public const PATH_COPY = 'copy';

    /** Attachable image types (getimagesize type → extension); no SVG, which can carry script */
    private const MEDIA_TYPES = [
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];

    private const PAGE_EXT = '.md';
    private const ATTIC_EXT = '.zip';
    private const TRASH_PAGE = 'page.md';
    private const TRASH_ATTIC = 'attic.zip';

    private readonly StateDb $state;
    private readonly Attic $attic;

    /** @var resource|null */
    private $lock = null;
    private int $lockDepth = 0;

    public function __construct(
        private readonly string $dataRoot,
        private readonly IndexInterface $index,
        ?string $statePath = null,
    ) {
        if (!is_dir($dataRoot) && !@mkdir($dataRoot, 0775, true) && !is_dir($dataRoot)) {
            throw new RuntimeException('Cannot create the data directory');
        }
        $this->state = new StateDb(Databases::openState($statePath ?? $dataRoot . '/reporion.sqlite'));
        $this->attic = new Attic();
    }

    public function create(string $path, array $frontmatter, string $body, string $actor, ?string $note = null, bool $auto = false, bool $exclusive = false): PageRecord
    {
        return $this->locked(function () use ($path, $frontmatter, $body, $actor, $note, $auto, $exclusive): PageRecord {
            $finalPath = $this->allocatePath($path, $exclusive);
            $pid = Ulid::generate();
            // Every report is written in the one exams shape (phase 27, docs/FORMATS.md §12)
            if (ReportPath::isReport($finalPath)) {
                $frontmatter = Exams::normalize(self::withoutPid($frontmatter));
            }
            $document = $this->encodeDocument($frontmatter, $body, $pid);
            $sha = hash('sha256', $document);

            $journal = $this->journal();
            $journal->appendIntent('create', $pid, $finalPath, 1, null, $sha, $actor);

            $now = self::now();
            $history = [
                'v' => Attic::FORMAT,
                'pid' => $pid,
                'status' => ($frontmatter['status'] ?? 'draft') === 'archived' ? 'archived' : 'draft',
                'archived' => null,
                'revlog' => [Revlog::chained(self::revlogEntry(1, $now, $actor, $note, \strlen($document), $sha, 'create', $auto), [], $pid)],
                'signatures' => [],
            ];
            $this->attic->append($this->atticFile($finalPath), $document, $history, self::at($now));
            $this->writePageFile($finalPath, $document);

            $this->state->transaction(function () use ($finalPath, $pid, $history, $now, $actor, $frontmatter): void {
                $this->state->dropRedirect($finalPath);
                $this->state->insertPage([
                    'pid' => $pid,
                    'path' => $finalPath,
                    'rev' => 1,
                    'status' => $history['status'],
                    'created' => $now,
                    'created_by' => $actor,
                    'visibility' => (string) ($frontmatter['visibility'] ?? 'private'),
                ] + $this->fileFacts($finalPath));
                $this->state->mirror($pid, $history);
            });
            $this->reindex($finalPath);
            $journal->appendDone($pid, 1);

            // Read back: what was persisted, not the caller's raw input
            return $this->read($finalPath);
        });
    }

    public function save(string $path, array $frontmatter, string $body, int $baseRev, string $actor, ?string $note = null, bool $auto = false, bool $minor = false): PageRecord
    {
        return $this->locked(function () use ($path, $frontmatter, $body, $baseRev, $actor, $note, $auto, $minor): PageRecord {
            $row = $this->liveRow($path);
            $pid = (string) $row['pid'];
            $history = $this->historyOf($path);
            $rev = \count($history['revlog']);
            if ($rev !== $baseRev) {
                throw new RevisionConflictException($this->read($path), $baseRev);
            }

            $frontmatter = self::withoutPid($frontmatter);
            // The one exams shape (phase 27): a top-level value changed since the
            // current revision is an edit and goes into the exam
            if (ReportPath::isReport($path)) {
                try {
                    [$before] = $this->parseDocument((string) @file_get_contents($this->pageFile($path)));
                    $before = self::withoutPid($before);
                } catch (RuntimeException) {
                    $before = null;
                }
                $frontmatter = Exams::normalize($frontmatter, $before);
            }
            $document = $this->encodeDocument($frontmatter, $body, $pid);
            $sha = hash('sha256', $document);
            $visibility = (string) ($frontmatter['visibility'] ?? $row['visibility']);

            // D3: a signature covers a revision's exact bytes, so a signed
            // revision is never squashed into: 'minor' is ignored and a
            // normal new (unsigned, draft) revision follows
            if ($minor && $history['status'] !== 'signed') {
                return $this->saveMinor($path, $pid, $history, $document, $sha, $rev, $actor, $note, $visibility);
            }

            $next = $rev + 1;
            $journal = $this->journal();
            $journal->appendIntent('save', $pid, $path, $next, $baseRev, $sha, $actor);

            $now = self::now();
            // D3: correcting a signed report is a 'resign', not a plain 'edit'
            $kind = $history['status'] === 'signed' ? 'resign' : 'edit';
            $history['revlog'][] = Revlog::chained(self::revlogEntry($next, $now, $actor, $note, \strlen($document), $sha, $kind, $auto), $history['revlog'], $pid);
            // Archived (imported) pages stay archived through an edit; anything else edited is a draft
            if ($history['status'] !== 'archived') {
                $history['status'] = 'draft';
            }
            $this->attic->append($this->atticFile($path), $document, $history, self::at($now));
            $this->writePageFile($path, $document);
            $this->commitWrite($path, $pid, $history, $visibility, $actor);
            $journal->appendDone($pid, $next);

            return $this->read($path);
        });
    }

    /**
     * save()'s minor-edit path (invariant 3's one exception): the newest
     * revision's attic entry replaced in place (Attic::replaceNewest(),
     * which refuses a signed one), its revlog entry re-chained, `rev`
     * unchanged. The journal intent's rev is base_rev (op 'minor', keyed
     * apart from the write that made that revision); replay tells "the
     * zip was replaced" from "it was not" by the entry's sha256.
     *
     * @param array<string, mixed> $history
     */
    private function saveMinor(string $path, string $pid, array $history, string $document, string $sha, int $rev, string $actor, ?string $note, string $visibility): PageRecord
    {
        $journal = $this->journal();
        $journal->appendIntent('minor', $pid, $path, $rev, $rev, $sha, $actor);

        $now = self::now();
        $old = $history['revlog'][$rev - 1];
        // Re-linked to the same predecessor: only the newest revision is ever squashed
        $history['revlog'][$rev - 1] = Revlog::chained(
            self::revlogEntry($rev, $now, $actor, $note ?? ($old['note'] ?? null), \strlen($document), $sha, (string) ($old['kind'] ?? 'edit'), (bool) ($old['auto'] ?? false), true),
            \array_slice($history['revlog'], 0, $rev - 1),
            $pid
        );
        $this->attic->replaceNewest($this->atticFile($path), $document, $history, self::at($now));
        $this->writePageFile($path, $document);
        $this->commitWrite($path, $pid, $history, $visibility, $actor);
        $journal->appendDone($pid, $rev, 'minor');

        return $this->read($path);
    }

    /**
     * No base_rev check, unlike save(): revert never claims "the page was
     * at rev X when I read it", only "make $toRev's content current" (A2:
     * a forward operation); its bytes are $toRev's own, verbatim.
     */
    public function revert(string $path, int $toRev, string $actor, ?string $note = null): PageRecord
    {
        return $this->locked(function () use ($path, $toRev, $actor, $note): PageRecord {
            $document = $this->readRevision($path, $toRev);
            [$frontmatter] = $this->parseDocument($document);
            $row = $this->liveRow($path);
            $pid = (string) $row['pid'];
            $history = $this->historyOf($path);
            $rev = \count($history['revlog']);
            $next = $rev + 1;
            $sha = hash('sha256', $document);

            $journal = $this->journal();
            $journal->appendIntent('revert', $pid, $path, $next, $rev, $sha, $actor);

            $now = self::now();
            $history['revlog'][] = Revlog::chained(self::revlogEntry($next, $now, $actor, $note, \strlen($document), $sha, 'revert'), $history['revlog'], $pid);
            // A reverted-to revision's status is never carried forward (D3): it is signed again in its own right
            if ($history['status'] !== 'archived') {
                $history['status'] = 'draft';
            }
            $this->attic->append($this->atticFile($path), $document, $history, self::at($now));
            $this->writePageFile($path, $document);
            $this->commitWrite($path, $pid, $history, (string) ($frontmatter['visibility'] ?? $row['visibility']), $actor);
            $journal->appendDone($pid, $next);

            return $this->read($path);
        });
    }

    /**
     * A signature record for the current revision and `status: signed`:
     * the attic's record rewritten (Attic::writeHistory(): every entry
     * kept), then the state mirror; journalled, since that is two writes.
     * The digest covers the stored revision's bytes (the zip's entry, not
     * the page file a hand might have touched). Idempotent per revision.
     */
    public function sign(string $path, string $actor, array $schemaFields, ?string $parafa = null): PageRecord
    {
        return $this->locked(function () use ($path, $actor, $schemaFields, $parafa): PageRecord {
            $row = $this->liveRow($path);
            $pid = (string) $row['pid'];
            $history = $this->historyOf($path);
            $rev = \count($history['revlog']);
            foreach ($history['signatures'] as $existing) {
                if ((int) ($existing['rev'] ?? -1) === $rev) {
                    return $this->read($path);
                }
            }

            $document = $this->attic->revision($this->atticFile($path), $rev);
            [$frontmatter, $body] = $this->parseDocument($document);
            $now = self::now();
            $history['signatures'][] = [
                'rev' => $rev,
                'by' => $actor,
                'ts' => $now,
                'alg' => 'sha256',
                'digest' => hash('sha256', Canonical::bytes($frontmatter, $body, $schemaFields)),
                'parafa' => $parafa,
                // The schema field order the digest was reordered by (Service\Revisions::signature())
                'field_order' => Canonical::orderSpec($schemaFields),
            ];
            $history['status'] = 'signed';

            $journal = $this->journal();
            $journal->appendIntent('sign', $pid, $path, $rev, null, hash('sha256', $document), $actor);
            $this->attic->writeHistory($this->atticFile($path), $history, self::at($now));
            $this->state->transaction(fn () => $this->state->mirror($pid, $history));
            $this->reindex($path);
            $journal->appendDone($pid, $rev, 'sign');

            return $this->read($path);
        });
    }

    public function read(string $path): PageRecord
    {
        $row = $this->liveRow($path);
        $file = $this->pageFile($path);
        if (!is_file($file)) {
            throw new PageNotFoundException();
        }
        $history = self::committed($row, $this->historyOf($path));
        [$frontmatter, $body] = $this->parseDocument((string) file_get_contents($file));

        return new PageRecord(
            (string) $row['pid'],
            $path,
            \count($history['revlog']),
            (string) $history['status'],
            (string) $row['visibility'],
            self::withoutPid($frontmatter),
            $body,
            $history['revlog'],
            $this->meta($row, $history),
        );
    }

    public function readRevision(string $path, int $rev): string
    {
        $this->liveRow($path);
        try {
            return $this->attic->revision($this->atticFile($path), $rev);
        } catch (AtticException $e) {
            if (\in_array($e->reason, ['missing', 'no_revision'], true)) {
                throw new PageNotFoundException();
            }
            throw $e;
        }
    }

    public function revisions(string $path): array
    {
        return $this->read($path)->revlog;
    }

    /**
     * Every live page whose file is where its row says, as colon paths: a
     * query, not a tree walk. A page file with no row is strayPaths()'.
     *
     * @return iterable<string>
     */
    public function allPaths(): iterable
    {
        foreach ($this->state->livePaths() as $path) {
            if (is_file($this->pageFile($path))) {
                yield $path;
            }
        }
    }

    /** The PageSnapshot create()/save() index, rebuilt for an existing page */
    public function snapshotOf(string $path): PageSnapshot
    {
        $row = $this->liveRow($path);
        $file = $this->pageFile($path);
        if (!is_file($file)) {
            throw new PageNotFoundException();
        }
        $history = self::committed($row, $this->historyOf($path));
        $document = (string) file_get_contents($file);
        [$frontmatter, $body] = $this->parseDocument($document);
        $frontmatter = self::withoutPid($frontmatter);

        $segments = explode(':', $path);
        array_pop($segments);
        $revlog = $history['revlog'];
        $last = $revlog[array_key_last($revlog)] ?? [];
        // TODO 13: the newest revision a person made, for "recently changed" / "my drafts"
        $handEdit = Revlog::lastHandEdit($revlog);
        // Phase 24a: the first signature, for turnaround (a re-sign keeps the first)
        $first = $history['signatures'][0] ?? [];
        // The page file's real mtime: index:verify compares it with a fresh stat()
        $mtime = filemtime($file);

        return new PageSnapshot(
            pid: (string) $row['pid'],
            path: $path,
            ns: implode(':', $segments),
            rev: \count($revlog),
            status: (string) $history['status'],
            visibility: (string) $row['visibility'],
            frontmatter: $frontmatter,
            body: $body,
            bytes: \strlen($document),
            mtime: $mtime !== false ? $mtime : time(),
            bodySha: hash('sha256', $document),
            updated: (string) ($last['ts'] ?? self::now()),
            updatedBy: (string) ($last['by'] ?? ''),
            note: $last['note'] ?? null,
            kind: (string) ($last['kind'] ?? 'edit'),
            media: array_map(static fn (array $m): string => $m['sha256'] . '.' . $m['ext'], $this->state->media((string) $row['pid'])),
            edited: $handEdit[0] ?? null,
            editedBy: $handEdit[1] ?? null,
            signedAt: \is_string($first['ts'] ?? null) ? $first['ts'] : null,
            signedBy: \is_string($first['by'] ?? null) ? $first['by'] : null,
        );
    }

    public function replayJournal(int $minAgeSeconds = 0): array
    {
        $journal = $this->journal();
        $outcomes = [];
        foreach (self::stale($journal->openIntents(), $minAgeSeconds) as $intent) {
            $outcomes[] = $this->locked(fn (): array => $this->recoverIntent($journal, $intent));
        }

        return $outcomes;
    }

    /**
     * Page files under pages/ that no live page row has at their path and
     * no open journal intent accounts for: put there or moved there by
     * hand. reconcilePath() rejoins or adopts one; this only lists them.
     *
     * @return list<string> colon paths
     */
    public function strayPaths(): array
    {
        $pending = [];
        foreach ($this->journal()->openIntents() as $intent) {
            foreach (['path', 'from'] as $key) {
                if (\is_string($intent[$key] ?? null)) {
                    $pending[(string) $intent[$key]] = true;
                }
            }
        }
        $stray = [];
        foreach ($this->pageFilesOnDisk() as $path) {
            if (!isset($pending[$path]) && $this->state->pageAt($path) === null) {
                $stray[] = $path;
            }
        }
        sort($stray);

        return $stray;
    }

    /**
     * Journal intents still open and older than $minAgeSeconds: what
     * replayJournal() would act on (journal:replay --dry-run).
     *
     * @return list<array<string, mixed>>
     */
    public function staleIntents(int $minAgeSeconds): array
    {
        return self::stale($this->journal()->openIntents(), $minAgeSeconds);
    }

    /**
     * The front controller's crash recovery: cheap when there is nothing to
     * do (the journal is read from its checkpoint), never waits on another
     * request replaying.
     *
     * @return list<array{pid: string, rev: int, outcome: string}>
     */
    public function replayCrashedWrites(int $minAgeSeconds): array
    {
        $journal = $this->journal();
        if (self::stale($journal->openIntentsSinceCheckpoint(), $minAgeSeconds) === []) {
            return [];
        }
        $lock = @fopen($this->dataRoot . '/journal/.replay.lock', 'c');
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
                $outcomes[] = $this->locked(fn (): array => $this->recoverIntent($journal, $intent));
            }
            flock($lock, LOCK_UN);

            return $outcomes;
        } finally {
            fclose($lock);
        }
    }

    /** Soft delete: the page file and its attic zip into trash/{pid}/, the row marked trashed */
    public function delete(string $path, string $actor): void
    {
        $this->locked(function () use ($path, $actor): void {
            $row = $this->liveRow($path);
            $pid = (string) $row['pid'];
            $rev = (int) $row['rev'];

            $journal = $this->journal();
            $journal->appendIntent('delete', $pid, $path, $rev, null, (string) $row['file_sha'], $actor);
            $this->finishDelete($row, $path, $actor, self::now());
            $journal->appendDone($pid, $rev, 'delete');
        });
    }

    public function move(string $from, string $to, string $actor): PageRecord
    {
        $this->assertValidPath($to);

        return $this->locked(function () use ($from, $to, $actor): PageRecord {
            $row = $this->liveRow($from);
            if ($to === $from) {
                throw new InvalidArgumentException('The page is already at that path');
            }
            if ($this->isTaken($to)) {
                throw new InvalidArgumentException('Another page already has that path');
            }
            $pid = (string) $row['pid'];
            $rev = (int) $row['rev'];

            $journal = $this->journal();
            $journal->appendIntent('move', $pid, $to, $rev, null, (string) $row['file_sha'], $actor, ['from' => $from]);
            $this->moveFile($this->pageFile($from), $this->pageFile($to));
            $this->moveFile($this->atticFile($from), $this->atticFile($to));
            $this->finishMove($pid, $from, $to, $actor, self::now());
            $journal->appendDone($pid, $rev, 'move');

            return $this->read($to);
        });
    }

    public function redirectTarget(string $path): ?string
    {
        if (!self::safePath($path) || $this->state->pageAt($path) !== null) {
            return null;
        }
        $pid = $this->state->redirectPid($path);
        $row = $pid !== null ? $this->state->pageByPid($pid) : null;

        return $row !== null && $row['trashed_at'] === null && (string) $row['path'] !== $path ? (string) $row['path'] : null;
    }

    public function trash(): array
    {
        $entries = [];
        foreach ($this->state->trashed() as $row) {
            $pid = (string) $row['pid'];
            $title = (string) $row['path'];
            $file = $this->trashDir($pid) . '/' . self::TRASH_PAGE;
            try {
                if (is_file($file)) {
                    [$frontmatter, $body] = $this->parseDocument((string) file_get_contents($file));
                    $title = PageTitle::of(self::withoutPid($frontmatter), $body) ?: $title;
                }
            } catch (RuntimeException) {
                // an unparseable page is still listed, by path
            }
            $entries[] = [
                'pid' => $pid,
                'path' => (string) $row['path'],
                'title' => $title,
                'status' => (string) $row['status'],
                'signed' => $this->state->hasSignature($pid),
                'deletedAt' => $row['trashed_at'] !== null ? (string) $row['trashed_at'] : null,
                'deletedBy' => $row['trashed_by'] !== null ? (string) $row['trashed_by'] : null,
            ];
        }

        return $entries;
    }

    public function restore(string $pid, string $actor): PageRecord
    {
        return $this->locked(function () use ($pid, $actor): PageRecord {
            $row = $this->trashedRow($pid);
            $target = $this->allocatePath((string) $row['path']);
            $rev = (int) $row['rev'];

            $journal = $this->journal();
            $journal->appendIntent('restore', $pid, $target, $rev, null, '', $actor);
            $this->finishRestore($pid, $target);
            $journal->appendDone($pid, $rev, 'restore');

            return $this->read($target);
        });
    }

    public function purge(string $pid, string $actor, bool $includeSigned = false): void
    {
        $this->locked(function () use ($pid, $actor, $includeSigned): void {
            $row = $this->trashedRow($pid);
            if ($this->state->hasSignature($pid) && !$includeSigned) {
                throw new InvalidArgumentException('Signed content is purged only with an explicit override (D3b)');
            }
            $journal = $this->journal();
            $journal->appendIntent('purge', $pid, (string) $row['path'], (int) $row['rev'], null, '', $actor);
            $this->finishPurge($pid);
            $journal->appendDone($pid, (int) $row['rev'], 'purge');
        });
    }

    /**
     * An image attached (D10/D27): the bytes once, content addressed, in
     * data/media/{year}/{sha256}.{ext}, written first; then the page's
     * media row under its human name. A crash in between leaves an
     * unreferenced blob, never a row without its file. Re-attaching the
     * same bytes returns the existing entry.
     */
    public function attachMedia(string $path, string $bytes, string $name, string $actor): array
    {
        $row = $this->liveRow($path);
        $info = @getimagesizefromstring($bytes);
        $ext = \is_array($info) ? (self::MEDIA_TYPES[$info[2]] ?? null) : null;
        if ($ext === null) {
            throw new InvalidArgumentException('Only PNG, JPEG, GIF and WebP images can be attached');
        }
        $sha = hash('sha256', $bytes);
        $yearDir = $this->dataRoot . '/media/' . date('Y');
        if (!is_dir($yearDir) && !mkdir($yearDir, 0775, true) && !is_dir($yearDir)) {
            throw new RuntimeException('Cannot create media directory');
        }
        if ($this->mediaFile($sha, $ext) === null) {
            AtomicWriter::putOnce($yearDir . '/' . $sha . '.' . $ext, $bytes);
        }

        return $this->locked(function () use ($path, $row, $sha, $ext, $name, $bytes, $info, $actor): array {
            $pid = (string) $row['pid'];
            $entry = $this->state->transaction(function () use ($pid, $sha, $ext, $name, $bytes, $info, $actor): array {
                foreach ($this->state->media($pid) as $existing) {
                    if ($existing['sha256'] === $sha) {
                        return $existing;
                    }
                }
                $entry = [
                    'sha256' => $sha,
                    'ext' => $ext,
                    'name' => self::mediaName($name, $ext),
                    'bytes' => \strlen($bytes),
                    'w' => (int) $info[0],
                    'h' => (int) $info[1],
                    'added' => self::now(),
                    'by' => $actor,
                ];
                $this->state->addMedia($pid, $entry);

                return $entry;
            });
            $this->reindex($path);

            return $entry;
        });
    }

    public function mediaOf(string $path): array
    {
        return $this->state->media((string) $this->liveRow($path)['pid']);
    }

    public function mediaFile(string $sha, string $ext): ?string
    {
        if (preg_match('/^[0-9a-f]{64}$/', $sha) !== 1 || !\in_array($ext, self::MEDIA_TYPES, true)) {
            return null;
        }
        $files = glob($this->dataRoot . '/media/[0-9][0-9][0-9][0-9]/' . $sha . '.' . $ext) ?: [];

        return $files[0] ?? null;
    }

    public function recordDelivery(string $path, array $entry): void
    {
        $pid = (string) $this->liveRow($path)['pid'];
        $scalars = array_filter($entry, static fn (mixed $v): bool => \is_scalar($v));
        $this->state->transaction(fn () => $this->state->addDelivery($pid, $scalars, self::DELIVERIES_KEPT));
    }

    public function saveDraft(string $path, string $username, int $baseRev, bool $raw, string $form): void
    {
        $row = $this->liveRow($path);
        if (!self::isDraftOwner($username)) {
            throw new PageNotFoundException();
        }
        if (\strlen($form) > self::DRAFT_MAX_BYTES) {
            throw new InvalidArgumentException('The draft is too large');
        }
        // A draft of a revision that is no longer current is not written: the editor is behind
        if ((int) $row['rev'] !== $baseRev) {
            throw new RevisionConflictException($this->read($path), $baseRev);
        }
        $this->state->putDraft((string) $row['pid'], $username, $baseRev, $raw, $form, self::now());
    }

    public function readDraft(string $path, string $username): ?array
    {
        $row = self::safePath($path) ? $this->state->pageAt($path) : null;
        if ($row === null || !self::isDraftOwner($username)) {
            return null;
        }
        $draft = $this->state->draft((string) $row['pid'], $username);

        return $draft !== null ? [
            'base_rev' => $draft['base_rev'],
            'ts' => $draft['saved'],
            'raw' => $draft['raw'],
            'form' => $draft['form'],
            'current_rev' => (int) $row['rev'],
        ] : null;
    }

    public function discardDraft(string $path, string $username): void
    {
        $row = self::safePath($path) ? $this->state->pageAt($path) : null;
        if ($row !== null && self::isDraftOwner($username)) {
            $this->state->dropDraft((string) $row['pid'], $username);
        }
    }

    public function comments(string $path): array
    {
        return $this->state->comments((string) $this->liveRow($path)['pid']);
    }

    public function updateComments(string $path, callable $change): array
    {
        $pid = (string) $this->liveRow($path)['pid'];

        // One transaction: two comments at once never lose one another
        return $this->state->transaction(function () use ($pid, $change): array {
            $comments = \array_slice(array_values($change($this->state->comments($pid))), -self::COMMENTS_MAX);
            $this->state->replaceComments($pid, $comments);

            return $this->state->comments($pid);
        });
    }

    /**
     * The next number of a D20 accession sequence, never below $floor + 1
     * (what disk and index already hold): allocated before the page is
     * created, so a crash leaves a gap, never a duplicate.
     */
    public function nextAccession(string $key, int $floor): int
    {
        return $this->state->nextCounter($key, $floor);
    }

    /** @return array<string, int> each sequence's last number issued */
    public function accessionCounters(): array
    {
        return $this->state->counters();
    }

    /**
     * integrity:verify's per-page check, reading only: the attic zip's
     * problems (Attic::problems(): an entry missing, corrupt or changed,
     * the chain), `current_stale` when the page file is not the newest
     * revision (D2), `mirror_drift` when the state DB's revision or
     * signature rows disagree with the zip.
     *
     * @return list<array{rev: ?int, problem: string}>
     *
     * @throws PageNotFoundException
     */
    public function historyProblems(string $path): array
    {
        $row = $this->liveRow($path);
        $zip = $this->atticFile($path);
        $problems = $this->attic->problems($zip);
        if ($problems !== [] && $problems[0]['rev'] === null && \in_array($problems[0]['problem'], ['missing', 'unreadable', 'history_missing', 'history_unreadable', 'history_invalid'], true)) {
            return $problems;
        }
        $history = $this->attic->history($zip);
        $n = \count($history['revlog']);
        $file = $this->pageFile($path);
        if ($n > 0 && is_file($file) && !hash_equals((string) $history['revlog'][$n - 1]['sha256'], hash('sha256', (string) file_get_contents($file)))) {
            $problems[] = ['rev' => $n, 'problem' => 'current_stale'];
        }
        $pid = (string) $row['pid'];
        $logged = [];
        foreach ($history['revlog'] as $entry) {
            $logged[(int) $entry['n']] = (string) $entry['sha256'];
        }
        $signed = array_map(static fn (array $s): int => (int) $s['rev'], $history['signatures']);
        sort($signed);
        if ($logged !== $this->state->revisionShas($pid) || $signed !== $this->state->signatureRevs($pid)
            || (int) $row['rev'] !== $n || (string) $row['status'] !== (string) $history['status']) {
            $problems[] = ['rev' => null, 'problem' => 'mirror_drift'];
        }

        return $problems;
    }

    /** @return list<string> live pages whose page file is not where the row says (moved or removed by hand) */
    public function missingPageFiles(): array
    {
        return array_values(array_filter($this->state->livePaths(), fn (string $path): bool => !is_file($this->pageFile($path))));
    }

    /** @return list<string> attic zips under attic/ that no page row has at their path, as colon paths */
    public function orphanAttics(): array
    {
        $root = $this->dataRoot . '/attic';
        if (!is_dir($root)) {
            return [];
        }
        $orphans = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $item) {
            if (!$item->isFile() || !str_ends_with($item->getFilename(), self::ATTIC_EXT)) {
                continue;
            }
            $path = str_replace('/', ':', substr($item->getPathname(), \strlen($root) + 1, -\strlen(self::ATTIC_EXT)));
            if ($this->state->pageAt($path) === null) {
                $orphans[] = $path;
            }
        }
        sort($orphans);

        return $orphans;
    }

    /**
     * What a stray page file (strayPaths()) is, without changing anything:
     * `moved` (its page's row is elsewhere, that file gone: rejoined by
     * pages:repair-paths), `copy` (its pid is a live page's that still has
     * its file) or `new` (no page is known for it: adopted as one).
     */
    public function strayKind(string $path): string
    {
        try {
            if ($this->owner($path) !== null) {
                return 'moved';
            }
            [$frontmatter] = $this->parseDocument((string) file_get_contents($this->pageFile($path)));
        } catch (RuntimeException) {
            return 'new';
        }
        $claimed = \is_string($frontmatter[self::PID_KEY] ?? null) ? $this->state->pageByPid($frontmatter[self::PID_KEY]) : null;

        return $claimed !== null && $claimed['trashed_at'] === null ? 'copy' : 'new';
    }

    /** @return list<string> what SQLite finds wrong with reporion.sqlite; none when sound */
    public function stateProblems(): array
    {
        return $this->state->check();
    }

    /**
     * Revision $rev of the page at $path as a copy of data/ holds it (a
     * mounted backup snapshot): null when that copy has no such revision.
     * Reads only.
     */
    public function revisionInCopy(string $copyRoot, string $path, int $rev): ?string
    {
        if (!self::safePath($path)) {
            return null;
        }
        $zip = rtrim($copyRoot, '/') . '/attic/' . str_replace(':', '/', $path) . self::ATTIC_EXT;
        try {
            return is_file($zip) ? $this->attic->revision($zip, $rev) : null;
        } catch (AtticException) {
            return null;
        }
    }

    /**
     * Every page file under pages/, live or not (a tree walk: for seeding
     * from what is on disk, never for listing pages)
     *
     * @return iterable<string> absolute file names
     */
    public function pageFiles(): iterable
    {
        foreach ($this->pageFilesOnDisk() as $path) {
            yield $this->pageFile($path);
        }
    }

    /** Whether $path is a well-formed page path (what create() would accept) */
    public static function isValidPath(string $path): bool
    {
        try {
            self::assertValidPath($path);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }

    /**
     * Where the page file at $path belongs (§5): $path itself when a live
     * row has it; else the path of the live page its frontmatter `pid`
     * names, or, for a file without one, of a page whose own file is gone
     * and whose last written bytes these are. Null: no page file here, or
     * one no page is known for (a new page to adopt).
     */
    public function storedPath(string $path): ?string
    {
        if (!self::safePath($path) || !is_file($this->pageFile($path))) {
            return null;
        }
        if ($this->state->pageAt($path) !== null) {
            return $path;
        }
        try {
            $row = $this->owner($path);
        } catch (RuntimeException) {
            return null;
        }

        return $row !== null ? (string) $row['path'] : null;
    }

    /**
     * The page file at $path made a page there (the rejoin of §5, what
     * pages:repair-paths applies): a file whose page's row is elsewhere,
     * with that row's own file gone, takes the row along (a `move` row, a
     * redirect from the old path, the attic zip moved); a file no page is
     * known for becomes a new page, its bytes revision 1. Refused while an
     * open journal intent names the page or the path.
     *
     * @return string PATH_OK (a page already), PATH_REPAIRED, PATH_BUSY or PATH_COPY
     *
     * @throws PageNotFoundException no page file there
     */
    public function reconcilePath(string $path, string $actor): string
    {
        $this->assertValidPath($path);

        return $this->locked(function () use ($path, $actor): string {
            $file = $this->pageFile($path);
            if (!is_file($file)) {
                throw new PageNotFoundException();
            }
            if ($this->state->pageAt($path) !== null) {
                return self::PATH_OK;
            }
            $document = (string) file_get_contents($file);
            [$frontmatter] = $this->parseDocument($document);
            $claimed = \is_string($frontmatter[self::PID_KEY] ?? null) ? $frontmatter[self::PID_KEY] : '';
            foreach ($this->journal()->openIntents() as $intent) {
                $names = [(string) ($intent['path'] ?? ''), (string) ($intent['from'] ?? '')];
                if (\in_array($path, $names, true) || ($claimed !== '' && ($intent['pid'] ?? null) === $claimed)) {
                    return self::PATH_BUSY;
                }
            }

            $row = $this->owner($path);
            if ($row === null) {
                $taken = $claimed !== '' ? $this->state->pageByPid($claimed) : null;
                if ($taken !== null && $taken['trashed_at'] === null) {
                    return self::PATH_COPY;
                }
                $this->adoptNew($path, $document, $taken === null && Ulid::isValid($claimed) ? $claimed : Ulid::generate(), $actor);

                return self::PATH_REPAIRED;
            }

            $pid = (string) $row['pid'];
            $from = (string) $row['path'];
            $journal = $this->journal();
            $journal->appendIntent('move', $pid, $path, (int) $row['rev'], null, hash('sha256', $document), $actor, ['from' => $from]);
            $this->moveFile($this->atticFile($from), $this->atticFile($path));
            $this->finishMove($pid, $from, $path, $actor, self::now());
            $journal->appendDone($pid, (int) $row['rev'], 'move');

            return self::PATH_REPAIRED;
        });
    }

    /**
     * A page file the index does not have at this path: rejoined or adopted
     * (reconcilePath()) and indexed. False, doing nothing, when there is no
     * page file there, when the index already has the path (the caller is
     * just not entitled to it), for a copy, or while a journal intent names
     * it. The caller asks the index again: who may see the page is still
     * decided there (invariant 6).
     */
    public function adopt(string $path, string $actor = 'system:admin'): bool
    {
        try {
            if (!self::safePath($path) || !is_file($this->pageFile($path))) {
                return false;
            }
            $everyone = new User('instance', '', true, [], true, '', '');
            if ($this->index->findByPath($path, $everyone) !== null) {
                return false;
            }
            $state = $this->reconcilePath($path, $actor);
            if ($state === self::PATH_OK) {
                $this->reindex($path);
            }

            return $state === self::PATH_OK || $state === self::PATH_REPAIRED;
        } catch (PageNotFoundException | InvalidArgumentException | RuntimeException) {
            return false;
        }
    }

    /**
     * The live row a page file at $path belongs to, when it is not at
     * $path: by its frontmatter pid, else (a file from before 2.0, with no
     * pid) by the bytes its page last had: only a row whose own page
     * file is gone, so a copy never takes another page's row.
     *
     * @return array<string, mixed>|null
     */
    private function owner(string $path): ?array
    {
        $document = (string) file_get_contents($this->pageFile($path));
        [$frontmatter] = $this->parseDocument($document);
        $claimed = \is_string($frontmatter[self::PID_KEY] ?? null) ? $frontmatter[self::PID_KEY] : '';
        if ($claimed !== '') {
            $row = $this->state->pageByPid($claimed);

            return $row !== null && $row['trashed_at'] === null && !is_file($this->pageFile((string) $row['path'])) ? $row : null;
        }
        foreach ($this->state->pagesWithFileSha(hash('sha256', $document)) as $row) {
            if (!is_file($this->pageFile((string) $row['path']))) {
                return $row;
            }
        }

        return null;
    }

    /** A page file no page is known for becomes a page: its bytes, as they are, revision 1 */
    private function adoptNew(string $path, string $document, string $pid, string $actor): void
    {
        [$frontmatter] = $this->parseDocument($document);
        $sha = hash('sha256', $document);
        $zip = $this->atticFile($path);
        $journal = $this->journal();
        $journal->appendIntent('create', $pid, $path, 1, null, $sha, $actor);
        if (is_file($zip)) {
            // An attic left with no row (a crash, a hand copy): kept, never overwritten
            throw new InvalidArgumentException('An attic zip is already there');
        }
        $now = self::now();
        $history = [
            'v' => Attic::FORMAT,
            'pid' => $pid,
            'status' => ($frontmatter['status'] ?? 'draft') === 'archived' ? 'archived' : 'draft',
            'archived' => null,
            'revlog' => [Revlog::chained(self::revlogEntry(1, $now, $actor, 'adopted', \strlen($document), $sha, 'create', true), [], $pid)],
            'signatures' => [],
        ];
        $this->attic->append($zip, $document, $history, self::at($now));
        $this->state->transaction(function () use ($path, $pid, $history, $now, $actor, $frontmatter): void {
            $this->state->dropRedirect($path);
            $this->state->insertPage([
                'pid' => $pid,
                'path' => $path,
                'rev' => 1,
                'status' => $history['status'],
                'created' => $now,
                'created_by' => $actor,
                'visibility' => (string) ($frontmatter['visibility'] ?? 'private'),
            ] + $this->fileFacts($path));
            $this->state->mirror($pid, $history);
        });
        $this->reindex($path);
        $journal->appendDone($pid, 1);
    }

    /**
     * After the attic and the page file of a save, minor edit or revert:
     * the row's file facts and visibility, the mirror, the author's draft
     * gone (a normal write supersedes it), the index.
     *
     * @param array<string, mixed> $history
     */
    private function commitWrite(string $path, string $pid, array $history, string $visibility, string $actor): void
    {
        $this->state->transaction(function () use ($path, $pid, $history, $visibility, $actor): void {
            $this->state->updatePage($pid, ['visibility' => $visibility] + $this->fileFacts($path));
            $this->state->mirror($pid, $history);
            if (self::isDraftOwner($actor)) {
                $this->state->dropDraft($pid, $actor);
            }
        });
        $this->reindex($path);
    }

    /** A move's state after its renames, shared with replay: the row's path, a `move` row, the redirect, the index */
    private function finishMove(string $pid, string $from, string $to, string $actor, string $ts): void
    {
        $this->state->transaction(function () use ($pid, $from, $to, $actor, $ts): void {
            $this->state->dropRedirect($to);
            $this->state->updatePage($pid, ['path' => $to] + $this->fileFacts($to));
            $this->state->addMove($pid, $from, $to, $ts, $actor);
            $this->state->setRedirect($from, $pid);
        });
        $this->reindex($to);
    }

    /** @param array<string, mixed> $row */
    private function finishDelete(array $row, string $path, string $actor, string $ts): void
    {
        $pid = (string) $row['pid'];
        $trash = $this->trashDir($pid);
        $this->moveFile($this->pageFile($path), $trash . '/' . self::TRASH_PAGE);
        $this->moveFile($this->atticFile($path), $trash . '/' . self::TRASH_ATTIC);
        $this->state->transaction(fn () => $this->state->updatePage($pid, ['trashed_at' => $ts, 'trashed_by' => $actor]));
        $this->index->remove($pid);
    }

    private function finishRestore(string $pid, string $target): void
    {
        $trash = $this->trashDir($pid);
        $this->moveFile($trash . '/' . self::TRASH_PAGE, $this->pageFile($target));
        $this->moveFile($trash . '/' . self::TRASH_ATTIC, $this->atticFile($target));
        $this->state->transaction(function () use ($pid, $target): void {
            $this->state->dropRedirect($target);
            $this->state->updatePage($pid, ['path' => $target, 'trashed_at' => null, 'trashed_by' => null]);
        });
        @rmdir($trash);
        $this->reindex($target);
    }

    private function finishPurge(string $pid): void
    {
        $trash = $this->trashDir($pid);
        foreach ([self::TRASH_PAGE, self::TRASH_ATTIC] as $name) {
            if (file_exists($trash . '/' . $name) && !@unlink($trash . '/' . $name)) {
                throw new RuntimeException('Cannot remove a file of the trashed page');
            }
        }
        if (is_dir($trash) && !@rmdir($trash)) {
            throw new RuntimeException('Cannot remove the trashed page');
        }
        Fsync::directory($this->dataRoot . '/trash');
        $this->state->transaction(fn () => $this->state->deletePage($pid));
    }

    /**
     * Recovers one intent and closes it; a discarded one (the write never
     * happened) is closed too. Only a corrupt attic stays open, for a
     * human to look at.
     *
     * @param array<string, mixed> $intent
     *
     * @return array{pid: string, rev: int, outcome: string}
     */
    private function recoverIntent(Journal $journal, array $intent): array
    {
        $pid = (string) ($intent['pid'] ?? '');
        $rev = (int) ($intent['rev'] ?? 0);
        $op = (string) ($intent['op'] ?? '');
        try {
            $outcome = $this->recoverIntentOnce($intent);
        } catch (PageNotFoundException | InvalidArgumentException) {
            // A path that names no page: nothing to finish, and left open it would stop every later replay
            $outcome = 'discarded';
        } catch (AtticException) {
            return ['pid' => $pid, 'rev' => $rev, 'outcome' => 'corrupt'];
        }
        $journal->appendDone($pid, $rev, \in_array($op, Journal::KEYED_OPS, true) ? $op : null);

        return ['pid' => $pid, 'rev' => $rev, 'outcome' => $outcome];
    }

    /** @param array<string, mixed> $intent */
    private function recoverIntentOnce(array $intent): string
    {
        $op = (string) ($intent['op'] ?? '');
        $pid = (string) ($intent['pid'] ?? '');
        $path = (string) ($intent['path'] ?? '');
        $rev = (int) ($intent['rev'] ?? 0);
        $actor = (string) ($intent['actor'] ?? '');
        $ts = (string) ($intent['ts'] ?? self::now());
        if (!self::safePath($path) || preg_match('/^[0-9A-Za-z]+$/', $pid) !== 1) {
            return 'discarded';
        }
        $row = $this->state->pageByPid($pid);

        switch ($op) {
            case 'move':
                $from = (string) ($intent['from'] ?? '');
                if (!self::safePath($from)) {
                    return 'discarded';
                }
                $moved = is_file($this->pageFile($path)) || is_file($this->atticFile($path));
                if (!$moved || $row === null) {
                    return 'discarded';
                }
                $this->moveFile($this->pageFile($from), $this->pageFile($path));
                $this->moveFile($this->atticFile($from), $this->atticFile($path));
                if ((string) $row['path'] !== $path) {
                    $this->finishMove($pid, $from, $path, $actor, $ts);
                } else {
                    $this->reindex($path);
                }

                return 'recovered';
            case 'delete':
                if ($row === null || ($row['trashed_at'] === null && is_file($this->pageFile($path)) && is_file($this->atticFile($path)))) {
                    return 'discarded';
                }
                if ($row['trashed_at'] === null) {
                    $this->finishDelete($row, $path, $actor, $ts);
                }

                return 'recovered';
            case 'restore':
                $trash = $this->trashDir($pid);
                if ($row === null || ($row['trashed_at'] !== null && is_file($trash . '/' . self::TRASH_PAGE) && is_file($trash . '/' . self::TRASH_ATTIC))) {
                    return 'discarded';
                }
                $this->finishRestore($pid, $path);

                return 'recovered';
            case 'purge':
                if ($row === null) {
                    return 'discarded';
                }
                $this->finishPurge($pid);

                return 'recovered';
            case 'sign':
                if ($row === null || !is_file($this->atticFile($path))) {
                    return 'discarded';
                }
                $this->resync($path, $pid, $this->attic->history($this->atticFile($path)), $intent);

                return 'recovered';
            case 'create':
            case 'save':
            case 'revert':
            case 'minor':
                $zip = $this->atticFile($path);
                if (!is_file($zip)) {
                    return 'discarded';
                }
                $history = $this->attic->history($zip);
                $have = \count($history['revlog']);
                if ($history['pid'] !== $pid || $have < $rev) {
                    return 'discarded';
                }
                if ($have > $rev) {
                    // A later write went through: this revision is history, the page file stays newer
                    return 'superseded';
                }
                if ($op === 'minor' && !hash_equals((string) $history['revlog'][$rev - 1]['sha256'], (string) ($intent['body_sha'] ?? ''))) {
                    // The zip was never replaced: what it holds is still a complete revision
                    return 'discarded';
                }
                $this->resync($path, $pid, $history, $intent);

                return 'recovered';
            default:
                return 'discarded';
        }
    }

    /**
     * A page made what its attic zip says, after a crash: the page file
     * written from the newest entry when it does not hold those bytes, the
     * row created (a create that stopped before it) or updated, mirrored,
     * indexed.
     *
     * @param array<string, mixed> $history
     * @param array<string, mixed> $intent
     */
    private function resync(string $path, string $pid, array $history, array $intent): void
    {
        $n = \count($history['revlog']);
        $file = $this->pageFile($path);
        $wanted = (string) $history['revlog'][$n - 1]['sha256'];
        if (!is_file($file) || !hash_equals($wanted, hash('sha256', (string) file_get_contents($file)))) {
            $this->writePageFile($path, $this->attic->revision($this->atticFile($path), $n));
        }
        [$frontmatter] = $this->parseDocument((string) file_get_contents($file));
        $this->state->transaction(function () use ($path, $pid, $history, $intent, $frontmatter): void {
            $row = $this->state->pageByPid($pid);
            if ($row === null) {
                $first = $history['revlog'][0];
                $this->state->dropRedirect($path);
                $this->state->insertPage([
                    'pid' => $pid,
                    'path' => $path,
                    'rev' => \count($history['revlog']),
                    'status' => $history['status'],
                    'created' => (string) ($first['ts'] ?? $intent['ts'] ?? self::now()),
                    'created_by' => (string) ($first['by'] ?? $intent['actor'] ?? ''),
                    'visibility' => (string) ($frontmatter['visibility'] ?? 'private'),
                ] + $this->fileFacts($path));
            } else {
                $this->state->updatePage($pid, ['path' => $path, 'visibility' => (string) ($frontmatter['visibility'] ?? $row['visibility'])] + $this->fileFacts($path));
            }
            $this->state->mirror($pid, $history);
        });
        $this->reindex($path);
    }

    private function reindex(string $path): void
    {
        $this->index->index($this->snapshotOf($path));
    }

    /**
     * The meta array PageRecord carries, in 1.x meta.json's shape so its
     * readers (signatures, deliveries, moves) are unchanged
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $history
     *
     * @return array<string, mixed>
     */
    private function meta(array $row, array $history): array
    {
        $pid = (string) $row['pid'];
        $meta = [
            'pid' => $pid,
            'created' => (string) $row['created'],
            'created_by' => (string) $row['created_by'],
            'path' => (string) $row['path'],
            'rev' => \count($history['revlog']),
            'revlog' => $history['revlog'],
            'signatures' => $history['signatures'],
            'status' => (string) $history['status'],
            'visibility' => (string) $row['visibility'],
            'share_token' => null,
        ];
        // As meta.json had them: only once there is one
        foreach (['moves' => $this->state->moves($pid), 'deliveries' => $this->state->deliveries($pid)] as $key => $rows) {
            if ($rows !== []) {
                $meta[$key] = $rows;
            }
        }

        return $meta;
    }

    /**
     * What readers see while a write is in flight, or after one a crash
     * stopped before its state transaction: the attic zip may already hold
     * a revision the row (and the page file) do not. Until the write commits
     * or replay finishes it, the page is what the row says: the record cut
     * at the row's rev, its status the row's.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $history
     *
     * @return array<string, mixed>
     */
    private static function committed(array $row, array $history): array
    {
        $rev = (int) $row['rev'];
        if ($rev < 1 || \count($history['revlog']) <= $rev) {
            return $history;
        }
        $history['revlog'] = \array_slice($history['revlog'], 0, $rev);
        $history['signatures'] = array_values(array_filter($history['signatures'], static fn (array $s): bool => (int) ($s['rev'] ?? 0) <= $rev));
        $history['status'] = (string) $row['status'];

        return $history;
    }

    /**
     * @return array{v: int, pid: string, status: string, archived: ?array<string, mixed>, revlog: list<array<string, mixed>>, signatures: list<array<string, mixed>>}
     */
    private function historyOf(string $path): array
    {
        try {
            return $this->attic->history($this->atticFile($path));
        } catch (AtticException $e) {
            if ($e->reason === 'missing') {
                throw new PageNotFoundException();
            }
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PageNotFoundException
     */
    private function liveRow(string $path): array
    {
        if (!self::safePath($path)) {
            throw new PageNotFoundException();
        }

        return $this->state->pageAt($path) ?? throw new PageNotFoundException();
    }

    /** @return array<string, mixed> */
    private function trashedRow(string $pid): array
    {
        if (preg_match('/^[0-9A-Za-z]+$/', $pid) !== 1) {
            throw new PageNotFoundException();
        }
        $row = $this->state->pageByPid($pid);
        if ($row === null || $row['trashed_at'] === null) {
            throw new PageNotFoundException();
        }

        return $row;
    }

    /**
     * $work under the one write lock (data/.write.lock): page writes are
     * few and short, so one lock for all keeps every check-then-write
     * (a free path, the current rev) honest. Re-entrant.
     *
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    private function locked(Closure $work): mixed
    {
        if ($this->lockDepth > 0) {
            ++$this->lockDepth;
            try {
                return $work();
            } finally {
                --$this->lockDepth;
            }
        }
        $lock = fopen($this->dataRoot . '/.write.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Cannot take the write lock');
        }
        $this->lock = $lock;
        $this->lockDepth = 1;
        try {
            return $work();
        } finally {
            $this->lockDepth = 0;
            $this->lock = null;
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function allocatePath(string $requested, bool $exclusive = false): string
    {
        $this->assertValidPath($requested);
        $segments = explode(':', $requested);
        $last = array_pop($segments);
        for ($suffix = 1;; ++$suffix) {
            $candidate = implode(':', [...$segments, $suffix === 1 ? $last : $last . '-' . $suffix]);
            if (!$this->isTaken($candidate)) {
                return $candidate;
            }
            // The path itself, or nothing: no `-2`
            if ($exclusive) {
                throw new PageExistsException($requested);
            }
        }
    }

    /**
     * A live page, a page file or an attic zip at $path takes it. A
     * redirect does not: a page created there takes the old name, and the
     * redirect goes (create()). A namespace of the same name never does.
     */
    private function isTaken(string $path): bool
    {
        return $this->state->pageAt($path) !== null || file_exists($this->pageFile($path)) || file_exists($this->atticFile($path));
    }

    private static function assertValidPath(string $path): void
    {
        if ($path === '' || str_starts_with($path, ':') || str_ends_with($path, ':') || str_contains($path, '::')) {
            throw new InvalidArgumentException('Invalid page path');
        }
        foreach (explode(':', $path) as $segment) {
            if (!self::safeSegment($segment)) {
                throw new InvalidArgumentException('Invalid page path');
            }
            // A segment that is a page file's or attic zip's name would put a
            // namespace directory where a page's file is, or a temp file's
            $lower = strtolower($segment);
            if (str_ends_with($lower, self::PAGE_EXT) || str_ends_with($lower, self::ATTIC_EXT) || str_contains($lower, '.tmp-') || str_ends_with($lower, '.lock') || str_starts_with($segment, '.')) {
                throw new InvalidArgumentException('Invalid page path');
            }
        }
    }

    private static function safeSegment(string $segment): bool
    {
        return $segment !== '' && $segment !== '.' && $segment !== '..' && !str_contains($segment, '/') && !str_contains($segment, '\\') && !str_contains($segment, "\0");
    }

    /**
     * The one check every path gets before it becomes a file name: a
     * request's path (`reports:..:other:page`) passes a grant's prefix
     * match yet would resolve outside data/pages/. No such page, never a
     * hint at what is on disk (invariant 9).
     */
    private static function safePath(string $path): bool
    {
        foreach (explode(':', $path) as $segment) {
            if (!self::safeSegment($segment)) {
                return false;
            }
        }

        return true;
    }

    private function pageFile(string $path): string
    {
        return $this->dataRoot . '/pages/' . str_replace(':', '/', $path) . self::PAGE_EXT;
    }

    private function atticFile(string $path): string
    {
        return $this->dataRoot . '/attic/' . str_replace(':', '/', $path) . self::ATTIC_EXT;
    }

    private function trashDir(string $pid): string
    {
        return $this->dataRoot . '/trash/' . $pid;
    }

    /** @return iterable<string> every `*.md` under pages/, as a colon path */
    private function pageFilesOnDisk(): iterable
    {
        $root = $this->dataRoot . '/pages';
        if (!is_dir($root)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            $name = $item->getFilename();
            if (!$item->isFile() || !str_ends_with($name, self::PAGE_EXT)) {
                continue;
            }
            $relative = substr($item->getPathname(), \strlen($root) + 1, -\strlen(self::PAGE_EXT));
            yield str_replace('/', ':', $relative);
        }
    }

    private function writePageFile(string $path, string $document): void
    {
        $file = $this->pageFile($path);
        $dir = \dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create namespace directory');
        }
        AtomicWriter::put($file, $document);
    }

    /** A rename, its target directory made first; nothing when $from is not there (replay) */
    private function moveFile(string $from, string $to): void
    {
        if (!file_exists($from)) {
            return;
        }
        $dir = \dirname($to);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create directory');
        }
        if (file_exists($to)) {
            throw new RuntimeException('Cannot move over an existing file');
        }
        if (!rename($from, $to)) {
            throw new RuntimeException('Cannot move a page file');
        }
        Fsync::directory($dir);
        Fsync::directory(\dirname($from));
    }

    /** @return array{file_sha: string, file_mtime: int, file_bytes: int} */
    private function fileFacts(string $path): array
    {
        $file = $this->pageFile($path);
        $bytes = is_file($file) ? (string) file_get_contents($file) : '';
        clearstatcache(true, $file);
        $mtime = is_file($file) ? filemtime($file) : false;

        return ['file_sha' => hash('sha256', $bytes), 'file_mtime' => $mtime !== false ? $mtime : 0, 'file_bytes' => \strlen($bytes)];
    }

    /**
     * The caller's frontmatter with Storage's pid last (§5): an incoming
     * `pid` is never the caller's to set
     *
     * @param array<string, mixed> $frontmatter
     */
    private function encodeDocument(array $frontmatter, string $body, string $pid): string
    {
        $frontmatter = self::withoutPid($frontmatter);
        $frontmatter[self::PID_KEY] = $pid;

        return "---\n" . Yaml::dump($frontmatter, 4, 2) . "---\n\n" . self::normalizeText($body);
    }

    /**
     * @param array<string, mixed> $frontmatter
     *
     * @return array<string, mixed>
     */
    private static function withoutPid(array $frontmatter): array
    {
        unset($frontmatter[self::PID_KEY]);

        return $frontmatter;
    }

    /**
     * LF-only, as encodeDocument() writes (Support\DocumentFormat::parse()
     * normalises CRLF for text from a browser; nothing here comes from one)
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

    private static function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return rtrim($text, "\n") . "\n";
    }

    /** A human file name without path parts or markdown-breaking characters */
    private static function mediaName(string $name, string $ext): string
    {
        $base = pathinfo(basename(str_replace('\\', '/', $name)), PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[\x00-\x1f\[\]()<>]+/u', '', $base));

        return ($base !== '' ? mb_substr($base, 0, 80) : 'image') . '.' . $ext;
    }

    /** A draft belongs to an account's username only (never a pseudouser's) */
    private static function isDraftOwner(string $username): bool
    {
        return preg_match('/^[a-z0-9](?:[a-z0-9_.-]{0,62}[a-z0-9])?$/', $username) === 1;
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

    /** @return array<string, mixed> */
    private static function revlogEntry(int $n, string $ts, string $actor, ?string $note, int $bytes, string $sha256, string $kind, bool $auto = false, bool $minor = false): array
    {
        // `auto` only when set: a hand edit's entry keeps the shape it always had
        return [
            'n' => $n,
            'ts' => $ts,
            'by' => $actor,
            'note' => $note,
            'bytes' => $bytes,
            'sha256' => $sha256,
            'minor' => $minor,
            'kind' => $kind,
        ] + ($auto ? ['auto' => true] : []);
    }

    private function journal(): Journal
    {
        return new Journal($this->dataRoot . '/journal');
    }

    private static function now(): string
    {
        return (new DateTimeImmutable('now'))->format('Y-m-d\TH:i:sP');
    }

    private static function at(string $ts): DateTimeImmutable
    {
        return new DateTimeImmutable($ts);
    }
}
