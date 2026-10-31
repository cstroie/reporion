<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service\Library;

use Reporion\Auth\Grant;
use Reporion\Auth\GrantRole;
use Reporion\Auth\User;
use Reporion\Exception\LibraryException;
use Reporion\Exception\SourceVisibilityException;
use Reporion\Index\Sqlite;
use Reporion\Service\Library\Extractor;
use Reporion\Service\Library\Library;
use Reporion\Service\TempUploads;
use Reporion\Storage\FileTree;
use Reporion\Tests\Storage\StorageTestCase;
use Reporion\Tests\Support\CnpTest;

/** The articles here are made up for the test (docs/library.md) */
final class LibraryTest extends StorageTestCase
{
    private const ARTICLE = "# A made-up study of test lesions\n\nAnn Nobody, Bob Nobody\n\nJ Synth Imaging 2024;3:11-14. doi:10.1234/jti.2024.0001\n\n"
        . "## Results\n\nGrade 3 was the most common.\n\n## References\n\n1. Nobody C. A paper that does not exist. 2020.\n";

    private FileTree $storage;
    private Sqlite $index;
    private Library $library;

    protected function setUp(): void
    {
        parent::setUp();
        $this->index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 3) . '/migrations');
        $this->storage = new FileTree($this->dataRoot, $this->index);
        $this->library = new Library($this->storage, $this->index, new Extractor($this->dataRoot . '/tmp'), new TempUploads($this->dataRoot));
    }

    public function testAScanHoldingAMisreadCnpIsRefusedAndNothingIsKept(): void
    {
        $cnp = \Reporion\Tests\Support\CnpTest::make(1, '850723');
        // OCR read its 0 as O: the checksum no longer holds, the stricter guard still refuses it
        $misread = substr($cnp, 0, 3) . 'O' . substr($cnp, 4);
        $runner = static function (array $argv, int $timeout, array $env) use ($misread): array {
            $tool = basename($argv[0]);
            if ($tool === 'pdftotext') {
                file_put_contents((string) end($argv), "\f");

                return [0, ''];
            }
            if ($tool === 'pdfinfo') {
                return [0, \in_array('-meta', $argv, true) ? '' : "Pages: 1\n"];
            }
            if ($tool === 'pdftoppm') {
                file_put_contents((string) end($argv) . '.png', 'PNG');

                return [0, ''];
            }

            return $tool === 'tesseract' ? [0, "A made-up referral letter.\nPatient CNP " . $misread . "\nSeen for headaches over many weeks."] : [1, ''];
        };
        $library = new Library($this->storage, $this->index, new Extractor($this->dataRoot . '/tmp', runner: $runner), new TempUploads($this->dataRoot));

        try {
            $library->upload('%PDF-1.4 scan', 'scan.pdf', self::owner());
            self::fail('a scan holding a CNP is refused');
        } catch (LibraryException $e) {
            self::assertSame(LibraryException::PATIENT_DATA, $e->reason);
        }
        self::assertSame([], array_merge(glob($this->dataRoot . '/tmp/library/*') ?: [], glob($this->dataRoot . '/tmp/library-file/*') ?: []), 'nothing kept');
        self::assertSame([], glob($this->dataRoot . '/sources/*/*') ?: []);
    }

    private static function owner(): User
    {
        return new User('owner', 'x', true, [], true, 'now', 'now');
    }

    public function testAnUploadIsProposedThenConfirmedIntoAPrivateLibraryPage(): void
    {
        $proposal = $this->library->upload(self::ARTICLE, 'study.md', self::owner());

        self::assertSame('A made-up study of test lesions', $proposal['csl']['title']);
        self::assertSame('10.1234/jti.2024.0001', $proposal['csl']['DOI']);
        self::assertSame('madeup2024', $proposal['key']);
        self::assertNull($proposal['duplicate']);
        self::assertStringContainsString('Grade 3 was the most common.', $proposal['preview']);
        self::assertSame([], glob($this->dataRoot . '/sources/*'), 'nothing kept before the confirm');

        $csl = $proposal['csl'];
        $csl['author'] = [['family' => 'Nobody', 'given' => 'Ann'], ['family' => 'Nobody', 'given' => 'Bob']];
        $csl['unknown-field'] = 'dropped';
        $page = $this->library->confirm($proposal['token'], ['csl' => $csl, 'tags' => ['#Grading', 'grading', 'lesions']], self::owner(), 'owner');

        self::assertSame('library:nobody2024', $page->path);
        self::assertSame('private', $page->frontmatter['visibility']);
        self::assertSame(['grading', 'lesions'], $page->frontmatter['tags']);
        self::assertArrayNotHasKey('unknown-field', $page->frontmatter['csl']);
        self::assertSame('Nobody', $page->frontmatter['csl']['author'][0]['family']);
        $original = $page->frontmatter['original'];
        self::assertSame(hash('sha256', self::ARTICLE), $original['sha256']);
        self::assertSame(['md', 'study.md', true], [$original['ext'], $original['name'], $original['text']]);
        self::assertSame(self::ARTICLE, file_get_contents((string) $this->storage->originalFile($original['sha256'], 'md')));
        self::assertStringContainsString("## References\n\n```text\n1. Nobody C. A paper that does not exist. 2020.\n```", $page->body);
        self::assertSame('10.1234/jti.2024.0001', $this->index->listNamespaceMeta('library', self::owner())[0]['meta']['csl']['DOI']);
    }

    public function testTheSameFileAgainIsADuplicateAndIsNotAdded(): void
    {
        $this->library->add(self::ARTICLE, 'study.md', self::owner(), 'owner');
        $proposal = $this->library->upload(self::ARTICLE, 'copy.md', self::owner());

        self::assertSame('file', $proposal['duplicate']['by']);
        self::assertSame('library:madeup2024', $proposal['duplicate']['path']);
        try {
            $this->library->confirm($proposal['token'], ['csl' => $proposal['csl']], self::owner(), 'owner');
            self::fail('no exception');
        } catch (LibraryException $e) {
            self::assertSame(LibraryException::DUPLICATE, $e->reason);
            self::assertSame($proposal['duplicate']['pid'], $e->pid);
        }
    }

    public function testAnotherFileOfTheSameDoiIsADuplicate(): void
    {
        $this->library->add(self::ARTICLE, 'study.md', self::owner(), 'owner');
        $proposal = $this->library->upload(self::ARTICLE . "\nAccepted manuscript.\n", 'accepted.md', self::owner());

        self::assertSame('doi', $proposal['duplicate']['by']);
    }

    public function testATakenKeyGetsTheNextLetterAndATypedKeyIsChecked(): void
    {
        [$first] = $this->library->add("# Grading of made-up lesions\n\n© 2024\n", 'a.md', self::owner(), 'owner');
        [$second] = $this->library->add("# Grading of other lesions\n\n© 2024\n", 'b.md', self::owner(), 'owner');

        self::assertSame(['library:grading2024', 'library:grading2024b'], [$first->path, $second->path]);
        foreach (['Not A Key', 'grading2024'] as $typed) {
            try {
                $this->library->add("# Third\n\nText.\n", 'c.md', self::owner(), 'owner', ['key' => $typed]);
                self::fail('no exception for ' . $typed);
            } catch (LibraryException $e) {
                self::assertContains($e->reason, [LibraryException::INVALID, LibraryException::DUPLICATE]);
            }
        }
    }

    public function testATextHoldingAValidCnpIsRefusedAndNothingIsKept(): void
    {
        $cnp = CnpTest::make(1, '800115');
        try {
            $this->library->upload("# Discharge letter\n\nPatient CNP {$cnp}\n", 'letter.txt', self::owner());
            self::fail('no exception');
        } catch (LibraryException $e) {
            self::assertSame(LibraryException::PATIENT_DATA, $e->reason);
        }
        self::assertSame([], glob($this->dataRoot . '/tmp/library*/*') ?: []);
        self::assertFalse(Library::holdsPatientData('Thirteen digits that are no CNP: 1234567890123'));
    }

    public function testAFileWithNoTextToCheckIsRefused(): void
    {
        foreach (["   \n\n", "\u{FEFF}"] as $empty) {
            try {
                $this->library->upload($empty, 'scan.txt', self::owner());
                self::fail('no exception');
            } catch (LibraryException $e) {
                self::assertSame(LibraryException::NO_TEXT, $e->reason);
            }
        }
    }

    public function testALibrarySourceIsAlwaysPrivateWhereverItIs(): void
    {
        [$page] = $this->library->add(self::ARTICLE, 'study.md', self::owner(), 'owner');
        foreach (['unlisted', 'public'] as $visibility) {
            $fm = $page->frontmatter;
            $fm['visibility'] = $visibility;
            foreach ([fn () => $this->storage->save($page->path, $fm, $page->body, 1, 'owner'), fn () => $this->storage->create('wiki:copy', $fm, $page->body, 'owner')] as $write) {
                try {
                    $write();
                    self::fail('no exception for ' . $visibility);
                } catch (SourceVisibilityException) {
                }
            }
        }
        self::assertSame('private', $this->storage->read($page->path)->visibility);
        self::assertSame(1, $this->storage->read($page->path)->rev);
        self::assertFalse($this->storage->isPathTaken('wiki:copy'));
    }

    public function testAnUnknownOrUsedTokenHasExpired(): void
    {
        $proposal = $this->library->upload(self::ARTICLE, 'study.md', self::owner());
        $this->library->confirm($proposal['token'], ['csl' => $proposal['csl']], self::owner(), 'owner');

        $this->expectExceptionObject(new LibraryException(LibraryException::EXPIRED));
        $this->library->confirm($proposal['token'], ['csl' => ['title' => 'Again']], self::owner(), 'owner');
    }

    public function testFilesOfAnUnknownTypeOrTooLargeAreRefused(): void
    {
        $small = new Library($this->storage, $this->index, new Extractor($this->dataRoot . '/tmp'), new TempUploads($this->dataRoot), maxBytes: 10);
        foreach ([['x.exe', 'MZ', LibraryException::UNSUPPORTED], ['x.md', str_repeat('a', 11), LibraryException::TOO_LARGE], ['x.md', '', LibraryException::INVALID]] as [$name, $bytes, $reason]) {
            try {
                $small->upload($bytes, $name, self::owner());
                self::fail('no exception for ' . $name);
            } catch (LibraryException $e) {
                self::assertSame($reason, $e->reason);
            }
        }
    }

    public function testTheRecordKeepsKnownFieldsInTheirShapesAndNeedsATitle(): void
    {
        $clean = Library::cleanCsl([
            'type' => 'nonsense', 'title' => "  A\ntitle  ", 'DOI' => 'https://doi.org/10.1234/ABC', 'PMID' => 'x12',
            'issued' => ['date-parts' => [[2024, 13, 1]]], 'URL' => 'javascript:alert(1)', 'author' => [['given' => 'Only given'], ['literal' => 'WHO'], 'x'],
            'custom' => ['nested'],
        ]);

        self::assertSame(['type' => 'article-journal', 'title' => 'A title', 'author' => [['literal' => 'WHO']], 'issued' => ['date-parts' => [[2024]]], 'DOI' => '10.1234/abc'], $clean);
        $this->expectExceptionObject(new LibraryException(LibraryException::INVALID));
        Library::cleanCsl(['author' => [['family' => 'Nobody']]]);
    }

    public function testARefusedConfirmLeavesTheUploadForAnotherTry(): void
    {
        $this->library->add("# Something else\n\n© 2024\n", 'a.md', self::owner(), 'owner', ['key' => 'taken2024']);
        $proposal = $this->library->upload(self::ARTICLE, 'study.md', self::owner());
        try {
            $this->library->confirm($proposal['token'], ['csl' => $proposal['csl'], 'key' => 'taken2024'], self::owner(), 'owner');
            self::fail('no exception');
        } catch (LibraryException $e) {
            self::assertSame(LibraryException::DUPLICATE, $e->reason);
        }

        $page = $this->library->confirm($proposal['token'], ['csl' => $proposal['csl'], 'key' => 'study2024'], self::owner(), 'owner');
        self::assertSame('library:study2024', $page->path);
    }

    public function testARecordIsCorrectedAsANewRevisionAndKeepsItsDoiToItself(): void
    {
        [$first] = $this->library->add(self::ARTICLE, 'study.md', self::owner(), 'owner');
        [$second] = $this->library->add("# Another made-up study\n\n© 2023\n", 'b.md', self::owner(), 'owner');

        $csl = $first->frontmatter['csl'];
        $csl['title'] = 'A corrected title';
        $csl['author'] = [['family' => 'Nobody', 'given' => 'Ann']];
        $saved = $this->library->updateRecord($first->path, $csl, ['Corrected'], 1, self::owner(), 'owner');

        self::assertSame(2, $saved->rev);
        self::assertSame('A corrected title', $saved->frontmatter['title']);
        self::assertSame(['corrected'], $saved->frontmatter['tags']);
        self::assertSame($first->body, $saved->body);

        $other = $second->frontmatter['csl'];
        $other['DOI'] = '10.1234/JTI.2024.0001';
        try {
            $this->library->updateRecord($second->path, $other, null, 1, self::owner(), 'owner');
            self::fail('no exception');
        } catch (LibraryException $e) {
            self::assertSame([LibraryException::DUPLICATE, $first->pid], [$e->reason, $e->pid]);
        }
    }

    public function testEntriesListTheSourcesWithWhatTheTableShows(): void
    {
        $csl = ['title' => 'A made-up study of test lesions', 'author' => [['family' => 'Nobody', 'given' => 'Ann']], 'container-title' => 'Journal of Synthetic Imaging', 'issued' => ['date-parts' => [[2024]]]];
        $this->library->add(self::ARTICLE, 'study.md', self::owner(), 'owner', ['csl' => $csl, 'tags' => ['grading']]);
        $this->storage->create('library:about', ['title' => 'About the library'], "Text.\n", 'owner');

        $entries = $this->library->entries(self::owner());

        self::assertCount(1, $entries, 'a page in the namespace that is no source is not listed');
        self::assertSame(['nobody2024', 'Nobody A', 2024, 'Journal of Synthetic Imaging', ['grading'], 'md'], [
            $entries[0]['key'], $entries[0]['authors'], $entries[0]['year'], $entries[0]['journal'], $entries[0]['tags'], $entries[0]['ext'],
        ]);
    }

    public function testOnlyAnEditorOfTheLibraryMayAdd(): void
    {
        self::assertTrue($this->library->canAdd(self::owner()));
        self::assertTrue($this->library->canAdd(new User('ed', 'x', false, [new Grant('library', GrantRole::Editor)], true, 'now', 'now')));
        self::assertFalse($this->library->canAdd(new User('ro', 'x', false, [new Grant('library', GrantRole::Viewer)], true, 'now', 'now')));
        self::assertFalse($this->library->canAdd(null));
    }

    private const BIB = <<<'BIB'
