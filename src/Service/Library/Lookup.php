<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Library;

use Closure;
use DOMDocument;
use DOMXPath;
use Reporion\Support\ArticleIds;

/**
 * The online lookups of docs/library.md (phase 4, opt-in: Admin → Settings → Library): a DOI to
 * CrossRef, a PMID to PubMed (efetch), a DOI to the PMID it has in PubMed (esearch). Only the
 * identifier leaves the box, never page text, a title or a file name (invariant 8): the records
 * that come back are proposals for the writer to correct (D8). The fetch is a closure, so tests send
 * nothing; it answers the body, '' for "no such record" (HTTP 404) and null for any other failure.
 */
final class Lookup
{
    private const CROSSREF = 'https://api.crossref.org/works/';
    private const EUTILS = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/';

    private const TYPES = [
        'journal-article' => 'article-journal', 'book' => 'book', 'monograph' => 'book', 'edited-book' => 'book', 'reference-book' => 'book',
        'book-chapter' => 'chapter', 'book-section' => 'chapter', 'proceedings-article' => 'paper-conference', 'report' => 'report',
        'dissertation' => 'thesis', 'dataset' => 'dataset', 'standard' => 'standard', 'posted-content' => 'article', 'peer-review' => 'review',
    ];

    /** @param Closure(string): ?string $fetch */
    public function __construct(private readonly Closure $fetch)
    {
    }

