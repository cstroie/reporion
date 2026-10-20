<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Storage;

use PDO;
use Reporion\Exception\PageNotFoundException;
use Reporion\Index\Sqlite;
use Reporion\Storage\FileTree;

/**
 * A page's comments (D52): `comment` rows of the state database keyed by
 * the page's pid (D54; was `comments.json` in the page directory): never a
 * revision, carried with the page through moves, the trash and back.
 */
final class CommentsStorageTest extends StorageTestCase
{
    private const PATH = 'docs:note';

    private function storage(): FileTree
    {
        return new FileTree($this->dataRoot, new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations'));
    }

    /** @return array<string, mixed> */
    private static function comment(string $id, string $text): array
    {
        return ['id' => $id, 'by' => 'ana', 'ts' => '2026-10-19T10:00:00+03:00', 'text' => $text, 'quote' => '', 'rev' => 1, 'resolved' => null];
    }

    public function testARoundTripNeverARevision(): void
    {
        $storage = $this->storage();
        $storage->create(self::PATH, ['title' => 'N', 'visibility' => 'private'], "text\n", 'ana');
        self::assertSame([], $storage->comments(self::PATH));

        $storage->updateComments(self::PATH, static fn (array $all): array => [...$all, self::comment('A', 'Prima')]);
        $kept = $storage->updateComments(self::PATH, static fn (array $all): array => [...$all, self::comment('B', 'A doua')]);

        self::assertSame(['Prima', 'A doua'], array_column($kept, 'text'));
        self::assertSame(['A', 'B'], array_column($storage->comments(self::PATH), 'id'));
        self::assertSame(1, $storage->read(self::PATH)->rev, 'no revision');
        self::assertSame(2, (int) (new PDO('sqlite:' . $this->dataRoot . '/reporion.sqlite'))->query('SELECT COUNT(*) FROM comment')?->fetchColumn());
    }

    public function testAMalformedEntryIsLeftOutAndAPageThatIsNotThereRefused(): void
    {
        $storage = $this->storage();
        $storage->create(self::PATH, ['title' => 'N', 'visibility' => 'private'], "text\n", 'ana');
        $storage->updateComments(self::PATH, static fn (array $all): array => [['id' => 'A', 'by' => 'ana', 'text' => 'ok'], ['id' => 5], 'x']);

        self::assertSame(['A'], array_column($storage->comments(self::PATH), 'id'));

        $this->expectException(PageNotFoundException::class);
        $storage->comments('docs:nope');
    }

    public function testAtMostTheNewestFiveHundredAreKept(): void
    {
        $storage = $this->storage();
        $storage->create(self::PATH, ['title' => 'N', 'visibility' => 'private'], "text\n", 'ana');
        $many = array_map(static fn (int $i): array => self::comment('C' . $i, 't' . $i), range(1, FileTree::COMMENTS_MAX + 3));

        $kept = $storage->updateComments(self::PATH, static fn (): array => $many);

        self::assertCount(FileTree::COMMENTS_MAX, $kept);
        self::assertSame('C4', $kept[0]['id']);
    }

    public function testCommentsTravelWithThePage(): void
    {
        $storage = $this->storage();
        $storage->create(self::PATH, ['title' => 'N', 'visibility' => 'private'], "text\n", 'ana');
        $storage->updateComments(self::PATH, static fn (array $all): array => [self::comment('A', 'De verificat')]);

        $storage->move(self::PATH, 'docs:moved', 'ana');
        self::assertSame(['A'], array_column($storage->comments('docs:moved'), 'id'));

        $pid = $storage->read('docs:moved')->pid;
        $storage->delete('docs:moved', 'ana');
        $storage->restore($pid, 'ana');
        self::assertSame(['A'], array_column($storage->comments('docs:moved'), 'id'), 'delete and restore');

        $storage->create('docs:target:child', ['title' => 'C', 'visibility' => 'private'], "c\n", 'ana');
        $storage->move('docs:moved', 'docs:target', 'ana');
        self::assertSame(['A'], array_column($storage->comments('docs:target'), 'id'), 'beside a namespace of the same name');
    }
}
