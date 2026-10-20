<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Storage;

use Reporion\Storage\FileTree;
use Reporion\Storage\Journal;

/**
 * A page and a namespace may share a name (decided 2026-09-26, as in
 * DokuWiki): `reports:mri:mioveni` describes the site whose reports live
 * under `reports:mri:mioveni:*`. Since D54 they are a file and a directory,
 * `mioveni.md` beside `mioveni/`, so creating, moving, restoring and deleting
 * the page never touch the pages under it.
 */
final class PageAndNamespaceTest extends StorageTestCase
{
    public function testAPageCreatedBesideANamespaceTakesItsNameNotASuffix(): void
    {
        $storage = $this->storage();
        $storage->create('reports:mri:mioveni:260920-a-b', ['title' => 'R', 'visibility' => 'private'], 'report', 'owner');

        $site = $storage->create('reports:mri:mioveni', ['title' => 'Mioveni', 'visibility' => 'private'], 'site', 'owner');

        self::assertSame('reports:mri:mioveni', $site->path);
        self::assertFileExists($this->dataRoot . '/pages/reports/mri/mioveni.md');
        self::assertFileExists($this->dataRoot . '/pages/reports/mri/mioveni/260920-a-b.md');
        self::assertSame("site\n", $storage->read('reports:mri:mioveni')->body);
        self::assertSame("report\n", $storage->read('reports:mri:mioveni:260920-a-b')->body);
        self::assertSame(['reports:mri:mioveni', 'reports:mri:mioveni:260920-a-b'], iterator_to_array($storage->allPaths(), false));
    }

    public function testAPageStillCollidesAndGetsTheSuffix(): void
    {
        $storage = $this->storage();
        $storage->create('docs:a', ['title' => 'A', 'visibility' => 'private'], 'a', 'owner');
        $storage->create('docs:a:child', ['title' => 'C', 'visibility' => 'private'], 'c', 'owner');
        self::assertSame('docs:a-2', $storage->create('docs:a', ['title' => 'A2', 'visibility' => 'private'], 'a2', 'owner')->path);
    }

    public function testARedirectIsNotACollisionTheNameCanBeTakenAgain(): void
    {
        $storage = $this->storage();
        $storage->create('docs:m', ['title' => 'M', 'visibility' => 'private'], 'm', 'owner');
        $storage->move('docs:m', 'docs:n', 'owner');
        self::assertSame('docs:n', $storage->redirectTarget('docs:m'));

        $new = $storage->create('docs:m', ['title' => 'M2', 'visibility' => 'private'], 'm2', 'owner', exclusive: true);

        self::assertSame('docs:m', $new->path, 'the redirect gave the name up');
        self::assertNull($storage->redirectTarget('docs:m'));
        self::assertSame("m2\n", $storage->read('docs:m')->body);
        self::assertSame("m\n", $storage->read('docs:n')->body, 'the moved page is untouched');
        self::assertSame('docs:m', $storage->read('docs:n')->meta['moves'][0]['from'], 'and still records where it came from');
    }

    /** The 1.x page-directory entries are ordinary names now (D54): nothing of a page sits beside its pages */
    public function testNamesTheOldPageDirectoryUsedAreOrdinaryPages(): void
    {
        $storage = $this->storage();
        foreach (['docs:rev', 'docs:meta.json', 'docs:current', 'docs:media.json', 'docs:redirect'] as $path) {
            self::assertSame($path, $storage->create($path, ['title' => 'X', 'visibility' => 'private'], 'x', 'owner')->path);
        }
    }

    public function testDeletingAPageWithPagesUnderItLeavesThemAlone(): void
    {
        $storage = $this->storage();
        $storage->create('docs:a', ['title' => 'A', 'visibility' => 'private'], 'a', 'owner');
        $storage->create('docs:a:child', ['title' => 'C', 'visibility' => 'private'], 'c', 'owner');

        $storage->delete('docs:a', 'owner');

        self::assertSame("c\n", $storage->read('docs:a:child')->body);
        self::assertCount(1, $storage->trash());
        self::assertSame(['docs:a:child'], iterator_to_array($storage->allPaths(), false));
    }

