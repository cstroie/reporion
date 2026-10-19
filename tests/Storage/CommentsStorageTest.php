<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Reporion\Tests\Storage;

use InvalidArgumentException;
use Reporion\Exception\PageNotFoundException;
use Reporion\Index\Sqlite;
use Reporion\Storage\FlatFile;

/**
 * A page's comments (D52): `comments.json` beside its revisions: never a
 * revision, carried with the page through moves, the trash and back, and
 * never a name a page may take.
 */
final class CommentsStorageTest extends StorageTestCase
{
    private const PATH = 'docs:note';

    private function storage(): FlatFile
    {
        return new FlatFile($this->dataRoot, new Sqlite($this->dataRoot . '/index.sqlite', \dirname(__DIR__, 2) . '/migrations'));
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
        self::assertFileExists($this->dataRoot . '/pages/docs/note/comments.json');
    }

    public function testAMalformedEntryIsLeftOutAndAPageThatIsNotThereRefused(): void
    {
        $storage = $this->storage();
        $storage->create(self::PATH, ['title' => 'N', 'visibility' => 'private'], "text\n", 'ana');
        file_put_contents($this->dataRoot . '/pages/docs/note/comments.json', '{"v":1,"comments":[{"id":"A","by":"ana","text":"ok"},{"id":5},"x"]}');

        self::assertSame(['A'], array_column($storage->comments(self::PATH), 'id'));

        $this->expectException(PageNotFoundException::class);
        $storage->comments('docs:nope');
    }

    public function testCommentsTravelWithThePage(): void
    {
        $storage = $this->storage();
        $storage->create(self::PATH, ['title' => 'N', 'visibility' => 'private'], "text\n", 'ana');
        $storage->updateComments(self::PATH, static fn (array $all): array => [self::comment('A', 'De verificat')]);

        $storage->move(self::PATH, 'docs:moved', 'ana');
        self::assertSame(['A'], array_column($storage->comments('docs:moved'), 'id'));
        self::assertFileDoesNotExist($this->dataRoot . '/pages/docs/note/comments.json', 'not left in the redirect stub');

        $pid = $storage->read('docs:moved')->pid;
        $storage->delete('docs:moved', 'ana');
        $storage->restore($pid, 'ana');
        self::assertSame(['A'], array_column($storage->comments('docs:moved'), 'id'), 'delete and restore');

        $storage->create('docs:target:child', ['title' => 'C', 'visibility' => 'private'], "c\n", 'ana');
        $storage->move('docs:moved', 'docs:target', 'ana');
        self::assertSame(['A'], array_column($storage->comments('docs:target'), 'id'), 'into a namespace of the same name');
    }

    public function testNoPageMayBeNamedLikeTheCommentsFile(): void
    {
        $storage = $this->storage();
        $storage->create(self::PATH, ['title' => 'N', 'visibility' => 'private'], "text\n", 'ana');
        $storage->updateComments(self::PATH, static fn (array $all): array => [self::comment('A', 'x')]);

        try {
            $storage->create('docs:note:comments.json', ['title' => 'X', 'visibility' => 'private'], 'x', 'ana');
            self::fail('must be refused');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
        self::assertSame(['docs:note'], iterator_to_array($storage->allPaths(), false));
        self::assertSame([], $storage->strayPaths());
    }
}
