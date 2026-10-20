<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Storage;

use DateTimeImmutable;
use FilesystemIterator;
use Reporion\Schema\Loader;
use Reporion\Support\Canonical;
use Reporion\Support\DocumentFormat;
use Reporion\Support\Revlog;
use Reporion\Support\Zip;
use Reporion\Support\ZipEntry;
use RuntimeException;
use Throwable;

/**
 * The one-off conversion of a 1.x data directory to the 2.0 layout (D54,
 * docs/architecture-v2.md §11), copy and verify: --from is only read; --to
 * is new and holds the result. Per page directory (meta.json + current.md):
 * the page file is current.md byte for byte, the attic zip holds every
 * rev/NNNN.md.gz gunzipped plus revlog.json from meta.json, the state rows
 * come from meta.json, media.json, comments.json and autosave.*.json.
 * Redirect stubs become redirect rows, trash/{slug}.{pid}/ becomes
 * trash/{pid}/, counters.json the counter table. Everything else in data/
 * is linked across (copied where a hard link cannot be made), the caches
 * left out.
 *
 * Every page is checked as it is written: each revision's bytes hash to its
 * revlog entry, the zip reads back to exactly those bytes and that record,
 * the page file equals current.md, the rows equal meta.json. A page that
 * fails is named by pid (never a path: invariant 8) and the run fails;
 * --from is never touched. Faults the 1.x archive already had (a
 * current.md that is not the newest revision, a broken chain, a signature
 * that no longer matches) are carried over as they were and counted.
 */
final class V1Converter
{
    /** data/ entries not carried: caches, the 1.x page tree and trash (converted), scratch space */
    private const NOT_COPIED = ['pages', 'trash', 'tmp', 'counters.json', 'counters.json.lock', '.write.lock'];
    private const CACHE_PREFIXES = ['index.sqlite', 'vectors.sqlite', 'reporion.sqlite'];

    /** @var array<string, int> */
    private array $counts = [];

    /** @var list<array{pid: ?string, path_hash: ?string, problem: string}> */
    private array $failures = [];

    private ?StateDb $state = null;

    public function __construct(
        private readonly string $from,
        private readonly string $to,
        private readonly Loader $schemas,
    ) {
    }

    /**
     * @return array{counts: array<string, int>, failures: list<array{pid: ?string, path_hash: ?string, problem: string}>}
     *
     * @throws RuntimeException when the run cannot start (nothing written then)
     */
    public function run(bool $dryRun): array
    {
        $this->counts = array_fill_keys([
            'pages', 'revisions', 'signatures', 'signatures_not_matching', 'current_stale', 'chain_problems',
            'media', 'comments', 'drafts', 'moves', 'deliveries', 'shares', 'redirects', 'redirects_dangling',
            'trash', 'strays', 'counters', 'files_linked', 'files_copied',
        ], 0);
        $this->failures = [];
        $this->preflight();

        if (!$dryRun) {
            if (!@mkdir($this->to, 0770, true)) {
                throw new RuntimeException('Cannot create --to');
            }
            $this->copyRest();
            $this->state = new StateDb(Databases::openState($this->to . '/reporion.sqlite'));
        }

        $paths = [];
        $stubs = [];
        foreach ($this->pageDirectories($this->from . '/pages', '') as [$dir, $path, $kind]) {
            if ($kind === 'page') {
                $pid = $this->page($dir, $path, null, $dryRun);
                if ($pid !== null) {
                    $paths[$path] = $pid;
                }
            } elseif ($kind === 'stub') {
                $stubs[$path] = trim((string) file_get_contents($dir . '/redirect'));
            } else {
                $this->stray($dir, $path, $dryRun);
            }
        }
        foreach ($stubs as $path => $target) {
            $this->redirect($path, $target, $paths, $dryRun);
        }
        $this->trash($dryRun);
        $this->counters($dryRun);

        return ['counts' => $this->counts, 'failures' => $this->failures];
    }

