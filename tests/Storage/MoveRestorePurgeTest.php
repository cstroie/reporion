<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Storage;

use InvalidArgumentException;
use Reporion\Exception\PageNotFoundException;
use Reporion\Storage\FlatFile;
use Reporion\Storage\Journal;

/**
 * Storage\FlatFile::move() / redirectTarget() / trash() / restore() / purge().
 */
final class MoveRestorePurgeTest extends StorageTestCase
{
    public function testMoveLeavesAStubRecordsTheMoveAndReindexes(): void
    {
        $index = new RecordingIndex();
        $storage = new FlatFile($this->dataRoot, $index);
        $created = $storage->create('docs:a', ['title' => 'A', 'visibility' => 'private'], 'body', 'owner');

        $moved = $storage->move('docs:a', 'guides:b', 'mihai');

        self::assertSame('guides:b', $moved->path);
        self::assertSame($created->pid, $moved->pid);
        self::assertSame(1, $moved->rev, 'a move is not a revision');
        self::assertSame('guides:b', $storage->redirectTarget('docs:a'));
        self::assertNull($storage->redirectTarget('guides:b'));
        self::assertSame([['from' => 'docs:a', 'to' => 'guides:b']], array_map(
            static fn (array $m): array => ['from' => $m['from'], 'to' => $m['to']],
            $moved->meta['moves']
        ));
        self::assertSame('guides:b', $index->indexed[array_key_last($index->indexed)]->path);
        self::assertSame(['guides:b'], iterator_to_array($storage->allPaths(), false), 'a stub is not a page');
        self::assertSame("body\n", $storage->readRevision('guides:b', 1) === '' ? '' : $storage->read('guides:b')->body);
    }

    public function testRedirectChainsCollapseAtWriteTime(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $storage->create('docs:a', ['title' => 'A', 'visibility' => 'private'], 'body', 'owner');

        $storage->move('docs:a', 'docs:b', 'owner');
        $storage->move('docs:b', 'docs:c', 'owner');

        self::assertSame('docs:c', $storage->redirectTarget('docs:a'), 'A points straight at C, never at B');
        self::assertSame('docs:c', $storage->redirectTarget('docs:b'));
    }

    public function testAPageCanMoveBackOntoItsOwnStub(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $storage->create('docs:a', ['title' => 'A', 'visibility' => 'private'], 'body', 'owner');

        $storage->move('docs:a', 'docs:b', 'owner');
        $back = $storage->move('docs:b', 'docs:a', 'owner');

        self::assertSame('docs:a', $back->path);
        self::assertSame('docs:a', $storage->redirectTarget('docs:b'));
        self::assertNull($storage->redirectTarget('docs:a'));
    }