    public function testAPageMovesBesideANamespaceOfTheSameName(): void
    {
        $index = new RecordingIndex();
        $storage = new FileTree($this->dataRoot, $index);
        $storage->create('bookmarks:gemini:capsule', ['title' => 'C', 'visibility' => 'private'], 'c', 'owner');
        // How the importer left it: the page beside its namespace, suffixed
        $page = $storage->create('bookmarks:gemini-2', ['title' => 'G', 'visibility' => 'private'], 'g', 'owner');

        $moved = $storage->move('bookmarks:gemini-2', 'bookmarks:gemini', 'owner');

        self::assertSame($page->pid, $moved->pid);
        self::assertSame("g\n", $storage->read('bookmarks:gemini')->body);
        self::assertSame("c\n", $storage->read('bookmarks:gemini:capsule')->body);
        self::assertSame('bookmarks:gemini', $storage->redirectTarget('bookmarks:gemini-2'));
        self::assertSame('bookmarks:gemini', $index->indexed[array_key_last($index->indexed)]->path);
    }

    public function testARestoreGoesBackBesideThePagesCreatedUnderItsNameMeanwhile(): void
    {
        $storage = $this->storage();
        $page = $storage->create('docs:a', ['title' => 'A', 'visibility' => 'private'], 'a', 'owner');
        $storage->delete('docs:a', 'owner');
        $storage->create('docs:a:child', ['title' => 'C', 'visibility' => 'private'], 'c', 'owner');

        $restored = $storage->restore($page->pid, 'owner');

        self::assertSame('docs:a', $restored->path);
        self::assertSame("a\n", $storage->read('docs:a')->body);
        self::assertSame("c\n", $storage->read('docs:a:child')->body);
        self::assertSame([], $storage->trash());
        self::assertSame([], glob($this->dataRoot . '/trash/*') ?: []);
    }

    public function testReplayFinishesARestoreInterruptedBetweenItsRenames(): void
    {
        $storage = $this->storage();
        $page = $storage->create('docs:a', ['title' => 'A', 'visibility' => 'private'], 'a', 'owner');
        $storage->delete('docs:a', 'owner');
        // Crash after the page file came back, before the attic zip and the row
        rename($this->dataRoot . '/trash/' . $page->pid . '/page.md', $this->dataRoot . '/pages/docs/a.md');
        (new Journal($this->dataRoot . '/journal'))->appendIntent('restore', $page->pid, 'docs:a', 1, null, '', 'owner');

        $outcomes = $this->storage()->replayJournal();

        self::assertSame('recovered', $outcomes[0]['outcome']);
        $recovered = $this->storage();
        self::assertSame("a\n", $recovered->read('docs:a')->body);
        self::assertSame("a\n", substr($recovered->readRevision('docs:a', 1), -2), 'the revisions came back too');
        self::assertSame([], $recovered->trash());
    }

    public function testACreateDiscardedOnReplayLeavesTheNameFree(): void
    {
        $storage = $this->storage();
        $storage->create('docs:x:child', ['title' => 'C', 'visibility' => 'private'], 'c', 'owner');
        // Crash after the intent, before the attic zip
        (new Journal($this->dataRoot . '/journal'))->appendIntent('create', '01TESTCLAIM000000000000000', 'docs:x', 1, null, '', 'owner');

        $outcomes = $this->storage()->replayJournal();

        self::assertSame('discarded', $outcomes[0]['outcome']);
        self::assertSame('docs:x', $this->storage()->create('docs:x', ['title' => 'X', 'visibility' => 'private'], 'x', 'owner')->path, 'not docs:x-2');
    }

    private function storage(): FileTree
    {
        return new FileTree($this->dataRoot, new RecordingIndex());
    }
}
