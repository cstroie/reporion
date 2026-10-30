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
}
