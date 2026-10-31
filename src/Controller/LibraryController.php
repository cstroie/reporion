<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Controller;

use Reporion\Audit\AuditLog;
use Reporion\Auth\User;
use Reporion\Exception\LibraryException;
use Reporion\Exception\PageNotFoundException;
use Reporion\Exception\RevisionConflictException;
use Reporion\Http\ApiResponse;
use Reporion\Http\ChromeVars;
use Reporion\Http\Request;
use Reporion\Http\Response;
use Reporion\Http\View;
use Reporion\Index\IndexInterface;
use Reporion\Service\Library\Extractor;
use Reporion\Service\Library\Highlights;
use Reporion\Service\Library\Analyses;
use Reporion\Service\Library\Library;
use Reporion\Support\Flashcards;
use Reporion\Service\Library\WebClip;
use Reporion\Http\Throttle;
use Reporion\Storage\FileTree;
use Reporion\Support\Bibliography;
use Reporion\Support\Citation;
use Reporion\Support\InternalLink;

/**
 * The library's endpoints (docs/library.md, phase 1):
 *
 * POST /api/v1/library/uploads?name={file name}: the file itself as the body (as media), read and
 * answered with the proposed record and a token; nothing kept but the temporary upload.
 * POST /api/v1/library {token, csl, key?, tags?}: the confirmed record, made a library page.
 * GET /library/file/{pid}: a source's original, to a signed-in caller who may see its page.
 * GET /library: the library's view (phase 2): every source the caller may see, filtered by a tag,
 * sorted by author, year or title, with the upload form for an editor.
 * PUT /api/v1/library/{pid}/record {csl, base_rev, tags?}: a source's record corrected.
 * POST /api/v1/library/{pid}/lookup: the record asked again of CrossRef and PubMed by its DOI or PMID and
 * overwritten with what they return (opt-in `library.lookup`, off: 404).
 *
 * An editor of the library's namespace adds; anyone else gets 404 (invariant 9).
 */
final class LibraryController
{
    private const TYPES = [
        'pdf' => 'application/pdf', 'txt' => 'text/plain; charset=utf-8', 'md' => 'text/markdown; charset=utf-8', 'markdown' => 'text/markdown; charset=utf-8',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'odt' => 'application/vnd.oasis.opendocument.text',
        'rtf' => 'application/rtf', 'epub' => 'application/epub+zip', 'doc' => 'application/msword', 'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'odp' => 'application/vnd.oasis.opendocument.presentation',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
    ];

    public function __construct(
        private readonly Library $library,
        private readonly FileTree $storage,
        private readonly IndexInterface $index,
        private readonly AuditLog $audit,
        private readonly ?Highlights $highlights = null,
        private readonly ?WebClip $clip = null,
        private readonly ?Throttle $throttle = null,
    ) {
    }

    /** Web clips a writer may make in an hour */
    public const CLIPS_PER_HOUR = 30;

    /** The exports of a reference manager the upload takes as records with no file */
    public const IMPORT_EXTENSIONS = ['bib', 'ris', 'json'];

    /** The orders the view offers; the first is the default */
    public const SORTS = ['author', 'year', 'title'];

