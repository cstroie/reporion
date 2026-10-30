<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Library;

use InvalidArgumentException;
use Reporion\Auth\User;
use Reporion\Exception\LibraryException;
use Reporion\Exception\PageExistsException;
use Reporion\Index\IndexInterface;
use Reporion\Service\TempUploads;
use Reporion\Storage\FileTree;
use Reporion\Storage\PageRecord;
use Reporion\Support\ArticleIds;
use Reporion\Support\CitationKey;
use Reporion\Support\Cnp;
use Reporion\Support\Slug;
use Reporion\Support\SourceText;

/**
 * The library (docs/library.md): one page per article, `{namespace}:{key}`, its body the
 * article's cleaned text, its frontmatter the record (`csl`, CSL-JSON) and the original
 * (`original`, the file in data/sources/).
 *
 * Two steps, as Mendeley's: upload() reads the file and proposes a record (nothing stored but a
 * temporary upload), confirm() makes the page from the record the writer saw and corrected (D8).
 * add() does both at once, for the CLI. Never patient data: a text holding a valid CNP is refused.
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
        [$ext, $extracted, $proposal] = $this->proposal($bytes, $name, $principal);
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
        return $this->proposal($bytes, $name, $principal)[2];
    }

    /**
     * @return array{0: string, 1: Extracted, 2: array<string, mixed>}
     *
     * @throws LibraryException
     */
    private function proposal(string $bytes, string $name, User $principal): array
    {
        [$ext, $extracted] = $this->read($bytes, $name);
        $identified = Identify::propose($extracted, $name);

        return [$ext, $extracted, [
            'name' => self::fileName($name),
            'ext' => $ext,
            'bytes' => \strlen($bytes),
            'pages' => $extracted->pages,
            'has_text' => $extracted->hasText,
            'csl' => $identified['csl'],
            'how' => $identified['how'],
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
        $key = $this->keyFor($fields['key'] ?? null, $csl);
        $tags = self::cleanTags($fields['tags'] ?? []);

        $json = $this->temp->take(self::BUCKET, $token);
        $upload = $json !== null ? json_decode($json, true) : null;
        $bytes = \is_array($upload) ? $this->temp->take(self::FILE_BUCKET, (string) ($upload['file'] ?? '')) : null;
        if (!\is_array($upload) || $bytes === null) {
            throw new LibraryException(LibraryException::EXPIRED);
        }

        return $this->create($bytes, (string) $upload['ext'], (string) $upload['name'], Extracted::fromArray((array) $upload['extracted']), $csl, $key, $tags, $principal, $actor);
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
        $csl = self::cleanCsl(array_merge(Identify::propose($extracted, $name)['csl'], \is_array($fields['csl'] ?? null) ? $fields['csl'] : []));
        $key = $this->keyFor($fields['key'] ?? null, $csl);
        $record = $this->create($bytes, $ext, self::fileName($name), $extracted, $csl, $key, self::cleanTags($fields['tags'] ?? []), $principal, $actor);

        return [$record, $csl];
    }

    /**
     * An article already in the library that $csl or the file's hash names: by the file, the DOI,
     * the PMID, else the folded title and year (the last only a hint, never a refusal)
     *
     * @param array<string, mixed> $csl
     *
     * @return array{pid: string, path: string, title: string, by: string, has_original: bool}|null
     */
    public function duplicateOf(array $csl, string $sha, User $principal): ?array
    {
        $doi = \is_string($csl['DOI'] ?? null) ? ArticleIds::normalizeDoi($csl['DOI']) : null;
        $pmid = \is_scalar($csl['PMID'] ?? null) ? (string) $csl['PMID'] : null;
        $title = \is_string($csl['title'] ?? null) ? Slug::fold($csl['title']) : '';
        $year = $csl['issued']['date-parts'][0][0] ?? null;
        $byTitle = null;
        foreach ($this->index->listNamespaceMeta($this->namespace, $principal) as $row) {
            $meta = $row['meta'];
            $theirs = \is_array($meta['csl'] ?? null) ? $meta['csl'] : [];
            $hasOriginal = \is_array($meta['original'] ?? null);
            $hit = static fn (string $by): array => ['pid' => $row['pid'], 'path' => $row['path'], 'title' => $row['title'], 'by' => $by, 'has_original' => $hasOriginal];
            if ($hasOriginal && ($meta['original']['sha256'] ?? null) === $sha) {
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

        return $clean;
    }

    /** Whether the text holds a valid CNP: a patient document, which the library never takes */
    public static function holdsPatientData(string $text): bool
    {
        if (preg_match_all('/(?<!\d)[1-9]\d{12}(?!\d)/', $text, $m) > 0) {
            foreach ($m[0] as $candidate) {
                if (Cnp::isValid($candidate)) {
                    return true;
                }
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
        if (self::holdsPatientData($extracted->body . "\n" . $extracted->references . "\n" . $extracted->head)) {
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
    private function create(string $bytes, string $ext, string $name, Extracted $extracted, array $csl, string $key, array $tags, User $principal, string $actor): PageRecord
    {
        $sha = hash('sha256', $bytes);
        $duplicate = $this->duplicateOf($csl, $sha, $principal);
        if ($duplicate !== null && $duplicate['by'] !== 'title') {
            throw new LibraryException(LibraryException::DUPLICATE, $duplicate['pid']);
        }

        $original = $this->storage->storeOriginal($bytes, $ext) + [
            'name' => $name,
            'pages' => $extracted->pages,
            'text' => $extracted->hasText,
        ];
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
