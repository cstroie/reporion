<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Service\Library;

use Reporion\Audit\AuditLog;
use Reporion\Auth\SystemActor;
use Reporion\Auth\User;
use Reporion\Exception\PageNotFoundException;
use Reporion\Index\Sqlite;
use Reporion\Service\Library\Abstracts;
use Reporion\Service\Library\Analyses;
use Reporion\Service\Library\Extractor;
use Reporion\Service\Library\Library;
use Reporion\Service\Library\Lookup;
use Reporion\Service\Maintenance\LibraryAbstractsTask;
use Reporion\Service\Maintenance\MaintenanceTask;
use Reporion\Service\PageMoves;
use Reporion\Service\TempUploads;
use Reporion\Storage\FileTree;
use Reporion\Tests\Storage\StorageTestCase;

/** Each source's abstract page (Service\Library\Abstracts); the articles are made up for the test */
final class AbstractsTest extends StorageTestCase
{
    private FileTree $storage;
    private Sqlite $index;
    private AuditLog $audit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->index = new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 3) . '/migrations');
        $this->storage = new FileTree($this->dataRoot, $this->index);
        $this->audit = new AuditLog($this->dataRoot . '/audit');
    }

    private static function owner(): User
    {
        return new User('owner', 'x', true, [], true, 'now', 'now');
    }

    /** $n words of made-up prose */
    private static function words(int $n, string $word = 'lesion'): string
    {
        return rtrim(str_repeat($word . ' ', $n)) . '.';
    }

    /** A made-up article, its title and DOI on top, the opening given, then a long body */
    private static function article(string $opening, string $doi = '10.1234/jti.2024.0001'): string
    {
        return "# A made-up study of test lesions\n\nAnn Nobody\n\nJ Synth Imaging 2024;3:11-14. doi:{$doi}\n\n" . $opening
            . "\n\n## Introduction\n\n" . self::words(400, 'intro') . "\n\n## Results\n\n" . self::words(400, 'result') . "\n";
    }

    private function library(?\Closure $fetch = null): Library
    {
        return new Library($this->storage, $this->index, new Extractor($this->dataRoot . '/tmp'), new TempUploads($this->dataRoot), 'library', 50 * 1024 * 1024,
            $fetch !== null ? new Lookup($fetch) : null, new Abstracts($this->storage, $this->audit));
    }

    /** A fetch that answers PubMed with an abstract of $words words, as PMID $pmid (CrossRef: nothing) */
    private static function pubmed(int $words, string $pmid = '12345678'): \Closure
    {
        return static fn (string $url): ?string => match (true) {
            str_contains($url, 'crossref') => '',
            str_contains($url, 'esearch') => '{"esearchresult":{"idlist":["' . $pmid . '"]}}',
            default => '<PubmedArticleSet><PubmedArticle><MedlineCitation><Article><ArticleTitle>A made-up study of test lesions</ArticleTitle><Abstract>'
                . '<AbstractText Label="PURPOSE">' . self::words(intdiv($words, 2), 'aim') . '</AbstractText><AbstractText Label="RESULTS">' . self::words($words - intdiv($words, 2), 'found') . '</AbstractText>'
                . '</Abstract></Article></MedlineCitation></PubmedArticle></PubmedArticleSet>',
        };
    }

    private function audited(): string
    {
        return (string) @file_get_contents($this->dataRoot . '/audit/' . date('Y-m') . '.ndjson');
    }

    public function testAnArticleWhoseTextMarksItsAbstractGetsItsAbstractPage(): void
    {
        [$source] = $this->library()->add(self::article("## Abstract\n\n" . self::words(100)), 'study.md', self::owner(), 'owner');

        $page = $this->storage->read(Abstracts::pathFor($source->path));
        self::assertSame(self::words(100) . "\n", $page->body);
        self::assertSame('private', $page->frontmatter['visibility']);
        self::assertSame('Abstract: A made-up study of test lesions', $page->frontmatter['title']);
        self::assertSame(['of' => $source->pid, 'from' => 'text'], array_intersect_key($page->frontmatter['abstract'], ['of' => 1, 'from' => 1]));
        self::assertSame('owner', $page->revlog[0]['by'] ?? null);
        self::assertStringContainsString('"with":"abstract"', $this->audited());
        self::assertSame(['text' => self::words(100), 'from' => 'text', 'path' => $page->path], (new Abstracts($this->storage))->of($source));
    }

    public function testTheRecordsAbstractComesFirstAndAStubIsNoAbstract(): void
    {
        [$source] = $this->library(self::pubmed(60))->add(self::article("## Abstract\n\n" . self::words(100)), 'study.md', self::owner(), 'owner');
        $page = $this->storage->read(Abstracts::pathFor($source->path));
        self::assertSame('record', $page->frontmatter['abstract']['from']);
        self::assertStringStartsWith('PURPOSE: aim aim', $page->body);

        // A two-word abstract in the record is a stub: the article's own is taken
        [$other] = $this->library(self::pubmed(2, '12345679'))->add(self::article("## Abstract\n\n" . self::words(90, 'mass'), '10.1234/jti.2024.0002'), 'other.md', self::owner(), 'owner');
        self::assertSame('text', $this->storage->read(Abstracts::pathFor($other->path))->frontmatter['abstract']['from']);
    }

    public function testPubmedsAbstractWinsOverCrossrefsWhenBothAnswer(): void
    {
        $pubmed = self::pubmed(60);
        $both = static fn (string $url): ?string => str_contains($url, 'crossref')
            ? '{"message":{"type":"journal-article","title":["A made-up study of test lesions"],"abstract":"<jats:p>' . self::words(70, 'crossref') . '</jats:p>"}}'
            : $pubmed($url);

        [$source] = $this->library($both)->add(self::article(self::words(150, 'opening')), 'study.md', self::owner(), 'owner');

        self::assertStringStartsWith('PURPOSE: aim', $source->frontmatter['csl']['abstract'], 'the record keeps PubMed\'s');
        self::assertStringStartsWith('PURPOSE: aim', $this->storage->read(Abstracts::pathFor($source->path))->body);
    }

    public function testAnArticleWithNoAbstractItsTextMarksPlainlyGetsNone(): void
    {
        [$source] = $this->library()->add(self::article(self::words(150, 'opening')), 'study.md', self::owner(), 'owner');

        $this->expectException(PageNotFoundException::class);
        $this->storage->read(Abstracts::pathFor($source->path));
    }

    public function testAPageThereThatIsNoAbstractPageIsNeverWrittenOverNorRead(): void
    {
        [$source] = $this->library()->add(self::article(self::words(150, 'opening')), 'study.md', self::owner(), 'owner');
        $this->storage->create(Abstracts::pathFor($source->path), ['title' => 'Mine', 'visibility' => 'private'], "My own notes.\n", 'owner');

        self::assertSame('has', $this->library()->fillAbstract($source->path, SystemActor::LIBRARIAN)['status']);
        self::assertNull((new Abstracts($this->storage))->write($this->storage->read($source->path), 'owner', ['text' => self::words(100), 'from' => 'text']));
        self::assertSame("My own notes.\n", $this->storage->read(Abstracts::pathFor($source->path))->body);
        self::assertNull((new Abstracts($this->storage))->of($source), 'not an abstract page: the analyses read the article');
    }

    public function testALookupRunAgainReplacesTheAbstractWithTheRecordsAndTheHistoryKeepsTheOld(): void
    {
        [$source] = $this->library()->add(self::article("## Abstract\n\n" . self::words(100)), 'study.md', self::owner(), 'owner');

        $this->library(self::pubmed(60))->relookup($source->path, self::owner(), 'owner');

        $page = $this->storage->read(Abstracts::pathFor($source->path));
        self::assertSame(['record', 2], [$page->frontmatter['abstract']['from'], $page->rev]);
        self::assertStringStartsWith('PURPOSE:', $page->body);
    }

    public function testTheAbstractPageMovesAndGoesToTheTrashWithItsSource(): void
    {
        [$source] = $this->library()->add(self::article("## Abstract\n\n" . self::words(100)), 'study.md', self::owner(), 'owner');
        $moves = new PageMoves($this->storage, $this->audit, 'library', new Analyses($this->storage, $this->index));

        $moved = $moves->move($source->path, 'library:renamed2024', 'owner')['moved'];

        self::assertSame($source->pid, $this->storage->read('library:renamed2024:abstract')->frontmatter['abstract']['of']);
        self::assertStringContainsString('"with":"abstract"', $this->audited());
        $this->storage->delete($moved->path, 'owner');
        self::assertSame(1, (new Analyses($this->storage))->trashWith($moved->path, 'owner', $this->audit));
        $this->expectException(PageNotFoundException::class);
        $this->storage->read('library:renamed2024:abstract');
    }

    public function testTheBackfillMakesTheMissingOnesAsTheLibrarianAndItsCheckWritesNothing(): void
    {
        // Two sources added before abstracts were kept: their pages made by hand, no abstract page
        $plain = new Library($this->storage, $this->index, new Extractor($this->dataRoot . '/tmp'), new TempUploads($this->dataRoot), 'library', 50 * 1024 * 1024, null, new Abstracts($this->storage));
        [$with] = $plain->add(self::article("## Abstract\n\n" . self::words(100)), 'study.md', self::owner(), 'owner');
        [$without] = $plain->add(self::article(self::words(150, 'opening'), '10.1234/jti.2024.0002'), 'other.md', self::owner(), 'owner');
        $this->storage->delete(Abstracts::pathFor($with->path), 'owner');

        $task = new LibraryAbstractsTask($this->library());
        $check = $task->run(MaintenanceTask::CHECK, 'owner', $task->options([]));
        self::assertSame(1, $check->summary()['would_make']);
        self::assertSame(1, $check->summary()['none']);
        try {
            $this->storage->read(Abstracts::pathFor($with->path));
            self::fail('a check writes nothing');
        } catch (PageNotFoundException) {
        }

        $run = $task->run(MaintenanceTask::APPLY, 'owner', $task->options([]));
        self::assertSame(1, $run->summary()['made']);
        self::assertSame([$with->pid], array_column($run->items(), 'pid'));
        $page = $this->storage->read(Abstracts::pathFor($with->path));
        self::assertSame(SystemActor::LIBRARIAN, $page->revlog[0]['by'] ?? null);
        self::assertStringContainsString('"on_behalf_of":"owner"', $this->audited());

        // Again: nothing left to make
        self::assertSame(0, $task->run(MaintenanceTask::APPLY, 'owner', $task->options([]))->summary()['made']);
        self::assertSame($without->pid, $this->storage->read($without->path)->pid);
    }
}