    /**
     * The real fetch: HTTPS to the two services only, one attempt, a short timeout, a size limit, a
     * User-Agent that names the software and nobody's address
     *
     * @return Closure(string): ?string
     */
    public static function http(int $timeoutSeconds = 5): Closure
    {
        return static function (string $url) use ($timeoutSeconds): ?string {
            $host = parse_url($url, PHP_URL_HOST);
            if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !\in_array($host, ['api.crossref.org', 'eutils.ncbi.nlm.nih.gov'], true)) {
                return null;
            }
            $context = stream_context_create(['http' => [
                'method' => 'GET', 'timeout' => $timeoutSeconds, 'ignore_errors' => true, 'follow_location' => 0,
                'header' => "User-Agent: Reporion library lookup\r\nAccept: application/json, application/xml\r\n",
            ]]);
            $body = @file_get_contents($url, false, $context, 0, 2 * 1024 * 1024);
            $status = isset($http_response_header[0]) && preg_match('~^HTTP/\S+ (\d{3})~', $http_response_header[0], $m) === 1 ? (int) $m[1] : 0;
            if ($body === false || $status === 0) {
                return null;
            }

            return $status === 404 ? '' : ($status >= 200 && $status < 300 ? $body : null);
        };
    }

    /**
     * @return array{csl: array<string, mixed>, tags: list<string>}|null null: no such DOI
     *
     * @throws \RuntimeException when the service could not be reached or answered nonsense
     */
    public function crossref(string $doi): ?array
    {
        $doi = ArticleIds::normalizeDoi($doi);
        if ($doi === null) {
            return null;
        }
        $body = ($this->fetch)(self::CROSSREF . rawurlencode($doi));
        if ($body === null) {
            throw new \RuntimeException('crossref');
        }
        $message = $body === '' ? null : (json_decode($body, true)['message'] ?? null);
        if (!\is_array($message)) {
            if ($body !== '' && !\is_array(json_decode($body, true))) {
                throw new \RuntimeException('crossref');
            }

            return null;
        }
        $first = static fn (mixed $v): string => \is_array($v) ? (string) ($v[0] ?? '') : (\is_scalar($v) ? (string) $v : '');
        $csl = ['type' => self::TYPES[(string) ($message['type'] ?? '')] ?? 'article-journal', 'title' => trim(strip_tags($first($message['title'] ?? '')))];
        $authors = [];
        foreach (\is_array($message['author'] ?? null) ? $message['author'] : [] as $author) {
            if (\is_array($author)) {
                $authors[] = ($author['family'] ?? '') !== '' ? array_filter(['family' => (string) $author['family'], 'given' => (string) ($author['given'] ?? '')], static fn (string $v): bool => $v !== '') : ['literal' => (string) ($author['name'] ?? '')];
            }
        }
        if ($authors !== []) {
            $csl['author'] = $authors;
        }
        foreach (['container-title' => 'container-title', 'short-container-title' => 'container-title-short', 'volume' => 'volume', 'issue' => 'issue', 'page' => 'page', 'publisher' => 'publisher', 'ISSN' => 'ISSN', 'ISBN' => 'ISBN', 'language' => 'language'] as $from => $to) {
            $value = trim(strip_tags($first($message[$from] ?? '')));
            if ($value !== '') {
                $csl[$to] = $value;
            }
        }
        $parts = $message['issued']['date-parts'][0] ?? $message['published']['date-parts'][0] ?? $message['published-print']['date-parts'][0] ?? null;
        if (\is_array($parts) && isset($parts[0]) && is_numeric($parts[0])) {
            $csl['issued'] = ['date-parts' => [array_map('intval', array_slice(array_values($parts), 0, 3))]];
        }
        $csl['DOI'] = $doi;
        if (\is_string($message['abstract'] ?? null)) {
            $abstract = trim(html_entity_decode(strip_tags((string) preg_replace('~</jats:p>\s*~', "\n\n", $message['abstract'])), ENT_QUOTES | ENT_HTML5));
            if ($abstract !== '') {
                $csl['abstract'] = $abstract;
            }
        }

        return ['csl' => $csl, 'tags' => []];
    }

    /**
     * @return array{csl: array<string, mixed>, tags: list<string>}|null null: no such PMID
     *
     * @throws \RuntimeException when the service could not be reached or answered nonsense
     */
    public function pubmed(string $pmid): ?array
    {
        if (preg_match('/^\d{1,9}$/', $pmid) !== 1) {
            return null;
        }
        $xml = ($this->fetch)(self::EUTILS . 'efetch.fcgi?db=pubmed&retmode=xml&id=' . $pmid);
        if ($xml === null || stripos($xml, '<!ENTITY') !== false) {
            throw new \RuntimeException('pubmed');
        }
        if ($xml === '') {
            return null;
        }
        $doc = new DOMDocument();
        // No network and no entity substitution (an XML bomb or an external entity is nothing here)
        if (!@$doc->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOWARNING | LIBXML_NOERROR)) {
            throw new \RuntimeException('pubmed');
        }
        $x = new DOMXPath($doc);
        $text = static fn (string $path, ?\DOMNode $in = null): string => trim((string) $x->evaluate('string(' . $path . ')', $in));
        if ($x->query('//PubmedArticle')->length === 0) {
            return null;
        }
        $csl = ['type' => 'article-journal', 'title' => trim(preg_replace('/\s+/u', ' ', $text('//PubmedArticle[1]//ArticleTitle')) ?? ''), 'PMID' => $pmid];
        $authors = [];
        foreach ($x->query('//PubmedArticle[1]//AuthorList/Author') ?: [] as $author) {
            $family = $text('LastName', $author);
            $authors[] = $family !== '' ? array_filter(['family' => $family, 'given' => $text('ForeName', $author) !== '' ? $text('ForeName', $author) : $text('Initials', $author)], static fn (string $v): bool => $v !== '') : ['literal' => $text('CollectiveName', $author)];
        }
        $authors = array_values(array_filter($authors, static fn (array $a): bool => ($a['literal'] ?? 'x') !== ''));
        if ($authors !== []) {
            $csl['author'] = $authors;
        }
        foreach (['container-title' => '//PubmedArticle[1]//Journal/Title', 'container-title-short' => '//PubmedArticle[1]//Journal/ISOAbbreviation', 'volume' => '//PubmedArticle[1]//JournalIssue/Volume', 'issue' => '//PubmedArticle[1]//JournalIssue/Issue', 'page' => '//PubmedArticle[1]//Pagination/MedlinePgn', 'ISSN' => '//PubmedArticle[1]//Journal/ISSN'] as $field => $path) {
            if ($text($path) !== '') {
                $csl[$field] = $text($path);
            }
        }
        $year = $text('//PubmedArticle[1]//JournalIssue/PubDate/Year');
        if ($year === '' && preg_match('/\b(1[5-9]\d\d|20\d\d)\b/', $text('//PubmedArticle[1]//JournalIssue/PubDate/MedlineDate'), $m) === 1) {
            $year = $m[1];
        }
        if (preg_match('/^\d{4}$/', $year) === 1) {
            $csl['issued'] = ['date-parts' => [[(int) $year]]];
        }
        $doi = $text('//PubmedArticle[1]//ArticleId[@IdType="doi"]');
        if ($doi !== '' && ($norm = ArticleIds::normalizeDoi($doi)) !== null) {
            $csl['DOI'] = $norm;
        }
        $pmc = $text('//PubmedArticle[1]//ArticleId[@IdType="pmc"]');
        if ($pmc !== '') {
            $csl['PMCID'] = $pmc;
        }
        $abstract = [];
        foreach ($x->query('//PubmedArticle[1]//Abstract/AbstractText') ?: [] as $part) {
            $label = $part instanceof \DOMElement ? trim($part->getAttribute('Label')) : '';
            $abstract[] = ($label !== '' ? $label . ': ' : '') . trim($part->textContent);
        }
        if ($abstract !== []) {
            $csl['abstract'] = implode("\n\n", $abstract);
        }
        $mesh = [];
        foreach ($x->query('//PubmedArticle[1]//MeshHeadingList/MeshHeading/DescriptorName') ?: [] as $descriptor) {
            $mesh[] = trim($descriptor->textContent);
        }

        return ['csl' => $csl, 'tags' => $mesh];
    }

    /**
     * The PMID PubMed has for a DOI, or null
     *
     * @throws \RuntimeException when the service could not be reached or answered nonsense
     */
    public function pmidOf(string $doi): ?string
    {
        $doi = ArticleIds::normalizeDoi($doi);
        if ($doi === null) {
            return null;
        }
        $body = ($this->fetch)(self::EUTILS . 'esearch.fcgi?db=pubmed&retmode=json&term=' . rawurlencode('"' . $doi . '"[AID]'));
        if ($body === null) {
            throw new \RuntimeException('pubmed');
        }
        $ids = $body === '' ? [] : (json_decode($body, true)['esearchresult']['idlist'] ?? []);

        return \is_array($ids) && \count($ids) === 1 && preg_match('/^\d{1,9}$/', (string) $ids[0]) === 1 ? (string) $ids[0] : null;
    }
}