    public function index(Request $request, ?User $principal): Response
    {
        if ($principal === null) {
            throw new PageNotFoundException();
        }
        $entries = $this->library->entries($principal);
        $counts = [];
        foreach ($entries as $entry) {
            foreach ($entry['tags'] as $tag) {
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }
        ksort($counts);
        $tag = (string) ($request->query['tag'] ?? '');
        if ($tag !== '') {
            $entries = array_values(array_filter($entries, static fn (array $e): bool => \in_array($tag, $e['tags'], true)));
        }
        $sort = \in_array($request->query['sort'] ?? null, self::SORTS, true) ? (string) $request->query['sort'] : self::SORTS[0];
        usort($entries, static fn (array $a, array $b): int => match ($sort) {
            'year' => [$b['year'] ?? 0, mb_strtolower($a['authors'])] <=> [$a['year'] ?? 0, mb_strtolower($b['authors'])],
            'title' => strcoll(mb_strtolower($a['title']), mb_strtolower($b['title'])),
            default => [mb_strtolower($a['authors'] !== '' ? $a['authors'] : $a['title']), $a['year'] ?? 0] <=> [mb_strtolower($b['authors'] !== '' ? $b['authors'] : $b['title']), $b['year'] ?? 0],
        });

        return Response::html(View::page(\dirname(__DIR__, 2) . '/templates/library.php', [
            'entries' => $entries,
            'tagCounts' => $counts,
            'tag' => $tag,
            'sort' => $sort,
            'canAdd' => $this->library->canAdd($principal),
            'namespace' => $this->library->namespace(),
            'maxMb' => intdiv($this->library->maxBytes(), 1024 * 1024),
            'extensions' => array_keys(Extractor::TYPES),
            'webClip' => $this->clip !== null,
            'basePath' => $request->basePath,
        ] + ChromeVars::shell($request, $principal, $this->index, $this->library->namespace()), t('library.title')));
    }

    public function record(Request $request, string $pid, ?User $principal): Response
    {
        $row = $principal !== null ? $this->index->findByPid($pid, $principal) : null;
        if ($principal === null || $row === null || !$principal->canWrite((string) $row['path'])) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }
        $fields = $request->json();
        if (!\is_array($fields['csl'] ?? null) || !\is_int($fields['base_rev'] ?? null)) {
            return ApiResponse::error(422, 'invalid_body', '"csl" (object) and "base_rev" (integer) are required.');
        }
        $tags = \is_array($fields['tags'] ?? null) ? array_values(array_filter($fields['tags'], 'is_string')) : null;
        try {
            $page = $this->library->updateRecord((string) $row['path'], $fields['csl'], $tags, $fields['base_rev'], $principal, $principal->username);
        } catch (LibraryException $e) {
            return $this->failure($e, $principal);
        } catch (RevisionConflictException) {
            return ApiResponse::error(409, 'conflict', t('library.err_conflict'));
        } catch (PageNotFoundException) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }
        $this->audit->record('page.save', $principal->username, $request, $page->pid, $page->path, $page->rev);

