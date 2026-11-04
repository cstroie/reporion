<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service\Library;

use Reporion\Auth\User;
use Reporion\Exception\LibraryException;
use Reporion\Index\Sqlite;
use Reporion\Service\Library\Extractor;
use Reporion\Service\Library\Library;
use Reporion\Service\Library\Lookup;
use Reporion\Service\Library\WebClip;
use Reporion\Service\TempUploads;
use Reporion\Storage\FileTree;
use Reporion\Tests\Storage\StorageTestCase;

/** Find full text (D60): Unpaywall, the web clip's fetch, the upload's reading; nothing leaves the test */
final class FullTextTest extends StorageTestCase
{
    private FileTree $storage;
    private Sqlite $index;
    /** @var list<string> */
    private array $asked = [];
    /** @var list<string> */
    private array $fetched = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 3) . '/migrations');
        $this->storage = new FileTree($this->dataRoot, $this->index);
        $this->storage->create('library:made2020', ['title' => 'A made-up study', 'visibility' => 'private', 'csl' => ['title' => 'A made-up study', 'DOI' => '10.9999/made.1', 'issued' => ['date-parts' => [[2020]]]]], '', 'owner');
        $this->storage->create('library:nodoi2020', ['title' => 'No DOI here', 'visibility' => 'private', 'csl' => ['title' => 'No DOI here']], '', 'owner');
    }

    private static function owner(): User
    {
        return new User('owner', 'x', true, [], true, 'now', 'now');
    }

    /**
     * @param ?string $unpaywall the body Unpaywall answers ('' no such DOI, null unreachable)
     * @param string  $pdfText   what the PDF holds
     * @param string  $type      the content type the publisher answers with
     */
    private function library(?string $unpaywall, ?string $pdfText = null, string $type = 'application/pdf', string $email = 'library@example.org', string $body = "%PDF-1.4\nmade-up"): Library
    {
        $pdfText ??= "A made-up study\n\nDOI 10.9999/made.1\n\n" . str_repeat('The text of the article, long enough to be a text. ', 8);
        $lookup = new Lookup(function (string $url) use ($unpaywall): ?string {
            $this->asked[] = $url;

            return $unpaywall;
        });
        $clip = new WebClip(1024 * 1024, static fn (string $host): array => ['93.184.216.34'], function (string $url, string $address, string $host, int $max) use ($type, $body): array {
            $this->fetched[] = $url;

            return ['status' => 200, 'headers' => ['Content-Type: ' . $type], 'body' => $body];
        });
        $runner = static function (array $argv, int $timeout, array $env) use ($pdfText): array {
            $tool = basename($argv[0]);
            if ($tool === 'pdftotext') {
                file_put_contents((string) end($argv), $pdfText);

                return [0, ''];
            }

            return [0, $tool === 'pdfinfo' && !\in_array('-meta', $argv, true) ? "Pages: 1\n" : ''];
        };

        return new Library($this->storage, $this->index, new Extractor($this->dataRoot . '/tmp', runner: $runner), new TempUploads($this->dataRoot), 'library', 50 * 1024 * 1024, $lookup, null, $clip, $email);
    }

    private const FOUND = '{"is_oa":true,"best_oa_location":{"url_for_pdf":"https://repo.example.org/files/made.pdf","version":"publishedVersion","license":"cc-by","host_type":"repository"},"oa_locations":[]}';

    public function testTheFreePdfIsFetchedReadAndAttachedAndOnlyTheDoiAndAddressWereSent(): void
    {
        $result = $this->library(self::FOUND)->findFullText('library:made2020', self::owner(), 'owner');

        self::assertSame(['attached', 'repo.example.org'], [$result['status'], $result['host']]);
        $page = $this->storage->read('library:made2020');
        self::assertSame('pdf', $page->frontmatter['original']['ext']);
        self::assertSame('made2020.pdf', $page->frontmatter['original']['name']);
        self::assertStringContainsString('The text of the article', $page->body);
        self::assertSame('https://api.unpaywall.org/v2/10.9999%2Fmade.1?email=library%40example.org', $this->asked[0]);
        foreach ($this->asked as $url) {
            self::assertStringNotContainsString('made-up', strtolower($url), 'the identifier (and the address, to Unpaywall) only: never a title or text');
        }
        self::assertSame(['https://repo.example.org/files/made.pdf'], $this->fetched);
        self::assertFileExists($this->dataRoot . '/sources/' . substr($page->frontmatter['original']['sha256'], 0, 2) . '/' . $page->frontmatter['original']['sha256'] . '.pdf');

        self::assertSame('has_file', $this->library(self::FOUND)->findFullText('library:made2020', self::owner(), 'owner')['status']);
    }

    public function testNothingFreeNoDoiAnotherArticleAndNotAPdfKeepNothing(): void
    {
        self::assertSame('none', $this->library('')->findFullText('library:made2020', self::owner(), 'owner')['status'], 'Unpaywall does not know the DOI');
        self::assertSame('none', $this->library('{"is_oa":false,"best_oa_location":null,"oa_locations":[]}')->findFullText('library:made2020', self::owner(), 'owner')['status']);
        self::assertSame('failed', $this->library(null)->findFullText('library:made2020', self::owner(), 'owner')['status'], 'Unpaywall not reached');
        self::assertSame('no_doi', $this->library(self::FOUND)->findFullText('library:nodoi2020', self::owner(), 'owner')['status']);
        self::assertSame('not_pdf', $this->library(self::FOUND, type: 'text/html', body: '<html>paywall</html>')->findFullText('library:made2020', self::owner(), 'owner')['status']);
        self::assertSame('not_pdf', $this->library(self::FOUND, body: 'not a pdf at all')->findFullText('library:made2020', self::owner(), 'owner')['status'], 'the content type says PDF, the bytes do not');
        self::assertSame('mismatch', $this->library(self::FOUND, "Another article\n\nDOI 10.1111/other.2\n\n" . str_repeat("Some long enough text of the other article. ", 8))->findFullText('library:made2020', self::owner(), 'owner')['status']);
        self::assertArrayNotHasKey('original', $this->storage->read('library:made2020')->frontmatter);
        self::assertSame([], glob($this->dataRoot . '/sources/*/*') ?: []);
    }

    public function testAPdfHoldingAPatientsCnpIsRefusedAndNothingIsKept(): void
    {
        $cnp = \Reporion\Tests\Support\CnpTest::make(1, '850723');

        try {
            $this->library(self::FOUND, "A made-up study\n\nDOI 10.9999/made.1\n\nPatient CNP " . $cnp . " seen for headaches over many weeks. " . str_repeat("Filler text of a made-up letter. ", 8))->findFullText('library:made2020', self::owner(), 'owner');
            self::fail('refused');
        } catch (LibraryException $e) {
            self::assertSame(LibraryException::PATIENT_DATA, $e->reason);
        }
        self::assertSame([], glob($this->dataRoot . '/sources/*/*') ?: []);
    }

    public function testItIsOffWithoutTheLookupsTheAddressOrTheFetch(): void
    {
        $off = new Library($this->storage, $this->index, new Extractor($this->dataRoot . '/tmp'), new TempUploads($this->dataRoot));
        self::assertFalse($off->fullTextOn());
        self::assertFalse($this->library(self::FOUND, email: '')->fullTextOn());
        self::assertTrue($this->library(self::FOUND)->fullTextOn());

        $this->expectExceptionObject(new LibraryException(LibraryException::LOOKUP_OFF));
        $off->findFullText('library:made2020', self::owner(), 'owner');
    }

    public function testUnpaywallsAnswerIsReadForItsPdfWhateverLocationHasOne(): void
    {
        $lookup = static fn (string $body): Lookup => new Lookup(static fn (string $url): ?string => $body);

        self::assertSame('https://x.example/a.pdf', $lookup('{"best_oa_location":{"url_for_pdf":null},"oa_locations":[{"url_for_pdf":"https://x.example/a.pdf","version":"acceptedVersion"}]}')->unpaywall('10.9999/x', 'a@example.org')['url']);
        self::assertNull($lookup('{"best_oa_location":{"url_for_pdf":"ftp://x/a.pdf"}}')->unpaywall('10.9999/x', 'a@example.org'), 'http(s) only');
        self::assertNull($lookup('{}')->unpaywall('not a doi', 'a@example.org'));
        $this->expectException(\RuntimeException::class);
        $lookup('<html>')->unpaywall('10.9999/x', 'a@example.org');
    }

    /**
     * The chain of free copies (2026-11-04): Lookup answers by the first key its URL holds, the web
     * clip by the whole URL (status, content type, body); pandoc writes $jatsText for an XML
     *
     * @param array<string, string>                   $lookups
     * @param array<string, array{int, string, string}> $files
     */
    private function chain(array $lookups, array $files, ?string $openAlexKey = null, string $jatsText = ''): Library
    {
        $lookup = new Lookup(function (string $url) use ($lookups): ?string {
            $this->asked[] = $url;
            foreach ($lookups as $needle => $body) {
                if (str_contains($url, $needle)) {
                    return $body;
                }
            }

            return '';
        });
        $clip = new WebClip(1024 * 1024, static fn (string $host): array => ['93.184.216.34'], function (string $url) use ($files): array {
            $this->fetched[] = $url;
            [$status, $type, $body] = $files[$url] ?? [404, 'text/html', ''];

            return ['status' => $status, 'headers' => ['Content-Type: ' . $type], 'body' => $body];
        });
        $pdfText = "A made-up study\n\nDOI 10.9999/made.1\n\n" . str_repeat('The text of the article, long enough to be a text. ', 8);
        $runner = static function (array $argv, int $timeout, array $env) use ($pdfText, $jatsText): array {
            $tool = basename($argv[0]);
            if ($tool === 'pdftotext') {
                file_put_contents((string) end($argv), $pdfText);

                return [0, ''];
            }
            if ($tool === 'pandoc') {
                file_put_contents($argv[(int) array_search('-o', $argv, true) + 1], $jatsText);

                return [0, ''];
            }

            return [0, $tool === 'pdfinfo' && !\in_array('-meta', $argv, true) ? "Pages: 1\n" : ''];
        };

        return new Library($this->storage, $this->index, new Extractor($this->dataRoot . '/tmp', runner: $runner), new TempUploads($this->dataRoot), 'library', 50 * 1024 * 1024, $lookup, null, $clip, 'library@example.org', $openAlexKey);
    }

    private const TWO_COPIES = '{"best_oa_location":{"url_for_pdf":"https://publisher.example/made.pdf"},"oa_locations":[{"url_for_pdf":"https://publisher.example/made.pdf"},{"url_for_pdf":"https://repo.example.org/made.pdf"}]}';

    private static function jats(string $doi): string
    {
        return '<?xml version="1.0"?><article><front><article-meta><article-id pub-id-type="pmcid">PMC123</article-id><article-id pub-id-type="doi">' . $doi . '</article-id></article-meta></front><body><p>Text.</p></body></article>';
    }

    public function testARefusingPublisherLeavesTheNextCopy(): void
    {
        $result = $this->chain(['unpaywall' => self::TWO_COPIES], [
            'https://publisher.example/made.pdf' => [403, 'text/html', 'Forbidden'],
            'https://repo.example.org/made.pdf' => [200, 'application/pdf', "%PDF-1.4\nmade-up"],
        ])->findFullText('library:made2020', self::owner(), 'owner');

        self::assertSame(['attached', 'repo.example.org'], [$result['status'], $result['host']]);
        self::assertSame(['https://publisher.example/made.pdf', 'https://repo.example.org/made.pdf'], $this->fetched);
        self::assertSame('pdf', $this->storage->read('library:made2020')->frontmatter['original']['ext']);
    }

    public function testEverythingRefusedSaysSoAndKeepsNothing(): void
    {
        $result = $this->chain(['unpaywall' => self::TWO_COPIES], [
            'https://publisher.example/made.pdf' => [403, 'text/html', 'Forbidden'],
        ])->findFullText('library:made2020', self::owner(), 'owner');

        self::assertSame('refused', $result['status']);
        self::assertArrayNotHasKey('original', $this->storage->read('library:made2020')->frontmatter);
        self::assertNotEmpty(array_filter($this->asked, static fn (string $u): bool => str_contains($u, 'www.ebi.ac.uk/europepmc/webservices/rest/search?query=DOI')), 'Europe PMC was asked by the DOI');
        self::assertEmpty(array_filter($this->asked, static fn (string $u): bool => str_contains($u, 'openalex')), 'no key: OpenAlex is not asked');
    }

    public function testOpenAlexsOwnCopyIsFetchedWithTheKey(): void
    {
        $result = $this->chain([
            'unpaywall' => self::TWO_COPIES,
            'api.openalex.org/works/doi:10.9999%2Fmade.1?api_key=k3y-made-up' => '{"has_content":{"pdf":true},"content_urls":{"pdf":"https://content.openalex.org/works/W1.pdf"}}',
        ], [
            'https://publisher.example/made.pdf' => [403, 'text/html', 'Forbidden'],
            'https://content.openalex.org/works/W1.pdf?api_key=k3y-made-up' => [200, 'application/pdf', "%PDF-1.4\nmade-up"],
        ], 'k3y-made-up')->findFullText('library:made2020', self::owner(), 'owner');

        self::assertSame(['attached', 'content.openalex.org'], [$result['status'], $result['host']]);
    }

    public function testEuropePmcsOpenTextIsTakenByThePmcidAndMustNameTheDoi(): void
    {
        $text = "# Introduction\n\n" . str_repeat('The open-access text of the made-up article, read from its XML. ', 8);
        $this->storage->save('library:made2020', ['title' => 'A made-up study', 'visibility' => 'private', 'csl' => ['title' => 'A made-up study', 'DOI' => '10.9999/made.1', 'PMCID' => 'PMC123', 'issued' => ['date-parts' => [[2020]]]]], '', 1, 'owner');

        $other = $this->chain(['unpaywall' => '', 'PMC123/fullTextXML' => self::jats('10.1111/other.2')], [], null, $text)->findFullText('library:made2020', self::owner(), 'owner');
        self::assertSame('mismatch', $other['status'], 'the XML names another article');

        $result = $this->chain(['unpaywall' => '', 'PMC123/fullTextXML' => self::jats('10.9999/made.1')], [], null, $text)->findFullText('library:made2020', self::owner(), 'owner');
        self::assertSame(['attached', 'www.ebi.ac.uk'], [$result['status'], $result['host']]);
        $page = $this->storage->read('library:made2020');
        self::assertSame('xml', $page->frontmatter['original']['ext']);
        self::assertStringContainsString('read from its XML', $page->body);
        self::assertEmpty(array_filter($this->asked, static fn (string $u): bool => str_contains($u, 'search?query')), 'the record had its PMCID: no search');
    }
}
