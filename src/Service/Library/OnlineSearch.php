<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Library;

use Reporion\Auth\SystemActor;
use Reporion\Auth\User;
use Reporion\Exception\LibraryException;
use Reporion\Support\ArticleIds;
use Reporion\Support\CslJson;

/**
 * Add by search (docs/library.md, D60; EndNote's online search, Zotero's "add by identifier"): PubMed
 * asked for articles by the words a writer types, or CrossRef / PubMed for a DOI or PMID typed
 * whole; the records that come back are proposals the writer picks from and adds as sources with no
 * file (D8). Opt-in (`library.search`, Admin → Settings → Library), apart from the lookups by
 * identifier: **this is the one lookup that sends what was typed**, so the screen says so, a query
 * holding a patient's CNP (or any run of thirteen digits shaped like one) is refused before anything
 * is sent, and the audit keeps a count, never the words (invariant 8). Nothing is stored by searching.
 */
final class OnlineSearch
{
    /** The longest query taken */
    public const MAX_QUERY = 200;

    /** The most records one add takes */
    public const MAX_ADD = 10;

    public function __construct(private readonly Library $library, private readonly ?Lookup $lookup = null)
    {
    }

    /** Whether Admin → Settings → Library has the search on */
    public function available(): bool
    {
        return $this->lookup !== null;
    }

    /**
     * @return array{how: string, results: list<array{csl: array<string, mixed>, tags: list<string>, in_library: ?array{pid: string, path: string, by: string}}>}
     *               how: `search` (words), `doi`, `pmid` (an identifier typed whole)
     *
     * @throws LibraryException LOOKUP_OFF (search is off), INVALID (empty, too long), PATIENT_DATA (a CNP in it: nothing sent), FAILED (the service did not answer)
     */
    public function find(string $query, User $principal): array
    {
        if ($this->lookup === null) {
            throw new LibraryException(LibraryException::LOOKUP_OFF);
        }
        $query = trim((string) preg_replace('/\s+/u', ' ', $query));
        if ($query === '' || mb_strlen($query) > self::MAX_QUERY || preg_match('/[\x00-\x1F\x7F]/', $query) === 1) {
            throw new LibraryException(LibraryException::INVALID);
        }
        // What is typed goes to PubMed as it is: a patient's number in it is refused, the stricter way (a digit misread or spaced is a risk too)
        if (Library::holdsPatientData($query, true)) {
            throw new LibraryException(LibraryException::PATIENT_DATA);
        }
        try {
            if (preg_match('~^(?:doi:\s*|https?://(?:dx\.)?doi\.org/)?(10\.\d{4,9}/\S+)$~i', $query, $m) === 1 && ArticleIds::normalizeDoi($m[1]) !== null) {
                $how = 'doi';
                $found = $this->lookup->crossref($m[1]);
                $records = $found !== null ? [['csl' => $found['csl'], 'tags' => $found['tags']]] : [];
            } elseif (preg_match('/^(?:pmid:?\s*)?(\d{1,9})$/i', $query, $m) === 1) {
                $how = 'pmid';
                $found = $this->lookup->pubmed($m[1]);
                $records = $found !== null ? [['csl' => $found['csl'], 'tags' => $found['tags']]] : [];
            } else {
                $how = 'search';
                $records = $this->lookup->search($query);
            }
        } catch (\RuntimeException) {
            throw new LibraryException(LibraryException::FAILED);
        }
        $results = [];
        foreach ($records as $record) {
            $duplicate = $this->library->duplicateOf($record['csl'], '', $principal);
            $results[] = $record + ['in_library' => $duplicate !== null ? ['pid' => $duplicate['pid'], 'path' => $duplicate['path'], 'by' => $duplicate['by']] : null];
        }

        return ['how' => $how, 'results' => $results];
    }

    /**
     * The records the writer picked, added as sources with no file (Library::importRecords(): one
     * in the library already, with no title or holding a CNP is skipped), each with its abstract and
     * a check for a retraction when the lookups by identifier are on. Nothing the search gave is
     * trusted as it comes: it is cleaned as an import is.
     *
     * @param list<array<string, mixed>> $records CSL-JSON records
     * @param list<string>               $tags
     *
     * @return array{added: list<array{key: string, path: string, pid: string, title: string}>, skipped: list<array{title: string, reason: string}>}
     *
     * @throws LibraryException LOOKUP_OFF, INVALID (none, or more than MAX_ADD)
     */
    public function add(array $records, array $tags, User $principal, string $actor): array
    {
        if ($this->lookup === null) {
            throw new LibraryException(LibraryException::LOOKUP_OFF);
        }
        $records = array_values(array_filter($records, 'is_array'));
        if ($records === [] || \count($records) > self::MAX_ADD) {
            throw new LibraryException(LibraryException::INVALID);
        }
        $done = $this->library->importRecords(CslJson::write(array_map(static fn (array $csl): array => ['key' => '', 'csl' => $csl, 'tags' => []], $records)), $principal, $actor, $tags !== [] ? $tags : null);
        foreach ($done['added'] as $added) {
            try {
                $this->library->fillAbstract($added['path'], SystemActor::LIBRARIAN, $this->library->lookupOn(), false, $actor);
                if ($this->library->lookupOn()) {
                    $this->library->checkNotice($added['path'], SystemActor::LIBRARIAN);
                }
            } catch (\Throwable) {
                // Added all the same: library:abstracts and library:notices find them later
            }
        }

        return $done;
    }
}