    public function testMoveRefusesATakenPathAPageWithChildrenAndBadPaths(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $storage->create('docs:a', ['title' => 'A', 'visibility' => 'private'], 'body', 'owner');
        $storage->create('docs:b', ['title' => 'B', 'visibility' => 'private'], 'body', 'owner');
        $storage->create('docs:a:child', ['title' => 'Child', 'visibility' => 'private'], 'body', 'owner');

        foreach (['docs:b', 'docs:..:x', 'docs:a'] as $bad) {
            try {
                $storage->move($bad === 'docs:a' ? 'docs:b' : 'docs:b', $bad === 'docs:a' ? 'docs:a' : $bad, 'owner');
                self::fail("moving onto {$bad} must be refused");
            } catch (InvalidArgumentException) {
                self::assertSame('docs:b', $storage->read('docs:b')->path);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $storage->move('docs:a', 'docs:z', 'owner');
    }

    public function testReplayFinishesAMoveInterruptedAfterTheRename(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $page = $storage->create('docs:a', ['title' => 'A', 'visibility' => 'private'], 'body', 'owner');
        // Crash right after rename(): no stub, meta.json still names docs:a
        (new Journal($this->dataRoot . '/journal'))->appendIntent('move', $page->pid, 'docs:b', 1, null, '', 'owner', ['from' => 'docs:a']);
        rename($this->dataRoot . '/pages/docs/a', $this->dataRoot . '/pages/docs/b');

        $index = new RecordingIndex();
        $outcomes = (new FlatFile($this->dataRoot, $index))->replayJournal();

        self::assertSame('recovered', $outcomes[0]['outcome']);
        $recovered = new FlatFile($this->dataRoot, new RecordingIndex());
        self::assertSame('docs:b', $recovered->read('docs:b')->meta['path']);
        self::assertSame('docs:b', $recovered->redirectTarget('docs:a'));
        self::assertSame('docs:b', $index->indexed[0]->path);
    }

    public function testDeleteThenRestorePutsThePageBackAndListsWhoDeletedIt(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $page = $storage->create('docs:a', ['title' => 'Alpha', 'visibility' => 'private'], 'body', 'owner');
        $storage->delete('docs:a', 'mihai');

        $trash = $storage->trash();
        self::assertCount(1, $trash);
        self::assertSame($page->pid, $trash[0]['pid']);
        self::assertSame('Alpha', $trash[0]['title']);
        self::assertSame('mihai', $trash[0]['deletedBy']);
        self::assertFalse($trash[0]['signed']);

        $restored = $storage->restore($page->pid, 'owner');

        self::assertSame('docs:a', $restored->path);
        self::assertSame([], $storage->trash());
    }

    public function testRestoreTakesTheNextFreePathWhenTheOldOneIsTaken(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $page = $storage->create('docs:a', ['title' => 'Alpha', 'visibility' => 'private'], 'body', 'owner');
        $storage->delete('docs:a', 'owner');
        $storage->create('docs:a', ['title' => 'New alpha', 'visibility' => 'private'], 'body', 'owner');

        self::assertSame('docs:a-2', $storage->restore($page->pid, 'owner')->path);
        self::assertSame('New alpha', $storage->read('docs:a')->frontmatter['title']);
    }

    public function testPurgeRemovesForGoodButASignedPageNeedsTheOverride(): void
    {
        $storage = new FlatFile($this->dataRoot, new RecordingIndex());
        $plain = $storage->create('docs:a', ['title' => 'A', 'visibility' => 'private'], 'body', 'owner');
        $signed = $storage->create('docs:s', ['title' => 'S', 'visibility' => 'private'], 'body', 'owner');
        $storage->sign('docs:s', 'owner', []);
        $storage->delete('docs:a', 'owner');
        $storage->delete('docs:s', 'owner');

        $storage->purge($plain->pid, 'owner');
        self::assertSame([$signed->pid], array_column($storage->trash(), 'pid'));

        try {
            $storage->purge($signed->pid, 'owner');
            self::fail('a signed page must not be purged without the override');
        } catch (InvalidArgumentException) {
            self::assertCount(1, $storage->trash());
        }
        $storage->purge($signed->pid, 'owner', includeSigned: true);
        self::assertSame([], $storage->trash());

        $this->expectException(PageNotFoundException::class);
        $storage->restore($plain->pid, 'owner');
    }

    public function testALegacyDeleteDoneLineStillClosesItsIntent(): void
    {
        $journal = new Journal($this->dataRoot . '/journal');
        $journal->appendIntent('create', 'P1', 'docs:a', 1, null, '', 'owner');
        $journal->appendDone('P1', 1);
        $journal->appendIntent('delete', 'P1', 'docs:a', 1, null, '', 'owner');
        $journal->appendDone('P1', 1); // how a delete's done was written before ops were recorded

        self::assertSame([], $journal->openIntents());
    }

    public function testASecondMoveAtTheSameRevIsNotHiddenByTheFirstOnesDone(): void
    {
        $journal = new Journal($this->dataRoot . '/journal');
        $journal->appendIntent('move', 'P1', 'docs:b', 1, null, '', 'owner', ['from' => 'docs:a']);
        $journal->appendDone('P1', 1, 'move');
        $journal->appendIntent('move', 'P1', 'docs:c', 1, null, '', 'owner', ['from' => 'docs:b']);

        $open = $journal->openIntents();
        self::assertCount(1, $open);
        self::assertSame('docs:c', $open[0]['path']);
    }
}