@article{Nobody2024,
  author = {Nobody, Ann}, title = {Imported made-up study}, journal = {Journal of Synthetic Imaging},
  year = {2024}, doi = {10.1234/jti.2024.0001}, keywords = {mri, grading}
}
@article{Nobody2024,
  author = {Nobody, Bob}, title = {Another made-up study}, year = {2023}, doi = {10.1234/jti.2023.0002}
}
@article{bad key!,
  author = {Nobody, Cleo}, title = {A third one}, year = {2022}
}
@article{noyear, title = {   }}
@article{cnp, author = {Nobody, Dan}, title = {Study of patient CNPHERE}, year = {2021}}
BIB;

    private static function bib(): string
    {
        return str_replace('CNPHERE', CnpTest::make(1, '800115'), self::BIB);
    }

    public function testRecordsAreImportedAsPrivateSourcesWithNoFile(): void
    {
        $result = $this->library->importRecords(self::bib(), self::owner(), 'owner', ['batch']);

        self::assertSame(['nobody2024', 'nobody2023', 'nobody2022'], array_column($result['added'], 'key'), 'the file\'s key when it is one and free; made otherwise');
        self::assertSame([['title' => '', 'reason' => 'patient_data']], array_filter($result['skipped'], static fn (array $s): bool => $s['reason'] === 'patient_data'), 'a CNP in a record: nothing kept, nothing echoed');
        $page = $this->storage->read('library:nobody2024');
        self::assertSame('private', $page->visibility);
        self::assertSame(['mri', 'grading', 'batch'], $page->frontmatter['tags']);
        self::assertArrayNotHasKey('original', $page->frontmatter);
        self::assertSame('10.1234/jti.2024.0001', $page->frontmatter['csl']['DOI']);

        $again = $this->library->importRecords(self::bib(), self::owner(), 'owner');
        self::assertSame([], $again['added'], 'importing the same file again adds nothing');
        self::assertContains('duplicate', array_column($again['skipped'], 'reason'));
    }

    public function testAnImportTakesOnlyAReferenceManagersFormats(): void
    {
        $this->expectException(LibraryException::class);
        $this->library->importRecords("Just some text, not a bibliography\n", self::owner(), 'owner');
    }

    public function testADryRunImportKeepsNothing(): void
    {
        $result = $this->library->importRecords(self::bib(), self::owner(), 'owner', null, dryRun: true);

        self::assertCount(3, $result['added']);
        self::assertFalse($this->storage->isPathTaken('library:nobody2024'));
    }

    public function testTheFileOfARecordAlreadyKeptIsAttachedToIt(): void
    {
        $this->library->importRecords(self::bib(), self::owner(), 'owner');
        $before = $this->storage->read('library:nobody2024');

        $proposal = $this->library->upload(self::ARTICLE, 'study.md', self::owner());
        self::assertSame('doi', $proposal['duplicate']['by']);
        self::assertFalse($proposal['duplicate']['has_original']);

        $page = $this->library->confirm($proposal['token'], ['csl' => $proposal['csl'], 'key' => 'other-key'], self::owner(), 'owner');

        self::assertSame('library:nobody2024', $page->path, 'the same page and key: citations of it stand');
        self::assertSame($before->rev + 1, $page->rev);
        self::assertSame('md', $page->frontmatter['original']['ext']);
        self::assertSame(['mri', 'grading'], $page->frontmatter['tags']);
        self::assertSame('Imported made-up study', $page->frontmatter['csl']['title'], 'the record the page had is kept');
        self::assertStringContainsString('Grade 3 was the most common', $page->body);

        // Now it has a file: the same article again is a duplicate
        $second = $this->library->upload(self::ARTICLE, 'study2.md', self::owner());
        $this->expectException(LibraryException::class);
        $this->library->confirm($second['token'], ['csl' => $second['csl']], self::owner(), 'owner');
    }

    public function testARecordTypedWithACnpIsRefused(): void
    {
        $this->expectException(LibraryException::class);
        Library::cleanCsl(['title' => 'Patient ' . CnpTest::make(1, '800115') . ' study']);
    }

    /** @param \Closure(string): ?string $fetch */
    private function withLookup(\Closure $fetch): Library
    {
        return new Library($this->storage, $this->index, new Extractor($this->dataRoot . '/tmp'), new TempUploads($this->dataRoot), 'library', 50 * 1024 * 1024, new \Reporion\Service\Library\Lookup($fetch));
    }

    public function testTheLookupCompletesTheProposalFromTheIdentifiersOnly(): void
    {
        $asked = [];
        $library = $this->withLookup(function (string $url) use (&$asked): ?string {
            $asked[] = $url;

            return match (true) {
                str_contains($url, 'crossref') => '{"message":{"type":"journal-article","title":["Looked-up made-up study"],"author":[{"given":"Zed","family":"Abel"}],"issued":{"date-parts":[[2024]]},"container-title":["J Synth"]}}',
                str_contains($url, 'esearch') => '{"esearchresult":{"idlist":["12345678"]}}',
                default => '<PubmedArticleSet><PubmedArticle><MedlineCitation><Article><Abstract><AbstractText>Abs.</AbstractText></Abstract></Article><MeshHeadingList><MeshHeading><DescriptorName>Lesions</DescriptorName></MeshHeading></MeshHeadingList></MedlineCitation></PubmedArticle></PubmedArticleSet>',
            };
        });

        $proposal = $library->upload(self::ARTICLE, 'study.md', self::owner());

        self::assertSame('crossref+pubmed', $proposal['lookup']);
        self::assertSame(['Lesions'], $proposal['tags']);
        self::assertSame('Looked-up made-up study', $proposal['csl']['title'], 'the lookup replaces what was read');
        self::assertSame('12345678', $proposal['csl']['PMID']);
        self::assertSame('Abs.', $proposal['csl']['abstract']);
        foreach ($asked as $url) {
            self::assertStringNotContainsString('made-up', $url, 'no title and no text: the identifier only');
            self::assertStringNotContainsString('study', $url);
        }
        self::assertCount(3, $asked);
    }

    public function testAFailedLookupLeavesTheRecordAsReadAndSaysSo(): void
    {
        $proposal = $this->withLookup(static fn (string $url): ?string => null)->upload(self::ARTICLE, 'study.md', self::owner());

        self::assertSame('failed', $proposal['lookup']);
        self::assertSame('A made-up study of test lesions', $proposal['csl']['title']);
        self::assertSame([], $proposal['tags']);
    }

    public function testNoIdentifierNoRequestAndLookupOffNothingAtAll(): void
    {
        $asked = 0;
        $library = $this->withLookup(function () use (&$asked): ?string {
            ++$asked;

            return null;
        });
        self::assertSame('none', $library->upload("# A made-up study with no identifier\n\nText only.\n", 'x.md', self::owner())['lookup']);
        self::assertSame(0, $asked);

        // The dry run (library:add --dry-run) sends nothing even when the lookup is on
        $library->propose(self::ARTICLE, 'study.md', self::owner());
        self::assertSame(0, $asked);

        self::assertNull($this->library->upload(self::ARTICLE, 'study.md', self::owner())['lookup'], 'off: null');
    }

    /** A fetch that answers CrossRef and PubMed as a made-up article's, and counts what was asked */
    private static function crossrefAndPubmed(array &$asked): \Closure
    {
        return function (string $url) use (&$asked): ?string {
            $asked[] = $url;

            return match (true) {
                str_contains($url, 'crossref') => '{"message":{"type":"journal-article","title":["Looked-up made-up study"],"author":[{"given":"Zed","family":"Abel"}],"issued":{"date-parts":[[2023]]},"container-title":["J Synth"]}}',
                str_contains($url, 'esearch') => '{"esearchresult":{"idlist":["12345678"]}}',
                default => '<PubmedArticleSet><PubmedArticle><MedlineCitation><Article><Abstract><AbstractText>Abs.</AbstractText></Abstract></Article><MeshHeadingList><MeshHeading><DescriptorName>Lesions</DescriptorName></MeshHeading></MeshHeadingList></MedlineCitation></PubmedArticle></PubmedArticleSet>',
            };
        };
    }

    public function testALookupRunAgainOverwritesTheRecordAndKeepsTheTags(): void
    {
        [$page] = $this->library->add(self::ARTICLE, 'study.md', self::owner(), 'owner', ['tags' => ['mine']]);
        $corrected = $this->library->updateRecord($page->path, ['title' => 'Corrected by hand', 'DOI' => '10.1234/jti.2024.0001', 'issued' => ['date-parts' => [[2024]]]], null, $page->rev, self::owner(), 'owner');
        $asked = [];

        $result = $this->withLookup(self::crossrefAndPubmed($asked))->relookup($page->path, self::owner(), 'owner');

        self::assertSame('updated', $result['status']);
        self::assertSame('crossref+pubmed', $result['how']);
        self::assertContains('title', $result['changed']);
        self::assertSame($corrected->rev + 1, $result['rev']);
        $now = $this->storage->read($page->path);
        self::assertSame('Looked-up made-up study', $now->frontmatter['csl']['title'], 'overwritten, the hand correction too');
        self::assertSame('Looked-up made-up study', $now->frontmatter['title'], 'the page title follows the record');
        self::assertSame([[2023]], $now->frontmatter['csl']['issued']['date-parts']);
        self::assertSame('12345678', $now->frontmatter['csl']['PMID']);
        self::assertSame(['mine', 'lesions'], $now->frontmatter['tags'], 'MeSH terms added, none removed');
        self::assertSame(['lesions'], $result['tags']);
        self::assertSame($corrected->body, $now->body);
        foreach ($asked as $url) {
            self::assertStringNotContainsString('Corrected', $url, 'the identifier only');
        }

        // Again: the same answer, nothing to change, no new revision
        $again = $this->withLookup(self::crossrefAndPubmed($asked))->relookup($page->path, self::owner(), 'owner');
        self::assertSame('unchanged', $again['status']);
        self::assertSame($result['rev'], $this->storage->read($page->path)->rev);
    }

    public function testADryRunLookupAsksAndWritesNothing(): void
    {
        [$page] = $this->library->add(self::ARTICLE, 'study.md', self::owner(), 'owner');
        $asked = [];

        $result = $this->withLookup(self::crossrefAndPubmed($asked))->relookup($page->path, self::owner(), 'owner', true);

        self::assertSame('updated', $result['status']);
        self::assertNotSame([], $asked);
        self::assertSame($page->rev, $this->storage->read($page->path)->rev);
        self::assertSame('A made-up study of test lesions', $this->storage->read($page->path)->frontmatter['csl']['title']);
    }

    public function testALookupWithNoIdentifierAsksNothingAndAFailedOneChangesNothing(): void
    {
        [$bare] = $this->library->add("# A made-up study with no identifier\n\nText only.\n", 'x.md', self::owner(), 'owner');
        [$page] = $this->library->add(self::ARTICLE, 'study.md', self::owner(), 'owner');
        $asked = 0;

        $none = $this->withLookup(function () use (&$asked): ?string {
            ++$asked;

            return null;
        });

        self::assertSame('no_id', $none->relookup($bare->path, self::owner(), 'owner')['status']);
        self::assertSame(0, $asked);
        self::assertSame('failed', $none->relookup($page->path, self::owner(), 'owner')['status']);
        self::assertSame($page->rev, $this->storage->read($page->path)->rev);
    }

    public function testALookupIsRefusedWhenTheLookupsAreOffOrThePageIsNoSource(): void
    {
        [$page] = $this->library->add(self::ARTICLE, 'study.md', self::owner(), 'owner');
        try {
            $this->library->relookup($page->path, self::owner(), 'owner');
            self::fail('lookups are off');
        } catch (LibraryException $e) {
            self::assertSame(LibraryException::LOOKUP_OFF, $e->reason);
        }

        $this->storage->create('notes:plain', ['title' => 'Plain'], 'Text', 'owner', 'x');
        $this->expectException(LibraryException::class);
        $this->withLookup(static fn (string $url): ?string => null)->relookup('notes:plain', self::owner(), 'owner');
    }

    public function testALookupWithNoTitleKeepsTheTitleReadFromTheFile(): void
    {
        $library = $this->withLookup(static fn (string $url): ?string => str_contains($url, 'crossref')
            ? '{"message":{"type":"journal-article","title":[],"container-title":["J Synth"],"author":[]}}'
            : '{"esearchresult":{"idlist":[]}}');

        $proposal = $library->upload(self::ARTICLE, 'study.md', self::owner());

        self::assertSame('crossref', $proposal['lookup']);
        self::assertSame('A made-up study of test lesions', $proposal['csl']['title']);
        self::assertSame('J Synth', $proposal['csl']['container-title']);
    }
}