    /** Refused before anything is written: a missing or 2.0 source, an existing target, an unfinished write */
    private function preflight(): void
    {
        if (!is_dir($this->from . '/pages')) {
            throw new RuntimeException('--from has no pages/: not a data directory');
        }
        if (is_dir($this->from . '/attic')) {
            throw new RuntimeException('--from already has an attic/: it is in the 2.0 layout');
        }
        if (file_exists($this->to)) {
            throw new RuntimeException('--to exists: give a new directory');
        }
        if (realpath(\dirname($this->to)) === false) {
            throw new RuntimeException('--to: its parent directory does not exist');
        }
        if ((new Journal($this->from . '/journal'))->openIntents() !== []) {
            throw new RuntimeException('--from has unfinished writes: stop the instance, run bin/reporion journal:replay with the 1.x code, then this again');
        }
    }

    /**
     * One 1.x page directory, written and checked; null when it failed
     *
     * @param ?string $trashTo trash/{pid}/ of the target when the page is a trashed one
     */
    private function page(string $dir, string $path, ?string $trashTo, bool $dryRun): ?string
    {
        $meta = json_decode((string) @file_get_contents($dir . '/meta.json'), true);
        $pid = \is_array($meta) && \is_string($meta['pid'] ?? null) ? $meta['pid'] : null;
        if ($pid === null) {
            return $this->fail(null, $path, 'meta_unreadable');
        }
        if ($trashTo === null && !FileTree::isValidPath($path)) {
            // A segment 2.0 cannot hold (`x.md`, `.hidden`): rename it with the 1.x code first
            return $this->fail($pid, $path, 'path_invalid');
        }
        $current = (string) @file_get_contents($dir . '/current.md');
        $revlog = array_values((array) ($meta['revlog'] ?? []));
        $signatures = array_values((array) ($meta['signatures'] ?? []));
        $rev = (int) ($meta['rev'] ?? 0);
        if ($rev < 1 || \count($revlog) !== $rev) {
            return $this->fail($pid, $path, 'revlog_gap');
        }

        // Every revision, its bytes the ones the revlog recorded
        $revisions = [];
        foreach ($revlog as $i => $entry) {
            $n = $i + 1;
            $bytes = @gzdecode((string) @file_get_contents(sprintf('%s/rev/%04d.md.gz', $dir, $n)));
            if (!\is_array($entry) || (int) ($entry['n'] ?? 0) !== $n || $bytes === false) {
                return $this->fail($pid, $path, 'rev_missing');
            }
            if (!hash_equals((string) ($entry['sha256'] ?? ''), hash('sha256', $bytes))) {
                return $this->fail($pid, $path, 'rev_changed');
            }
            $revisions[$n] = $bytes;
        }
        if ($current !== $revisions[$rev]) {
            ++$this->counts['current_stale'];
        }
        if (Revlog::chainProblems($revlog, $pid) !== []) {
            ++$this->counts['chain_problems'];
        }
        $history = [
            'v' => Attic::FORMAT,
            'pid' => $pid,
            'status' => \in_array($meta['status'] ?? null, ['draft', 'signed', 'archived'], true) ? $meta['status'] : 'draft',
            'archived' => null,
            'revlog' => $revlog,
            'signatures' => $signatures,
        ];
        foreach ($signatures as $signature) {
            ++$this->counts['signatures'];
            if (!$this->signatureMatches($signature, $revisions)) {
                ++$this->counts['signatures_not_matching'];
            }
        }

        if (!$dryRun) {
            try {
                [$pageFile, $zip] = $trashTo !== null
                    ? [$trashTo . '/' . FileTree::TRASH_PAGE, $trashTo . '/' . FileTree::TRASH_ATTIC]
                    : [$this->to . '/pages/' . str_replace(':', '/', $path) . '.md', $this->to . '/attic/' . str_replace(':', '/', $path) . '.zip'];
                $this->writeZip($zip, $revisions, $history, $revlog);
                self::write($pageFile, $current);
                $this->rows($dir, $path, $meta, $history, $pageFile, $trashTo !== null);
            } catch (Throwable) {
                return $this->fail($pid, $path, 'write_failed');
            }
        }
        ++$this->counts['pages'];
        $this->counts['revisions'] += $rev;

        return $pid;
    }