        return ApiResponse::json(['pid' => $page->pid, 'path' => $page->path, 'rev' => $page->rev]);
    }

    /**
     * POST /api/v1/library/{pid}/lookup: the source's DOI or PMID sent again to CrossRef and PubMed
     * (the identifier only), the record's fields overwritten with what returns (Library::relookup()),
     * one new revision by the caller when something changed. Lookups off, or a source the caller may
     * not write: 404.
     */
    public function lookup(Request $request, string $pid, ?User $principal): Response
    {
        $row = $principal !== null ? $this->index->findByPid($pid, $principal) : null;
        if ($principal === null || $row === null || !$principal->canWrite((string) $row['path']) || !$this->library->lookupOn()) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }
        try {
            $result = $this->library->relookup((string) $row['path'], $principal, $principal->username);
        } catch (LibraryException $e) {
            return $this->failure($e, $principal);
        } catch (RevisionConflictException) {
            return ApiResponse::error(409, 'conflict', t('library.err_conflict'));
        } catch (PageNotFoundException) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }
        if ($result['status'] === 'updated') {
            $this->audit->record('page.save', $principal->username, $request, $result['pid'], $result['path'], $result['rev'], extra: ['lookup' => (string) $result['how'], 'fields' => \count($result['changed'])]);
        }

        return ApiResponse::json($result);
    }

    /**
     * POST /api/v1/library/{pid}/highlights {comment, to}: a comment quoting the source appended to
     * the page `to` as `> quote [@key, p. N]` (Service\Library\Highlights, phase 7), one revision of
     * that page by the caller; a source, comment or page the caller may not reach 404
     */
    public function highlight(Request $request, string $pid, ?User $principal): Response
    {
        $fields = $request->json();
        if ($principal === null || $this->highlights === null || !\is_string($fields['comment'] ?? null) || !\is_string($fields['to'] ?? null)) {
            return ApiResponse::error($principal === null ? 404 : 422, $principal === null ? 'not_found' : 'invalid_body', $principal === null ? 'Not found.' : '"comment" and "to" (strings) are required.');
        }
        try {
            $sent = $this->highlights->send($pid, $fields['comment'], $fields['to'], $principal);
        } catch (PageNotFoundException) {
            return ApiResponse::error(404, 'not_found', t('library.highlight_not_found'));
        } catch (LibraryException) {
            return ApiResponse::error(422, 'refused', t('library.highlight_refused'));
        } catch (RevisionConflictException) {
            return ApiResponse::error(409, 'conflict', t('library.err_conflict'));
        }
        $row = $this->index->findByPath($sent['path'], $principal);
        $this->audit->record('page.save', $principal->username, $request, (string) ($row['pid'] ?? ''), $sent['path'], $sent['rev'], extra: ['highlight_from' => $pid]);

        return ApiResponse::json(['path' => $sent['path'], 'rev' => $sent['rev'], 'page' => $sent['page'], 'href' => $request->basePath . '/' . $sent['path']]);
    }

    /**
     * GET /library/audio/{pid}: a kept audio overview's file (`audio` on the kept `studio-audio`
     * page, docs/library.md phase 8), under that page's visibility (anything else 404), with
     * `Range` so the player can seek: one byte range a request, 206 with `Content-Range`
     */
    public function audio(Request $request, string $pid, ?User $principal): Response
    {
        $notFound = new Response(404, 'Not found', ['Content-Type' => 'text/plain; charset=utf-8']);
        $row = $principal !== null ? $this->index->findByPid($pid, $principal) : null;
        if ($row === null) {
            return $notFound;
        }
        try {
            $page = $this->storage->read((string) $row['path']);
        } catch (PageNotFoundException) {
            return $notFound;
        }
        $audio = $page->frontmatter['audio'] ?? null;
        $ext = \is_array($audio) && \in_array($audio['ext'] ?? null, ['mp3', 'wav'], true) ? (string) $audio['ext'] : null;
        $file = $ext !== null && Analyses::isAnalysis($page->frontmatter) ? $this->storage->originalFile((string) ($audio['sha256'] ?? ''), $ext) : null;
        $size = $file !== null ? (int) filesize($file) : 0;
        if ($file === null || $size === 0) {
            return $notFound;
        }
        $headers = [
            'Content-Type' => $ext === 'mp3' ? 'audio/mpeg' : 'audio/wav',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ];
        $range = $request->range;
        if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) === 1 && ($m[1] !== '' || $m[2] !== '')) {
            $start = $m[1] !== '' ? (int) $m[1] : max(0, $size - (int) $m[2]);
            $end = $m[1] !== '' && $m[2] !== '' ? min((int) $m[2], $size - 1) : $size - 1;
            if ($start > $end || $start >= $size) {
                return new Response(416, '', ['Content-Range' => 'bytes */' . $size] + $headers);
            }

            return new Response(206, (string) file_get_contents($file, false, null, $start, $end - $start + 1), ['Content-Range' => 'bytes ' . $start . '-' . $end . '/' . $size] + $headers);
        }

        return new Response(200, (string) file_get_contents($file), $headers);
    }

    /**
     * GET /library/flashcards/{pid}: a kept flashcards page (the `source-flashcards` or
     * `studio-flashcards` analysis, docs/library.md phase 8) as the file Anki imports
     * (Support\Flashcards::anki()): a citation printed as `(Author year, p. N)` from the library's
     * record; under the page's visibility (anything else, or no card in it, 404); audited `export`.
     */
    public function flashcards(Request $request, string $pid, ?User $principal): Response
    {
        $notFound = new Response(404, 'Not found', ['Content-Type' => 'text/plain; charset=utf-8']);
        $row = $principal !== null ? $this->index->findByPid($pid, $principal) : null;
        if ($principal === null || $row === null) {
            return $notFound;
        }
        try {
            $page = $this->storage->read((string) $row['path']);
        } catch (PageNotFoundException) {
            return $notFound;
        }
        $prompt = $page->frontmatter[Analyses::KEY]['prompt'] ?? null;
        $cards = \in_array($prompt, ['source-flashcards', 'studio-flashcards'], true) ? Flashcards::parse($page->body) : [];
        if ($cards === []) {
            return $notFound;
        }
        $records = [];
        $cite = function (string $key, ?string $locator) use (&$records): string {
            $records[$key] ??= $this->library->citedRecords([$key])[0]['csl'] ?? null;

            return ($records[$key] !== null ? Citation::apaLabel($records[$key]) : $key) . ($locator !== null ? ', ' . $locator : '');
        };
        // The page it was made of names the deck: `iwata2019`, `white-matter`
        $segments = explode(':', $page->path);
        $of = $segments[\count($segments) - 2] ?? 'cards';
        $this->audit->record('export', $principal->username, $request, $page->pid, $page->path, $page->rev, extra: ['format' => 'anki', 'cards' => \count($cards)]);

        return new Response(200, Flashcards::anki($cards, ['reporion', $of], $cite), [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $of . '-flashcards.txt"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * POST /api/v1/library/clip {url}: a web page, PDF or text file fetched by its address
     * (Service\Library\WebClip, phase 7, opt-in `library.web_clip`) and read as an upload: the same
     * proposal, with the address and the day as `URL` and `accessed`; nothing is kept until the
     * writer confirms it. Off: 404, as for anyone who may not add.
     */
    public function clip(Request $request, ?User $principal): Response
    {
        if ($principal === null || $this->clip === null || !$this->library->canAdd($principal)) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }
        $url = $request->json()['url'] ?? null;
        if (!\is_string($url) || WebClip::parse(trim($url)) === null) {
            return ApiResponse::error(422, 'invalid', t('library.clip_bad_url'));
        }
        if ($this->throttle !== null && $this->throttle->hit('clip:' . $principal->username, 3600) > self::CLIPS_PER_HOUR) {
            return ApiResponse::error(429, 'throttled', t('library.clip_throttled', [(string) self::CLIPS_PER_HOUR]));
        }
        @set_time_limit(WebClip::TIMEOUT * (WebClip::MAX_REDIRECTS + 1) + 60);
        try {
            $fetched = $this->clip->fetch(trim($url));
            $proposal = $this->library->upload($fetched['bytes'], 'clip.' . $fetched['ext'], $principal);
        } catch (LibraryException $e) {
            $clipOwn = [LibraryException::INVALID => [422, 'clip_refused'], LibraryException::FAILED => [502, 'clip_failed'], LibraryException::UNSUPPORTED => [415, 'clip_unsupported'], LibraryException::TIMEOUT => [504, 'clip_failed']];
            // Before anything was fetched or read, the clip's own words; after, the upload's
            if (!isset($fetched) && isset($clipOwn[$e->reason])) {
                return ApiResponse::error($clipOwn[$e->reason][0], $e->reason, t('library.' . $clipOwn[$e->reason][1]));
            }

            return $this->failure($e, $principal);
        }
        $proposal['csl']['URL'] = $fetched['url'];
        $proposal['csl']['accessed'] = ['date-parts' => [[(int) date('Y'), (int) date('n'), (int) date('j')]]];
        if ($fetched['ext'] === 'html' && !isset($proposal['csl']['DOI'])) {
            $proposal['csl']['type'] = 'webpage';
        }
        // The host only: an address's path or query may say anything
        $this->audit->record('library.clip', $principal->username, $request, null, null, null, extra: ['host' => $fetched['host'], 'ext' => $fetched['ext'], 'bytes' => \strlen($fetched['bytes'])]);

        return ApiResponse::json($proposal, 201);
    }

    public function upload(Request $request, ?User $principal): Response
    {
        if ($principal === null || !$this->library->canAdd($principal)) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }
        try {
            $proposal = $this->library->upload($request->body, (string) ($request->query['name'] ?? ''), $principal);
        } catch (LibraryException $e) {
            return $this->failure($e);
        }

        return ApiResponse::json($proposal, 201);
    }

    public function confirm(Request $request, ?User $principal): Response
    {
        if ($principal === null || !$this->library->canAdd($principal)) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }
        $fields = $request->json();
        if (!\is_string($fields['token'] ?? null) || !\is_array($fields['csl'] ?? null)) {
            return ApiResponse::error(422, 'invalid_body', '"token" (string) and "csl" (object) are required.');
        }
        try {
            $page = $this->library->confirm($fields['token'], $fields, $principal, $principal->username);
        } catch (LibraryException $e) {
            return $this->failure($e, $principal);
        }
        $original = (array) $page->frontmatter['original'];
        $this->audit->record('library.add', $principal->username, $request, $page->pid, $page->path, $page->rev, extra: [
            'sha256' => (string) ($original['sha256'] ?? ''),
            'bytes' => (int) ($original['bytes'] ?? 0),
            'ext' => (string) ($original['ext'] ?? ''),
        ]);

        return ApiResponse::json([
            'pid' => $page->pid,
            'path' => $page->path,
            'rev' => $page->rev,
            'key' => substr($page->path, \strlen($this->library->namespace()) + 1),
            'href' => $request->basePath . '/' . $page->path,
        ], 201);
    }

    /**
     * POST /api/v1/library/import: the raw body of a BibTeX, RIS or CSL-JSON export (a reference
     * manager's) added as sources with no file; `?tags=a,b` for every record. Editors of the library.
     */
    public function import(Request $request, ?User $principal): Response
    {
        if ($principal === null || !$this->library->canAdd($principal)) {
            return ApiResponse::error(404, 'not_found', 'Not found.');
        }
        try {
            if (\strlen($request->body) > $this->library->maxBytes()) {
                throw new LibraryException(LibraryException::TOO_LARGE);
            }
            $extra = array_values(array_filter(array_map('trim', explode(',', (string) ($request->query['tags'] ?? '')))));
            $result = $this->library->importRecords($request->body, $principal, $principal->username, $extra);
        } catch (LibraryException $e) {
            return $this->failure($e, $principal);
        }
        $this->audit->record('library.import', $principal->username, $request, null, null, null, extra: ['added' => \count($result['added']), 'skipped' => \count($result['skipped'])]);
        foreach ($result['added'] as &$added) {
            $added['href'] = $request->basePath . '/' . $added['path'];
        }

        return ApiResponse::json($result, $result['added'] !== [] ? 201 : 200);
    }

    public function original(Request $request, string $pid, ?User $principal): Response
    {
        $notFound = new Response(404, 'Not found', ['Content-Type' => 'text/plain; charset=utf-8']);
        $row = $principal !== null ? $this->index->findByPid($pid, $principal) : null;
        if ($row === null) {
            return $notFound;
        }
        try {
            $page = $this->storage->read((string) $row['path']);
        } catch (PageNotFoundException) {
            return $notFound;
        }
        $original = $page->frontmatter['original'] ?? null;
        $file = \is_array($original) ? $this->storage->originalFile((string) ($original['sha256'] ?? ''), (string) ($original['ext'] ?? '')) : null;
        $bytes = $file !== null ? file_get_contents($file) : false;
        if ($bytes === false) {
            return $notFound;
        }
        $ext = (string) $original['ext'];
        $segments = explode(':', $page->path);
        $name = end($segments) . '.' . $ext;

        return new Response(200, $bytes, [
            // A PDF opens in the browser; anything else is downloaded, never rendered from this origin
            'Content-Type' => $ext === 'pdf' ? self::TYPES['pdf'] : (self::TYPES[$ext] ?? 'application/octet-stream'),
            'Content-Disposition' => ($ext === 'pdf' ? 'inline' : 'attachment') . '; filename="' . $name . '"',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ] + ($ext === 'pdf' ? [] : [
            // A clipped web page's scripts never run as this site, even if a browser rendered it (a sandbox would stop a PDF viewer)
            'Content-Security-Policy' => 'sandbox; default-src \'none\'',
        ]));
    }

    private const EXPORT_TYPES = [
        'bibtex' => ['application/x-bibtex; charset=utf-8', 'bib'],
        'ris' => ['application/x-research-info-systems; charset=utf-8', 'ris'],
        'csl' => ['application/json; charset=utf-8', 'json'],
        'text' => ['text/plain; charset=utf-8', 'txt'],
    ];

    /**
     * GET /library/export?format=bibtex|ris|csl|text[&style=][&key=a,b][&tag=][&page=path]: the
     * library the caller may read (or the keys/tag asked for), or the sources one page cites (the
     * page read under the caller; its sources' records as its References section prints them)
     */
    public function export(Request $request, ?User $principal): Response
    {
        $notFound = new Response(404, 'Not found', ['Content-Type' => 'text/plain; charset=utf-8']);
        if ($principal === null) {
            return $notFound;
        }
        $format = (string) ($request->query['format'] ?? 'bibtex');
        if (!isset(self::EXPORT_TYPES[$format])) {
            return new Response(422, 'Unknown format', ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $pagePath = (string) ($request->query['page'] ?? '');
        if ($pagePath !== '') {
            $row = $this->index->findByPath($pagePath, $principal);
            if ($row === null) {
                return $notFound;
            }
            try {
                $page = $this->storage->read((string) $row['path']);
            } catch (PageNotFoundException) {
                return $notFound;
            }
            $keys = InternalLink::citedKeys($page->body);
            foreach (\is_array($page->frontmatter['sources'] ?? null) ? $page->frontmatter['sources'] : [] as $key) {
                if (\is_string($key) && !\in_array($key, $keys, true)) {
                    $keys[] = $key;
                }
            }
            $records = $this->library->citedRecords($keys);
        } else {
            $keys = (string) ($request->query['key'] ?? '') !== '' ? array_values(array_filter(explode(',', (string) $request->query['key']))) : null;
            $tag = (string) ($request->query['tag'] ?? '');
            $records = $this->library->records($principal, $keys, $tag !== '' ? $tag : null);
        }
        $this->audit->record('library.export', $principal->username, $request, null, null, null, extra: ['format' => $format, 'count' => \count($records), 'scope' => $pagePath !== '' ? 'page' : 'library']);
        [$type, $ext] = self::EXPORT_TYPES[$format];

        return new Response(200, Bibliography::write($format, $records, Citation::style($request->query['style'] ?? null)), [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="library.' . $ext . '"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function failure(LibraryException $e, ?User $principal = null): Response
    {
        $status = match ($e->reason) {
            LibraryException::TOO_LARGE => 413,
            LibraryException::UNSUPPORTED => 415,
            LibraryException::DUPLICATE => 409,
            LibraryException::EXPIRED => 410,
            LibraryException::TOOL_MISSING => 503,
            LibraryException::TIMEOUT => 504,
            LibraryException::SCAN_TOO_LONG => 413,
            LibraryException::NO_OCR => 503,
            default => 422,
        };
        $args = match ($e->reason) {
            LibraryException::TOO_LARGE => [(string) intdiv($this->library->maxBytes(), 1024 * 1024)],
            LibraryException::SCAN_TOO_LONG => [(string) \Reporion\Service\Library\Extractor::OCR_MAX_PAGES],
            default => [],
        };
        $fields = null;
        // The article already there, when the caller may see it
        if ($e->pid !== null && ($row = $this->index->findByPid($e->pid, $principal)) !== null) {
            $fields = ['pid' => $e->pid, 'path' => (string) $row['path']];
        }

        return ApiResponse::error($status, $e->reason, t('library.err_' . $e->reason, $args), $fields);
    }
}
