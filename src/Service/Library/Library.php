<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Library;

use InvalidArgumentException;
use Reporion\Auth\User;
use Reporion\Exception\LibraryException;
use Reporion\Exception\PageExistsException;
use Reporion\Support\Bibliography;
use Reporion\Index\IndexInterface;
use Reporion\Service\TempUploads;
use Reporion\Storage\FileTree;
use Reporion\Storage\PageRecord;
use Reporion\Support\ArticleIds;
use Reporion\Support\Citation;
use Reporion\Support\CitationKey;
use Reporion\Support\HtmlMeta;
use Reporion\Support\Cnp;
use Reporion\Support\LibrarySource;
use Reporion\Support\Slug;
use Reporion\Support\SourceText;

/**
 * The library (docs/library.md): one page per article, `{namespace}:{key}`, its body the
 * article's cleaned text, its frontmatter the record (`csl`, CSL-JSON) and the original
 * (`original`, the file in data/sources/).
 *
 * Two steps, as Mendeley's: upload() reads the file and proposes a record (nothing stored but a
 * temporary upload), confirm() makes the page from the record the writer saw and corrected (D8).
 * add() does both at once, for the CLI. Never patient data: a text holding a valid CNP is refused, and
 * so is a file with no text to check (a scan), until OCR.
 */
final class Library
{
    /** The temporary uploads: the proposal, and the file itself */
    private const BUCKET = 'library';
    private const FILE_BUCKET = 'library-file';

    /** CSL-JSON fields kept, and how each is read */
    private const TEXT_FIELDS = [
        'title', 'container-title', 'container-title-short', 'volume', 'issue', 'page', 'publisher', 'publisher-place',
        'edition', 'number', 'language', 'ISSN', 'ISBN', 'PMCID', 'note', 'genre',
    ];
    private const TYPES = ['article-journal', 'article', 'book', 'chapter', 'paper-conference', 'report', 'thesis', 'webpage', 'review', 'manuscript', 'dataset', 'standard'];

    private const MAX_TAGS = 20;
    private const PREVIEW_CHARS = 800;