    /**
     * The zip built from the source bytes, read back from its durable temp
     * file and checked before it is put in place: the record, and every
     * entry the very bytes of its revision
     *
     * @param array<int, string>              $revisions
     * @param array<string, mixed>            $history
     * @param list<array<string, mixed>>      $revlog
     */
    private function writeZip(string $zip, array $revisions, array $history, array $revlog): void
    {
        $entries = [];
        foreach ($revisions as $n => $bytes) {
            $entries[] = ZipEntry::deflated(Attic::entryName($n), $bytes, self::when((string) ($revlog[$n - 1]['ts'] ?? '')));
        }
        $json = json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $entries[] = ZipEntry::deflated(Attic::HISTORY, $json, new DateTimeImmutable());
        self::mkdir(\dirname($zip));
        AtomicWriter::putChecked($zip, Zip::assemble($entries), static function (string $tmp) use ($revisions, $history): void {
            $attic = new Attic();
            $problems = array_filter($attic->problems($tmp), static fn (array $p): bool => !str_starts_with($p['problem'], 'chain_'));
            if ($problems !== [] || $attic->history($tmp) !== json_decode((string) json_encode($history), true)) {
                throw new RuntimeException('The zip does not read back as written');
            }
            foreach ($revisions as $n => $bytes) {
                if ($attic->revision($tmp, $n) !== $bytes) {
                    throw new RuntimeException('A revision does not read back as written');
                }
            }
        });
    }

    /**
     * The page's state rows, then read back against meta.json
     *
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $history
     */
    private function rows(string $dir, string $path, array $meta, array $history, string $pageFile, bool $trashed): void
    {
        $state = $this->state ?? throw new RuntimeException('No state database');
        $pid = (string) $meta['pid'];
        $first = $history['revlog'][0];
        $state->transaction(function () use ($state, $dir, $path, $meta, $history, $pageFile, $trashed, $pid, $first): void {
            $deletion = $trashed ? $this->deletion($pid, $dir) : null;
            $state->insertPage([
                'pid' => $pid,
                'path' => $path,
                'rev' => \count($history['revlog']),
                'status' => (string) $history['status'],
                'created' => (string) ($meta['created'] ?? $first['ts'] ?? ''),
                'created_by' => (string) ($meta['created_by'] ?? $first['by'] ?? ''),
                'visibility' => \in_array($meta['visibility'] ?? null, ['private', 'unlisted', 'public'], true) ? $meta['visibility'] : 'private',
                'file_sha' => hash('sha256', (string) file_get_contents($pageFile)),
                'file_mtime' => (int) filemtime($pageFile),
                'file_bytes' => (int) filesize($pageFile),
                'trashed_at' => $deletion['ts'] ?? null,
                'trashed_by' => $deletion['actor'] ?? null,
            ]);
            $state->mirror($pid, $history);
            foreach ((array) ($meta['moves'] ?? []) as $move) {
                if (\is_array($move) && \is_string($move['from'] ?? null) && \is_string($move['to'] ?? null)) {
                    $state->addMove($pid, $move['from'], $move['to'], (string) ($move['ts'] ?? ''), (string) ($move['by'] ?? ''));
                    ++$this->counts['moves'];
                }
            }
            foreach ((array) ($meta['deliveries'] ?? []) as $delivery) {
                if (\is_array($delivery)) {
                    $state->addDelivery($pid, array_filter($delivery, \is_scalar(...)), FileTree::DELIVERIES_KEPT);
                    ++$this->counts['deliveries'];
                }
            }
            $share = $meta['share_token'] ?? null;
            if (\is_array($share) && \is_string($share['hash'] ?? null)) {
                $state->insertShare($pid, $share);
                ++$this->counts['shares'];
            }
            foreach (self::json($dir . '/media.json') as $entry) {
                if (\is_array($entry) && \is_string($entry['sha256'] ?? null) && \is_string($entry['ext'] ?? null)) {
                    $state->addMedia($pid, [
                        'sha256' => $entry['sha256'], 'ext' => $entry['ext'], 'name' => (string) ($entry['name'] ?? $entry['sha256'] . '.' . $entry['ext']),
                        'bytes' => (int) ($entry['bytes'] ?? 0), 'w' => (int) ($entry['w'] ?? 0), 'h' => (int) ($entry['h'] ?? 0),
                        'added' => (string) ($entry['added'] ?? ''), 'by' => (string) ($entry['by'] ?? ''),
                    ]);
                    ++$this->counts['media'];
                }
            }
            $comments = self::json($dir . '/' . 'comments.json')['comments'] ?? [];
            $state->replaceComments($pid, \is_array($comments) ? array_values($comments) : []);
            $this->counts['comments'] += \count($state->comments($pid));
            foreach (glob($dir . '/autosave.*.json') ?: [] as $file) {
                $username = substr(basename($file), \strlen('autosave.'), -\strlen('.json'));
                $draft = self::json($file);
                if (preg_match('/^[a-z0-9](?:[a-z0-9_.-]{0,62}[a-z0-9])?$/', $username) === 1 && \is_int($draft['base_rev'] ?? null) && \is_string($draft['form'] ?? null)) {
                    $state->putDraft($pid, $username, $draft['base_rev'], ($draft['raw'] ?? false) === true, $draft['form'], (string) ($draft['ts'] ?? ''));
                    ++$this->counts['drafts'];
                }
            }
        });

        // Read back: the rows are meta.json
        $row = $state->pageByPid($pid);
        $logged = [];
        foreach ($history['revlog'] as $entry) {
            $logged[(int) $entry['n']] = (string) $entry['sha256'];
        }
        if ($row === null || (string) $row['path'] !== $path || (int) $row['rev'] !== (int) $meta['rev']
            || $state->revisionShas($pid) !== $logged || \count($state->signatureRevs($pid)) !== \count($history['signatures'])) {
            throw new RuntimeException('The state rows do not read back as meta.json');
        }
    }

