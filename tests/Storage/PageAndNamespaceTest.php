<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Storage;

use InvalidArgumentException;
use Reporion\Storage\FlatFile;
use Reporion\Storage\Journal;

/**
 * A page and a namespace may share a name (decided 2026-09-26, as in
 * DokuWiki): `reports:mri:mioveni` describes the site whose reports live
 * under `reports:mri:mioveni:*`, in the same directory. Creating, moving,
 * restoring and deleting the page never carry the pages under it, and a
 * crash half-way is finished by journal replay.
 */
final class PageAndNamespaceTest extends StorageTestCase
{
    public function testAPageCreatedOverANamespaceTakesItsNameNotASuffix(): void
    {
        $storage = $this->storage();
        $storage->create('reports:mri:mioveni:260920-a-b', ['title' => 'R', 'visibility' => 'private'], 'report', 'owner');

        $site = $storage->create('reports:mri:mioveni', ['title' => 'Mioveni', 'visibility' => 'private'], 'site', 'owner');

        self::assertSame('reports:mri:mioveni', $site->path);
        self::assertSame("site\n", $storage->read('reports:mri:mioveni')->body);
        self::assertSame("report\n", $storage->read('reports:mri:mioveni:260920-a-b')->body);
        $paths = iterator_to_array($storage->allPaths(), false);
        sort($paths);
        self::assertSame(['reports:mri:mioveni', 'reports:mri:mioveni:260920-a-b'], $paths);
    }

    public function testAStubOrAPageStillCollidesAndGetsTheSuffix(): void
    {
        $storage = $this->storage();
        $storage->create('docs:a', ['title' => 'A', 'visibility' => 'private'], 'a', 'owner');
        $storage->create('docs:a:child', ['title' => 'C', 'visibility' => 'private'], 'c', 'owner');
        self::assertSame('docs:a-2', $storage->create('docs:a', ['title' => 'A2', 'visibility' => 'private'], 'a2', 'owner')->path);

        $storage->create('docs:m', ['title' => 'M', 'visibility' => 'private'], 'm', 'owner');
        $storage->move('docs:m', 'docs:n', 'owner');
        self::assertSame('docs:m-2', $storage->create('docs:m', ['title' => 'M2', 'visibility' => 'private'], 'm2', 'owner')->path, 'a redirect stub keeps its name');
    }

    public function testNamesAPageDirectoryUsesItselfAreRefused(): void
    {
        $storage = $this->storage();
        foreach (['docs:rev', 'docs:meta.json', 'docs:current.md', 'docs:media.json', 'docs:redirect'] as $path) {
            try {
                $storage->create($path, ['title' => 'X', 'visibility' => 'private'], 'x', 'owner');
                self::fail($path . ' must be refused');
            } catch (InvalidArgumentException) {
                self::assertDirectoryDoesNotExist($this->dataRoot . '/pages/' . str_replace(':', '/', $path));
            }
        }
    }

    public function testDeletingAPageWithPagesUnderItIsRefusedAndLeavesThemAlone(): void
    {
        $storage = $this->storage();
        $storage->create('docs:a', ['title' => 'A', 'visibility' => 'private'], 'a', 'owner');
        $storage->create('docs:a:child', ['title' => 'C', 'visibility' => 'private'], 'c', 'owner');

        try {
            $storage->delete('docs:a', 'owner');
            self::fail('must be refused');
        } catch (InvalidArgumentException) {
            self::assertSame("a\n", $storage->read('docs:a')->body);
            self::assertSame("c\n", $storage->read('docs:a:child')->body);
            self::assertSame([], $storage->trash());
        }

        $storage->delete('docs:a:child', 'owner');
        $storage->delete('docs:a', 'owner');
        self::assertCount(2, $storage->trash());
    }

    public function testAPageMovesOntoANamespaceOfTheSameNameBesideItsPages(): void
    {
        $index = new RecordingIndex();
        $storage = new FlatFile($this->dataRoot, $index);
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

    public function testReplayFinishesAMoveOntoANamespaceInterruptedBeforeMetaJson(): void
    {
        $storage = $this->storage();
        $storage->create('docs:x:child', ['title' => 'C', 'visibility' => 'private'], 'c', 'owner');
        $page = $storage->create('docs:y', ['title' => 'Y', 'visibility' => 'private'], 'y', 'owner');
        // Crash after rev/ and current.md moved in, before meta.json
        (new Journal($this->dataRoot . '/journal'))->appendIntent('move', $page->pid, 'docs:x', 1, null, '', 'owner', ['from' => 'docs:y']);
        rename($this->dataRoot . '/pages/docs/y/rev', $this->dataRoot . '/pages/docs/x/rev');
        rename($this->dataRoot . '/pages/docs/y/current.md', $this->dataRoot . '/pages/docs/x/current.md');

        $outcomes = $this->storage()->replayJournal();

        self::assertSame('recovered', $outcomes[0]['outcome']);
        $recovered = $this->storage();
        self::assertSame("y\n", $recovered->read('docs:x')->body);
        self::assertSame('docs:x', $recovered->read('docs:x')->meta['path']);
        self::assertSame('docs:x', $recovered->redirectTarget('docs:y'));
        self::assertSame("c\n", $recovered->read('docs:x:child')->body);
        self::assertSame(1, \count($recovered->revisions('docs:x')));
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

    public function testReplayFinishesARestoreInterruptedRightAfterTheClaim(): void
    {
        $storage = $this->storage();
        $page = $storage->create('docs:a', ['title' => 'A', 'visibility' => 'private'], 'a', 'owner');
        $storage->delete('docs:a', 'owner');
        $storage->create('docs:a:child', ['title' => 'C', 'visibility' => 'private'], 'c', 'owner');
        // Crash after allocatePath() claimed docs:a (an empty rev/) and the intent
        mkdir($this->dataRoot . '/pages/docs/a/rev');
        (new Journal($this->dataRoot . '/journal'))->appendIntent('restore', $page->pid, 'docs:a', 1, null, '', 'owner');

        $outcomes = $this->storage()->replayJournal();

        self::assertSame('recovered', $outcomes[0]['outcome']);
        $recovered = $this->storage();
        self::assertSame("a\n", $recovered->read('docs:a')->body);
        self::assertSame("a\n", substr($recovered->readRevision('docs:a', 1), -2), 'the revisions came back, not the empty claim');
        self::assertSame([], $recovered->trash());
    }

    public function testACreateDiscardedOnReplayReleasesItsClaimOnTheNamespace(): void
    {
        $storage = $this->storage();
        $storage->create('docs:x:child', ['title' => 'C', 'visibility' => 'private'], 'c', 'owner');
        // Crash after claiming docs:x and logging the intent, before the revision file
        mkdir($this->dataRoot . '/pages/docs/x/rev');
        (new Journal($this->dataRoot . '/journal'))->appendIntent('create', '01TESTCLAIM000000000000000', 'docs:x', 1, null, '', 'owner');

        $outcomes = $this->storage()->replayJournal();

        self::assertSame('discarded', $outcomes[0]['outcome']);
        self::assertSame('docs:x', $this->storage()->create('docs:x', ['title' => 'X', 'visibility' => 'private'], 'x', 'owner')->path, 'the name is free again, not docs:x-2');
    }

    private function storage(): FlatFile
    {
        return new FlatFile($this->dataRoot, new RecordingIndex());
    }
}
