<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Service\Library;

use Closure;
use DOMDocument;
use DOMXPath;
use Reporion\Support\ArticleIds;
use Reporion\Support\SourceNotice;

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
    private const UNPAYWALL = 'https://api.unpaywall.org/v2/';

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
            if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !\in_array($host, ['api.crossref.org', 'eutils.ncbi.nlm.nih.gov', 'api.unpaywall.org'], true)) {
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
     * @return array{csl: array<string, mixed>, tags: list<string>, notices: list<array<string, string>>}|null null: no such DOI;
     *               notices: what CrossRef's `updated-by` says became of it (Support\SourceNotice)
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

        return ['csl' => self::filled($csl), 'tags' => [], 'notices' => SourceNotice::fromCrossref($message['updated-by'] ?? null)];
    }

    /**
     * @return array{csl: array<string, mixed>, tags: list<string>, notices: list<array<string, string>>}|null null: no such PMID;
     *               notices: PubMed's retraction, concern and erratum marks (Support\SourceNotice)
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
        foreach (['container-title' => '//PubmedArticle[1]//Journal/Title', 'container-title-short' => '//PubmedArticle[1]//MedlineJournalInfo/MedlineTA', 'volume' => '//PubmedArticle[1]//JournalIssue/Volume', 'issue' => '//PubmedArticle[1]//JournalIssue/Issue', 'page' => '//PubmedArticle[1]//Pagination/MedlinePgn', 'ISSN' => '//PubmedArticle[1]//Journal/ISSN'] as $field => $path) {
            if ($text($path) !== '') {
                $csl[$field] = $text($path);
            }
        }
        // The NLM title abbreviation (MedlineTA: `N Engl J Med`) is what Vancouver and AMA print; the ISO one has periods
        if (!isset($csl['container-title-short']) && $text('//PubmedArticle[1]//Journal/ISOAbbreviation') !== '') {
            $csl['container-title-short'] = $text('//PubmedArticle[1]//Journal/ISOAbbreviation');
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

        $publicationTypes = [];
        foreach ($x->query('//PubmedArticle[1]//PublicationTypeList/PublicationType') ?: [] as $type) {
            $publicationTypes[] = trim($type->textContent);
        }
        $refTypes = [];
        foreach ($x->query('//PubmedArticle[1]//CommentsCorrectionsList/CommentsCorrections') ?: [] as $ref) {
            $refTypes[] = $ref instanceof \DOMElement ? $ref->getAttribute('RefType') : '';
        }

        return ['csl' => self::filled($csl), 'tags' => $mesh, 'notices' => SourceNotice::fromPubmed($publicationTypes, $refTypes)];
    }

    /**
     * Only what the service said: an empty field would replace a good one read from the file
     *
     * @param array<string, mixed> $csl
     *
     * @return array<string, mixed>
     */
    private static function filled(array $csl): array
    {
        return array_filter($csl, static fn (mixed $v): bool => $v !== '' && $v !== []);
    }

    /** The most records one search returns */
    public const SEARCH_LIMIT = 10;

    /**
     * PubMed searched by words (D60: Add by search; opt-in `library.search`, and unlike every other
     * lookup what is sent is what the writer typed): esearch, then esummary for the ids it gives. Each
     * answer is a proposed record for the writer to add (D8), as an upload's. The caller has refused a
     * query that holds a patient's CNP and says plainly in the screen that the words go to PubMed.
     *
     * @return list<array{csl: array<string, mixed>, tags: list<string>}> best match first
     *
     * @throws \RuntimeException when the service could not be reached or answered nonsense
     */
    public function search(string $query, int $limit = self::SEARCH_LIMIT): array
    {
        $query = trim((string) preg_replace('/\s+/u', ' ', $query));
        if ($query === '') {
            return [];
        }
        $found = ($this->fetch)(self::EUTILS . 'esearch.fcgi?db=pubmed&retmode=json&sort=relevance&retmax=' . max(1, min(self::SEARCH_LIMIT, $limit)) . '&term=' . rawurlencode($query));
        $json = $found === null ? null : json_decode($found, true);
        if (!\is_array($json)) {
            throw new \RuntimeException('pubmed');
        }
        $ids = array_values(array_filter(\is_array($json['esearchresult']['idlist'] ?? null) ? $json['esearchresult']['idlist'] : [], static fn (mixed $id): bool => \is_string($id) && preg_match('/^\d{1,9}$/', $id) === 1));
        if ($ids === []) {
            return [];
        }
        $summary = ($this->fetch)(self::EUTILS . 'esummary.fcgi?db=pubmed&retmode=json&id=' . implode(',', $ids));
        $result = $summary === null ? null : (json_decode($summary, true)['result'] ?? null);
        if (!\is_array($result)) {
            throw new \RuntimeException('pubmed');
        }
        $records = [];
        foreach ($ids as $id) {
            $row = $result[$id] ?? null;
            if (!\is_array($row) || trim(strip_tags((string) ($row['title'] ?? ''))) === '') {
                continue;
            }
            $csl = ['type' => 'article-journal', 'title' => trim(strip_tags((string) $row['title'])), 'PMID' => $id];
            $authors = [];
            foreach (\is_array($row['authors'] ?? null) ? $row['authors'] : [] as $author) {
                $name = \is_array($author) ? trim((string) ($author['name'] ?? '')) : '';
                if ($name === '') {
                    continue;
                }
                // "Nobody A": the family name, then the initials
                $authors[] = ($author['authtype'] ?? 'Author') === 'Author' && preg_match('/^(.+) ([A-Z]{1,4})$/u', $name, $m) === 1 ? ['family' => $m[1], 'given' => $m[2]] : ['literal' => $name];
            }
            if ($authors !== []) {
                $csl['author'] = $authors;
            }
            foreach (['container-title' => 'fulljournalname', 'container-title-short' => 'source', 'volume' => 'volume', 'issue' => 'issue', 'page' => 'pages', 'ISSN' => 'issn'] as $to => $from) {
                $value = trim((string) ($row[$from] ?? ''));
                if ($value !== '') {
                    $csl[$to] = $value;
                }
            }
            if (preg_match('/\b(1[5-9]\d\d|20\d\d)\b/', (string) ($row['pubdate'] ?? ''), $m) === 1) {
                $csl['issued'] = ['date-parts' => [[(int) $m[1]]]];
            }
            foreach (\is_array($row['articleids'] ?? null) ? $row['articleids'] : [] as $articleId) {
                if (\is_array($articleId) && ($articleId['idtype'] ?? '') === 'doi' && ($doi = ArticleIds::normalizeDoi((string) ($articleId['value'] ?? ''))) !== null) {
                    $csl['DOI'] = $doi;
                }
                if (\is_array($articleId) && ($articleId['idtype'] ?? '') === 'pmc' && preg_match('/^PMC\d+$/', (string) ($articleId['value'] ?? '')) === 1) {
                    $csl['PMCID'] = (string) $articleId['value'];
                }
            }
            $records[] = ['csl' => $csl, 'tags' => []];
        }

        return $records;
    }

    /**
     * Where Unpaywall says a free copy of the article is (D60: Find full text): the DOI and the
     * owner's e-mail address (which Unpaywall asks every caller to give) are all that is sent. The
     * best open-access location with a PDF, else any with one.
     *
     * @return array{url: string, version: string, license: string, host_type: string}|null null: no such DOI, or no free PDF
     *
     * @throws \RuntimeException when the service could not be reached or answered nonsense
     */
    public function unpaywall(string $doi, string $email): ?array
    {
        $doi = ArticleIds::normalizeDoi($doi);
        if ($doi === null) {
            return null;
        }
        $body = ($this->fetch)(self::UNPAYWALL . rawurlencode($doi) . '?email=' . rawurlencode($email));
        if ($body === null) {
            throw new \RuntimeException('unpaywall');
        }
        if ($body === '') {
            return null;
        }
        $json = json_decode($body, true);
        if (!\is_array($json)) {
            throw new \RuntimeException('unpaywall');
        }
        $locations = array_merge(\is_array($json['best_oa_location'] ?? null) ? [$json['best_oa_location']] : [], \is_array($json['oa_locations'] ?? null) ? $json['oa_locations'] : []);
        foreach ($locations as $location) {
            if (\is_array($location) && \is_string($location['url_for_pdf'] ?? null) && preg_match('~^https?://~i', $location['url_for_pdf']) === 1) {
                return [
                    'url' => $location['url_for_pdf'],
                    'version' => \is_string($location['version'] ?? null) ? $location['version'] : '',
                    'license' => \is_string($location['license'] ?? null) ? $location['license'] : '',
                    'host_type' => \is_string($location['host_type'] ?? null) ? $location['host_type'] : '',
                ];
            }
        }

        return null;
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
