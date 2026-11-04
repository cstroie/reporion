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
use Reporion\Service\Library\OnlineSearch;
use Reporion\Service\TempUploads;
use Reporion\Storage\FileTree;
use Reporion\Tests\Storage\StorageTestCase;
use Reporion\Tests\Support\CnpTest;

/** Add by search (D60): the answers are made up; nothing leaves the test */
final class OnlineSearchTest extends StorageTestCase
{
    private FileTree $storage;
    private Sqlite $index;
    private Library $library;
    /** @var list<string> */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 3) . '/migrations');
        $this->storage = new FileTree($this->dataRoot, $this->index);
        $this->library = new Library($this->storage, $this->index, new Extractor($this->dataRoot . '/tmp'), new TempUploads($this->dataRoot));
    }

    private static function owner(): User
    {
        return new User('owner', 'x', true, [], true, 'now', 'now');
    }

    private function search(bool $on = true): OnlineSearch
    {
        $lookup = new Lookup(function (string $url): ?string {
            $this->asked[] = $url;

            return match (true) {
                str_contains($url, 'esearch') => '{"esearchresult":{"idlist":["12345678"]}}',
                str_contains($url, 'esummary') => '{"result":{"uids":["12345678"],"12345678":{"uid":"12345678","pubdate":"2024","source":"J Synth","authors":[{"name":"Nobody A","authtype":"Author"}],"title":"A made-up study of test lesions.","articleids":[{"idtype":"doi","value":"10.9999/jsi.1"}]}}}',
                str_contains($url, 'crossref') => '{"message":{"type":"journal-article","title":["A made-up study by DOI"],"issued":{"date-parts":[[2023]]}}}',
                str_contains($url, 'efetch') => '<PubmedArticleSet><PubmedArticle><MedlineCitation><PMID>5</PMID><Article><Journal><JournalIssue><PubDate><Year>2020</Year></PubDate></JournalIssue><Title>J Synth</Title></Journal><ArticleTitle>A made-up study by PMID</ArticleTitle></Article></MedlineCitation></PubmedArticle></PubmedArticleSet>',
                default => '',
            };
        });

        return new OnlineSearch($this->library, $on ? $lookup : null);
    }

    public function testWordsAreSearchedAndAnIdentifierTypedWholeIsLookedUp(): void
    {
        $words = $this->search()->find('hepatic steatosis MRI', self::owner());
        self::assertSame('search', $words['how']);
        self::assertSame('A made-up study of test lesions.', $words['results'][0]['csl']['title']);
        self::assertNull($words['results'][0]['in_library']);

        $doi = $this->search()->find('https://doi.org/10.9999/ABC.5', self::owner());
        self::assertSame(['doi', 'A made-up study by DOI'], [$doi['how'], $doi['results'][0]['csl']['title']]);
        self::assertStringContainsString('crossref.org/works/10.9999%2Fabc.5', end($this->asked));

        $pmid = $this->search()->find('PMID: 5', self::owner());
        self::assertSame(['pmid', 'A made-up study by PMID'], [$pmid['how'], $pmid['results'][0]['csl']['title']]);
    }

    public function testAPatientsNumberOrAnEmptyOrLongQueryIsRefusedBeforeAnythingIsSent(): void
    {
        $cnp = CnpTest::make(1, '850723');
        foreach ([
            'steatosis ' . $cnp => LibraryException::PATIENT_DATA,
            'MRI ' . substr($cnp, 0, 3) . 'O' . substr($cnp, 4) => LibraryException::PATIENT_DATA,
            substr($cnp, 0, 7) . ' ' . substr($cnp, 7) => LibraryException::PATIENT_DATA,
            '   ' => LibraryException::INVALID,
            str_repeat('a', 201) => LibraryException::INVALID,
            "tab\x01inside" => LibraryException::INVALID,
        ] as $query => $reason) {
            try {
                $this->search()->find((string) $query, self::owner());
                self::fail('refused: ' . $reason);
            } catch (LibraryException $e) {
                self::assertSame($reason, $e->reason, (string) $query);
            }
        }
        self::assertSame([], $this->asked, 'nothing was sent');
    }

    public function testItIsOffUntilTurnedOnAndAFailureIsSaid(): void
    {
        self::assertFalse($this->search(false)->available());
        try {
            $this->search(false)->find('words', self::owner());
            self::fail('off');
        } catch (LibraryException $e) {
            self::assertSame(LibraryException::LOOKUP_OFF, $e->reason);
        }
        $this->expectExceptionObject(new LibraryException(LibraryException::FAILED));
        (new OnlineSearch($this->library, new Lookup(static fn (string $url): ?string => null)))->find('words', self::owner());
    }

    public function testWhatIsInTheLibraryAlreadyIsMarkedAndAddingKeepsOnlyWhatIsNew(): void
    {
        $this->storage->create('library:have2024', ['title' => 'A made-up study of test lesions.', 'visibility' => 'private', 'csl' => ['title' => 'A made-up study of test lesions.', 'DOI' => '10.9999/jsi.1', 'issued' => ['date-parts' => [[2024]]]]], '', 'owner');

        $found = $this->search()->find('steatosis', self::owner());
        self::assertSame(['by' => 'doi', 'path' => 'library:have2024'], ['by' => $found['results'][0]['in_library']['by'], 'path' => $found['results'][0]['in_library']['path']]);

        $done = $this->search()->add([
            $found['results'][0]['csl'],
            ['type' => 'article-journal', 'title' => 'Another made-up study of lesions', 'PMID' => '99', 'issued' => ['date-parts' => [[2022]]]],
        ], ['pubmed'], self::owner(), 'owner');

        self::assertCount(1, $done['added']);
        self::assertSame([['title' => 'A made-up study of test lesions.', 'reason' => 'duplicate']], $done['skipped']);
        $page = $this->storage->read($done['added'][0]['path']);
        self::assertSame(['private', ['pubmed'], '99'], [$page->visibility, $page->frontmatter['tags'], $page->frontmatter['csl']['PMID']]);
        self::assertArrayNotHasKey('original', $page->frontmatter, 'a record with no file');
    }

    public function testAddTakesOneToTenRecordsOnly(): void
    {
        foreach ([[], array_fill(0, 11, ['title' => 'x y z'])] as $records) {
            try {
                $this->search()->add($records, [], self::owner(), 'owner');
                self::fail('refused');
            } catch (LibraryException $e) {
                self::assertSame(LibraryException::INVALID, $e->reason);
            }
        }
    }
}