    public function __construct(
        private readonly FileTree $storage,
        private readonly IndexInterface $index,
        private readonly Extractor $extractor,
        private readonly TempUploads $temp,
        private readonly string $namespace = 'library',
        private readonly int $maxBytes = 50 * 1024 * 1024,
        // The online lookups (Admin → Settings → Library, off by default): null sends nothing anywhere
        private readonly ?Lookup $lookup = null,
    ) {
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    /** Whether $principal may add to the library: an editor of its namespace */
    public function canAdd(?User $principal): bool
    {
        return $principal !== null && $principal->canWrite($this->namespace);
    }

    /**
     * Reads the file and proposes its record. Kept for an hour under the returned token.
     *
     * @return array<string, mixed> token, name, ext, bytes, pages, has_text, csl, how, key, duplicate, preview
     *
     * @throws LibraryException
     */
    public function upload(string $bytes, string $name, User $principal): array
    {
        [$ext, $extracted, $proposal] = $this->proposal($bytes, $name, $principal, true);
        $fileToken = $this->temp->put(self::FILE_BUCKET, $bytes);
        $token = $this->temp->put(self::BUCKET, (string) json_encode([
            'name' => self::fileName($name),
            'ext' => $ext,
            'sha256' => hash('sha256', $bytes),
            'file' => $fileToken,
            'extracted' => $extracted->toArray(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

        return ['token' => $token] + $proposal;
    }

    /**
     * upload()'s answer without keeping anything (bin/reporion library:add --dry-run)
     *
     * @return array<string, mixed>
     *
     * @throws LibraryException
     */
    public function propose(string $bytes, string $name, User $principal): array
    {
        return $this->proposal($bytes, $name, $principal, false)[2];
    }

    /**
     * @return array{0: string, 1: Extracted, 2: array<string, mixed>}
     *
     * @throws LibraryException
     */
    private function proposal(string $bytes, string $name, User $principal, bool $online): array
    {
        [$ext, $extracted] = $this->read($bytes, $name);
        $identified = Identify::propose($extracted);
        // A web page says what it is in its head (citation_* tags, else <title>): that, over a guess from its text (phase 7)
        if (\in_array($ext, ['html', 'htm'], true) && ($meta = HtmlMeta::csl($bytes)) !== []) {
            $identified['csl'] = array_merge($identified['csl'], $meta);
            $identified['how'] = array_merge($identified['how'], array_fill_keys(array_keys($meta), 'html'));
        }
        [$identified['csl'], $tags, $looked] = $online ? $this->lookedUp($identified['csl']) : [$identified['csl'], [], null];

        return [$ext, $extracted, [
            'name' => self::fileName($name),
            'ext' => $ext,
            'bytes' => \strlen($bytes),
            'pages' => $extracted->pages,
            'has_text' => $extracted->hasText,
            'csl' => $identified['csl'],
            'how' => $identified['how'],
            // What the online lookup did: 'crossref', 'pubmed', 'crossref+pubmed', 'none' (nothing found), 'failed' (not reached), null (off)
            'lookup' => $looked,
            'tags' => $tags,
            'key' => CitationKey::make($identified['csl'], fn (string $k): bool => $this->keyTaken($k)),
            'duplicate' => $this->duplicateOf($identified['csl'], hash('sha256', $bytes), $principal),
            'preview' => self::preview($extracted->body),
        ]];
    }

    /**
     * Makes the library page from an upload and the record the writer confirmed.
     *
     * @param array<string, mixed> $fields csl (object), key (optional), tags (optional list)
     *
     * @throws LibraryException
     */
    public function confirm(string $token, array $fields, User $principal, string $actor): PageRecord
    {
        $csl = self::cleanCsl(\is_array($fields['csl'] ?? null) ? $fields['csl'] : []);
        $tags = self::cleanTags($fields['tags'] ?? []);

        // Read, not taken: a refusal below (a duplicate, a taken key) leaves the upload for another try
        $json = $this->temp->peek(self::BUCKET, $token);
        $upload = $json !== null ? json_decode($json, true) : null;
        $bytes = \is_array($upload) ? $this->temp->peek(self::FILE_BUCKET, (string) ($upload['file'] ?? '')) : null;
        if (!\is_array($upload) || $bytes === null) {
            throw new LibraryException(LibraryException::EXPIRED);
        }
        $page = $this->create($bytes, (string) $upload['ext'], (string) $upload['name'], Extracted::fromArray((array) $upload['extracted']), $csl, $fields['key'] ?? null, $tags, $principal, $actor);
        $this->temp->take(self::BUCKET, $token);
        $this->temp->take(self::FILE_BUCKET, (string) $upload['file']);

        return $page;
    }

    /**
     * upload() and confirm() in one: the proposed record as it is (bin/reporion library:add)
     *
     * @param array<string, mixed> $fields as confirm()'s; a field given replaces the proposed one
     *
     * @return array{0: PageRecord, 1: array<string, mixed>} the page and the record it holds
     *
     * @throws LibraryException
     */
    public function add(string $bytes, string $name, User $principal, string $actor, array $fields = []): array
    {
        [$ext, $extracted] = $this->read($bytes, $name);
        [$looked, $mesh] = $this->lookedUp(Identify::propose($extracted)['csl']);
        $csl = self::cleanCsl(array_merge($looked, \is_array($fields['csl'] ?? null) ? $fields['csl'] : []));
        $record = $this->create($bytes, $ext, self::fileName($name), $extracted, $csl, $fields['key'] ?? null, self::cleanTags(array_merge($mesh, \is_array($fields['tags'] ?? null) ? $fields['tags'] : [])), $principal, $actor);

        return [$record, $csl];
    }

    /**
     * A source's record corrected after the fact (the record card's Edit): the record cleaned as on
     * confirm, the page's title with it, its tags when given; one new revision. A DOI or PMID
     * another source holds is refused.
     *
     * @param array<string, mixed> $csl
     * @param list<string>|null    $tags null: left as they are
     *
     * @throws LibraryException INVALID (not a source, no title), DUPLICATE (with the other's pid)
     * @throws \Reporion\Exception\RevisionConflictException when $baseRev is not the current revision
     * @throws \Reporion\Exception\PageNotFoundException
     */
    public function updateRecord(string $path, array $csl, ?array $tags, int $baseRev, User $principal, string $actor): PageRecord
    {
        $page = $this->storage->read($path);
        if (!LibrarySource::isSource($page->frontmatter)) {
            throw new LibraryException(LibraryException::INVALID);
        }
        $csl = self::cleanCsl($csl);
        $duplicate = $this->duplicateOf($csl, '', $principal, $page->pid);
        if ($duplicate !== null && $duplicate['by'] !== 'title') {
            throw new LibraryException(LibraryException::DUPLICATE, $duplicate['pid']);
        }
        $frontmatter = $page->frontmatter;
        $frontmatter['title'] = $csl['title'];
        $frontmatter['csl'] = $csl;
        if ($tags !== null) {
            $frontmatter['tags'] = self::cleanTags($tags);
            if ($frontmatter['tags'] === []) {
                unset($frontmatter['tags']);
            }
        }

        return $this->storage->save($path, $frontmatter, $page->body, $baseRev, $actor, 'library: record');
    }

    /** Whether the online lookups are on (Admin → Settings → Library `library.lookup`) */
    public function lookupOn(): bool
    {
        return $this->lookup !== null;
    }

    /**
     * A source's record asked again of CrossRef (its DOI) and PubMed (its PMID, or the one PubMed has
     * for the DOI): what they return **overwrites** the record's fields, as at upload (owner's choice,
     * 2026-10-31: whoever runs it wants the fields updated); a field the lookup does not return stays.
     * The page's title follows the record's. PubMed's MeSH terms are added to the tags, none removed.
     * Only the identifier is sent. One new revision when something changed; none otherwise. With
     * $dryRun the lookup is made and nothing is written.
     *
     * @return array{pid: string, path: string, rev: int, status: string, how: ?string, changed: list<string>, tags: list<string>}
     *               status: `updated`, `unchanged`, `no_id` (no DOI or PMID), `none` (nothing found), `failed` (not reached)
     *
     * @throws LibraryException LOOKUP_OFF, INVALID (not a source), DUPLICATE (the lookup brings a DOI or PMID another source holds), PATIENT_DATA
     * @throws \Reporion\Exception\RevisionConflictException
     * @throws \Reporion\Exception\PageNotFoundException
     */
    public function relookup(string $path, User $principal, string $actor, bool $dryRun = false): array
    {
        if ($this->lookup === null) {
            throw new LibraryException(LibraryException::LOOKUP_OFF);
        }
        $page = $this->storage->read($path);
        if (!LibrarySource::isSource($page->frontmatter)) {
            throw new LibraryException(LibraryException::INVALID);
        }
        $result = ['pid' => $page->pid, 'path' => $page->path, 'rev' => $page->rev, 'status' => 'unchanged', 'how' => null, 'changed' => [], 'tags' => []];
        $before = self::cleanCsl(\is_array($page->frontmatter['csl'] ?? null) ? $page->frontmatter['csl'] : []);
        if (!isset($before['DOI']) && !isset($before['PMID'])) {
            return ['status' => 'no_id'] + $result;
        }
        [$found, $mesh, $how] = $this->lookedUp($before);
        $result['how'] = $how;
        if ($how === 'failed' || $how === 'none') {
            return ['status' => $how] + $result;
        }
        $after = self::cleanCsl($found);
        // A DOI or PMID the lookup brings that another source holds: refused, nothing written
        $duplicate = $this->duplicateOf($after, '', $principal, $page->pid);
        if ($duplicate !== null && $duplicate['by'] !== 'title') {
            throw new LibraryException(LibraryException::DUPLICATE, $duplicate['pid']);
        }
        $changed = array_values(array_filter(array_unique(array_merge(array_keys($before), array_keys($after))), static fn (string $field): bool => ($before[$field] ?? null) !== ($after[$field] ?? null)));
        $have = array_values(array_filter(\is_array($page->frontmatter['tags'] ?? null) ? $page->frontmatter['tags'] : [], 'is_string'));
        $tags = self::cleanTags(array_merge($have, $mesh));
        $added = array_values(array_diff($tags, $have));
        $result['changed'] = $changed;
        $result['tags'] = $added;
        if ($changed === [] && $added === []) {
            return $result;
        }
        $result['status'] = 'updated';
        if ($dryRun) {
            return $result;
        }
        $frontmatter = $page->frontmatter;
        $frontmatter['title'] = $after['title'];
        $frontmatter['csl'] = $after;
        if ($tags !== []) {
            $frontmatter['tags'] = $tags;
        }
        $saved = $this->storage->save($path, $frontmatter, $page->body, $page->rev, $actor, 'library: lookup (' . implode(', ', $changed) . ')');
        $result['rev'] = $saved->rev;

        return $result;
    }

    /**
     * The library as its view lists it: every source $principal may see, with what the table shows
     *
     * @return list<array{pid: string, path: string, key: string, title: string, authors: string, year: ?int, journal: string, tags: list<string>, ext: string, doi: string}>
     */
    public function entries(User $principal): array
    {
        $entries = [];
        foreach ($this->index->listNamespaceMeta($this->namespace, $principal) as $row) {
            $meta = $row['meta'];
            if (!LibrarySource::isSource($meta)) {
                continue;
            }
            $csl = \is_array($meta['csl'] ?? null) ? $meta['csl'] : [];
            $journal = \is_string($csl['container-title'] ?? null) ? $csl['container-title'] : (\is_string($csl['publisher'] ?? null) ? $csl['publisher'] : '');
            $entries[] = [
                'pid' => $row['pid'],
                'path' => $row['path'],
                'key' => substr($row['path'], \strlen($this->namespace) + 1),
                'title' => $row['title'] !== '' ? $row['title'] : (string) ($csl['title'] ?? ''),
                'authors' => Citation::authorsShort($csl),
                'year' => Citation::year($csl),
                'journal' => $journal,
                'tags' => array_values(array_filter(\is_array($meta['tags'] ?? null) ? $meta['tags'] : [], 'is_string')),
                'cited' => \count($this->index->backlinks($row['pid'], $principal)),
                'ext' => \is_array($meta['original'] ?? null) ? (string) ($meta['original']['ext'] ?? '') : '',
                'doi' => \is_string($csl['DOI'] ?? null) ? $csl['DOI'] : '',
            ];
        }

        return $entries;
    }

    /**
     * The records the caller may read, for an export (docs/library.md): the same listing as entries(),
     * under the index predicate (invariant 6)
     *
     * @param ?list<string> $keys only these keys
     * @param ?string       $tag  only sources with this tag
     *
     * @return list<array{key: string, csl: array<string, mixed>, tags: list<string>}>
     */
    public function records(User $principal, ?array $keys = null, ?string $tag = null): array
    {
        $records = [];
        foreach ($this->index->listNamespaceMeta($this->namespace, $principal) as $row) {
            $meta = $row['meta'];
            $key = substr($row['path'], \strlen($this->namespace) + 1);
            $tags = array_values(array_filter(\is_array($meta['tags'] ?? null) ? $meta['tags'] : [], 'is_string'));
            if (!LibrarySource::isSource($meta) || !\is_array($meta['csl'] ?? null) || ($keys !== null && !\in_array($key, $keys, true)) || ($tag !== null && !\in_array($tag, $tags, true))) {
                continue;
            }
            $records[] = ['key' => $key, 'csl' => $meta['csl'], 'tags' => $tags];
        }

        return $records;
    }

    /**
     * The records of the keys a page cites, in the order given: bibliographic facts only, whoever
     * reads the citing page (owner, 2026-10-30), as its References section prints them
     *
     * @param list<string> $keys
     *
     * @return list<array{key: string, csl: array<string, mixed>, tags: list<string>}>
     */
    public function citedRecords(array $keys): array
    {
        $citations = new Citations($this->storage, $this->namespace);
        $records = [];
        foreach ($keys as $key) {
            $csl = $citations->record($key);
            if ($csl !== null) {
                $records[] = ['key' => $key, 'csl' => $csl, 'tags' => []];
            }
        }

        return $records;
    }

    /**
     * An article already in the library that $csl or the file's hash names: by the file, the DOI,
     * the PMID, else the folded title and year (the last only a hint, never a refusal)
     *
     * @param array<string, mixed> $csl
     *
     * @return array{pid: string, path: string, title: string, by: string, has_original: bool}|null
     */
    public function duplicateOf(array $csl, string $sha, User $principal, ?string $exceptPid = null): ?array
    {
        $doi = \is_string($csl['DOI'] ?? null) ? ArticleIds::normalizeDoi($csl['DOI']) : null;
        $pmid = \is_scalar($csl['PMID'] ?? null) ? (string) $csl['PMID'] : null;
        $title = \is_string($csl['title'] ?? null) ? Slug::fold($csl['title']) : '';
        $year = $csl['issued']['date-parts'][0][0] ?? null;
        $byTitle = null;
        foreach ($this->index->listNamespaceMeta($this->namespace, $principal) as $row) {
            if ($row['pid'] === $exceptPid) {
                continue;
            }
            $meta = $row['meta'];
            $theirs = \is_array($meta['csl'] ?? null) ? $meta['csl'] : [];
            $hasOriginal = \is_array($meta['original'] ?? null);
            $hit = static fn (string $by): array => ['pid' => $row['pid'], 'path' => $row['path'], 'title' => $row['title'], 'by' => $by, 'has_original' => $hasOriginal];
            if ($sha !== '' && $hasOriginal && ($meta['original']['sha256'] ?? null) === $sha) {
                return $hit('file');
            }
            if ($doi !== null && \is_string($theirs['DOI'] ?? null) && ArticleIds::normalizeDoi($theirs['DOI']) === $doi) {
                return $hit('doi');
            }
            if ($pmid !== null && $pmid !== '' && (string) ($theirs['PMID'] ?? '') === $pmid) {
                return $hit('pmid');
            }
            if ($byTitle === null && $title !== '' && \is_string($theirs['title'] ?? null) && Slug::fold($theirs['title']) === $title
                && ($theirs['issued']['date-parts'][0][0] ?? null) === $year) {
                $byTitle = $hit('title');
            }
        }

        return $byTitle;
    }

    /**
     * CSL-JSON as the library keeps it: known fields only, plain strings, people as
     * {family, given} or {literal}, a DOI folded; a title is required
     *
     * @param array<string, mixed> $csl
     *
     * @return array<string, mixed>
     *
     * @throws LibraryException INVALID when there is no title
     */
    public static function cleanCsl(array $csl): array
    {
        $clean = ['type' => \in_array($csl['type'] ?? null, self::TYPES, true) ? $csl['type'] : 'article-journal'];
        foreach (self::TEXT_FIELDS as $field) {
            $value = self::line($csl[$field] ?? null);
            if ($value !== '') {
                $clean[$field] = $value;
            }
        }
        if (!isset($clean['title'])) {
            throw new LibraryException(LibraryException::INVALID);
        }
        $authors = [];
        foreach (\is_array($csl['author'] ?? null) ? \array_slice(array_values($csl['author']), 0, 100) : [] as $person) {
            if (!\is_array($person)) {
                continue;
            }
            $family = self::line($person['family'] ?? null);
            $given = self::line($person['given'] ?? null);
            $literal = self::line($person['literal'] ?? null);
            if ($family !== '') {
                $authors[] = $given !== '' ? ['family' => $family, 'given' => $given] : ['family' => $family];
            } elseif ($literal !== '') {
                $authors[] = ['literal' => $literal];
            }
        }
        if ($authors !== []) {
            $clean['author'] = $authors;
        }
        $parts = $csl['issued']['date-parts'][0] ?? null;
        if (\is_array($parts) && isset($parts[0]) && is_numeric($parts[0]) && (int) $parts[0] >= 1500 && (int) $parts[0] <= (int) date('Y') + 1) {
            $date = [(int) $parts[0]];
            foreach ([1 => 12, 2 => 31] as $i => $max) {
                if (!isset($parts[$i]) || !is_numeric($parts[$i]) || (int) $parts[$i] < 1 || (int) $parts[$i] > $max) {
                    break;
                }
                $date[] = (int) $parts[$i];
            }
            $clean['issued'] = ['date-parts' => [$date]];
        }
        $doi = \is_string($csl['DOI'] ?? null) ? ArticleIds::normalizeDoi($csl['DOI']) : null;
        if ($doi !== null) {
            $clean['DOI'] = $doi;
        }
        if (\is_scalar($csl['PMID'] ?? null) && preg_match('/^\d{1,9}$/', (string) $csl['PMID']) === 1) {
            $clean['PMID'] = (string) $csl['PMID'];
        }
        $url = self::line($csl['URL'] ?? null);
        if (preg_match('~^https?://\S+$~i', $url) === 1) {
            $clean['URL'] = $url;
        }
        $abstract = \is_string($csl['abstract'] ?? null) ? trim(mb_substr($csl['abstract'], 0, 10000)) : '';
        if ($abstract !== '') {
            $clean['abstract'] = $abstract;
        }
        // A record is literature too: a CNP in any of its texts is a patient's (invariants 8, 10); ids and numbers are not text
        $texts = array_values(array_diff_key($clean, array_flip(['DOI', 'ISBN', 'ISSN', 'URL', 'PMID', 'PMCID', 'page', 'volume', 'issue', 'number'])));
        foreach ($authors as $author) {
            $texts = array_merge($texts, array_values($author));
        }
        if (self::holdsPatientData(implode("\n", array_filter($texts, 'is_string')))) {
            throw new LibraryException(LibraryException::PATIENT_DATA);
        }

        return $clean;
    }

    /**
     * The file added to a source that had only its record: the original, the text as the page body, and the
     * fields the record lacked; the key, the tags and the rest of the record untouched
     *
     * @param array<string, mixed> $csl the file's own proposal
     *
     * @throws LibraryException
     */
    private function attach(string $path, string $bytes, string $ext, string $name, Extracted $extracted, array $csl, string $actor): PageRecord
    {
        $page = $this->storage->read($path);
        $original = $this->storage->storeOriginal($bytes, $ext) + ['name' => $name, 'pages' => $extracted->pages, 'text' => $extracted->hasText] + ($extracted->ocr ? ['ocr' => true] : []);
        $frontmatter = $page->frontmatter;
        $frontmatter['original'] = $original;
        $frontmatter['csl'] = \is_array($frontmatter['csl'] ?? null) ? $frontmatter['csl'] + $csl : $csl;

        try {
            return $this->storage->save($path, $frontmatter, trim($page->body) === '' ? self::body($extracted) : $page->body, $page->rev, $actor, 'library: file added (' . $ext . ')');
        } catch (\Reporion\Exception\RevisionConflictException) {
            throw new LibraryException(LibraryException::DUPLICATE, $page->pid);
        }
    }

    /** The most records one import takes */
    public const MAX_IMPORT = 500;

    /**
     * Records from a reference manager's export (BibTeX, RIS or CSL-JSON, docs/library.md) as sources
     * with no file: each cleaned as a confirmed record, skipped when it has no title, holds a patient's
     * CNP, or is in the library already (DOI, PMID, or the same title and year). A later upload of
     * the same DOI or PMID takes the file.
     *
     * @param ?list<string> $extraTags added to every record
     *
     * @return array{added: list<array{key: string, path: string, pid: string, title: string}>, skipped: list<array{title: string, reason: string}>}
     *
     * @throws LibraryException UNSUPPORTED when the text is none of the formats, TOO_LARGE above MAX_IMPORT records
     */
    public function importRecords(string $text, User $principal, string $actor, ?array $extraTags = null, bool $dryRun = false): array
    {
        $entries = Bibliography::parse($text);
        if ($entries === [] && Bibliography::detect($text) === null) {
            throw new LibraryException(LibraryException::UNSUPPORTED);
        }
        if (\count($entries) > self::MAX_IMPORT) {
            throw new LibraryException(LibraryException::TOO_LARGE);
        }
        $added = [];
        $skipped = [];
        foreach ($entries as $entry) {
            $label = \is_string($entry['csl']['title'] ?? null) ? mb_substr($entry['csl']['title'], 0, 200) : '';
            // The file's key may become the page's path: a CNP there is a patient's too
            if (self::holdsPatientData($entry['key'])) {
                $skipped[] = ['title' => '', 'reason' => LibraryException::PATIENT_DATA];
                continue;
            }
            try {
                $csl = self::cleanCsl($entry['csl']);
            } catch (LibraryException $e) {
                $skipped[] = ['title' => $e->reason === LibraryException::PATIENT_DATA ? '' : $label, 'reason' => $e->reason];
                continue;
            }
            if ($this->duplicateOf($csl, '', $principal) !== null) {
                $skipped[] = ['title' => $label, 'reason' => LibraryException::DUPLICATE];
                continue;
            }
            $wanted = strtolower(trim($entry['key']));
            $key = CitationKey::isValid($wanted) && !$this->keyTaken($wanted) ? $wanted : CitationKey::make($csl, fn (string $k): bool => $this->keyTaken($k));
            $tags = self::cleanTags(array_merge($entry['tags'], $extraTags ?? []));
            if ($dryRun) {
                $added[] = ['key' => $key, 'path' => $this->namespace . ':' . $key, 'pid' => '', 'title' => $csl['title']];
                continue;
            }
            try {
                $page = $this->storage->create($this->namespace . ':' . $key, array_filter(['title' => $csl['title'], 'visibility' => 'private', 'tags' => $tags, 'csl' => $csl], static fn (mixed $v): bool => $v !== []), '', $actor, 'library: imported record', exclusive: true);
            } catch (PageExistsException) {
                $skipped[] = ['title' => $label, 'reason' => LibraryException::DUPLICATE];
                continue;
            } catch (InvalidArgumentException) {
                $skipped[] = ['title' => $label, 'reason' => LibraryException::INVALID];
                continue;
            }
            $added[] = ['key' => $key, 'path' => $page->path, 'pid' => $page->pid, 'title' => $csl['title']];
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    /**
     * A proposed record completed from CrossRef (a DOI) and PubMed (a PMID, or the one PubMed has for
     * the DOI), when the lookup is on: what they return replaces what was read from the file; only
     * the identifier is sent. A failure leaves the record as it was.
     *
     * @param array<string, mixed> $csl
     *
     * @return array{0: array<string, mixed>, 1: list<string>, 2: ?string} the record, MeSH terms as tags, what was done
     */
    private function lookedUp(array $csl): array
    {
        if ($this->lookup === null) {
            return [$csl, [], null];
        }
        $doi = \is_string($csl['DOI'] ?? null) ? $csl['DOI'] : null;
        $pmid = \is_scalar($csl['PMID'] ?? null) ? (string) $csl['PMID'] : null;
        if ($doi === null && $pmid === null) {
            return [$csl, [], 'none'];
        }
        $found = [];
        $tags = [];
        try {
            $crossref = $doi !== null ? $this->lookup->crossref($doi) : null;
            if ($crossref !== null) {
                $found[] = 'crossref';
                $csl = $crossref['csl'] + $csl;
            }
            $pmid ??= $doi !== null ? $this->lookup->pmidOf($doi) : null;
            $pubmed = $pmid !== null ? $this->lookup->pubmed($pmid) : null;
            if ($pubmed !== null) {
                $found[] = 'pubmed';
                $csl = ($crossref !== null ? $csl + $pubmed['csl'] : $pubmed['csl'] + $csl);
                $tags = $pubmed['tags'];
            }
        } catch (\RuntimeException) {
            return [$csl, $tags, 'failed'];
        }

        return [$csl, $tags, $found === [] ? 'none' : implode('+', $found)];
    }

    /**
     * Whether the text holds a valid CNP: a patient document, which the library never takes. Text
     * read by OCR ($ocr) is held to more (phase 8): OCR misreads digits (O for 0, l for 1, S for 5,
     * a space inside), which breaks a CNP's checksum, so there any 13 digits shaped like a CNP (a
     * sex digit, a month, a day) are enough, with the look-alike letters read as digits.
     * Refusing an article for a number that only looks like a CNP is the safe side.
     */
    public static function holdsPatientData(string $text, bool $ocr = false): bool
    {
        if (preg_match_all('/(?<!\d)[1-9]\d{12}(?!\d)/', $text, $m) > 0) {
            foreach ($m[0] as $candidate) {
                if (Cnp::isValid($candidate)) {
                    return true;
                }
            }
        }
        if (!$ocr) {
            return false;
        }
        // Digits and their look-alikes, a single space or dot allowed between them, at least 10 real digits
        preg_match_all('/(?<![\p{L}\d])[\dOoDQIl|!SsBZz](?:[ .]?[\dOoDQIl|!SsBZz]){12,14}(?![\p{L}\d])/u', $text, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as [$run, $at]) {
            // An ISBN-13 (978…, 979…) has a CNP's shape: not one when it says so
            if (stripos(substr($text, max(0, $at - 12), min(12, $at)), 'ISBN') !== false) {
                continue;
            }
            $digits = strtr((string) preg_replace('/[ .]/', '', $run), ['O' => '0', 'o' => '0', 'D' => '0', 'Q' => '0', 'I' => '1', 'l' => '1', '|' => '1', '!' => '1', 'S' => '5', 's' => '5', 'B' => '8', 'Z' => '2', 'z' => '2']);
            if (\strlen($digits) !== 13 || preg_match_all('/\d/', $run) < 10) {
                continue;
            }
            if (preg_match('/^[1-9]\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])\d{6}$/', $digits) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: string, 1: Extracted}
     *
     * @throws LibraryException
     */
    private function read(string $bytes, string $name): array
    {
        if ($bytes === '') {
            throw new LibraryException(LibraryException::INVALID);
        }
        if (\strlen($bytes) > $this->maxBytes) {
            throw new LibraryException(LibraryException::TOO_LARGE);
        }
        $ext = Extractor::extensionOf($name) ?? throw new LibraryException(LibraryException::UNSUPPORTED);
        $extracted = $this->extractor->extract($bytes, $ext);
        // A scan cannot be checked for patient data: refused unless OCR gives it text (owner, 2026-10-30; phase 8)
        if (!$extracted->hasText) {
            throw new LibraryException(LibraryException::NO_TEXT);
        }
        if (self::holdsPatientData($extracted->body . "\n" . $extracted->references . "\n" . $extracted->head, $extracted->ocr)) {
            throw new LibraryException(LibraryException::PATIENT_DATA);
        }

        return [$ext, $extracted];
    }

    /**
     * @param array<string, mixed> $csl
     * @param list<string>         $tags
     *
     * @throws LibraryException
     */
    private function create(string $bytes, string $ext, string $name, Extracted $extracted, array $csl, mixed $typedKey, array $tags, User $principal, string $actor): PageRecord
    {
        $sha = hash('sha256', $bytes);
        $duplicate = $this->duplicateOf($csl, $sha, $principal);
        // A record kept with no file (an import) takes the file of the same DOI or PMID: the page, its key and
        // its notebooks' citations stay as they are
        if ($duplicate !== null && !$duplicate['has_original'] && \in_array($duplicate['by'], ['doi', 'pmid'], true)) {
            return $this->attach($duplicate['path'], $bytes, $ext, $name, $extracted, $csl, $actor);
        }
        if ($duplicate !== null && $duplicate['by'] !== 'title') {
            throw new LibraryException(LibraryException::DUPLICATE, $duplicate['pid']);
        }
        $key = $this->keyFor($typedKey, $csl);

        $original = $this->storage->storeOriginal($bytes, $ext) + [
            'name' => $name,
            'pages' => $extracted->pages,
            'text' => $extracted->hasText,
        ] + ($extracted->ocr ? ['ocr' => true] : []);
        $frontmatter = array_filter([
            'title' => $csl['title'],
            'visibility' => 'private',
            'tags' => $tags,
            'csl' => $csl,
            'original' => $original,
        ], static fn (mixed $v): bool => $v !== []);

        try {
            return $this->storage->create($this->namespace . ':' . $key, $frontmatter, self::body($extracted), $actor, 'library: from ' . $ext, exclusive: true);
        } catch (PageExistsException) {
            throw new LibraryException(LibraryException::DUPLICATE);
        } catch (InvalidArgumentException) {
            throw new LibraryException(LibraryException::INVALID);
        }
    }

    /** The page body: the article's text, then its own references as they were printed */
    private static function body(Extracted $extracted): string
    {
        $body = rtrim($extracted->body);
        if ($extracted->references !== '') {
            $longest = preg_match_all('/`+/', $extracted->references, $runs) > 0 ? max(array_map('strlen', $runs[0])) : 0;
            $fence = str_repeat('`', max(3, $longest + 1));
            $body .= ($body !== '' ? "\n\n" : '') . "## References\n\n" . $fence . "text\n" . rtrim($extracted->references) . "\n" . $fence;
        }

        return $body !== '' ? $body . "\n" : '';
    }

    /**
     * @param array<string, mixed> $csl
     *
     * @throws LibraryException INVALID for a key typed that is not one, DUPLICATE for one taken
     */
    private function keyFor(mixed $typed, array $csl): string
    {
        if (\is_string($typed) && trim($typed) !== '') {
            $key = trim($typed);
            if (!CitationKey::isValid($key)) {
                throw new LibraryException(LibraryException::INVALID);
            }
            if ($this->keyTaken($key)) {
                throw new LibraryException(LibraryException::DUPLICATE);
            }

            return $key;
        }

        return CitationKey::make($csl, fn (string $k): bool => $this->keyTaken($k));
    }

    private function keyTaken(string $key): bool
    {
        return $this->storage->isPathTaken($this->namespace . ':' . $key);
    }

    /** @return list<string> */
    private static function cleanTags(mixed $tags): array
    {
        $clean = [];
        foreach (\is_array($tags) ? $tags : [] as $tag) {
            $tag = \is_string($tag) ? trim(mb_strtolower(ltrim(trim($tag), '#'))) : '';
            if ($tag !== '' && mb_strlen($tag) <= 60 && !str_contains($tag, ',') && !\in_array($tag, $clean, true)) {
                $clean[] = $tag;
            }
        }

        return \array_slice($clean, 0, self::MAX_TAGS);
    }

    private static function line(mixed $value): string
    {
        if (!\is_scalar($value)) {
            return '';
        }

        return trim(mb_substr((string) preg_replace('/\s+/u', ' ', (string) $value), 0, 500));
    }

    /** A file name without directories or control characters, for the record */
    private static function fileName(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));

        return mb_substr((string) preg_replace('/[\x00-\x1f\x7f]/', '', $base), 0, 200);
    }

    private static function preview(string $body): string
    {
        $text = trim((string) preg_replace([SourceText::MARKER, '/^#+\s*/m', '/\\\\([\[\]#>*+\-.)])/', '/\s+/u'], ['', '', '$1', ' '], $body));

        return mb_strlen($text) > self::PREVIEW_CHARS ? mb_substr($text, 0, self::PREVIEW_CHARS) . '…' : $text;
    }
}