    /**
     * A 1.x redirect stub: a row naming the page it points at, by pid
     *
     * @param array<string, string> $paths converted page path => pid
     */
    private function redirect(string $path, string $target, array $paths, bool $dryRun): void
    {
        $pid = $paths[$target] ?? null;
        if ($pid === null) {
            ++$this->counts['redirects_dangling'];

            return;
        }
        if (!$dryRun) {
            $this->state?->transaction(fn () => $this->state->setRedirect($path, $pid));
        }
        ++$this->counts['redirects'];
    }

    /** trash/{slug}.{pid}/ (meta.json inside) becomes trash/{pid}/ with its path note */
    private function trash(bool $dryRun): void
    {
        foreach (glob($this->from . '/trash/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $meta = json_decode((string) @file_get_contents($dir . '/meta.json'), true);
            if (!\is_array($meta) || !\is_string($meta['pid'] ?? null) || preg_match('/^[0-9A-Za-z]+$/', $meta['pid']) !== 1) {
                $this->fail(null, basename($dir), 'trash_unreadable');
                continue;
            }
            $path = (string) ($meta['path'] ?? '');
            $target = $this->to . '/trash/' . $meta['pid'];
            if (!$dryRun) {
                self::mkdir($target);
                self::write($target . '/' . FileTree::TRASH_PATH, $path . "\n");
            }
            if ($this->page($dir, $path, $target, $dryRun) !== null) {
                ++$this->counts['trash'];
            }
        }
    }

    private function counters(bool $dryRun): void
    {
        $counters = self::json($this->from . '/counters.json');
        foreach ($counters as $key => $value) {
            if (\is_string($key) && is_numeric($value)) {
                if (!$dryRun) {
                    $this->state?->setCounter($key, (int) $value);
                }
                ++$this->counts['counters'];
            }
        }
    }

    /** A directory with page files and no meta.json: not a page in 1.x; its current.md is kept as a page file to adopt */
    private function stray(string $dir, string $path, bool $dryRun): void
    {
        ++$this->counts['strays'];
        if (!$dryRun && is_file($dir . '/current.md') && FileTree::isValidPath($path)) {
            $file = $this->to . '/pages/' . str_replace(':', '/', $path) . '.md';
            if (!file_exists($file)) {
                self::write($file, (string) file_get_contents($dir . '/current.md'));
            }
        }
    }

    /**
     * @param array<string, mixed> $signature
     * @param array<int, string>   $revisions
     */
    private function signatureMatches(array $signature, array $revisions): bool
    {
        $bytes = $revisions[(int) ($signature['rev'] ?? 0)] ?? null;
        if ($bytes === null) {
            return false;
        }
        try {
            [$frontmatter, $body] = DocumentFormat::parse($bytes);
            $order = \is_array($signature['field_order'] ?? null) ? $signature['field_order']
                : $this->schemas->fieldsFor(array_values(array_filter((array) ($frontmatter['modality'] ?? []), \is_string(...))));

            return hash_equals((string) ($signature['digest'] ?? ''), hash('sha256', Canonical::bytes($frontmatter, $body, $order)));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * When and by whom a trashed pid was deleted: the journal's last `delete`
     * intent, else the directory's time
     *
     * @return array{ts: string, actor: string}
     */
    private function deletion(string $pid, string $dir): array
    {
        $journal = new Journal($this->from . '/journal');
        $found = null;
        foreach ($journal->files() as $file) {
            foreach ($journal->readLines($file) as $line) {
                if (($line['op'] ?? null) === 'delete' && ($line['state'] ?? null) === 'intent' && ($line['pid'] ?? null) === $pid) {
                    $found = ['ts' => (string) ($line['ts'] ?? ''), 'actor' => (string) ($line['actor'] ?? '')];
                }
            }
        }

        return $found ?? ['ts' => date('Y-m-d\TH:i:sP', (int) filemtime($dir)), 'actor' => 'migrate-v2'];
    }

    /**
     * Every directory under pages/ that is a page (meta.json + current.md),
     * a redirect stub (redirect, no meta.json) or a stray (page files, no
     * meta.json); the pages under a page directory too
     *
     * @return iterable<array{0: string, 1: string, 2: 'page'|'stub'|'stray'}>
     */
    private function pageDirectories(string $dir, string $prefix): iterable
    {
        $names = scandir($dir) ?: [];
        sort($names);
        foreach ($names as $name) {
            if ($name === '.' || $name === '..' || $name === 'rev' || !is_dir($dir . '/' . $name) || is_link($dir . '/' . $name)) {
                continue;
            }
            $sub = $dir . '/' . $name;
            $path = $prefix === '' ? $name : $prefix . ':' . $name;
            if (is_file($sub . '/meta.json') && is_file($sub . '/current.md')) {
                yield [$sub, $path, 'page'];
            } elseif (is_file($sub . '/redirect') && !is_file($sub . '/meta.json')) {
                yield [$sub, $path, 'stub'];
            } elseif (is_file($sub . '/current.md') || is_dir($sub . '/rev')) {
                yield [$sub, $path, 'stray'];
            }
            yield from $this->pageDirectories($sub, $path);
        }
    }

    /** data/'s other entries (users, groups, media, audit, journal, settings, …), hard-linked where the filesystem allows */
    private function copyRest(): void
    {
        foreach (new FilesystemIterator($this->from, FilesystemIterator::SKIP_DOTS) as $item) {
            $name = $item->getFilename();
            if (\in_array($name, self::NOT_COPIED, true) || str_starts_with($name, '.pre-restore-')) {
                continue;
            }
            foreach (self::CACHE_PREFIXES as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    continue 2;
                }
            }
            $this->copyTree($item->getPathname(), $this->to . '/' . $name);
        }
    }

    private function copyTree(string $from, string $to): void
    {
        if (is_link($from)) {
            return;
        }
        if (is_dir($from)) {
            self::mkdir($to);
            foreach (new FilesystemIterator($from, FilesystemIterator::SKIP_DOTS) as $item) {
                $this->copyTree($item->getPathname(), $to . '/' . $item->getFilename());
            }

            return;
        }
        if (@link($from, $to)) {
            ++$this->counts['files_linked'];

            return;
        }
        if (!@copy($from, $to)) {
            throw new RuntimeException('Cannot copy a data file');
        }
        @touch($to, (int) filemtime($from));
        ++$this->counts['files_copied'];
    }

    private function fail(?string $pid, string $path, string $problem): ?string
    {
        $this->failures[] = ['pid' => $pid, 'path_hash' => $pid === null ? 'sha256:' . hash('sha256', $path) : null, 'problem' => $problem];

        return null;
    }

    /** @return array<mixed> */
    private static function json(string $file): array
    {
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return \is_array($data) ? $data : [];
    }

    private static function write(string $file, string $bytes): void
    {
        self::mkdir(\dirname($file));
        AtomicWriter::put($file, $bytes);
    }

    private static function mkdir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create a directory');
        }
    }

    private static function when(string $ts): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($ts !== '' ? $ts : 'now');
        } catch (Throwable) {
            return new DateTimeImmutable();
        }
    }
}
